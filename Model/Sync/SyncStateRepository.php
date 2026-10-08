<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

use Fullspec\AnswerEng\Model\Db\SqlExecutor;
use Fullspec\AnswerEng\Model\Support\Ts;

/**
 * The per-view sync state machine (spec §6). Every outcome is applied by ONE
 * UPDATE guarded only by config_revision, so a push that raced a config
 * change is discarded, never half-applied.
 *
 * MySQL evaluates single-table UPDATE assignments LEFT TO RIGHT and later
 * expressions see earlier assignments — assignment order below is deliberate.
 */
class SyncStateRepository
{
    public const OK = 'ok';
    public const RETRYING = 'retrying';
    public const EXHAUSTED = 'exhausted';
    public const BLOCKED = 'blocked';
    public const PAUSED = 'paused';
    public const DEBOUNCE_SECONDS = 300;
    public const MAX_TRANSIENT_ATTEMPTS = 3;
    public const CAUSE_RUN_IN_PROGRESS = 'run_in_progress';
    public const CAUSE_EXPORT_FAILED = 'catalog_export_failed';
    private const RETRY_SECONDS = [300, 900];

    /**
     * Constructor.
     *
     * @param SqlExecutor $db
     */
    public function __construct(private readonly SqlExecutor $db)
    {
    }

    /**
     * The sync-state table name.
     *
     * @return string
     */
    private function t(): string
    {
        return $this->db->table('answereng_sync_state');
    }

    /**
     * Ensure.
     *
     * @param int $storeId
     * @return void
     */
    public function ensure(int $storeId): void
    {
        $this->db->execute("INSERT IGNORE INTO {$this->t()} (store_id) VALUES (?)", [$storeId]);
    }

    /**
     * The view's state row (created with defaults if missing).
     *
     * @param int $storeId
     * @return SyncState
     */
    public function get(int $storeId): SyncState
    {
        $this->ensure($storeId);
        return SyncState::fromRow((array) $this->db->fetchRow("SELECT * FROM {$this->t()} WHERE store_id = ?", [$storeId]));
    }

    /**
     * Mark dirty.
     *
     * @param list<int> $storeIds
     * @param \DateTimeImmutable $now
     */
    public function markDirty(array $storeIds, \DateTimeImmutable $now): void
    {
        if ($storeIds === []) {
            return;
        }
        $in = $this->placeholders($storeIds);
        // A catalog change resumes views stuck for catalog reasons.
        $this->db->execute(
            "UPDATE {$this->t()} SET state = 'ok', reason = NULL, attempts = 0, next_attempt_at = NULL,
                 retry_reason = NULL
             WHERE store_id IN ($in)
               AND (state = 'exhausted'
                    OR (state = 'paused' AND reason IN ('validation_failed', 'product_limit')))",
            $storeIds
        );
        $values = implode(', ', array_fill(0, count($storeIds), '(?, 1, ?)'));
        $bind = [];
        foreach ($storeIds as $id) {
            $bind[] = $id;
            $bind[] = Ts::fmt($now);
        }
        $this->db->execute(
            "INSERT INTO {$this->t()} (store_id, dirty, last_change_at) VALUES $values
             ON DUPLICATE KEY UPDATE dirty = 1, last_change_at = VALUES(last_change_at)",
            $bind
        );
    }

    /**
     * Apply settings hash.
     *
     * @param int $storeId
     * @param string $hash
     * @param \DateTimeImmutable $now
     * @return bool
     */
    public function applySettingsHash(int $storeId, string $hash, \DateTimeImmutable $now): bool
    {
        $this->ensure($storeId);
        return $this->db->execute(
            "UPDATE {$this->t()} SET config_revision = config_revision + 1, settings_hash = ?, dirty = 1,
                 last_change_at = ?, attempts = 0, next_attempt_at = NULL, retry_reason = NULL,
                 reason = NULL, state = 'ok'
             WHERE store_id = ? AND (settings_hash IS NULL OR settings_hash <> ?)",
            [$hash, Ts::fmt($now), $storeId, $hash]
        ) > 0;
    }

    /**
     * Select for debounce.
     *
     * @param list<int> $enabledStoreIds
     * @param \DateTimeImmutable $now
     * @return list<int>
     */
    public function selectForDebounce(array $enabledStoreIds, \DateTimeImmutable $now): array
    {
        if ($enabledStoreIds === []) {
            return [];
        }
        $cutoff = Ts::fmt($now->modify('-' . self::DEBOUNCE_SECONDS . ' seconds'));
        return $this->ids(
            "SELECT store_id FROM {$this->t()}
             WHERE store_id IN ({$this->placeholders($enabledStoreIds)})
               AND ((state IN ('ok', 'blocked') AND dirty = 1 AND last_change_at <= ?)
                    OR (state = 'retrying' AND next_attempt_at <= ?))
             ORDER BY store_id",
            [...$enabledStoreIds, $cutoff, Ts::fmt($now)]
        );
    }

    /**
     * Select for reconcile.
     *
     * @param list<int> $enabledStoreIds
     * @param \DateTimeImmutable $now
     * @return list<int>
     */
    public function selectForReconcile(array $enabledStoreIds, \DateTimeImmutable $now): array
    {
        foreach ($enabledStoreIds as $id) {
            $this->ensure($id);
        }
        if ($enabledStoreIds === []) {
            return [];
        }
        return $this->ids(
            "SELECT store_id FROM {$this->t()}
             WHERE store_id IN ({$this->placeholders($enabledStoreIds)})
               AND (state IN ('ok', 'blocked', 'exhausted')
                    OR (state = 'retrying' AND next_attempt_at <= ?))
             ORDER BY store_id",
            [...$enabledStoreIds, Ts::fmt($now)]
        );
    }

    /**
     * Record success.
     *
     * @param int $storeId
     * @param int $revision
     * @param \DateTimeImmutable $startedAt
     * @param \DateTimeImmutable $now
     * @param int $productCount
     * @param string|null $storeKey
     * @param string|null $reason
     * @return bool
     */
    public function recordSuccess(
        int $storeId,
        int $revision,
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $now,
        int $productCount,
        ?string $storeKey,
        ?string $reason
    ): bool {
        $changed = $this->db->execute(
            "UPDATE {$this->t()} SET
                 dirty = IF(last_change_at IS NULL OR last_change_at < ?, 0, dirty),
                 state = 'ok', reason = ?, retry_reason = NULL, attempts = 0, next_attempt_at = NULL,
                 last_sync_at = ?, product_count = ?, synced_store_key = COALESCE(?, synced_store_key)
             WHERE store_id = ? AND config_revision = ?",
            [Ts::fmt($startedAt), $reason, Ts::fmt($now), $productCount, $storeKey, $storeId, $revision]
        );
        return $this->applied($changed, $storeId, $revision);
    }

    /**
     * A transient failure: retry at +5 then +15 minutes, exhausted on the 3rd in a row.
     *
     * $cause replaces any stale reason (a manual sync of a paused/blocked view keeps nothing of it):
     * null, CAUSE_RUN_IN_PROGRESS or CAUSE_EXPORT_FAILED; StateMessage words the state from it.
     *
     * @param int $storeId
     * @param int $revision
     * @param \DateTimeImmutable $now
     * @param int|null $throttleSeconds
     * @param string|null $cause
     */
    public function recordTransient(
        int $storeId,
        int $revision,
        \DateTimeImmutable $now,
        ?int $throttleSeconds,
        ?string $cause = null
    ): bool {
        $throttleUntil = $throttleSeconds === null ? null : Ts::fmt($now->modify("+$throttleSeconds seconds"));
        $changed = $this->db->execute(
            // state and next_attempt_at read the OLD attempts; attempts is assigned last.
            "UPDATE {$this->t()} SET
                 state = IF(attempts + 1 >= ?, 'exhausted', 'retrying'),
                 next_attempt_at = IF(attempts + 1 >= ?, NULL, COALESCE(?, IF(attempts = 0, ?, ?))),
                 retry_reason = IF(attempts + 1 >= ?, NULL, ?),
                 reason = ?,
                 attempts = attempts + 1
             WHERE store_id = ? AND config_revision = ?",
            [
                self::MAX_TRANSIENT_ATTEMPTS,
                self::MAX_TRANSIENT_ATTEMPTS, $throttleUntil,
                Ts::fmt($now->modify('+' . self::RETRY_SECONDS[0] . ' seconds')),
                Ts::fmt($now->modify('+' . self::RETRY_SECONDS[1] . ' seconds')),
                self::MAX_TRANSIENT_ATTEMPTS, $throttleSeconds === null ? null : 'throttled',
                $cause,
                $storeId, $revision,
            ]
        );
        return $this->applied($changed, $storeId, $revision);
    }

    /**
     * Pause the view.
     *
     * For a catalog-dependent reason (validation_failed, product_limit) a catalog edit made at or after
     * $startedAt already happened while the push ran — markDirty found nothing to unpause then — so the
     * view stays `ok` (and dirty) and the next debounced run sends the fixed catalog instead of waiting for
     * another edit.
     *
     * @param int $storeId
     * @param int $revision
     * @param string $reason
     * @param \DateTimeImmutable $startedAt
     */
    public function recordPaused(int $storeId, int $revision, string $reason, \DateTimeImmutable $startedAt): bool
    {
        if (!in_array($reason, Classifier::CATALOG_DEPENDENT, true)) {
            return $this->setTerminal($storeId, $revision, self::PAUSED, $reason);
        }
        $editedSince = 'last_change_at IS NOT NULL AND last_change_at >= ?';
        $changed = $this->db->execute(
            // Both conditions read last_change_at, which this UPDATE never assigns.
            "UPDATE {$this->t()} SET
                 state = IF($editedSince, 'ok', 'paused'),
                 reason = IF($editedSince, NULL, ?),
                 retry_reason = NULL, attempts = 0, next_attempt_at = NULL
             WHERE store_id = ? AND config_revision = ?",
            [Ts::fmt($startedAt), Ts::fmt($startedAt), $reason, $storeId, $revision]
        );
        return $this->applied($changed, $storeId, $revision);
    }

    /**
     * Record blocked.
     *
     * @param int $storeId
     * @param int $revision
     * @param string $reason
     * @return bool
     */
    public function recordBlocked(int $storeId, int $revision, string $reason): bool
    {
        return $this->setTerminal($storeId, $revision, self::BLOCKED, $reason);
    }

    /**
     * Resume views paused for $reason (no revision bump: nothing about the configuration changed).
     *
     * Used when the condition behind a non-catalog pause is lifted, e.g. a cancelled store deletion.
     *
     * @param list<int> $storeIds
     * @param string $reason
     * @param \DateTimeImmutable $now
     */
    public function resumePaused(array $storeIds, string $reason, \DateTimeImmutable $now): void
    {
        if ($storeIds === []) {
            return;
        }
        $this->db->execute(
            "UPDATE {$this->t()} SET state = 'ok', reason = NULL, dirty = 1, last_change_at = ?
             WHERE store_id IN ({$this->placeholders($storeIds)}) AND state = 'paused' AND reason = ?",
            [Ts::fmt($now), ...$storeIds, $reason]
        );
    }

    /**
     * Enabled views the admin system message reports: over the product cap, or exhausted.
     *
     * @param list<int> $enabledStoreIds
     * @return list<array{store_id: int, state: string, reason: ?string}>
     */
    public function viewsNeedingAttention(array $enabledStoreIds): array
    {
        if ($enabledStoreIds === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            "SELECT store_id, state, reason FROM {$this->t()}
             WHERE store_id IN ({$this->placeholders($enabledStoreIds)})
               AND (state = 'exhausted' OR (state = 'blocked' AND reason = 'too_many_products'))
             ORDER BY store_id",
            $enabledStoreIds
        );
        return array_map(static fn (array $r) => [
            'store_id' => (int) $r['store_id'],
            'state' => (string) $r['state'],
            'reason' => $r['reason'] === null ? null : (string) $r['reason'],
        ], $rows);
    }

    /**
     * Record account.
     *
     * @param int $storeId
     * @param string $accountId
     * @param string $apiKeyHash
     * @return void
     */
    public function recordAccount(int $storeId, string $accountId, string $apiKeyHash): void
    {
        $this->ensure($storeId);
        $this->db->execute(
            "UPDATE {$this->t()} SET account_id = ?, account_key_hash = ? WHERE store_id = ?",
            [$accountId, $apiKeyHash, $storeId]
        );
    }

    /**
     * Throttled until.
     *
     * @param int $storeId
     * @param \DateTimeImmutable $now
     * @return \DateTimeImmutable|null
     */
    public function throttledUntil(int $storeId, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $value = $this->db->fetchOne(
            "SELECT next_attempt_at FROM {$this->t()}
             WHERE store_id = ? AND state = 'retrying' AND retry_reason = 'throttled' AND next_attempt_at > ?",
            [$storeId, Ts::fmt($now)]
        );
        return Ts::parse($value === null ? null : (string) $value);
    }

    /**
     * Save snapshot.
     *
     * @param int $storeId
     * @param string $fingerprint
     * @param string $json
     * @param \DateTimeImmutable $now
     * @return void
     */
    public function saveSnapshot(int $storeId, string $fingerprint, string $json, \DateTimeImmutable $now): void
    {
        $this->ensure($storeId);
        $this->db->execute(
            "UPDATE {$this->t()} SET snapshot_fingerprint = ?, snapshot_json = ?, snapshot_fetched_at = ?
             WHERE store_id = ?",
            [$fingerprint, $json, Ts::fmt($now), $storeId]
        );
    }

    /**
     * Forget the fallback summary snapshot (its connection fingerprint no longer applies).
     *
     * @param int $storeId
     */
    public function clearSnapshot(int $storeId): void
    {
        $this->db->execute(
            "UPDATE {$this->t()} SET snapshot_fingerprint = NULL, snapshot_json = NULL, snapshot_fetched_at = NULL
             WHERE store_id = ?",
            [$storeId]
        );
    }

    /**
     * Set terminal.
     *
     * @param int $storeId
     * @param int $revision
     * @param string $state
     * @param string $reason
     * @return bool
     */
    private function setTerminal(int $storeId, int $revision, string $state, string $reason): bool
    {
        $changed = $this->db->execute(
            "UPDATE {$this->t()} SET state = ?, reason = ?, retry_reason = NULL, attempts = 0, next_attempt_at = NULL
             WHERE store_id = ? AND config_revision = ?",
            [$state, $reason, $storeId, $revision]
        );
        return $this->applied($changed, $storeId, $revision);
    }

    /**
     * Applied.
     *
     * MySQL reports CHANGED rows, so an UPDATE that rewrote identical values
     * returns 0. Revisions only ever increase, so if the revision still equals
     * the captured one now, it also did when the UPDATE ran: it was applied.
     *
     * @param int $changed
     * @param int $storeId
     * @param int $revision
     */
    private function applied(int $changed, int $storeId, int $revision): bool
    {
        if ($changed > 0) {
            return true;
        }
        return (int) $this->db->fetchOne(
            "SELECT config_revision FROM {$this->t()} WHERE store_id = ?",
            [$storeId]
        ) === $revision;
    }

    /**
     * Placeholders.
     *
     * @param list<int> $ids
     */
    private function placeholders(array $ids): string
    {
        return implode(', ', array_fill(0, count($ids), '?'));
    }

    /**
     * Run a store-id query.
     *
     * @param string $sql
     * @param list<scalar|null> $bind
     * @return list<int>
     */
    private function ids(string $sql, array $bind): array
    {
        return array_map(static fn (array $r) => (int) $r['store_id'], $this->db->fetchAll($sql, $bind));
    }
}

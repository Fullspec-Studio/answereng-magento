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
    private const RETRY_SECONDS = [300, 900];

    public function __construct(private readonly SqlExecutor $db)
    {
    }

    private function t(): string
    {
        return $this->db->table('answereng_sync_state');
    }

    public function ensure(int $storeId): void
    {
        $this->db->execute("INSERT IGNORE INTO {$this->t()} (store_id) VALUES (?)", [$storeId]);
    }

    public function get(int $storeId): SyncState
    {
        $this->ensure($storeId);
        return SyncState::fromRow((array) $this->db->fetchRow("SELECT * FROM {$this->t()} WHERE store_id = ?", [$storeId]));
    }

    /**
     * @param list<int> $storeIds
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
     * @param list<int> $enabledStoreIds
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
     * @param list<int> $enabledStoreIds
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

    public function recordTransient(int $storeId, int $revision, \DateTimeImmutable $now, ?int $throttleSeconds): bool
    {
        $throttleUntil = $throttleSeconds === null ? null : Ts::fmt($now->modify("+$throttleSeconds seconds"));
        $changed = $this->db->execute(
            // state and next_attempt_at read the OLD attempts; attempts is assigned last.
            "UPDATE {$this->t()} SET
                 state = IF(attempts + 1 >= ?, 'exhausted', 'retrying'),
                 next_attempt_at = IF(attempts + 1 >= ?, NULL, COALESCE(?, IF(attempts = 0, ?, ?))),
                 retry_reason = IF(attempts + 1 >= ?, NULL, ?),
                 attempts = attempts + 1
             WHERE store_id = ? AND config_revision = ?",
            [
                self::MAX_TRANSIENT_ATTEMPTS,
                self::MAX_TRANSIENT_ATTEMPTS, $throttleUntil,
                Ts::fmt($now->modify('+' . self::RETRY_SECONDS[0] . ' seconds')),
                Ts::fmt($now->modify('+' . self::RETRY_SECONDS[1] . ' seconds')),
                self::MAX_TRANSIENT_ATTEMPTS, $throttleSeconds === null ? null : 'throttled',
                $storeId, $revision,
            ]
        );
        return $this->applied($changed, $storeId, $revision);
    }

    /**
     * Pause the view. For a catalog-dependent reason (validation_failed, product_limit) a catalog edit
     * made at or after $startedAt already happened while the push ran — markDirty found nothing to
     * unpause then — so the view stays `ok` (and dirty) and the next debounced run sends the fixed
     * catalog instead of waiting for another edit.
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

    public function recordBlocked(int $storeId, int $revision, string $reason): bool
    {
        return $this->setTerminal($storeId, $revision, self::BLOCKED, $reason);
    }

    public function recordAccount(int $storeId, string $accountId, string $apiKeyHash): void
    {
        $this->ensure($storeId);
        $this->db->execute(
            "UPDATE {$this->t()} SET account_id = ?, account_key_hash = ? WHERE store_id = ?",
            [$accountId, $apiKeyHash, $storeId]
        );
    }

    public function throttledUntil(int $storeId, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $value = $this->db->fetchOne(
            "SELECT next_attempt_at FROM {$this->t()}
             WHERE store_id = ? AND state = 'retrying' AND retry_reason = 'throttled' AND next_attempt_at > ?",
            [$storeId, Ts::fmt($now)]
        );
        return Ts::parse($value === null ? null : (string) $value);
    }

    public function saveSnapshot(int $storeId, string $fingerprint, string $json, \DateTimeImmutable $now): void
    {
        $this->ensure($storeId);
        $this->db->execute(
            "UPDATE {$this->t()} SET snapshot_fingerprint = ?, snapshot_json = ?, snapshot_fetched_at = ?
             WHERE store_id = ?",
            [$fingerprint, $json, Ts::fmt($now), $storeId]
        );
    }

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
     * MySQL reports CHANGED rows, so an UPDATE that rewrote identical values
     * returns 0. Revisions only ever increase, so if the revision still equals
     * the captured one now, it also did when the UPDATE ran: it was applied.
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
     * @param list<int> $ids
     */
    private function placeholders(array $ids): string
    {
        return implode(', ', array_fill(0, count($ids), '?'));
    }

    /**
     * @param list<scalar|null> $bind
     * @return list<int>
     */
    private function ids(string $sql, array $bind): array
    {
        return array_map(static fn (array $r) => (int) $r['store_id'], $this->db->fetchAll($sql, $bind));
    }
}

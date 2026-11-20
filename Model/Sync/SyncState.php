<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

use Fullspec\AnswerEng\Model\Support\Ts;

class SyncState
{
    /**
     * Constructor.
     *
     * @param int $storeId
     * @param bool $dirty
     * @param \DateTimeImmutable|null $lastChangeAt
     * @param string $state
     * @param string|null $reason
     * @param string|null $retryReason
     * @param int $attempts
     * @param \DateTimeImmutable|null $nextAttemptAt
     * @param int $configRevision
     * @param string|null $settingsHash
     * @param \DateTimeImmutable|null $lastSyncAt
     * @param int|null $productCount
     * @param string|null $syncedStoreKey
     * @param string|null $accountId
     * @param string|null $accountKeyHash
     * @param string|null $snapshotJson
     * @param string|null $snapshotFingerprint
     * @param \DateTimeImmutable|null $snapshotFetchedAt
     * @param string|null $snapshotFixesJson
     * @param string|null $snapshotAppliedJson
     */
    public function __construct(
        public readonly int $storeId,
        public readonly bool $dirty,
        public readonly ?\DateTimeImmutable $lastChangeAt,
        public readonly string $state,
        public readonly ?string $reason,
        public readonly ?string $retryReason,
        public readonly int $attempts,
        public readonly ?\DateTimeImmutable $nextAttemptAt,
        public readonly int $configRevision,
        public readonly ?string $settingsHash,
        public readonly ?\DateTimeImmutable $lastSyncAt,
        public readonly ?int $productCount,
        public readonly ?string $syncedStoreKey,
        public readonly ?string $accountId,
        public readonly ?string $accountKeyHash,
        public readonly ?string $snapshotJson,
        public readonly ?string $snapshotFingerprint,
        public readonly ?\DateTimeImmutable $snapshotFetchedAt,
        public readonly ?string $snapshotFixesJson = null,
        public readonly ?string $snapshotAppliedJson = null
    ) {
    }

    /**
     * From row.
     *
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $str = static fn (string $k): ?string => $row[$k] === null ? null : (string) $row[$k];
        return new self(
            (int) $row['store_id'],
            (int) $row['dirty'] === 1,
            Ts::parse($str('last_change_at')),
            (string) $row['state'],
            $str('reason'),
            $str('retry_reason'),
            (int) $row['attempts'],
            Ts::parse($str('next_attempt_at')),
            (int) $row['config_revision'],
            $str('settings_hash'),
            Ts::parse($str('last_sync_at')),
            $row['product_count'] === null ? null : (int) $row['product_count'],
            $str('synced_store_key'),
            $str('account_id'),
            $str('account_key_hash'),
            $str('snapshot_json'),
            $str('snapshot_fingerprint'),
            Ts::parse($str('snapshot_fetched_at')),
            $str('snapshot_fixes_json'),
            $str('snapshot_applied_json')
        );
    }
}

<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

use Fullspec\AnswerEng\Model\Db\SqlExecutorInterface;
use Fullspec\AnswerEng\Model\Support\Ts;

/**
 * Rolling per-view log shown in the admin panel.
 * Detail must already be sanitized.
 */
class SyncLog
{
    public const KEEP = 20;

    /**
     * Constructor.
     *
     * @param SqlExecutorInterface $db
     */
    public function __construct(private readonly SqlExecutorInterface $db)
    {
    }

    /**
     * Append.
     *
     * @param int $storeId
     * @param \DateTimeImmutable $at
     * @param string $source
     * @param string $outcome
     * @param string|null $detail
     * @param string|null $adminUser
     * @return void
     */
    public function append(
        int $storeId,
        \DateTimeImmutable $at,
        string $source,
        string $outcome,
        ?string $detail = null,
        ?string $adminUser = null
    ): void {
        $t = $this->db->table('answereng_sync_log');
        $this->db->execute(
            "INSERT INTO $t (store_id, created_at, source, outcome, detail, admin_user) VALUES (?, ?, ?, ?, ?, ?)",
            [$storeId, Ts::fmt($at), $source, $outcome,
                $detail === null ? null : mb_substr($detail, 0, 1000), $adminUser]
        );
        $this->db->execute(
            "DELETE FROM $t WHERE store_id = ? AND log_id NOT IN (
                 SELECT log_id FROM (
                     SELECT log_id FROM $t WHERE store_id = ? ORDER BY log_id DESC LIMIT " . self::KEEP . "
                 ) AS keep_rows)",
            [$storeId, $storeId]
        );
    }

    /**
     * Recent.
     *
     * @param int $storeId
     * @param int $limit
     * @return list<array<string,mixed>>
     */
    public function recent(int $storeId, int $limit = self::KEEP): array
    {
        $t = $this->db->table('answereng_sync_log');
        return $this->db->fetchAll(
            "SELECT created_at, source, outcome, detail, admin_user FROM $t
             WHERE store_id = ? ORDER BY log_id DESC LIMIT " . max(1, min($limit, self::KEEP)),
            [$storeId]
        );
    }
}

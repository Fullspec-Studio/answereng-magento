<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Registry;

use Fullspec\AnswerEng\Model\Db\SqlExecutor;
use Fullspec\AnswerEng\Model\Support\Ts;

/**
 * Spec §7.1: remote identities (engine URL + account + store key) are kept
 * separate from the views that pushed to them, so a key change, a
 * connection change or two views sharing a key never lose track of a store.
 */
class RemoteStoreRegistry
{
    public function __construct(private readonly SqlExecutor $db)
    {
    }

    private function stores(): string
    {
        return $this->db->table('answereng_remote_store');
    }

    private function links(): string
    {
        return $this->db->table('answereng_remote_store_view');
    }

    public function recordPush(string $engineUrl, string $accountId, string $storeKey, int $storeId, \DateTimeImmutable $now): int
    {
        // purge_after is assigned before remote_status: it must read the old status.
        $this->db->execute(
            "INSERT INTO {$this->stores()} (engine_url, account_id, store_key, remote_status, first_synced_at)
             VALUES (?, ?, ?, 'active', ?)
             ON DUPLICATE KEY UPDATE
                 remote_store_id = LAST_INSERT_ID(remote_store_id),
                 purge_after = IF(remote_status = 'deleted', NULL, purge_after),
                 remote_status = IF(remote_status = 'deleted', 'active', remote_status)",
            [$engineUrl, $accountId, $storeKey, Ts::fmt($now)]
        );
        $id = $this->db->lastInsertId();
        $this->db->execute(
            "INSERT INTO {$this->links()} (remote_store_id, store_id, last_synced_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE last_synced_at = VALUES(last_synced_at)",
            [$id, $storeId, Ts::fmt($now)]
        );
        return $id;
    }

    /** @return list<RemoteStore> newest link first */
    public function forView(int $storeId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT s.*, l.last_synced_at FROM {$this->stores()} s
             JOIN {$this->links()} l ON l.remote_store_id = s.remote_store_id
             WHERE l.store_id = ? ORDER BY l.last_synced_at DESC, s.remote_store_id DESC",
            [$storeId]
        );
        return array_map([$this, 'row'], $rows);
    }

    public function get(int $id): ?RemoteStore
    {
        $row = $this->db->fetchRow(
            "SELECT s.*, NULL AS last_synced_at FROM {$this->stores()} s WHERE s.remote_store_id = ?",
            [$id]
        );
        return $row === null ? null : $this->row($row);
    }

    /** @return list<int> */
    public function viewsOf(int $id): array
    {
        return array_map(static fn (array $r) => (int) $r['store_id'], $this->db->fetchAll(
            "SELECT store_id FROM {$this->links()} WHERE remote_store_id = ? ORDER BY store_id",
            [$id]
        ));
    }

    public function setStatus(int $id, string $status, ?\DateTimeImmutable $purgeAfter): void
    {
        $this->db->execute(
            "UPDATE {$this->stores()} SET remote_status = ?, purge_after = ? WHERE remote_store_id = ?",
            [$status, $purgeAfter === null ? null : Ts::fmt($purgeAfter), $id]
        );
    }

    /** Local only: drops this view's link, and the identity with its last link. */
    public function forget(int $id, int $storeId): void
    {
        $this->db->execute("DELETE FROM {$this->links()} WHERE remote_store_id = ? AND store_id = ?", [$id, $storeId]);
        $this->db->execute(
            "DELETE FROM {$this->stores()} WHERE remote_store_id = ?
               AND NOT EXISTS (SELECT 1 FROM {$this->links()} WHERE remote_store_id = ?)",
            [$id, $id]
        );
    }

    /** @param array<string,mixed> $row */
    private function row(array $row): RemoteStore
    {
        return new RemoteStore(
            (int) $row['remote_store_id'],
            (string) $row['engine_url'],
            (string) $row['account_id'],
            (string) $row['store_key'],
            (string) $row['remote_status'],
            Ts::parse($row['purge_after'] === null ? null : (string) $row['purge_after']),
            Ts::parse($row['last_synced_at'] === null ? null : (string) $row['last_synced_at'])
        );
    }
}

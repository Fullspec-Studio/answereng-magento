<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

final class SchemaTest extends DbTestCase
{
    public function testDefaults(): void
    {
        $this->db->execute('INSERT INTO answereng_sync_state (store_id) VALUES (?)', [1]);
        $row = $this->db->fetchRow('SELECT * FROM answereng_sync_state WHERE store_id = 1');
        self::assertSame(['0', 'ok', '0', '1'],
            [$row['dirty'], $row['state'], $row['attempts'], $row['config_revision']]);
    }

    public function testRemoteIdentityIsUnique(): void
    {
        $sql = 'INSERT INTO answereng_remote_store (engine_url, account_id, store_key, first_synced_at) VALUES (?, ?, ?, ?)';
        $this->db->execute($sql, ['https://e', 'acct_1', 's', '2026-10-07 00:00:00']);
        $this->expectException(\PDOException::class);
        $this->db->execute($sql, ['https://e', 'acct_1', 's', '2026-10-07 00:00:00']);
    }

    public function testViewLinksCascadeWithTheIdentity(): void
    {
        $this->db->execute('INSERT INTO answereng_remote_store (engine_url, account_id, store_key, first_synced_at) VALUES (?, ?, ?, ?)',
            ['https://e', 'acct_1', 's', '2026-10-07 00:00:00']);
        $id = $this->db->lastInsertId();
        $this->db->execute('INSERT INTO answereng_remote_store_view (remote_store_id, store_id, last_synced_at) VALUES (?, ?, ?)',
            [$id, 1, '2026-10-07 00:00:00']);
        $this->db->execute('DELETE FROM answereng_remote_store WHERE remote_store_id = ?', [$id]);
        self::assertSame('0', (string) $this->db->fetchOne('SELECT COUNT(*) FROM answereng_remote_store_view'));
    }
}

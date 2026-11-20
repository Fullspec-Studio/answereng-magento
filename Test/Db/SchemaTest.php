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

    public function testSnapshotColumnsHoldFixListsAsMediumtext(): void
    {
        $types = [];
        foreach ($this->db->fetchAll(
            "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'answereng_sync_state'
               AND COLUMN_NAME IN ('snapshot_json', 'snapshot_fixes_json', 'snapshot_applied_json')
             ORDER BY COLUMN_NAME"
        ) as $row) {
            $types[$row['COLUMN_NAME']] = [$row['DATA_TYPE'], $row['IS_NULLABLE']];
        }
        self::assertSame([
            'snapshot_applied_json' => ['mediumtext', 'YES'],
            'snapshot_fixes_json' => ['mediumtext', 'YES'],
            'snapshot_json' => ['mediumtext', 'YES'],
        ], $types);
        $whitelist = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/etc/db_schema_whitelist.json'), true);
        self::assertTrue($whitelist['answereng_sync_state']['column']['snapshot_fixes_json'] ?? false);
        self::assertTrue($whitelist['answereng_sync_state']['column']['snapshot_applied_json'] ?? false);
    }
}

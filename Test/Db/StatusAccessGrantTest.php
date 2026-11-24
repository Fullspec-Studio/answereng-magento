<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Model\Setup\StatusAccessGrant;

/** Upgrading from 0.1.0: roles that had an AnswerEng permission keep reaching Catalog › AnswerEng. */
final class StatusAccessGrantTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Magento's authorization_rule, reduced to the columns the grant touches.
        $this->db->execute('DROP TABLE IF EXISTS authorization_rule');
        $this->db->execute('CREATE TABLE authorization_rule (
            rule_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            role_id INT UNSIGNED NOT NULL DEFAULT 0,
            resource_id VARCHAR(255) NULL,
            privileges VARCHAR(20) NULL,
            permission VARCHAR(10) NULL,
            KEY AUTHORIZATION_RULE_RESOURCE_ID_ROLE_ID (resource_id, role_id))');
    }

    private function rule(int $role, string $resource, string $permission): void
    {
        $this->db->execute(
            'INSERT INTO authorization_rule (role_id, resource_id, permission) VALUES (?, ?, ?)',
            [$role, $resource, $permission]
        );
    }

    /** @return array<int, string> role_id => permission for the status resource */
    private function statusRules(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT role_id, permission FROM authorization_rule WHERE resource_id = 'Fullspec_AnswerEng::status'"
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['role_id']] = (string) $row['permission'];
        }
        ksort($out);
        return $out;
    }

    public function testGrantsStatusToRolesThatHadAnAnswerEngPermission(): void
    {
        // 0.1.0 shapes: settings + sync + delete; settings only; sync without settings row; no AnswerEng at all.
        foreach (['config', 'sync', 'delete_store'] as $r) {
            $this->rule(10, "Fullspec_AnswerEng::$r", 'allow');
        }
        $this->rule(11, 'Fullspec_AnswerEng::config', 'allow');
        $this->rule(11, 'Fullspec_AnswerEng::sync', 'deny');
        $this->rule(12, 'Fullspec_AnswerEng::sync', 'allow');
        $this->rule(13, 'Fullspec_AnswerEng::config', 'deny');
        $this->rule(13, 'Magento_Sales::sales', 'allow');

        self::assertSame(3, (new StatusAccessGrant($this->db))->grant());
        self::assertSame([10 => 'allow', 11 => 'allow', 12 => 'allow'], $this->statusRules());
    }

    public function testKeepsAnExistingStatusRuleAndRunsOnlyOnce(): void
    {
        $this->rule(20, 'Fullspec_AnswerEng::config', 'allow');
        $this->rule(20, 'Fullspec_AnswerEng::status', 'deny'); // an admin chose this after upgrading
        $this->rule(21, 'Fullspec_AnswerEng::config', 'allow');

        $grant = new StatusAccessGrant($this->db);
        self::assertSame(1, $grant->grant());
        self::assertSame(0, $grant->grant());
        self::assertSame([20 => 'deny', 21 => 'allow'], $this->statusRules());
    }
}

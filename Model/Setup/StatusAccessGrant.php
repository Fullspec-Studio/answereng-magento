<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Setup;

use Fullspec\AnswerEng\Model\Db\SqlExecutor;

/**
 * Upgrade from 0.1.0: the sync and delete permissions moved under the new Catalog › AnswerEng page
 * (Fullspec_AnswerEng::status). Roles saved under 0.1.0 have no rule for that resource, so they would
 * lose the page their actions now live on. Grant it to every role that allows an AnswerEng resource,
 * and leave alone any role that already has a status rule (an admin's later choice wins).
 */
class StatusAccessGrant
{
    public const STATUS = 'Fullspec_AnswerEng::status';
    public const LEGACY = [
        'Fullspec_AnswerEng::config',
        'Fullspec_AnswerEng::sync',
        'Fullspec_AnswerEng::delete_store',
    ];

    /**
     * @param SqlExecutor $db
     */
    public function __construct(private readonly SqlExecutor $db)
    {
    }

    /**
     * Grant the status resource to 0.1.0 roles that have no rule for it yet.
     *
     * @return int roles granted
     */
    public function grant(): int
    {
        $table = $this->db->table('authorization_rule');
        $roles = $this->db->fetchAll(
            "SELECT DISTINCT r.role_id FROM $table r
              WHERE r.permission = 'allow' AND r.resource_id IN (?, ?, ?)
                AND NOT EXISTS (SELECT 1 FROM $table s WHERE s.role_id = r.role_id AND s.resource_id = ?)",
            [...self::LEGACY, self::STATUS]
        );
        foreach ($roles as $row) {
            $this->db->execute(
                "INSERT INTO $table (role_id, resource_id, privileges, permission) VALUES (?, ?, NULL, 'allow')",
                [(int) $row['role_id'], self::STATUS]
            );
        }
        return count($roles);
    }
}

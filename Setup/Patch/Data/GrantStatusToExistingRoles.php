<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Setup\Patch\Data;

use Fullspec\AnswerEng\Model\Setup\StatusAccessGrant;
use Magento\Framework\Acl\Data\CacheInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/** Keeps 0.1.0 admin roles on Catalog › AnswerEng after upgrading (see StatusAccessGrant). */
class GrantStatusToExistingRoles implements DataPatchInterface
{
    /**
     * @param StatusAccessGrant $grant
     * @param CacheInterface $aclCache
     */
    public function __construct(
        private readonly StatusAccessGrant $grant,
        private readonly CacheInterface $aclCache
    ) {
    }

    /**
     * @inheritdoc
     */
    public function apply()
    {
        if ($this->grant->grant() > 0) {
            $this->aclCache->clean();
        }
        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases()
    {
        return [];
    }
}

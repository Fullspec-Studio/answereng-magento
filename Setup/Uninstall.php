<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

class Uninstall implements UninstallInterface
{
    private const TABLES = ['answereng_remote_store_view', 'answereng_remote_store', 'answereng_sync_log',
        'answereng_sync_state'];

    /**
     * @inheritdoc
     */
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();
        $connection = $setup->getConnection();
        foreach (self::TABLES as $table) {
            $connection->dropTable($setup->getTable($table));
        }
        $connection->delete($setup->getTable('core_config_data'), ['path LIKE ?' => 'answereng/%']);
        $setup->endSetup();
    }
}

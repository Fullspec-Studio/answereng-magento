<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The status panel lives on its own page under Catalog, not in Stores › Configuration:
 * configuration holds settings, the page holds status, results and actions.
 */
final class AdminXmlTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testCatalogMenuOpensTheStatusPage(): void
    {
        $menu = simplexml_load_file(self::ROOT . '/etc/adminhtml/menu.xml');
        self::assertNotFalse($menu);
        $items = [];
        foreach ($menu->menu->add as $add) {
            $items[(string) $add['id']] = $add;
        }
        $page = $items['Fullspec_AnswerEng::status_page'] ?? null;
        self::assertNotNull($page, 'menu item for the status page');
        self::assertSame('answereng/status/index', (string) $page['action']);
        self::assertSame('Fullspec_AnswerEng::status', (string) $page['resource']);
        $group = $items[(string) $page['parent']] ?? null;
        self::assertNotNull($group, 'the page sits in an AnswerEng group');
        self::assertSame('Magento_Catalog::catalog', (string) $group['parent']);
        self::assertFileExists(self::ROOT . '/Controller/Adminhtml/Status/Index.php');
        self::assertFileExists(self::ROOT . '/view/adminhtml/layout/answereng_status_index.xml');
    }

    public function testStatusPermissionSitsUnderCatalogWithSyncAndDeleteBeneathIt(): void
    {
        $acl = simplexml_load_file(self::ROOT . '/etc/acl.xml');
        self::assertNotFalse($acl);
        $status = $acl->xpath('//resource[@id="Magento_Catalog::catalog"]/resource[@id="Fullspec_AnswerEng::status"]');
        self::assertCount(1, $status);
        $children = array_map(static fn ($r) => (string) $r['id'], $status[0]->xpath('resource'));
        self::assertSame(['Fullspec_AnswerEng::sync', 'Fullspec_AnswerEng::delete_store'], $children);
        self::assertCount(1, $acl->xpath('//resource[@id="Magento_Config::config"]/resource[@id="Fullspec_AnswerEng::config"]'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function controllerResources(): array
    {
        return [
            'page' => ['Status/Index.php', 'Fullspec_AnswerEng::status'],
            'test connection' => ['Action/TestConnection.php', 'Fullspec_AnswerEng::status'],
            'check again' => ['Action/RefreshSummary.php', 'Fullspec_AnswerEng::status'],
            'sync now' => ['Action/SyncNow.php', 'Fullspec_AnswerEng::sync'],
            'clear' => ['Action/Clear.php', 'Fullspec_AnswerEng::sync'],
            'delete' => ['Action/Delete.php', 'Fullspec_AnswerEng::delete_store'],
            'cancel' => ['Action/Cancel.php', 'Fullspec_AnswerEng::delete_store'],
            'forget' => ['Action/Forget.php', 'Fullspec_AnswerEng::delete_store'],
        ];
    }

    /**
     * @dataProvider controllerResources
     */
    public function testEachControllerChecksItsPermission(string $file, string $resource): void
    {
        $source = (string) file_get_contents(self::ROOT . '/Controller/Adminhtml/' . $file);
        self::assertStringContainsString("ADMIN_RESOURCE = '" . $resource . "'", $source);
    }

    public function testActionsReturnToTheStatusPage(): void
    {
        $source = (string) file_get_contents(self::ROOT . '/Controller/Adminhtml/Action/AbstractAction.php');
        self::assertStringNotContainsString('system_config', $source);
        self::assertStringContainsString("'answereng/status/index'", $source);
    }
}

<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit;

use PHPUnit\Framework\TestCase;

final class ModuleXmlTest extends TestCase
{
    /**
     * Magento's UninstallCollector only runs Setup\Uninstall for modules listed in setup_module, and only a
     * setup_version puts a module there. Found in the local end-to-end run: without it,
     * `module:uninstall --remove-data` left the tables and answereng/* config rows behind.
     */
    public function testDeclaresASetupVersionSoUninstallRuns(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/etc/module.xml');
        self::assertNotFalse($xml);
        self::assertSame('Fullspec_AnswerEng', (string) $xml->module['name']);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) $xml->module['setup_version']);
    }
}

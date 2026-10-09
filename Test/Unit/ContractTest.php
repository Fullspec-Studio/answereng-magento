<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Test/Fixtures/* is copied byte-for-byte from the engine (tests/fixtures) and
 * pinned by CONTRACT.sha256, the same manifest every connector carries.
 */
final class ContractTest extends TestCase
{
    public function testFixturesMatchTheManifest(): void
    {
        $dir = dirname(__DIR__) . '/Fixtures';
        $lines = file($dir . '/CONTRACT.sha256', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertNotEmpty($lines);
        foreach ($lines as $line) {
            [$hash, $relative] = preg_split('/\s+/', trim($line), 2);
            self::assertFileExists($dir . '/' . $relative);
            self::assertSame($hash, hash_file('sha256', $dir . '/' . $relative), $relative);
        }
    }

    public function testManifestCoversTheErrorContract(): void
    {
        $manifest = (string) file_get_contents(dirname(__DIR__) . '/Fixtures/CONTRACT.sha256');
        self::assertStringContainsString('error_codes.json', $manifest);
        self::assertStringContainsString('read_api/stores.json', $manifest);
    }
}

<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Config;

use Fullspec\AnswerEng\Model\Config\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    private function settings(array $o = []): Settings
    {
        return new Settings($o['storeId'] ?? 1, $o['enabled'] ?? true, $o['engineUrl'] ?? 'https://api.answereng.io',
            $o['apiKey'] ?? 'ae_1', $o['storeKey'] ?? 'shop.example.com', $o['brands'] ?? ['Acme'], $o['competitors'] ?? []);
    }

    public function testHashChangesWithAnySettingFingerprintOnlyWithConnection(): void
    {
        $base = $this->settings();
        $brands = $this->settings(['brands' => ['Acme', 'Acme Outdoor']]);
        $key = $this->settings(['apiKey' => 'ae_2']);
        self::assertNotSame($base->hash(), $brands->hash());
        self::assertSame($base->fingerprint(), $brands->fingerprint());
        self::assertNotSame($base->fingerprint(), $key->fingerprint());
        self::assertNotSame($base->hash(), $this->settings(['enabled' => false])->hash());
    }

    public function testApiKeyNeverAppearsInHashesOrFingerprints(): void
    {
        $s = $this->settings(['apiKey' => 'ae_supersecret']);
        self::assertStringNotContainsString('supersecret', $s->hash() . $s->fingerprint() . $s->apiKeyHash());
    }

    public function testConnectionUsesTheEffectiveEngineUrl(): void
    {
        self::assertSame('https://api.answereng.io', $this->settings()->connection()->engineUrl);
    }

    public function testOnlyTheAnswerEngApiCountsAsTheProductionEngine(): void
    {
        self::assertTrue($this->settings()->usesProductionEngine());
        self::assertTrue($this->settings(['engineUrl' => 'HTTPS://API.answereng.io'])->usesProductionEngine());
        self::assertFalse($this->settings(['engineUrl' => 'http://host.docker.internal:8895'])->usesProductionEngine());
        self::assertFalse($this->settings(['engineUrl' => 'https://api.answereng.io.evil.example'])->usesProductionEngine());
    }
}

<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Model\Admin\SystemMessage;
use Fullspec\AnswerEng\Model\Config\Settings;
use Fullspec\AnswerEng\Model\Config\SettingsProviderInterface;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Fullspec\AnswerEng\Test\Support\FakeSettings;
use Fullspec\AnswerEng\Test\Support\FixedClock;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Notification\MessageInterface;

final class SystemMessageTest extends DbTestCase
{
    private FakeSettings $settings;
    private SyncStateRepository $state;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new FakeSettings();
        $this->state = new SyncStateRepository($this->db);
        foreach ([1, 2, 3] as $id) {
            $this->settings->put($id);
            $this->state->ensure($id);
        }
    }

    private function message(): SystemMessage
    {
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(static fn (string $route) => "/admin/$route");
        $named = new class ($this->settings) implements SettingsProviderInterface {
            public function __construct(private readonly FakeSettings $inner)
            {
            }

            public function forStore(int $storeId): Settings
            {
                return $this->inner->forStore($storeId);
            }

            public function allStoreIds(): array
            {
                return $this->inner->allStoreIds();
            }

            public function enabledStoreIds(): array
            {
                return $this->inner->enabledStoreIds();
            }

            public function storeIdsForWebsites(array $websiteIds): array
            {
                return $this->inner->storeIdsForWebsites($websiteIds);
            }

            public function storeName(int $storeId): string
            {
                return "View <$storeId>";   // markup in a store name must come out escaped
            }

            public function storeIdByCode(string $code): ?int
            {
                return $this->inner->storeIdByCode($code);
            }
        };
        return new SystemMessage($named, $this->state, $url, new Escaper());
    }

    private function exhaust(int $storeId, ?string $cause = null): void
    {
        $now = (new FixedClock())->now();
        for ($i = 0; $i < SyncStateRepository::MAX_TRANSIENT_ATTEMPTS; $i++) {
            $this->state->recordTransient($storeId, $this->state->get($storeId)->configRevision, $now, null, $cause);
        }
    }

    public function testHiddenWhileEveryViewIsFine(): void
    {
        $this->state->recordPaused(2, $this->state->get(2)->configRevision, 'invalid_key', (new FixedClock())->now());
        $this->state->recordBlocked(3, $this->state->get(3)->configRevision, 'no_brand_names');
        self::assertFalse($this->message()->isDisplayed());
    }

    public function testNamesOverCapAndExhaustedViewsEscapedWithALink(): void
    {
        $this->state->recordBlocked(1, $this->state->get(1)->configRevision, 'too_many_products');
        $this->exhaust(2);
        $this->exhaust(3, SyncStateRepository::CAUSE_EXPORT_FAILED);
        $m = $this->message();
        self::assertTrue($m->isDisplayed());
        self::assertSame(MessageInterface::SEVERITY_MAJOR, $m->getSeverity());
        $text = $m->getText();
        self::assertStringContainsString('store view View &lt;1&gt; has more than 5,000 eligible products', $text);
        self::assertStringContainsString('could not be reached for store view View &lt;2&gt; after 3 attempts', $text);
        self::assertStringContainsString('reading the catalog of store view View &lt;3&gt; failed 3 times', $text);
        self::assertStringContainsString('<a href="/admin/adminhtml/system_config/edit/section/answereng">', $text);
        self::assertStringNotContainsString('<1>', $text);
    }

    public function testIdentityFollowsTheAffectedSetAndDisabledViewsAreIgnored(): void
    {
        $this->exhaust(2);
        $first = $this->message()->getIdentity();
        self::assertSame($first, $this->message()->getIdentity());
        $this->state->recordBlocked(1, $this->state->get(1)->configRevision, 'too_many_products');
        self::assertNotSame($first, $this->message()->getIdentity());

        $s = $this->settings->forStore(1);
        $this->settings->stores[1] = new Settings(1, false, $s->engineUrl, $s->apiKey, $s->storeKey, $s->brandNames,
            $s->competitors);
        $this->settings->stores[2] = new Settings(2, false, $s->engineUrl, $s->apiKey, 'two', $s->brandNames,
            $s->competitors);
        self::assertFalse($this->message()->isDisplayed());   // disabled views never raise the message

        $this->settings->put(2);
        $this->state->markDirty([2], (new FixedClock())->now());   // a catalog change resets exhausted
        self::assertFalse($this->message()->isDisplayed());
    }
}

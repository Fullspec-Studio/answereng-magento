<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Cron\Reconcile;
use Fullspec\AnswerEng\Cron\SyncDirty;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Fullspec\AnswerEng\Test\Support\FakeRunner;
use Fullspec\AnswerEng\Test\Support\FakeSettings;
use Fullspec\AnswerEng\Test\Support\FixedClock;

final class CronTest extends DbTestCase
{
    public function testDebounceSyncsOnlyQuietDirtyEnabledViews(): void
    {
        $settings = new FakeSettings();
        $settings->put(1);
        $settings->put(2);
        $settings->put(3, ['enabled' => false]);
        $clock = new FixedClock();
        $state = new SyncStateRepository($this->db);
        $state->markDirty([1, 3], $clock->now()->modify('-10 minutes'));
        $state->markDirty([2], $clock->now()->modify('-1 minute'));
        $runner = new FakeRunner();
        (new SyncDirty($settings, $state, $runner, $clock))->execute();
        self::assertSame([[1, 'debounce', false]], $runner->calls);
    }

    public function testReconcileSyncsEveryEligibleEnabledView(): void
    {
        $settings = new FakeSettings();
        $settings->put(1);
        $settings->put(2);
        $state = new SyncStateRepository($this->db);
        $state->ensure(2);
        $rev = $state->get(2)->configRevision;
        $state->recordPaused(2, $rev, 'invalid_key');
        $runner = new FakeRunner();
        (new Reconcile($settings, $state, $runner, new FixedClock()))->execute();
        self::assertSame([[1, 'reconcile', false]], $runner->calls);
    }
}

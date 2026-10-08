<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Model\Sync\ChangeTracker;
use Fullspec\AnswerEng\Model\Sync\ConfigChangeHandler;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Fullspec\AnswerEng\Test\Support\FakeSettings;
use Fullspec\AnswerEng\Test\Support\FixedClock;

final class ChangeTrackingTest extends DbTestCase
{
    public function testMarksOnlyEnabledViewsOfTheGivenWebsites(): void
    {
        $settings = new FakeSettings();
        $settings->put(1, [], 1);
        $settings->put(2, [], 2);
        $settings->put(3, ['enabled' => false], 1);
        $state = new SyncStateRepository($this->db);
        $tracker = new ChangeTracker($settings, $state, new FixedClock());
        $tracker->markWebsites([1]);
        self::assertSame([true, false, false], [$state->get(1)->dirty, $state->get(2)->dirty, $state->get(3)->dirty]);
        $tracker->markAll();
        self::assertTrue($state->get(2)->dirty);
        self::assertFalse($state->get(3)->dirty);
    }

    public function testConfigChangesBumpExactlyTheViewsWhoseEffectiveSettingsChanged(): void
    {
        $settings = new FakeSettings();
        $settings->put(1);
        $settings->put(2);
        $state = new SyncStateRepository($this->db);
        $handler = new ConfigChangeHandler($settings, $state, new FixedClock());
        self::assertSame([1, 2], $handler->handle());       // first save records hashes
        self::assertSame([], $handler->handle());           // nothing changed
        $settings->put(2, ['brands' => ['Acme', 'New Brand']]);
        self::assertSame([2], $handler->handle());
        self::assertSame([2, 3], [$state->get(1)->configRevision, $state->get(2)->configRevision]);
    }
}

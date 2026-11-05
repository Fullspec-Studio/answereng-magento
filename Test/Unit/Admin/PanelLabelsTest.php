<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Admin;

use Fullspec\AnswerEng\Model\Admin\PanelLabels;
use PHPUnit\Framework\TestCase;

/** The panel shows these instead of the stored codes ("ok", "deletion_requested", "HTTP 200"). */
final class PanelLabelsTest extends TestCase
{
    public function testStateLabelsAndTones(): void
    {
        self::assertSame(['label' => 'Synced', 'tone' => 'good'], PanelLabels::state('ok', null, '2026-11-05 21:59'));
        self::assertSame('Not synced yet', PanelLabels::state('ok', null, null)['label']);
        self::assertSame('Catalog cleared', PanelLabels::state('ok', 'catalog_cleared', '2026-11-05 21:59')['label']);
        self::assertSame('warn', PanelLabels::state('retrying', 'run_in_progress', null)['tone']);
        self::assertSame('warn', PanelLabels::state('paused', 'store_limit', null)['tone']);
        self::assertSame(['label' => 'Sync failed', 'tone' => 'bad'], PanelLabels::state('exhausted', null, null));
        self::assertSame('bad', PanelLabels::state('blocked', 'too_many_products', null)['tone']);
        self::assertSame(['label' => 'mystery', 'tone' => 'neutral'], PanelLabels::state('mystery', null, null));
    }

    public function testEveryLoggedOutcomeHasALabel(): void
    {
        $outcomes = ['pushed', 'cleared', 'catalog_cleared', 'deletion_requested', 'deletion_cancelled', 'forgotten',
            'skipped', 'superseded', 'retrying', 'throttled', 'paused', 'exhausted', 'blocked', 'failed'];
        foreach ($outcomes as $outcome) {
            self::assertNotSame($outcome, PanelLabels::outcome($outcome)['label'], $outcome);
        }
        self::assertSame(['label' => 'Sent', 'tone' => 'good'], PanelLabels::outcome('pushed'));
        self::assertSame('new_code', PanelLabels::outcome('new_code')['label']);
    }

    public function testSourceNamesTheAdminUser(): void
    {
        self::assertSame('jane', PanelLabels::source('admin', 'jane'));
        self::assertSame('Command line · jane', PanelLabels::source('cli', 'jane'));
        self::assertSame('Admin panel', PanelLabels::source('admin', null));
        self::assertSame('Product change', PanelLabels::source('debounce', null));
        self::assertSame('Nightly check', PanelLabels::source('reconcile', ''));
    }

    public function testDetailDropsPlainSuccessAndFormatsThePurgeDate(): void
    {
        self::assertSame('', PanelLabels::detail('HTTP 200'));
        self::assertSame('', PanelLabels::detail(null));
        self::assertSame('HTTP 504', PanelLabels::detail('HTTP 504'));
        self::assertSame('shop-1 deletes 12 Nov 2026, 21:55 UTC',
            PanelLabels::detail('shop-1 purge_after=2026-11-12 21:55:37'));
    }

    public function testWhenFormatsUtcTimestamps(): void
    {
        self::assertSame('5 Nov 2026, 21:59', PanelLabels::when('2026-11-05 21:59'));
        self::assertSame('28 Sep 2026, 06:00', PanelLabels::when('2026-09-28T06:00:12+00:00'));
        self::assertSame('–', PanelLabels::when(null));
        self::assertSame('not a date', PanelLabels::when('not a date'));
    }
}

<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Admin;

use Fullspec\AnswerEng\Model\Admin\TrendChart;
use PHPUnit\Framework\TestCase;

/** Review Focus 3: the trend chart's edge cases. */
final class TrendChartTest extends TestCase
{
    private const W = 480;
    private const H = 200;

    private static function point(string $at, mixed $rate, mixed $low = null, mixed $high = null): array
    {
        return ['started_at' => $at, 'rate' => $rate, 'low' => $low ?? $rate, 'high' => $high ?? $rate];
    }

    /** @return list<array{0: float, 1: float}> */
    private static function coords(string $points): array
    {
        return array_map(
            static fn (string $p): array => array_map('floatval', explode(',', $p)),
            preg_split('/\s+/', trim($points)) ?: []
        );
    }

    private static function assertInsideTheBox(array $g): void
    {
        foreach (array_merge(self::coords($g['line']), self::coords($g['band'])) as [$x, $y]) {
            self::assertTrue(is_finite($x) && is_finite($y));
            self::assertGreaterThanOrEqual(0, $x);
            self::assertLessThanOrEqual(self::W, $x);
            self::assertGreaterThanOrEqual(0, $y);
            self::assertLessThanOrEqual(self::H, $y);
        }
    }

    public function testFewerThanTwoValidPointsDrawsNothing(): void
    {
        self::assertNull(TrendChart::geometry([], self::W, self::H));
        self::assertNull(TrendChart::geometry([self::point('2026-09-14T06:00:00+00:00', 0.3)], self::W, self::H));
        self::assertNull(TrendChart::geometry([
            self::point('2026-09-14T06:00:00+00:00', 0.3),
            self::point('2026-09-21T06:00:00+00:00', 'n/a'),
            'garbage',
        ], self::W, self::H));
    }

    public function testFixtureTrendHasALinePerPointAndLabelsEndingWithNow(): void
    {
        $g = TrendChart::geometry([
            self::point('2026-09-14T06:00:08+00:00', 0.34, 0.29, 0.39),
            self::point('2026-09-21T06:00:09+00:00', 0.38, 0.33, 0.43),
            self::point('2026-09-28T06:00:12+00:00', 0.42, 0.37, 0.47),
        ], self::W, self::H);
        self::assertNotNull($g);
        self::assertCount(3, self::coords($g['line']));
        self::assertCount(6, self::coords($g['band']));
        self::assertSame(['Sep 14', 'Sep 21', 'now'], array_column($g['labels'], 'text'));
        self::assertInsideTheBox($g);
        // y grows downward: the rising trend ends higher on screen
        $line = self::coords($g['line']);
        self::assertLessThan($line[0][1], $line[2][1]);
    }

    public function testEqualPointsDrawAFlatLine(): void
    {
        $p = self::point('2026-09-14T06:00:00+00:00', 0.4, 0.35, 0.45);
        $g = TrendChart::geometry([$p, $p], self::W, self::H);
        self::assertNotNull($g);
        $line = self::coords($g['line']);
        self::assertSame($line[0][1], $line[1][1]);
        self::assertLessThan($line[1][0], $line[0][0]);
        self::assertInsideTheBox($g);
    }

    public function testLowEqualToHighIsAFlatBand(): void
    {
        $g = TrendChart::geometry([
            self::point('2026-09-14T06:00:00+00:00', 0.2, 0.2, 0.2),
            self::point('2026-09-21T06:00:00+00:00', 0.3, 0.3, 0.3),
        ], self::W, self::H);
        self::assertNotNull($g);
        $band = self::coords($g['band']);
        self::assertSame($band[0], $band[3]);
        self::assertSame($band[1], $band[2]);
    }

    public function testRatesOfZeroAndOneAndOutOfRangeStayInsideTheBox(): void
    {
        $g = TrendChart::geometry([
            self::point('2026-09-14T06:00:00+00:00', 0.0, -0.2, 0.0),
            self::point('2026-09-21T06:00:00+00:00', 1.0, 1.0, 1.4),
            self::point('not a date', 0.5, 0.0, 1.0),
        ], self::W, self::H);
        self::assertNotNull($g);
        self::assertInsideTheBox($g);
        self::assertSame('100%', $g['ticks'][array_key_last($g['ticks'])]['text']);
        self::assertSame('0%', $g['ticks'][0]['text']);
    }

    public function testAxisStartsAtZeroAndTopsAtSixtyForLowRates(): void
    {
        $g = TrendChart::geometry([
            self::point('2026-09-14T06:00:00+00:00', 0.1),
            self::point('2026-09-21T06:00:00+00:00', 0.12),
        ], self::W, self::H);
        self::assertNotNull($g);
        self::assertSame(['0%', '20%', '40%', '60%'], array_column($g['ticks'], 'text'));
    }
}

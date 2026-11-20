<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

/**
 * Geometry for the server-rendered visibility trend (WooCommerce `render_trend`): a line through the
 * rates, a band from low to high, and date labels. The y-axis always starts at zero and tops out at
 * 60% or the next 20% step above the highest `high`; rates are clamped to 0..1, so every point stays
 * inside the box.
 */
class TrendChart
{
    public const LEFT = 40;
    public const RIGHT = 14;
    public const TOP = 10;
    public const BOTTOM = 26;

    /**
     * The chart's shapes in a $width × $height box, or null with fewer than two valid points.
     *
     * @param array<int,mixed> $points {started_at, rate, low, high}, oldest first
     * @param int $width
     * @param int $height
     * @return array<string,mixed>|null keys line, band (SVG points), labels list<{x, text}>,
     *     ticks list<{y, text}>, dots list<{x, y}>, box {left, right, top, bottom}
     */
    public static function geometry(array $points, int $width, int $height): ?array
    {
        $clamp = static fn (float $r): float => max(0.0, min(1.0, $r));
        $valid = [];
        foreach ($points as $p) {
            if (!is_array($p) || !is_numeric($p['rate'] ?? null)) {
                continue;
            }
            $rate = $clamp((float) $p['rate']);
            $valid[] = [
                'at' => is_string($p['started_at'] ?? null) ? $p['started_at'] : null,
                'rate' => $rate,
                'low' => is_numeric($p['low'] ?? null) ? $clamp((float) $p['low']) : $rate,
                'high' => is_numeric($p['high'] ?? null) ? $clamp((float) $p['high']) : $rate,
            ];
        }
        $n = count($valid);
        if ($n < 2) {
            return null;
        }

        $left = (float) self::LEFT;
        $right = (float) max(self::LEFT + 1, $width - self::RIGHT);
        $top = (float) self::TOP;
        $bottom = (float) max(self::TOP + 1, $height - self::BOTTOM);
        $maxHigh = max(array_column($valid, 'high'));
        $steps = max(3, min(5, (int) ceil($maxHigh * 5 - 1e-9)));
        $ymax = $steps / 5;
        $x = static fn (int $i): float => round($left + $i * ($right - $left) / ($n - 1), 1);
        $y = static fn (float $r): float => round($bottom - ($r / $ymax) * ($bottom - $top), 1);

        $upper = [];
        $lower = [];
        $line = [];
        $dots = [];
        foreach ($valid as $i => $p) {
            $upper[] = $x($i) . ',' . $y($p['high']);
            $lower[] = $x($i) . ',' . $y($p['low']);
            $line[] = $x($i) . ',' . $y($p['rate']);
            $dots[] = ['x' => $x($i), 'y' => $y($p['rate'])];
        }

        $ticks = [];
        for ($s = 0; $s <= $steps; $s++) {
            $ticks[] = ['y' => $y($s / 5), 'text' => ($s * 20) . '%'];
        }

        $date = static function (?string $at): string {
            try {
                return $at === null || $at === '' ? ''
                    : (new \DateTimeImmutable($at, new \DateTimeZone('UTC')))
                        ->setTimezone(new \DateTimeZone('UTC'))->format('M j');
            } catch (\Exception) {
                return '';
            }
        };
        $labels = [];
        $every = max(1, (int) ceil($n / 5));
        $minGap = ($right - $left) / 8;
        foreach ($valid as $i => $p) {
            $last = $i === $n - 1;
            if (!$last && ($i % $every !== 0 || $x($i) > $right - $minGap)) {
                continue;   // keep clear of the "now" label
            }
            $labels[] = ['x' => $x($i), 'text' => $last ? (string) __('now') : $date($p['at'])];
        }

        return [
            'line' => implode(' ', $line),
            'band' => implode(' ', array_merge($upper, array_reverse($lower))),
            'labels' => $labels,
            'ticks' => $ticks,
            'dots' => $dots,
            'box' => ['left' => $left, 'right' => $right, 'top' => $top, 'bottom' => $bottom],
        ];
    }
}

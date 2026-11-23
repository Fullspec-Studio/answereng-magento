<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;

/**
 * Display labels for the status panel. The panel keeps the stored codes
 * (sync state, log outcome, log source) and shows these instead. Each label
 * carries a tone (good, warn, bad, neutral) that the template maps to a pill.
 */
class PanelLabels
{
    /**
     * Engine display names and series colors (WooCommerce `ENGINE_META`), matched by substring
     * against the raw engine or model id, in this order.
     */
    private const ENGINES = [
        'claude' => ['Claude', '#C15F3C'],
        'mistral' => ['Mistral', '#6C4FD8'],
        'llama' => ['Llama', '#0E7A6C'],
        'sonar' => ['Perplexity Sonar', '#1D4ED8'],
        'perplexity' => ['Perplexity Sonar', '#1D4ED8'],
    ];

    /** Color for an engine nobody named yet (WooCommerce fallback). */
    private const OTHER_ENGINE = '#B0367A';

    /**
     * Display name and series color for a raw engine id ("perplexity:sonar", "bedrock:…claude…").
     *
     * Pass a fix's `FixView::engineKey()`. Older engines send only the provider, and `bedrock` alone is
     * "Amazon Bedrock". Unknown ids show as given.
     *
     * @param string $key
     * @return array{label: string, color: string}
     */
    public static function engine(string $key): array
    {
        $normalized = strtolower(trim($key));
        foreach (self::ENGINES as $needle => [$label, $color]) {
            if (str_contains($normalized, $needle)) {
                return ['label' => $label, 'color' => $color];
            }
        }
        if ($normalized === 'bedrock') {
            return ['label' => 'Amazon Bedrock', 'color' => self::OTHER_ENGINE];
        }
        return ['label' => $key, 'color' => self::OTHER_ENGINE];
    }

    /** Plain names for the engine's fix kinds. */
    private const FIX_KINDS = [
        'copy' => 'Product description',
        'qa' => 'Q&A',
        'schema' => 'Structured data',
        'attribute' => 'Attribute',
    ];

    /** The engine marks a reason it has no data for with this prefix. */
    private const MISSING_PREFIX = 'insufficient_data:';

    /** The engine lists at most this many fixes per request. */
    public const FIX_LIST_LIMIT = 100;

    /**
     * Plain name for a fix kind ("qa" is "Q&A"); unknown kinds are humanized.
     *
     * @param string $kind
     * @return string
     */
    public static function fixKind(string $kind): string
    {
        $kind = strtolower(trim($kind));
        return self::FIX_KINDS[$kind] ?? ucfirst(str_replace('_', ' ', $kind));
    }

    /**
     * A diagnosis's reasons split into product data the engine found missing and everything else.
     *
     * "insufficient_data: product lacks material specifications (…)" becomes
     * "Material specifications (…)" under `missing`. Other reasons are capitalized. Duplicates are dropped.
     *
     * @param list<string> $reasons
     * @return array{missing: list<string>, other: list<string>}
     */
    public static function reasons(array $reasons): array
    {
        $out = ['missing' => [], 'other' => []];
        foreach ($reasons as $reason) {
            $reason = trim($reason);
            $bucket = 'other';
            if (stripos($reason, self::MISSING_PREFIX) === 0) {
                $bucket = 'missing';
                $reason = trim(substr($reason, strlen(self::MISSING_PREFIX)));
                $reason = (string) preg_replace('/^(the\s+)?product\s+(lacks|is missing|has no)\s+/i', '', $reason);
            }
            $reason = rtrim($reason, " .;");
            if ($reason === '') {
                continue;
            }
            $reason = ucfirst($reason);
            if (!in_array($reason, $out[$bucket], true)) {
                $out[$bucket][] = $reason;
            }
        }
        return $out;
    }

    /**
     * How many drafted fixes await review, as shown: the engine's full count when it sends one,
     * otherwise the listed count, with "+" once the list hits the engine's limit.
     *
     * @param int|null $total
     * @param int $listed
     * @return string
     */
    public static function fixCount(?int $total, int $listed): string
    {
        if ($total !== null) {
            return (string) max($total, $listed);
        }
        return $listed >= self::FIX_LIST_LIMIT ? $listed . '+' : (string) $listed;
    }

    /**
     * Change in percentage points between the last two trend rates; null with fewer than two points.
     *
     * @param array<int,array<string,mixed>> $trend
     * @return float|null
     */
    public static function delta(array $trend): ?float
    {
        $rates = [];
        foreach ($trend as $point) {
            if (is_numeric($point['rate'] ?? null)) {
                $rates[] = (float) $point['rate'];
            }
        }
        $n = count($rates);
        return $n < 2 ? null : ($rates[$n - 1] - $rates[$n - 2]) * 100;
    }

    /**
     * "▲ +4.0 vs last run", "▼ −2.5 vs last run", or "— no change vs last run" under 0.05 points.
     *
     * @param float $delta
     * @return string
     */
    public static function deltaText(float $delta): string
    {
        if (abs($delta) < 0.05) {
            return (string) __('— no change vs last run');
        }
        $points = ($delta > 0 ? '+' : '−') . number_format(abs($delta), 1);
        return ($delta > 0 ? '▲ ' : '▼ ') . (string) __('%1 vs last run', $points);
    }

    /**
     * A rate as a bare percentage: one decimal under 10%, whole above (WooCommerce `pct`).
     *
     * @param float $rate
     * @return string
     */
    public static function percent(float $rate): string
    {
        $value = $rate * 100;
        return number_format($value, $value < 10 ? 1 : 0);
    }

    /**
     * The label and tone for a view's sync state.
     *
     * @param string $state
     * @param string|null $reason
     * @param string|null $lastSyncAt
     * @param string|null $summaryState SummaryView state; "out_of_sync" turns a Synced view amber
     * @return array{label: string, tone: string}
     */
    public static function state(
        string $state,
        ?string $reason,
        ?string $lastSyncAt,
        ?string $summaryState = null
    ): array {
        if ($state === SyncStateRepository::OK && $summaryState === SummaryView::OUT_OF_SYNC) {
            return ['label' => (string) __('Out of sync'), 'tone' => 'warn'];
        }
        if ($state === SyncStateRepository::OK && $reason === 'catalog_cleared') {
            return ['label' => (string) __('Catalog cleared'), 'tone' => 'neutral'];
        }
        if ($state === SyncStateRepository::OK && $lastSyncAt === null) {
            return ['label' => (string) __('Not synced yet'), 'tone' => 'neutral'];
        }
        return match ($state) {
            SyncStateRepository::OK => ['label' => (string) __('Synced'), 'tone' => 'good'],
            SyncStateRepository::RETRYING => ['label' => (string) __('Retrying'), 'tone' => 'warn'],
            SyncStateRepository::PAUSED => ['label' => (string) __('Paused'), 'tone' => 'warn'],
            SyncStateRepository::EXHAUSTED => ['label' => (string) __('Sync failed'), 'tone' => 'bad'],
            SyncStateRepository::BLOCKED => ['label' => (string) __('Blocked'), 'tone' => 'bad'],
            default => ['label' => $state, 'tone' => 'neutral'],
        };
    }

    /**
     * The label and tone for a sync-log outcome.
     *
     * @param string $outcome
     * @return array{label: string, tone: string}
     */
    public static function outcome(string $outcome): array
    {
        return match ($outcome) {
            'pushed' => ['label' => (string) __('Sent'), 'tone' => 'good'],
            'cleared', 'catalog_cleared' => ['label' => (string) __('Catalog cleared'), 'tone' => 'neutral'],
            'deletion_requested' => ['label' => (string) __('Deletion requested'), 'tone' => 'bad'],
            'deletion_cancelled' => ['label' => (string) __('Deletion cancelled'), 'tone' => 'neutral'],
            'forgotten' => ['label' => (string) __('Forgotten'), 'tone' => 'neutral'],
            'skipped' => ['label' => (string) __('Skipped'), 'tone' => 'neutral'],
            'superseded' => ['label' => (string) __('Superseded'), 'tone' => 'neutral'],
            'retrying' => ['label' => (string) __('Retrying'), 'tone' => 'warn'],
            'throttled' => ['label' => (string) __('Rate limited'), 'tone' => 'warn'],
            'paused' => ['label' => (string) __('Paused'), 'tone' => 'warn'],
            'exhausted' => ['label' => (string) __('Gave up'), 'tone' => 'bad'],
            'blocked' => ['label' => (string) __('Blocked'), 'tone' => 'bad'],
            'failed' => ['label' => (string) __('Failed'), 'tone' => 'bad'],
            default => ['label' => $outcome, 'tone' => 'neutral'],
        };
    }

    /**
     * What started a logged sync: the admin user's name when one is recorded.
     *
     * @param string $source
     * @param string|null $adminUser
     * @return string
     */
    public static function source(string $source, ?string $adminUser): string
    {
        if ($source === 'admin' && $adminUser !== null && $adminUser !== '') {
            return $adminUser;
        }
        $label = match ($source) {
            'admin' => (string) __('Admin panel'),
            'cli' => (string) __('Command line'),
            'debounce' => (string) __('Product change'),
            'reconcile' => (string) __('Nightly check'),
            default => $source,
        };
        return $adminUser !== null && $adminUser !== '' ? $label . ' · ' . $adminUser : $label;
    }

    /**
     * The log detail worth reading: a plain "HTTP 200" says nothing the outcome doesn't.
     *
     * @param string|null $detail
     * @return string
     */
    public static function detail(?string $detail): string
    {
        $detail = trim((string) $detail);
        if ($detail === 'HTTP 200') {
            return '';
        }
        return (string) preg_replace_callback(
            '/purge_after=(\d{4}-\d\d-\d\d \d\d:\d\d)(:\d\d)?/',
            static fn (array $m): string => (string) __('deletes %1 UTC', self::when($m[1])),
            $detail
        );
    }

    /**
     * A UTC timestamp ("2026-11-05 21:59", "2026-09-28T06:00:12+00:00") as "5 Nov 2026, 21:59".
     *
     * Anything unparseable comes back unchanged.
     *
     * @param string|null $timestamp
     * @return string
     */
    public static function when(?string $timestamp): string
    {
        if ($timestamp === null || $timestamp === '') {
            return '–';
        }
        try {
            $at = new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return $timestamp;
        }
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('j M Y, H:i');
    }
}

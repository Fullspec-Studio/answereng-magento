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
     * The label and tone for a view's sync state.
     *
     * @param string $state
     * @param string|null $reason
     * @param string|null $lastSyncAt
     * @return array{label: string, tone: string}
     */
    public static function state(string $state, ?string $reason, ?string $lastSyncAt): array
    {
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

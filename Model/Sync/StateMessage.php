<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

/**
 * The merchant-facing sentence for a view's sync state (spec §4 and the
 * "Merchant sees" column of §6). Built only from the state, the reason code and
 * the sanitized ErrorDetail string ("HTTP 403 store_limit limit=1"), never from
 * engine prose.
 */
class StateMessage
{
    public static function for(
        string $state,
        ?string $reason,
        ?string $retryReason = null,
        string $detail = '',
        ?string $purgeAfter = null
    ): ?string {
        return match ($state) {
            SyncStateRepository::OK => $reason === 'catalog_cleared' ? self::cleared() : null,
            SyncStateRepository::RETRYING => match (true) {
                $retryReason === 'throttled' => (string) __('Rate limited by AnswerEng, retrying.'),
                $reason === 'run_in_progress' => (string) __('Analysis run in progress — will clear when it finishes.'),
                default => (string) __('AnswerEng is waking up or unreachable; retrying shortly.'),
            },
            SyncStateRepository::EXHAUSTED => (string) __('AnswerEng could not be reached after 3 attempts. '
                . 'The nightly sync will try again.'),
            SyncStateRepository::BLOCKED => self::blocked((string) $reason),
            SyncStateRepository::PAUSED => self::paused((string) $reason, $detail, $purgeAfter),
            default => null,
        };
    }

    public static function cleared(): string
    {
        return (string) __('No eligible products — the AnswerEng catalog is cleared and weekly analysis is paused '
            . 'until products return.');
    }

    public static function blocked(string $reason): string
    {
        return match ($reason) {
            'too_many_products' => (string) __('This store view has more than 5,000 eligible products, the AnswerEng '
                . 'limit, so it is not synced. Reduce the enabled, visible products on this website to 5,000 or fewer.'),
            'duplicate_store_key' => (string) __('Another enabled store view uses the same store key. Store keys must '
                . 'be unique.'),
            'no_api_key' => (string) __('Enter an API key to sync.'),
            'no_brand_names' => (string) __('Add at least one brand name to sync.'),
            'no_store_key' => (string) __('Set a store key to sync.'),
            default => (string) __('Not synced: %1.', $reason),
        };
    }

    public static function paused(string $reason, string $detail = '', ?string $purgeAfter = null): string
    {
        $limit = preg_match('/\blimit=(\d+)/', $detail, $m) === 1 ? $m[1] : null;
        $http = preg_match('/^HTTP (\d{3})/', $detail, $m) === 1 ? $m[1] : null;
        return match ($reason) {
            'invalid_key' => (string) __('API key rejected or account inactive — check the key.'),
            'store_limit' => ($limit !== null
                    ? (string) __('Your AnswerEng plan allows %1 store(s) and they are all in use.', $limit)
                    : (string) __('Your AnswerEng plan\'s stores are all in use.'))
                . ' ' . __('Disabling a view or clearing a catalog does not free a slot. Options: upgrade your plan; '
                . 'set this view\'s store key to one of your existing stores (Test connection lists them) if that is '
                . 'the store you meant; delete a store you no longer need (its slot frees when the 7-day undo window '
                . 'ends); or contact support@answereng.io.'),
            'product_limit' => $limit !== null
                ? (string) __('This catalog exceeds your plan\'s %1-product limit.', $limit)
                : (string) __('This catalog exceeds your plan\'s product limit.'),
            'store_owned_elsewhere' => (string) __('Store key is registered to another AnswerEng account — choose a '
                . 'different store key.'),
            'store_pending_deletion' => ($purgeAfter !== null
                    ? (string) __('This store is scheduled for deletion on %1 UTC.', $purgeAfter)
                    : (string) __('This store is scheduled for deletion.'))
                . ' ' . __('Cancel the deletion in the Danger zone to resume syncing.'),
            'validation_failed' => (string) __('Engine rejected the catalog data (%1).', $detail),
            default => $http !== null
                ? (string) __('Engine refused the request (HTTP %1).', $http)
                : (string) __('Engine refused the request.'),
        };
    }
}

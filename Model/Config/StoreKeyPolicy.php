<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Config;

/** Store keys are engine identities: trimmed, never case-folded, unique across enabled views. */
class StoreKeyPolicy
{
    /**
     * Normalize.
     *
     * @param string $key
     * @return string
     */
    public static function normalize(string $key): string
    {
        return trim($key);
    }

    /**
     * From base url.
     *
     * @param string $baseUrl
     * @return string
     */
    public static function fromBaseUrl(string $baseUrl): string
    {
        $host = parse_url(trim($baseUrl), PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : '';
    }

    /**
     * Whether the key looks like a local or private address rather than the shop's public domain.
     *
     * Covers localhost, IP addresses and development-only names such as *.test or *.local.
     * Used for a warning, never to block a save.
     *
     * @param string $key
     * @return bool
     */
    public static function looksLocal(string $key): bool
    {
        $key = strtolower(self::normalize($key));
        if ($key === '' || filter_var($key, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        foreach (['localhost', 'local', 'test', 'internal', 'invalid', 'example', 'lan', 'home.arpa'] as $suffix) {
            if ($key === $suffix || str_ends_with($key, '.' . $suffix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Assert unique.
     *
     * @param string $key
     * @param array<int,string> $otherKeysByStoreId
     */
    public static function assertUnique(string $key, array $otherKeysByStoreId): void
    {
        $key = self::normalize($key);
        foreach ($otherKeysByStoreId as $storeId => $other) {
            if ($key !== '' && self::normalize($other) === $key) {
                throw new DuplicateStoreKeyException((int) $storeId, $key);
            }
        }
    }
}

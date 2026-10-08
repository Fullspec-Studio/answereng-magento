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

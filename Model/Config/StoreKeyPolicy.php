<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Config;

/** Store keys are engine identities: trimmed, never case-folded, unique across enabled views. */
class StoreKeyPolicy
{
    public static function normalize(string $key): string
    {
        return trim($key);
    }

    public static function fromBaseUrl(string $baseUrl): string
    {
        $host = parse_url(trim($baseUrl), PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : '';
    }

    /**
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

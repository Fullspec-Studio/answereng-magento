<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

use Magento\Framework\App\CacheInterface;

class MagentoKeyValueCache implements KeyValueCacheInterface
{
    private const TAG = 'ANSWERENG';

    /**
     * Constructor.
     *
     * @param CacheInterface $cache
     */
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    /**
     * @inheritdoc
     */
    public function load(string $key): ?string
    {
        // CacheInterface::load() returns false on a miss at runtime; (string) maps that to ''.
        $value = (string) $this->cache->load($key);
        return $value !== '' ? $value : null;
    }

    /**
     * @inheritdoc
     */
    public function save(string $key, string $value, int $ttlSeconds): void
    {
        $this->cache->save($value, $key, [self::TAG], $ttlSeconds);
    }

    /**
     * @inheritdoc
     */
    public function remove(string $key): void
    {
        $this->cache->remove($key);
    }
}

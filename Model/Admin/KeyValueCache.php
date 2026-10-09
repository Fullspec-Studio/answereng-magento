<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

interface KeyValueCache
{
    /**
     * Cached value for the key, or null on a miss.
     *
     * @param string $key
     * @return string|null
     */
    public function load(string $key): ?string;

    /**
     * Store a value for the given number of seconds.
     *
     * @param string $key
     * @param string $value
     * @param int $ttlSeconds
     * @return void
     */
    public function save(string $key, string $value, int $ttlSeconds): void;

    /**
     * Remove.
     *
     * @param string $key
     * @return void
     */
    public function remove(string $key): void;
}

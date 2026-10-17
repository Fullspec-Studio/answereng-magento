<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Support;

use Fullspec\AnswerEng\Model\Admin\KeyValueCache;

final class ArrayCache implements KeyValueCache
{
    /** @var array<string,string> */
    public array $items = [];

    /** @var array<string,int> */
    public array $ttls = [];

    public function load(string $key): ?string
    {
        return $this->items[$key] ?? null;
    }

    public function save(string $key, string $value, int $ttlSeconds): void
    {
        $this->items[$key] = $value;
        $this->ttls[$key] = $ttlSeconds;
    }

    public function remove(string $key): void
    {
        unset($this->items[$key]);
    }
}

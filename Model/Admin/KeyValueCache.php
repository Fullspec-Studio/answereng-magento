<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

interface KeyValueCache
{
    public function load(string $key): ?string;

    public function save(string $key, string $value, int $ttlSeconds): void;

    public function remove(string $key): void;
}

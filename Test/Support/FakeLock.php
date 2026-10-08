<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Support;

use Fullspec\AnswerEng\Model\Support\SyncLock;

final class FakeLock implements SyncLock
{
    /** @var array<string,bool> */
    public array $held = [];

    public function acquire(string $name): bool
    {
        if (isset($this->held[$name])) {
            return false;
        }
        $this->held[$name] = true;
        return true;
    }

    public function release(string $name): void
    {
        unset($this->held[$name]);
    }
}

<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Support;

interface SyncLock
{
    /** Non-blocking: false when another process holds it. */
    public function acquire(string $name): bool;

    public function release(string $name): void;
}

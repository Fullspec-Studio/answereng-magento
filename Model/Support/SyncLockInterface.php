<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Support;

interface SyncLockInterface
{
    /**
     * Non-blocking: false when another process holds it.
     *
     * @param string $name
     */
    public function acquire(string $name): bool;

    /**
     * Release.
     *
     * @param string $name
     * @return void
     */
    public function release(string $name): void;
}

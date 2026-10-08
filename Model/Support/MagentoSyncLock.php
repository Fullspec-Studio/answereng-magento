<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Support;

use Magento\Framework\Lock\LockManagerInterface;

class MagentoSyncLock implements SyncLock
{
    public function __construct(private readonly LockManagerInterface $locks)
    {
    }

    public function acquire(string $name): bool
    {
        return $this->locks->lock($name, 0);
    }

    public function release(string $name): void
    {
        $this->locks->unlock($name);
    }
}

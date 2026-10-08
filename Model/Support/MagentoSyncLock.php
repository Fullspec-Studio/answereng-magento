<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Support;

use Magento\Framework\Lock\LockManagerInterface;

class MagentoSyncLock implements SyncLock
{
    /**
     * Constructor.
     *
     * @param LockManagerInterface $locks
     */
    public function __construct(private readonly LockManagerInterface $locks)
    {
    }

    /**
     * @inheritdoc
     */
    public function acquire(string $name): bool
    {
        return $this->locks->lock($name, 0);
    }

    /**
     * @inheritdoc
     */
    public function release(string $name): void
    {
        $this->locks->unlock($name);
    }
}

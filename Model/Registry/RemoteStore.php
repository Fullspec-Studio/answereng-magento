<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Registry;

class RemoteStore
{
    /**
     * Constructor.
     *
     * @param int $id
     * @param string $engineUrl
     * @param string $accountId
     * @param string $storeKey
     * @param string $remoteStatus
     * @param \DateTimeImmutable|null $purgeAfter
     * @param \DateTimeImmutable|null $lastSyncedAt
     */
    public function __construct(
        public readonly int $id,
        public readonly string $engineUrl,
        public readonly string $accountId,
        public readonly string $storeKey,
        public readonly string $remoteStatus,
        public readonly ?\DateTimeImmutable $purgeAfter,
        public readonly ?\DateTimeImmutable $lastSyncedAt
    ) {
    }
}

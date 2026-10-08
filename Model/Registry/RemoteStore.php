<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Registry;

class RemoteStore
{
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

<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

class Outcome
{
    /**
     * Constructor.
     *
     * @param OutcomeKind $kind
     * @param string|null $reason
     * @param string $detail
     * @param int|null $retryAfterSeconds
     * @param string|null $purgeAfter
     * @param int|null $limit
     */
    public function __construct(
        public readonly OutcomeKind $kind,
        public readonly ?string $reason,
        public readonly string $detail,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?string $purgeAfter = null,
        public readonly ?int $limit = null
    ) {
    }
}

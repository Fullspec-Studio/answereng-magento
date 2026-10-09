<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Support;

class SystemClock implements Clock
{
    /**
     * @inheritdoc
     */
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}

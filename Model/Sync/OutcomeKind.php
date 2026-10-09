<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

enum OutcomeKind: string
{
    case Success = 'success';
    case AlreadyClear = 'already_clear';
    case Transient = 'transient';
    case Throttled = 'throttled';
    case Paused = 'paused';
}

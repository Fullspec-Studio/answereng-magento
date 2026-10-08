<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Engine;

/** Mirrors the engine's tests/fixtures/error_codes.json (ErrorCodeTest pins it). */
enum ErrorCode: string
{
    case InvalidKey = 'invalid_key';
    case StoreLimit = 'store_limit';
    case ProductLimit = 'product_limit';
    case StoreOwnedElsewhere = 'store_owned_elsewhere';
    case ValidationFailed = 'validation_failed';
    case UnknownStore = 'unknown_store';
    case RunInProgress = 'run_in_progress';
    case StorePendingDeletion = 'store_pending_deletion';
    case ConfirmationMismatch = 'confirmation_mismatch';
    case PurgeInProgress = 'purge_in_progress';
    case InvalidRequest = 'invalid_request';
    case Throttled = 'throttled';
    case QuotaExceeded = 'quota_exceeded';
}

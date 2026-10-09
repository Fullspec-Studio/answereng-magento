<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

use Fullspec\AnswerEng\Model\Engine\EngineResult;

/** Spec §6 "Response classification": branches on status and `code` only. */
class Classifier
{
    public const MIN_THROTTLE_SECONDS = 300;
    public const CATALOG_DEPENDENT = ['validation_failed', 'product_limit'];
    private const PAUSING_CODES = ['invalid_key', 'store_limit', 'product_limit', 'store_owned_elsewhere',
        'store_pending_deletion', 'validation_failed'];

    /**
     * Classify.
     *
     * @param EngineResult $r
     * @param bool $isClear
     * @return Outcome
     */
    public function classify(EngineResult $r, bool $isClear = false): Outcome
    {
        $detail = ErrorDetail::of($r);
        $code = $r->code();
        if ($r->status === null || $r->status >= 500) {
            return new Outcome(OutcomeKind::Transient, null, $detail);
        }
        if ($r->isSuccess()) {
            return new Outcome(OutcomeKind::Success, null, 'HTTP ' . $r->status);
        }
        if ($r->status === 429) {
            return new Outcome(
                OutcomeKind::Throttled,
                $code ?? 'throttled',
                $detail,
                max(self::MIN_THROTTLE_SECONDS, $r->retryAfter ?? 0)
            );
        }
        if ($r->status === 409 && $code === 'run_in_progress') {
            return new Outcome(OutcomeKind::Transient, 'run_in_progress', $detail);
        }
        if ($isClear && $r->status === 404 && $code === 'unknown_store') {
            return new Outcome(OutcomeKind::AlreadyClear, null, $detail);
        }
        if ($code !== null && in_array($code, self::PAUSING_CODES, true)) {
            return new Outcome(OutcomeKind::Paused, $code, $detail, null, $r->purgeAfter(), $r->limit());
        }
        return new Outcome(OutcomeKind::Paused, 'unexpected', $detail);
    }
}

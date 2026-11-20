<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Engine\EngineClient;

/**
 * Mark a drafted fix applied, or move it back. Branches on the HTTP status only: the engine reuses
 * `unknown_store` for an unknown fix, so a 404 here always means the fix is gone. A 429 is the gateway
 * throttling before the request reached the engine, so it is a plain refusal, not an unconfirmed change.
 */
class FixActions
{
    /**
     * Constructor.
     *
     * @param SettingsProvider $settings
     * @param EngineClient $engine
     * @param ResultsProvider $results
     */
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly EngineClient $engine,
        private readonly ResultsProvider $results
    ) {
    }

    /**
     * Mark (or unmark) one fix applied.
     *
     * @param int $storeId
     * @param int $fixId
     * @param bool $applied
     * @return ActionResult
     */
    public function setApplied(int $storeId, int $fixId, bool $applied): ActionResult
    {
        $s = $this->settings->forStore($storeId);
        if (!$s->enabled || $s->apiKey === '' || $s->storeKey === '') {
            return new ActionResult(false, (string) __('Enable this store view and enter an API key first.'));
        }
        $r = $applied
            ? $this->engine->markApplied($s->connection(), $s->storeKey, $fixId)
            : $this->engine->unmarkApplied($s->connection(), $s->storeKey, $fixId);
        if ($r->isSuccess()) {
            $this->results->forgetFixes($storeId);
            return new ActionResult(true, $applied
                ? (string) __('Marked as applied.')
                : (string) __('Moved back to drafted fixes.'));
        }
        if ($r->status === null || $r->status >= 500) {
            $this->results->forgetFixes($storeId);
            return new ActionResult(false, (string) __(
                "Couldn't confirm that change. AnswerEng didn't respond in time. "
                . 'Refresh in a moment to see whether it was applied.'
            ));
        }
        if ($r->status === 404) {
            return new ActionResult(false, (string) __(
                'That fix no longer exists in AnswerEng. Refresh to see the current list.'
            ));
        }
        if ($r->status === 409 && $r->code() === 'store_pending_deletion') {
            return new ActionResult(false, (string) __(
                "This store is scheduled for deletion, so fixes can't be changed."
            ));
        }
        if ($r->status === 409) {
            return new ActionResult(false, (string) __(
                "AnswerEng refused that fix, so it can't be marked as applied."
            ));
        }
        return new ActionResult(false, (string) __("AnswerEng didn't accept that change. Nothing was changed."));
    }
}

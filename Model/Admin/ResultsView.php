<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

/**
 * Everything the status page shows from the engine: the summary and the fix lists.
 */
class ResultsView
{
    /**
     * Constructor.
     *
     * @param SummaryView $summary
     * @param list<FixView>|null $fixes suggested fixes; null when unavailable
     * @param list<FixView>|null $applied applied fixes; null hides the section
     * @param bool $fixesStale the lists come from the fallback snapshot
     */
    public function __construct(
        public readonly SummaryView $summary,
        public readonly ?array $fixes,
        public readonly ?array $applied,
        public readonly bool $fixesStale
    ) {
    }
}

<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Controller\Adminhtml\Action;

use Fullspec\AnswerEng\Model\Admin\ActionResult;
use Fullspec\AnswerEng\Model\Admin\SummaryProvider;
use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;

class RefreshSummary extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Fullspec_AnswerEng::config';

    /**
     * Constructor.
     *
     * @param Context $context
     * @param SettingsProvider $settings
     * @param SummaryProvider $summaries
     */
    public function __construct(
        Context $context,
        SettingsProvider $settings,
        private readonly SummaryProvider $summaries
    ) {
        parent::__construct($context, $settings);
    }

    /**
     * @inheritdoc
     */
    protected function perform(): Redirect
    {
        $this->summaries->forget($this->storeId());
        return $this->back(new ActionResult(true, (string) __('Checked for new results.')), true);
    }
}

<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Controller\Adminhtml\Action;

use Fullspec\AnswerEng\Model\Admin\ActionResult;
use Fullspec\AnswerEng\Model\Admin\ResultsProvider;
use Fullspec\AnswerEng\Model\Config\SettingsProviderInterface;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;

class RefreshSummary extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Fullspec_AnswerEng::status';

    /**
     * Constructor.
     *
     * @param Context $context
     * @param SettingsProviderInterface $settings
     * @param ResultsProvider $results
     */
    public function __construct(
        Context $context,
        SettingsProviderInterface $settings,
        private readonly ResultsProvider $results
    ) {
        parent::__construct($context, $settings);
    }

    /**
     * @inheritdoc
     */
    protected function perform(): Redirect
    {
        $this->results->forget($this->storeId());
        return $this->back(new ActionResult(true, (string) __('Checked for new results.')), true);
    }
}

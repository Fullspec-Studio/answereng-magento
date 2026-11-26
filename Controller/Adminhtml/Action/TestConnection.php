<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Controller\Adminhtml\Action;

use Fullspec\AnswerEng\Model\Admin\ActionResult;
use Fullspec\AnswerEng\Model\Admin\RemoteStoreActions;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Fullspec\AnswerEng\Model\Config\SettingsProviderInterface;

class TestConnection extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Fullspec_AnswerEng::status';

    /**
     * Constructor.
     *
     * @param Context $context
     * @param SettingsProviderInterface $settings
     * @param RemoteStoreActions $actions
     */
    public function __construct(
        Context $context,
        SettingsProviderInterface $settings,
        private readonly RemoteStoreActions $actions
    ) {
        parent::__construct($context, $settings);
    }

    /**
     * @inheritdoc
     */
    protected function perform(): Redirect
    {
        $report = $this->actions->testConnection($this->storeId());
        $message = $report->message;
        if ($report->existingKeys !== []) {
            $message .= ' ' . __('Stores in this workspace: %1.', implode(', ', $report->existingKeys));
        }
        return $this->back(new ActionResult($report->ok(), $message));
    }
}

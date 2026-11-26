<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Controller\Adminhtml\Action;

use Fullspec\AnswerEng\Model\Admin\RemoteStoreActions;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Fullspec\AnswerEng\Model\Config\SettingsProviderInterface;

class Clear extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Fullspec_AnswerEng::sync';

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
        return $this->back($this->actions->clear($this->storeId(), $this->adminUser()));
    }
}

<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Controller\Adminhtml\Action;

use Fullspec\AnswerEng\Model\Admin\RemoteStoreActions;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Fullspec\AnswerEng\Model\Config\SettingsProvider;

class Cancel extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Fullspec_AnswerEng::delete_store';

    /**
     * Constructor.
     *
     * @param Context $context
     * @param SettingsProvider $settings
     * @param RemoteStoreActions $actions
     */
    public function __construct(
        Context $context,
        SettingsProvider $settings,
        private readonly RemoteStoreActions $actions
    ) {
        parent::__construct($context, $settings);
    }

    /**
     * @inheritdoc
     */
    protected function perform(): Redirect
    {
        return $this->back($this->actions->cancel(
            $this->storeId(),
            (int) $this->getRequest()->getParam('identity_id'),
            $this->adminUser()
        ));
    }
}

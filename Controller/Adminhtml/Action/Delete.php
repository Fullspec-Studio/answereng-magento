<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Controller\Adminhtml\Action;

use Fullspec\AnswerEng\Model\Admin\RemoteStoreActions;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Fullspec\AnswerEng\Model\Config\SettingsProviderInterface;

class Delete extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Fullspec_AnswerEng::delete_store';

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
        $request = $this->getRequest();
        return $this->back($this->actions->delete(
            $this->storeId(),
            (int) $request->getParam('identity_id'),
            (string) $request->getParam('confirm_key'),
            $request->getParam('acknowledge') === '1',
            $this->adminUser()
        ));
    }
}

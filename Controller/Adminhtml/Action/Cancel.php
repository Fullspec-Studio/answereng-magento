<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Controller\Adminhtml\Action;

use Fullspec\AnswerEng\Model\Admin\RemoteStoreActions;
use Magento\Backend\App\Action\Context;

class Cancel extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Fullspec_AnswerEng::delete_store';

    public function __construct(Context $context, private readonly RemoteStoreActions $actions)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        return $this->back($this->actions->cancel(
            $this->storeId(),
            (int) $this->getRequest()->getParam('identity_id'),
            $this->adminUser()
        ));
    }
}

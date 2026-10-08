<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Controller\Adminhtml\Action;

use Fullspec\AnswerEng\Model\Admin\RemoteStoreActions;
use Magento\Backend\App\Action\Context;

class Delete extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Fullspec_AnswerEng::delete_store';

    public function __construct(Context $context, private readonly RemoteStoreActions $actions)
    {
        parent::__construct($context);
    }

    public function execute()
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

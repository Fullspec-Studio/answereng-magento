<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Controller\Adminhtml\Action;

use Fullspec\AnswerEng\Model\Admin\RemoteStoreActions;
use Magento\Backend\App\Action\Context;

class Clear extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Fullspec_AnswerEng::sync';

    public function __construct(Context $context, private readonly RemoteStoreActions $actions)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        return $this->back($this->actions->clear($this->storeId(), $this->adminUser()));
    }
}

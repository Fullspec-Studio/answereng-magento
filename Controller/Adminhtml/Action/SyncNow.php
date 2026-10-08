<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Controller\Adminhtml\Action;

use Fullspec\AnswerEng\Model\Admin\ActionResult;
use Fullspec\AnswerEng\Model\Sync\SyncRunner;
use Magento\Backend\App\Action\Context;

class SyncNow extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Fullspec_AnswerEng::sync';

    public function __construct(Context $context, private readonly SyncRunner $runner)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->runner->sync($this->storeId(), 'admin', true);
        return $this->back(new ActionResult(!$result->isError(), $result->message));
    }
}

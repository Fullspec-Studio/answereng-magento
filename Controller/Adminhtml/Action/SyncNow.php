<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Controller\Adminhtml\Action;

use Fullspec\AnswerEng\Model\Admin\ActionResult;
use Fullspec\AnswerEng\Model\Sync\SyncResult;
use Fullspec\AnswerEng\Model\Sync\SyncRunner;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Fullspec\AnswerEng\Model\Config\SettingsProvider;

class SyncNow extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Fullspec_AnswerEng::sync';

    public function __construct(Context $context, SettingsProvider $settings, private readonly SyncRunner $runner)
    {
        parent::__construct($context, $settings);
    }

    protected function perform(): Redirect
    {
        $result = $this->runner->sync($this->storeId(), 'admin', true);
        $ok = !$result->isError();
        $plain = !in_array($result->status, [SyncResult::PUSHED, SyncResult::CLEARED], true);
        return $this->back(new ActionResult($ok, $result->message), $ok && $plain);
    }
}

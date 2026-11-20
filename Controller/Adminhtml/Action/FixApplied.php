<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Controller\Adminhtml\Action;

use Fullspec\AnswerEng\Model\Admin\ActionResult;
use Fullspec\AnswerEng\Model\Admin\FixActions;
use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;

class FixApplied extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Fullspec_AnswerEng::fixes';

    /**
     * Constructor.
     *
     * @param Context $context
     * @param SettingsProvider $settings
     * @param FixActions $actions
     */
    public function __construct(
        Context $context,
        SettingsProvider $settings,
        private readonly FixActions $actions
    ) {
        parent::__construct($context, $settings);
    }

    /**
     * @inheritdoc
     */
    protected function perform(): Redirect
    {
        $request = $this->getRequest();
        $fixId = (int) $request->getParam('fix_id');
        $op = (string) $request->getParam('op');
        if ($fixId <= 0 || !in_array($op, ['mark', 'unmark'], true)) {
            return $this->back(new ActionResult(false, (string) __('Unknown fix.')));
        }
        return $this->back($this->actions->setApplied($this->storeId(), $fixId, $op === 'mark'));
    }
}

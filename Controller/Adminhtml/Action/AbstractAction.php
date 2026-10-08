<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Controller\Adminhtml\Action;

use Fullspec\AnswerEng\Model\Admin\ActionResult;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;

/** POST-only, form-key protected (backend default), ACL via ADMIN_RESOURCE; redirects back to the section. */
abstract class AbstractAction extends Action implements HttpPostActionInterface
{
    protected function storeId(): int
    {
        return (int) $this->getRequest()->getParam('store');
    }

    protected function adminUser(): string
    {
        /** @var \Magento\User\Model\User|null $user */
        $user = $this->_auth->getUser();
        return (string) $user?->getUserName();
    }

    protected function back(ActionResult $result): Redirect
    {
        $result->ok
            ? $this->messageManager->addSuccessMessage($result->message)
            : $this->messageManager->addErrorMessage($result->message);
        return $this->resultRedirectFactory->create()->setPath('adminhtml/system_config/edit',
            ['section' => 'answereng', 'store' => $this->storeId()]);
    }
}

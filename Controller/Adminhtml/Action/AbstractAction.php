<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Controller\Adminhtml\Action;

use Fullspec\AnswerEng\Model\Admin\ActionResult;
use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;

/** POST-only, form-key protected (backend default), ACL via ADMIN_RESOURCE; redirects back to the status page. */
abstract class AbstractAction extends Action implements HttpPostActionInterface
{
    /**
     * Constructor.
     *
     * @param Context $context
     * @param SettingsProvider $settingsProvider
     */
    public function __construct(Context $context, private readonly SettingsProvider $settingsProvider)
    {
        parent::__construct($context);
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        if (!in_array($this->requestedStoreId(), $this->settingsProvider->allStoreIds(), true)) {
            $this->messageManager->addErrorMessage(
                (string) __('Unknown store view. Choose a store view and try again.')
            );
            return $this->resultRedirectFactory->create()->setPath('answereng/status/index');
        }
        return $this->perform();
    }

    /**
     * Perform.
     *
     * @return Redirect
     */
    abstract protected function perform(): Redirect;

    /**
     * Store id.
     *
     * @return int
     */
    protected function storeId(): int
    {
        return $this->requestedStoreId();
    }

    /**
     * Admin user.
     *
     * @return string
     */
    protected function adminUser(): string
    {
        /** @var \Magento\User\Model\User|null $user */
        $user = $this->_auth->getUser();
        return (string) $user?->getUserName();
    }

    /**
     * Flash the result and redirect back to the status page.
     *
     * @param ActionResult $result
     * @param bool $notice
     * @return Redirect
     */
    protected function back(ActionResult $result, bool $notice = false): Redirect
    {
        if (!$result->ok) {
            $this->messageManager->addErrorMessage($result->message);
        } elseif ($notice) {
            $this->messageManager->addNoticeMessage($result->message);
        } else {
            $this->messageManager->addSuccessMessage($result->message);
        }
        return $this->resultRedirectFactory->create()->setPath(
            'answereng/status/index',
            ['store' => $this->storeId()]
        );
    }

    /**
     * Requested store id.
     *
     * @return int
     */
    private function requestedStoreId(): int
    {
        return (int) $this->getRequest()->getParam('store');
    }
}

<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Controller\Adminhtml\Status;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Backend\App\Action\Context;

/** Catalog › AnswerEng › Status & AI Visibility: sync status, results and actions per store view. */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Fullspec_AnswerEng::status';

    /**
     * Constructor.
     *
     * @param Context $context
     * @param PageFactory $pageFactory
     */
    public function __construct(Context $context, private readonly PageFactory $pageFactory)
    {
        parent::__construct($context);
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Fullspec_AnswerEng::status_page');
        $page->getConfig()->getTitle()->prepend((string) __('Status & AI Visibility'));
        return $page;
    }
}

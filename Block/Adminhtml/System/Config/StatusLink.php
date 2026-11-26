<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Block\Adminhtml\System\Config;

use Fullspec\AnswerEng\Model\Config\SettingsProviderInterface;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/** Settings page pointer to Catalog › AnswerEng, where status, results and actions live. */
class StatusLink extends Field
{
    /**
     * @var string
     */
    protected $_template = 'Fullspec_AnswerEng::status-link.phtml';

    /**
     * Constructor.
     *
     * @param Context $context
     * @param SettingsProviderInterface $settings
     * @param array<string,mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly SettingsProviderInterface $settings,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @inheritdoc
     */
    public function render(AbstractElement $element)
    {
        return $this->_decorateRowHtml($element, '<td colspan="4">' . $this->toHtml() . '</td>');
    }

    /**
     * The status page, opened on this scope's store view when one is chosen.
     *
     * @return string
     */
    public function getStatusUrl(): string
    {
        $id = (int) $this->getRequest()->getParam('store');
        $params = in_array($id, $this->settings->allStoreIds(), true) ? ['store' => $id] : [];
        return $this->getUrl('answereng/status/index', $params);
    }

    /**
     * Whether this admin may open the status page.
     *
     * @return bool
     */
    public function canViewStatus(): bool
    {
        return $this->_authorization->isAllowed('Fullspec_AnswerEng::status');
    }
}

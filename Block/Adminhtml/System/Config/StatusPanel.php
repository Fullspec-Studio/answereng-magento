<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Block\Adminhtml\System\Config;

use Fullspec\AnswerEng\Model\Admin\PanelViewModel;
use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class StatusPanel extends Field
{
    /**
     * @var string
     */
    protected $_template = 'Fullspec_AnswerEng::status.phtml';

    /**
     * Constructor.
     *
     * @param Context $context
     * @param PanelViewModel $panel
     * @param SettingsProvider $settings
     * @param array<string,mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly PanelViewModel $panel,
        private readonly SettingsProvider $settings,
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
     * Get store id.
     *
     * @return int
     */
    public function getStoreId(): int
    {
        $id = (int) $this->getRequest()->getParam('store');
        return in_array($id, $this->settings->allStoreIds(), true) ? $id : 0;
    }

    /**
     * Get panel.
     *
     * @return array<string,mixed>|list<array<string,mixed>>
     */
    public function getPanel(): array
    {
        $storeId = $this->getStoreId();
        return $storeId > 0 ? $this->panel->forView($storeId) : $this->panel->forDefault((int) $this->getRequest()->getParam('website'));
    }
}

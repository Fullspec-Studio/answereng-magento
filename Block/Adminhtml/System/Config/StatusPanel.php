<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Block\Adminhtml\System\Config;

use Fullspec\AnswerEng\Model\Admin\PanelViewModel;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class StatusPanel extends Field
{
    protected $_template = 'Fullspec_AnswerEng::status.phtml';

    /**
     * @param array<string,mixed> $data
     */
    public function __construct(Context $context, private readonly PanelViewModel $panel, array $data = [])
    {
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element)
    {
        return $this->_decorateRowHtml($element, '<td colspan="4">' . $this->toHtml() . '</td>');
    }

    public function getStoreId(): int
    {
        return (int) $this->getRequest()->getParam('store');
    }

    /**
     * @return array<string,mixed>|list<array<string,mixed>>
     */
    public function getPanel(): array
    {
        $storeId = $this->getStoreId();
        return $storeId > 0 ? $this->panel->forView($storeId) : $this->panel->forDefault();
    }
}

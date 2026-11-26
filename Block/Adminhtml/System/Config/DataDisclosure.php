<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Block\Adminhtml\System\Config;

use Fullspec\AnswerEng\Model\Config\SettingsProviderInterface;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/** Settings page disclosure of what the catalog push sends to the engine and what never leaves the store. */
class DataDisclosure extends Field
{
    /**
     * @var string
     */
    protected $_template = 'Fullspec_AnswerEng::data-disclosure.phtml';

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
     * The engine product data is sent to, with the hosted default applied when the field is blank.
     *
     * @return string
     */
    public function getEngineUrl(): string
    {
        return $this->settings->forStore(0)->engineUrl;
    }

    /**
     * What the catalog push sends.
     *
     * @return string[]
     */
    public function getSentItems(): array
    {
        return [
            (string) __(
                'Product SKU, name and short description '
                . '(or the full description, cleaned, when there is no short description)'
            ),
            (string) __(
                'Price, the product\'s category (its URL key), and product attributes set to '
                . '"Visible on Catalog Pages on Storefront"'
            ),
            (string) __('Your brand and competitor names'),
            (string) __('Your store key (an identifier for this store view, not personal data)'),
        ];
    }

    /**
     * What never leaves the store.
     *
     * @return string[]
     */
    public function getNeverSentItems(): array
    {
        return [
            (string) __('Customer names, emails and addresses'),
            (string) __('Orders and payment data'),
            (string) __('Product images, analytics and telemetry'),
        ];
    }
}

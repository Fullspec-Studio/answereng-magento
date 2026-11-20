<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Block\Adminhtml\Status;

use Fullspec\AnswerEng\Model\Admin\PanelViewModel;
use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/** The status panel on Catalog › AnswerEng: every enabled view, or one view picked in the store switcher. */
class Panel extends Template
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
     * The chosen store view, or 0 for the overview (also for unknown ids).
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
        return $storeId > 0 ? $this->panel->forView($storeId) : $this->panel->forDefault();
    }

    /**
     * The settings page, at this view's scope when one is chosen.
     *
     * @return string
     */
    public function getSettingsUrl(): string
    {
        $storeId = $this->getStoreId();
        return $this->getUrl(
            'adminhtml/system_config/edit',
            $storeId > 0 ? ['section' => 'answereng', 'store' => $storeId] : ['section' => 'answereng']
        );
    }
}

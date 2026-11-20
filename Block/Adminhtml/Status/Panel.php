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
     * The panel data, loaded once per request (the template and its partials all read it).
     *
     * @var array<string,mixed>|list<array<string,mixed>>|null
     */
    private ?array $panelData = null;

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
        if ($this->panelData === null) {
            $storeId = $this->getStoreId();
            $this->panelData = $storeId > 0 ? $this->panel->forView($storeId) : $this->panel->forDefault();
        }
        return $this->panelData;
    }

    /**
     * One section of the view page (view/adminhtml/templates/status/<name>.phtml).
     *
     * @param string $name
     * @return string
     */
    public function renderPart(string $name): string
    {
        return $this->fetchView((string) $this->getTemplateFile('Fullspec_AnswerEng::status/' . $name . '.phtml'));
    }

    /**
     * The settings page at the Brand group, where the competitor list lives.
     *
     * @return string
     */
    public function getCompetitorsUrl(): string
    {
        return $this->getSettingsUrl() . '#answereng_brand-head';
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

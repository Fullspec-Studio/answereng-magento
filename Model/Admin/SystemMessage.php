<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Notification\MessageInterface;

/**
 * Spec §4 / §6: a persistent admin system message while an enabled view is blocked for having more than
 * 5,000 eligible products or is exhausted after 3 transient failures. One SELECT per request (memoized),
 * because Magento asks every registered message for its identity on every admin page.
 */
class SystemMessage implements MessageInterface
{
    /**
     * @var list<array{store_id: int, state: string, reason: ?string}>|null
     */
    private ?array $rows = null;

    /**
     * @param SettingsProvider $settings
     * @param SyncStateRepository $state
     * @param UrlInterface $url
     * @param Escaper $escaper
     */
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly UrlInterface $url,
        private readonly Escaper $escaper
    ) {
    }

    /**
     * Changes whenever the set of affected views or their states changes, so a new problem shows again.
     *
     * @return string
     */
    public function getIdentity()
    {
        $parts = array_map(static fn (array $r) => $r['store_id'] . ':' . $r['state'], $this->rows());
        sort($parts);
        return hash('sha256', 'answereng_sync|' . implode(',', $parts));
    }

    /**
     * Shown while any enabled view is over the product cap or exhausted.
     *
     * @return bool
     */
    public function isDisplayed()
    {
        return $this->rows() !== [];
    }

    /**
     * One sentence per case naming the affected views (names escaped), plus a link to the AnswerEng section.
     *
     * @return string
     */
    public function getText()
    {
        $over = $exhausted = $exportFailed = [];
        foreach ($this->rows() as $r) {
            $name = $this->escaper->escapeHtml($this->settings->storeName($r['store_id']));
            if ($r['state'] === SyncStateRepository::BLOCKED) {
                $over[] = $name;
            } elseif ($r['reason'] === SyncStateRepository::CAUSE_EXPORT_FAILED) {
                $exportFailed[] = $name;
            } else {
                $exhausted[] = $name;
            }
        }
        $sentences = [];
        if ($over !== []) {
            $sentences[] = (string) __('AnswerEng: store view %1 has more than 5,000 eligible products, the AnswerEng '
                . 'limit, and is not synced.', implode(', ', $over));
        }
        if ($exhausted !== []) {
            $sentences[] = (string) __('AnswerEng could not be reached for store view %1 after 3 attempts; the '
                . 'nightly sync will try again.', implode(', ', $exhausted));
        }
        if ($exportFailed !== []) {
            $sentences[] = (string) __('AnswerEng: reading the catalog of store view %1 failed 3 times; nothing was '
                . 'sent. The nightly sync will try again.', implode(', ', $exportFailed));
        }
        // An admin URL from Magento's own URL builder; escaped for the attribute.
        $link = $this->escaper->escapeHtml($this->url->getUrl('adminhtml/system_config/edit/section/answereng'));
        $sentences[] = '<a href="' . $link . '">' . $this->escaper->escapeHtml(__('Open AnswerEng settings')) . '</a>';
        return implode(' ', $sentences);
    }

    /**
     * Major: syncing stopped for at least one view until the merchant or the nightly sync acts.
     *
     * @return int
     */
    public function getSeverity()
    {
        return self::SEVERITY_MAJOR;
    }

    /**
     * The affected views, read once per request.
     *
     * @return list<array{store_id: int, state: string, reason: ?string}>
     */
    private function rows(): array
    {
        return $this->rows ??= $this->state->viewsNeedingAttention($this->settings->enabledStoreIds());
    }
}

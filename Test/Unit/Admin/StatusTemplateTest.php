<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Admin;

use Fullspec\AnswerEng\Model\Admin\FixView;
use Fullspec\AnswerEng\Model\Admin\ResultsView;
use Fullspec\AnswerEng\Model\Admin\SummaryView;
use Magento\Framework\Escaper;
use Magento\Framework\Translate\InlineInterface;
use Magento\Framework\ZendEscaper;
use PHPUnit\Framework\TestCase;

/** Renders status.phtml and its partials with a stub block, the way Magento includes them. */
final class StatusTemplateTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../../../view/adminhtml/templates/';

    private function escaper(): Escaper
    {
        $escaper = new Escaper();
        $inline = $this->createMock(InlineInterface::class);
        $inline->method('isAllowed')->willReturn(false);
        foreach (['translateInline' => $inline, 'escaper' => new ZendEscaper()] as $name => $value) {
            $p = new \ReflectionProperty(Escaper::class, $name);
            $p->setValue($escaper, $value);
        }
        return $escaper;
    }

    private static function fix(int $id, string $content = 'Draft text', ?string $appliedAt = null): FixView
    {
        return new FixView($id, 'copy', $content, 'high', ['Reason one.', 'Reason two.'],
            'waterproof hiking boots', 'perplexity', $appliedAt);
    }

    private static function summary(string $state = SummaryView::FRESH): SummaryView
    {
        return new SummaryView(
            $state,
            'https://app.answereng.io/stores/demo',
            0.42,
            0.37,
            0.47,
            '2026-09-28T06:00:12+00:00',
            new \DateTimeImmutable('2026-10-07 12:00:00'),
            null,
            [
                ['started_at' => '2026-09-14T06:00:08+00:00', 'rate' => 0.34, 'low' => 0.29, 'high' => 0.39],
                ['started_at' => '2026-09-28T06:00:12+00:00', 'rate' => 0.42, 'low' => 0.37, 'high' => 0.47],
            ],
            ['perplexity:sonar' => ['rate' => 0.47, 'low' => 0.37, 'high' => 0.57]],
            [
                '__store__' => ['rate' => 0.42, 'low' => 0.37, 'high' => 0.47],
                'Merrell' => ['rate' => 0.44, 'low' => 0.39, 'high' => 0.49],
            ]
        );
    }

    /**
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private static function panel(array $over = []): array
    {
        return $over + [
            'pendingDeletion' => null,
            'storeId' => 1,
            'enabled' => true,
            'storeKey' => 'demo',
            'testEngine' => null,
            'state' => 'ok',
            'reason' => null,
            'message' => null,
            'lastSyncAt' => '2026-10-07 11:00',
            'productCount' => 12,
            'log' => [],
            'results' => new ResultsView(self::summary(), [self::fix(118), self::fix(117)], [], false),
            'identities' => [],
            'canClear' => true,
            'canSync' => true,
            'canDelete' => true,
            'canApplyFixes' => true,
            'autoSync' => true,
            'healthy' => true,
            'urls' => [
                'syncNow' => '/admin/answereng/action/syncnow',
                'refreshSummary' => '/admin/answereng/action/refreshsummary',
                'testConnection' => '/admin/answereng/action/testconnection',
                'clear' => '/admin/answereng/action/clear',
                'delete' => '/admin/answereng/action/delete',
                'cancel' => '/admin/answereng/action/cancel',
                'forget' => '/admin/answereng/action/forget',
                'fixApplied' => '/admin/answereng/action/fixapplied',
            ],
        ];
    }

    /** @param array<string,mixed> $panel */
    private function render(array $panel, ?string $part = null): string
    {
        $block = new StubPanelBlock($panel, $this->escaper(), self::TEMPLATES);
        return $part === null ? $block->renderFile(self::TEMPLATES . 'status.phtml') : $block->renderPart($part);
    }

    public function testEngineTextIsEscaped(): void
    {
        $evil = '<script>alert(1)</script>&"';
        $fix = new FixView(118, 'copy', $evil, 'high', [$evil], $evil, $evil, null);
        $applied = new FixView(99, 'copy', 'x', 'low', [], $evil, 'bedrock', '2026-10-01T09:00:00+00:00');
        $summary = new SummaryView(SummaryView::FRESH, null, 0.4, 0.3, 0.5, null, null, null, [],
            [$evil => ['rate' => 0.4, 'low' => 0.3, 'high' => 0.5]],
            ['__store__' => ['rate' => 0.4, 'low' => 0.3, 'high' => 0.5], $evil => ['rate' => 0.2, 'low' => 0.1, 'high' => 0.3]]);
        $html = $this->render(self::panel([
            'results' => new ResultsView($summary, [$fix], [$applied], false),
        ]));
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;&amp;', $html);
        self::assertSame(1, preg_match('/data-answereng-copy="([^"]*)"/', $html, $m));
        self::assertSame($evil, html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function testFixesAreAboveSyncAndJumpLinkCountsThem(): void
    {
        $html = $this->render(self::panel());
        self::assertStringContainsString('2 drafted fixes to review', $html);
        self::assertStringContainsString('href="#answereng-fixes"', $html);
        $order = array_map(static fn (string $needle) => strpos($html, $needle), [
            'data-answereng-jump',
            'id="answereng-visibility"',
            'id="answereng-breakdown"',
            'id="answereng-fixes"',
            'id="answereng-sync"',
        ]);
        self::assertNotContains(false, $order);
        $sorted = $order;
        sort($sorted);
        self::assertSame($sorted, $order);

        $one = $this->render(self::panel([
            'results' => new ResultsView(self::summary(), [self::fix(118)], [], false),
        ]));
        self::assertStringContainsString('1 drafted fix to review', $one);
        $none = $this->render(self::panel(['results' => new ResultsView(self::summary(), [], [], false)]));
        self::assertStringNotContainsString('data-answereng-jump', $none);
        self::assertStringContainsString('No drafted fixes await review.', $none);
    }

    public function testMarkButtonsNeedThePermission(): void
    {
        $results = new ResultsView(self::summary(), [self::fix(118)], [self::fix(90, 'x', '2026-10-01T09:00:00+00:00')], false);
        $allowed = $this->render(self::panel(['results' => $results]), 'fixes');
        self::assertStringContainsString('Mark as applied', $allowed);
        self::assertStringContainsString('Undo', $allowed);
        self::assertStringContainsString('Applied (1)', $allowed);
        self::assertSame(2, substr_count($this->attrsDecoded($allowed), 'data-answereng-action="/admin/answereng/action/fixapplied"'));
        self::assertStringContainsString('{"store":1,"fix_id":118,"op":"mark"}', $this->attrsDecoded($allowed));
        self::assertStringContainsString('{"store":1,"fix_id":90,"op":"unmark"}', $this->attrsDecoded($allowed));

        $denied = $this->render(self::panel(['results' => $results, 'canApplyFixes' => false]), 'fixes');
        self::assertStringNotContainsString('Mark as applied', $denied);
        self::assertStringNotContainsString('>Undo<', $denied);
        self::assertStringNotContainsString('fixapplied', $denied);
        self::assertStringContainsString('Copy draft', $denied);
    }

    public function testDraftsFromOneDiagnosisShareACardWithReadableReasons(): void
    {
        $reasons = [
            'insufficient_data: product lacks material specifications (waterproof, breathable)',
            'insufficient_data: product lacks size dimensions',
            'insufficient_data: product lacks weight',
            'insufficient_data: product lacks capacity',
            'title is generic',
        ];
        $mk = static fn (int $id, string $kind, int $diag, string $prompt) => new FixView(
            $id, $kind, "draft $id", 'high', $reasons, $prompt, 'bedrock', null,
            'us.anthropic.claude-haiku-4-5-20251001-v1:0', $diag
        );
        $fixes = [$mk(1, 'qa', 7, 'compact bag'), $mk(2, 'copy', 7, 'compact bag'), $mk(3, 'schema', 8, 'gym bag')];
        $html = $this->render(self::panel(['results' => new ResultsView(self::summary(), $fixes, [], false)]), 'fixes');

        self::assertSame(2, substr_count($html, 'data-answereng-fix'));
        self::assertSame(3, substr_count($html, 'Copy draft'));
        self::assertStringContainsString('Draft Q&amp;A — review before use', $html);
        self::assertStringContainsString('Draft Product description — review before use', $html);
        self::assertStringContainsString('Draft Structured data — review before use', $html);
        self::assertStringContainsString('Seen in Claude', $html);
        self::assertStringNotContainsString('Amazon Bedrock', $html);
        self::assertStringNotContainsString('insufficient_data', $html);
        self::assertStringContainsString('Missing from your product data', $html);
        self::assertStringContainsString('<li>Material specifications (waterproof, breathable)</li>', $html);
        self::assertStringContainsString('Show 1 more', $html);
        self::assertStringContainsString('<li>Title is generic</li>', $html);
        self::assertStringContainsString('High priority', $html);
    }

    public function testFixCountUsesTheEngineTotalOrMarksTheListLimit(): void
    {
        $many = array_map(static fn (int $i) => self::fix($i), range(1, 100));
        $capped = $this->render(self::panel(['results' => new ResultsView(self::summary(), $many, [], false)]));
        self::assertStringContainsString('100+ drafted fixes to review', $capped);
        self::assertStringContainsString('100+ awaiting review', $capped);
        self::assertStringContainsString('Showing the 100 newest.', $capped);

        $s = self::summary();
        $withTotal = new SummaryView($s->state, $s->dashboardUrl, $s->rate, $s->low, $s->high, $s->runStartedAt,
            $s->fetchedAt, null, $s->trend, $s->engineBreakdown, $s->shareOfVoice, 137);
        $total = $this->render(self::panel(['results' => new ResultsView($withTotal, $many, [], false)]));
        self::assertStringContainsString('137 drafted fixes to review', $total);
        self::assertStringContainsString('137 awaiting review', $total);
        self::assertStringContainsString('Showing the 100 newest.', $total);

        $few = $this->render(self::panel());
        self::assertStringNotContainsString('Showing the', $few);
    }

    private function attrsDecoded(string $html): string
    {
        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function testSyncOpensWhenUnhealthy(): void
    {
        self::assertSame(0, preg_match('/<details[^>]*id="answereng-sync"[^>]*\bopen\b/', $this->render(self::panel(), 'sync')));
        $unhealthy = $this->render(self::panel(['healthy' => false]), 'sync');
        self::assertSame(1, preg_match('/<details[^>]*id="answereng-sync"[^>]*\bopen\b/', $unhealthy));
        self::assertStringNotContainsString('Auto-sync is off', $unhealthy);
        self::assertStringContainsString(
            'Auto-sync is off. Your catalog syncs nightly, or when you click Sync now.',
            $this->render(self::panel(['autoSync' => false]), 'sync')
        );
    }

    public function testUnavailableFixesWinOverStaleAndStaleBannerCoversFixes(): void
    {
        $unavailable = $this->render(self::panel([
            'results' => new ResultsView(self::summary(SummaryView::STALE), null, null, true),
        ]));
        self::assertStringContainsString('Drafted fixes are unavailable right now', $unavailable);
        self::assertStringContainsString('Could not refresh from AnswerEng. Showing results fetched', $unavailable);

        $stale = $this->render(self::panel([
            'results' => new ResultsView(self::summary(), [self::fix(118)], [], true),
        ]));
        self::assertStringContainsString('Could not refresh drafted fixes from AnswerEng.', $stale);
        self::assertStringContainsString('Draft text', $stale);
    }

    public function testPreviewAndEmptyStatesSkipTheResultSections(): void
    {
        foreach ([SummaryView::NO_RUN, SummaryView::NOT_SYNCED, SummaryView::OUT_OF_SYNC] as $state) {
            $html = $this->render(self::panel([
                'healthy' => false,
                'results' => new ResultsView(new SummaryView($state, null), null, null, false),
            ]));
            self::assertStringNotContainsString('id="answereng-breakdown"', $html, $state);
            self::assertStringNotContainsString('id="answereng-fixes"', $html, $state);
            self::assertSame(1, preg_match('/<details[^>]*id="answereng-sync"[^>]*\bopen\b/', $html), $state);
        }
        $outOfSync = $this->render(self::panel([
            'results' => new ResultsView(new SummaryView(SummaryView::OUT_OF_SYNC, null), null, null, false),
        ]));
        self::assertStringContainsString('Out of sync with AnswerEng. Sync now to see results.', $outOfSync);
        $noRun = $this->render(self::panel([
            'results' => new ResultsView(new SummaryView(SummaryView::NO_RUN, null), null, null, false),
        ]));
        self::assertStringContainsString('Connected. Waiting for the first analysis run.', $noRun);
    }

    public function testResultSectionsShowTheEngineLabelsTrendAndShareOfVoice(): void
    {
        $html = $this->render(self::panel());
        self::assertStringContainsString('<span class="ae-bar-name">Perplexity Sonar</span>', $html);
        self::assertStringContainsString('<span class="ae-fix-engine">Seen in Perplexity Sonar</span>', $html);
        self::assertStringContainsString('Your store (you)', $html);
        self::assertStringContainsString('Merrell', $html);
        self::assertStringContainsString('▲ +8.0 vs last run', $html);
        self::assertStringContainsString('<polyline', $html);
        self::assertStringContainsString('Y-axis starts at zero · 2 completed runs.', $html);
        self::assertStringContainsString('Open full results in AnswerEng', $html);
        self::assertStringContainsString('/admin/config#answereng_brand-head', $html);
    }
}

/** Stands in for Block\Adminhtml\Status\Panel: includes templates with $block and $escaper in scope. */
class StubPanelBlock
{
    /**
     * @param array<string,mixed> $panel
     */
    public function __construct(
        private readonly array $panel,
        private readonly Escaper $escaper,
        private readonly string $templates
    ) {
    }

    public function getStoreId(): int
    {
        return (int) $this->panel['storeId'];
    }

    /** @return array<string,mixed> */
    public function getPanel(): array
    {
        return $this->panel;
    }

    public function getSettingsUrl(): string
    {
        return '/admin/config';
    }

    public function getCompetitorsUrl(): string
    {
        return '/admin/config#answereng_brand-head';
    }

    /** @param array<string,mixed> $params */
    public function getUrl(string $route = '', array $params = []): string
    {
        return '/admin/' . $route;
    }

    public function renderPart(string $name): string
    {
        return $this->renderFile($this->templates . 'status/' . $name . '.phtml');
    }

    public function renderFile(string $file): string
    {
        $block = $this;
        $escaper = $this->escaper;
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}

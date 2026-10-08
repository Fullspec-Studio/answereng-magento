<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Support;

use Fullspec\AnswerEng\Model\Config\ConfigDisabler;
use Fullspec\AnswerEng\Model\Config\Settings;

final class FakeDisabler implements ConfigDisabler
{
    /** @var list<int> */
    public array $disabled = [];

    public function __construct(private readonly FakeSettings $settings)
    {
    }

    public function disable(int $storeId): void
    {
        $s = $this->settings->forStore($storeId);
        $this->settings->stores[$storeId] = new Settings($storeId, false, $s->engineUrl, $s->apiKey, $s->storeKey,
            $s->brandNames, $s->competitors);
        $this->disabled[] = $storeId;
    }
}

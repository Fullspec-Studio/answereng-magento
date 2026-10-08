<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Config;

interface ConfigDisabler
{
    /** Turn AnswerEng off for a store view (a config save: bumps its revision). */
    public function disable(int $storeId): void;
}

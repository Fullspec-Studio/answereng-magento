<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Config;

interface ConfigDisablerInterface
{
    /**
     * Turn AnswerEng off for a store view (a config save: bumps its revision).
     *
     * @param int $storeId
     */
    public function disable(int $storeId): void;
}

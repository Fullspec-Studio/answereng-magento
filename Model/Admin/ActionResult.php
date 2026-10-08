<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

class ActionResult
{
    /**
     * Constructor.
     *
     * @param bool $ok
     * @param string $message
     */
    public function __construct(public readonly bool $ok, public readonly string $message)
    {
    }
}

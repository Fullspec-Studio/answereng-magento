<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

class ActionResult
{
    public function __construct(public readonly bool $ok, public readonly string $message)
    {
    }
}

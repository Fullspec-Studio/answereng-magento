<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Observer;

use Fullspec\AnswerEng\Model\Sync\ConfigChangeHandler;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class ConfigSaved implements ObserverInterface
{
    public function __construct(private readonly ConfigChangeHandler $handler)
    {
    }

    public function execute(Observer $observer): void
    {
        $this->handler->handle();
    }
}

<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Config;

class DuplicateStoreKeyException extends \RuntimeException
{
    /**
     * Constructor.
     *
     * @param int $storeId
     * @param string $key
     */
    public function __construct(public readonly int $storeId, string $key)
    {
        parent::__construct("store key \"$key\" is already used by store view $storeId");
    }
}

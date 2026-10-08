<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

if (!function_exists('__')) {
    /**
     * Mirrors Magento's app/functions.php for tests and static analysis.
     *
     * @param mixed ...$args
     */
    function __(...$args): \Magento\Framework\Phrase
    {
        $text = array_shift($args);
        if (isset($args[0]) && is_array($args[0])) {
            $args = $args[0];
        }
        return new \Magento\Framework\Phrase((string) $text, $args);
    }
}

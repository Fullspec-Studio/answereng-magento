<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Catalog;

// phpcs:disable Magento2.Functions.StaticFunction -- pure functions, no state or dependencies to intercept.
class TextCleaner
{
    /**
     * Plain.
     *
     * @param string $html
     * @return string
     */
    public static function plain(string $html): string
    {
        $text = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6])\b[^>]*>/i', ' ', $html) ?? $html;
        // Decodes stored product text to plain text for the engine. The result is never output as HTML, so
        // Magento's Escaper (an output encoder) doesn't apply.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}

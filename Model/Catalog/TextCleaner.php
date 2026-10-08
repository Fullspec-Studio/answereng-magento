<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Catalog;

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
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}

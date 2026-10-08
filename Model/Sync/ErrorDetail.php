<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

use Fullspec\AnswerEng\Model\Engine\EngineResult;

/**
 * The only engine-error text the module ever stores or shows: status, code,
 * limit, and for 422 at most five loc/type pairs. Never `error`, `msg` or
 * `input` (validation bodies echo submitted catalog data).
 */
class ErrorDetail
{
    private const MAX_ITEMS = 5;
    private const MAX_ITEM = 200;
    private const MAX_TOTAL = 1000;

    public static function of(EngineResult $r): string
    {
        if ($r->status === null) {
            return mb_substr('transport: ' . ($r->transportError ?? 'error'), 0, self::MAX_TOTAL);
        }
        $parts = ['HTTP ' . $r->status];
        if ($r->code() !== null) {
            $parts[] = $r->code();
        }
        if ($r->limit() !== null) {
            $parts[] = 'limit=' . $r->limit();
        }
        $detail = implode(' ', $parts);
        $errors = $r->json['errors'] ?? null;
        if ($r->status === 422 && is_array($errors)) {
            $items = [];
            foreach (array_slice($errors, 0, self::MAX_ITEMS) as $error) {
                if (!is_array($error)) {
                    continue;
                }
                $loc = is_array($error['loc'] ?? null)
                    ? implode('.', array_map(static fn ($p) => is_scalar($p) ? (string) $p : '?', $error['loc']))
                    : '?';
                $type = is_string($error['type'] ?? null) ? $error['type'] : '?';
                $items[] = mb_substr($loc . ':' . $type, 0, self::MAX_ITEM);
            }
            if ($items !== []) {
                $detail .= '; ' . implode(', ', $items);
            }
        }
        return mb_substr($detail, 0, self::MAX_TOTAL);
    }
}

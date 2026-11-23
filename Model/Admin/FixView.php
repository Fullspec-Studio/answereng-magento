<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

/**
 * One drafted fix from `GET /stores/{store_key}/fixes` (suggested or applied).
 */
class FixView
{
    /**
     * Constructor.
     *
     * @param int $fixId
     * @param string $kind
     * @param string $content
     * @param string $priority
     * @param list<string> $reasons
     * @param string $promptText
     * @param string $engine the provider (`perplexity`, `bedrock`), not the model
     * @param string|null $appliedAt
     * @param string $model the engine's model id; empty from engines that don't send it
     * @param int|null $diagnosisId drafts from one diagnosis share it; null from engines that don't send it
     */
    public function __construct(
        public readonly int $fixId,
        public readonly string $kind,
        public readonly string $content,
        public readonly string $priority,
        public readonly array $reasons,
        public readonly string $promptText,
        public readonly string $engine,
        public readonly ?string $appliedAt,
        public readonly string $model = '',
        public readonly ?int $diagnosisId = null
    ) {
    }

    /**
     * Fixes from a decoded fixes response.
     *
     * Entries without an int `fix_id` or a string `content` are skipped; other fields default to empty.
     *
     * @param mixed $json
     * @return list<FixView>
     */
    public static function listFromJson(mixed $json): array
    {
        if (!is_array($json) || !array_is_list($json)) {
            return [];
        }
        $str = static fn (array $e, string $k): string => is_string($e[$k] ?? null) ? $e[$k] : '';
        $fixes = [];
        foreach ($json as $e) {
            if (!is_array($e) || !is_int($e['fix_id'] ?? null) || !is_string($e['content'] ?? null)) {
                continue;
            }
            $reasons = is_array($e['reasons'] ?? null) && array_is_list($e['reasons'])
                ? array_values(array_filter($e['reasons'], 'is_string'))
                : [];
            $fixes[] = new self(
                $e['fix_id'],
                $str($e, 'kind'),
                $e['content'],
                $str($e, 'priority'),
                $reasons,
                $str($e, 'prompt_text'),
                $str($e, 'engine'),
                is_string($e['applied_at'] ?? null) ? $e['applied_at'] : null,
                $str($e, 'model'),
                is_int($e['diagnosis_id'] ?? null) ? $e['diagnosis_id'] : null
            );
        }
        return $fixes;
    }

    /**
     * Engine id in `engine_breakdown` form ("bedrock:…claude…"), or the provider alone without a model.
     *
     * @return string
     */
    public function engineKey(): string
    {
        return $this->model === '' ? $this->engine : $this->engine . ':' . $this->model;
    }

    /**
     * Fixes grouped by the diagnosis that drafted them, in first-seen order.
     *
     * Without a diagnosis id (older engines), fixes with the same prompt, engine and reasons are grouped.
     *
     * @param list<FixView> $fixes
     * @return list<list<FixView>>
     */
    public static function groupByDiagnosis(array $fixes): array
    {
        $groups = [];
        foreach ($fixes as $fix) {
            $key = $fix->diagnosisId !== null
                ? 'd' . $fix->diagnosisId
                : 'p' . hash('sha256', (string) json_encode([$fix->promptText, $fix->engineKey(), $fix->reasons]));
            $groups[$key][] = $fix;
        }
        return array_values($groups);
    }
}

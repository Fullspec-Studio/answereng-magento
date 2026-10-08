<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Engine;

use Fullspec\AnswerEng\Model\Engine\ErrorCode;
use PHPUnit\Framework\TestCase;

final class ErrorCodeTest extends TestCase
{
    public function testEveryEngineCodeIsKnown(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/error_codes.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $codes = array_keys($fixture['lambda']);
        foreach ($fixture['gateway'] as $body) {
            $codes[] = $body['code'];
        }
        foreach (array_unique($codes) as $code) {
            self::assertNotNull(ErrorCode::tryFrom($code), "unknown engine code $code");
        }
    }
}

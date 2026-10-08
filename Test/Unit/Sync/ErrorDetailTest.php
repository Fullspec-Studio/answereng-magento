<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Sync;

use Fullspec\AnswerEng\Model\Engine\EngineResult;
use Fullspec\AnswerEng\Model\Engine\HttpResponse;
use Fullspec\AnswerEng\Model\Sync\ErrorDetail;
use PHPUnit\Framework\TestCase;

final class ErrorDetailTest extends TestCase
{
    public function testValidationDetailKeepsOnlyLocAndTypeAndNeverInput(): void
    {
        $errors = [];
        for ($i = 0; $i < 8; $i++) {
            $errors[] = ['type' => 'string_type', 'loc' => ['products', $i, 'title'],
                'msg' => 'secret product text', 'input' => ['title' => 'SECRET-INPUT']];
        }
        $detail = ErrorDetail::of(EngineResult::fromResponse(new HttpResponse(422, [],
            json_encode(['code' => 'validation_failed', 'error' => 'invalid catalog', 'errors' => $errors]))));
        self::assertStringStartsWith('HTTP 422 validation_failed', $detail);
        self::assertStringContainsString('products.0.title:string_type', $detail);
        self::assertStringNotContainsString('SECRET', $detail);
        self::assertStringNotContainsString('secret', $detail);
        self::assertSame(5, substr_count($detail, ':string_type'));
        self::assertLessThanOrEqual(1000, mb_strlen($detail));
    }

    public function testCodeAndLimitOnlyNeverErrorText(): void
    {
        $detail = ErrorDetail::of(EngineResult::fromResponse(new HttpResponse(403, [],
            '{"error":"plan allows 1 store(s)","code":"store_limit","limit":1}')));
        self::assertSame('HTTP 403 store_limit limit=1', $detail);
    }

    public function testTransportDetail(): void
    {
        self::assertSame('transport: timed out', ErrorDetail::of(EngineResult::transportFailure('timed out')));
    }
}

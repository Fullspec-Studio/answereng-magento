<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Sync;

use Fullspec\AnswerEng\Model\Sync\StateMessage;
use PHPUnit\Framework\TestCase;

/** Found in the local end-to-end run: the panel showed only raw codes such as "paused (store_limit)". */
final class StateMessageTest extends TestCase
{
    public function testStoreLimitNamesTheLimitAndEveryOption(): void
    {
        $m = StateMessage::for('paused', 'store_limit', null, 'HTTP 403 store_limit limit=1');
        self::assertStringContainsString('Your AnswerEng plan allows 1 store(s) and they are all in use.', (string) $m);
        foreach (['upgrade your plan', 'existing stores', 'delete a store you no longer need', 'support@answereng.io',
                     'does not free a slot'] as $option) {
            self::assertStringContainsString($option, (string) $m);
        }
        self::assertStringContainsString('all in use', StateMessage::paused('store_limit'));
    }

    public function testPausedReasonsUseTheSpecCopy(): void
    {
        self::assertSame('API key rejected or account inactive — check the key.', StateMessage::paused('invalid_key'));
        self::assertSame('This catalog exceeds your plan\'s 5000-product limit.',
            StateMessage::paused('product_limit', 'HTTP 403 product_limit limit=5000'));
        self::assertStringContainsString('another AnswerEng account', StateMessage::paused('store_owned_elsewhere'));
        self::assertSame('This store is scheduled for deletion on 2026-10-15 20:50 UTC. Cancel the deletion in the '
            . 'Danger zone to resume syncing.', StateMessage::paused('store_pending_deletion', '', '2026-10-15 20:50'));
        self::assertSame('Engine rejected the catalog data (HTTP 422 validation_failed; products.0.sku:string_type).',
            StateMessage::paused('validation_failed', 'HTTP 422 validation_failed; products.0.sku:string_type'));
        self::assertSame('Engine refused the request (HTTP 403).', StateMessage::paused('unexpected', 'HTTP 403'));
    }

    public function testOtherStates(): void
    {
        self::assertNull(StateMessage::for('ok', null));
        self::assertStringStartsWith('No eligible products', (string) StateMessage::for('ok', 'catalog_cleared'));
        self::assertStringContainsString('5,000', (string) StateMessage::for('blocked', 'too_many_products'));
        self::assertStringContainsString('Rate limited', (string) StateMessage::for('retrying', null, 'throttled'));
        self::assertStringContainsString('waking up', (string) StateMessage::for('retrying', null));
        self::assertStringContainsString('3 attempts', (string) StateMessage::for('exhausted', null));
    }
}

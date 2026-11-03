<?php
declare(strict_types=1);

namespace Tests;

use app\controller\RevenueAi;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Tests\Support\ReflectionHelper;

final class RevenueAiExecutionIntentScopeTest extends TestCase
{
    use ReflectionHelper;

    private function intent(): array
    {
        return ['id' => 90, 'hotel_id' => 20, 'source_module' => 'price_suggestion', 'source_record_id' => 12,
            'platform' => 'ctrip', 'date_start' => '2099-10-08', 'date_end' => '2099-10-08',
            'target_value' => ['room_type_key' => 'fixture-room', 'rate_plan_key' => 'fixture-rate']];
    }

    private function fields(): array
    {
        return ['platform' => 'ctrip', 'execution_date' => '2099-10-08', 'room_type_key' => 'fixture-room', 'rate_plan_key' => 'fixture-rate'];
    }

    private function verify(array $intent, array $fields): void
    {
        $controller = (new ReflectionClass(RevenueAi::class))->newInstanceWithoutConstructor();
        $this->invokeNonPublic($controller, 'assertPriceSuggestionExecutionIntentScope', [$intent,
            ['id' => 12, 'hotel_id' => 20, 'suggestion_date' => '2026-08-20'], $fields]);
    }

    public function testMatchingFutureExecutionScopeKeepsOriginalRecord(): void
    {
        $intent = $this->intent();
        $before = serialize($intent);
        $this->verify($intent, $this->fields());
        self::assertSame($before, serialize($intent));
    }

    public function testLegacyOmittedFieldsRemainIdempotentWithoutInventingDefaults(): void
    {
        $intent = $this->intent();
        unset($intent['platform'], $intent['date_start'], $intent['date_end'], $intent['target_value']);
        $before = serialize($intent);
        $this->verify($intent, []);
        self::assertSame($before, serialize($intent));
    }

    public function testExplicitScopeMismatchIs409AndPreservesRecord(): void
    {
        foreach (['hotel_id', 'source_module', 'source_record_id', 'platform', 'date_start', 'date_end', 'room_type_key', 'rate_plan_key'] as $key) {
            $intent = $this->intent();
            if (in_array($key, ['room_type_key', 'rate_plan_key'], true)) $intent['target_value'][$key] = 'other';
            else $intent[$key] = in_array($key, ['hotel_id', 'source_record_id'], true) ? 21 : 'other';
            $before = serialize($intent);
            try {
                $this->verify($intent, $this->fields());
                self::fail('Mismatch accepted: ' . $key);
            } catch (RuntimeException $error) {
                self::assertSame(409, $error->getCode(), $key);
                self::assertSame('已有执行意图与本次填写的酒店、建议或执行范围不一致，请回读原执行单后重新确认', $error->getMessage(), $key);
                self::assertSame($before, serialize($intent), $key);
            }
        }
    }

    public function testMalformedOrMissingTargetCannotSatisfyExplicitKeys(): void
    {
        foreach ([null, 'corrupt', [], ['room_type_key' => 'fixture-room']] as $target) {
            $intent = $this->intent(); $intent['target_value'] = $target;
            try { $this->verify($intent, $this->fields()); self::fail('Missing target accepted'); }
            catch (RuntimeException $error) { self::assertSame(409, $error->getCode()); }
        }
    }

    public function testWhitespaceNormalizationAndSameScopeRecovery(): void
    {
        $fields = $this->fields(); $fields['platform'] = ' CTRIP '; $fields['room_type_key'] = ' fixture-room ';
        $this->verify($this->intent(), $fields);
        self::assertSame('fixture-room', $this->intent()['target_value']['room_type_key']);
    }
}

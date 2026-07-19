<?php
declare(strict_types=1);

namespace Tests;

use app\service\CompetitorManualObservationService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CompetitorManualObservationServiceTest extends TestCase
{
    public function testUnboundPublicCtripCardStaysBindingMissing(): void
    {
        $normalized = CompetitorManualObservationService::normalizePublicObservation(
            $this->target(),
            $this->input()
        );
        $record = $normalized['record'];

        self::assertSame('ctrip', $normalized['canonical_platform']);
        self::assertSame('xc', $record['platform']);
        self::assertSame(391.0, $record['price']);
        self::assertSame('bookable', $record['availability']);
        self::assertSame('2026-07-19 06:30:00', $record['collected_at']);
        self::assertSame('https://hotels.ctrip.com/hotels/130079194.html', $record['source_ref']);
        self::assertSame('manual_ctrip_public_observation', $record['source_method']);
        self::assertSame('incomplete', $record['validation_status']);
        self::assertStringContainsString('ota_hotel_id_missing', $record['failure_reason']);
        self::assertStringContainsString('target_binding_missing', $record['failure_reason']);
        self::assertStringContainsString('exact_room_rate_terms_not_disclosed', $record['failure_reason']);
        self::assertSame('', $record['availability_scope_key']);
        self::assertSame('', $record['comparison_key']);
        self::assertNull($record['ota_hotel_id']);
        self::assertSame('hotel_lowest_visible_rate', $record['room_type_key']);
        self::assertSame('visible_starting_price', $record['price_basis']);
        self::assertSame(1, $record['nights']);
        self::assertSame(2, $record['adults']);
        self::assertSame(0, $record['children']);
    }

    public function testIdenticalObservationHasStableContentHashButLaterCaptureCreatesTimelineEvent(): void
    {
        $first = CompetitorManualObservationService::normalizePublicObservation($this->target(), $this->input());
        $replay = CompetitorManualObservationService::normalizePublicObservation($this->target(), $this->input());
        $later = CompetitorManualObservationService::normalizePublicObservation(
            $this->target(),
            $this->input(['collected_at' => '2026-07-19T07:30'])
        );

        self::assertSame($first['record']['content_hash'], $replay['record']['content_hash']);
        self::assertNotSame($first['record']['content_hash'], $later['record']['content_hash']);
        self::assertSame($first['record']['availability_scope_key'], $later['record']['availability_scope_key']);
    }

    public function testSoldOutObservationUsesNullInsteadOfFakeZeroPrice(): void
    {
        $normalized = CompetitorManualObservationService::normalizePublicObservation(
            $this->target(),
            $this->input(['availability' => 'sold_out', 'price' => '391'])
        );

        self::assertNull($normalized['record']['price']);
        self::assertSame('sold_out', $normalized['record']['availability']);
        self::assertSame('', $normalized['record']['availability_scope_key']);
        self::assertSame('', $normalized['record']['comparison_key']);
    }

    public function testBookableObservationRejectsMissingPrice(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('正价格');

        CompetitorManualObservationService::normalizePublicObservation(
            $this->target(),
            $this->input(['price' => ''])
        );
    }

    public function testPriceParserRejectsNegativeOrEmbeddedNumbers(): void
    {
        foreach (['-391', 'abc391', '391abc'] as $invalidPrice) {
            try {
                CompetitorManualObservationService::normalizePublicObservation(
                    $this->target(),
                    $this->input(['price' => $invalidPrice])
                );
                self::fail('Expected invalid price to be rejected: ' . $invalidPrice);
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('正数', $exception->getMessage());
            }
        }
    }

    public function testRejectsCrossPlatformSourceAndTargetMismatch(): void
    {
        try {
            CompetitorManualObservationService::normalizePublicObservation(
                $this->target(),
                $this->input(['source_ref' => 'https://www.meituan.com/hotel/123'])
            );
            self::fail('Expected source host mismatch to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('来源域名', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('提交平台与竞品目标平台不一致');
        CompetitorManualObservationService::normalizePublicObservation(
            $this->target(),
            $this->input(['platform' => 'meituan'])
        );
    }

    public function testPublicNameHashNeverBecomesOtaHotelIdentity(): void
    {
        $normalized = CompetitorManualObservationService::normalizePublicObservation(
            $this->target(['hotel_code' => 'public-name:e808098b0b86c927d6b9705ffec5c517']),
            $this->input(['ota_hotel_id' => 'public-name:e808098b0b86c927d6b9705ffec5c517'])
        );

        self::assertNull($normalized['record']['ota_hotel_id']);
        self::assertStringContainsString('ota_hotel_id_missing', $normalized['record']['failure_reason']);
    }

    public function testRequestCannotPromoteOrReplaceStoredOtaHotelIdentity(): void
    {
        try {
            CompetitorManualObservationService::normalizePublicObservation(
                $this->target(),
                $this->input(['ota_hotel_id' => '99999'])
            );
            self::fail('Expected an unbound target to reject a request-supplied OTA identity.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('不能用请求参数临时提升身份', $exception->getMessage());
        }

        $bound = CompetitorManualObservationService::normalizePublicObservation(
            $this->target(['hotel_code' => '130079194']),
            $this->input(['ota_hotel_id' => '130079194'])
        );
        self::assertSame('130079194', $bound['record']['ota_hotel_id']);
        self::assertNotSame('', $bound['record']['availability_scope_key']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('竞品目标绑定不一致');
        CompetitorManualObservationService::normalizePublicObservation(
            $this->target(['hotel_code' => '130079194']),
            $this->input(['ota_hotel_id' => '130079195'])
        );
    }

    public function testPublicHotelPageMustMatchStoredTargetIdentity(): void
    {
        $matched = CompetitorManualObservationService::normalizePublicObservation(
            $this->target(['hotel_code' => '130079194']),
            $this->input(['source_surface' => 'public_hotel_page', 'ota_hotel_id' => ''])
        );
        self::assertSame('130079194', $matched['record']['ota_hotel_id']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('公开来源 URL 必须包含并匹配所选竞品 OTA 酒店 ID');
        CompetitorManualObservationService::normalizePublicObservation(
            $this->target(['hotel_code' => '130079195']),
            $this->input(['source_surface' => 'public_hotel_page', 'ota_hotel_id' => ''])
        );
    }

    public function testBoundPublicNearbyCardMustMatchStoredTargetIdentity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('公开来源 URL 必须包含并匹配所选竞品 OTA 酒店 ID');

        CompetitorManualObservationService::normalizePublicObservation(
            $this->target(['hotel_code' => '130079195']),
            $this->input(['source_surface' => 'public_nearby_card'])
        );
    }

    public function testPersistenceContractScopesTargetIdempotencyAndReadback(): void
    {
        $root = dirname(__DIR__);
        $service = (string)file_get_contents($root . '/app/service/CompetitorManualObservationService.php');
        $migration = (string)file_get_contents(
            $root . '/database/migrations/20260719_add_competitor_observation_idempotency_index.sql'
        );

        self::assertStringContainsString("->where('id', \$competitorHotelId)", $service);
        self::assertStringContainsString("->where('store_id', \$systemHotelId)", $service);
        self::assertStringContainsString("->where('content_hash', (string)\$record['content_hash'])", $service);
        self::assertStringContainsString('->lock(true)', $service);
        self::assertStringContainsString('readbackMatches($readback, $record, true)', $service);
        foreach (['ota_hotel_id', 'failure_reason', 'nights', 'adults', 'children', 'rate_plan_key', 'currency', 'price_basis'] as $field) {
            self::assertStringContainsString("'{$field}'", $service);
        }
        self::assertStringContainsString("->update(['readback_verified' => 1])", $service);
        self::assertStringContainsString('idx_competitor_observation_content', $migration);
        self::assertStringNotContainsString('DELETE FROM', strtoupper($migration));
    }

    /** @return array<string,mixed> */
    private function target(array $overrides = []): array
    {
        return array_replace([
            'id' => 2,
            'tenant_id' => 80,
            'store_id' => 80,
            'platform' => 'xc',
            'city' => '敦煌',
            'hotel_name' => '敦煌兰亭·宿集',
            'hotel_code' => 'public-name:e808098b0b86c927d6b9705ffec5c517',
            'status' => 1,
        ], $overrides);
    }

    /** @return array<string,mixed> */
    private function input(array $overrides = []): array
    {
        return array_replace([
            'platform' => 'ctrip',
            'collected_at' => '2026-07-19T06:30',
            'check_in_date' => '2026-07-19',
            'check_out_date' => '2026-07-20',
            'adults' => 2,
            'children' => 0,
            'availability' => 'bookable',
            'price' => '391',
            'currency' => 'CNY',
            'source_ref' => 'https://hotels.ctrip.com/hotels/130079194.html?token=private#rooms',
            'source_surface' => 'public_nearby_card',
            'ota_hotel_id' => '',
        ], $overrides);
    }
}

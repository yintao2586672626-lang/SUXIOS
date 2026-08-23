<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiDailyCompetitionBundlePersistenceService;
use app\service\OtaCompetitionAnalysisBundleService;
use PHPUnit\Framework\TestCase;

final class AiDailyReportCompetitionReadbackTest extends TestCase
{
    public function testContentDigestCoversTheCompleteBundleAndSurvivesJsonRoundtrip(): void
    {
        $bundle = $this->bundle();
        $digest = (string)$bundle['content_digest'];

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $digest);
        self::assertSame($digest, OtaCompetitionAnalysisBundleService::contentDigest($bundle));
        self::assertSame(
            $digest,
            OtaCompetitionAnalysisBundleService::contentDigest(
                json_decode(json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true)
            )
        );

        $tampered = $bundle;
        $tampered['recommendations'][0]['action'] = 'tampered recommendation';
        self::assertNotSame($digest, OtaCompetitionAnalysisBundleService::contentDigest($tampered));
    }

    public function testExactReadbackReceiptRequiresIdentityDigestAndRenderContractMatch(): void
    {
        $bundle = $this->bundle();
        $contract = AiDailyCompetitionBundlePersistenceService::buildContract($bundle);
        $snapshot = [
            'competition_circle_bundle' => $bundle,
            'competition_circle_bundle_persistence' => $contract,
        ];

        $receipt = AiDailyCompetitionBundlePersistenceService::receipt($snapshot);
        self::assertSame('exact_readback_verified', $receipt['status']);
        self::assertTrue($receipt['exact_readback_verified']);
        self::assertSame([], $receipt['failure_reasons']);
        self::assertSame($bundle['bundle_id'], $receipt['bundle_id']);
        self::assertSame($bundle['source_fingerprint'], $receipt['source_fingerprint']);
        self::assertSame($bundle['content_digest'], $receipt['content_digest']);
    }

    public function testContentTamperingFailsEvenWhenBundleAndSourceIdentityStayUnchanged(): void
    {
        $bundle = $this->bundle();
        $contract = AiDailyCompetitionBundlePersistenceService::buildContract($bundle);
        $bundle['report_document']['title'] = 'tampered title';

        $receipt = AiDailyCompetitionBundlePersistenceService::receipt([
            'competition_circle_bundle' => $bundle,
            'competition_circle_bundle_persistence' => $contract,
        ]);

        self::assertFalse($receipt['exact_readback_verified']);
        self::assertSame('competition_content_digest_mismatch', $receipt['status']);
        self::assertContains('competition_content_digest_mismatch', $receipt['failure_reasons']);
    }

    public function testRenderIdentityTamperingIsReportedSeparately(): void
    {
        $bundle = $this->bundle();
        $contract = AiDailyCompetitionBundlePersistenceService::buildContract($bundle);
        $bundle['report_document']['render_contract']['bundle_id'] = 'different-bundle';

        $receipt = AiDailyCompetitionBundlePersistenceService::receipt([
            'competition_circle_bundle' => $bundle,
            'competition_circle_bundle_persistence' => $contract,
        ]);

        self::assertFalse($receipt['exact_readback_verified']);
        self::assertContains('competition_render_contract_identity_mismatch', $receipt['failure_reasons']);
    }

    public function testHistoricalBundleWithoutPersistenceContractRemainsLegacyUnverified(): void
    {
        $receipt = AiDailyCompetitionBundlePersistenceService::receipt([
            'competition_circle_bundle' => $this->bundle(),
        ]);

        self::assertSame('legacy_unverified', $receipt['status']);
        self::assertFalse($receipt['exact_readback_verified']);
        self::assertSame(['competition_content_digest_missing'], $receipt['failure_reasons']);
    }

    /** @return array<string,mixed> */
    private function bundle(): array
    {
        $bundle = [
            'schema_version' => OtaCompetitionAnalysisBundleService::SCHEMA_VERSION,
            'bundle_id' => 'ota-competition-80-20260823-abcdef123456',
            'source_fingerprint' => str_repeat('a', 64),
            'facts' => [
                'ctrip' => ['adr' => 288.0],
                'meituan' => ['rank' => 2],
            ],
            'recommendations' => [[
                'title' => '人工复核价格带',
                'action' => '先核验同房型同取消政策，再由用户决定是否调整。',
            ]],
            'report_document' => [
                'schema_version' => 'suxios.ota_competition_report.v1',
                'title' => 'OTA竞争商圈经营报告',
                'render_contract' => [
                    'bundle_id' => 'ota-competition-80-20260823-abcdef123456',
                    'source_fingerprint' => str_repeat('a', 64),
                    'exact_readback_required' => true,
                ],
            ],
            'render_contract' => [
                'requested_edition' => 'lite',
                'single_calculation' => true,
            ],
        ];
        $digest = OtaCompetitionAnalysisBundleService::contentDigest($bundle);
        $bundle['content_digest'] = $digest;
        $bundle['report_document']['render_contract']['content_digest'] = $digest;
        return $bundle;
    }

}

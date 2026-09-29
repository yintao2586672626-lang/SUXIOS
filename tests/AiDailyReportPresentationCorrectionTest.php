<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiDailyReportPresentationRendererService;
use app\service\AiDailyReportPresentationSpecService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class AiDailyReportPresentationCorrectionTest extends TestCase
{
    public static function corrections(): array
    {
        $cases = [];
        foreach (['owner', 'expert'] as $audience) {
            $cases[$audience . '-correction-only'] = [$audience, '', '应按携程渠道解释，不代表全酒店营收。'];
            $cases[$audience . '-reason-and-correction'] = [$audience, '原建议扩大了证据范围。', '先核对同日同渠道事实。'];
            $cases[$audience . '-long'] = [$audience, str_repeat('理由', 498) . '理由终点', str_repeat('修正', 498) . '修正终点'];
        }
        return $cases;
    }

    #[DataProvider('corrections')]
    public function testSavedCorrectionSurvivesSpecHtmlAndPowerPointNotes(string $audience, string $comment, string $correction): void
    {
        $report = self::report($comment, $correction);
        $before = $report;
        $spec = (new AiDailyReportPresentationSpecService())->build($report, $audience);
        $human = array_values(array_filter($spec['evidence_ledger'], static fn(array $row): bool => $row['class'] === 'HUMAN_DECISION'));
        self::assertCount(1, $human);
        self::assertStringContainsString($correction, $human[0]['statement']);
        if ($comment !== '') self::assertStringContainsString($comment, $human[0]['statement']);
        self::assertFalse($human[0]['execution_authorized']);
        self::assertFalse($human[0]['external_write_authorized']);
        self::assertSame($before, $report);

        $renderer = new AiDailyReportPresentationRendererService();
        $rendered = $renderer->render($spec);
        self::assertSame('pass', $renderer->verifyBundle($rendered['bundle'], $rendered['manifest'])['status']);
        $files = self::zipEntries($rendered['bundle']);
        $html = $files[$rendered['manifest']['components']['html']['filename']];
        self::assertStringContainsString($correction, $html);
        if ($comment !== '') self::assertStringContainsString($comment, $html);
        $pptx = self::zipEntries($files[$rendered['manifest']['components']['pptx']['filename']]);
        $notes = implode('', array_filter($pptx, static fn(string $name): bool => str_starts_with($name, 'ppt/notesSlides/notesSlide'), ARRAY_FILTER_USE_KEY));
        self::assertStringContainsString($correction, $notes);
        if ($comment !== '') self::assertStringContainsString($comment, $notes);
        $slides = implode('', array_filter($pptx, static fn(string $name): bool => preg_match('~^ppt/slides/slide\d+\.xml$~', $name) === 1, ARRAY_FILTER_USE_KEY));
        self::assertStringContainsString(mb_substr($correction, 0, 20), $slides, 'Visible summary starts with the correction, even with a long reason.');
    }

    public function testLegacyJudgmentAndTrainingExclusionRemainCompatible(): void
    {
        $report = self::report('旧版备注', '');
        $report['human_judgments'][0]['decision'] = 'accepted';
        unset($report['human_judgments'][0]['correction']);
        $service = new AiDailyReportPresentationSpecService();
        $legacy = $service->build($report, 'owner');
        self::assertSame('accepted：旧版备注', $legacy['evidence_ledger'][0]['statement']);
        $report['human_judgments'][0]['note'] = '旧note字段';
        self::assertSame('accepted：旧note字段', $service->build($report, 'expert')['evidence_ledger'][0]['statement']);
        $report['human_judgments'][0]['correction'] = '仅内部使用的修正终点';
        $training = $service->build($report, 'training');
        self::assertNotContains('HUMAN_DECISION', array_column($training['evidence_ledger'], 'class'));
        self::assertStringNotContainsString('仅内部使用的修正终点', json_encode($training, JSON_UNESCAPED_UNICODE));
    }

    public function testCorrectionIsEscapedAndChangesTheImmutableSpecFingerprint(): void
    {
        $service = new AiDailyReportPresentationSpecService();
        $report = self::report('判断理由', '修正一');
        $first = $service->build($report, 'owner');
        $report['human_judgments'][0]['correction'] = '<script>correction()</script>';
        $second = $service->build($report, 'owner');
        self::assertNotSame($first['spec_fingerprint'], $second['spec_fingerprint']);
        $rendered = (new AiDailyReportPresentationRendererService())->render($second);
        $files = self::zipEntries($rendered['bundle']);
        $html = $files[$rendered['manifest']['components']['html']['filename']];
        self::assertStringContainsString('&lt;script&gt;correction()&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>correction()</script>', $html);
    }

    public function testSpecificJudgmentTargetSurvivesEachPresentationFormat(): void
    {
        $key = 'ctrip_' . str_repeat('x', 110) . '尾部';
        $report = self::report('同一原因', '同一修正');
        $report['human_judgments'][0]['target_key'] = $key;
        foreach (['owner', 'expert'] as $audience) {
            $spec = (new AiDailyReportPresentationSpecService())->build($report, $audience);
            $human = $spec['evidence_ledger'][0];
            self::assertSame($key, $human['target_key'] ?? null);
            self::assertStringContainsString($key, $human['label']);
            $rendered = (new AiDailyReportPresentationRendererService())->render($spec);
            $files = self::zipEntries($rendered['bundle']);
            self::assertStringContainsString($key, $files[$rendered['manifest']['components']['html']['filename']]);
            $pptx = self::zipEntries($files[$rendered['manifest']['components']['pptx']['filename']]);
            self::assertStringContainsString($key, implode('', $pptx));
            $second = $report;
            $second['human_judgments'][0]['target_key'] = 'meituan_same_day_reference';
            self::assertNotSame($spec['spec_fingerprint'], (new AiDailyReportPresentationSpecService())->build($second, $audience)['spec_fingerprint']);
        }
        self::assertStringNotContainsString($key, json_encode((new AiDailyReportPresentationSpecService())->build($report, 'training'), JSON_UNESCAPED_UNICODE));
    }

    public static function report(string $comment, string $correction): array
    {
        return ['id' => 90003, 'tenant_id' => 90001, 'hotel_id' => 90002, 'report_date' => '2026-09-23',
            'summary' => 'SYNTHETIC 未核验演示夹具，不是经营事实。',
            'human_judgments' => [['id' => 'synthetic-review-1', 'user_id' => 90004, 'recorded_at' => '2026-09-24 10:00:00',
                'target_type' => 'ai_interpretation', 'decision' => 'corrected', 'comment' => $comment, 'correction' => $correction]],
        ];
    }

    public static function zipEntries(string $bytes): array
    {
        $file = tempnam(sys_get_temp_dir(), 'suxi-correction-');
        file_put_contents($file, $bytes);
        $zip = new ZipArchive();
        try {
            self::assertTrue($zip->open($file) === true);
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) $entries[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
            return $entries;
        } finally {
            $zip->close();
            unlink($file);
        }
    }
}

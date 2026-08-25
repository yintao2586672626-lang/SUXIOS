<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiDailyReportPresentationRendererService;
use app\service\AiDailyReportPresentationSpecService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class AiDailyReportPresentationRendererServiceTest extends TestCase
{
    public function testOneVerifiedSpecRendersDeterministicOfflineHtmlAndEditablePptx(): void
    {
        $spec = (new AiDailyReportPresentationSpecService())->build($this->trustedReport(), 'owner');
        $renderer = new AiDailyReportPresentationRendererService();

        $first = $renderer->render($spec);
        $second = $renderer->render($spec);

        self::assertSame($first['content_sha256'], $second['content_sha256']);
        self::assertSame($first['bundle'], $second['bundle']);
        self::assertSame('pass', $renderer->verifyBundle($first['bundle'], $first['manifest'])['status']);
        self::assertSame($spec['spec_fingerprint'], $first['manifest']['source']['spec_fingerprint']);
        self::assertFalse($first['manifest']['contract']['external_write_authorized']);
        self::assertSame('pending', $first['manifest']['contract']['human_review_status']);

        $bundleFiles = $this->zipEntries($first['bundle']);
        self::assertCount(4, $bundleFiles);
        $htmlName = $first['manifest']['components']['html']['filename'];
        $pptxName = $first['manifest']['components']['pptx']['filename'];
        $html = $bundleFiles[$htmlName] ?? '';
        self::assertNotSame('', $html);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert("x")</script>', $html);
        self::assertStringNotContainsString('src="http', $html);
        self::assertStringNotContainsString('href="http', $html);

        $pptxFiles = $this->zipEntries((string)($bundleFiles[$pptxName] ?? ''));
        foreach (['[Content_Types].xml', 'ppt/presentation.xml', 'ppt/slides/slide1.xml', 'ppt/notesSlides/notesSlide1.xml'] as $required) {
            self::assertArrayHasKey($required, $pptxFiles);
        }
        self::assertStringContainsString('[Sources]', $pptxFiles['ppt/notesSlides/notesSlide1.xml']);
        self::assertStringNotContainsString('<script>alert("x")</script>', implode('', $pptxFiles));
        foreach (array_keys($pptxFiles) as $filename) {
            self::assertFalse(str_ends_with(strtolower($filename), 'vbaproject.bin'));
        }

        $tampered = $this->replaceZipEntry($first['bundle'], $htmlName, $html . '<!-- tampered -->');
        $verification = $renderer->verifyBundle($tampered, $first['manifest']);
        self::assertSame('fail', $verification['status']);
        self::assertContains('bundle_component_hash_mismatch:html', $verification['errors']);
    }

    public function testRendererRejectsSpecFingerprintDrift(): void
    {
        $spec = (new AiDailyReportPresentationSpecService())->build($this->trustedReport(), 'owner');
        $spec['deck']['title'] = '被修改的标题';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fingerprint verification failed');
        (new AiDailyReportPresentationRendererService())->render($spec);
    }

    /** @return array<string,mixed> */
    private function trustedReport(): array
    {
        return [
            'id' => 88,
            'tenant_id' => 3,
            'hotel_id' => 7,
            'report_date' => '2026-08-22',
            'summary' => '已回读事实；恶意文本 <script>alert("x")</script> 必须只作为文字。',
            'result_contract' => [
                'result_version' => str_repeat('a', 64),
                'metric_version' => 'ai_daily_report_metric.v1',
                'reference_version' => str_repeat('b', 64),
                'boundary' => 'OTA渠道事实不扩大为全酒店财务结论。',
            ],
            'source_refs' => [[
                'key' => 'online_daily_data#99',
                'platform' => 'ctrip',
                'data_source_id' => 99,
                'hotel_id' => 7,
                'data_date' => '2026-08-22',
                'quality_status' => 'ok',
                'readback_verified' => true,
                'metric_keys' => ['book_order_num'],
            ]],
            'result_layers' => [
                'source_facts' => [[
                    'key' => 'orders',
                    'label' => 'OTA订单',
                    'value' => 12,
                    'unit' => '单',
                    'data_status' => 'available',
                    'metric_scope' => 'ota_channel',
                ]],
                'derived_metrics' => [],
                'anomaly_signals' => [],
                'ai_assistance' => [],
                'human_judgments' => [],
            ],
            'recommended_actions' => [[
                'title' => '人工复核',
                'action' => '核对本店与竞品展示信息；恶意文本 <script>alert("x")</script> 必须只作为文字。',
                'status' => 'pending_approval',
            ]],
            'data_gaps' => [[
                'code' => 'competitor_same_scope_missing',
                'message' => '缺少同商圈同口径竞品样本。',
            ]],
        ];
    }

    /** @return array<string,string> */
    private function zipEntries(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'suxios-renderer-test-');
        self::assertIsString($path);
        self::assertSame(strlen($content), file_put_contents($path, $content, LOCK_EX));
        $zip = new ZipArchive();
        try {
            self::assertTrue($zip->open($path, ZipArchive::CHECKCONS) === true);
            $files = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                $value = $zip->getFromIndex($index);
                self::assertIsString($name);
                self::assertIsString($value);
                $files[$name] = $value;
            }
            return $files;
        } finally {
            $zip->close();
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }

    private function replaceZipEntry(string $content, string $filename, string $replacement): string
    {
        $path = tempnam(sys_get_temp_dir(), 'suxios-renderer-tamper-');
        self::assertIsString($path);
        self::assertSame(strlen($content), file_put_contents($path, $content, LOCK_EX));
        $zip = new ZipArchive();
        try {
            self::assertTrue($zip->open($path) === true);
            self::assertTrue($zip->addFromString($filename, $replacement));
            self::assertTrue($zip->close());
            $result = file_get_contents($path);
            self::assertIsString($result);
            return $result;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}

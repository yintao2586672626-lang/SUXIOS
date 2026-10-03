<?php
declare(strict_types=1);

use app\service\AiDailyReportPresentationArtifactService;
use app\service\AiDailyReportPresentationRendererService;
use app\service\AiDailyReportPresentationReviewService;
use app\service\AiDailyReportPresentationSpecService;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

final class AiDailyReportPresentationReviewServiceTest extends TestCase
{
    private static array $originalConfig;

    public static function setUpBeforeClass(): void
    {
        self::$originalConfig = [];
        foreach (['database', 'cache', 'log'] as $key) self::$originalConfig[$key] = Config::get($key, []);
        $fixturePath = getenv('SUXIOS_CACHE_PATH') ?: sys_get_temp_dir() . '/presentation-review-' . getmypid();
        Config::set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => $fixturePath . '/cache/']]], 'cache');
        Config::set(['default' => 'file', 'channels' => ['file' => ['type' => 'File', 'path' => $fixturePath . '/log/', 'close' => true]]], 'log');
        Config::set(['default' => 'presentation_fixture', 'connections' => ['presentation_fixture' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'fields_strict' => false,
        ]]], 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL)');
        Db::execute('CREATE TABLE ai_report_presentation_specs (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER,
            hotel_id INTEGER, report_id INTEGER, audience TEXT, schema_version TEXT, adapter_version TEXT,
            source_result_version TEXT, spec_fingerprint TEXT, spec_json TEXT, data_status TEXT, render_status TEXT,
            created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(report_id,audience,adapter_version,spec_fingerprint))');
        Db::execute('CREATE TABLE ai_report_presentation_reviews (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER,
            hotel_id INTEGER, report_id INTEGER, presentation_spec_id INTEGER, spec_fingerprint TEXT, review_fingerprint TEXT,
            review_json TEXT, review_status TEXT, request_key TEXT, request_hash TEXT, created_by INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(tenant_id,hotel_id,presentation_spec_id,request_key))');
        Db::execute('CREATE TABLE ai_report_presentation_artifacts (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER,
            hotel_id INTEGER, report_id INTEGER, presentation_spec_id INTEGER, audience TEXT, format TEXT, renderer_version TEXT,
            spec_fingerprint TEXT, content_sha256 TEXT, content_bytes INTEGER, mime_type TEXT, artifact_filename TEXT,
            manifest_json TEXT, artifact_blob BLOB, render_status TEXT, created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(presentation_spec_id,renderer_version))');
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        foreach (self::$originalConfig as $key => $value) Config::set($value, $key);
    }

    protected function setUp(): void
    {
        foreach (['ai_report_presentation_artifacts', 'ai_report_presentation_reviews', 'ai_report_presentation_specs', 'hotels'] as $table) Db::execute('DELETE FROM ' . $table);
        Db::name('hotels')->insertAll([['id' => 7, 'tenant_id' => 3, 'name' => 'TEST-ONLY酒店'], ['id' => 8, 'tenant_id' => 4, 'name' => 'TEST-ONLY其他租户']]);
    }

    public function testPartialReviewHasExactReadbackIdempotencyAndNeverChangesImmutableSpec(): void
    {
        $stored = $this->stored();
        $service = new AiDailyReportPresentationReviewService();
        self::assertSame($stored['record_id'], $this->stored()['record_id']);
        $pending = $service->readForSpec($stored, [7]);
        self::assertSame('pending', $pending['status']);
        self::assertFalse($pending['readback_verified']);
        self::assertGreaterThanOrEqual(5, $pending['pending_item_count']);
        $specJson = Db::name('ai_report_presentation_specs')->where('id', $stored['record_id'])->value('spec_json');
        $input = ['expected_review_fingerprint' => $pending['review_fingerprint'], 'decisions' => [['id' => 'check:scope', 'decision' => 'confirmed', 'note' => '只核对OTA渠道']], 'idempotency_key' => 'partial-review-1'];
        $saved = $service->saveAndReadback($stored, [7], $input, 9);
        self::assertTrue($saved['readback_verified']);
        self::assertSame($saved, $service->readForSpec($stored, [7]) + ['idempotent' => false]);
        self::assertTrue($service->saveAndReadback($stored, [7], $input, 9)['idempotent']);
        self::assertSame(1, Db::name($service::TABLE)->count());
        self::assertSame($specJson, Db::name('ai_report_presentation_specs')->where('id', $stored['record_id'])->value('spec_json'));
        self::assertSame('pending', $stored['spec']['qa']['human_review_status']);
        self::assertFalse($saved['operating_approval_granted']);
        self::assertFalse($saved['external_write_authorized']);
    }

    public function testFormalExportNeedsThisSavedVersionAndProducesImmutableSameStatusHtmlPptxReviewManifest(): void
    {
        $stored = $this->stored();
        $reviews = new AiDailyReportPresentationReviewService();
        $artifacts = new AiDailyReportPresentationArtifactService();
        $pending = $reviews->readForSpec($stored, [7]);
        $draft = $artifacts->saveAndReadback($stored, 9);
        self::assertSame('draft', $draft['export_mode']);
        self::assertSame('pending', $draft['human_review_status']);
        $this->fails(fn() => $artifacts->saveAndReadback($stored, 9, true, 'formal', $pending['review_fingerprint']), 'presentation_review_required_before_formal_export');
        $reviewed = $this->complete($stored);
        $formal = $artifacts->saveAndReadback($stored, 9, true, 'formal', $reviewed['review_fingerprint']);
        self::assertSame('reviewed', $formal['human_review_status']);
        self::assertSame('formal', $formal['export_mode']);
        self::assertSame($reviewed['review_fingerprint'], $formal['review_fingerprint']);
        self::assertNotSame($draft['artifact_id'], $formal['artifact_id']);
        self::assertNotSame($draft['content_sha256'], $formal['content_sha256']);
        $old = $artifacts->readExact(88, $draft['artifact_id'], [7], 3, true);
        self::assertSame($draft['bundle_base64'], $old['bundle_base64']);
        self::assertSame('pending', $old['human_review_status']);
        self::assertSame($formal['artifact_id'], $artifacts->saveAndReadback($stored, 9, true, 'formal', $reviewed['review_fingerprint'])['artifact_id']);
        $files = $this->unzip(base64_decode($formal['bundle_base64'], true));
        self::assertCount(5, $files);
        $manifest = $formal['manifest'];
        self::assertSame($reviewed['review_fingerprint'], json_decode($files['presentation-review.json'], true)['review_fingerprint']);
        self::assertSame($stored['spec'], json_decode($files['presentation-spec.json'], true));
        self::assertStringContainsString('正式版 · 人工复核：已逐项复核', $files[$manifest['components']['html']['filename']]);
        $pptx = $this->unzip($files[$manifest['components']['pptx']['filename']]);
        foreach ($pptx as $name => $xml) if (preg_match('#^ppt/slides/slide\d+\.xml$#', $name)) self::assertStringContainsString('正式版 · 人工复核：已逐项复核', $xml);
        self::assertStringContainsString($reviewed['source_evidence_fingerprint'], $pptx['ppt/notesSlides/notesSlide1.xml']);
        self::assertSame('pass', (new AiDailyReportPresentationRendererService())->verifyBundle(base64_decode($formal['bundle_base64']), $manifest)['status']);
        self::assertFalse($manifest['contract']['operating_approval_granted']);
    }

    public function testChangedSourcesCreateNewPendingReviewAndCannotReuseOldApproval(): void
    {
        $stored = $this->stored();
        $approved = $this->complete($stored);
        $report = $this->report();
        $report['result_contract']['result_version'] = str_repeat('c', 64);
        $report['result_layers']['source_facts'][0]['value'] = 13;
        $new = (new AiDailyReportPresentationSpecService())->saveAndReadback($report, 'owner', 9);
        $pending = (new AiDailyReportPresentationReviewService())->readForSpec($new, [7]);
        self::assertNotSame($stored['spec_fingerprint'], $new['spec_fingerprint']);
        self::assertSame('pending', $pending['status']);
        self::assertNull($pending['review_id']);
        self::assertNotSame($approved['source_evidence_fingerprint'], $pending['source_evidence_fingerprint']);
        $this->fails(fn() => (new AiDailyReportPresentationArtifactService())->saveAndReadback($new, 9, true, 'formal', $approved['review_fingerprint']), 'presentation_review_stale');
        self::assertSame('reviewed', (new AiDailyReportPresentationReviewService())->readForSpec($stored, [7])['status']);
    }

    public function testHotelIdentityChangeInvalidatesOldReviewWithoutRewritingItsEvidence(): void
    {
        $stored = $this->stored();
        $approved = $this->complete($stored);
        $service = new AiDailyReportPresentationReviewService();
        $savedEvidence = Db::name($service::TABLE)->where('id', $approved['review_id'])->find();

        // Mutable identity columns move; the immutable review snapshot keeps its original scope.
        Db::name('hotels')->where('id', 7)->update(['id' => 77]);
        Db::name('ai_report_presentation_specs')->where('id', $stored['record_id'])->update(['hotel_id' => 77]);
        $moved = $stored;
        $moved['hotel_id'] = 77;

        $reason = 'hotel_identity_mapping_unverified_rebuild_current_spec';
        $this->fails(fn() => $service->readForSpec($moved, [77]), $reason);
        $this->fails(fn() => $service->readSnapshotForSpec($moved, [77], $approved['review_id']), $reason);
        $this->fails(fn() => $service->saveAndReadback($moved, [77], [
            'expected_review_fingerprint' => $approved['review_fingerprint'],
            'decisions' => [['id' => 'check:scope', 'decision' => 'confirmed']],
        ], 9), $reason);
        self::assertSame($savedEvidence, Db::name($service::TABLE)->where('id', $approved['review_id'])->find());
        self::assertSame(1, Db::name($service::TABLE)->count());
    }

    public function testGapCannotBePromotedAndRevisionRequiresNoteAndBlocksFormalExport(): void
    {
        $stored = $this->stored();
        $service = new AiDailyReportPresentationReviewService();
        $pending = $service->readForSpec($stored, [7]);
        $gap = array_values(array_filter($pending['items'], static fn(array $i): bool => $i['is_evidence_gap']))[0];
        $this->fails(fn() => $service->saveAndReadback($stored, [7], ['expected_review_fingerprint' => $pending['review_fingerprint'], 'decisions' => [['id' => $gap['id'], 'decision' => 'confirmed']]], 9), 'presentation_review_cannot_promote_gap_to_fact');
        $this->fails(fn() => $service->saveAndReadback($stored, [7], ['expected_review_fingerprint' => $pending['review_fingerprint'], 'decisions' => [['id' => 'check:pptx', 'decision' => 'needs_revision']]], 9), 'presentation_review_note_invalid');
        $reviewed = $this->complete($stored);
        $revision = $service->saveAndReadback($stored, [7], ['expected_review_fingerprint' => $reviewed['review_fingerprint'], 'decisions' => [['id' => 'check:pptx', 'decision' => 'needs_revision', 'note' => '标题超出文本框']]], 9);
        self::assertSame('needs_revision', $revision['status']);
        self::assertSame(1, $revision['revision_item_count']);
        $this->fails(fn() => (new AiDailyReportPresentationArtifactService())->saveAndReadback($stored, 9, true, 'formal', $revision['review_fingerprint']), 'presentation_review_required_before_formal_export');
    }

    public function testScopeFingerprintConcurrencyAndIdempotencyFailuresDoNotInsertReview(): void
    {
        $stored = $this->stored();
        $service = new AiDailyReportPresentationReviewService();
        $this->fails(fn() => $service->readForSpec($stored, [8]), 'presentation_review_hotel_not_permitted');
        $wrongTenant = $stored; $wrongTenant['tenant_id'] = 4;
        $this->fails(fn() => $service->readForSpec($wrongTenant, [7]), 'presentation_review_hotel_not_permitted');
        $tampered = $stored; $tampered['spec']['deck']['title'] = 'tampered';
        $this->fails(fn() => $service->readForSpec($tampered, [7]), 'presentation_review_spec_fingerprint_mismatch');
        $pending = $service->readForSpec($stored, [7]);
        $input = ['expected_review_fingerprint' => $pending['review_fingerprint'], 'decisions' => [['id' => 'check:scope', 'decision' => 'confirmed']], 'idempotency_key' => 'concurrency-key'];
        $service->saveAndReadback($stored, [7], $input, 9);
        $changed = $input; $changed['decisions'][0]['note'] = 'different';
        $this->fails(fn() => $service->saveAndReadback($stored, [7], $changed, 9), 'presentation_review_idempotency_conflict');
        $changed['idempotency_key'] = 'other-unique-key';
        $this->fails(fn() => $service->saveAndReadback($stored, [7], $changed, 9), 'presentation_review_stale');
        self::assertSame(1, Db::name($service::TABLE)->count());
    }

    public function testCorruptSavedReviewIsRejectedRatherThanRenderedOrZeroFilled(): void
    {
        $stored = $this->stored();
        $review = $this->complete($stored);
        Db::name(AiDailyReportPresentationReviewService::TABLE)->where('id', $review['review_id'])->update(['review_json' => '{}']);
        $this->fails(fn() => (new AiDailyReportPresentationReviewService())->readForSpec($stored, [7]), 'presentation_review_content_digest_mismatch');
        $this->fails(fn() => (new AiDailyReportPresentationArtifactService())->saveAndReadback($stored, 9), 'presentation_review_content_digest_mismatch');
        self::assertSame(0, Db::name('ai_report_presentation_artifacts')->count());
    }

    public function testChecksumConsistentUnknownDecisionCannotUnlockFormalExport(): void
    {
        $stored = $this->stored();
        $review = $this->complete($stored);
        $payload = json_decode((string)Db::name(AiDailyReportPresentationReviewService::TABLE)->where('id', $review['review_id'])->value('review_json'), true);
        $payload['items'][0]['decision'] = 'unsupported_decision';
        $fingerprint = $this->rewriteReviewForFixture($review['review_id'], $payload);
        $this->fails(fn() => (new AiDailyReportPresentationReviewService())->readForSpec($stored, [7]), 'presentation_review_items_mismatch');
        $this->fails(fn() => (new AiDailyReportPresentationArtifactService())->saveAndReadback($stored, 9, true, 'formal', $fingerprint), 'presentation_review_items_mismatch');
        self::assertSame(0, Db::name('ai_report_presentation_artifacts')->count());
    }

    public function testChecksumConsistentGapPromotionIsRejectedOnReadback(): void
    {
        $stored = $this->stored();
        $review = $this->complete($stored);
        $payload = json_decode((string)Db::name(AiDailyReportPresentationReviewService::TABLE)->where('id', $review['review_id'])->value('review_json'), true);
        $foundGap = false;
        foreach ($payload['items'] as &$item) {
            if ($item['is_evidence_gap']) { $item['decision'] = 'confirmed'; $foundGap = true; break; }
        }
        unset($item);
        self::assertTrue($foundGap);
        $fingerprint = $this->rewriteReviewForFixture($review['review_id'], $payload);
        $this->fails(fn() => (new AiDailyReportPresentationReviewService())->readForSpec($stored, [7]), 'presentation_review_items_mismatch');
        $this->fails(fn() => (new AiDailyReportPresentationArtifactService())->saveAndReadback($stored, 9, true, 'formal', $fingerprint), 'presentation_review_items_mismatch');
        self::assertSame(0, Db::name('ai_report_presentation_artifacts')->count());
    }

    public function testTrainingExportOmitsScopeIdentifiersAndArbitraryPrivateReviewerNotes(): void
    {
        $stored = $this->stored('training');
        $reviewed = $this->complete($stored, '敏感经营备注 TEST-ONLY酒店 手机13800138000');
        $context = (new AiDailyReportPresentationReviewService())->exportContext($reviewed, 'formal');
        self::assertArrayNotHasKey('hotel_id', $context);
        self::assertArrayNotHasKey('tenant_id', $context);
        self::assertTrue($context['review_notes_redacted']);
        $artifact = (new AiDailyReportPresentationArtifactService())->saveAndReadback($stored, 9, true, 'formal', $reviewed['review_fingerprint']);
        $files = $this->unzip(base64_decode($artifact['bundle_base64']));
        self::assertStringNotContainsString('13800138000', $files['presentation-review.json']);
        self::assertStringNotContainsString('13800138000', $files['manifest.json']);
        self::assertNull(json_decode($files['presentation-spec.json'], true)['source_report']['hotel_id']);
    }

    public function testLegacyFourFileBundleRemainsReadableAndNewReviewVariantDoesNotOverwriteIt(): void
    {
        $stored = $this->stored();
        $legacy = (new AiDailyReportPresentationRendererService())->render($stored['spec']);
        self::assertCount(4, $this->unzip($legacy['bundle']));
        $id = (int)Db::name('ai_report_presentation_artifacts')->insertGetId([
            'tenant_id' => 3, 'hotel_id' => 7, 'report_id' => 88, 'presentation_spec_id' => $stored['record_id'], 'audience' => 'owner',
            'format' => 'bundle_zip', 'renderer_version' => $legacy['renderer_version'], 'spec_fingerprint' => $stored['spec_fingerprint'],
            'content_sha256' => $legacy['content_sha256'], 'content_bytes' => $legacy['content_bytes'], 'mime_type' => $legacy['mime_type'],
            'artifact_filename' => $legacy['filename'], 'manifest_json' => $legacy['manifest_json'], 'artifact_blob' => $legacy['bundle'],
            'render_status' => 'rendered_and_readback_verified', 'created_by' => 9,
        ]);
        $service = new AiDailyReportPresentationArtifactService();
        $new = $service->saveAndReadback($stored, 9);
        self::assertNotSame($id, $new['artifact_id']);
        self::assertSame(2, Db::name('ai_report_presentation_artifacts')->count());
        $old = $service->readExact(88, $id, [7], 3, true);
        self::assertSame($legacy['bundle'], base64_decode($old['bundle_base64']));
        self::assertNull($old['review_fingerprint']);
        self::assertSame('pending', $old['human_review_status']);
    }

    public function testHistoricalArtifactCannotBeReboundToADifferentSpecOrLoseItsReviewLineage(): void
    {
        $stored = $this->stored();
        $review = $this->complete($stored);
        $artifacts = new AiDailyReportPresentationArtifactService();
        $formal = $artifacts->saveAndReadback($stored, 9, true, 'formal', $review['review_fingerprint']);
        Db::name(AiDailyReportPresentationReviewService::TABLE)->where('id', $review['review_id'])->delete();
        $this->fails(fn() => $artifacts->readExact(88, $formal['artifact_id'], [7], 3, true), 'presentation_review_artifact_lineage_missing');
        $report = $this->report(); $report['id'] = 89;
        $other = (new AiDailyReportPresentationSpecService())->saveAndReadback($report, 'owner', 9);
        Db::name('ai_report_presentation_artifacts')->where('id', $formal['artifact_id'])->update([
            'report_id' => 89, 'presentation_spec_id' => $other['record_id'], 'spec_fingerprint' => $other['spec_fingerprint']]);
        $this->fails(fn() => $artifacts->readExact(89, $formal['artifact_id'], [7], 3, true), 'exact readback verification failed');
    }

    public function testRenumberPreservesOldEvidenceAndRequiresNewCurrentScopeReviewBeforeFormalExport(): void
    {
        $stored = $this->stored();
        $reviews = new AiDailyReportPresentationReviewService();
        $artifacts = new AiDailyReportPresentationArtifactService();
        $oldReview = $this->complete($stored);
        $oldFormal = $artifacts->saveAndReadback($stored, 9, true, 'formal', $oldReview['review_fingerprint']);
        $originalReview = Db::name($reviews::TABLE)->where('id', $oldReview['review_id'])->find();
        $originalSpecJson = Db::name('ai_report_presentation_specs')->where('id', $stored['record_id'])->value('spec_json');
        $originalManifest = Db::name('ai_report_presentation_artifacts')->where('id', $oldFormal['artifact_id'])->value('manifest_json');

        // TEST-ONLY: simulate the registry's relational renumber, preserving all digest-bound JSON.
        Db::name('hotels')->where('id', 7)->update(['id' => 70]);
        foreach (['ai_report_presentation_specs', $reviews::TABLE, 'ai_report_presentation_artifacts'] as $table) {
            Db::name($table)->where('hotel_id', 7)->update(['hotel_id' => 70]);
        }
        $oldCurrentWrapper = $stored; $oldCurrentWrapper['hotel_id'] = 70;
        $reason = 'hotel_identity_mapping_unverified_rebuild_current_spec';
        $this->fails(fn() => $reviews->readForSpec($oldCurrentWrapper, [70]), $reason);
        $this->fails(fn() => $reviews->readSnapshotForSpec($oldCurrentWrapper, [70], $oldReview['review_id']), $reason);
        $this->fails(fn() => (new AiDailyReportPresentationSpecService())->readLatest(88, [70], 3), $reason);
        $this->fails(fn() => $artifacts->readExact(88, $oldFormal['artifact_id'], [70], 3, true), $reason);
        $this->fails(fn() => $artifacts->saveAndReadback($oldCurrentWrapper, 9, true, 'formal', $oldReview['review_fingerprint']), $reason);

        $report = $this->report();
        $report['hotel_id'] = 70;
        $report['source_refs'][0]['hotel_id'] = 70;
        $current = (new AiDailyReportPresentationSpecService())->saveAndReadback($report, 'owner', 9);
        self::assertNotSame($stored['record_id'], $current['record_id']);
        self::assertNotSame($stored['spec_fingerprint'], $current['spec_fingerprint']);
        $pending = $reviews->readForSpec($current, [70]);
        self::assertSame('pending', $pending['status']);
        self::assertNull($pending['review_id']);
        $this->fails(fn() => $artifacts->saveAndReadback($current, 9, true, 'formal', $oldReview['review_fingerprint']), 'presentation_review_stale');
        $this->fails(fn() => $artifacts->saveAndReadback($current, 9, true, 'formal', $pending['review_fingerprint']), 'presentation_review_required_before_formal_export');
        $currentReview = $this->complete($current);
        $formal = $artifacts->saveAndReadback($current, 9, true, 'formal', $currentReview['review_fingerprint']);
        self::assertSame(70, $currentReview['hotel_id']);
        self::assertSame('reviewed', $formal['human_review_status']);
        self::assertSame($currentReview['review_fingerprint'], $formal['review_fingerprint']);
        self::assertNotSame($oldFormal['artifact_id'], $formal['artifact_id']);
        self::assertSame($formal['bundle_base64'], $artifacts->readExact(88, $formal['artifact_id'], [70], 3, true)['bundle_base64']);
        $renderer = new AiDailyReportPresentationRendererService();
        $rendered = $renderer->render($current['spec'], $reviews->exportContext($currentReview, 'formal'));
        self::assertSame('pass', $renderer->verifyBundle($rendered['bundle'], $rendered['manifest'])['status']);
        $preserved = Db::name($reviews::TABLE)->where('id', $oldReview['review_id'])->find();
        foreach (['review_json', 'review_fingerprint', 'review_status', 'request_key', 'request_hash'] as $field) {
            self::assertSame($originalReview[$field], $preserved[$field], $field . ' must remain immutable');
        }
        self::assertSame($originalSpecJson, Db::name('ai_report_presentation_specs')->where('id', $stored['record_id'])->value('spec_json'));
        self::assertSame($originalManifest, Db::name('ai_report_presentation_artifacts')->where('id', $oldFormal['artifact_id'])->value('manifest_json'));
    }

    public function testSameTenantRelocationAndForgedReviewHotelRemainRejected(): void
    {
        $stored = $this->stored();
        $reviews = new AiDailyReportPresentationReviewService();
        $review = $this->complete($stored);
        Db::name('hotels')->insert(['id' => 70, 'tenant_id' => 3, 'name' => 'TEST-ONLY同租户另一酒店']);
        Db::name('ai_report_presentation_specs')->where('id', $stored['record_id'])->update(['hotel_id' => 70]);
        Db::name($reviews::TABLE)->where('id', $review['review_id'])->update(['hotel_id' => 70]);
        $relocated = $stored; $relocated['hotel_id'] = 70;
        $this->fails(fn() => $reviews->readForSpec($relocated, [7, 70]), 'hotel_identity_mapping_unverified_rebuild_current_spec');
        Db::name('ai_report_presentation_specs')->where('id', $stored['record_id'])->update(['hotel_id' => 7]);
        Db::name($reviews::TABLE)->where('id', $review['review_id'])->update(['hotel_id' => 7]);
        $payload = json_decode((string)Db::name($reviews::TABLE)->where('id', $review['review_id'])->value('review_json'), true);
        $payload['hotel_id'] = 70;
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        Db::name($reviews::TABLE)->where('id', $review['review_id'])->update(['review_json' => $json, 'review_fingerprint' => hash('sha256', $json)]);
        $this->fails(fn() => $reviews->readForSpec($stored, [7, 70]), 'presentation_review_scope_or_source_mismatch');
        self::assertSame(1, Db::name($reviews::TABLE)->count());
        self::assertSame(0, Db::name('ai_report_presentation_artifacts')->count());
    }

    public function testAnonymizedTrainingReviewCannotTransferAcrossHotelIdentityAndFreshReportCanBeReviewed(): void
    {
        $stored = $this->stored('training');
        $review = $this->complete($stored);
        $oldJson = Db::name(AiDailyReportPresentationReviewService::TABLE)->where('id', $review['review_id'])->value('review_json');
        Db::name('hotels')->where('id', 7)->update(['id' => 70]);
        foreach (['ai_report_presentation_specs', AiDailyReportPresentationReviewService::TABLE] as $table) {
            Db::name($table)->where('hotel_id', 7)->update(['hotel_id' => 70]);
        }
        $stored['hotel_id'] = 70;
        $service = new AiDailyReportPresentationReviewService();
        $this->fails(fn() => $service->readForSpec($stored, [70]), 'hotel_identity_mapping_unverified_rebuild_current_spec');
        // TEST-ONLY: a newly generated current-hotel report has a fresh source result version.
        $report = $this->report(); $report['hotel_id'] = 70; $report['source_refs'][0]['hotel_id'] = 70;
        $report['result_contract']['result_version'] = str_repeat('c', 64);
        $current = (new AiDailyReportPresentationSpecService())->saveAndReadback($report, 'training', 9);
        self::assertNotSame($stored['spec_fingerprint'], $current['spec_fingerprint']);
        self::assertSame('pending', $service->readForSpec($current, [70])['status']);
        $reviewed = $this->complete($current);
        $artifactService = new AiDailyReportPresentationArtifactService();
        $formal = $artifactService->saveAndReadback($current, 9, true, 'formal', $reviewed['review_fingerprint']);
        self::assertSame('reviewed', $formal['human_review_status']);
        self::assertSame($formal['bundle_base64'], $artifactService->readExact(88, $formal['artifact_id'], [70], 3, true)['bundle_base64']);
        self::assertNull($current['spec']['source_report']['hotel_id']);
        self::assertArrayNotHasKey('hotel_id', $formal['manifest']['review']);
        self::assertSame($oldJson, Db::name($service::TABLE)->where('id', $review['review_id'])->value('review_json'));
    }

    private function complete(array $stored, string $note = ''): array
    {
        $service = new AiDailyReportPresentationReviewService();
        $hotelIds = [(int)$stored['hotel_id']];
        $pending = $service->readForSpec($stored, $hotelIds);
        return $service->saveAndReadback($stored, $hotelIds, ['expected_review_fingerprint' => $pending['review_fingerprint'],
            'decisions' => array_map(static fn(array $i): array => ['id' => $i['id'], 'decision' => $i['is_evidence_gap'] ? 'gap_acknowledged' : 'confirmed', 'note' => $note], $pending['items'])], 9);
    }

    private function rewriteReviewForFixture(int $id, array $payload): string
    {
        // Simulate a checksum-consistent malformed legacy record, without weakening the product's review oracle.
        $service = new AiDailyReportPresentationReviewService();
        $json = (new ReflectionMethod($service, 'json'))->invoke($service, $payload);
        $fingerprint = hash('sha256', $json);
        Db::name($service::TABLE)->where('id', $id)->update(['review_json' => $json, 'review_fingerprint' => $fingerprint]);
        return $fingerprint;
    }

    private function stored(string $audience = 'owner'): array { return (new AiDailyReportPresentationSpecService())->saveAndReadback($this->report(), $audience, 9); }
    private function report(): array
    {
        return ['id' => 88, 'tenant_id' => 3, 'hotel_id' => 7, 'report_date' => '2026-10-02', 'summary' => 'TEST-ONLY OTA订单证据',
            'result_contract' => ['result_version' => str_repeat('a', 64), 'metric_version' => 'ai_daily_report_metric.v1', 'reference_version' => str_repeat('b', 64), 'boundary' => 'OTA渠道事实'],
            'source_refs' => [['key' => 'online_daily_data#99', 'platform' => 'ctrip', 'data_source_id' => 99, 'hotel_id' => 7, 'data_date' => '2026-10-02', 'quality_status' => 'ok', 'readback_verified' => true, 'metric_keys' => ['book_order_num']]],
            'result_layers' => ['source_facts' => [['key' => 'orders', 'label' => 'OTA订单', 'value' => 12, 'unit' => '单', 'data_status' => 'available', 'metric_scope' => 'ota_channel']], 'derived_metrics' => [], 'anomaly_signals' => [], 'ai_assistance' => [], 'human_judgments' => []],
            'recommended_actions' => [['title' => '人工核对', 'action' => '仅建议核对', 'status' => 'pending_approval']],
            'data_gaps' => [['code' => 'cost_missing', 'message' => '实际成本缺失，不计算全酒店利润']]];
    }

    private function fails(callable $action, string $message): void
    {
        try { $action(); }
        catch (RuntimeException|InvalidArgumentException $e) { self::assertStringContainsString($message, $e->getMessage()); return; }
        self::fail('expected rejection: ' . $message);
    }

    private function unzip(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'report-review-zip-');
        file_put_contents($path, $content);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path) === true);
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) $files[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        $zip->close(); unlink($path);
        return $files;
    }
}

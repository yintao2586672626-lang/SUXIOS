<?php
declare(strict_types=1);

namespace Tests;

use app\service\MeituanPublicPageEvidenceService;
use app\service\OtaPublicPageDiagnosisService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class MeituanPublicPageFieldSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $fixture = new MeituanPublicPageEvidenceChronologyTest('testOlderSameDaySaveIsRejectedWithoutChangingExactPersistedRow');
        (new \ReflectionMethod($fixture, 'setUp'))->invoke($fixture);
    }

    private function observation(string $time, array $fields, string $url, string $screenshot): array
    {
        return [
            'ota_hotel_id' => '1001',
            'role' => 'self',
            'business_date' => substr($time, 0, 10),
            'collected_at' => $time,
            'source_url' => $url,
            'screenshot_ref' => $screenshot,
            'fields' => $fields,
            'evidence_paths' => array_combine(
                array_keys($fields),
                array_map(static fn(string $key): string => $screenshot . ':' . $key, array_keys($fields))
            ),
        ];
    }

    private function source(string $time, string $url, string $screenshot, int $actor, string $status = 'field_observation'): array
    {
        return [
            'collected_at' => $time,
            'source_url' => $url,
            'screenshot_ref' => $screenshot,
            'captured_by' => $actor,
            'provenance_status' => $status,
        ];
    }

    private function assertFieldSource(array $expected, array $actual): void
    {
        foreach ($expected as $key => $value) {
            self::assertArrayHasKey($key, $actual);
            self::assertSame($value, $actual[$key], $key . ' must identify the field observation.');
        }
    }

    private function diagnosis(MeituanPublicPageEvidenceService $service): array
    {
        return (new OtaPublicPageDiagnosisService())->build(
            10,
            'meituan',
            '2026-09-20',
            $service->listDiagnosisProfiles(10, '2026-09-20')
        );
    }

    private function fact(array $diagnosis, string $field): array
    {
        $facts = array_merge(...array_column($diagnosis['dimensions'], 'facts'));
        $matches = array_values(array_filter($facts, static fn(array $fact): bool => $fact['field_key'] === $field));
        self::assertCount(1, $matches, 'Each retained field must have exactly one diagnosis fact.');
        return $matches[0];
    }

    private function sourceForField(array $sources, string $field): array
    {
        $matches = array_values(array_filter(
            $sources,
            static fn(array $source): bool => in_array($field, $source['field_keys'] ?? [], true)
        ));
        self::assertCount(1, $matches, 'Each field must belong to one matching source group.');
        return $matches[0];
    }

    private function assertObservedOnly(array $diagnosis): void
    {
        self::assertSame(0, $diagnosis['evidence_coverage']['verified_field_count']);
        foreach (array_merge(...array_column($diagnosis['dimensions'], 'facts')) as $fact) {
            self::assertSame('observed', $fact['quality_status']);
            self::assertSame('source_observed', $fact['source_validation_status']);
            self::assertSame('readback_verified', $fact['persistence_readback_status']);
        }
        foreach ($diagnosis['sources'] as $source) {
            self::assertSame('source_observed', $source['source_validation_status']);
            self::assertSame('readback_verified', $source['persistence_readback_status']);
        }
    }

    public function testPartialSaveKeepsExactFieldSourcesInPersistedAndListedProfile(): void
    {
        $service = new MeituanPublicPageEvidenceService();
        $urlA = 'https://hotel.meituan.com/hotel/1001/ratings';
        $urlB = 'https://hotel.meituan.com/hotel/1001/basic';
        $first = $service->saveObservation(10, $this->observation('2026-09-20 09:00:00', ['rating' => 4.8], $urlA, 'shot-nine'), 91);
        $second = $service->saveObservation(10, $this->observation('2026-09-20 10:00:00', ['name' => '合成本店'], $urlB, 'shot-ten'), 92);

        self::assertSame($first['profile']['snapshot_id'], $second['profile']['snapshot_id']);
        self::assertSame(['rating' => 4.8, 'name' => '合成本店'], $second['profile']['fields']);
        self::assertArrayHasKey('field_sources', $second['profile']);
        $this->assertFieldSource($this->source('2026-09-20 09:00:00', $urlA, 'shot-nine', 91), $second['profile']['field_sources']['rating']);
        $this->assertFieldSource($this->source('2026-09-20 10:00:00', $urlB, 'shot-ten', 92), $second['profile']['field_sources']['name']);
        self::assertSame('shot-nine:rating', $second['profile']['evidence_paths']['rating']);
        self::assertSame('shot-ten:name', $second['profile']['evidence_paths']['name']);
        self::assertSame('2026-09-20 10:00:00', $second['profile']['collected_at']);
        self::assertSame('source_observed', $second['profile']['source_validation_status']);
        self::assertTrue($second['profile']['persistence_readback_verified']);
        self::assertSame($second['profile'], $service->listProfiles(10)[0]);
        self::assertSame($second['profile'], $service->listDiagnosisProfiles(10, '2026-09-20')[0]);
        $row = Db::name('online_daily_data')->where('id', $first['profile']['snapshot_id'])->find();
        $stored = json_decode($row['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($second['profile']['field_sources'], $stored['profile']['field_sources']);
        self::assertSame(1, (int)$row['readback_verified']);
        self::assertSame('unverified', $row['validation_status']);
    }

    public function testDiagnosisGroupsFieldsByTheirOwnObservationWithoutInventingSnapshots(): void
    {
        $service = new MeituanPublicPageEvidenceService();
        $urlA = 'https://hotel.meituan.com/hotel/1001/ratings';
        $urlB = 'https://hotel.meituan.com/hotel/1001/basic';
        $first = $service->saveObservation(10, $this->observation('2026-09-20 09:00:00', ['rating' => 4.8, 'platform_grade' => '高档型'], $urlA, 'shot-nine'), 91);
        $service->saveObservation(10, $this->observation('2026-09-20 10:00:00', ['name' => '合成本店'], $urlB, 'shot-ten'), 92);
        $diagnosis = $this->diagnosis($service);

        foreach ([['rating', 4.8, '2026-09-20 09:00:00', $urlA, 'shot-nine'], ['name', '合成本店', '2026-09-20 10:00:00', $urlB, 'shot-ten']] as [$field, $value, $time, $url, $screenshot]) {
            $fact = $this->fact($diagnosis, $field);
            self::assertSame($value, $fact['observed_value']);
            self::assertSame($time, $fact['captured_at']);
            self::assertSame($url, $fact['source_url']);
            self::assertSame($screenshot . ':' . $field, $fact['source_locator']);
            self::assertSame($screenshot, $fact['screenshot_ref']);
            self::assertSame('field_observation', $fact['source_provenance_status']);
            $source = $this->sourceForField($diagnosis['sources'], $field);
            self::assertSame($time, $source['collected_at']);
            self::assertSame($url, $source['source_url']);
            self::assertSame($screenshot, $source['screenshot_ref']);
            self::assertSame('field_observation', $source['provenance_status']);
            self::assertSame('online_daily_data#' . $first['profile']['snapshot_id'], $source['response_ref']);
        }
        self::assertCount(2, $diagnosis['sources']);
        $ratingFields = $this->sourceForField($diagnosis['sources'], 'rating')['field_keys'];
        sort($ratingFields);
        self::assertSame(['platform_grade', 'rating'], $ratingFields);
        self::assertSame(['name'], $this->sourceForField($diagnosis['sources'], 'name')['field_keys']);
        self::assertSame(3, $diagnosis['evidence_coverage']['observed_field_count']);
        $this->assertObservedOnly($diagnosis);
    }

    public function testSameTimeZeroCorrectionUpdatesOnlySubmittedFieldAndKeepsScreenshotGroupsSeparate(): void
    {
        $service = new MeituanPublicPageEvidenceService();
        $time = '2026-09-20 09:00:00';
        $url = 'https://hotel.meituan.com/hotel/1001';
        $first = $service->saveObservation(10, $this->observation($time, ['rating' => 4.8, 'name' => '合成本店'], $url, 'original-shot'), 91);
        $corrected = $service->saveObservation(10, $this->observation($time, ['rating' => 0], $url, 'corrected-shot'), 92);

        self::assertSame($first['profile']['snapshot_id'], $corrected['profile']['snapshot_id']);
        self::assertSame(0, $corrected['profile']['fields']['rating']);
        self::assertArrayHasKey('field_sources', $corrected['profile']);
        $this->assertFieldSource($this->source($time, $url, 'original-shot', 91), $corrected['profile']['field_sources']['name']);
        $this->assertFieldSource($this->source($time, $url, 'corrected-shot', 92), $corrected['profile']['field_sources']['rating']);
        self::assertSame('original-shot:name', $corrected['profile']['evidence_paths']['name']);
        self::assertSame('corrected-shot:rating', $corrected['profile']['evidence_paths']['rating']);
        $diagnosis = $this->diagnosis($service);
        self::assertSame(0, $this->fact($diagnosis, 'rating')['observed_value']);
        self::assertSame('corrected-shot', $this->fact($diagnosis, 'rating')['screenshot_ref']);
        self::assertSame('original-shot', $this->fact($diagnosis, 'name')['screenshot_ref']);
        self::assertCount(2, $diagnosis['sources'], 'Equal URL and time do not merge distinct screenshot evidence.');
        self::assertSame(['name'], $this->sourceForField($diagnosis['sources'], 'name')['field_keys']);
        self::assertSame(['rating'], $this->sourceForField($diagnosis['sources'], 'rating')['field_keys']);
        $this->assertObservedOnly($diagnosis);
    }

    public function testLegacyFieldRetainsOldSnapshotContextWithoutClaimingAFieldObservationTime(): void
    {
        $service = new MeituanPublicPageEvidenceService();
        $urlA = 'https://hotel.meituan.com/hotel/1001/ratings';
        $urlB = 'https://hotel.meituan.com/hotel/1001/basic';
        $first = $service->saveObservation(10, $this->observation('2026-09-20 09:00:00', ['rating' => 4.8], $urlA, 'legacy-shot'), 91);
        $row = Db::name('online_daily_data')->where('id', $first['profile']['snapshot_id'])->find();
        $legacy = json_decode($row['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        unset($legacy['profile']['field_sources']);
        Db::name('online_daily_data')->where('id', $row['id'])->update(['raw_data' => json_encode($legacy, JSON_THROW_ON_ERROR)]);

        $legacyFact = $this->fact($this->diagnosis($service), 'rating');
        self::assertSame('', $legacyFact['captured_at']);
        self::assertSame('2026-09-20 09:00:00', $legacyFact['source_context_at']);
        self::assertSame('legacy_profile', $legacyFact['source_provenance_status']);

        $updated = $service->saveObservation(10, $this->observation('2026-09-20 10:00:00', ['name' => '新补名称'], $urlB, 'new-shot'), 92);
        self::assertArrayHasKey('field_sources', $updated['profile']);
        $this->assertFieldSource($this->source('2026-09-20 09:00:00', $urlA, 'legacy-shot', 91, 'legacy_profile'), $updated['profile']['field_sources']['rating']);
        $this->assertFieldSource($this->source('2026-09-20 10:00:00', $urlB, 'new-shot', 92), $updated['profile']['field_sources']['name']);
        $diagnosis = $this->diagnosis($service);
        $rating = $this->fact($diagnosis, 'rating');
        self::assertSame('', $rating['captured_at']);
        self::assertSame('2026-09-20 09:00:00', $rating['source_context_at']);
        self::assertSame($urlA, $rating['source_url']);
        self::assertSame('legacy-shot', $rating['screenshot_ref']);
        self::assertSame('legacy-shot:rating', $rating['source_locator']);
        self::assertSame('legacy_profile', $rating['source_provenance_status']);
        $name = $this->fact($diagnosis, 'name');
        self::assertSame('2026-09-20 10:00:00', $name['captured_at']);
        self::assertSame($urlB, $name['source_url']);
        self::assertSame('field_observation', $name['source_provenance_status']);
        self::assertCount(2, $diagnosis['sources']);
        $legacySource = $this->sourceForField($diagnosis['sources'], 'rating');
        self::assertSame('legacy_profile', $legacySource['provenance_status']);
        self::assertSame('2026-09-20 09:00:00', $legacySource['collected_at']);
        self::assertSame($urlA, $legacySource['source_url']);
        self::assertSame('legacy-shot', $legacySource['screenshot_ref']);
        $this->assertObservedOnly($diagnosis);
    }

    public function testUnverifiedNewRowDoesNotClaimThatFieldSourcesWereNeverRecorded(): void
    {
        $service = new MeituanPublicPageEvidenceService();
        $input = $this->observation('2026-09-20 09:00:00', ['rating' => 4.8], 'https://hotel.meituan.com/hotel/1001/ratings', 'new-shot');
        $saved = $service->saveObservation(10, $input, 91);
        Db::name('online_daily_data')->where('id', $saved['profile']['snapshot_id'])->update(['readback_verified' => 0]);
        $blocked = $this->diagnosis($service);
        self::assertSame(0, $blocked['evidence_coverage']['observed_field_count']);
        self::assertSame([], array_merge(...array_column($blocked['dimensions'], 'facts')));
        self::assertSame('unverified', $blocked['sources'][0]['persistence_readback_status']);
        self::assertSame('profile_context', $blocked['sources'][0]['provenance_status']);
        self::assertSame([], $blocked['sources'][0]['field_keys']);
        $input['collected_at'] = '2026-09-20 10:00:00';
        $service->saveObservation(10, $input, 91);
        self::assertSame(1, $this->diagnosis($service)['evidence_coverage']['observed_field_count']);
    }

    public function testExecutionDraftKeepsFieldMembershipAndSourceProvenance(): void
    {
        $service = new MeituanPublicPageEvidenceService();
        $urlA = 'https://hotel.meituan.com/hotel/1001/ratings';
        $urlB = 'https://hotel.meituan.com/hotel/1001/basic';
        $first = $service->saveObservation(10, $this->observation('2026-09-20 09:00:00', ['rating' => 4.8], $urlA, 'shot-nine'), 91);
        $service->saveObservation(10, $this->observation('2026-09-20 10:00:00', ['name' => '合成本店'], $urlB, 'shot-ten'), 92);
        $diagnosis = $this->diagnosis($service);
        $draft = (new OtaPublicPageDiagnosisService())->buildExecutionIntentDraft($diagnosis, [
            'assignee_id' => 9,
            'due_at' => '2099-07-18 18:00:00',
            'review_at' => '2099-07-19 10:00:00',
        ]);

        self::assertSame('complete_public_page_evidence', $draft['input']['action_type']);
        self::assertSame('pending_approval', $draft['input']['status']);
        $sources = $draft['input']['evidence']['sources'];
        self::assertCount(2, $sources);
        foreach (['rating', 'name'] as $field) {
            $diagnosisSource = $this->sourceForField($diagnosis['sources'], $field);
            $draftSource = $this->sourceForField($sources, $field);
            foreach (['field_keys', 'provenance_status', 'screenshot_ref', 'source_url', 'collected_at', 'response_ref', 'source_validation_status', 'persistence_readback_status'] as $key) {
                self::assertSame($diagnosisSource[$key], $draftSource[$key], $key . ' must survive the task-draft projection.');
            }
            self::assertSame('source_observed', $draftSource['source_validation_status']);
            self::assertSame('readback_verified', $draftSource['persistence_readback_status']);
        }
        $rowRef = 'online_daily_data#' . $first['profile']['snapshot_id'];
        self::assertSame([$rowRef], array_values(array_unique(array_column($sources, 'response_ref'))));
        self::assertSame(1, count(array_filter($draft['input']['evidence']['evidence_refs'], static fn(string $ref): bool => $ref === $rowRef)));
        $this->assertObservedOnly($diagnosis);
    }
}

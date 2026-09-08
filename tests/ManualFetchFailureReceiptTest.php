<?php
declare(strict_types=1);

namespace Tests;

use app\service\FailureEvidenceService;
use app\service\OtaUpstreamFailureService;
use PHPUnit\Framework\TestCase;

final class ManualFetchFailureReceiptTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        (new \think\App())->initialize();
    }

    private function harness(array $fixture): object
    {
        return new class($fixture) {
            use \app\controller\concern\OnlineDataManualFetchConcern;
            use \app\controller\concern\OnlineDataRequestConcern;
            public array $alerts = [];
            public int $requests = 0;
            public array $parsedBusinessData = [];
            public function __construct(private readonly array $fixture) {}
            public function run(string $platform): \think\Response
            {
                $request = ['config_id' => 'fixture-config', 'start_date' => '2026-09-01', 'end_date' => '2026-09-01', 'auto_save' => true];
                $credential = ['cookies' => 'synthetic-only-never-transmitted'];
                return match ($platform) {
                    'meituan' => $this->executeMeituanManualFetch($request, $credential, 7),
                    'ctrip' => $this->executeCtripManualFetch($request, $credential, 7),
                    'traffic' => $this->executeCtripTrafficFetch($request, $credential, 7),
                };
            }
            private function resolveMeituanManualFetchConfigMetadata(string $id, int $hotel): array
            {
                return ['config_id' => $id, 'system_hotel_id' => $hotel, 'partner_id' => 'fixture-partner', 'poi_id' => 'fixture-poi'];
            }
            private function buildCtripTrafficDateRange(string $range, string $start, string $end): array { return [$start, $end]; }
            private function sendHttpRequest(string $url, array $data, string $cookies, array $auth = []): array { $this->requests++; return $this->fixture; }
            private function sendMeituanRequest(string $url, array $data, string $cookies, array $auth = []): array { $this->requests++; return $this->fixture; }
            private function sendCtripJsonRequest(string $url, array $data, string $cookies): array { $this->requests++; return $this->fixture; }
            private function buildCtripBusinessFingerprint(array $data): string
            {
                $this->parsedBusinessData = $data;
                throw new \RuntimeException('fixture stop after business validation');
            }
            private function recordCookieAlert(string $platform, string $action, string $error, ?int $hotel): void
            {
                $this->alerts[] = ['platform' => $platform, 'hotel' => $hotel, 'requires_login' => OtaUpstreamFailureService::explicitlyRequiresLogin($error)];
            }
            protected function error(string $message = '失败', int $code = 400, mixed $data = null): \think\Response
            {
                return json(['code' => $code, 'message' => $message, 'data' => $data], $code);
            }
        };
    }

    public function testMeituanBusinessFailureReachesReceiptAndAuditWithoutOriginalText(): void
    {
        $fixture = array_merge(OtaUpstreamFailureService::meituanBusinessFailure(303, '尚未登录 synthetic-private'), [
            'success' => false, 'business_code' => 303, 'http_code' => 200,
            'business_message' => 'synthetic-private', 'raw' => 'synthetic-private', 'data' => ['private' => 'synthetic-private'],
        ]);
        $harness = $this->harness($fixture);
        $response = $harness->run('meituan');
        self::assertSame(400, $response->getCode());
        $evidence = FailureEvidenceService::fromResponse($response->getData());
        self::assertSame('login_required', $evidence['reason_code']);
        self::assertSame('upstream_request', $evidence['failure_stage']);
        self::assertSame(303, $evidence['upstream_business_code']);
        self::assertSame(200, $evidence['upstream_http_status']);
        self::assertSame([['platform' => 'meituan', 'hotel' => 7, 'requires_login' => true]], $harness->alerts);
        self::assertSame(1, $harness->requests);
        self::assertStringNotContainsString('synthetic-private', $response->getContent());
    }

    public function testCtripBusinessFailuresReturnSafeEvidenceAndOnlyExplicitLoginAlerts(): void
    {
        foreach ([[403, '权限不足 synthetic-private', 'ctrip_api_error', false], [401, 'synthetic-private', 'login_required', true]] as [$code, $message, $reason, $requiresLogin]) {
            $harness = $this->harness(['success' => true, 'http_code' => 200, 'raw' => 'synthetic-private', 'data' => ['code' => $code, 'message' => $message]]);
            $response = $harness->run('ctrip');
            self::assertSame(400, $response->getCode());
            $evidence = FailureEvidenceService::fromResponse($response->getData());
            self::assertSame($reason, $evidence['reason_code']);
            self::assertSame('upstream_request', $evidence['failure_stage']);
            self::assertSame($code, $evidence['upstream_business_code']);
            self::assertSame($requiresLogin, $harness->alerts[0]['requires_login']);
            self::assertSame(7, $harness->alerts[0]['hotel']);
            self::assertSame(1, $harness->requests);
            self::assertStringNotContainsString('synthetic-private', $response->getContent());
        }
    }

    public function testCtripExplicitFailureEnvelopesCannotFallThroughToPersistence(): void
    {
        foreach ([
            [['success' => false, 'resultCode' => 401, 'message' => '请重新登录 synthetic-private'], 'login_required'],
            [['success' => false, 'message' => '平台拒绝 synthetic-private'], 'ctrip_api_error'],
            [['success' => false, 'code' => 0, 'message' => '平台拒绝 synthetic-private'], 'ctrip_api_error'],
            [['ResponseStatus' => ['Ack' => 'Failure', 'Errors' => [['Message' => '平台拒绝 synthetic-private']]]], 'ctrip_api_error'],
            [['success' => true, 'ResponseStatus' => ['Ack' => 'Failure', 'Errors' => [['Message' => '平台拒绝 synthetic-private']]]], 'ctrip_api_error'],
            [['message' => '', 'ResponseStatus' => ['Ack' => 'Failure', 'Errors' => [['Message' => '请重新登录 synthetic-private']]]], 'login_required'],
            [['message' => '调用失败', 'ResponseStatus' => ['Ack' => 'Failure', 'Errors' => [['Message' => '服务拒绝'], ['Message' => '请重新登录 synthetic-private']]]], 'login_required'],
        ] as [$payload, $reason]) {
            $harness = $this->harness(['success' => true, 'http_code' => 200, 'raw' => 'synthetic-private', 'data' => $payload]);
            $response = $harness->run('ctrip');
            self::assertSame(400, $response->getCode());
            $evidence = FailureEvidenceService::fromResponse($response->getData());
            self::assertSame($reason, $evidence['reason_code']);
            self::assertSame('upstream_request', $evidence['failure_stage']);
            self::assertSame(1, $harness->requests);
            self::assertSame($reason === 'login_required', $harness->alerts[0]['requires_login']);
            self::assertStringNotContainsString('synthetic-private', $response->getContent());
        }
    }

    public function testMainBusinessRootStatusPreservesItsExistingParserBoundary(): void
    {
        $payload = ['success' => true, 'status' => 1, 'rows' => [['count' => 3]]];
        $harness = $this->harness(['success' => true, 'http_code' => 200, 'raw' => '', 'data' => $payload]);
        $harness->run('ctrip'); // The fixture deliberately stops once the normal parser is entered.
        self::assertSame($payload, $harness->parsedBusinessData);
        self::assertSame([], $harness->alerts);
        self::assertNull(OtaUpstreamFailureService::ctripBusinessFailure($payload, false));
        self::assertSame('ctrip_api_error', OtaUpstreamFailureService::ctripBusinessFailure($payload)['reason']);
    }

    public function testCtripTrafficHttpAndBusinessFailuresKeepTheirCauses(): void
    {
        foreach ([302 => 'upstream_redirect', 403 => 'upstream_forbidden', 429 => 'upstream_rate_limited'] as $status => $reason) {
            $harness = $this->harness(OtaUpstreamFailureService::ctripJsonResponse('<html>synthetic-private</html>', $status));
            $response = $harness->run('traffic');
            self::assertSame(400, $response->getCode());
            $evidence = FailureEvidenceService::fromResponse($response->getData());
            self::assertSame($reason, $evidence['reason_code']);
            self::assertSame($status, $evidence['upstream_http_status']);
            self::assertSame('upstream_request', $evidence['failure_stage']);
            self::assertFalse($harness->alerts[0]['requires_login']);
            self::assertStringNotContainsString('synthetic-private', $response->getContent());
        }
        $harness = $this->harness(OtaUpstreamFailureService::ctripJsonResponse('{"code":401,"message":"synthetic-private"}', 200));
        $response = $harness->run('traffic');
        self::assertSame('login_required', FailureEvidenceService::fromResponse($response->getData())['reason_code']);
        self::assertTrue($harness->alerts[0]['requires_login']);
        self::assertStringNotContainsString('synthetic-private', $response->getContent());
    }

    public function testJsonSuccessStaysReadableWhileHtmlAndInvalidJsonAreExplicitFailures(): void
    {
        $raw = '{"code":0,"rows":[{"date":"2026-09-01","count":3}]}';
        $success = OtaUpstreamFailureService::ctripJsonResponse($raw, 200);
        self::assertSame('', $success['error']);
        self::assertSame($raw, $success['raw_response']);
        self::assertSame(3, $success['decoded_data']['rows'][0]['count']);
        foreach (['<html>synthetic-private</html>' => 'upstream_html', 'synthetic-private' => 'upstream_invalid_json'] as $body => $reason) {
            $failure = OtaUpstreamFailureService::ctripJsonResponse($body, 200);
            self::assertSame($reason, $failure['reason']);
            self::assertSame('', $failure['raw_response']);
            self::assertNull($failure['decoded_data']);
            self::assertStringNotContainsString('synthetic-private', json_encode($failure));
        }
    }

    public function testDateAndReadbackFailuresAreNotErasedByAuditAllowlist(): void
    {
        foreach (['response_business_date_missing', 'response_business_date_ambiguous', 'response_business_date_mismatch'] as $reason) {
            $audit = FailureEvidenceService::fromResponse(['data' => ['reason' => $reason, 'stage' => 'date_validation', 'save_status' => 'target_date_unverified']]);
            self::assertSame($reason, $audit['reason_code']);
            self::assertSame('date_validation', $audit['failure_stage']);
            self::assertSame('target_date_unverified', $audit['save_status']);
        }
        foreach (['save_status', 'persistence_status'] as $key) {
            foreach (['not_persisted', 'readback_failed'] as $status) {
                $audit = FailureEvidenceService::fromResponse(['data' => [$key => $status]]);
                self::assertSame($status, $audit['save_status']);
                self::assertSame($status, $audit['reason_code']);
                self::assertSame('persistence', $audit['failure_stage']);
            }
        }
    }
}

<?php
declare(strict_types=1);

namespace Tests\Support\OnlineData;

use app\controller\OnlineData;
use app\command\PlatformProfileLogin;
use app\service\BrowserProfileCaptureRequestService;
use app\service\CtripTrafficDisplayService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\OnlineDataQuerySpy;
use Tests\Support\ReflectionHelper;
use Tests\Support\SourceAggregate;
use think\App;

trait ProfileTestCases
{

    public function testProfileDerivedCookieExtractionRequiresAuthoritativeReusableProof(): void
    {
        $controller = $this->controller();

        self::assertSame(['profile_session_unverified'], $this->invokeNonPublic(
            $controller,
            'profileCookieSourceLoginMissingRequirements',
            [[
                'manual_login_state_verified' => true,
                'profile_status' => 'logged_in',
                'last_login_verified_at' => '2026-05-03 09:00:00',
            ]]
        ));
    }

    public function testPlatformProfileLoginVerifiedConfigMarksTrafficSourceWithoutSensitiveValues(): void
    {
        $command = $this->profileLoginCommand();

        $config = $this->invokeNonPublic($command, 'buildProfileLoginVerifiedConfig', [[
            'registered_by' => 'p0_ota_field_loop',
            'capture_sections' => 'traffic',
            'profile_id' => 'system_60',
            'hotel_id' => 'ctrip-60',
            'profile_status' => 'expired',
            'auth_status' => ['ok' => false, 'status' => 'login_required'],
        ], 'ctrip', 'system_60', [
            'data_source_id' => 14,
            'capture_sections' => 'traffic',
        ], [
            'auth_status' => [
                'ok' => true,
                'status' => 'logged_in',
                'url' => 'https://ebooking.ctrip.com/path?token=secret-token',
                'message' => 'Ctrip profile is logged in.',
            ],
            'capture_gate' => [
                'status' => 'pass',
                'mode' => 'login_only',
                'failed_check_ids' => [],
                'checks' => [[
                    'id' => 'auth_session',
                    'status' => 'pass',
                    'message' => 'ready',
                    'raw_token' => 'must-not-store',
                ]],
            ],
        ], '2026-06-27 09:00:00']);

        self::assertTrue($config['manual_login_state_verified']);
        self::assertTrue($config['login_state_verified']);
        self::assertTrue($config['profile_login_verified']);
        self::assertSame('logged_in', $config['profile_status']);
        self::assertSame('logged_in', $config['login_status']);
        self::assertSame('2026-06-27 09:00:00', $config['last_login_verified_at']);
        self::assertSame('traffic', $config['capture_sections']);
        self::assertSame('p0_ota_field_loop', $config['registered_by']);
        self::assertSame('ctrip-60', $config['hotel_id']);
        self::assertSame('system_60', $config['stable_profile_id']);
        self::assertSame('system_60', $config['profile_binding_key']);
        self::assertSame('ota_account_store', $config['profile_reuse_scope']);
        self::assertTrue($config['profile_daily_reuse_enabled']);
        self::assertSame('data-sources/:id/sync', $config['profile_daily_reuse_entry']);
        self::assertTrue($config['profile_login_probe_required_before_relogin']);
        self::assertSame(['ok' => true, 'status' => 'logged_in', 'message' => 'Ctrip profile is logged in.'], $config['auth_status']);
        self::assertSame('pass', $config['profile_login_capture_gate']['status']);

        $encoded = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString('secret-token', $encoded);
        self::assertStringNotContainsString('must-not-store', $encoded);
        self::assertStringNotContainsString('ebooking.ctrip.com/path', $encoded);
        self::assertArrayNotHasKey('data_type', $config);
        $this->invokeNonPublic($command, 'assertProfileSourceMetadataIsSafe', [$config]);
    }

    public function testPlatformProfileLoginAcceptsMetadataOnlySourceConfig(): void
    {
        $config = $this->invokeNonPublic($this->profileLoginCommand(), 'decodeSafeProfileSourceConfig', [
            json_encode([
                'profile_id' => 'profile-58',
                'capture_sections' => ['traffic', 'orders'],
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        self::assertSame('profile-58', $config['profile_id']);
        self::assertSame(['traffic', 'orders'], $config['capture_sections']);
    }

    public function testPlatformProfileLoginAcceptsOnlyTrustedCtripPublicIdentityReference(): void
    {
        $command = $this->profileLoginCommand();
        $source = [
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
            'enabled' => 1,
            'status' => 'ready',
        ];
        $config = [
            'profile_id' => '6866634',
            'hotel_id' => '130079194',
            'platform_hotel_name' => 'Dunhuang Molan Club Wild Luxury Homestay (Mingsha Mountain & Crescent Spring Branch)',
            'platform_hotel_identity_source' => 'trip_public_profile',
            'platform_hotel_public_url' => 'https://uk.trip.com/hotels/dunhuang-hotel-detail-130079194/dunhuang-molan-club-wild-luxury-homestay/',
            'platform_hotel_identity_checked_at' => '2026-07-22 18:38:12',
        ];
        $request = [
            'system_hotel_id' => 80,
            'profile_id' => '6866634',
            'hotel_id' => '130079194',
        ];

        self::assertSame(
            $config['platform_hotel_name'],
            $this->invokeNonPublic($command, 'validateTrustedCtripPlatformHotelName', [$source, $config, $request])
        );

        foreach ([
            ['platform_hotel_public_url' => 'https://example.com/hotel-detail-130079194/'],
            ['platform_hotel_public_url' => 'https://uk.trip.com/hotels/dunhuang-hotel-detail-999/'],
            ['platform_hotel_identity_source' => 'manual'],
            ['platform_hotel_identity_checked_at' => ''],
        ] as $mutation) {
            self::assertSame('', $this->invokeNonPublic(
                $command,
                'validateTrustedCtripPlatformHotelName',
                [$source, array_merge($config, $mutation), $request]
            ));
        }

        self::assertSame('', $this->invokeNonPublic(
            $command,
            'validateTrustedCtripPlatformHotelName',
            [$source, $config, array_merge($request, ['hotel_id' => '999'])]
        ));
    }

    public function testPlatformProfileLoginRejectsLegacySecretsInsideSourceConfig(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('credential migration is required');
        $this->invokeNonPublic($this->profileLoginCommand(), 'decodeSafeProfileSourceConfig', [
            json_encode([
                'profile_id' => 'profile-58',
                'nested' => ['authorization' => 'Bearer legacy-profile-token'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function testCtripProfileFieldMetadataRejectsCredentialMaterial(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('字段元数据不得包含');
        $this->invokeNonPublic($this->controller(), 'normalizeCtripProfileCaptureField', [[
            'id' => 'unsafe-field',
            'field_key' => 'unsafe_field',
            'field_name' => 'Unsafe field',
            'section' => 'traffic_report',
            'source_interface' => 'Authorization: Bearer profile-field-secret',
            'source_keys' => 'metric.value',
            'enabled' => true,
        ]]);
    }

    public function testPlatformProfileLoginTaskRequestUsesMetadataAllowlist(): void
    {
        $prepared = $this->invokeNonPublic($this->controller(), 'preparePlatformProfileLoginRequest', [
            'ctrip',
            [
                'source_id' => 91,
                'profile_id' => 'profile-58',
                'hotel_id' => 'ctrip-hotel-58',
                'hotel_name' => '测试门店',
                'captureSections' => ['traffic', 'business_overview'],
                'syncAfterLogin' => true,
                'targetDate' => '2026-07-09',
                'debug_note' => 'must-not-enter-task-file',
            ],
            58,
            'profile-58',
        ]);

        self::assertSame('ctrip', $prepared['platform']);
        self::assertSame(58, $prepared['system_hotel_id']);
        self::assertSame(91, $prepared['data_source_id']);
        self::assertSame('traffic,business_overview', $prepared['capture_sections']);
        self::assertSame('2026-07-09', $prepared['data_date']);
        self::assertTrue($prepared['sync_after_login']);
        self::assertArrayNotHasKey('debug_note', $prepared);
    }

    public function testPlatformProfileLoginTaskRequestRejectsReusableSecrets(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('只接受元数据');
        $this->invokeNonPublic($this->controller(), 'preparePlatformProfileLoginRequest', [
            'ctrip',
            [
                'profile_id' => 'profile-58',
                'cookies' => 'sid=profile-login-secret',
            ],
            58,
            'profile-58',
        ]);
    }

    public function testPlatformProfileLoginCachesRedactNestedCredentialMaterial(): void
    {
        $payload = [
            'status' => 'failed',
            'auth_status' => [
                'status' => 'login_required',
                'message' => 'Cookie: sid=cache-cookie-secret; session=cache-session-secret',
                'raw_token' => 'cache-raw-token-secret',
            ],
            'capture_gate' => [
                'checks' => [[
                    'id' => 'auth',
                    'message' => 'Authorization: Bearer cache-auth-secret',
                ]],
            ],
        ];

        $controllerSafe = $this->invokeNonPublic($this->controller(), 'sanitizePlatformProfileLoginCachePayload', [$payload]);
        $commandSafe = $this->invokeNonPublic($this->profileLoginCommand(), 'sanitizeProfileLoginCachePayload', [$payload]);
        foreach ([$controllerSafe, $commandSafe] as $safe) {
            $encoded = (string)json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            self::assertSame('login_required', $safe['auth_status']['status']);
            self::assertSame('[redacted]', $safe['auth_status']['raw_token']);
            foreach (['cache-cookie-secret', 'cache-session-secret', 'cache-raw-token-secret', 'cache-auth-secret'] as $secret) {
                self::assertStringNotContainsString($secret, $encoded);
            }
        }
    }

    public function testPlatformProfileProbeKeepsAntiBotAndSessionExpiredStates(): void
    {
        $controller = $this->controller();
        $command = $this->profileLoginCommand();

        $antiBotStatus = $this->invokeNonPublic($controller, 'ctripProfileProbeStatusCode', [[
            'message' => 'captcha required by platform risk control',
        ], [
            'ok' => false,
            'status' => 'captcha_required',
            'message' => 'captcha required by platform risk control',
        ]]);
        $sessionExpiredStatus = $this->invokeNonPublic($controller, 'ctripProfileProbeStatusCode', [[
            'message' => 'session_expired',
        ], [
            'ok' => false,
            'status' => 'session_expired',
        ]]);
        $loginTaskAntiBot = $this->invokeNonPublic($command, 'profileLoginFailureStatusCode', [
            'human verification required',
            ['ok' => false, 'status' => 'human_verification_required'],
            null,
        ]);

        self::assertSame('anti_bot', $antiBotStatus);
        self::assertSame('session_expired', $sessionExpiredStatus);
        self::assertSame('anti_bot', $loginTaskAntiBot);
    }

    public function testPlatformProfileLoginRequiresStrongSessionProbeEvidence(): void
    {
        $command = $this->profileLoginCommand();
        $strongProbe = [
            'schema_version' => 1,
            'contract_version' => '2026-07-19.1',
            'performed' => true,
            'verified' => true,
            'status' => 'collectable',
            'collectable' => true,
            'proof_eligible' => true,
            'evidence_type' => 'recognized_business_response_2xx_plus_session_cookie',
            'evidence_level' => 'strong',
            'sensitive_values_exposed' => false,
            'signals' => [
                'auth' => ['status' => 'pass'],
                'url' => ['status' => 'pass', 'trusted_host' => true, 'business_path' => true],
                'page' => ['status' => 'pass', 'business_marker_present' => true, 'risk_control_present' => false],
                'session_state' => ['status' => 'pass', 'session_state_count' => 1],
                'api' => ['status' => 'pass', 'successful_response_count' => 1],
                'identity' => ['status' => 'matched', 'hotel_scope_verified' => true],
            ],
        ];

        self::assertTrue($this->invokeNonPublic($command, 'profileLoginSessionProbeEligible', [$strongProbe]));

        foreach ([
            ['evidence_level' => 'partial'],
            ['proof_eligible' => false],
            ['verified' => false],
            ['sensitive_values_exposed' => true],
            ['evidence_type' => 'page_url_only'],
            ['contract_version' => '2026-07-18.9'],
        ] as $mutation) {
            self::assertFalse($this->invokeNonPublic(
                $command,
                'profileLoginSessionProbeEligible',
                [array_merge($strongProbe, $mutation)]
            ));
        }

        $riskProbe = $strongProbe;
        $riskProbe['signals']['page']['risk_control_present'] = true;
        self::assertFalse($this->invokeNonPublic($command, 'profileLoginSessionProbeEligible', [$riskProbe]));
        $missingSessionState = $strongProbe;
        $missingSessionState['signals']['session_state']['session_state_count'] = 0;
        self::assertFalse($this->invokeNonPublic($command, 'profileLoginSessionProbeEligible', [$missingSessionState]));
    }

    public function testPlatformProfileSessionProbeFailureKeepsBackoffWithoutSecrets(): void
    {
        $command = $this->profileLoginCommand();
        $probe = [
            'schema_version' => 1,
            'contract_version' => '2026-07-19.1',
            'mode' => 'session_probe_only',
            'platform' => 'ctrip',
            'performed' => true,
            'verified' => false,
            'status' => 'anti_bot',
            'collectable' => false,
            'proof_eligible' => false,
            'evidence_type' => 'insufficient',
            'evidence_level' => 'blocked',
            'sensitive_values_exposed' => false,
            'retry_after_seconds' => 900,
            'next_retry_at' => '2026-07-19T02:15:00.000Z',
            'message' => 'risk control Cookie: sid=must-not-leak',
            'raw_cookie' => 'must-not-leak',
            'signals' => [
                'page' => ['status' => 'blocked', 'risk_control_present' => true, 'raw_text' => 'must-not-leak'],
                'session_state' => ['status' => 'pass', 'platform_state_count' => 3, 'session_state_count' => 1],
            ],
            'drift_diagnostics' => [
                'contract_version' => '2026-07-19.1',
                'status' => 'suspected',
                'recognized_response_count' => 0,
                'candidate_response_count' => 3,
                'sensitive_values_exposed' => false,
                'signal_ids' => ['protected_route_rule_miss'],
                'advisory_signal_ids' => ['session_cookie_name_fallback', 'token=must-not-leak'],
                'candidate_reason_ids' => ['unknown_business_json_route', 'token=must-not-leak'],
                'candidate_route_samples' => [
                    'https://ebooking.ctrip.com/api/new-dashboard/123456?token=must-not-leak#cookie',
                    'ebooking.ctrip.com/api/new-dashboard/123456?token=second-secret',
                    'https://ebooking.ctrip.com/api/authorization/must-not-leak?cookie=third-secret',
                    'https://ebooking.ctrip.com/api/store/01890f4c-7b2a-7cc2-9f2c-4a7d6e5b3c1a',
                    'https://ebooking.ctrip.com/api/sessionid/cookie-secret-value',
                    'https://ebooking.ctrip.com/api/x-api-key/api-secret-value',
                    'https://ebooking.ctrip.com/api/eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.signatureValue123',
                    'https://ebooking.ctrip.com/api/AbCdEfGhIjKlMnOpQrStUv123456',
                    'https://ebooking.ctrip.com/api/token%2Fencoded-secret-value',
                    'https://ebooking.ctrip.com/api/abcdefghijklmnopqrstuvwxyzABCDEFGH',
                    'https://ebooking.ctrip.com/api/QWxhZGRpbjpvcGVuIHNlc2FtZQ==',
                    'https://ebooking.ctrip.com/api/token%252Fdouble-secret-value',
                    'https://ebooking.ctrip.com/api/token%2525252Fdeep-secret-value',
                    'https://ebooking.ctrip.com/api/connect.sid/cookie-secret-value',
                    'https://ebooking.ctrip.com/api/ctrip_session/shortsecret',
                    'https://ebooking.ctrip.com/api/ctrip-auth/shortsecret',
                    'https://ebooking.ctrip.com/api/ebk_session/shortsecret',
                    'https://ebooking.ctrip.com/api/ebooking-ticket/shortsecret',
                ],
            ],
        ];

        $compact = $this->invokeNonPublic($command, 'compactProfileLoginSessionProbe', [$probe]);
        $emptyDiagnosticsProbe = $probe;
        $emptyDiagnosticsProbe['drift_diagnostics']['signal_ids'] = [];
        $emptyDiagnosticsProbe['drift_diagnostics']['advisory_signal_ids'] = [];
        $emptyDiagnosticsProbe['drift_diagnostics']['candidate_reason_ids'] = [];
        $emptyDiagnosticsProbe['drift_diagnostics']['candidate_route_samples'] = [];
        $emptyDiagnosticsCompact = $this->invokeNonPublic($command, 'compactProfileLoginSessionProbe', [$emptyDiagnosticsProbe]);
        $sensitiveKeyProbe = $probe;
        $sensitiveKeyProbe['drift_diagnostics']['candidate_route_samples'] = [
            'https://ebooking.ctrip.com/api/ABCDEFGHIJKLMNOPQRSTUVWX.token/shortsecret',
            'https://ebooking.ctrip.com/api/v4.public.abcdefghijklmnopqrstuvwxyz1234567890.signature/shortsecret',
            'https://ebooking.ctrip.com/api/abcdefghijklmnopqrstuvwxyz.auth/shortsecret',
            'https://ebooking.ctrip.com/api/PHPSESSID/shortsecret',
            'https://ebooking.ctrip.com/api/foo.bar.sid/shortsecret',
            'https://ebooking.ctrip.com/api/ebfooToken/shortsecret',
            'https://ebooking.ctrip.com/api/ebMerchantSession/shortsecret',
        ];
        $sensitiveKeyCompact = $this->invokeNonPublic($command, 'compactProfileLoginSessionProbe', [$sensitiveKeyProbe]);
        $cacheSafe = $this->invokeNonPublic($command, 'sanitizeProfileLoginCachePayload', [[
            'session_probe' => $compact,
        ]]);
        $status = $this->invokeNonPublic($command, 'profileLoginFailureStatusCode', [
            'probe failed',
            ['ok' => false, 'status' => 'anti_bot'],
            ['retry_after_seconds' => 900, 'next_retry_at' => '2026-07-19T02:15:00.000Z'],
            $probe,
        ]);
        $captureFailedStatus = $this->invokeNonPublic($command, 'profileLoginFailureStatusCode', [
            'Node.js process failed before producing a trusted probe',
            [],
            null,
            ['performed' => false, 'status' => 'capture_failed'],
        ]);
        $legacyProbe = $probe;
        $legacyProbe['contract_version'] = '2026-07-18.9';
        $legacyProbe['status'] = 'collectable';
        $legacyProbe['verified'] = true;
        $legacyProbe['collectable'] = true;
        $legacyStatus = $this->invokeNonPublic($command, 'profileLoginFailureStatusCode', [
            'legacy probe',
            ['ok' => true, 'status' => 'logged_in'],
            null,
            $legacyProbe,
        ]);

        self::assertSame('anti_bot', $status);
        self::assertSame('capture_failed', $captureFailedStatus);
        self::assertSame('platform_contract_drift', $legacyStatus);
        self::assertSame(900, $compact['retry_after_seconds']);
        self::assertSame('2026-07-19T02:15:00.000Z', $compact['next_retry_at']);
        self::assertTrue($compact['signals']['page']['risk_control_present']);
        self::assertSame(1, $cacheSafe['session_probe']['signals']['session_state']['session_state_count']);
        self::assertSame(['protected_route_rule_miss'], $compact['drift_diagnostics']['signal_ids']);
        self::assertSame(['session_cookie_name_fallback'], $compact['drift_diagnostics']['advisory_signal_ids']);
        self::assertSame(['unknown_business_json_route'], $compact['drift_diagnostics']['candidate_reason_ids']);
        self::assertSame([], $emptyDiagnosticsCompact['drift_diagnostics']['signal_ids']);
        self::assertSame([], $emptyDiagnosticsCompact['drift_diagnostics']['advisory_signal_ids']);
        self::assertSame([], $emptyDiagnosticsCompact['drift_diagnostics']['candidate_reason_ids']);
        self::assertSame([], $emptyDiagnosticsCompact['drift_diagnostics']['candidate_route_samples']);
        self::assertSame([
            'ebooking.ctrip.com/api/:redacted/:redacted',
            'ebooking.ctrip.com/api/phpsessid/:redacted',
            'ebooking.ctrip.com/api/foo.bar.sid/:redacted',
            'ebooking.ctrip.com/api/ebfootoken/:redacted',
            'ebooking.ctrip.com/api/ebmerchantsession/:redacted',
        ], $sensitiveKeyCompact['drift_diagnostics']['candidate_route_samples']);
        self::assertSame([
            'ebooking.ctrip.com/api/new-dashboard/:id',
            'ebooking.ctrip.com/api/authorization/:redacted',
            'ebooking.ctrip.com/api/store/:id',
            'ebooking.ctrip.com/api/sessionid/:redacted',
            'ebooking.ctrip.com/api/x-api-key/:redacted',
            'ebooking.ctrip.com/api/:redacted',
            'ebooking.ctrip.com/api/connect.sid/:redacted',
            'ebooking.ctrip.com/api/ctrip_session/:redacted',
            'ebooking.ctrip.com/api/ctrip-auth/:redacted',
            'ebooking.ctrip.com/api/ebk_session/:redacted',
            'ebooking.ctrip.com/api/ebooking-ticket/:redacted',
        ], $compact['drift_diagnostics']['candidate_route_samples']);
        self::assertArrayNotHasKey('raw_cookie', $compact);
        self::assertArrayNotHasKey('raw_text', $compact['signals']['page']);
        self::assertStringNotContainsString('must-not-leak', (string)json_encode($compact, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('second-secret', (string)json_encode($compact, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('third-secret', (string)json_encode($compact, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('cookie-secret-value', (string)json_encode($compact, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('api-secret-value', (string)json_encode($compact, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('encoded-secret-value', (string)json_encode($compact, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('eyJhbGci', (string)json_encode($compact, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('AbCdEfGh', (string)json_encode($compact, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz', (string)json_encode($compact, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('QWxhZGRp', (string)json_encode($compact, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('double-secret-value', (string)json_encode($compact, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('deep-secret-value', (string)json_encode($compact, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('shortsecret', (string)json_encode($compact, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('shortsecret', (string)json_encode($sensitiveKeyCompact, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('ABCDEFGHIJKLMNOP', (string)json_encode($sensitiveKeyCompact, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz.auth', (string)json_encode($sensitiveKeyCompact, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('?', (string)json_encode($compact['drift_diagnostics']['candidate_route_samples'], JSON_UNESCAPED_UNICODE));
    }

    public function testProfileProbeDoesNotPromoteWeakEvidenceWhenAuthLooksLoggedIn(): void
    {
        $status = $this->invokeNonPublic($this->controller(), 'ctripProfileProbeStatusCode', [[
            'session_probe' => [
                'status' => 'weak_evidence',
                'collectable' => false,
                'proof_eligible' => false,
            ],
        ], [
            'ok' => true,
            'status' => 'logged_in',
        ]]);

        self::assertSame('capture_failed', $status);
    }

    public function testPlatformProfileAntiBotBackoffBlocksOnlyUntilRetryTime(): void
    {
        $controller = $this->controller();
        $now = strtotime('2026-07-19T02:00:00.000Z');
        $cached = [
            'status_code' => 'anti_bot',
            'session_probe' => [
                'retry_after_seconds' => 900,
                'next_retry_at' => '2026-07-19T02:15:00.000Z',
            ],
        ];

        $activeUntil = $this->invokeNonPublic($controller, 'platformProfileLoginBackoffUntil', [$cached, $now]);
        $expiredUntil = $this->invokeNonPublic($controller, 'platformProfileLoginBackoffUntil', [$cached, $activeUntil]);
        $otherPlatformState = $this->invokeNonPublic($controller, 'platformProfileLoginBackoffUntil', [[
            'status_code' => 'logged_in',
            'session_probe' => $cached['session_probe'],
        ], $now]);
        $legacyStateWithoutTiming = $this->invokeNonPublic($controller, 'platformProfileLoginBackoffUntil', [[
            'status_code' => 'anti_bot',
        ], $now]);

        self::assertSame(strtotime('2026-07-19T02:15:00.000Z'), $activeUntil);
        self::assertSame(0, $expiredUntil);
        self::assertSame(0, $otherPlatformState);
        self::assertSame(0, $legacyStateWithoutTiming);
    }

    public function testPlatformProfileCachedBlockingStateOverridesReusableSourceDisplay(): void
    {
        $controller = $this->controller();
        $source = [
            'id' => 99,
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
            'system_hotel_id' => 7,
            'last_sync_status' => 'success',
            'last_error' => '',
        ];

        $antiBot = $this->invokeNonPublic($controller, 'resolvePlatformProfileStatusCode', [
            'profile-7',
            true,
            $source,
            ['status_code' => 'anti_bot'],
            [],
        ]);
        $identityUnverified = $this->invokeNonPublic($controller, 'resolvePlatformProfileStatusCode', [
            'profile-7',
            true,
            $source,
            ['status_code' => 'hotel_identity_unverified'],
            [],
        ]);

        self::assertSame('anti_bot', $antiBot);
        self::assertSame('hotel_identity_unverified', $identityUnverified);
    }

    public function testPlatformProfileStatusDetectsAntiBotFromSourceLog(): void
    {
        $controller = $this->controller();

        $status = $this->invokeNonPublic($controller, 'resolvePlatformProfileStatusCode', [
            'store-7',
            true,
            [
                'status' => 'failed',
                'last_sync_status' => 'failed',
                'last_error' => 'captcha required by platform risk control',
            ],
            [],
            [],
        ]);

        self::assertSame('anti_bot', $status);
    }

    public function testBrowserProfileBindingDoesNotPersistRequestCookiesAsDataSourceSecret(): void
    {
        $secretAssignment = "\$payloadForSave['secret'] = ['cookies' => \$cookies];";
        $commandSource = (string)file_get_contents(dirname(__DIR__, 3) . '/app/command/PlatformProfileLogin.php');
        $controllerSource = SourceAggregate::read(
            dirname(__DIR__, 3),
            'app/controller/concern/AutoFetchConcern.php'
        );

        self::assertStringNotContainsString($secretAssignment, $commandSource);
        self::assertStringNotContainsString($secretAssignment, $controllerSource);
    }

    public function testPlatformProfileLoginDataSourceStatusClearsOnlyStaleLoginErrors(): void
    {
        $command = $this->profileLoginCommand();

        self::assertSame('ready', $this->invokeNonPublic($command, 'dataSourceStatusAfterProfileLogin', [[
            'status' => 'waiting_config',
            'data_type' => 'traffic',
        ]]));
        self::assertSame('success', $this->invokeNonPublic($command, 'dataSourceStatusAfterProfileLogin', [[
            'status' => 'success',
            'data_type' => 'traffic',
        ]]));
        self::assertTrue($this->invokeNonPublic($command, 'isStaleProfileLoginError', [
            'Meituan login session is not ready. Re-login with a visible browser Profile before scheduled sync.',
        ]));
        self::assertFalse($this->invokeNonPublic($command, 'isStaleProfileLoginError', [
            'Ctrip browser capture completed but no business rows were parsed.',
        ]));
    }

    public function testAvailableProfileWithoutAuthoritativeProofWaitsForLogin(): void
    {
        $controller = $this->controller();

        self::assertSame('waiting_login', $this->invokeNonPublic($controller, 'resolvePlatformProfileStatusCode', [
            'profile-58',
            true,
            ['ingestion_method' => 'browser_profile', 'status' => 'ready'],
            [],
            ['profile_daily_reuse_enabled' => true],
        ]));
    }

    public function testPlatformProfileLoginBuildsTargetDateTrafficSyncOptions(): void
    {
        $command = $this->profileLoginCommand();

        $options = $this->invokeNonPublic($command, 'buildProfileLoginSyncOptions', ['ctrip', [
            'sync_after_login' => true,
            'target_date' => '2026-06-27',
            'capture_sections' => ['traffic', 'orders'],
            'data_period' => 'historical_daily',
            'snapshot_time' => '2026-06-27 10:00:00',
        ]]);
        $compact = $this->invokeNonPublic($command, 'compactProfileLoginSyncResult', [[
            'task_id' => 91,
            'status' => 'success',
            'message' => 'Platform data synchronized.',
            'normalized_count' => 5,
            'saved_count' => 5,
            'payload' => ['token' => 'must-not-copy'],
        ], 14, $options]);

        self::assertTrue($this->invokeNonPublic($command, 'shouldSyncDataSourceAfterProfileLogin', [[
            'sync_after_login' => true,
        ]]));
        self::assertFalse($this->invokeNonPublic($command, 'shouldSyncDataSourceAfterProfileLogin', [[
            'sync_after_login' => false,
        ]]));
        self::assertSame('profile_login_after_login', $options['trigger_type']);
        self::assertSame('2026-06-27', $options['data_date']);
        self::assertSame('traffic,orders', $options['capture_sections']);
        self::assertSame(['traffic', 'orders'], $options['sections']);
        self::assertSame('historical_daily', $options['data_period']);
        self::assertFalse($options['interactive_browser']);
        self::assertSame('success', $compact['status']);
        self::assertSame(14, $compact['data_source_id']);
        self::assertSame(91, $compact['task_id']);
        self::assertSame(5, $compact['saved_count']);
        self::assertFalse($compact['sensitive_values_exposed']);
        self::assertStringNotContainsString('must-not-copy', json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $meituanOptions = $this->invokeNonPublic($command, 'buildProfileLoginSyncOptions', ['meituan', [
            'target_date' => '2026-07-19',
            'capture_sections' => ['traffic', 'orders', 'reviews', 'ads'],
        ]]);
        $withoutAdsEntry = $this->invokeNonPublic($command, 'constrainProfileLoginSyncOptionsBySource', [
            $meituanOptions,
            [
                'platform' => 'meituan',
                'config_json' => json_encode(['store_id' => 'store-80'], JSON_THROW_ON_ERROR),
            ],
        ]);
        self::assertSame('traffic,orders,reviews', $withoutAdsEntry['capture_sections']);
        self::assertSame(['traffic', 'orders', 'reviews'], $withoutAdsEntry['sections']);
        self::assertSame(['ads'], $withoutAdsEntry['skipped_sections_no_entry']);

        $withAdsEntry = $this->invokeNonPublic($command, 'constrainProfileLoginSyncOptionsBySource', [
            $meituanOptions,
            [
                'platform' => 'meituan',
                'config_json' => json_encode([
                    'store_id' => 'store-80',
                    'ads_url' => 'https://ebmidas.dianping.com/business/home',
                ], JSON_THROW_ON_ERROR),
            ],
        ]);
        self::assertSame('traffic,orders,reviews,ads', $withAdsEntry['capture_sections']);
        self::assertArrayNotHasKey('skipped_sections_no_entry', $withAdsEntry);
    }

    public function testPublicDataSourceSyncCannotForgeInternalProfileLoginBypassOptions(): void
    {
        $options = $this->invokeNonPublic($this->controller(), 'sanitizePublicDataSourceSyncOptions', [[
            'trigger_type' => 'profile_login_after_login',
            'triggerType' => 'profile_login_after_login',
            'interactive_browser' => true,
            'interactiveBrowser' => true,
            'data_date' => '2026-07-18',
        ]]);

        self::assertSame('manual', $options['trigger_type']);
        self::assertFalse($options['interactive_browser']);
        self::assertArrayNotHasKey('triggerType', $options);
        self::assertArrayNotHasKey('interactiveBrowser', $options);
        self::assertSame('2026-07-18', $options['data_date']);
    }

    public function testBrowserProfileCollectionProofNeverPromotesConfirmedEmptyOrMissingScope(): void
    {
        $controller = $this->controller();
        $auth = ['ok' => true, 'status' => 'logged_in'];

        self::assertFalse($this->invokeNonPublic($controller, 'recordVerifiedBrowserProfileCollectionProof', [
            'ctrip', 58, 'profile-58', 18, $auth, '24588', 0, 0, true,
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'browserProfileCollectionProofEligible', [
            58, 'profile-58', 18, $auth, '24588', 1, 2, true,
        ]));
        self::assertFalse($this->invokeNonPublic($controller, 'browserProfileCollectionProofEligible', [
            58, 'profile-58', 0, $auth, '24588', 3, 3, true,
        ]));
        self::assertFalse($this->invokeNonPublic($controller, 'browserProfileCollectionProofEligible', [
            58, 'profile-58', 18, $auth, '24588', 3, 3, false,
        ]));
        self::assertFalse($this->invokeNonPublic($controller, 'browserProfileCollectionProofEligible', [
            58, 'profile-58', 18, ['ok' => false, 'status' => 'login_required'], '24588', 3, 3, true,
        ]));
        self::assertFalse($this->invokeNonPublic($controller, 'browserProfileCollectionProofEligible', [
            58, 'profile-58', 18, $auth, '', 3, 3, true,
        ]));
    }

    public function testBrowserProfileCollectionProofOutcomeExplainsWhyProofWasNotRecorded(): void
    {
        $controller = $this->controller();
        $auth = ['ok' => true, 'status' => 'logged_in'];
        $cases = [
            'no_persisted_rows' => [58, 'profile-58', 18, $auth, '24588', 0, 0, true],
            'readback_not_verified' => [58, 'profile-58', 18, $auth, '24588', 2, 2, false],
            'data_source_scope_missing' => [58, 'profile-58', 0, $auth, '24588', 2, 2, true],
            'auth_evidence_unverified' => [58, 'profile-58', 18, ['ok' => false, 'status' => 'login_required'], '24588', 2, 2, true],
            'hotel_identity_unverified' => [58, 'profile-58', 18, $auth, '', 2, 2, true],
        ];

        foreach ($cases as $reasonCode => $args) {
            $outcome = $this->invokeNonPublic($controller, 'recordBrowserProfileCollectionProofOutcome', [
                'ctrip',
                ...$args,
            ]);
            self::assertSame('not_recorded', $outcome['session_proof_status'], $reasonCode);
            self::assertSame($reasonCode, $outcome['session_proof_reason_code'], $reasonCode);
            self::assertNotSame('', trim((string)$outcome['session_proof_message']), $reasonCode);
            self::assertNotSame('', trim((string)$outcome['session_proof_next_action']), $reasonCode);
            self::assertArrayNotHasKey('error', $outcome, $reasonCode);
        }

        $verified = $this->invokeNonPublic($controller, 'browserProfileCollectionProofStatusPayload', ['verified', 'none']);
        self::assertSame('verified', $verified['session_proof_status']);
        self::assertSame('none', $verified['session_proof_reason_code']);
    }

    public function testPlatformProfileLoginRequestResolvesMeituanDataSourceServerSide(): void
    {
        $controller = $this->controller();

        $request = $this->invokeNonPublic($controller, 'buildPlatformProfileLoginRequestFromDataSource', [
            'meituan',
            [
                'data_source_id' => 18,
                'system_hotel_id' => 58,
                'bind_data_source' => true,
            ],
            [
                'id' => 18,
                'platform' => 'meituan',
                'ingestion_method' => 'browser_profile',
                'enabled' => 1,
                'status' => 'waiting_config',
                'system_hotel_id' => 58,
                'data_type' => 'traffic',
                'name' => '天成美团流量源',
                'config' => [
                    'store_id' => 'mt-store-58',
                    'poi_id' => 'mt-poi-58',
                    'partner_id' => 'partner-58',
                    'capture_sections' => ['traffic', 'orders'],
                ],
                'secret_json' => json_encode(['cookies' => 'must-not-merge'], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        self::assertSame(18, $request['data_source_id']);
        self::assertSame(58, $request['system_hotel_id']);
        self::assertSame('mt-store-58', $request['store_id']);
        self::assertSame('mt-poi-58', $request['poi_id']);
        self::assertSame('partner-58', $request['partner_id']);
        self::assertSame('traffic,orders', $request['capture_sections']);
        self::assertArrayNotHasKey('secret_json', $request);
        self::assertArrayNotHasKey('cookies', $request);
    }

    public function testCtripProfileStatusBindingRequiresStrongMatchedProbe(): void
    {
        $controller = $this->controller();
        self::assertSame(25, $this->invokeNonPublic($controller, 'ctripProfileStatusRequestedDataSourceId', [[
            'data_source_id' => 25,
            'source_id' => 99,
        ]]));
        self::assertSame(0, $this->invokeNonPublic($controller, 'ctripProfileStatusRequestedDataSourceId', [[
            'source_id' => 25,
        ]]));
        $this->invokeNonPublic($controller, 'assertExpectedBrowserProfileDataSourceId', [25, 25]);
        try {
            $this->invokeNonPublic($controller, 'assertExpectedBrowserProfileDataSourceId', [25, 26]);
            self::fail('Mismatched source id must fail before verified proof write.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Profile data source changed before verified proof write.', $exception->getMessage());
        }

        $strongProbe = [
            'schema_version' => 1,
            'contract_version' => '2026-07-19.1',
            'performed' => true,
            'verified' => true,
            'status' => 'collectable',
            'collectable' => true,
            'proof_eligible' => true,
            'evidence_type' => 'recognized_business_response_2xx_plus_session_cookie',
            'evidence_level' => 'strong',
            'sensitive_values_exposed' => false,
            'signals' => [
                'auth' => ['status' => 'pass'],
                'url' => ['trusted_host' => true, 'business_path' => true],
                'page' => [
                    'risk_control_present' => false,
                    'session_expired_present' => false,
                    'challenge_present' => false,
                ],
                'session_state' => ['session_state_count' => 1],
                'api' => ['successful_response_count' => 1],
                'identity' => ['status' => 'matched', 'hotel_scope_verified' => true],
            ],
        ];
        $status = [
            'status_code' => 'logged_in',
            'auth_status' => ['ok' => true, 'status' => 'logged_in'],
            'session_probe' => $strongProbe,
        ];

        self::assertTrue($this->invokeNonPublic($controller, 'ctripProfileStatusProbeEligibleForBinding', [$status]));

        $status['session_probe']['signals']['identity'] = [
            'status' => 'mismatch',
            'hotel_scope_verified' => false,
        ];
        self::assertFalse($this->invokeNonPublic($controller, 'ctripProfileStatusProbeEligibleForBinding', [$status]));

        $status['status_code'] = 'login_expired';
        self::assertFalse($this->invokeNonPublic($controller, 'ctripProfileStatusProbeEligibleForBinding', [$status]));
    }

    public function testPlatformProfileStatusItemExposesMachineReadableBindingContract(): void
    {
        $source = SourceAggregate::read(
            dirname(__DIR__, 3),
            'app/controller/concern/AutoFetchConcern.php'
        );

        self::assertStringContainsString('PlatformProfileBindingReadinessService::buildContract(', $source);
        self::assertStringContainsString("'binding_contract' => \$bindingContract", $source);
    }

    public function testPlatformProfileLoginRequestRejectsMismatchedDataSourceScope(): void
    {
        $controller = $this->controller();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('平台 Profile 数据源与当前酒店不匹配');

        $this->invokeNonPublic($controller, 'buildPlatformProfileLoginRequestFromDataSource', [
            'ctrip',
            [
                'data_source_id' => 14,
                'system_hotel_id' => 60,
            ],
            [
                'id' => 14,
                'platform' => 'ctrip',
                'ingestion_method' => 'browser_profile',
                'enabled' => 1,
                'status' => 'waiting_config',
                'system_hotel_id' => 58,
                'data_type' => 'traffic',
                'config' => [
                    'profile_id' => 'system_58',
                    'hotel_id' => 'ctrip-58',
                ],
            ],
        ]);
    }

    public function testP0ProfileLoginTriggerActionUsesDataSourceIdWithoutRawPlatformIdentifiers(): void
    {
        $controller = $this->controller();

        $action = $this->invokeNonPublic($controller, 'phase1P0ProfileLoginTriggerAction', [
            'ctrip',
            14,
            58,
            '2026-06-27',
        ]);

        self::assertSame('client_local_authorization_required', $action['status']);
        self::assertSame('CLIENT_OPEN', $action['method']);
        self::assertSame('https://ebooking.ctrip.com/home/mainland', $action['entry']);
        self::assertSame('account_owner_local_computer_only', $action['authorization_policy']);
        self::assertTrue($action['server_browser_launch_disabled']);
        self::assertSame(14, $action['client_authorization_context']['data_source_id']);
        self::assertSame(58, $action['client_authorization_context']['system_hotel_id']);
        self::assertSame('2026-06-27', $action['client_authorization_context']['data_date']);
        self::assertSame('/api/online-data/data-sources/14/sync', $action['after_login_sync']['entry']);
        self::assertFalse($action['sensitive_values_exposed']);

        $encoded = json_encode($action, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString('profile_id', $encoded);
        self::assertStringNotContainsString('store_id', $encoded);
        self::assertStringNotContainsString('poi_id', $encoded);
        self::assertStringNotContainsString('cookie', strtolower((string)$encoded));
    }

    public function testCtripAdsRowsDoNotUseProfileIdAsHotelId(): void
    {
        $controller = $this->controller();
        $ad = [
            'campaignId' => 'ad-identity',
            'impressions' => 10,
            'clicks' => 1,
            'consume' => 2,
            'statDate' => '2026-05-18',
        ];

        $rows = $this->invokeNonPublic($controller, 'buildCtripCapturedAdRows', [[$ad], [
            'profile_id' => 'profile-58',
            'ctrip_hotel_id' => 'ctrip-58',
            'request_start_date' => '2026-05-18',
            'request_end_date' => '2026-05-18',
        ], 58]);

        self::assertCount(1, $rows);
        self::assertSame('ctrip-58', $rows[0]['hotel_id']);
        $raw = json_decode((string)$rows[0]['raw_data'], true);
        self::assertSame('ctrip-58', $raw['_capture_context']['hotel_id']);
        self::assertArrayNotHasKey('profile_id', $raw['_capture_context']);

        $profileOnlyRows = $this->invokeNonPublic($controller, 'buildCtripCapturedAdRows', [[$ad], [
            'profile_id' => 'profile-58',
            'request_start_date' => '2026-05-18',
            'request_end_date' => '2026-05-18',
        ], 58]);

        self::assertCount(1, $profileOnlyRows);
        self::assertSame('', $profileOnlyRows[0]['hotel_id']);
        $profileOnlyRaw = json_decode((string)$profileOnlyRows[0]['raw_data'], true);
        self::assertArrayNotHasKey('hotel_id', $profileOnlyRaw['_capture_context']);
        self::assertArrayNotHasKey('profile_id', $profileOnlyRaw['_capture_context']);
    }

    public function testCtripProfilePrefersExistingSystemHotelProfileOverNodeId(): void
    {
        $controller = $this->controller();
        $projectRoot = dirname(__DIR__, 3);
        $profileId = 'phpunit_' . bin2hex(random_bytes(4));
        $profileDir = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ctrip_profile_' . $profileId;

        if (!is_dir($profileDir)) {
            self::assertTrue(mkdir($profileDir, 0775, true));
        }

        try {
            $resolved = $this->invokeNonPublic($controller, 'ctripProfileStoreIdFromConfig', [[
                'node_id' => 'node-should-not-win',
                'system_hotel_id' => $profileId,
            ], 0]);

            self::assertSame($profileId, $resolved);
        } finally {
            if (is_dir($profileDir)) {
                @rmdir($profileDir);
            }
        }
    }

    public function testCtripProfileCanResolveOtaHotelIdWhenProfileIdMissing(): void
    {
        $controller = $this->controller();

        $resolved = $this->invokeNonPublic($controller, 'ctripProfileStoreIdFromConfig', [[
            'ota_hotel_id' => 'ctrip-ota-24588',
            'node_id' => 'node-24588',
            'system_hotel_id' => '7',
        ], 0]);

        self::assertSame('ctrip-ota-24588', $resolved);
    }

    public function testCtripProfileStatusReportsReusableProfileWithoutLeakingCookie(): void
    {
        $controller = $this->controller();
        $projectRoot = dirname(__DIR__, 3);
        $profileId = 'phpunit_status_' . bin2hex(random_bytes(4));
        $profileDir = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ctrip_profile_' . $profileId;

        if (!is_dir($profileDir)) {
            self::assertTrue(mkdir($profileDir, 0775, true));
        }

        try {
            $status = $this->invokeNonPublic($controller, 'buildCtripProfileStatus', [[
                'profile_id' => $profileId,
            ], null, false]);

            self::assertSame($profileId, $status['profile_id']);
            self::assertTrue($status['exists']);
            self::assertSame('storage/ctrip_profile_' . $profileId, $status['profile_dir']);
            self::assertFalse($status['cookie_probe_requested']);
            self::assertFalse($status['cookie_extractable']);
            self::assertSame(0, $status['cookie_count']);
            self::assertArrayNotHasKey('cookie', $status);
            self::assertArrayNotHasKey('cookies', $status);
        } finally {
            if (is_dir($profileDir)) {
                @rmdir($profileDir);
            }
        }
    }

    public function testCtripProfileStatusExposesMissingProfileNextAction(): void
    {
        $controller = $this->controller();
        $profileId = 'missing_' . bin2hex(random_bytes(4));

        $status = $this->invokeNonPublic($controller, 'buildCtripProfileStatus', [[
            'profile_id' => $profileId,
        ], null, false]);

        self::assertSame($profileId, $status['profile_id']);
        self::assertFalse($status['exists']);
        self::assertSame('missing_profile', $status['status']);
        self::assertStringContainsString('Profile', $status['next_action']);
    }

    public function testCtripHybridAutoRunsProfileWhenBrowserDataSourceExists(): void
    {
        $controller = $this->controller();
        $sources = [['id' => 13, 'platform' => 'ctrip', 'ingestion_method' => 'browser_profile']];

        self::assertFalse($this->invokeNonPublic($controller, 'shouldRunCtripProfileBrowser', ['cookie_config', $sources]));
        self::assertFalse($this->invokeNonPublic($controller, 'shouldRunCtripProfileBrowser', ['hybrid_auto', []]));
        self::assertTrue($this->invokeNonPublic($controller, 'shouldRunCtripProfileBrowser', ['hybrid_auto', $sources]));
        self::assertTrue($this->invokeNonPublic($controller, 'shouldRunCtripProfileBrowserForCost', ['hybrid_auto', 26, $sources]));
    }

    public function testPlatformProfileStatusUsesLatestLoginFailureOverLoggedInCache(): void
    {
        $controller = $this->controller();

        $status = $this->invokeNonPublic($controller, 'resolvePlatformProfileStatusCode', [
            '6866634',
            true,
            [
                'last_sync_status' => 'failed',
                'last_error' => 'Ctrip browser Profile section capture failed: Ctrip login timeout after 30 seconds',
            ],
            [
                'status_code' => 'logged_in',
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
            ],
        ]);

        self::assertSame('login_expired', $status);

        $nonAuthFailure = $this->invokeNonPublic($controller, 'resolvePlatformProfileStatusCode', [
            '6866634',
            true,
            [
                'last_sync_status' => 'failed',
                'last_error' => 'field coverage failed',
            ],
            [
                'status_code' => 'logged_in',
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
            ],
        ]);

        self::assertSame('capture_failed', $nonAuthFailure);
    }

    public function testPlatformProfileLoginExpiredPromotesReloginAction(): void
    {
        $controller = $this->controller();

        $checks = $this->invokeNonPublic($controller, 'buildPlatformProfileBindingChecks', [
            'ctrip',
            7,
            ['profile_id' => '6866634', 'ota_hotel_id' => '6866634'],
            [
                'system_hotel_id' => 7,
                'last_sync_status' => 'failed',
                'last_error' => 'browser_profile needs relogin Ctrip login timeout after 30 seconds',
            ],
            'login_expired',
            true,
            '6866634',
        ]);
        $byKey = [];
        foreach ($checks as $check) {
            $byKey[$check['key']] = $check;
        }

        self::assertSame('error', $byKey['profile_login']['status']);
        self::assertSame('login_platform_profile', $byKey['profile_login']['action_key']);

        $primary = $this->invokeNonPublic($controller, 'firstPlatformProfileBindingAction', [$checks]);
        self::assertSame('profile_login', $primary['check_key']);
        self::assertSame('login_platform_profile', $primary['action_key']);
    }

    public function testPlatformProfileBindingChecksExposeDirectP0Actions(): void
    {
        $controller = $this->controller();

        $checks = $this->invokeNonPublic($controller, 'buildPlatformProfileBindingChecks', [
            'meituan',
            7,
            ['hotel_id' => 7],
            ['system_hotel_id' => 7, 'last_sync_status' => 'failed', 'last_error' => 'login expired'],
            'capture_failed',
            false,
            '',
        ]);
        $byKey = [];
        foreach ($checks as $check) {
            $byKey[$check['key']] = $check;
        }

        self::assertSame('configure_meituan_poi', $byKey['platform_identity']['action_key']);
        self::assertSame('补齐美团 POI/Store', $byKey['platform_identity']['action_label']);
        self::assertSame('platform-sources', $byKey['platform_identity']['action_target']);
        self::assertSame('open_sync_logs', $byKey['trial_capture']['action_key']);
        self::assertSame('查看日志并重试采集', $byKey['trial_capture']['action_label']);
        self::assertSame('sync-logs', $byKey['trial_capture']['action_target']);

        $primary = $this->invokeNonPublic($controller, 'firstPlatformProfileBindingAction', [$checks]);
        self::assertSame('profile_login', $primary['check_key']);
        self::assertSame('open_sync_logs', $primary['action_key']);
        self::assertSame('查看最近同步日志后重新检测登录状态', $primary['next_action']);
    }

    public function testPlatformProfileBindingChecksPromoteLoginActionWhenProfileNotLoggedIn(): void
    {
        $controller = $this->controller();

        $checks = $this->invokeNonPublic($controller, 'buildPlatformProfileBindingChecks', [
            'meituan',
            7,
            ['hotel_id' => 7, 'poi_id' => 'poi-7', 'partner_id' => 'partner-7'],
            null,
            'waiting_login',
            false,
            'poi-7',
        ]);
        $byKey = [];
        foreach ($checks as $check) {
            $byKey[$check['key']] = $check;
        }
        $primary = $this->invokeNonPublic($controller, 'firstPlatformProfileBindingAction', [$checks]);

        self::assertSame('ok', $byKey['platform_identity']['status']);
        self::assertSame('warning', $byKey['profile_login']['status']);
        self::assertSame('profile_login', $primary['check_key']);
        self::assertSame('login_platform_profile', $primary['action_key']);
        self::assertSame('登录美团', $primary['action_label']);
        self::assertSame('profile-login', $primary['action_target']);
        self::assertSame('点击“登录美团”完成平台验证', $primary['next_action']);
    }

    public function testCtripProfileCaptureConfigOptionsNormalizeSectionsAndMappingAliases(): void
    {
        $controller = $this->controller();

        $options = $this->invokeNonPublic($controller, 'buildCtripProfileCaptureConfigOptions', [[
            'captureSections' => ['business', 'traffic', 'quality_psi', '../bad', 'BIZTRAVEL_BPI'],
            'approvedMappingPath' => ' docs/ctrip_approved_mapping.example.json ',
        ], []]);

        self::assertSame('all', $options['capture_sections']);
        self::assertSame('all', $options['profile_sections']);
        self::assertSame('docs/ctrip_approved_mapping.example.json', $options['approved_mappings_path']);
    }

    public function testCtripProfileCaptureConfigOptionsPreserveOriginalWhenKeysAreAbsent(): void
    {
        $controller = $this->controller();

        $options = $this->invokeNonPublic($controller, 'buildCtripProfileCaptureConfigOptions', [[], [
            'capture_sections' => 'business,traffic,quality_psi',
            'approved_mappings_path' => 'docs/approved.json',
        ]]);

        self::assertSame('all', $options['capture_sections']);
        self::assertSame('all', $options['profile_sections']);
        self::assertSame('docs/approved.json', $options['approved_mappings_path']);
    }

    public function testCtripProfileCaptureConfigOptionsDefaultToDefaultPreset(): void
    {
        $controller = $this->controller();

        $options = $this->invokeNonPublic($controller, 'buildCtripProfileCaptureConfigOptions', [[], []]);

        self::assertSame('all', $options['capture_sections']);
        self::assertSame('all', $options['profile_sections']);
    }

    public function testCtripProfileCaptureFieldDefaultsCoverLatestTaskFieldsAndGaps(): void
    {
        $controller = $this->controller();

        $fields = $this->invokeNonPublic($controller, 'defaultCtripProfileCaptureFields');
        $modules = $this->invokeNonPublic($controller, 'defaultCtripProfileCaptureModules');
        $byKey = [];
        foreach ($fields as $field) {
            $byKey[$field['field_key']] = $field;
        }
        self::assertArrayNotHasKey('room_type', $modules);
        self::assertArrayNotHasKey('competitor_hotel_list', $byKey);
        self::assertSame('https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true', $modules['business_overview']['page_url']);
        self::assertSame('https://ebooking.ctrip.com/datacenter/inland/businessreport/weekReport?microJump=true', $modules['business_weekly_overview']['page_url']);
        self::assertSame('https://ebooking.ctrip.com/datacenter/inland/businessreport/beneficialdata?microJump=true', $modules['sales_report']['page_url']);
        self::assertSame('https://ebooking.ctrip.com/datacenter/inland/businessreport/flowdata?microJump=true', $modules['traffic_report']['page_url']);
        self::assertSame('竞争圈动态-竞争圈概览', $modules['competitor_overview']['label']);
        self::assertSame('https://ebooking.ctrip.com/ebkgrowth/datacenter/competition/competitionprofile?microJump=true', $modules['competitor_overview']['page_url']);
        self::assertSame('用户行为-IM看板', $modules['im_board']['label']);
        self::assertSame('https://ebooking.ctrip.com/datacenter/inland/userbehavior/user?goto=im', $modules['im_board']['page_url']);
        self::assertSame('经营收益数据', $modules['business_overview']['primary_category']);
        self::assertSame('经营收益数据', $modules['sales_report']['primary_category']);
        self::assertSame('流量转化数据', $modules['traffic_report']['primary_category']);
        self::assertSame('流量转化数据', $modules['ads_pyramid']['primary_category']);
        self::assertSame('服务质量数据', $modules['comment_review']['primary_category']);
        self::assertSame('服务质量数据', $modules['quality_psi']['primary_category']);
        self::assertSame('服务质量数据', $modules['im_board']['primary_category']);
        self::assertSame('竞争力数据', $modules['competitor_rank']['primary_category']);
        foreach ($modules as $module) {
            self::assertNotSame('', trim((string)($module['page_url'] ?? '')));
            self::assertContains($module['primary_category'], ['流量转化数据', '经营收益数据', '服务质量数据', '竞争力数据']);
        }

        foreach ([
            'visitor_count',
            'visitor_rank',
            'visitor_count_last_week',
            'competitor_avg_visitor',
            'qunar_visitor_count',
            'qunar_visitor_rank',
            'qunar_visitor_count_last_week',
            'qunar_competitor_avg_visitor',
            'order_count',
            'realtime_booking_orders',
            'realtime_booking_orders_last_week',
            'realtime_booking_orders_rank',
            'order_count_sync',
            'order_count_rank',
            'competitor_avg_orders',
            'ctrip_order_count',
            'ctrip_order_count_sync',
            'ctrip_order_count_rank',
            'qunar_order_count',
            'qunar_order_count_sync',
            'qunar_order_count_rank',
            'elong_order_count',
            'elong_order_count_sync',
            'elong_order_count_rank',
            'order_amount',
            'order_amount_last_week',
            'amount_rank',
            'book_order_num_rank',
            'comment_score_rank',
            'conversion_rank',
            'room_nights',
            'in_house_room_nights',
            'in_house_room_nights_last_week',
            'in_house_room_nights_rank',
            'room_nights_last_week',
            'quantity_rank',
            'occupied_rooms',
            'occupied_rooms_sync',
            'occupied_rooms_rank',
            'competitor_avg_occupied_rooms',
            'avg_price',
            'avg_price_last_week',
            'avg_price_rank',
            'close_rate',
            'close_rate_last_week',
            'close_rate_rank',
            'occupancy_rate',
            'occupancy_rate_sync',
            'occupancy_rate_rank',
            'competition_rank',
            'competition_profile_order_count',
            'competition_profile_order_amount',
            'competition_profile_room_nights',
            'competition_profile_occupancy_rate',
            'competition_profile_app_visitor',
            'competition_profile_app_conversion_rate',
            'competition_profile_list_exposure',
            'competition_profile_detail_visitor',
            'competition_profile_order_page_visitor',
            'competition_profile_list_to_detail_rate',
            'competition_profile_order_fill_rate',
            'competition_profile_psi_score',
            'competition_profile_ctrip_rating',
            'seq_rank',
            'target_date',
            'search_window',
            'compare_scope',
            'future_search_pv',
            'future_search_uv',
            'future_search_order_count',
            'future_search_conversion_rate',
            'list_exposure',
            'competitor_list_exposure',
            'detail_visitor',
            'competitor_detail_visitor',
            'flow_rate',
            'competitor_flow_rate',
            'order_page_visitor',
            'competitor_order_page_visitor',
            'order_fill_rate',
            'competitor_order_fill_rate',
            'order_submit_user',
            'competitor_order_submit_user',
            'deal_rate',
            'competitor_deal_rate',
            'last_week_checkout_room_nights',
            'last_week_checkout_sales',
            'last_week_checkout_room_price',
            'last_week_book_quantity',
            'last_week_book_room_nights',
            'last_week_book_sales',
            'weekly_self_list_exposure',
            'weekly_self_detail_exposure',
            'weekly_self_order_filling_num',
            'weekly_self_order_submit_num',
            'weekly_self_flow_rate',
            'weekly_self_order_fill_rate',
            'weekly_self_deal_rate',
            'weekly_competitor_list_exposure',
            'weekly_competitor_detail_exposure',
            'weekly_competitor_order_filling_num',
            'weekly_competitor_order_submit_num',
            'weekly_competitor_flow_rate',
            'weekly_competitor_order_fill_rate',
            'weekly_competitor_deal_rate',
            'top_competitor_list_exposure',
            'top_competitor_detail_exposure',
            'top_competitor_order_filling_num',
            'top_competitor_order_submit_num',
            'top_competitor_flow_rate',
            'top_competitor_order_fill_rate',
            'top_competitor_deal_rate',
            'weekly_order_page_visitor',
            'weekly_competitor_avg_order_page_visitor',
            'weekly_top_competitor_order_page_visitor',
            'weekly_submit_user',
            'weekly_competitor_avg_submit_user',
            'weekly_top_competitor_submit_user',
            'last_week_comment_score',
            'last_week_good_add',
            'last_week_bad_add',
            'last_week_price_score',
            'flow_lost_order_num',
            'flow_lost_room_nights',
            'flow_lost_amount',
            'top_flow_hotel',
            'top_flow_hotel_browse_rate',
            'top_flow_hotel_order_rate',
            'top_hot_room',
            'top_hot_room_nights',
            'top_hot_room_sale_percent',
            'hot_words_count',
            'top_hot_words',
            'hot_hotels_count',
            'top_hot_hotels',
            'psi_score',
            'service_score_rank',
            'ctrip_comment_count',
            'qunar_comment_count',
            'elong_comment_count',
            'zx_comment_count',
            'ctrip_rating',
            'review_environment_score',
            'review_facility_score',
            'review_service_score',
            'review_cleanliness_score',
            'review_photo_count',
            'review_photo_rate',
            'comment_score_summary',
            'comment_unreply_count',
            'comment_good_rate',
            'reply_rate',
            'reply_rank',
            'five_min_reply_rate',
            'manual_reply_rate',
            'robot_resolution_rate',
            'im_rank',
            'session_count',
            'manual_session_count',
            'robot_session_count',
            'im_order_conversion_rate',
            'hotel_collect',
            'hotel_collect_rank',
            'ad_cost',
        ] as $requiredKey) {
            self::assertArrayHasKey($requiredKey, $byKey);
        }

        foreach ([
            'notice_count',
            'notice_title',
            'notice_text',
            'target_url',
            'diagnosis_score',
            'diagnosis_level',
            'advice_text',
            'comment_rows',
            'good_review_count',
            'qunar_list_exposure',
            'qunar_flow_rate',
            'page_views',
            'flow_conversion_rate',
        ] as $skippedKey) {
            self::assertArrayNotHasKey($skippedKey, $byKey);
        }
        self::assertSame('comment_review', $byKey['bad_review_count']['section']);
        self::assertSame('getCommentNumV2 / getCommentList', $byKey['bad_review_count']['source_interface']);

        self::assertSame('confirmed', $byKey['ad_cost']['status']);
        self::assertTrue($byKey['ad_cost']['enabled']);
        self::assertStringContainsString('todayCost', $byKey['ad_cost']['source_keys']);
        self::assertStringContainsString('cashCost', $byKey['ad_cost']['source_keys']);
        self::assertStringContainsString('bonusCost', $byKey['ad_cost']['source_keys']);
        self::assertStringContainsString('queryFlowTransforNewV1', $byKey['flow_rate']['source_interface']);
        self::assertSame('data.amount', $byKey['order_amount']['json_path']);
        self::assertSame('data.quantity', $byKey['room_nights']['json_path']);
        self::assertSame('data.visitorTotal', $byKey['visitor_count']['json_path']);
        self::assertSame('data.occupiedRooms', $byKey['occupied_rooms']['json_path']);
        self::assertSame('data.orderQuantity', $byKey['order_count']['json_path']);
        self::assertSame('needs_parser', $byKey['realtime_booking_orders']['status']);
        self::assertSame('candidate:data.bookOrderNum', $byKey['realtime_booking_orders']['json_path']);
        self::assertSame('needs_parser', $byKey['in_house_room_nights']['status']);
        self::assertSame('candidate:data.bookQuantity', $byKey['in_house_room_nights']['json_path']);
        self::assertSame('data.occupancyRate', $byKey['occupancy_rate']['json_path']);
        self::assertSame('data.serviceScore / data.psiScoreBo.totalScore', $byKey['psi_score']['json_path']);
        self::assertSame('data.serviceScoreRank', $byKey['service_score_rank']['json_path']);
        self::assertSame('competitor_overview', $byKey['competition_profile_order_count']['section']);
        self::assertStringContainsString('competitionprofile', $byKey['competition_profile_order_count']['page_url']);
        self::assertSame('getManagementData', $byKey['competition_profile_order_count']['source_interface']);
        self::assertSame('dataList[indexType=0].val', $byKey['competition_profile_order_count']['json_path']);
        self::assertStringContainsString('online_daily_data.book_order_num', $byKey['competition_profile_order_count']['storage_field']);
        self::assertStringContainsString('raw_data.metrics', $byKey['competition_profile_order_count']['storage_field']);
        self::assertStringContainsString('计数差 <=1', $byKey['competition_profile_order_count']['notes']);
        self::assertSame('getManagementData', $byKey['competition_profile_app_conversion_rate']['source_interface']);
        self::assertSame('dataList[indexType=5].val', $byKey['competition_profile_app_conversion_rate']['json_path']);
        self::assertSame('getFlowData / getFlowSource', $byKey['competition_profile_list_exposure']['source_interface']);
        self::assertStringContainsString('listExposure', $byKey['competition_profile_list_exposure']['source_keys']);
        self::assertStringContainsString('online_daily_data.list_exposure', $byKey['competition_profile_list_exposure']['storage_field']);
        self::assertSame('dataList[indexType=10].val', $byKey['competition_profile_order_fill_rate']['json_path']);
        self::assertSame('getServiceData', $byKey['competition_profile_psi_score']['source_interface']);
        self::assertSame('dataList[indexType=12].val', $byKey['competition_profile_psi_score']['json_path']);
        self::assertStringContainsString('queryFlowTransforNewV1', $byKey['flow_rate']['request_url']);
        self::assertStringContainsString('flowdata', $byKey['flow_rate']['page_url']);
        self::assertStringContainsString('hotelId=当前携程酒店ID', $byKey['flow_rate']['json_path']);
        self::assertStringContainsString('当前携程酒店ID', $byKey['flow_rate']['ownership_rule']);
        self::assertSame('online_daily_data.flow_rate', $byKey['flow_rate']['storage_field']);
        self::assertStringContainsString('detailExposure / listExposure', $byKey['flow_rate']['transform_rule']);
        self::assertStringContainsString('hotelId=-1', $byKey['competitor_flow_rate']['transform_rule']);
        self::assertStringContainsString('hotelId=-1', $byKey['competitor_flow_rate']['json_path']);
        self::assertStringContainsString('竞争圈平均', $byKey['competitor_flow_rate']['ownership_rule']);
        self::assertStringContainsString('flowRate', $byKey['competitor_flow_rate']['source_keys']);
        self::assertStringContainsString('hotelId=-1', $byKey['competitor_detail_visitor']['transform_rule']);
        self::assertStringContainsString('detailExposure', $byKey['competitor_detail_visitor']['source_keys']);
        self::assertStringContainsString('orderFillingNum / detailExposure', $byKey['order_fill_rate']['transform_rule']);
        self::assertStringContainsString('hotelId=-1', $byKey['competitor_order_fill_rate']['transform_rule']);
        self::assertStringContainsString('orderFillingNum / detailExposure', $byKey['competitor_order_fill_rate']['transform_rule']);
        self::assertStringContainsString('hotelId=-1', $byKey['competitor_order_page_visitor']['transform_rule']);
        self::assertStringContainsString('orderFillingNum', $byKey['competitor_order_page_visitor']['source_keys']);
        self::assertStringContainsString('orderSubmitNum / orderFillingNum', $byKey['deal_rate']['transform_rule']);
        self::assertStringContainsString('hotelId=-1', $byKey['competitor_deal_rate']['transform_rule']);
        self::assertStringContainsString('orderSubmitNum / orderFillingNum', $byKey['competitor_deal_rate']['transform_rule']);
        self::assertStringContainsString('hotelId=-1', $byKey['competitor_order_submit_user']['transform_rule']);
        self::assertStringContainsString('orderSubmitNum', $byKey['competitor_order_submit_user']['source_keys']);
        self::assertSame('business_weekly_overview', $byKey['weekly_self_list_exposure']['section']);
        self::assertSame('data.myHotel.totalListExposure', $byKey['weekly_self_list_exposure']['json_path']);
        self::assertSame('getLastWeekReportV1', $byKey['last_week_book_sales']['source_interface']);
        self::assertSame('data.lastWeekBookSales', $byKey['last_week_book_sales']['json_path']);
        self::assertSame('getUserBehaviorV1 / getUserBehavorV1', $byKey['last_week_comment_score']['source_interface']);
        self::assertSame('data.lossOrderVo.ordernum', $byKey['flow_lost_order_num']['json_path']);
        self::assertSame('getHotRoomsV1', $byKey['top_hot_room']['source_interface']);
        self::assertSame('count(data[])', $byKey['hot_words_count']['json_path']);
        self::assertSame('data[0:10]', $byKey['top_hot_words']['json_path']);
        self::assertSame('data[0:10]', $byKey['top_hot_hotels']['json_path']);
        self::assertSame('queryUserSex', $byKey['user_sex']['source_interface']);
        self::assertSame('data[].name', $byKey['user_sex']['json_path']);
        self::assertSame('queryUserPriceInfo', $byKey['price_sensitivity']['source_interface']);
        self::assertSame('data.titleList[]', $byKey['price_sensitivity']['json_path']);
        self::assertSame('queryUserSource', $byKey['source_city']['source_interface']);
        self::assertSame('data.cities[].name', $byKey['source_city']['json_path']);
        self::assertSame('queryUserAge', $byKey['avg_user_age']['source_interface']);
        self::assertSame('data.avg', $byKey['avg_user_age']['json_path']);
        self::assertStringContainsString('queryUserFeatures', $byKey['user_age']['source_interface']);
        self::assertSame('queryUserFeatures / queryUserTravelTime', $byKey['travel_time']['source_interface']);
        self::assertSame('data[].traveltime / data.titleList[] / data[].name', $byKey['travel_time']['json_path']);
        self::assertSame('getOrderDistribution', $byKey['booking_hour']['source_interface']);
        self::assertSame('data.titleList[] / data[].name', $byKey['booking_hour']['json_path']);
        self::assertSame('queryUserStar', $byKey['hotel_star_preference']['source_interface']);
        self::assertSame('data.titleList[] / data[].name', $byKey['hotel_star_preference']['json_path']);
        self::assertSame('queryUserFeatures', $byKey['price_band']['source_interface']);
        self::assertSame('data[].consumer', $byKey['price_band']['json_path']);
        self::assertSame('queryUserPrice', $byKey['consumption_power']['source_interface']);
        self::assertSame('data.titleList[] / data[].name', $byKey['consumption_power']['json_path']);
        self::assertSame('queryUserBookingDays', $byKey['avg_booking_days']['source_interface']);
        self::assertSame('data.avg', $byKey['avg_booking_days']['json_path']);
        self::assertSame('queryUserBookingDays', $byKey['booking_days']['source_interface']);
        self::assertSame('data.titleList[]', $byKey['booking_days']['json_path']);
        self::assertSame('queryUserStayDays', $byKey['avg_stay_days']['source_interface']);
        self::assertSame('data.avg', $byKey['avg_stay_days']['json_path']);
        self::assertSame('queryUserStayDays', $byKey['stay_days']['source_interface']);
        self::assertSame('data.titleList[]', $byKey['stay_days']['json_path']);
        self::assertSame('queryOrderType', $byKey['booking_method']['source_interface']);
        self::assertSame('data.titleList[] / data[].name', $byKey['booking_method']['json_path']);
        self::assertSame('queryUserOrders', $byKey['order_hotel_count']['source_interface']);
        self::assertSame('data.titleList[] / data[].name', $byKey['order_hotel_count']['json_path']);
        self::assertSame('queryUserPoint', $byKey['order_preference']['source_interface']);
        self::assertSame('data.titleList[]', $byKey['order_preference']['json_path']);
        self::assertSame('queryUserPoint', $byKey['preference_frequency']['source_interface']);
        self::assertSame('data.userColumnBos[].titleList[]', $byKey['preference_frequency']['json_path']);
        self::assertSame('percent', $byKey['distribution_share']['value_type']);
        self::assertStringContainsString('data.valueList[]', $byKey['distribution_share']['json_path']);
        self::assertSame('getCommentsScoreV2 / getCommentNumV2', $byKey['ctrip_comment_count']['source_interface']);
        self::assertSame('data.ctripCommentCount / ctripCount.commentCount', $byKey['ctrip_comment_count']['json_path']);
        self::assertSame('getCommentsScoreV2 / getCommentNumV2', $byKey['qunar_comment_count']['source_interface']);
        self::assertSame('data.qunarCommentCount / qunarCount.commentCount', $byKey['qunar_comment_count']['json_path']);
        self::assertSame('getCommentsScoreV2 / getCommentNumV2', $byKey['elong_comment_count']['source_interface']);
        self::assertSame('data.elongCommentCount / elongCount.commentCount', $byKey['elong_comment_count']['json_path']);
        self::assertSame('getDayReportServerQuantity / getCommentsScoreV2', $byKey['ctrip_rating']['source_interface']);
        self::assertSame('data.ctripRatingall', $byKey['ctrip_rating']['json_path']);
        self::assertSame('getCommentsScoreV2', $byKey['qunar_rating']['source_interface']);
        self::assertSame('data.qunarRatingall', $byKey['qunar_rating']['json_path']);
        self::assertSame('getCommentsScoreV2', $byKey['elong_rating']['source_interface']);
        self::assertSame('traffic_report', $byKey['elong_rating']['section']);
        self::assertSame('data.elongRatingall', $byKey['elong_rating']['json_path']);
        self::assertArrayNotHasKey('ctrip_comment_id', $byKey);
        self::assertArrayNotHasKey('qunar_comment_id', $byKey);
        self::assertArrayNotHasKey('elong_comment_id', $byKey);
        self::assertSame('getCommentNumV2', $byKey['zx_comment_count']['source_interface']);
        self::assertSame('zxCount.commentCount', $byKey['zx_comment_count']['json_path']);
        self::assertSame('getCommentsScoreV2 / getCommentNumV2', $byKey['comment_response_rate']['source_interface']);
        self::assertSame('data.responseRate / {channel}Count.responseRate', $byKey['comment_response_rate']['json_path']);
        self::assertSame('getCommentNumV2', $byKey['comment_unreply_count']['source_interface']);
        self::assertSame('{channel}Count.unReplyCount', $byKey['comment_unreply_count']['json_path']);
        self::assertStringContainsString('restapi/soa2/26353/getCommentNumV2', $byKey['comment_unreply_count']['request_url']);
        self::assertStringContainsString('ctripCount.unReplyCount', $byKey['comment_unreply_count']['source_keys']);
        self::assertSame('getCommentNumV2', $byKey['comment_good_rate']['source_interface']);
        self::assertSame('{channel}Count.goodRate', $byKey['comment_good_rate']['json_path']);
        self::assertStringContainsString('restapi/soa2/26353/getCommentNumV2', $byKey['comment_good_rate']['request_url']);
        self::assertStringContainsString('ctripCount.goodRate', $byKey['comment_good_rate']['source_keys']);
        self::assertSame('comment_review', $byKey['review_environment_score']['section']);
        self::assertSame('getHotelRating', $byKey['review_environment_score']['source_interface']);
        self::assertSame('confirmed', $byKey['review_environment_score']['status']);
        self::assertSame('ratingInfo.ratingLocation / ctripRatings.ratingLocation / elongRatings.ratingLocation / ratingInfo.scoreInfo.subScores[type=ratingLocation].scoreSimple / elongRatings.scoreInfo.subScores[type=ratingLocation].score', $byKey['review_environment_score']['json_path']);
        self::assertStringContainsString('ratingLocation', $byKey['review_environment_score']['source_keys']);
        self::assertSame('comment_review', $byKey['review_facility_score']['section']);
        self::assertSame('getHotelRating', $byKey['review_facility_score']['source_interface']);
        self::assertSame('confirmed', $byKey['review_facility_score']['status']);
        self::assertSame('ratingInfo.ratingFacility / ctripRatings.ratingFacility / elongRatings.ratingFacility / ratingInfo.scoreInfo.subScores[type=ratingFacility].scoreSimple / elongRatings.scoreInfo.subScores[type=ratingFacility].score', $byKey['review_facility_score']['json_path']);
        self::assertStringContainsString('ratingFacility', $byKey['review_facility_score']['source_keys']);
        self::assertSame('comment_review', $byKey['review_service_score']['section']);
        self::assertSame('getHotelRating', $byKey['review_service_score']['source_interface']);
        self::assertSame('confirmed', $byKey['review_service_score']['status']);
        self::assertSame('ratingInfo.ratingService / ctripRatings.ratingService / elongRatings.ratingService / ratingInfo.scoreInfo.subScores[type=ratingService].scoreSimple / elongRatings.scoreInfo.subScores[type=ratingService].score', $byKey['review_service_score']['json_path']);
        self::assertStringContainsString('ratingService', $byKey['review_service_score']['source_keys']);
        self::assertSame('comment_review', $byKey['review_cleanliness_score']['section']);
        self::assertSame('getHotelRating', $byKey['review_cleanliness_score']['source_interface']);
        self::assertSame('confirmed', $byKey['review_cleanliness_score']['status']);
        self::assertSame('ratingInfo.ratingRoom / ctripRatings.ratingRoom / elongRatings.ratingRoom / ratingInfo.scoreInfo.subScores[type=ratingRoom].scoreSimple / elongRatings.scoreInfo.subScores[type=ratingRoom].score', $byKey['review_cleanliness_score']['json_path']);
        self::assertStringContainsString('ratingRoom', $byKey['review_cleanliness_score']['source_keys']);
        self::assertSame('confirmed', $byKey['review_photo_count']['status']);
        self::assertSame('data.hasPicCount / {channel}Count.hasPicCount', $byKey['review_photo_count']['json_path']);
        self::assertStringContainsString('restapi/soa2/26353/getCommentNumV2', $byKey['review_photo_count']['request_url']);
        self::assertStringContainsString('ctripCount.hasPicCount', $byKey['review_photo_count']['source_keys']);
        self::assertStringContainsString('raw_data.metrics.review_photo_count', $byKey['review_photo_count']['storage_field']);
        self::assertSame('confirmed', $byKey['review_photo_rate']['status']);
        self::assertSame('derived:data.hasPicCount / data.commentCount / {channel}Count.hasPicCount / {channel}Count.commentCount', $byKey['review_photo_rate']['json_path']);
        self::assertStringContainsString('hasPicCount / commentCount * 100', $byKey['review_photo_rate']['notes']);
        self::assertStringContainsString('raw_data.metrics.review_photo_rate', $byKey['review_photo_rate']['storage_field']);
        self::assertSame('im_board', $byKey['five_min_reply_rate']['section']);
        self::assertSame('getImIndex', $byKey['five_min_reply_rate']['source_interface']);
        self::assertSame('data.replyRate5m / data.fiveMinReplyRate / data.replyRate', $byKey['five_min_reply_rate']['json_path']);
        self::assertStringContainsString('raw_data.metrics.five_min_reply_rate', $byKey['five_min_reply_rate']['storage_field']);
        self::assertSame('im_board', $byKey['manual_reply_rate']['section']);
        self::assertSame('getImIndex', $byKey['manual_reply_rate']['source_interface']);
        self::assertSame('data.manualReplyRate / data.humanReplyRate / data.manualreplyrate5m', $byKey['manual_reply_rate']['json_path']);
        self::assertSame('im_board', $byKey['robot_resolution_rate']['section']);
        self::assertSame('getImIndex', $byKey['robot_resolution_rate']['source_interface']);
        self::assertSame('data.robotResolutionRate / data.robotResolveRate / data.aisolverate', $byKey['robot_resolution_rate']['json_path']);
        self::assertSame('im_board', $byKey['im_rank']['section']);
        self::assertSame('getImIndex', $byKey['im_rank']['source_interface']);
        self::assertStringContainsString('raw_data.rank_metrics.im_rank', $byKey['im_rank']['storage_field']);
        self::assertSame('im_board', $byKey['session_count']['section']);
        self::assertSame('getImDateDistribute / getImSessionDistribute / getImOrderConversionDetail', $byKey['session_count']['source_interface']);
        self::assertStringContainsString('data[].sessionCount', $byKey['session_count']['json_path']);
        self::assertSame('im_board', $byKey['manual_session_count']['section']);
        self::assertSame('getImSessionDistribute / getImOrderConversionDetail', $byKey['manual_session_count']['source_interface']);
        self::assertSame('im_board', $byKey['robot_session_count']['section']);
        self::assertSame('getImSessionDistribute', $byKey['robot_session_count']['source_interface']);
        self::assertSame('im_board', $byKey['im_order_conversion_rate']['section']);
        self::assertSame('getImOrderConversionRateByDay / getImOrderConversionDetail', $byKey['im_order_conversion_rate']['source_interface']);
        self::assertStringContainsString('raw_data.metrics.im_order_conversion_rate', $byKey['im_order_conversion_rate']['storage_field']);
        self::assertSame('im_board', $this->invokeNonPublic($controller, 'classifyCtripProfileCaptureSectionByPageUrl', [
            'https://ebooking.ctrip.com/datacenter/inland/userbehavior/user?goto=im',
            '',
        ]));
    }

    public function testCtripProfileCaptureModuleDefaultsRefreshLegacySystemLabelsOnly(): void
    {
        $controller = $this->controller();
        $modules = $this->invokeNonPublic($controller, 'defaultCtripProfileCaptureModules');

        $modules['competitor_overview']['label'] = '竞争圈动态-概览';
        [$merged, $changed] = $this->invokeNonPublic($controller, 'mergeDefaultCtripProfileCaptureModules', [$modules]);
        self::assertTrue($changed);
        self::assertSame('竞争圈动态-竞争圈概览', $merged['competitor_overview']['label']);

        $modules['competitor_overview']['label'] = '自定义竞争圈概览';
        [$merged, $changed] = $this->invokeNonPublic($controller, 'mergeDefaultCtripProfileCaptureModules', [$modules]);
        self::assertFalse($changed);
        self::assertSame('自定义竞争圈概览', $merged['competitor_overview']['label']);
    }

    public function testCtripProfileFieldSimpleEvidenceCreatesFieldConfig(): void
    {
        $controller = $this->controller();

        $payload = [
            'page_url' => 'https://ebooking.ctrip.com/datacenter/inland/businessreport/flowdata?microJump=true',
            'request_url' => 'https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/queryFlowTransforNewV1?hostType=Ebooking',
            'json' => 'response[hotelId=6866634].detailExposure',
            'target_value' => 'detailExposure',
            'value_meaning' => '详情页访客量',
            'section' => 'traffic_report',
        ];

        $prepared = $this->invokeNonPublic($controller, 'prepareCtripProfileFieldSaveData', [$payload, [], true]);

        self::assertTrue($this->invokeNonPublic($controller, 'hasRequiredCtripProfileFieldEvidence', [$prepared]));
        self::assertSame('detailexposure', $prepared['field_key']);
        self::assertSame('详情页访客量', $prepared['field_name']);
        self::assertSame('detailExposure', $prepared['source_keys']);
        self::assertSame('needs_parser', $prepared['status']);

        $normalized = $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [$prepared]);

        self::assertSame($payload['page_url'], $normalized['page_url']);
        self::assertSame($payload['request_url'], $normalized['request_url']);
        self::assertSame($payload['json'], $normalized['json_path']);
        self::assertSame('detailExposure', $normalized['target_value']);
        self::assertSame('详情页访客量', $normalized['value_meaning']);
        self::assertSame('traffic_report', $normalized['section']);
        self::assertSame('needs_parser', $normalized['status']);
    }

    public function testCtripProfileCaptureFieldSectionIsClassifiedByPageUrl(): void
    {
        $controller = $this->controller();

        $outlineField = $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
            'id' => 'profile_field_service_score_rank',
            'field_key' => 'service_score_rank',
            'field_name' => 'Service score rank',
            'section' => 'quality_psi',
            'page_url' => 'https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true',
            'enabled' => true,
        ]]);
        self::assertSame('business_overview', $outlineField['section']);

        $weeklyField = $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
            'id' => 'profile_field_weekly_order_page_visitor',
            'field_key' => 'weekly_order_page_visitor',
            'field_name' => 'Weekly order page visitor',
            'section' => 'traffic_report',
            'page_url' => 'https://ebooking.ctrip.com/datacenter/inland/businessreport/weekReport?microJump=true',
            'enabled' => true,
        ]]);
        self::assertSame('business_weekly_overview', $weeklyField['section']);

        $salesField = $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
            'id' => 'profile_field_order_amount',
            'field_key' => 'order_amount',
            'field_name' => 'Order amount',
            'section' => 'business_overview',
            'page_url' => 'https://ebooking.ctrip.com/datacenter/inland/businessreport/beneficialdata?microJump=true',
            'enabled' => true,
        ]]);
        self::assertSame('sales_report', $salesField['section']);

        $trafficField = $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
            'id' => 'profile_field_detail_visitor',
            'field_key' => 'detail_visitor',
            'field_name' => 'Detail visitor',
            'section' => 'business_overview',
            'page_url' => 'https://ebooking.ctrip.com/datacenter/inland/businessreport/flowdata?microJump=true',
            'enabled' => true,
        ]]);
        self::assertSame('traffic_report', $trafficField['section']);

        $commentField = $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
            'id' => 'profile_field_comment_rows',
            'field_key' => 'comment_rows',
            'field_name' => 'Comment rows',
            'section' => 'business_overview',
            'page_url' => 'https://ebooking.ctrip.com/comment/commentList?microJump=true',
            'enabled' => true,
        ]]);
        self::assertSame('comment_review', $commentField['section']);

        $userProfileField = $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
            'id' => 'profile_field_user_profile',
            'field_key' => 'user_profile',
            'field_name' => 'User profile',
            'section' => 'business_overview',
            'page_url' => 'https://ebooking.ctrip.com/ebkgrowth/datacenter/userbehavior/user?microJump=true',
            'enabled' => true,
        ]]);
        self::assertSame('user_profile', $userProfileField['section']);

        $psiField = $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
            'id' => 'profile_field_psi_score',
            'field_key' => 'psi_score',
            'field_name' => 'PSI score',
            'section' => 'business_overview',
            'page_url' => 'https://ebooking.ctrip.com/toolcenter/psi/index?fromType=menu&microJump=true',
            'enabled' => true,
        ]]);
        self::assertSame('quality_psi', $psiField['section']);

        $unknownField = $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
            'id' => 'profile_field_unknown',
            'field_key' => 'unknown',
            'field_name' => 'Unknown',
            'section' => 'quality_psi',
            'page_url' => 'https://ebooking.ctrip.com/example/unknown',
            'enabled' => true,
        ]]);
        self::assertSame('quality_psi', $unknownField['section']);
    }

    public function testCtripProfileDeletedDefaultModuleRestoresFromDuplicatePageUrl(): void
    {
        $controller = $this->controller();

        $defaultUrl = 'https://ebooking.ctrip.com/ebkgrowth/datacenter/competition/competitionprofile?microJump=true';
        $modules = [
            'competitor_overview' => $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureModule', [[
                'id' => 'competitor_overview',
                'label' => '竞争圈动态-竞争圈概览',
                'page_url' => $defaultUrl,
                'primary_category' => '竞争力数据',
                'enabled' => false,
                'system' => true,
                'sort_order' => 60,
                'deleted_at' => '2026-06-04 19:00:14',
            ]]),
            'module_2de12be6' => $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureModule', [[
                'id' => 'module_2de12be6',
                'label' => '竞争圈概览',
                'page_url' => $defaultUrl,
                'primary_category' => '竞争力数据',
                'enabled' => true,
                'sort_order' => 5,
            ]]),
        ];

        [$merged, $changed] = $this->invokeNonPublic($controller, 'mergeDefaultCtripProfileCaptureModules', [$modules]);

        self::assertTrue($changed);
        self::assertSame('', $merged['competitor_overview']['deleted_at']);
        self::assertTrue($merged['competitor_overview']['enabled']);
        self::assertSame('竞争圈动态-竞争圈概览', $merged['competitor_overview']['label']);
        self::assertNotSame('', $merged['module_2de12be6']['deleted_at']);
        self::assertFalse($merged['module_2de12be6']['enabled']);
    }

    public function testCtripProfileDeletedAndDisabledFieldsStayOutOfCaptureScope(): void
    {
        $controller = $this->controller();

        $fields = [
            'profile_field_order_count' => $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
                'id' => 'profile_field_order_count',
                'field_key' => 'order_count',
                'field_name' => 'Order Count',
                'enabled' => true,
                'status' => 'confirmed',
            ]]),
            'profile_field_order_amount' => $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
                'id' => 'profile_field_order_amount',
                'field_key' => 'order_amount',
                'field_name' => 'Order Amount',
                'enabled' => false,
                'status' => 'confirmed',
            ]]),
            'profile_field_room_nights' => $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
                'id' => 'profile_field_room_nights',
                'field_key' => 'room_nights',
                'field_name' => 'Room Nights',
                'enabled' => true,
                'status' => 'confirmed',
                'deleted_at' => '2026-06-04 17:30:00',
                'deleted_by' => 7,
            ]]),
        ];

        $active = $this->invokeNonPublic($controller, 'activeCtripProfileCaptureFields', [$fields]);
        self::assertArrayHasKey('profile_field_order_count', $active);
        self::assertArrayHasKey('profile_field_order_amount', $active);
        self::assertArrayNotHasKey('profile_field_room_nights', $active);
        self::assertFalse($fields['profile_field_room_nights']['enabled']);
        self::assertSame('paused', $fields['profile_field_room_nights']['status']);

        $enabledMap = $this->invokeNonPublic($controller, 'ctripProfileEnabledFieldKeyMap', [$fields]);
        self::assertArrayHasKey('order_count', $enabledMap);
        self::assertArrayNotHasKey('order_amount', $enabledMap);
        self::assertArrayNotHasKey('room_nights', $enabledMap);

        $payload = $this->invokeNonPublic($controller, 'buildCtripProfileFieldConfigPayload', [$fields]);
        self::assertSame(['order_count'], $payload['allowed_field_keys']);
        self::assertSame(['business_overview'], $payload['allowed_sections']);
        self::assertCount(1, $payload['fields']);
        self::assertSame('order_count', $payload['fields'][0]['field_key']);

        self::assertSame(
            ['business_overview'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileCaptureSectionsForRun', [['sections' => 'default'], $payload, false])
        );
        self::assertSame(
            ['business_overview'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileCaptureSectionsForRun', [['sections' => 'business_overview,traffic_report'], $payload, false])
        );
        self::assertSame(
            [],
            $this->invokeNonPublic($controller, 'resolveCtripProfileCaptureSectionsForRun', [['sections' => 'traffic_report'], $payload, false])
        );
    }

    public function testCtripProfileCaptureSectionAliasesIncludeCommentReview(): void
    {
        $controller = $this->controller();
        $payload = [
            'allowed_sections' => ['business_overview', 'comment_review'],
            'allowed_field_keys' => ['order_count', 'comment_unreply_count'],
            'fields' => [],
        ];

        self::assertSame(
            ['comment_review'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileCaptureSectionsForRun', [['sections' => 'comment'], $payload, false])
        );
        self::assertSame(
            ['comment_review'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileCaptureSectionsForRun', [['sections' => 'review'], $payload, false])
        );
        self::assertSame(
            ['business_overview', 'comment_review'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileCaptureSectionsForRun', [['sections' => 'business,comment'], $payload, false])
        );
    }

    public function testCtripProfileFieldSampleVerificationStatusIsNormalized(): void
    {
        $controller = $this->controller();

        $matched = $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
            'id' => 'profile_field_order_count',
            'field_key' => 'order_count',
            'field_name' => 'Order Count',
            'status' => 'confirmed',
            'sample_verification_status' => 'matched',
            'sample_verified_at' => '2026-06-03 23:48:00',
            'sample_verified_by' => 7,
            'verified_sample_value' => '18',
            'verified_sample_source_key' => 'orderQuantity',
            'verified_sample_source_path' => 'data.orderQuantity',
            'verified_sample_endpoint_id' => 'fetchCapacityOverviewV4',
            'verified_sample_data_date' => '2026-06-03',
            'verified_sample_hotel_name' => '门店 西安天诚',
            'verified_sample_captured_at' => '2026-06-04 13:31:26',
        ]]);
        self::assertSame('matched', $matched['sample_verification_status']);
        self::assertSame('2026-06-03 23:48:00', $matched['sample_verified_at']);
        self::assertSame(7, $matched['sample_verified_by']);
        self::assertSame('18', $matched['verified_sample_value']);
        self::assertSame('orderQuantity', $matched['verified_sample_source_key']);
        self::assertSame('data.orderQuantity', $matched['verified_sample_source_path']);
        self::assertSame('fetchCapacityOverviewV4', $matched['verified_sample_endpoint_id']);
        self::assertSame('2026-06-03', $matched['verified_sample_data_date']);
        self::assertSame('门店 西安天诚', $matched['verified_sample_hotel_name']);
        self::assertSame('2026-06-04 13:31:26', $matched['verified_sample_captured_at']);

        $mismatched = $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
            'id' => 'profile_field_order_amount',
            'field_key' => 'order_amount',
            'field_name' => 'Order Amount',
            'status' => 'needs_parser',
            'sampleVerificationStatus' => 'mismatch',
            'sampleVerifiedAt' => '2026-06-03 23:49:00',
            'sampleVerifiedBy' => 8,
        ]]);
        self::assertSame('mismatched', $mismatched['sample_verification_status']);
        self::assertSame('2026-06-03 23:49:00', $mismatched['sample_verified_at']);
        self::assertSame(8, $mismatched['sample_verified_by']);

        $invalid = $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
            'id' => 'profile_field_room_nights',
            'field_key' => 'room_nights',
            'field_name' => 'Room Nights',
            'status' => 'pending',
            'sample_verification_status' => 'unknown',
            'sample_verified_at' => '2026-06-03 23:50:00',
            'sample_verified_by' => 9,
            'verified_sample_value' => '3',
            'verified_sample_source_key' => 'quantity',
        ]]);
        self::assertSame('unverified', $invalid['sample_verification_status']);
        self::assertSame('', $invalid['sample_verified_at']);
        self::assertNull($invalid['sample_verified_by']);
        self::assertSame('', $invalid['verified_sample_value']);
        self::assertSame('', $invalid['verified_sample_source_key']);

        $summary = $this->invokeNonPublic($controller, 'summarizeCtripProfileCaptureFields', [[$matched, $mismatched, $invalid]]);
        self::assertSame(1, $summary['sample_verification_counts']['matched']);
        self::assertSame(1, $summary['sample_verification_counts']['mismatched']);
        self::assertSame(1, $summary['sample_verification_counts']['unverified']);
        self::assertSame(1, $summary['confirmed_field_count']);
        self::assertSame(2, $summary['doubtful_field_count']);
    }

    public function testCtripProfileFieldSampleVerificationStatusControlsFieldStatus(): void
    {
        $controller = $this->controller();

        self::assertSame(
            'confirmed',
            $this->invokeNonPublic($controller, 'statusForCtripProfileFieldSampleVerification', ['matched', 'needs_parser'])
        );
        self::assertSame(
            'needs_parser',
            $this->invokeNonPublic($controller, 'statusForCtripProfileFieldSampleVerification', ['mismatched', 'confirmed'])
        );
        self::assertSame(
            'paused',
            $this->invokeNonPublic($controller, 'statusForCtripProfileFieldSampleVerification', ['unverified', 'paused'])
        );
        self::assertSame(
            'pending',
            $this->invokeNonPublic($controller, 'statusForCtripProfileFieldSampleVerification', ['unverified', 'invalid_status'])
        );
    }

    public function testCtripProfileTrafficFunnelSamplesResolveConcreteValues(): void
    {
        $controller = $this->controller();
        $raw = [
            'response' => [
                [
                    'date' => '2026-06-01',
                    'hotelId' => 134396668,
                    'listExposure' => 1297,
                    'detailExposure' => 231,
                    'flowRate' => 17.81,
                    'orderFillingNum' => 9,
                    'orderSubmitNum' => 7,
                ],
                [
                    'date' => '2026-06-01',
                    'hotelId' => -1,
                    'listExposure' => 799,
                    'detailExposure' => 172,
                    'flowRate' => 21.5,
                    'orderFillingNum' => 10,
                    'orderSubmitNum' => 6,
                ],
            ],
        ];

        self::assertSame(
            [1297.0, 'listExposure', 'raw_data.response.[0]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['page_views', [], $raw])
        );
        self::assertSame(
            [799.0, 'listExposure', 'raw_data.response.[1]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['competitor_list_exposure', [], $raw])
        );
        self::assertSame(
            ['17.81', 'detailExposure / listExposure', 'raw_data.response.[0]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['flow_rate', [], $raw])
        );
        self::assertSame(
            ['3.90', 'orderFillingNum / detailExposure', 'raw_data.response.[0]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['order_fill_rate', [], $raw])
        );
        self::assertSame(
            ['77.78', 'orderSubmitNum / orderFillingNum', 'raw_data.response.[0]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['deal_rate', [], $raw])
        );
        self::assertSame(
            ['21.53', 'detailExposure / listExposure', 'raw_data.response.[1]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['competitor_flow_rate', [], $raw])
        );
        self::assertSame(
            ['5.81', 'orderFillingNum / detailExposure', 'raw_data.response.[1]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['competitor_order_fill_rate', [], $raw])
        );
        self::assertSame(
            ['60.00', 'orderSubmitNum / orderFillingNum', 'raw_data.response.[1]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['competitor_deal_rate', [], $raw])
        );
        self::assertSame(
            [1297.0, 'listExposure', 'raw_data.response.[0]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['qunar_list_exposure', [], $raw])
        );
        self::assertSame(
            [799.0, 'listExposure', 'raw_data.response.[1]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['qunar_competitor_list_exposure', [], $raw])
        );
        self::assertSame(
            ['17.81', 'detailExposure / listExposure', 'raw_data.response.[0]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['qunar_flow_rate', [], $raw])
        );
        self::assertSame(
            ['21.53', 'detailExposure / listExposure', 'raw_data.response.[1]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['qunar_competitor_flow_rate', [], $raw])
        );
        self::assertSame(
            ['3.90', 'orderFillingNum / detailExposure', 'raw_data.response.[0]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['qunar_order_fill_rate', [], $raw])
        );
        self::assertSame(
            ['5.81', 'orderFillingNum / detailExposure', 'raw_data.response.[1]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['qunar_competitor_order_fill_rate', [], $raw])
        );
        self::assertSame(
            ['77.78', 'orderSubmitNum / orderFillingNum', 'raw_data.response.[0]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['qunar_deal_rate', [], $raw])
        );
        self::assertSame(
            ['60.00', 'orderSubmitNum / orderFillingNum', 'raw_data.response.[1]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['qunar_competitor_deal_rate', [], $raw])
        );

        $storedQunarCompetitorRow = [
            'id' => 99,
            'source' => 'qunar',
            'platform' => 'Qunar',
            'compare_type' => 'competitor',
            'list_exposure' => 799,
            'detail_exposure' => 172,
            'flow_rate' => 21.5,
            'order_filling_num' => 10,
            'order_submit_num' => 6,
            'dimension' => 'catalog:traffic_report:traffic_flow_transform:list_exposure+detail_visitor+flow_rate:0',
        ];
        self::assertSame(
            [799.0, 'listExposure', 'online_daily_data#99'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['qunar_competitor_list_exposure', $storedQunarCompetitorRow, []])
        );
        self::assertSame(
            ['21.53', 'detailExposure / listExposure', 'online_daily_data#99'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['qunar_competitor_flow_rate', $storedQunarCompetitorRow, []])
        );
    }

    public function testCtripProfileOnlineDailySamplesResolveLegacyFieldAliases(): void
    {
        $controller = $this->controller();
        $row = [
            'id' => 9419,
            'source' => 'ctrip',
            'platform' => 'Ctrip',
            'compare_type' => 'self',
            'data_value' => 34,
            'book_order_num' => 2,
        ];
        $raw = [
            'row' => [
                'raw_data' => [
                    'metrics' => [
                        'visitor_count' => 15,
                        'conversion_rate' => 100,
                    ],
                ],
            ],
        ];
        $rawMap = $this->invokeNonPublic($controller, 'flattenCtripProfileRawValues', [$raw]);

        $lastVisitorKeys = array_merge(
            ['last_visitor_total', 'lastVisitorTotal'],
            $this->invokeNonPublic($controller, 'onlineDailyDataSampleAliases', ['last_visitor_total'])
        );
        self::assertSame(
            [15, 'visitor_count', 'online_daily_data#9419'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileOnlineDailyFieldSample', ['last_visitor_total', $row, $raw, $rawMap, $lastVisitorKeys])
        );

        $closeRateKeys = array_merge(
            ['close_rate', 'closeRate'],
            $this->invokeNonPublic($controller, 'onlineDailyDataSampleAliases', ['close_rate'])
        );
        self::assertSame(
            [100, 'conversion_rate', 'online_daily_data#9419'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileOnlineDailyFieldSample', ['close_rate', $row, $raw, $rawMap, $closeRateKeys])
        );

        $rankRow = [
            'id' => 9977,
            'source' => 'ctrip',
            'platform' => 'Ctrip',
            'compare_type' => 'self',
            'data_type' => 'ranking',
            'dimension' => 'catalog:business_overview:business_hotel_seq:seq_rank:data',
        ];
        $rankRaw = [
            'row' => [
                'raw_data' => [
                    'facts' => [
                        [
                            'metric_key' => 'seq_rank',
                            'metric_label' => '实时排名',
                            'value' => 550,
                            'source_key' => 'rank',
                            'source_path' => 'data.rank',
                        ],
                        [
                            'metric_key' => 'seq_rank',
                            'metric_label' => '实时排名',
                            'value' => 0,
                            'source_key' => 'competitorRank',
                            'source_path' => 'data.competitorRank',
                        ],
                    ],
                    'metrics' => [
                        'seq_rank' => null,
                    ],
                    'rank_metrics' => [
                        'seq_rank' => null,
                    ],
                ],
            ],
        ];
        $rankRawMap = $this->invokeNonPublic($controller, 'flattenCtripProfileRawValues', [$rankRaw]);
        self::assertSame(
            [550, 'rank', 'data.rank'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileOnlineDailyFieldSample', ['seq_rank', $rankRow, $rankRaw, $rankRawMap, ['seq_rank', 'rank']])
        );
    }

    public function testCtripProfileSampleBucketUsesSectionForRepeatedMetricKey(): void
    {
        $controller = $this->controller();
        $scopes = [
            'order_amount' => [
                'business_overview:order_amount' => true,
                'sales_report:order_amount' => true,
            ],
            'flow_rate' => [
                'traffic_report:flow_rate' => true,
            ],
        ];

        self::assertFalse($this->invokeNonPublic($controller, 'shouldSkipCtripProfileOnlineDailySampleSection', [
            'ctrip_comment_count',
            'business_overview',
            'traffic_report',
            ['ctrip_comment_count' => 1],
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'shouldSkipCtripProfileOnlineDailySampleSection', [
            'order_amount',
            'business_overview',
            'sales_report',
            ['order_amount' => 2],
        ]));
        self::assertSame(
            'sales_report:order_amount',
            $this->invokeNonPublic($controller, 'ctripProfileSampleBucketKeyForRow', ['order_amount', 'sales_report', $scopes])
        );
        self::assertNull(
            $this->invokeNonPublic($controller, 'ctripProfileSampleBucketKeyForRow', ['order_amount', '', $scopes])
        );
        self::assertSame(
            'traffic_report:flow_rate',
            $this->invokeNonPublic($controller, 'ctripProfileSampleBucketKeyForRow', ['flow_rate', '', $scopes])
        );
    }

    public function testCtripProfileOnlineDailySampleSectionResolvesFromRawOrDimension(): void
    {
        $controller = $this->controller();

        self::assertSame(
            'traffic_report',
            $this->invokeNonPublic($controller, 'ctripProfileSampleSectionFromOnlineDailyRow', [
                ['dimension' => 'catalog:sales_report:manual_checkout:order_amount:self'],
                ['capture_section' => 'traffic_report'],
            ])
        );
        self::assertSame(
            'sales_report',
            $this->invokeNonPublic($controller, 'ctripProfileSampleSectionFromOnlineDailyRow', [
                ['dimension' => 'catalog:sales_report:manual_checkout:order_amount:self'],
                [],
            ])
        );
        self::assertSame(
            '',
            $this->invokeNonPublic($controller, 'ctripProfileSampleSectionFromOnlineDailyRow', [
                ['dimension' => ''],
                [],
            ])
        );
    }

    public function testCtripProfileTrafficFunnelSamplesIgnoreNonFunnelTrafficRows(): void
    {
        $controller = $this->controller();

        $visitorTitleRow = [
            'id' => 9289,
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'compare_type' => 'competitor',
            'hotel_id' => '6866634',
            'list_exposure' => 0,
            'detail_exposure' => 15,
            'flow_rate' => 0,
            'order_filling_num' => 0,
            'order_submit_num' => 0,
            'dimension' => 'catalog:business_overview:business_visitor_title:visitor_count+visitor_rank+competitor_avg_visitor:root',
        ];
        $flowTransformRow = [
            'id' => 9287,
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'compare_type' => 'self',
            'hotel_id' => '6866634',
            'list_exposure' => 258,
            'detail_exposure' => 24,
            'flow_rate' => 9.3,
            'order_filling_num' => 6,
            'order_submit_num' => 6,
            'dimension' => 'catalog:business_overview:business_flow_transform:date+list_exposure+detail_visitor:0',
        ];

        self::assertNull($this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['competitor_list_exposure', $visitorTitleRow, []]));
        self::assertSame(
            [258.0, 'listExposure', 'online_daily_data#9287'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['page_views', $flowTransformRow, []])
        );
    }

    public function testCtripProfileTrafficFunnelFieldsPreferOnlineDailySamples(): void
    {
        $controller = $this->controller();

        self::assertTrue($this->invokeNonPublic($controller, 'ctripProfilePrefersOnlineDailySamples', ['page_views']));
        self::assertTrue($this->invokeNonPublic($controller, 'ctripProfilePrefersOnlineDailySamples', ['flow_conversion_rate']));
        self::assertTrue($this->invokeNonPublic($controller, 'ctripProfilePrefersOnlineDailySamples', ['qunar_competitor_deal_rate']));
        self::assertFalse($this->invokeNonPublic($controller, 'ctripProfilePrefersOnlineDailySamples', ['order_amount']));
    }

    public function testCtripProfileTrafficScopeDoesNotTreatCompetitorRowsAsSelf(): void
    {
        $controller = $this->controller();

        self::assertFalse($this->invokeNonPublic($controller, 'ctripProfileTrafficRowMatchesScope', [[
            'compare_type' => 'competitor',
            'hotel_id' => '6866634',
        ], 'self']));
        self::assertFalse($this->invokeNonPublic($controller, 'ctripProfileTrafficRowMatchesScope', [[
            'compare_type' => '',
            'hotel_id' => '-1',
        ], 'self']));
        self::assertTrue($this->invokeNonPublic($controller, 'ctripProfileTrafficRowMatchesScope', [[
            'compare_type' => 'self',
            'hotel_id' => '6866634',
        ], 'self']));
        self::assertTrue($this->invokeNonPublic($controller, 'ctripProfileTrafficRowMatchesScope', [[
            'compare_type' => 'competitor',
            'hotel_id' => '6866634',
        ], 'competitor']));
        self::assertTrue($this->invokeNonPublic($controller, 'ctripProfileTrafficRowMatchesScope', [[
            'compare_type' => '',
            'hotel_id' => '-1',
        ], 'competitor']));
    }

    public function testCtripProfilePreferredOnlineDailySamplesDoNotFallbackToWrongScopeGenericValues(): void
    {
        $controller = $this->controller();
        $competitorRow = [
            'id' => 9289,
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'compare_type' => 'competitor',
            'hotel_id' => '6866634',
            'list_exposure' => 0,
        ];

        self::assertNull($this->invokeNonPublic($controller, 'resolveCtripProfileOnlineDailyFieldSample', [
            'page_views',
            $competitorRow,
            [],
            [],
            ['page_views', 'listExposure', 'list_exposure'],
        ]));
        self::assertSame(
            [0, 'list_exposure', 'online_daily_data#9289'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileOnlineDailyFieldSample', [
                'custom_metric',
                $competitorRow,
                [],
                [],
                ['list_exposure'],
            ])
        );
    }

    public function testCtripProfileTrafficRowSelectionDoesNotFallbackCompetitorAverageToSelf(): void
    {
        $controller = $this->controller();

        self::assertNull($this->invokeNonPublic($controller, 'selectCtripProfileTrafficRow', [[
            ['row' => ['hotelId' => '-1', 'listExposure' => 1463], 'path' => 'raw_data.row'],
        ], 'self']));
        self::assertSame(
            ['row' => ['listExposure' => 258], 'path' => 'raw_data.row'],
            $this->invokeNonPublic($controller, 'selectCtripProfileTrafficRow', [[
                ['row' => ['listExposure' => 258], 'path' => 'raw_data.row'],
            ], 'self'])
        );
    }

    public function testCtripProfileCaptureGateArgsDefaultToFieldCoverageThreshold(): void
    {
        $controller = $this->controller();

        $defaultArgs = $this->invokeNonPublic($controller, 'appendCtripCaptureGateArgs', [['node'], []]);

        self::assertContains('--min-field-coverage-rate=80', $defaultArgs);
        self::assertNotContains('--max-missing-fields=0', $defaultArgs);

        $customArgs = $this->invokeNonPublic($controller, 'appendCtripCaptureGateArgs', [['node'], [
            'minFieldCoverageRate' => '65.5',
            'maxMissingFields' => 4,
            'requireFieldCoverage' => true,
        ]]);

        self::assertContains('--min-field-coverage-rate=65.5', $customArgs);
        self::assertContains('--max-missing-fields=4', $customArgs);
        self::assertContains('--require-field-coverage', $customArgs);
    }

    public function testCtripProfileStandardRowsPreserveQunarTrafficSourceAndPlatform(): void
    {
        $controller = $this->controller();
        $payload = [
            'standard_rows' => [[
                'hotel_id' => '-1',
                'hotel_name' => '竞争圈平均',
                'source' => 'qunar',
                'platform' => 'Qunar',
                'data_date' => '2026-06-01',
                'data_type' => 'traffic',
                'capture_section' => 'traffic_report',
                'endpoint_id' => 'traffic_flow_transform',
                'dimension' => 'catalog:traffic_report:traffic_flow_transform:list_exposure+detail_visitor+flow_rate:1',
                'compare_type' => 'competitor',
                'list_exposure' => 799,
                'detail_exposure' => 172,
                'flow_rate' => 21.5,
                'order_filling_num' => 10,
                'order_submit_num' => 6,
                'raw_data' => [
                    'source' => 'ctrip_catalog_facts',
                    'metrics' => ['flow_rate' => 21.5],
                ],
            ]],
        ];

        $rows = $this->invokeNonPublic($controller, 'extractCtripStandardRows', [$payload, 7, '2026-06-01', '134396668', null, ['list_exposure', 'detail_visitor', 'flow_rate']]);

        self::assertCount(1, $rows);
        self::assertSame('qunar', $rows[0]['source']);
        self::assertSame('Qunar', $rows[0]['platform']);
        self::assertSame('competitor', $rows[0]['compare_type']);
        self::assertSame(799, $rows[0]['list_exposure']);
        self::assertSame(172, $rows[0]['detail_exposure']);
        self::assertSame(21.5, $rows[0]['flow_rate']);
        self::assertSame(10, $rows[0]['order_filling_num']);
        self::assertSame(6, $rows[0]['order_submit_num']);
    }
}

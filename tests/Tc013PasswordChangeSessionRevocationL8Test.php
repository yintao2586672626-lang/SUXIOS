<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Auth as AuthController;
use app\middleware\Auth as AuthMiddleware;
use app\model\SystemConfig;
use app\model\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use think\App;
use think\Request;
use think\Response;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;
use Throwable;

final class Tc013PasswordChangeSessionRevocationL8Test extends TestCase
{
    private const PRIMARY_USER_ID = 130131;
    private const OTHER_USER_ID = 130132;
    private const ADMIN_USER_ID = 130133;
    private const PRIMARY_OLD_PASSWORD = 'Tc013-Old!A7';
    private const PRIMARY_NEW_PASSWORD = 'Tc013-New!B8';
    private const OTHER_PASSWORD = 'Tc013-Other!C9';
    private const ADMIN_PASSWORD = 'Tc013-Admin!D0';
    private const FAILURE_TRIGGER = 'tc013_forced_password_save_failure';

    /** @var array<string, mixed> */
    private static array $originalDatabaseConfig = [];

    /** @var array<string, mixed> */
    private static array $originalCacheConfig = [];

    /** @var array<string, array{found: bool, value: mixed}> */
    private static array $originalSystemConfigValueCache = [];

    private static App $app;
    private static ReflectionProperty $systemConfigValueCacheProperty;
    private static string $databaseConnection = '';
    private static string $sqlitePath = '';
    private static string $cacheStore = '';
    private static string $cachePath = '';

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__));
        self::$app->initialize();

        self::$systemConfigValueCacheProperty = new ReflectionProperty(SystemConfig::class, 'valueCache');
        self::$systemConfigValueCacheProperty->setAccessible(true);
        self::$originalSystemConfigValueCache = self::$systemConfigValueCacheProperty->getValue();

        $nonce = getmypid() . '_' . bin2hex(random_bytes(5));
        self::$databaseConnection = 'tc013_' . $nonce;
        self::$cacheStore = 'tc013_' . $nonce;
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tc013_password_sessions_' . $nonce . '.sqlite';
        self::$cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tc013_password_sessions_cache_' . $nonce;

        self::$originalDatabaseConfig = Config::get('database');
        $database = self::$originalDatabaseConfig;
        $database['default'] = self::$databaseConnection;
        $database['connections'][self::$databaseConnection] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);

        self::$originalCacheConfig = Config::get('cache');
        $cache = self::$originalCacheConfig;
        $cache['default'] = self::$cacheStore;
        $cache['stores'][self::$cacheStore] = [
            'type' => 'File',
            'path' => self::$cachePath,
            'prefix' => 'tc013',
            'expire' => 0,
            'tag_prefix' => 'tag:',
            'serialize' => [],
        ];
        Config::set($cache, 'cache');
        app('cache')->forgetDriver(self::$cacheStore);

        Cache::clear();
        self::createSchema();
    }

    public static function tearDownAfterClass(): void
    {
        $cleanupErrors = [];

        try {
            Cache::clear();
            app('cache')->forgetDriver(self::$cacheStore);
        } catch (Throwable $e) {
            $cleanupErrors[] = 'cache cleanup: ' . $e->getMessage();
        }

        if (self::$originalCacheConfig !== []) {
            Config::set(self::$originalCacheConfig, 'cache');
        }

        try {
            Db::connect()->close();
        } catch (Throwable $e) {
            $cleanupErrors[] = 'SQLite close: ' . $e->getMessage();
        }

        if (self::$originalDatabaseConfig !== []) {
            Config::set(self::$originalDatabaseConfig, 'database');
            Db::connect(null, true);
        }

        self::$systemConfigValueCacheProperty->setValue(null, self::$originalSystemConfigValueCache);

        if (is_file(self::$sqlitePath) && !unlink(self::$sqlitePath)) {
            $cleanupErrors[] = 'SQLite fixture still exists: ' . self::$sqlitePath;
        }
        try {
            self::removeDirectory(self::$cachePath);
        } catch (Throwable $e) {
            $cleanupErrors[] = 'cache directory cleanup: ' . $e->getMessage();
        }

        if ($cleanupErrors !== []) {
            throw new RuntimeException(implode('; ', $cleanupErrors));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        Db::execute('DROP TRIGGER IF EXISTS ' . self::FAILURE_TRIGGER);
        Db::name('operation_logs')->delete(true);
        Db::name('system_config')->delete(true);
        Db::name('users')->delete(true);
        Cache::clear();
        self::$systemConfigValueCacheProperty->setValue(null, []);

        Db::name('system_config')->insertAll([
            ['config_key' => SystemConfig::KEY_PASSWORD_MIN_LENGTH, 'config_value' => '6'],
            ['config_key' => SystemConfig::KEY_PASSWORD_REQUIRE_SPECIAL, 'config_value' => '0'],
        ]);
        Db::name('users')->insertAll([
            $this->userRow(self::PRIMARY_USER_ID, 'tc013_primary', self::PRIMARY_OLD_PASSWORD),
            $this->userRow(self::OTHER_USER_ID, 'tc013_other', self::OTHER_PASSWORD),
            $this->userRow(self::ADMIN_USER_ID, 'tc013_admin', self::ADMIN_PASSWORD),
        ]);
    }

    protected function tearDown(): void
    {
        try {
            Db::execute('DROP TRIGGER IF EXISTS ' . self::FAILURE_TRIGGER);
            Db::name('operation_logs')->delete(true);
            Db::name('system_config')->delete(true);
            Db::name('users')->delete(true);
            Cache::clear();
            self::$systemConfigValueCacheProperty->setValue(null, []);
        } finally {
            parent::tearDown();
        }
    }

    /**
     * @param array{actor_scope: string, data_completeness: string, freshness: string, upstream_state: string} $factors
     */
    #[DataProvider('l8VariantProvider')]
    public function testTc013SuccessfulPasswordChangeRevokesEveryPreChangeSession(
        string $caseId,
        array $factors
    ): void {
        $message = $caseId . ' factors=' . json_encode($factors, JSON_UNESCAPED_SLASHES);
        $tokenPrefix = strtolower(str_replace('-', '', $caseId));
        $primaryOldTokens = [
            $tokenPrefix . '_primary_prechange_a',
            $tokenPrefix . '_primary_prechange_b',
        ];
        $otherOldToken = $tokenPrefix . '_other_prechange';
        $freshToken = $tokenPrefix . '_primary_postchange';

        foreach ($primaryOldTokens as $token) {
            $this->seedToken($token, self::PRIMARY_USER_ID);
        }
        $this->seedToken($otherOldToken, self::OTHER_USER_ID);

        $primaryActor = $this->assertTokenStatus(
            $primaryOldTokens[0],
            200,
            $message . ' first pre-change session was not established'
        );
        $this->assertTokenStatus(
            $primaryOldTokens[1],
            200,
            $message . ' second pre-change session was not established'
        );
        $actor = $factors['actor_scope'] === 'authorized'
            ? $primaryActor
            : $this->assertTokenStatus($otherOldToken, 200, $message . ' other-user token was not established');

        $this->configurePasswordSaveOutcome($factors['upstream_state']);
        $post = [
            // The production endpoint is self-scoped. This ignored target makes
            // the other-token variants an explicit cross-user attempt.
            'target_user_id' => self::PRIMARY_USER_ID,
            'old_password' => self::PRIMARY_OLD_PASSWORD,
        ];
        if ($factors['data_completeness'] === 'complete') {
            $post['new_password'] = self::PRIMARY_NEW_PASSWORD;
        }

        [$response, $error] = $this->attemptPasswordChange($actor, $post);
        $persistenceReached = $factors['actor_scope'] === 'authorized'
            && $factors['data_completeness'] === 'complete';
        $passwordChanged = $persistenceReached && $factors['upstream_state'] === 'success';

        if ($passwordChanged) {
            self::assertNull($error, $message . ' successful save raised an exception');
            self::assertInstanceOf(Response::class, $response, $message);
            self::assertSame(200, $response->getCode(), $message . ' password change did not succeed');
            self::assertSame(1, Db::name('operation_logs')->where('action', 'change_password')->count(), $message);
        } elseif ($persistenceReached) {
            self::assertNotNull($error, $message . ' forced password save failure was not surfaced');
            self::assertStringContainsString(self::FAILURE_TRIGGER, $error->getMessage(), $message);
            self::assertNull($response, $message . ' persistence exception unexpectedly produced a success response');
            self::assertSame(0, Db::name('operation_logs')->count(), $message . ' failed save wrote a success log');
        } else {
            self::assertNull($error, $message . ' validation/scope rejection raised an unexpected exception');
            self::assertInstanceOf(Response::class, $response, $message);
            self::assertNotSame(200, $response->getCode(), $message . ' invalid or other-user request was accepted');
            self::assertSame(0, Db::name('operation_logs')->count(), $message . ' rejected request wrote a success log');
        }

        $this->assertPasswordPersistence($passwordChanged, $message);

        // A newly issued session is represented by the exact token cache shape
        // consumed by the production middleware, then authenticated through it.
        $this->seedToken($freshToken, self::PRIMARY_USER_ID);
        $selectedToken = $factors['freshness'] === 'fresh' ? $freshToken : $primaryOldTokens[0];
        $selectedExpectedStatus = $passwordChanged && $factors['freshness'] === 'stale' ? 401 : 200;
        $this->assertTokenStatus(
            $selectedToken,
            $selectedExpectedStatus,
            $message . ' selected ' . $factors['freshness'] . ' session had the wrong authentication state'
        );

        if ($passwordChanged) {
            foreach ($primaryOldTokens as $oldToken) {
                $this->assertTokenStatus(
                    $oldToken,
                    401,
                    $message . ' pre-change token remained authenticated after the password save'
                );
            }
        }

        if ($factors['actor_scope'] === 'restricted') {
            $this->assertTokenStatus(
                $otherOldToken,
                200,
                $message . ' rejected cross-user attempt revoked the unrelated actor session'
            );
        }
    }

    public function testLegacyTokenIsUpgradedWithoutSurvivingAPasswordChange(): void
    {
        $legacyActiveToken = 'tc013_legacy_active';
        $this->seedLegacyToken($legacyActiveToken, self::PRIMARY_USER_ID);

        $this->assertTokenStatus(
            $legacyActiveToken,
            200,
            'A valid pre-upgrade token should be migrated instead of forcing a logout'
        );

        $primaryUser = User::find(self::PRIMARY_USER_ID);
        self::assertInstanceOf(User::class, $primaryUser);
        $migratedToken = Cache::get('token_' . $legacyActiveToken);
        self::assertIsArray($migratedToken);
        self::assertSame($primaryUser->authSessionVersion(), $migratedToken['auth_version'] ?? null);

        $legacyPreChangeToken = 'tc013_legacy_before_password_change';
        $this->seedLegacyToken($legacyPreChangeToken, self::PRIMARY_USER_ID);
        [$response, $error] = $this->attemptPasswordChange($primaryUser, [
            'old_password' => self::PRIMARY_OLD_PASSWORD,
            'new_password' => self::PRIMARY_NEW_PASSWORD,
        ]);

        self::assertNull($error);
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getCode());
        $this->assertTokenStatus(
            $legacyPreChangeToken,
            401,
            'A legacy token issued before a password change must remain revoked'
        );
    }

    public function testLegacyTokenCannotUpgradeAfterAdministratorPasswordResetAudit(): void
    {
        $legacyToken = 'tc013_legacy_before_admin_reset';
        $this->seedLegacyToken($legacyToken, self::PRIMARY_USER_ID);
        Db::name('operation_logs')->insert([
            'user_id' => self::ADMIN_USER_ID,
            'module' => 'auth',
            'action' => 'reset_password',
            'description' => 'isolated administrator password reset',
            'extra_data' => json_encode([
                'operator_user_id' => self::ADMIN_USER_ID,
                'target_user_id' => self::PRIMARY_USER_ID,
            ], JSON_UNESCAPED_SLASHES),
            'create_time' => date('Y-m-d H:i:s'),
        ]);

        $resetAudit = Db::name('operation_logs')->where('action', 'reset_password')->find();
        self::assertSame(self::ADMIN_USER_ID, (int)($resetAudit['user_id'] ?? 0));
        $resetExtra = json_decode((string)($resetAudit['extra_data'] ?? ''), true);
        self::assertIsArray($resetExtra);
        self::assertSame(self::PRIMARY_USER_ID, (int)($resetExtra['target_user_id'] ?? 0));

        $this->assertTokenStatus(
            $legacyToken,
            401,
            'A legacy token issued before an administrator password reset must remain revoked'
        );
    }

    public function testLegacyTokenCannotUpgradeAfterHistoricalAdministratorResetAuditShape(): void
    {
        $legacyToken = 'tc013_legacy_before_historical_admin_reset';
        $this->seedLegacyToken($legacyToken, self::PRIMARY_USER_ID);
        Db::name('operation_logs')->insert([
            // Historical rows used user_id for the reset target and had no target_user_id envelope.
            'user_id' => self::PRIMARY_USER_ID,
            'module' => 'auth',
            'action' => 'reset_password',
            'description' => 'historical administrator password reset',
            'create_time' => date('Y-m-d H:i:s'),
        ]);

        $this->assertTokenStatus(
            $legacyToken,
            401,
            'A legacy token issued before a historical-format administrator reset must remain revoked'
        );
    }

    public function testMiddlewareRecordsSanitizedTerminalAuditForManualPathHttpFailure(): void
    {
        $token = 'tc013_manual_failure_audit';
        $requestId = 'tc013_manual_failure_request';
        $secret = 'Tc013-DoNotPersist!Http';
        $this->seedToken($token, self::PRIMARY_USER_ID);
        $request = $this->authenticatedRequest(
            $token,
            'PUT',
            '/api/users/' . self::PRIMARY_USER_ID,
            $requestId,
            ['password' => $secret, 'status' => 0]
        );

        $response = (new AuthMiddleware())->handle(
            $request,
            static fn(Request $_request): Response => json(['code' => 422, 'message' => 'validation failed'], 422)
        );

        self::assertSame(422, $response->getCode());
        $audit = Db::name('operation_logs')->order('id', 'desc')->find();
        self::assertIsArray($audit);
        self::assertSame('user', $audit['module']);
        self::assertSame('save_form', $audit['action']);
        self::assertSame('HTTP 422', $audit['error_info']);
        $extra = json_decode((string)$audit['extra_data'], true);
        self::assertIsArray($extra);
        self::assertSame('failed', $extra['outcome'] ?? null);
        self::assertSame(422, $extra['http_status'] ?? null);
        self::assertSame($requestId, $extra['request_id'] ?? null);
        self::assertSame('http_response_failure', $extra['reason_code'] ?? null);
        self::assertSame('***', $extra['params']['password'] ?? null, json_encode($extra, JSON_UNESCAPED_SLASHES));
        self::assertStringNotContainsString($secret, json_encode($audit, JSON_UNESCAPED_SLASHES));
    }

    public function testMiddlewareRecordsSafeTerminalAuditBeforeRethrowingControllerException(): void
    {
        $token = 'tc013_controller_exception_audit';
        $requestId = 'tc013_exception_failure_request';
        $secret = 'Tc013-DoNotPersist!Exception';
        $exceptionMessage = 'controller exploded with private detail ' . $secret;
        $this->seedToken($token, self::PRIMARY_USER_ID);
        $request = $this->authenticatedRequest(
            $token,
            'POST',
            '/api/auth/changePassword',
            $requestId,
            ['old_password' => $secret, 'new_password' => $secret]
        );

        $caught = null;
        try {
            (new AuthMiddleware())->handle(
                $request,
                static function (Request $_request) use ($exceptionMessage): Response {
                    throw new RuntimeException($exceptionMessage);
                }
            );
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(RuntimeException::class, $caught);
        self::assertSame($exceptionMessage, $caught->getMessage());
        $audit = Db::name('operation_logs')->order('id', 'desc')->find();
        self::assertIsArray($audit);
        self::assertSame('auth', $audit['module']);
        self::assertSame('save_form', $audit['action']);
        self::assertSame('controller_exception', $audit['error_info']);
        $extra = json_decode((string)$audit['extra_data'], true);
        self::assertIsArray($extra);
        self::assertSame('failed', $extra['outcome'] ?? null);
        self::assertSame(500, $extra['http_status'] ?? null);
        self::assertSame($requestId, $extra['request_id'] ?? null);
        self::assertSame('controller_exception', $extra['reason_code'] ?? null);
        self::assertSame(RuntimeException::class, $extra['exception_type'] ?? null);
        self::assertSame('***', $extra['params']['old_password'] ?? null, json_encode($extra, JSON_UNESCAPED_SLASHES));
        self::assertSame('***', $extra['params']['new_password'] ?? null, json_encode($extra, JSON_UNESCAPED_SLASHES));
        $encodedAudit = json_encode($audit, JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString($exceptionMessage, $encodedAudit);
        self::assertStringNotContainsString($secret, $encodedAudit);
    }

    /**
     * @return array<string, array{0: string, 1: array{actor_scope: string, data_completeness: string, freshness: string, upstream_state: string}}>
     */
    public static function l8VariantProvider(): array
    {
        return [
            'DX-0097 authorized complete fresh success' => ['DX-0097', self::factors('authorized', 'complete', 'fresh', 'success')],
            'DX-0098 authorized complete stale failure' => ['DX-0098', self::factors('authorized', 'complete', 'stale', 'failure')],
            'DX-0099 authorized missing fresh failure' => ['DX-0099', self::factors('authorized', 'missing_required', 'fresh', 'failure')],
            'DX-0100 authorized missing stale success' => ['DX-0100', self::factors('authorized', 'missing_required', 'stale', 'success')],
            'DX-0101 restricted complete fresh failure' => ['DX-0101', self::factors('restricted', 'complete', 'fresh', 'failure')],
            'DX-0102 restricted complete stale success' => ['DX-0102', self::factors('restricted', 'complete', 'stale', 'success')],
            'DX-0103 restricted missing fresh success' => ['DX-0103', self::factors('restricted', 'missing_required', 'fresh', 'success')],
            'DX-0104 restricted missing stale failure' => ['DX-0104', self::factors('restricted', 'missing_required', 'stale', 'failure')],
        ];
    }

    /**
     * @return array{actor_scope: string, data_completeness: string, freshness: string, upstream_state: string}
     */
    private static function factors(
        string $actorScope,
        string $dataCompleteness,
        string $freshness,
        string $upstreamState
    ): array {
        return [
            'actor_scope' => $actorScope,
            'data_completeness' => $dataCompleteness,
            'freshness' => $freshness,
            'upstream_state' => $upstreamState,
        ];
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL DEFAULT 0,
            username VARCHAR(80) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            realname VARCHAR(80),
            email VARCHAR(160),
            phone VARCHAR(30),
            role_id INTEGER NOT NULL DEFAULT 0,
            hotel_id INTEGER,
            status INTEGER NOT NULL DEFAULT 1,
            last_login_time DATETIME,
            last_login_ip VARCHAR(50),
            login_count INTEGER NOT NULL DEFAULT 0,
            create_time DATETIME,
            update_time DATETIME
        )');
        Db::execute('CREATE TABLE system_config (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            config_key VARCHAR(100) NOT NULL UNIQUE,
            config_value TEXT,
            description TEXT,
            create_time DATETIME,
            update_time DATETIME
        )');
        Db::execute('CREATE TABLE operation_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            hotel_id INTEGER,
            module VARCHAR(80),
            action VARCHAR(80),
            description TEXT,
            error_info TEXT,
            extra_data TEXT,
            ip VARCHAR(50),
            user_agent TEXT,
            create_time DATETIME
        )');
    }

    /** @return array<string, mixed> */
    private function userRow(int $id, string $username, string $password): array
    {
        return [
            'id' => $id,
            'tenant_id' => 0,
            'username' => $username,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'realname' => $username,
            'role_id' => 0,
            'hotel_id' => null,
            'status' => User::STATUS_ENABLED,
            'login_count' => 0,
            'create_time' => '2026-07-15 00:00:00',
            'update_time' => '2026-07-15 00:00:00',
        ];
    }

    private function configurePasswordSaveOutcome(string $upstreamState): void
    {
        Db::execute('DROP TRIGGER IF EXISTS ' . self::FAILURE_TRIGGER);
        if ($upstreamState === 'failure') {
            Db::execute(
                'CREATE TRIGGER ' . self::FAILURE_TRIGGER . ' BEFORE UPDATE OF password ON users '
                . "BEGIN SELECT RAISE(ABORT, '" . self::FAILURE_TRIGGER . "'); END"
            );
        }

        $triggerCount = Db::query(
            "SELECT COUNT(*) AS aggregate FROM sqlite_master WHERE type = 'trigger' AND name = '"
            . self::FAILURE_TRIGGER
            . "'"
        );
        self::assertSame(
            $upstreamState === 'failure' ? 1 : 0,
            (int)($triggerCount[0]['aggregate'] ?? 0),
            'Password persistence factor was not applied to the isolated SQLite fixture.'
        );
    }

    /**
     * @param array<string, mixed> $post
     * @return array{0: Response|null, 1: Throwable|null}
     */
    private function attemptPasswordChange(User $actor, array $post): array
    {
        try {
            $controller = new Tc013AuthControllerHarness(self::$app, $actor, $post);
            return [$controller->changePassword(), null];
        } catch (Throwable $e) {
            return [null, $e];
        }
    }

    private function assertPasswordPersistence(bool $passwordChanged, string $message): void
    {
        $primaryHash = (string)Db::name('users')->where('id', self::PRIMARY_USER_ID)->value('password');
        $otherHash = (string)Db::name('users')->where('id', self::OTHER_USER_ID)->value('password');

        if ($passwordChanged) {
            self::assertTrue(password_verify(self::PRIMARY_NEW_PASSWORD, $primaryHash), $message . ' new password was not persisted');
            self::assertFalse(password_verify(self::PRIMARY_OLD_PASSWORD, $primaryHash), $message . ' old password still matches');
        } else {
            self::assertTrue(password_verify(self::PRIMARY_OLD_PASSWORD, $primaryHash), $message . ' rejected/failed request changed target password');
            self::assertFalse(password_verify(self::PRIMARY_NEW_PASSWORD, $primaryHash), $message . ' rejected/failed request persisted new password');
        }

        self::assertTrue(password_verify(self::OTHER_PASSWORD, $otherHash), $message . ' other-token actor password changed');
    }

    private function seedToken(string $token, int $userId): void
    {
        $user = User::find($userId);
        self::assertInstanceOf(User::class, $user);
        $tokenData = [
            'user_id' => $userId,
            'created_at' => time(),
            'ip' => '127.0.0.1',
            'user_agent' => 'tc013-isolated-phpunit',
            'auth_version' => $user->authSessionVersion(),
        ];
        self::assertTrue(Cache::set('token_' . $token, $tokenData, 3600));
        self::assertTrue(Cache::set('user_token_' . $userId, $token, 3600));
    }

    private function seedLegacyToken(string $token, int $userId): void
    {
        $tokenData = [
            'user_id' => $userId,
            'created_at' => time(),
            'ip' => '127.0.0.1',
            'user_agent' => 'tc013-legacy-upgrade',
        ];
        self::assertTrue(Cache::set('token_' . $token, $tokenData, 3600));
        self::assertTrue(Cache::set('user_token_' . $userId, $token, 3600));
    }

    /** @param array<string, mixed> $post */
    private function authenticatedRequest(
        string $token,
        string $method,
        string $url,
        string $requestId,
        array $post
    ): Request {
        $path = trim((string)parse_url($url, PHP_URL_PATH), '/');

        $request = (new Request())
            ->setMethod($method)
            ->setUrl($url)
            ->setBaseUrl($url)
            ->setPathinfo($path)
            ->withServer(['REQUEST_METHOD' => $method])
            ->withHeader([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'tc013-isolated-phpunit',
                'X-Request-ID' => $requestId,
            ]);

        return $request->withInput((string)json_encode($post, JSON_UNESCAPED_SLASHES));
    }

    private function assertTokenStatus(string $token, int $expectedStatus, string $message): ?User
    {
        $authenticatedUser = null;
        $request = (new Request())
            ->setMethod('GET')
            ->setUrl('/api/auth/info')
            ->setBaseUrl('/api/auth/info')
            ->setPathinfo('api/auth/info')
            ->withHeader([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'User-Agent' => 'tc013-isolated-phpunit',
            ]);

        $response = (new AuthMiddleware())->handle(
            $request,
            static function (Request $authenticatedRequest) use (&$authenticatedUser): Response {
                $authenticatedUser = $authenticatedRequest->user ?? null;
                return json([
                    'code' => 200,
                    'data' => ['user_id' => (int)($authenticatedUser->id ?? 0)],
                ]);
            }
        );

        self::assertSame(
            $expectedStatus,
            $response->getCode(),
            $message . '; middleware response=' . (string)$response->getContent()
        );

        if ($expectedStatus === 200) {
            self::assertInstanceOf(User::class, $authenticatedUser, $message . ' did not attach a user');
            return $authenticatedUser;
        }

        self::assertNull($authenticatedUser, $message . ' rejected token still reached the protected handler');
        return null;
    }

    private static function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS);
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                self::removeDirectory($item->getPathname());
            } elseif (!unlink($item->getPathname())) {
                throw new RuntimeException('Unable to remove cache fixture file: ' . $item->getPathname());
            }
        }

        if (!rmdir($path)) {
            throw new RuntimeException('Unable to remove cache fixture directory: ' . $path);
        }
    }
}

final class Tc013AuthControllerHarness extends AuthController
{
    /** @param array<string, mixed> $post */
    public function __construct(App $app, User $actor, array $post)
    {
        parent::__construct($app);
        $this->currentUser = $actor;
        $this->request = (new Request())
            ->setMethod('POST')
            ->setUrl('/api/auth/changePassword')
            ->setBaseUrl('/api/auth/changePassword')
            ->setPathinfo('api/auth/changePassword')
            ->withPost($post)
            ->withHeader([
                'Accept' => 'application/json',
                'User-Agent' => 'tc013-isolated-phpunit',
            ]);
    }
}

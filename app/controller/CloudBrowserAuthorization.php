<?php
declare(strict_types=1);

namespace app\controller;

use app\service\CloudBrowserLoginGatewayService;
use app\service\CloudBrowserProfileService;
use app\service\CloudBrowserViewerAuthorizationService;
use RuntimeException;
use think\facade\Db;
use think\Response;

/** Customer entry points only. Gateway control credentials never reach the browser. */
final class CloudBrowserAuthorization extends Base
{
    public function status(): Response
    {
        [$hotelId, $userId] = $this->authorizedScope();
        $platform = trim((string)$this->request->get('platform', ''));
        try {
            return $this->success((new CloudBrowserProfileService())->status(
                $hotelId,
                $userId,
                $platform !== '' ? $platform : null
            ));
        } catch (RuntimeException $e) {
            return $this->error('无法读取云端授权状态', 422, ['reason' => $e->getMessage()]);
        }
    }

    /**
     * Legacy route retained, but it now opens the protected viewer flow and
     * never returns the one-time gateway ticket to the browser.
     */
    public function requestLogin(): Response
    {
        return $this->error('该入口已迁移到门店三源接入向导，请从门店管理打开云端登录。', 409, [
            'reason' => 'cloud_browser_legacy_login_route_deprecated',
            'replacement' => '/api/cloud-browser-profiles/open-login',
            'ticket_exposed' => false,
        ]);
    }

    /** Opens the loopback gateway and returns only a protected relative viewer URL. */
    public function openLogin(): Response
    {
        return $this->openLoginResponse();
    }

    private function openLoginResponse(): Response
    {
        [$hotelId, $userId, $tenantId] = $this->authorizedScope();
        $input = $this->requestData();
        $unknownKeys = array_values(array_diff(array_keys($input), ['hotel_id', 'platform']));
        if ($unknownKeys !== []) {
            return $this->error('云端登录只接收门店和平台，请直接在受保护的平台页面输入账号与验证信息。', 422, [
                'reason' => 'cloud_browser_login_input_unknown_fields',
                'unknown_fields' => $unknownKeys,
                'credentials_accepted' => false,
            ]);
        }
        try {
            $result = (new CloudBrowserLoginGatewayService())->open(
                $tenantId,
                $hotelId,
                $userId,
                (string)($input['platform'] ?? '')
            );
            $viewerToken = (string)($result['_viewer_token'] ?? '');
            unset($result['_viewer_token']);
            return $this->success($result, '云端登录窗口已打开，请直接在平台原页面完成登录。')
                ->cookie(CloudBrowserViewerAuthorizationService::COOKIE_NAME, $viewerToken, [
                    'expire' => CloudBrowserViewerAuthorizationService::MAX_TTL_SECONDS,
                    'path' => '/',
                    'secure' => true,
                    'httponly' => true,
                    'samesite' => 'strict',
                ]);
        } catch (RuntimeException $e) {
            $reason = $e->getMessage();
            $status = str_contains($reason, 'capacity_busy') ? 409 : 422;
            return $this->error('无法打开云端登录窗口', $status, ['reason' => $reason]);
        }
    }

    /** Seals the exact active Profile after the user finishes platform login. */
    public function completeLogin(): Response
    {
        [$hotelId, $userId, $tenantId] = $this->authorizedScope();
        $input = $this->requestData();
        $unknownKeys = array_values(array_diff(
            array_keys($input),
            ['hotel_id', 'platform', 'profile_id', 'session_id']
        ));
        if ($unknownKeys !== []) {
            return $this->error('完成云端登录只接收当前门店、平台及会话标识。', 422, [
                'reason' => 'cloud_browser_login_completion_input_unknown_fields',
                'unknown_fields' => $unknownKeys,
                'credentials_accepted' => false,
            ]);
        }
        $viewerToken = trim((string)$this->request->cookie(
            CloudBrowserViewerAuthorizationService::COOKIE_NAME,
            ''
        ));
        try {
            $result = (new CloudBrowserLoginGatewayService())->complete(
                $tenantId,
                $hotelId,
                $userId,
                (string)($input['platform'] ?? ''),
                (string)($input['profile_id'] ?? ''),
                (string)($input['session_id'] ?? ''),
                $viewerToken
            );
            return $this->success($result, '登录会话已加密保存；首次采集仍会核对平台门店身份。')
                ->cookie(CloudBrowserViewerAuthorizationService::COOKIE_NAME, '', [
                    'expire' => -3600,
                    'path' => '/',
                    'secure' => true,
                    'httponly' => true,
                    'samesite' => 'strict',
                ]);
        } catch (RuntimeException $e) {
            return $this->error('无法完成云端登录', 422, ['reason' => $e->getMessage()]);
        }
    }

    /** Public only to Nginx auth_request; the short-lived HttpOnly cookie is the authority. */
    public function authorizeViewer(): Response
    {
        $viewerToken = trim((string)$this->request->cookie(
            CloudBrowserViewerAuthorizationService::COOKIE_NAME,
            ''
        ));
        try {
            $scope = (new CloudBrowserViewerAuthorizationService())->authorize($viewerToken);
            return response('', 204, [
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
                // These digests are consumed only by Nginx auth_request and bind
                // the viewer WebSocket to the exact active gateway session.
                'X-SUXIOS-Viewer-Profile-Scope' => hash('sha256', (string)$scope['profile_id']),
                'X-SUXIOS-Viewer-Session-Scope' => hash('sha256', (string)$scope['session_id']),
            ]);
        } catch (RuntimeException) {
            return response('', 401, [
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }
    }

    /** @return array{0:int,1:int,2:int} */
    private function authorizedScope(): array
    {
        if (!$this->currentUser) {
            abort(401, '请先登录');
        }
        $input = $this->requestData();
        $hotelId = (int)($this->request->get('hotel_id', 0) ?: ($input['hotel_id'] ?? 0));
        if ($hotelId <= 0) {
            abort(422, '请选择酒店');
        }
        if (!$this->currentUser->isSuperAdmin()
            && !$this->currentUser->hasHotelPermission($hotelId, 'can_fetch_online_data')
        ) {
            abort(403, '无权管理该酒店的云端平台授权');
        }
        $hotel = Db::name('hotels')->where('id', $hotelId)->where('status', 1)->find();
        if (!is_array($hotel)) {
            abort(404, 'hotel_not_found');
        }
        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        if ($tenantId <= 0
            || (!$this->currentUser->isSuperAdmin() && $tenantId !== (int)$this->currentUser->tenant_id)
        ) {
            abort(403, 'hotel_tenant_scope_mismatch');
        }
        return [$hotelId, (int)$this->currentUser->id, $tenantId];
    }
}

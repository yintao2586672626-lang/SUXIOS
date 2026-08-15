import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const read = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

test('cloud viewer is same-origin, exact-session scoped, short-lived, and keeps every browser listener loopback-only', async () => {
  const [controller, gatewayService, viewerService, gateway, nginx, installer, vnc, novnc, remoteInstaller, routes] = await Promise.all([
    read('app/controller/CloudBrowserAuthorization.php'),
    read('app/service/CloudBrowserLoginGatewayService.php'),
    read('app/service/CloudBrowserViewerAuthorizationService.php'),
    read('deploy/remote-browser/cloud_browser_gateway.mjs'),
    read('deploy/nginx/suxios-cloud-browser-viewer.conf'),
    read('deploy/nginx/install_cloud_browser_viewer.sh'),
    read('deploy/remote-browser/systemd/suxios-cloud-browser-vnc.service'),
    read('deploy/remote-browser/systemd/suxios-cloud-browser-novnc.service'),
    read('deploy/remote-browser/install_secure_remote_browser.sh'),
    read('route/app.php'),
  ]);

  assert.match(gatewayService, /GATEWAY_URL = 'http:\/\/127\.0\.0\.1:8787'/);
  assert.match(gatewayService, /VIEWER_URL = '\/cloud-browser-viewer\//);
  assert.doesNotMatch(gatewayService, /viewer_url'\s*=>\s*\(string\).*gateway/);
  assert.match(viewerService, /MAX_TTL_SECONDS = 900/);
  assert.match(viewerService, /hash\('sha256', trim\(\$token\)\)/);
  assert.match(controller, /'httponly'\s*=>\s*true/);
  assert.match(controller, /'secure'\s*=>\s*true/);
  assert.match(controller, /'samesite'\s*=>\s*'strict'/);
  assert.match(controller, /X-SUXIOS-Viewer-Profile-Scope'[\s\S]{0,100}hash\('sha256'/);
  assert.match(controller, /X-SUXIOS-Viewer-Session-Scope'[\s\S]{0,100}hash\('sha256'/);
  assert.match(controller, /function requestLogin\(\): Response[\s\S]{0,260}cloud_browser_legacy_login_route_deprecated/);
  assert.match(controller, /cloud_browser_login_input_unknown_fields/);
  assert.match(controller, /cloud_browser_login_completion_input_unknown_fields/);
  assert.match(controller, /'credentials_accepted'\s*=>\s*false/g);
  const legacyEntry = controller.slice(
    controller.indexOf('public function requestLogin'),
    controller.indexOf('/** Opens the loopback gateway', controller.indexOf('public function requestLogin')),
  );
  assert.doesNotMatch(legacyEntry, /requestLoginEntry/);
  assert.match(legacyEntry, /'ticket_exposed'\s*=>\s*false/);
  assert.match(nginx, /auth_request \/_suxios_cloud_browser_viewer_auth/);
  assert.match(nginx, /location = \/_suxios_cloud_browser_viewer_auth \{[\s\S]{0,800}proxy_pass https:\/\/127\.0\.0\.1\/index\.php\/api\/cloud-browser-viewer\/authorize/);
  assert.match(nginx, /proxy_set_header Cookie \$http_cookie/);
  assert.match(nginx, /proxy_ssl_verify off/);
  assert.doesNotMatch(nginx, /proxy_pass http:\/\/127\.0\.0\.1:8080/);
  assert.match(nginx, /auth_request_set \$suxios_viewer_profile_scope \$upstream_http_x_suxios_viewer_profile_scope/);
  assert.match(nginx, /auth_request_set \$suxios_viewer_session_scope \$upstream_http_x_suxios_viewer_session_scope/);
  assert.match(nginx, /proxy_set_header X-SUXIOS-Viewer-Profile-Scope \$suxios_viewer_profile_scope/);
  assert.match(nginx, /proxy_set_header X-SUXIOS-Viewer-Session-Scope \$suxios_viewer_session_scope/);
  assert.match(nginx, /location = \/api\/cloud-browser-viewer\/authorize \{[\s\S]{0,80}return 404/);
  assert.doesNotMatch(nginx, /add_header X-SUXIOS-Viewer-(Profile|Session)-Scope/);
  assert.match(nginx, /proxy_pass http:\/\/127\.0\.0\.1:6080\//);
  assert.match(nginx, /proxy_set_header Upgrade \$http_upgrade/);
  assert.match(gateway, /viewerScopeDigestMatches/);
  assert.match(gateway, /x-suxios-viewer-profile-scope/);
  assert.match(gateway, /x-suxios-viewer-session-scope/);
  assert.match(gateway, /disconnectViewerConnections\(session\)/);
  assert.match(gateway, /HTTP\/1\.1 401 Unauthorized/);
  assert.match(nginx, /X-SUXIOS-Viewer-Profile-Scope \$suxios_viewer_profile_scope/);
  assert.match(nginx, /X-SUXIOS-Viewer-Session-Scope \$suxios_viewer_session_scope/);
  assert.match(installer, /expected_exactly_one_tls_server/);
  assert.match(installer, /fail_install[\s\S]+?rollback[\s\S]+?trap - ERR[\s\S]+?exit 1/);
  assert.match(installer, /fail_install "Viewer authorization did not fail closed/);
  assert.match(installer, /SUXIOS_CLOUD_BROWSER_VIEWER_VERIFY_TIMEOUT_SECONDS:-15/);
  assert.match(installer, /while :; do[\s\S]+?viewer_status[\s\S]+?sleep 1/);
  assert.match(installer, /nginx -t/);
  assert.match(vnc, /-nopw/);
  assert.match(vnc, /-listen 127\.0\.0\.1/);
  assert.doesNotMatch(vnc, /-rfbauth|LoadCredential/);
  assert.match(novnc, /127\.0\.0\.1:6080 127\.0\.0\.1:5900/);
  assert.match(remoteInstaller, /config_group="www-data"/);
  assert.match(remoteInstaller, /install -d -m 0750 -o root -g "\$config_group" "\$config_root"/);
  assert.match(routes, /cloud-browser-viewer\/authorize/);
  assert.match(routes, /cloud-browser-profiles[\s\S]{0,500}open-login/);
  assert.match(routes, /cloud-browser-profiles[\s\S]{0,500}complete-login/);
});

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

function read(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

function assertContract(condition, message) {
  if (!condition) {
    throw new Error(`[protected-core-contract] ${message}`);
  }
}

const route = read('route/app.php');
const service = read('app/service/ProtectedCapabilityService.php');
const auth = read('app/middleware/Auth.php');
const roleController = read('app/controller/RoleController.php');
const systemConfig = read('app/model/SystemConfig.php');
const dailyReport = read('app/controller/DailyReport.php');
const agent = read('app/controller/Agent.php');
const revenueAi = read('app/controller/RevenueAi.php');

const protectedRoutes = [
  'api/online-data/ctrip-profile-fields',
  'api/online-data/collection-reliability',
  'api/online-data/validate-ctrip-endpoint-evidence',
  'api/online-data/data-analysis',
  'api/online-data/ai-analysis',
  'api/agent',
  'api/ai-daily-reports',
  'api/ota-standard',
  'api/revenue-research',
  'api/transfer',
  'api/strategy',
  'api/simulation',
  'api/expansion',
  'api/opening',
  'api/operation',
  'api/daily-reports/export',
  'api/system-config/export',
  'api/ai-governance',
  'api/ai-config',
  'api/report-configs',
  'api/daily-reports/view-mapping',
];

for (const protectedRoute of protectedRoutes) {
  assertContract(service.includes(`'${protectedRoute}'`) || service.includes(`"${protectedRoute}"`), `${protectedRoute} must be classified by ProtectedCapabilityService`);
}

assertContract(
  !service.includes("'api/revenue-ai'") && !service.includes('"api/revenue-ai"'),
  'api/revenue-ai must stay outside ProtectedCapabilityService redaction so the manager homepage remains usable'
);

for (const basicReadRoute of [
  'api/online-data/daily-data-list',
  'api/online-data/daily-data-summary',
  'api/online-data/history',
]) {
  assertContract(!service.includes(`'${basicReadRoute}'`) && !service.includes(`"${basicReadRoute}"`), `${basicReadRoute} must stay outside protected core to avoid breaking basic reads`);
}

assertContract(service.includes('normalizePathRule'), 'ProtectedCapabilityService must support method-scoped protected routes');
assertContract(service.includes("['path' => 'api/online-data/data-sources', 'methods' => ['POST']]"), 'data source list GET must remain basic while POST is protected');
assertContract(service.includes("['path' => 'api/online-data/data-sources/*', 'methods' => ['DELETE']]"), 'data source delete must remain protected');
assertContract(service.includes("['path' => 'api/online-data/data-sources/*/sync', 'methods' => ['POST']]"), 'data source sync must remain protected');

const routeMarkers = [
  "Route::group('api/online-data'",
  "Route::group('api/agent'",
  "Route::group('api/ai-daily-reports'",
  "Route::group('api/transfer'",
  "Route::group('api/operation'",
  "Route::group('api/revenue-ai'",
  "Route::group('api/ai-governance'",
  "Route::group('api/ai-config'",
  "Route::group('api/report-configs'",
  "Route::group('api/daily-reports'",
  "Route::group('api/system-config'",
];

for (const marker of routeMarkers) {
  assertContract(route.includes(marker), `${marker} must remain in route/app.php`);
}

const sensitiveKeys = [
  'prompt',
  'formula',
  'source_path',
  'request_url',
  'headers',
  'raw_data',
  'p3_evidence',
  'field_mapping',
  'diagnosis_rule',
];

for (const key of sensitiveKeys) {
  assertContract(service.includes(`'${key}'`) || service.includes(`"${key}"`), `${key} must be in the redaction list`);
}

const permissionKeys = [
  'can_use_ai_decision',
  'can_use_investment',
  'can_export_data',
  'can_view_field_assets',
  'can_view_diagnostics',
  'can_manage_ai_governance',
];

for (const permission of permissionKeys) {
  assertContract(roleController.includes(permission), `${permission} must be exposed in role permissions`);
  assertContract(service.includes(permission), `${permission} must be enforced by ProtectedCapabilityService`);
}

assertContract(systemConfig.includes('KEY_PROTECTED_CAPABILITY_POLICY'), 'SystemConfig must define protected capability policy key');
assertContract(systemConfig.includes('default_module_entitlement'), 'protected capability policy must default module entitlement explicitly');
assertContract(auth.includes('ProtectedCapabilityService'), 'Auth middleware must use ProtectedCapabilityService');
assertContract(auth.includes('authorizeContext'), 'Auth middleware must enforce protected capability authorization');
assertContract(auth.includes('redactProtectedResponse'), 'Auth middleware must redact protected responses');
assertContract(auth.includes('X-Request-ID'), 'Auth middleware must return X-Request-ID');
assertContract(auth.includes('protected_trace') && auth.includes("'generated_at'"), 'protected JSON responses must include trace metadata');
assertContract(auth.includes('tenant_') && auth.includes('user_') && auth.includes('ip_') && auth.includes('endpoint_'), 'rate limit key must include tenant/user/IP/endpoint');
assertContract(auth.includes('OperationLog::record') && auth.includes('SystemNotification::recordEvent'), 'rate limit and denial paths must audit and notify');
assertContract(dailyReport.includes("'tenant_id'") && dailyReport.includes("'request_id'") && dailyReport.includes("'generated_at'"), 'daily report export watermark must include tenant/user/hotel/request/generated trace fields');
assertContract(agent.includes('direct_price_apply_disabled'), 'Agent direct price apply route must return an explicit disabled reason in Revenue AI Phase 1B');
assertContract(agent.includes("'local_price_updated' => false"), 'Agent direct price apply must not claim local room price was updated');
assertContract(agent.includes("'auto_write_ota' => false"), 'Agent direct price apply must not write or claim OTA writes');
assertContract(agent.includes("'/api/revenue-ai/price-suggestions/' . $id . '/execution-intent'"), 'Agent direct price apply must direct users to the Revenue AI execution-intent bridge');
assertContract(!agent.includes('$roomType->base_price = (float)$suggestion->suggested_price'), 'Agent direct price apply must not update room_types.base_price in Phase 1B');
assertContract(!agent.includes("'price_apply'"), 'Agent direct price apply must not log a successful price_apply event in Phase 1B');
assertContract(route.includes("Route::post('/price-suggestions/:id/review', 'RevenueAi/reviewPriceSuggestion')"), 'Revenue AI must expose manual review route');
assertContract(route.includes("Route::post('/price-suggestions/:id/execution-intent', 'RevenueAi/createPriceSuggestionExecutionIntent')"), 'Revenue AI must expose execution-intent bridge route');
assertContract(revenueAi.includes('PriceSuggestion::STATUS_PENDING'), 'Revenue AI review route must require pending suggestions before approve/reject');
assertContract(revenueAi.includes('PriceSuggestion::STATUS_APPROVED'), 'Revenue AI execution-intent route must require approved suggestions');
assertContract(revenueAi.includes('createExecutionIntent('), 'Revenue AI execution-intent route must use OperationManagementService');
assertContract(revenueAi.includes("'auto_write_ota' => false"), 'Revenue AI endpoints must not claim OTA write-back');
assertContract(revenueAi.includes("'local_price_updated' => false"), 'Revenue AI endpoints must not claim local price application');
assertContract(!revenueAi.includes('->apply('), 'Revenue AI endpoints must not call PriceSuggestion::apply');
assertContract(!revenueAi.includes('$roomType->base_price') && !revenueAi.includes("Db::name('room_types')"), 'Revenue AI endpoints must not update room_types.base_price');

console.log('[protected-core-contract] ok');

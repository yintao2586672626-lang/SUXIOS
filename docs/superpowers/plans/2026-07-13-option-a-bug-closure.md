# 方案 A 缺陷闭环 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 修复当前 5 个 PHPUnit 与 7 个 Node 契约失败，同时恢复 Profile 尝试采集、多配置凭据可执行和模板拆分后的真实 P0 校验，不伪造当日 OTA 闭环。

**Architecture:** 将“允许尝试 Profile 采集”与“可声明当日 P0 闭环”分层：采集入口只检查启用状态、平台、采集方式和本地 Profile，采集结果与 P0 校验继续依赖真实会话和目标日证据。凭据库以 `(tenant_id, system_hotel_id, platform, config_id)` 为独立执行定位器，同定位器保存视为轮换，不同定位器互不撤销；前端契约校验统一读取运行壳、预编译模板源和主入口源。

**Tech Stack:** PHP 8.2、ThinkPHP、PHPUnit 11、Node.js test runner、Vue 3 runtime-only、PowerShell。

---

### Task 1: 恢复 Profile 尝试采集边界

**Files:**
- Modify: `app/controller/concern/AutoFetchConcern.php`
- Test: `tests/OnlineDataTest.php`
- Test: `tests/OtaProfileSessionProofConsumerTest.php`

- [ ] **Step 1: 运行现有回归测试并确认 RED**

Run:

```powershell
& 'C:\xampp\php\php.exe' 'vendor\bin\phpunit' --colors=never tests\OnlineDataTest.php --filter 'testAutoFetchUsesReadyBrowserProfileSourcesWithoutSameDayProof'
& 'C:\xampp\php\php.exe' 'vendor\bin\phpunit' --colors=never tests\OtaProfileSessionProofConsumerTest.php --filter 'testAutoFetchCollectableSourcesUseReusableProofAndSupportBothProfileMethods|testDirectBrowserAutoFetchAttemptsProfileDirectoryWithoutCurrentProof'
```

Expected: 3 个测试因候选源被 `isCurrentVerified()` 过滤或直接采集提前返回 `current_session_not_verified` 而失败。

- [ ] **Step 2: 删除候选源的当日证明前置过滤**

在 `filterCollectableBrowserProfileDataSources()` 中保留启用状态、平台、采集方式和状态检查，删除：

```php
$proofService = new OtaProfileSessionProofService();
if (!$proofService->isCurrentVerified($source)) {
    continue;
}
```

- [ ] **Step 3: 删除直接 Profile 采集的当日证明前置返回**

在 `executeCtripBrowserProfileAutoFetch()` 与 `executeMeituanBrowserProfileAutoFetch()` 中删除：

```php
$profileSource = $this->loadProfileSessionSource($platform, $hotelId, $profileKey);
if (!$interactiveBrowser && !(new OtaProfileSessionProofService())->isCurrentVerified($profileSource ?? [])) {
    return [
        'success' => false,
        'skipped' => true,
        'message' => 'current_session_not_verified',
        'status_code' => 'current_session_not_verified',
        'saved_count' => 0,
    ];
}
```

继续保留本地 Profile 目录检查、采集脚本检查、真实响应登录失效判断和 P0 证明校验。

- [ ] **Step 4: 运行聚焦测试并确认 GREEN**

Run: 重复 Step 1 两条命令。

Expected: 3 个测试全部通过。

---

### Task 2: 修复多配置凭据被错误撤销

**Files:**
- Modify: `tests/OtaCredentialVaultTest.php`
- Modify: `app/service/OtaCredentialVault.php`
- Test: `tests/OtaCredentialMigrationServiceTest.php`

- [ ] **Step 1: 先把凭据库契约改成独立定位器并确认 RED**

将 `testNewConfigRevokesPreviousReadyCredentialForSameHotelAndPlatform()` 改为 `testDifferentConfigLocatorsRemainIndependentlyExecutable()`，断言：

```php
self::assertSame('ready', $vault->metadata(7, 101, 'ctrip', 'old-config')['credential_status']);
self::assertSame('ready', $vault->metadata(7, 101, 'ctrip', 'new-config')['credential_status']);
self::assertSame(2, (int)Db::name('ota_credentials')
    ->where('tenant_id', 7)
    ->where('system_hotel_id', 101)
    ->where('platform', 'ctrip')
    ->where('credential_status', 'ready')
    ->count());
self::assertSame('old', $vault->withPayloadForExecution(7, 101, 'ctrip', 'old-config', fn(array $payload): string => $payload['token']));
self::assertSame('new', $vault->withPayloadForExecution(7, 101, 'ctrip', 'new-config', fn(array $payload): string => $payload['token']));
```

Run:

```powershell
& 'C:\xampp\php\php.exe' 'vendor\bin\phpunit' --colors=never tests\OtaCredentialVaultTest.php --filter 'testDifferentConfigLocatorsRemainIndependentlyExecutable'
```

Expected: 旧定位器当前被自动置为 `revoked`，测试失败。

- [ ] **Step 2: 删除跨 config_id 自动撤销**

在 `OtaCredentialVault::store()` 的事务中删除对其他 `config_id` 的批量 `credential_status=revoked` 更新；继续保留同一完整定位器的加锁更新、显式 `revoke()` 与执行时 `ready` 校验。

- [ ] **Step 3: 验证凭据库与迁移幂等性**

Run:

```powershell
& 'C:\xampp\php\php.exe' 'vendor\bin\phpunit' --colors=never tests\OtaCredentialVaultTest.php
& 'C:\xampp\php\php.exe' 'vendor\bin\phpunit' --colors=never tests\OtaCredentialMigrationServiceTest.php --filter 'testExecuteMigratesOnlyVerifiedBindingsRemovesPlaintextAndIsIdempotent'
```

Expected: 不同定位器均可执行；迁移状态为 `completed`，首次迁移 5 个来源项，第二次迁移 0 个来源项。

---

### Task 3: 对齐预编译模板与 P0 校验器

**Files:**
- Modify: `scripts/verify_p0_ota_field_loop_closure.php`
- Test: `tests/automation/p0_ota_field_loop_runtime.test.mjs`
- Test: `tests/automation/business_chain_status_report.test.mjs`

- [ ] **Step 1: 确认模板路径误报 RED**

Run:

```powershell
node --test tests/automation/p0_ota_field_loop_runtime.test.mjs tests/automation/business_chain_status_report.test.mjs
```

Expected: 校验器返回退出码 1 / `failed`，唯一失败级前端问题包含 `ui_frontend_p0_source_evidence_status`。

- [ ] **Step 2: 把预编译模板源加入前端契约扫描**

将：

```php
$frontendSourcePaths = ['public/index.html', 'public/data-health-static.js'];
```

改为：

```php
$frontendSourcePaths = [
    'public/index.html',
    'resources/frontend/app-template.html',
    'public/app-main.js',
    'public/data-health-static.js',
];
```

- [ ] **Step 3: 验证真实 incomplete 而非 failed**

Run: 重复 Step 1 命令。

Expected: Node 测试通过；若目标日真实数据未闭环，PHP 校验器退出码为 2 且 `status=incomplete`，不能改成 `passed`。

---

### Task 4: 修复前端归属提示和过期契约测试

**Files:**
- Modify: `app/service/CtripProfileFieldMetaService.php`
- Modify: `resources/frontend/app-template.html`
- Modify: `tests/automation/ctrip_cookie_only_identity_gate.test.mjs`
- Modify: `tests/automation/ctrip_endpoint_evidence_ui.test.mjs`
- Modify: `tests/automation/project_copy_logic.test.mjs`
- Test: `tests/OnlineDataTest.php`
- Test: `tests/automation/ctrip_competition_circle_closure.test.mjs`

- [ ] **Step 1: 确认现有文案与契约 RED**

Run:

```powershell
& 'C:\xampp\php\php.exe' 'vendor\bin\phpunit' --colors=never tests\OnlineDataTest.php --filter 'testCtripProfileCaptureFieldDefaultsCoverLatestTaskFieldsAndGaps'
node --test tests/automation/ctrip_competition_circle_closure.test.mjs tests/automation/ctrip_cookie_only_identity_gate.test.mjs tests/automation/ctrip_endpoint_evidence_ui.test.mjs tests/automation/project_copy_logic.test.mjs
```

Expected: 1 个 PHP 与 4 个 Node 契约测试失败。

- [ ] **Step 2: 统一本店酒店 ID 说明**

将 `flowTransform()` 的本店规则改为：

```php
$selfRule = 'hotelId/masterHotelId 必须等于当前携程酒店ID（即当前门店在携程响应中的真实酒店ID），才可认定为本店数据';
```

- [ ] **Step 3: 补齐竞争圈归属与冲突说明**

在“查询归属门店”区域增加用户可见说明，包含以下真实规则：

```html
<div class="mt-2 text-xs leading-5 text-slate-600">
    本次结果统一归属于所选门店的竞争圈；酒店ID缺失或不一致会提示但不阻断查询。
    携程返回 hotelId 与该门店配置的携程 hotelId 一致时可确认本店身份；酒店名称和备注名不参与判定，只有明确的本店跨门店冲突才停止入库。
</div>
```

- [ ] **Step 4: 修正临时 Cookie 测试切片边界**

将主手工请求清洗器的结束标记从 `sanitizeMeituanManualFetchRequestData` 改为 `sanitizeCtripTemporaryCookieRequestData`，使“主路径不携带明文”与“单次临时 Cookie 路径”分别验证。

- [ ] **Step 5: 修正 rank 请求构造契约**

对 `rank` 单独断言：保存配置路径把 `normalizedConfigId` 写入 `body.config_id`，单次临时 Cookie 路径只允许 `body.cookies` 且 `auto_save=false`；其余 traffic/overview/ads/cookie-api 继续使用 `assertLocatorOnly()`。

- [ ] **Step 6: 让项目文案测试读取真实模板源**

在 `project_copy_logic.test.mjs` 中使用 `readFrontendContractSource()` 代替只读取 `public/index.html`，避免 runtime-only 空壳被误判为文案缺失。

- [ ] **Step 7: 重建预编译模板并验证聚焦测试**

Run:

```powershell
npm.cmd run build:frontend-template
npm.cmd run verify:frontend-template
& 'C:\xampp\php\php.exe' 'vendor\bin\phpunit' --colors=never tests\OnlineDataTest.php --filter 'testCtripProfileCaptureFieldDefaultsCoverLatestTaskFieldsAndGaps'
node --test tests/automation/ctrip_competition_circle_closure.test.mjs tests/automation/ctrip_cookie_only_identity_gate.test.mjs tests/automation/ctrip_endpoint_evidence_ui.test.mjs tests/automation/project_copy_logic.test.mjs
```

Expected: 模板 hash 更新，1 个 PHP 与相关 Node 测试全部通过。

---

### Task 5: 全量验证与浏览器验收

**Files:**
- Verify: `app/`
- Verify: `public/`
- Verify: `resources/frontend/`
- Verify: `scripts/`
- Verify: `tests/`

- [ ] **Step 1: 运行原始 12 个失败项**

Run:

```powershell
& 'C:\xampp\php\php.exe' 'vendor\bin\phpunit' --colors=never tests\OnlineDataTest.php --filter 'testCtripProfileCaptureFieldDefaultsCoverLatestTaskFieldsAndGaps|testAutoFetchUsesReadyBrowserProfileSourcesWithoutSameDayProof'
& 'C:\xampp\php\php.exe' 'vendor\bin\phpunit' --colors=never tests\OtaCredentialMigrationServiceTest.php --filter 'testExecuteMigratesOnlyVerifiedBindingsRemovesPlaintextAndIsIdempotent'
& 'C:\xampp\php\php.exe' 'vendor\bin\phpunit' --colors=never tests\OtaProfileSessionProofConsumerTest.php --filter 'testAutoFetchCollectableSourcesUseReusableProofAndSupportBothProfileMethods|testDirectBrowserAutoFetchAttemptsProfileDirectoryWithoutCurrentProof'
node --test tests/automation/business_chain_status_report.test.mjs tests/automation/ctrip_competition_circle_closure.test.mjs tests/automation/ctrip_cookie_only_identity_gate.test.mjs tests/automation/ctrip_endpoint_evidence_ui.test.mjs tests/automation/p0_ota_field_loop_runtime.test.mjs tests/automation/project_copy_logic.test.mjs
```

Expected: 5 个 PHP 与 24 个 Node 聚焦测试全部通过。

- [ ] **Step 2: 运行全量 PHPUnit 与 Node 自动化测试**

Run:

```powershell
& 'C:\xampp\php\php.exe' 'vendor\bin\phpunit' --colors=never
node --test tests/automation/*.test.mjs
```

Expected: 0 failures；若 Node 版本不展开 glob，则用 PowerShell 枚举路径后传给 `node --test`。

- [ ] **Step 3: 运行前端与关键业务门禁**

Run:

```powershell
npm.cmd run verify:frontend-template
npm.cmd run verify:public-entry
npm.cmd run verify:e2e-contracts
npm.cmd run verify:phase1-ota-loop
```

Expected: 前三项退出码 0；`verify:phase1-ota-loop` 的契约测试退出码 0，但内部 P0 业务状态允许真实 `incomplete`。

- [ ] **Step 4: 验证健康接口与实际页面**

Run:

```powershell
Invoke-WebRequest -UseBasicParsing http://127.0.0.1:8080/api/health | Select-Object StatusCode,Content
```

Expected: HTTP 200。随后刷新本地页面，确认无启动错误；若缺少有效登录，不声称已完成登录后竞争圈页面的交互验收。

- [ ] **Step 5: 检查最终差异**

Run:

```powershell
git diff --check
git status --short
git diff --stat
```

Expected: 无空白错误，仅包含本计划范围内文件。

# 员工与门店管理效率工作台实施计划

**目标：** 在不修改高级 AI 工具箱、Agent 页面/控制器/路由的前提下，完成员工管理和门店管理的自适应、数据完整性与高频操作闭环。

**范围：** `app/controller/User.php`、`app/controller/Hotel.php`、`route/app.php`、`public/index.html`、`public/user-admin-static.js`、相关自动化测试。明确不修改 `app/controller/Agent.php`、Agent 模型/服务、`agent-center` 页面或导航。

**验收：** 1024px 员工页不出现横向挤压；门店范围单行省略且可查看完整值；复制账号不重置密码；门店分配可检索和只看已选；列表筛选/排序不漏副门店授权；批量状态变更有预览确认；门店 OTA 卡片默认紧凑并能直接执行“下一步”；定向测试和本地页面检查通过。

## 任务 1：测试保护与后端查询正确性

- 扩展 `tests/automation/ota_admin_management_closure.test.mjs`，覆盖员工平板卡片、无损复制、分配检索、批量操作。
- 扩展 `tests/automation/hotel_management_responsive_layout.test.mjs`，覆盖紧凑 OTA 行、问题队列和可点击下一步。
- 新增 PHP 控制器契约测试，覆盖副门店过滤、排序白名单和批量状态预览/执行。
- 修改 `app/controller/User.php`、`app/controller/Hotel.php` 和 `route/app.php`，实现最小接口闭环。

## 任务 2：员工管理工作台

- 修改 `public/index.html`：平板宽度使用卡片；工具栏/表头保持上下文；门店范围保持省略展示。
- 增加门店分配搜索、只看已选、全选当前结果。
- 拆分“复制账号”与“重置密码并复制”，避免普通复制动作改密码。
- 增加勾选与批量启用/暂停，执行前展示影响数量和账号。

## 任务 3：门店管理工作台

- 修改 `public/index.html`：桌面 OTA 详情默认紧凑，按门店展开；窄屏继续使用卡片布局。
- 增加问题队列快捷筛选（未绑定、登录失效、未采集、未配置负责人）。
- 将平台卡片中的“下一步”改为可点击动作，复用现有路由函数。
- 增加门店批量启用/停用预览。

## 任务 4：验证

- `C:\xampp\php\php.exe -l app/controller/User.php`
- `C:\xampp\php\php.exe -l app/controller/Hotel.php`
- `C:\xampp\php\php.exe -l route/app.php`
- `node --test tests/automation/ota_admin_management_closure.test.mjs`
- `node --test tests/automation/hotel_management_responsive_layout.test.mjs`
- `node --test tests/automation/access_tier_permissions.test.mjs`
- 刷新 `http://127.0.0.1:8080/`，分别检查员工管理与门店管理的桌面/平板布局、弹窗和无数据破坏的交互。

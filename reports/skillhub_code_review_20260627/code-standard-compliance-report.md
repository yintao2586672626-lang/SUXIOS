# 规范合规报告

- 审查日期：2026-06-27
- Skill：`project-code-standard`
- 处理策略：只审查和报告；未对业务代码执行自动修复，避免在大范围脏工作区中混入无关格式改动。

## 合规结论

当前已检查范围未发现需要立即自动修复的格式问题。类型检查按项目脚本判断为无 TypeScript 输入并跳过。

## 检查项

| 检查项 | 结果 | 说明 |
| --- | --- | --- |
| SkillHub 安装 | 通过 | `skillhub 2026.6.27` |
| 专家包 Skill | 通过 | 6 个 Skill 均已安装 |
| Git diff 空白检查 | 通过 | 未发现可见空白/冲突标记问题 |
| PHP 语法 | 通过 | 已对变更 PHP 和新增 PHP 入口做语法检查 |
| JS/MJS 语法 | 通过 | 已对变更 JS/MJS 和新增自动化脚本做 `node --check` |
| 非安全审查脚本 | 通过 | `npm.cmd run review:non-security` |
| TypeScript | 跳过 | `npm.cmd run type-check` 输出无 TypeScript 输入 |
| Composer 格式/审计 | 未验证 | 当前环境没有 `composer` 或 `composer.phar` |

## 自动修复记录

- 未执行自动修复。
- 原因：
  - 当前工作区已有大量业务变更，审查任务不应引入额外格式 churn。
  - 已执行的空白、语法、非安全审查没有返回必须修复项。

## 命名与结构观察

- 新增投决模块命名基本清晰：`InvestmentDecision`、`InvestmentDecisionSupportService`、`P0OtaDownstreamGateService`。
- 新增浏览器辅助导入命名与现有 OTA 术语基本一致：`OtaBrowserAssistImportService`、`normalize_ota_browser_assist_capture`。
- 需要关注的结构风险是 `public/index.html` 继续承载过多页面状态和行为，后续应按模块拆出静态脚本。

## 建议执行顺序

1. 先修复 `after_login_sync.failed` toast 类型问题。
2. 为该状态补一条前端静态契约测试。
3. 后续单独开小任务拆分 `public/index.html` 中投决和 Profile 登录状态逻辑。
4. 在可用 Composer 环境补跑 `composer audit` 或等价依赖审计。

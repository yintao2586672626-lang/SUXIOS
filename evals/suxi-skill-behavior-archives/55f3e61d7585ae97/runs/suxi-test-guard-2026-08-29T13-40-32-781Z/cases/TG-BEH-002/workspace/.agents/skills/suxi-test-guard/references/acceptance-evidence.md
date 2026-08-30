# 验收证据合同

## 最小验收卡

默认作为内部执行合同，不强制用户阅读表单：

```text
test_basis:
scope_in / scope_out:
user_visible_claim:
deterministic_oracle:
highest_risk_path:
target / environment / data:
planned_checks:
evidence_refs:
status:
residual_unknown:
```

- `test_basis` 优先级：用户当前明确要求 → 可重现行为与自动契约 → 已批准规范/样例 → 当前文档 → 推断。
- 来源冲突时保留冲突和影响范围；没有确定性 oracle 的项标 `NEEDS DECISION` 或 `threshold_pending`。
- 新增数值门槛必须回指需求、SLA、预算、测量模型或用户已批准基线；“常见做法”不是宿析OS当前门槛。

## 用例与风险

- 每条用例只验证一个可判定行为，要求精确时用精确值，容差只在有来源时使用。
- 优先保护租户/酒店隔离、OTA 身份与业务日期、保存回读、指标口径、鉴权、审批和不可逆写入。
- 不因清单存在就强制性能、并发、压力、迁移或可用性测试；没有对应风险时写 `N/A` 和理由。
- 正式验收需要稳定 ID、风险/要求映射和预期证据；日常修复只保留能证明当前主张的最小记录。

## 执行状态

| 状态 | 判定 |
| --- | --- |
| `PASS` | 已对指定目标与环境执行，并观察到 oracle 要求的结果 |
| `FAIL` | 已执行，产品行为违反 oracle |
| `BLOCKED` | 缺环境、数据、依赖、授权或可判定性，无法形成有效产品结论 |
| `NOT RUN` | 在范围内但本次有意未执行；必须记录理由 |
| `N/A` | 当前风险不需要该检查；必须记录理由 |

优先级：任一可信 oracle 失败时优先 `FAIL`；没有产品失败证据但证据不足时为 `BLOCKED`。间歇失败不因后续一次通过自动消失：先判断失败运行是否对产品 oracle 的有效观察；有效违约为 `FAIL`，环境或测试基础设施不确定导致无法判断产品时为 `BLOCKED`。

范围内检查只是未尝试、且没有已知缺失前置时标 `NOT RUN`；因环境、数据、依赖、授权或 oracle 缺失而无法执行/判断时标 `BLOCKED`。不使用无定义的“未验证”替代两者。只知道某次失败、某次通过，但未能证明失败运行是有效产品观察时，汇总产品结论先为 `BLOCKED`，每次运行仍保留各自结果。

## 基线与 fixture

- 实现失败不是改预期结果的理由。
- 需求真实变化时，先记录新旧语义、影响用例和批准状态，再修订基线。
- 测试代码本身错误可以修复，但必须说明为什么不改变产品 oracle，并在修复后重跑原失败。
- 已批准 fixture、golden file 或 snapshot 不得为适配当前输出而机械刷新。

## 来源与许可边界

- 参考来源：`https://github.com/walterfan/lazy-rabbit-skills`
- 锁定提交：`284971cdd2666878d13716af8f26f51521cd915c`
- 取得时间：`2026-08-29 Asia/Shanghai`
- `skills/qa-acceptance-harness/SKILL.md`
  - SHA-256: `a7f2333ed2c961d0e9500a44b39126fc7edef203d49e7d11bef2fa9a7b564d9b`
  - 许可：`CC-BY-NC-ND-4.0`
- `references/atc-patterns.md`
  - SHA-256: `eee8bc4941cd9fcea9f6e87c3aaa2bea27df836c98bc910567b9251b0427a393`
- `references/harness-model.md`
  - SHA-256: `8b720712852b78e5012fc89661470a0de80d8504cd54e1b36306606c5fda9a43`
- `references/hld-guide.md`
  - SHA-256: `8320b099f04bb5e6a5d532b9dcf3c6cf782cde25cb50a9bb0314128c0bd205a8`

由于来源 Skill 声明“非商业、禁止演绎”，原文、资产模板和工作流保持 `reference_only`；宿析OS没有复制其模板或提示词。本文是基于宿析现有执行状态合同、数据真实性边界和通用 QA 概念独立编写的本地适配；不声称外部 Skill 已正式吸纳或获得商业复用授权。

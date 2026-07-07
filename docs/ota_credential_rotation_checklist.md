# OTA 凭证轮换与备份清理清单

更新时间：2026-05-30

## 当前问题

本地 `database/backups/*.sql` 中命中 `usertoken`、`usersign`、`cookies` 等 OTA 凭证形态字段。即使这些文件未被 Git 跟踪、也已被发布包规则排除，只要本地备份仍保留真实凭证，就不能视为安全闭环完成。

## 关闭条件

| 项目 | 要求 | 验收证据 |
|---|---|---|
| 凭证轮换 | 对携程和美团命中的真实 OTA Cookie/Token/UserSign 执行失效或重新授权 | 轮换日期、平台覆盖（携程、美团）、门店范围、执行人、复核人 |
| 本地备份清理 | 删除、加密归档或脱敏含凭证备份 | 清理路径、清理方式、复查时间 |
| 发布包排除 | `.gitignore` 与 `.gitattributes` 排除备份、采集 profile、采集报告、截图资产 | `npm run review:release-readiness` 相关 pass |
| Git 跟踪检查 | 备份文件未进入 Git | `git ls-files database/backups` 无输出 |
| 复扫 | 本地备份目录不再命中凭证形态字段，或已确认仅为脱敏样例 | `npm run review:release-ota-credentials` 与 `npm run review:release-readiness` 不再报该项失败 |

## 不允许

- 将真实 Cookie、Token、UserSign、账号密码、订单明细粘贴进文档、日志或 PR。
- 用“已加入 .gitignore”替代凭证轮换。
- 用空备份、假成功或兜底逻辑隐藏仍存在的凭证形态字段。

## 记录模板

```markdown
## OTA 凭证轮换记录

- 日期：
- 平台：携程 / 美团 / 其他
- 门店或账号范围：
- 处理动作：失效 / 重新授权 / 删除备份 / 加密归档 / 脱敏
- 执行人：
- 复核人：
- 复扫命令：
- 复扫结果：
- 备注：
```

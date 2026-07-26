# SUXIOS Project State Entry

本文件是稳定入口，不保存任何分支、HEAD、工作区、`.git/index.lock`、模块数量或 PR 瞬时值。

## 使用方式

1. 在 `HOTEL/` 运行 `npm run state:refresh`。
2. 读取机器生成且不进入 Git 的 `vault/current-state.md`。
3. 需要确认快照仍匹配现场时，运行 `npm run state:check`。

## 边界

- 当前本地仓库事实：`vault/current-state.md`。
- 历史验证事实：`vault/project-history.md`。
- 发布问题及验收命令：`docs/release_issue_register.md`。
- 实时 OTA、数据库、服务、外部 PR/CI 与发布就绪必须分别现场验证，不能从历史文档推断。

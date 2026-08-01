---
name: suxi-yingdao-rpa-reference
description: 用于宿析OS在用户提到影刀、影刀RPA、影刀帮助中心、快速入门、功能/指令/接口/管理/专题文档、常见问题、开放API或解决方案，或明确希望借鉴影刀自动化方法时，检索本地完整公开帮助中心目录并按需读取单条官方文档，将可见方法映射为宿析可验证实现。不要用于普通网页抓取、普通浏览器操作或未指向影刀的自动化任务。
---

# 影刀 RPA 帮助中心参考

## 目标

把影刀公开 RPA 帮助中心作为任务级参考，而不是复制整套产品。先从本地完整目录定位最相关的教程、说明、指令、接口、常见问题或解决方案，再低频读取少量官方页面，最后按宿析现有技术栈独立实现并验证。

本 Skill 只保存标题、栏目路径、文档 ID 和官方链接，不保存影刀完整正文、图片、HTML 或示例代码。整个帮助中心的栏目数量见 [help-center-map.md](references/help-center-map.md)，指令专用目录见 [category-map.md](references/category-map.md)，来源时间和许可边界见 [source-inventory.md](references/source-inventory.md)。

## 使用流程

1. 先检索本地目录：

```powershell
node .agents/skills/suxi-yingdao-rpa-reference/scripts/search-catalog.mjs "网页翻页抓取" --limit=12
node .agents/skills/suxi-yingdao-rpa-reference/scripts/search-catalog.mjs "影刀开放API" --scope=help-center
node .agents/skills/suxi-yingdao-rpa-reference/scripts/search-catalog.mjs "未找到元素" --section=常见问题
```

不带 `--scope` 时保持旧行为，只检索指令子目录；检索整个帮助中心时显式使用 `--scope=help-center`，传 `--section` 时会自动切到帮助中心并限定栏目。

2. 从结果中只选择完成当前任务所需的 1–3 篇文档。需要正文证据时按 ID 读取单页的有界提要：

```powershell
node .agents/skills/suxi-yingdao-rpa-reference/scripts/fetch-doc-outline.mjs 710435141686378496
```

3. 将证据整理为：

```text
官方来源 / 当前核对时间 / 可见事实 / 推断与未知 /
触发条件 / 输入 / 核心动作 / 输出 / 副作用 / 失败状态 /
宿析现有入口 / 独立实现方式 / 验证样例
```

4. 只回答使用方法时，给出原创概括和官方链接。用户要求做进宿析时，同时使用 `suxi-capability-absorption`，从真实业务入口完成最小闭环；不能把“找到了影刀文档”当成功能完成。

## 路由规则

- 影刀概述、快速入门、功能文档：用于理解产品定位、基础操作和公开功能合同，不能据此声称宿析拥有影刀运行环境。
- 条件、循环、等待、元素：用于控制流、重试、终止条件和页面状态判断。
- 网页、相似元素：用于授权页面的元素定位、列表、翻页、懒加载和弹窗处理。
- 数据表格、Excel/WPS、数据处理：用于结构化转换、文件表格和批量数据任务。
- 流程/应用、AI、网络、工作队列：用于编排、模型调用、HTTP 请求和任务分发。
- 桌面、鼠标键盘、对话框、操作系统：仅在用户明确要求操作本机应用时参考。
- 手机、自定义指令、其他：默认视为参考能力，不能因文档存在就声称当前环境可运行。
- 接口文档、开放API：先核对公开接口合同、鉴权、分页、限流和错误语义；未经授权不调用账号接口或写入接口。
- 常见问题：用于诊断线索和失败样例，不能把帮助文章直接当成当前故障根因。
- 管理文档：涉及组织、成员、权限和企业管理时，只提取公开合同，不臆造当前账号权限。
- 专题文档、解决方案：用于组合任务路径与边界，不机械复刻整套产品或隐藏后台。

更具体的宿析执行面与权限边界见 [execution-mapping.md](references/execution-mapping.md)。

## 真实性与安全

- 只访问公开、无鉴权的影刀帮助文档；不绕过登录、验证码、机器人限制或权限控制。
- 不运行、安装或照搬影刀页面中的脚本、插件、代码和外部依赖。
- 不批量下载或镜像正文。一次只读取当前任务需要的少量页面，并保留官方链接。
- 本地完整目录只证明“可发现”；读取当前官方页后才能标记 `observed`，明确输入输出和失败合同后才能标记 `understood`。
- 影刀能力存在不等于宿析已有运行支持。桌面、手机、Excel、外部平台写入和账号操作必须分别验证。
- OTA 页面证据只用于对应渠道；平台、酒店绑定、日期、来源时间和质量状态不完整时不得进入收益或 AI 决策。

## 更新与验证

影刀帮助中心目录发生变化或本地清单过期时，只刷新公开菜单元数据：

```powershell
node .agents/skills/suxi-yingdao-rpa-reference/scripts/refresh-catalog.mjs
node .agents/skills/suxi-yingdao-rpa-reference/scripts/verify-reference-catalog.mjs
```

刷新脚本同时生成帮助中心总索引和向后兼容的指令专用索引，只请求官方菜单树与 robots.txt，不抓取全部正文。单页正文提要只通过 `fetch-doc-outline.mjs` 临时输出，不写入项目。

# 携程定价 XLSX 输入能力吸纳卡

## 结果

宿析OS 的 Revenue Agent 现可把独立 `.xlsx` 文件转换为原有携程定价 JSON 输入，并继续复用既有的校验、事务保存、来源元数据、精确回读、AI 待审核和人工审核边界。

工作簿解析成功只代表“文件可读”，不代表数据是真实经营事实。只有酒店、日期、携程渠道范围、必填字段和证据文本全部通过原有合同后，才允许生成 JSON；默认不写数据库、不生成建议、不写 OTA。

## 来源与兼容性

- 插件：OpenAI Spreadsheets
- 本地捆绑版本：`26.730.11710`
- Skill：`Spreadsheets`
- 运行库：`@oai/artifact-tool`，本次验收环境版本 `2.8.6+`
- 作者：OpenAI
- 许可证：MIT
- 来源主页：<https://openai.com/>
- 本地来源目录：Codex primary runtime 的 `spreadsheets/26.730.11710`
- 兼容结论：独立文件使用 `Spreadsheets`；`excel-live-control` 只适用于 Excel 中已打开的工作簿和加载项会话，未接入服务端导入。
- 生产依赖：未新增 Composer 或 npm 运行依赖；服务端使用项目已启用的 PHP `ZipArchive` 与 `SimpleXML`。
- 权限：只读取用户明确提供的本地 `.xlsx`；转换阶段不访问网络、不写数据库、不写 OTA。
- 名称冲突：无 PHP 类名冲突；多工作表时要求业务表命名为 `pricing-input-intake`。

## 宿析OS 契约

入口命令：

```powershell
npm.cmd run build:revenue-ai-ctrip-pricing-input-from-xlsx -- `
  --xlsx-file=<path> `
  --output=<pricing-input-fillable.json> `
  --date=YYYY-MM-DD `
  --hotel-id=<system_hotel_id>
```

边界：

- 只接受 `.xlsx`，不接受旧 `.xls`、加密工作簿或超限压缩包。
- 优先读取 `pricing-input-intake`；仅有一个工作表时可读取该表；多表且无指定名称时拒绝。
- 复用 CSV 的 28 列业务合同，不另建 Excel 专属字段口径。
- Excel 日期序列转换为 `YYYY-MM-DD`；数值 `0` 保留为 `0`，不转成缺失。
- 公式和 Excel 错误单元格拒绝导入，避免把缓存公式值冒充人工确认事实。
- 每行 `business_date`、`hotel_id` 必须与命令目标一致；跨日期或跨酒店立即失败。
- 结构和业务校验通过后才写出 JSON；失败时返回工作表、行号、列名和修正提示，不写输出 JSON。
- 后续 `validate-only` 和 `dry-run` 均事务回滚；只有用户另行显式执行 `--execute=1` 才允许提交本地输入，仍不自动写 OTA。

## 验收证据

- PHPUnit：`8/8` 测试、`65` 断言通过；同时覆盖原 CSV 构建模式兼容性。
- 成功样例：官方表格运行时生成的真实 `.xlsx`，含 4 行输入；转换得到 1 个房型、1 条需求预测、1 条携程竞品价格，问题数为 0。
- 文件事实：工作表 `pricing-input-intake`、1900 日期系统、来源文件 SHA-256 已返回；日期序列 `46234` 被解析为 `2026-07-31`。
- 失败样例：缺少必填列、公式单元格、多表歧义、跨酒店、跨日期均被拒绝。
- 无写入预检：`database_written=false`、`auto_write_ota=false`。
- 事务链：`validate-only` 通过 8 项检查，房型、需求和竞品记录均完成临时保存及来源元数据验证，最终 `committed=false`、`rolled_back=true`。
- AI 边界：`dry-run` 生成 1 条待审核建议后回滚；`operation_intake_allowed=false`、`auto_write_ota=false`。
- 全范围旧门禁仍因当前检查把三源范围中的 `meituan` 视为违规而失败；其前后数据库范围指纹相同。该旧门禁问题未通过删除真实来源来掩盖。

## 性能监测评估

- 当前会话没有 Sentry、Datadog、OpenTelemetry 等可调用 MCP，未声称已接入线上监控。
- 本地 `/api/health` 返回 HTTP 200，应用和数据库检查为 `ok`；运行模式为 `development_fallback`，不代表生产就绪。
- `benchmark` Skill 的 Windows 浏览器组件已存在，但无头浏览器启动提权被系统额度限制拒绝；本轮未建立 FCP、LCP、资源体积基线。
- 后续触发条件：外部监控账号和数据处理边界明确，或浏览器测量权限恢复后，再建立页面性能基线与回归阈值；不得用静态估算代替实测。

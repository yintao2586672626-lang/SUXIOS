# PHPUnit 覆盖率缺口清单

生成时间：2026-05-18  
覆盖率驱动：PHPUnit 11.5.55 + Xdebug 3.5.1（仅 CLI 临时加载）  
覆盖范围：`app/controller`、`app/service`

## 当前覆盖率

| 指标 | 当前值 |
|---|---:|
| 测试数 | 47 |
| 断言数 | 953 |
| 方法覆盖 | 30 / 901（3.33%） |
| 行覆盖 | 840 / 16051（5.23%） |

报告文件：

- `tests/report/coverage-html/index.html`
- `tests/report/coverage.xml`

## 已补测试

| 文件 | 覆盖重点 |
|---|---|
| `tests/OperationAuditClassifierTest.php` | 操作审计路径分类、排除路径、手动记录路径 |
| `tests/OtaTrafficUrlNormalizerTest.php` | 携程流量 URL 默认值、参数补齐、版本号刷新、非法接口拒绝 |
| `tests/QuantSimulationServiceTest.php` | 量化测算输入归一、核心财务指标、风险分支 |
| `tests/ExpansionServiceTest.php` | 拓展评估、标杆模型、协同风险 |
| `tests/TransferDecisionServiceTest.php` | 转让定价、转让时机、看板汇总 |
| `tests/ControllerBaseResponseTest.php` | Base 控制器响应信封、分页、校验异常 |
| `tests/ControllerRouteContractTest.php` | 路由处理器与控制器方法可解析 |
| `tests/ServiceInventoryTest.php` | 服务类自动加载与公开行为约束 |
| `tests/AuthMiddlewareAuditTest.php` | 鉴权中间件审计参数脱敏、酒店 ID 解析 |

## 当前高覆盖模块

| 文件 | 行覆盖 |
|---|---:|
| `app/service/OtaTrafficUrlNormalizer.php` | 100.00% |
| `app/service/OperationAuditClassifier.php` | 94.83% |
| `app/service/ExpansionService.php` | 66.05% |
| `app/controller/Base.php` | 52.54% |
| `app/service/TransferDecisionService.php` | 46.88% |
| `app/service/QuantSimulationService.php` | 43.96% |

## 0% 覆盖优先缺口

| 优先级 | 文件 | 未覆盖行 | 补测建议 |
|---|---|---:|---|
| P0 | `app/controller/OnlineData.php` | 4308 | 先拆私有纯函数、列表聚合、OTA 数据归一，不直接触真实外部接口 |
| P0 | `app/controller/Agent.php` | 2579 | 先测模型选择、调试信息、报告 payload 构造，LLM 调用用 stub |
| P0 | `app/controller/DailyReport.php` | 1714 | 先测报表字段计算、旧数据 JSON 兼容、导入映射 |
| P1 | `app/service/OperationManagementService.php` | 650 | 补运营收入/房晚/可售房兜底提取与看板聚合 |
| P1 | `app/controller/StrategySimulation.php` | 543 | 补策略汇总、历史数据 fallback、非法输入 |
| P1 | `app/service/FeasibilityReportService.php` | 412 | 补投资输入归一、快照汇总、财务场景计算 |
| P1 | `app/service/OpeningService.php` | 312 | 补开业任务模板、风险等级、进度聚合 |
| P2 | `app/controller/AiConfig.php` | 303 | 补模型配置校验、默认模型、测试连接响应 |
| P2 | `app/service/LlmClient.php` | 241 | 补请求体构造、JSON schema 解析、异常兜底 |

## 下一步补齐顺序

1. `DailyReport`：优先覆盖收益指标公式，风险低、业务价值高。
2. `OperationManagementService`：覆盖运营看板下游口径，承接日报数据。
3. `FeasibilityReportService`：覆盖投资测算纯计算路径，避免触库和 LLM。
4. `OnlineData`：分批补 OTA 数据清洗、酒店合并、历史数据筛选。
5. `Agent`：用 LLM stub 补 AI 报告生成链路。

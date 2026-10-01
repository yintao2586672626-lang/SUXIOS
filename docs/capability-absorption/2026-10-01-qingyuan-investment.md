# 清远酒店投资工作簿学习处置与验收

结果：完成来源保全、深度拆分、125条公式重放、36组敏感性复算与八个知识主题的本地保存、精确回读和既有检索。完整分析见 [深度拆解与方法吸纳](../knowledge/qingyuan-investment-20261001/深度拆解与方法吸纳.md)。

状态：实现已修改（资料及参考导入脚本）｜验证聚焦通过｜Git未提交｜部署/现场不适用｜阻塞无（本次资料学习范围）。

## 来源与处置

来源是用户提供的《投资测算（清远酒店）.xlsx》，SHA256为 `49b5af7ae8655bb10a505b01da17da1db995314420ad6a45de7a90cac51f94f7`。文件保存的修改元数据为2022-04-26，无法证明当前市场价格、出租率、成本或项目身份。原件已保留且未修改；表内说明不是用户指令。

任务模式 `classify`；来源和可复用方法为 `store_only / reference_only`，完整经营预测/投资决策能力为 `absorption_candidate`、`understood`；限定公式与反例算术复现为 `reproduced`。知识入库不等于投资业务集成或经营事实验证。

## 知识入口与数量

| 本地单元ID | 片段ID | 主题 |
| ---: | ---: | --- |
| 89 | 946 | 清远投资表：来源与输入口径 |
| 90 | 947 | 清远投资表：收入爬坡与RevPAR |
| 91 | 948 | 清远投资表：成本租金及管理费 |
| 92 | 949 | 清远投资表：初始投资与折旧现金流 |
| 93 | 950 | 清远投资表：汇总对账与依赖检查 |
| 94 | 951 | 清远投资表：首次回本与失败状态 |
| 95 | 952 | 清远投资表：敏感性与保本线 |
| 96 | 953 | 清远投资表：尽调缺口与投资人台账边界 |

稳定键为 `global:qingyuan_investment:49b5af7ae865:<topic>`。通过现有 `knowledge_units / knowledge_chunks`、内容摘要与 `current_chunk_id` 保存，未另建事实或投资收支通道。每个主题以各自“清远投资表 + 主题名”查询均命中；来源引用非空。

初次导入八条均 `inserted`；再次导入八条均 `unchanged`；之后只读核验八条内容、元数据、摘要与关联均精确一致。10个保全文件指纹、32个参考门禁样例、八个跨酒店私有样本阻断均通过。导入脚本限定本地回环数据库，未向生产连接写入。

全部保持 `decision_safe=false`、`task_draft_safe=false`、`external_write_authorized=false`，来源情景不是当前酒店事实，不能自动建立运营任务、调价动作或投资人实收记录。

## 核心发现与证据上限

- 原表1.981875929年是累计税前利润代理；1.759272499年仅为加回折旧的税前现金代理，仍缺税款、融资、营运资金、后续资本支出、分配与到账日期。
- M23只扣一年折旧，使M25十年利润多303.417万元；逐年利润合计为3370.9999万元。该合计缺陷不直接改变逐年回收链。
- 第7年I22已填8%租金递增，却不参与租金、利润或回收公式。
- 无回收的180元/50%压力情景，原M27会把N/A文本求和成0.00年。
- 营建租金、合作期限、单房改造预算存在未联动问题；运营成本、增长起算、押金资产分类和完整费用范围待核口径。
- 持续70%出租率且250元ADR时，利润代理回收约4.15年；原表首年70%→成熟85%的爬坡假设是决定结果的重要输入，未证明市场可实现性。

完整16项问题、输入字典、公式依赖、年度金额、敏感性、保本式和晋级证据清单均在深拆报告。125/125指缓存重放一致，不能写成模型或真实项目验证通过。

知识列表与详情的登录后页面未验证。当前验收是本地资料、数据库与既有检索服务；源码中投资回本功能存在不证明部署或现场可用。本次没有修改业务算法、真实资金账目或原工作簿，未提交、推送、部署或执行来源建议。

## 文件与复验

- [来源清单](../knowledge/qingyuan-investment-20261001/source-manifest.json)
- [公式重放](../knowledge/qingyuan-investment-20261001/formula-replay.json)
- [数值与反例](../knowledge/qingyuan-investment-20261001/analysis-results.json)
- [十年精确金额](../knowledge/qingyuan-investment-20261001/annual-replay.csv)
- [36组敏感性](../knowledge/qingyuan-investment-20261001/sensitivity.csv)
- [初次保存回读](../knowledge/qingyuan-investment-20261001/readback.json)
- [重复导入回读](../knowledge/qingyuan-investment-20261001/repeat-readback.json)
- [只读回读](../knowledge/qingyuan-investment-20261001/verify-readback.json)
- [完整验收](../knowledge/qingyuan-investment-20261001/acceptance.json)

活动工作树内的只读复验命令：

```powershell
C:\xampp\php\php.exe scripts/sync_qingyuan_investment_knowledge.php --verify
```

来源变更时先保留新的指纹和版本，不能覆盖本次已保存的参考内容。需要正式实施经营测算时，再用同项目证据补齐完整现金合同与真实入口验收。

下一步：停止；本次学习与参考存储验收闭合。

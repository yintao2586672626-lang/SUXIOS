# 日周月报的字段来源、成熟窗口与不可兜底合同

来源：用户提供 hotel_auto_x 交付包。逐项代码行号和机制合同见 `output/absorption/20261006-hotel-deliveries/autox-review.md`。以下除广告纯函数外均是静态分析，未运行ETL、报表、调度或数据库。

## 能复用的结构

`core/collector.py:L20-29,L53-98` 用显式业务日期和门店/平台启动采集，返回结果与阶段化失败；但容器success不等于数据完整。`core/result.py:L53-140` 的空旧字典也可成功，不能采用为严格来源验证。

`pipeline/weekly_ota_source.py:L54-218` 的专用字段源按 store/platform/date/key 定位，并保存 source_name、quality、evidence。`pipeline/weekly_cell_audit.py:L341-375,L397-568,L821-908` 为每个单元格保存 value、source_table、source_fields、observed、used_field、fallback、status、reason。可学习字段级解释，先知道某个值从哪来、覆盖哪天，再生成报告。

`pipeline/ota_source_contract.py:L8-59` 区分昨天各来源的成熟窗口；未到期可pending而不是立即硬判缺口。源码时间窗只是该包配置，未验证为平台SLA；采用时需真实来源、上海时区和逐任务节奏。非昨天日期直接due、形状日期非日历校验等反例应先阻断。成熟时间到了不证明采集成功。

## 不能采用的政策

1. `pipeline/etl.py:L163-275` 缺 stat_date默认当天、新零值保留已有非零值，会把“日期缺失/真实零/采集无值”混在一起。旧值必须保留原日期与stale状态，不能当当次事实。
2. `weekly_ota_source.py:L167-218` 同时放行 ok、verified、assumed_zero，quality默认ok；不能当宿析严格真值门。源不可用应null，而不是推断零。
3. `weekly_template_report.py:L514-554,L568-596,L697-765`、`weekly_data_report.py:L404-454,L691-729` 存在专用源缺失后日报fallback；日报部分字段由PMS拥有。文档“OTA不以PMS兜底”与当前代码不一致，但本轮没有某次生产报表混用证据。OTA/PMS必须按各自范围显示，不彼此替代。
4. 新证据为空可能保留旧evidence；updated_at更新不等于新采集证明，changed计数不比较所有证据。字段源与日报分事务，外层warning或成功日志不证明两边保存和精确回读都完成。
5. 单元格fallback布尔有不足，不能仅凭它认定来源未降级。零间夜推零收入、无广告推零等政策须有同范围完整性证据，否则只能作为待核对假设。

## 调度参考边界

`scheduler/engine.py:L35-48,L160-229,L382-408,L2207-2245` 有 coalesce、同任务单实例、运行/失败/跳过摘要；`core/runtime.py:L66-128,L229-322` 与 `core/resource_lock.py:L37-113` 有锁与busy反馈。进程内防重、文件先查后写和Linux fcntl不能证明Windows跨进程幂等；采集success直接quality=ok也不能证明保存回读。保留方法与反例，不复制部署架构。

宿析落点：已有采集质量判断、OTA严格历史与报告证据服务继续承接字段来源/缺失原因；字段审计可作为下一次具体功能需求的候选。本轮没有业务代码写入、日报修正或采集运行。

五轮归纳：字段存在、有限输入、集合最新时间、多个语义分组、一次成功返回、一次保存成功是不同证据，不能彼此替代。字段级使用应同时保留身份、业务日期、观测时间、口径、覆盖、有限的派生值、来源质量、版本与独立回读；失败恢复水位只能前进到已确认结果。上述是本轮行为合同的综合方法，不声称来源包已有全部实现。

处置：参考 `store_only`、整个能力 `absorption_candidate / understood`。来源日期、金额、阈值、窗口与经营值不转成当前酒店默认。

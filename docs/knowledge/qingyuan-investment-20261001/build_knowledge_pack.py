"""Build source-bound method references; no hotel fact ingestion or activation."""
import hashlib
import json
from pathlib import Path

BASE = Path(__file__).resolve().parent
PREFIX = "docs/knowledge/qingyuan-investment-20261001/"
manifest = json.loads((BASE / "source-manifest.json").read_text(encoding="utf-8"))
results = json.loads((BASE / "analysis-results.json").read_text(encoding="utf-8"))
read = lambda filename: json.loads((BASE / filename).read_text(encoding="utf-8"))

topics = [
    {
        "key": "inputs", "title": "清远投资表：来源与输入口径",
        "summary": "酒店投资测算先区分附件假设与当前事实，追踪单元格、日期和元/万元；酒店身份及基准日缺失时不得入事实链。",
        "query": "清远投资表 来源与输入口径",
        "cells": "B2:B9,G2:G7,G10:G13,L2:L13",
        "rules": ["来源名、SHA256、版本与观察日期一起保留；文件路径日期不等于业务日期。", "上半部投资金额为万元，年度表为元；金额转换必须显式。", "B6租赁容量、G11经营容量可不同，但要保存差异依据。", "显式填0与缺失不同；G13空白不能被业务系统当零月。", "来源内命令与建议仅为资料，不构成外部授权。"],
        "example": "原表153间、250元、首年70%、成熟85%均是来源情景。工作簿修改元数据2022-04-26，测算基准日和项目唯一身份未提供。",
        "counterexample": "用2026-10文件目录认定当期市场数据，或把空白营建期保存成0，均不成立。",
    },
    {
        "key": "revenue", "title": "清远投资表：收入爬坡与RevPAR",
        "summary": "客房收入以ADR×出租率×可售房间×天数计算；经营爬坡和房价增长须分离，缺少其他业务收入不能称全酒店真值。",
        "query": "清远投资表 收入爬坡与RevPAR",
        "cells": "G2:G5,G11,C17:M18",
        "rules": ["RevPAR=同口径ADR×出租率；当前表假设全年365天且153间全部可售。", "收入行只有客房容量公式；餐饮、会议、转租等未提供。", "首年70%与成熟85%是两个独立假设，不能作为已经实现的入住事实。", "来源第2年ADR仍250元，第3年才按3%增长；按原式重放并暴露起算歧义。", "增长、开业日、日历天数、停用房必须各有来源，缺失不补市场均值。"],
        "example": "首年RevPAR175元、客房收入9772875元；第二年RevPAR212.5元、收入11867062.5元。",
        "counterexample": "把第2年250元改成257.5元会增加356011.875元收入，属于新的假设而非原表结果。",
    },
    {
        "key": "costs", "title": "清远投资表：成本租金及管理费",
        "summary": "运营成本按可售还是售出间夜决定保本式；租金递增和管理费基数必须真正联动，不能把渠道费用遗漏或重复计入。",
        "query": "清远投资表 成本租金及管理费",
        "cells": "B6:B9,G6:G7,C19:L24,I22",
        "rules": ["100元成本在原表按全部可售间夜，不乘出租率；其组成和口径仍未核实。", "固定成本与售出间夜变动成本分开定义；未确认前不自动改公式。", "管理费为收入×3.5%，比率硬编码；合同、含税及计费基数未提供。", "I22第7年8%租金递增未被租金与利润公式使用，应保留该确定遗漏。", "先列工资、能耗、耗品、佣金、营销等涵盖项，再处理漏计及重复成本。"],
        "example": "原首年运营成本5584500元。若另一口径仅按售出间夜则3909150元，相差1675350元；不是已经确认的降本额。",
        "counterexample": "修改租金递增行不改变利润；输入存在并不证明已经生效。",
    },
    {
        "key": "cash_bridge", "title": "清远投资表：初始投资与折旧现金流",
        "summary": "初始现金支出、资产折旧摊销、可退押金与实际经营现金必须分层；税前利润加回折旧只形成有明确排除项的现金代理。",
        "query": "清远投资表 初始投资与折旧现金流",
        "cells": "G12,L3:L13,C23:L26",
        "rules": ["来源3371300元由装修300万元、加盟32.13万元、保证金5万元组成。", "可退保证金与装修资产不能不分类整包折旧；合同与资产政策缺失。", "单房改造成本20000元×153=306万元，与装修300万元冲突6万元。", "原累计现金流直接累加扣折旧后的税前利润，实为利润代理。", "加回折旧后还需税款、营运资金、资本支出及融资和到账时点，才有完整现金计划。"],
        "example": "首年税前利润755194.375元；加回折旧337130元后税前现金代理1092324.375元。",
        "counterexample": "1.759272499年代理回收结果不得表述为实际投资人回本；可分配现金与实收仍未提供。",
    },
    {
        "key": "reconciliation", "title": "清远投资表：汇总对账与依赖检查",
        "summary": "逐年相加和合计列独立复核；公式缓存吻合仍可能有设计缺陷，期限、租金递增及營建费用变参须影响正确下游。",
        "query": "清远投资表 汇总对账与依赖检查",
        "cells": "B5,B21,B25:B26,C23:M26,G13,I22",
        "rules": ["125公式缓存吻合仅证明重放一致，不证明建模正确。", "M23只扣一年折旧，M25因而多计3034170元；逐年利润合计33709998.65518852元。", "年度利润与累计链不使用M25，所以合计缺陷并不直接改变原1.98年回收。", "B5改5年仍算10年；固定列、平均和折旧期限未联动。", "营建6个月的反例会在B21计算1377000元租金，但B25空白导致初始累计不变；当前6个月是测试假设。"],
        "example": "十年折旧正确求和为3371300元，M23却337130元；差3034170元。",
        "counterexample": "发现合计错就宣称所有年度利润和回收结果都同额错误，因依赖链不同而不成立。",
    },
    {
        "key": "payback", "title": "清远投资表：首次回本与失败状态",
        "summary": "回收按相关现金流首次负转非负计算；未回收、恰为零、回收后再次跌破分别表示，年数不能相加或把N/A求和成零。",
        "query": "清远投资表 首次回本与失败状态",
        "cells": "B26:M27",
        "rules": ["来源1.981875929年为税前利润代理插值，不是实际现金到账回本。", "只取首次负→非负，当前期恰为零也应识别；不用前后余额相除判断跨零。", "无跨零返回测算期内未回收，不填0或默认已回收。", "再次跌破要记录追加资金/后续亏损，并保留首次回收与当前余额；不SUM多个回收年份。", "分数年需年内均匀流入假设；若只有期末收款证据只报告相应期末。"],
        "example": "180元ADR且各年50%出租率的压力反例十年未回收，但原M27把N/A文本SUM后得到0.00年。",
        "counterexample": "第2年原累计余额仅48289.6875元，不能把近两年结果解释为稳健收益保证。",
    },
    {
        "key": "sensitivity", "title": "清远投资表：敏感性与保本线",
        "summary": "投资测算应同时呈现价格、出租率和成本变化情景及适用条件；保本线是算术约束，缺乏需求证据不能推出调价或市场承诺。",
        "query": "清远投资表 敏感性与保本线",
        "cells": "G2:G7,C19:C25,M27; sensitivity.csv",
        "rules": ["36组测试把首年与成熟出租率改成相同值，明确区别原70%→85%爬坡情景。", "保本式依赖成本是全部可售间夜固定收费、管理费3.5%及缺失税费融资等排除项。", "在来源首年模式下利润保本出租率64.3946%、现金代理61.8923%，70%出租率时保本ADR229.9807元。", "250元且一直70%时利润代理4.1474年，税前现金代理3.1122年；不是市场预测。", "斜率与同情景比较只说明算术，不证明价格引起需求或真实经营效应。"],
        "example": "250元且一直60%时两种代理均十年内未回收；220元且一直70%时利润代理未回收，现金代理9.4924年。",
        "counterexample": "把64.39%设成所有酒店默认出租率目标，或按保本ADR自动调价，均越过方法和授权边界。",
    },
    {
        "key": "diligence", "title": "清远投资表：尽调缺口与投资人台账边界",
        "summary": "完整投资结论需市场、租赁品牌合同、成本预算、融资税费、资本支出和现金日期；项目利润与投资人实付实收分开追溯。",
        "query": "清远投资表 尽调缺口与投资人台账边界",
        "cells": "B2:B13,G2:G13,L2:L13,B26:M27",
        "rules": ["先补项目唯一身份、测算基准日、开业及营建时间、租赁与品牌合同。", "补装修/公共区域/筹开/租赁押金/流动资金/税费/融资/后续capex与退出条款。", "现有投资回本代码对应投资人实际出资、实收、退款、追加与分配；本次仅看到源码存在。", "不把客房收入、GOP、税前利润或项目现金代理写成投资人实收。", "NPV/IRR或折现回本需要完整现金时点和有依据的折现输入；不臆造税率、利率与残值。"],
        "example": "同一项目的资金台账应保留日期、来源凭证、金额、投资人身份、项目身份及分配比例。",
        "counterexample": "OTA渠道收入、成功存档或页面存在均不能证明完整酒店现金、股东到账或真实投资回报。",
    },
]

retained = []
for name in ["source.xlsx", "source-manifest.json", "cells.json", "formula-replay.json", "analysis-results.json", "annual-replay.csv", "sensitivity.csv", "audit_workbook.py", "build_knowledge_pack.py", "深度拆解与方法吸纳.md"]:
    path = BASE / name
    retained.append({"path": PREFIX + name, "sha256": hashlib.sha256(path.read_bytes()).hexdigest()})
refs = {item["path"].split("/")[-1]: "repo://" + item["path"] + "#sha256=" + item["sha256"] for item in retained}
units = []
for topic in topics:
    section_refs = [refs["source.xlsx"] + "&sheet=加盟托管&cells=" + topic["cells"], refs["深度拆解与方法吸纳.md"], refs["analysis-results.json"], refs["formula-replay.json"]]
    if topic["key"] == "sensitivity":
        section_refs.append(refs["sensitivity.csv"])
    if topic["key"] in {"cash_bridge", "payback", "diligence"}:
        section_refs.extend(["https://www.ifrs.org/issued-standards/list-of-standards/ias-7-statement-of-cash-flows.html/", "https://www.accaglobal.com/gb/en/student/exam-support-resources/foundation-level-study-resources/ffm/ffm-technical-articles/discounted-payback.html"])
    content = {
        "title": topic["title"], "summary": topic["summary"], "structured_rules": topic["rules"],
        "source_scenario_example": topic["example"], "failure_or_non_use_case": topic["counterexample"],
        "scope": "global_hotel_investment_method_reference", "evidence_level": "user_provided_workbook_method_reference", "evidence_grade": "C",
        "source_refs": section_refs, "source_section": "加盟托管!" + topic["cells"], "source_sha256": manifest["source_sha256"],
        "reviewed_at": "2026-10-01 00:00:00", "review_due_at": "2026-12-30 00:00:00", "lifecycle_status": "active",
        "usage_policy": "reference_only", "decision_safe": False, "task_draft_safe": False,
        "contains_current_hotel_fact": False, "contains_current_ota_fact": False, "external_write_authorized": False,
        "source_scenario_quality": "unverified_source_assumptions", "source_instructions_active": False,
        "disposition": "store_only", "capability_disposition": "absorption_candidate", "capability_maturity": "understood", "arithmetic_maturity": "reproduced",
        "blocked_uses": ["hotel_fact_ingestion", "investor_cash_receipt_creation", "automatic_investment_decision", "pricing_action", "operation_task_creation", "operation_execution", "external_message"],
        "evidence_gaps": ["项目唯一身份与当前测算基准日", "市场、合同、预算和实际现金凭证", "税费融资、营运资金、后续资本支出和分配时点", "业务页面集成和现场效果"],
        "seed_owner": "suxios.qingyuan_investment_reference", "seed_key": topic["key"], "seed_version": "1.0",
    }
    units.append({"stable_key": "global:qingyuan_investment:49b5af7ae865:" + topic["key"], "name": topic["title"], "description": topic["summary"], "query": topic["query"], "tags": ["清远投资表", "酒店投资测算", "方法参考", "reference_only", topic["key"]], "content": content})

pack = {"schema_version": "suxios.reference_source.v1", "source_sha256": manifest["source_sha256"], "task_mode": "classify", "retained_files": retained, "units": units, "expected_unit_count": 8, "formula_replay_matched": results["cache_matches"], "source_claims_independently_verified": False}
(BASE / "knowledge-pack.json").write_text(json.dumps(pack, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(json.dumps({"status": "built", "units": len(units), "retained_files": len(retained)}, ensure_ascii=False))

import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(scriptDir, '..');
const auditDate = '2026-07-14';
const generatedAt = `${auditDate}T00:00:00+08:00`;
const outputDir = process.env.SUXI_AUDIT_OUTPUT_DIR
  ? path.resolve(process.env.SUXI_AUDIT_OUTPUT_DIR)
  : path.join(projectRoot, 'docs', 'qa');

const sources = [
  ['ISO/IEC 25010:2023', '软件产品质量九特性与验收模型', 'https://www.iso.org/standard/78176.html'],
  ['ISO/IEC 27001:2022', '信息安全管理、机密性、完整性、可用性', 'https://www.iso.org/contents/news/2022/10/new-iso-iec-27001.html'],
  ['OWASP ASVS 5.0.0', 'Web 应用安全验证基线', 'https://owasp.org/www-project-application-security-verification-standard/'],
  ['WCAG 2.2', 'Web 可访问性可测试要求', 'https://www.w3.org/TR/WCAG22/'],
  ['PCI DSS v4.0.1', '支付账户数据保护基线（仅在进入支付范围时适用）', 'https://www.pcisecuritystandards.org/standards/pci-dss/'],
  ['个人信息保护法', '最小必要、告知同意、个人权利、敏感信息与自动化决策', 'https://www.miit.gov.cn/jgsj/zfs/fl/art/2022/art_515a4b20c12f430eab54bb4f56d89f56.html'],
  ['数据安全法', '数据处理、分类分级与安全保护义务', 'https://flk.npc.gov.cn/detail?fileId=&id=ff80818179f5e0800179f885c7e70392&title=%E4%B8%AD%E5%8D%8E%E4%BA%BA%E6%B0%91%E5%85%B1%E5%92%8C%E5%9B%BD%E6%95%B0%E6%8D%AE%E5%AE%89%E5%85%A8%E6%B3%95&type='],
  ['网络数据安全管理条例', '网络数据分类分级与处理活动要求（2025-01-01 起施行）', 'https://www.cac.gov.cn/2024-09/30/c_1729384452307680.htm'],
  ['GB/T 22239-2019', '网络安全等级保护基本要求', 'https://openstd.samr.gov.cn/bzgk/std/newGbInfo?hcno=BAFB47E8874764186BDB7865E8344DAF'],
  ['OpenTravel', '酒店/旅行系统消息、代码表与互操作模型', 'https://opentravel.org/'],
  ['HTNG / AHLA', '酒店技术互操作与基础设施实践', 'https://www.ahla.com/sites/default/files/HTNG_NGI_Tech_Guide_10.23.pdf'],
  ['Oracle OPERA Cloud PMS', '主流酒店系统功能基线：预订、房态、支付、报表、移动与多门店', 'https://www.oracle.com/hospitality/hotel-property-management/hotel-pms-software/'],
  ['Cloudbeds PMS', '现代 PMS/酒店管理系统功能基线', 'https://www.cloudbeds.com/property-management-system/'],
  ['HSMAI Revenue Metrics', 'OCC、ADR、RevPAR 等酒店收益指标公式', 'https://academy.hsmai.org/wp-content/uploads/sites/11/2019/02/2019-hsmai-and-sit-revenue-management-metrics-study-final.pdf'],
  ['NIST AI RMF', 'AI 治理、映射、度量、管理与可信性', 'https://www.nist.gov/itl/ai-risk-management-framework'],
];

const freshEvidence = '2026-07-14：PHPUnit 1336 tests / 12138 assertions 全通过；Node automation 70 files / 492 tests 全通过；P0 guards、E2E structural contracts(2220)、Revenue AI closure(1733)、performance budget、report/security/finance regression 均通过；/api/health=200。';

const assessmentCatalogAuth = {
  auth_auto: {
    status: '满足', basis: '自动化+代码',
    evidence: `app/controller/Auth.php、app/middleware/Auth.php、app/model/User.php、tests/AuthMiddlewareAuditTest.php、tests/AuthRegistrationTest.php；${freshEvidence}`,
    next: '保留为认证冒烟与后端回归。',
  },
};

const insertedCategories = [
  {
    code: 'OTA', name: 'OTA 数据采集与任务控制', scope: '核心产品链', type: '功能/集成/数据',
    basis: '携程/美团授权采集边界、OpenTravel 互操作原则、可恢复任务基线',
    precondition: '使用已授权测试酒店和脱敏测试账号；需要真人登录或验证码时由测试人员完成，禁止记录 Cookie、Token 和敏感响应。',
    cases: [
      ['携程定位信息缺失', '移除携程酒店标识后发起采集。', '请求被阻止并返回 binding_missing 或等价真实状态，不生成空成功数据。', 'P0'],
      ['美团定位信息缺失', '移除美团酒店标识后发起采集。', '请求被阻止并指出缺失绑定，不把其他酒店或旧数据当作结果。', 'P0'],
      ['授权采集主路径', '为已绑定且凭据有效的测试酒店触发一次平台采集。', '创建可追踪任务，状态从排队到成功或真实失败，结果关联正确酒店、平台和日期。', 'P0'],
      ['临时 Cookie 采集路径', '在允许的临时会话入口提交短期测试 Cookie 并采集。', 'Cookie 只在约定生命周期内使用且不落明文日志，过期后任务明确失败。', 'P0'],
      ['手工 API 响应校验', '提交人工获取的脱敏平台 JSON 样本。', '先验证来源、结构、酒店与日期，再入库；不合格样本不产生正式指标。', 'P0'],
      ['浏览器 Profile 触发', '使用已绑定且当前登录证明有效的浏览器 Profile 发起采集。', '只使用匹配酒店与平台的 Profile，返回任务号并保留来源方式。', 'P0'],
      ['客户端本地登录边界', '在客户端完成平台登录后检查服务端记录和网络请求。', '服务端不保存可复用明文会话；需要人工步骤时状态清楚可见。', 'P0'],
      ['验证码与人机验证识别', '让平台返回滑块、短信验证或人机验证页面。', '任务进入 needs_human_action 或等价状态，不循环重试、不伪报采集成功。', 'P0'],
      ['Profile 状态读取', '读取未登录、已登录、过期、平台不匹配 Profile 的状态。', '四类状态准确区分，并给出可执行的重新登录提示。', 'P1'],
      ['采集状态全生命周期', '观察 queued、running、success、failed、cancelled 等状态迁移。', '状态单向、时间戳完整，终态不可被旧回调覆盖。', 'P0'],
      ['采集任务队列顺序', '同一酒店提交多个不同日期任务并观察执行。', '任务按队列策略执行且互不覆盖，优先级和创建时间可追踪。', 'P1'],
      ['临时错误重试', '模拟 429、502、503 和短暂网络中断。', '按上限退避重试并记录次数；超过上限后真实失败，不无限重试。', 'P0'],
      ['永久错误不重试', '模拟 400、401、403 和字段结构永久不合法。', '不进行无意义重试，直接标注认证、权限或格式失败原因。', 'P0'],
      ['采集超时', '让上游连接或响应超过配置超时。', '任务及时终止并可安全重试，不遗留 running 假状态。', 'P0'],
      ['采集任务取消', '在 queued 和 running 阶段分别取消任务。', '排队任务不执行；运行任务安全停止或进入取消待确认状态，不写半成品。', 'P1'],
      ['定时采集触发', '配置每日采集时间并跨越一次调度窗口。', '仅在启用酒店的正确时区触发一次，生成可审计任务。', 'P1'],
      ['自动采集开关', '关闭后等待调度，再重新开启并手动触发。', '关闭期不产生自动任务；重新开启不补造历史成功数据。', 'P0'],
      ['失败任务人工重试', '对同一失败任务点击重试并检查父子关系。', '生成新尝试或明确递增 attempt，保留原失败证据且不重复正式数据。', 'P0'],
      ['重复任务去重', '并发提交相同酒店、平台、日期和采集类型。', '只保留一个有效执行或用幂等键合并，避免重复请求和重复入库。', 'P0'],
      ['采集前置守卫', '使用停用酒店、停用凭据、过期证明和无权限用户发起任务。', '所有无效状态在请求上游前被阻止，错误原因与主体可审计。', 'P0'],
      ['原始响应错误保存', '让上游返回非 JSON、截断 JSON 和业务错误码。', '仅保存脱敏错误摘要和必要证据，不把错误页解析成零值指标。', 'P0'],
      ['请求响应脱敏', '在测试响应中注入 Cookie、手机号、Token 和身份证样例。', '日志、任务详情和通知均不暴露原值，原始敏感载荷按授权边界处理。', 'P0'],
      ['携程当日真实闭环', '用授权测试酒店采集当前业务日携程数据并与平台页面核对。', '关键字段、日期、酒店和来源一致，差异被明确记录。', 'P0'],
      ['美团当日真实闭环', '用授权测试酒店采集当前业务日美团数据并与平台页面核对。', '关键字段、日期、酒店和来源一致，失败不使用携程或旧数据替代。', 'P0'],
      ['双平台同酒店同日期闭环', '对同一酒店同一日期分别采集携程和美团。', '两平台记录独立可追溯，汇总时不重复计数且不扩大为全酒店结论。', 'P0'],
    ],
    assessments: `ota_auto ota_auto ota_auto ota_auto ota_auto ota_auto ota_auto ota_auto ota_auto ota_auto ota_auto ota_auto ota_auto ota_auto ota_auto ota_auto ota_auto ota_auto ota_auto ota_auto ota_auto ota_auto ota_live_partial ota_live_partial ota_not_verified`.split(/\s+/),
  },
  {
    code: 'DQ', name: '数据质量、血缘与快照', scope: '核心产品链', type: '数据/质量',
    basis: '可追溯数据管理、OTA 渠道范围边界、项目 P0 数据质量守卫',
    precondition: '准备成功、缺字段、过期、绑定缺失、权限失败、重复和人工导入样本，并使用隔离数据库验证写入。',
    cases: [
      ['平台字段血缘', '保存携程与美团各一条数据后读取 lineage。', '每条记录明确 platform，未知平台不得进入正式分析。', 'P0'],
      ['系统酒店标识血缘', '写入并查询 system_hotel_id。', '所有业务记录可回溯到唯一系统酒店，不能为空或被客户端越权替换。', 'P0'],
      ['平台酒店标识血缘', '提交含与不含 platform_hotel_id 的样本。', '有证据时保存原平台标识；缺失时明确标记，不用系统 ID 冒充。', 'P0'],
      ['数据业务日期', '提交跨日、未来日和无 data_date 样本。', '业务日期按来源规则保存；无日期或非法未来日期不进入正式日报。', 'P0'],
      ['采集时间', '比较 collected_at 与实际采集时刻和业务日期。', '采集时间为可信服务器时间且与 data_date 分离。', 'P0'],
      ['来源方式', '分别以自动、浏览器、手工 API 和导入产生数据。', 'source_method 准确保存并在 UI/导出中可见。', 'P0'],
      ['质量状态枚举', '写入 supported、unsupported 和非法质量状态。', '只接受定义状态，状态含义在接口、存储和界面一致。', 'P0'],
      ['关键字段缺失', '移除房价、间夜、流量等场景关键字段。', '对应指标显示 unavailable/缺失，不以 0 或空数组伪装。', 'P0'],
      ['过期数据识别', '把最近采集时间调整到超过有效阈值。', '标记 stale 并展示数据截止时间，AI 不当作实时证据。', 'P0'],
      ['绑定缺失状态', '解除平台酒店绑定后读取数据健康状态。', '返回 binding_missing，给出绑定入口且不沿用其他酒店绑定。', 'P0'],
      ['权限拒绝状态', '让上游返回 403 或授权范围不足。', '状态为 permission_denied 或等价值，并与账号错误、无数据区分。', 'P0'],
      ['采集失败状态', '模拟网络或解析失败后打开健康页。', '展示 collection_failed、时间和脱敏原因，不展示上次成功为当前成功。', 'P0'],
      ['人工导入验证状态', '导入未经平台交叉核验的合法文件。', '来源标记人工导入并保持未验证状态，不能宣传为线上实时数据。', 'P0'],
      ['派生指标血缘', '从源字段计算一个收入或转化指标并展开来源。', '能看到输入字段、公式/版本、时间范围和质量状态。', 'P0'],
      ['合成数据隔离', '载入测试或演示合成数据后查询正式报表。', '合成数据带明显标签且默认不进入真实酒店结论。', 'P0'],
      ['幂等保存', '用相同幂等键重复提交同一来源记录。', '只产生一条逻辑记录或同一版本，不重复累计。', 'P0'],
      ['更新与重复区分', '提交相同唯一键但内容更新的样本。', '按版本策略更新并保留变更证据，不静默产生重复行。', 'P1'],
      ['不可变快照', '生成日报或 AI 建议后再修改源数据。', '历史结果关联当时快照或版本，能解释前后差异。', 'P0'],
      ['历史终态保护', '用较晚到达的旧任务回调覆盖已确认历史数据。', '旧回调被拒绝或新建版本，不破坏已确认终态。', 'P0'],
      ['数据库回读核对', '保存样本后按酒店、平台、日期直接回读数据库。', '行数、唯一键、值和接口回显一致。', 'P0'],
      ['原始与标准化分层', '比较允许保存的脱敏原始证据和标准化记录。', '两层有引用关系，标准化失败不会伪造完整记录。', 'P1'],
      ['来源路径 source_path', '检查关键指标的原响应路径。', '记录明确、稳定的 source_path；无法定位时标为未知而非猜测。', 'P0'],
      ['指标键 metric_key', '查询同名展示指标对应的内部键。', 'metric_key 唯一且跨接口、存储、UI 一致。', 'P0'],
      ['统计周期', '混合日、周、月和滚动周期数据。', 'period_type、起止日期清楚，禁止不同粒度直接相加。', 'P0'],
      ['跨酒店污染检测', '在酒店 A 保存极值数据后查询酒店 B 各分析入口。', '酒店 B 的 API、缓存、报表和 AI 来源均不出现 A 的值。', 'P0'],
    ],
    assessments: `quality_auto quality_auto quality_auto quality_auto quality_auto quality_auto quality_auto quality_auto quality_auto quality_auto quality_auto quality_auto quality_auto quality_auto quality_auto quality_auto quality_auto quality_auto quality_auto quality_auto quality_auto quality_auto quality_auto quality_auto quality_auto`.split(/\s+/),
  },
  {
    code: 'REPORT', name: '经营日报、导入、导出与月度任务', scope: '核心产品链', type: '功能/数据',
    basis: '酒店经营数据管理常见闭环、ISO/IEC 25010 功能适合性与兼容性',
    precondition: '准备授权酒店、不同日期日报、合法与异常 CSV/XLSX 样本，并在隔离数据库执行新增、编辑、删除和导出。',
    cases: [
      ['创建经营日报', '填写必填经营字段并保存日报。', '保存成功并回显酒店、日期、字段和创建来源。', 'P0'],
      ['日报日期唯一性', '同一酒店同一业务日重复创建日报。', '按唯一规则拒绝或转为明确更新，不产生重复汇总。', 'P0'],
      ['读取日报详情', '创建后按 ID 和酒店日期读取。', '字段、空值语义、单位和质量状态与保存值一致。', 'P0'],
      ['编辑日报', '修改允许编辑字段并再次读取。', '变更持久化、更新时间和操作者可追溯，其他酒店不受影响。', 'P0'],
      ['删除日报范围', '由有权限与无权限用户分别删除日报。', '仅授权主体可删除；删除结果、关联影响和审计明确。', 'P0'],
      ['日报筛选分页', '按酒店、日期区间、来源和质量状态筛选翻页。', '结果范围、总数和排序稳定，无跨酒店数据。', 'P0'],
      ['导入文件类型', '上传允许与禁止的扩展名、MIME 和伪装文件。', '只接受约定表格类型，内容类型与扩展名不符时拒绝。', 'P0'],
      ['导入列映射', '使用标准列名、别名和自定义映射导入。', '预览清楚显示源列到目标字段映射，确认后结果一致。', 'P0'],
      ['导入缺少必填列', '移除酒店、日期或核心必填列。', '整批或受影响行被明确拒绝并列出缺失列。', 'P0'],
      ['导入非法数值', '在收入、间夜和房量列放入文本、NaN、无穷值。', '非法行不入正式数据，错误定位到行列。', 'P0'],
      ['导入负数边界', '在不允许为负的房量、入住数中填负数。', '拒绝不合业务语义的负值；允许负值的调整项有明确规则。', 'P0'],
      ['入住率边界', '提交小于 0、大于 100% 和不同百分比单位。', '统一单位并拒绝越界值，不自动截断掩盖错误。', 'P0'],
      ['零分母处理', '可售房为 0 时导入收入和入住数据。', '派生比例标记不可计算，不返回 Infinity、NaN 或伪造 0。', 'P0'],
      ['重复文件导入', '重复上传同一文件和相同业务键内容。', '通过摘要或业务键提示重复，避免二次累计。', 'P0'],
      ['导入保存回读', '确认导入后查询列表、详情和数据库。', '成功/失败行数与实际写入一致，值可编辑和回显。', 'P0'],
      ['导出酒店范围', '选择酒店 A 导出并用酒店 B 用户尝试。', '导出只含授权酒店与所选日期，不泄露其他酒店。', 'P0'],
      ['导出编码与公式注入', '导出中文、逗号、换行和以 =、+、-、@ 开头文本。', 'UTF-8 可打开，特殊字符正确转义，防止表格公式注入。', 'P0'],
      ['导入映射配置保存', '保存列映射后重新进入并复用。', '映射按用户或酒店约定持久化，敏感字段不能被映射到普通列。', 'P1'],
      ['旧映射兼容', '使用上一版本保存的映射导入当前模板。', '兼容字段仍可用；废弃字段明确提示迁移，不静默错列。', 'P1'],
      ['创建月度任务', '为指定酒店和月份创建经营任务。', '任务唯一关联酒店、月份、负责人和目标。', 'P1'],
      ['月度任务唯一性', '重复创建同酒店同月份同类型任务。', '按业务键去重或明确允许多版本，不重复统计。', 'P1'],
      ['月度目标保存回显', '设置收入、入住率、房价等目标并编辑。', '单位、精度和版本一致，目标与实际值分开。', 'P1'],
      ['缺酒店来源拒绝', '提交无 hotel_id 或来源不明的日报/导入。', '拒绝进入正式经营数据，不自动绑定当前列表首个酒店。', 'P0'],
      ['OTA 与全酒店范围区分', '仅有 OTA 数据时打开经营日报与全店指标。', '明确标注渠道范围，不把 OTA 订单/流量扩大成全酒店经营结果。', 'P0'],
      ['大文件导入原子性', '在大批量导入中途制造数据库失败。', '按设计全批回滚或逐批可恢复，成功失败清单与实际一致。', 'P0'],
    ],
    assessments: `report_auto report_auto report_auto report_auto report_auto report_auto report_auto report_auto report_auto report_auto report_auto report_auto report_auto report_auto report_auto report_auto report_auto report_auto report_partial report_auto report_auto report_auto report_auto report_auto report_partial`.split(/\s+/),
  },
  {
    code: 'METRIC', name: '收益指标、公式与口径', scope: '核心产品链', type: '业务规则/数据',
    basis: 'HSMAI 收益管理指标、酒店行业通用 OCC/ADR/RevPAR 口径、项目收益语义层',
    precondition: '准备可售房、已售房、客房收入、渠道流量与订单的已知答案数据集，固定币种、日期、税费口径和公式版本。',
    cases: [
      ['OCC 入住率公式', '用已售房晚 80、可售房晚 100 计算 OCC。', '结果为 80%，分子分母和单位可追溯。', 'P0'],
      ['ADR 平均房价公式', '用客房收入 8000、已售房晚 80 计算 ADR。', '结果为 100，币种与含税口径明确。', 'P0'],
      ['RevPAR 每房收益公式', '用客房收入 8000、可售房晚 100 计算 RevPAR。', '结果为 80，口径不混入非房收入。', 'P0'],
      ['RevPAR 等价校验', '比较 revenue/available rooms 与 OCC×ADR。', '相同输入和精度规则下两种算法一致。', 'P0'],
      ['零售出房 ADR', '已售房为 0 且收入为 0 或非 0 时计算 ADR。', '返回不可计算或业务异常，不出现除零和虚假 0。', 'P0'],
      ['零可售房 OCC/RevPAR', '可售房为 0 时计算 OCC 和 RevPAR。', '返回不可计算并说明原因，不产生 Infinity。', 'P0'],
      ['负值输入保护', '提交负可售房、负已售房和未经定义的负收入。', '拒绝非法输入或按调整口径单独记录，不直接进入主指标。', 'P0'],
      ['百分比单位一致', '分别提交 0.8、80 和 80% 表示入住率。', '输入层明确接受格式，存储与展示统一且不放大百倍。', 'P0'],
      ['币种与小数精度', '使用带两位以上小数和不同币种的收入。', '按币种精度舍入；不同币种未换算前不相加。', 'P0'],
      ['跨日聚合加权', '聚合两天房量和收入明显不同的数据。', '先汇总分子分母再计算，不简单平均日百分比或 ADR。', 'P0'],
      ['客房收入范围', '混入餐饮、会议、押金、退款和税费。', 'RevPAR/ADR 只使用定义的客房收入，排除项可解释。', 'P0'],
      ['OTA 渠道口径边界', '仅输入携程/美团渠道数据计算指标。', '输出标注 OTA/指定渠道，不命名为全酒店 OCC、ADR 或 RevPAR。', 'P0'],
      ['浏览转化率', '用详情访问与列表曝光构造正常、零分母和缺失场景。', '使用明确分子分母；缺失和零分母不伪装为 0%。', 'P1'],
      ['订单转化率', '用订单数与有效访问数计算并处理取消订单。', '有效订单定义和取消口径明确，日期窗口一致。', 'P1'],
      ['取消率', '用取消订单与总订单构造跨日取消样本。', '分母、取消归属日和状态终态规则固定可追溯。', 'P1'],
      ['房晚数', '对多房、多晚、改单和取消订单计算 room nights。', '房晚准确去重，取消/部分取消按终态规则处理。', 'P1'],
      ['ALOS 平均入住晚数', '用已知订单与房晚数据计算 ALOS。', '公式、订单范围和零分母处理明确。', 'P1'],
      ['Lead Time 提前预订天数', '构造预订日、入住日、当天订和异常倒序日期。', '按业务日计算，倒序日期被拒绝或标错。', 'P1'],
      ['ARI 房价指数', '用酒店 ADR 与竞品 ADR 计算正常和零分母场景。', '公式版本、竞品集和日期一致；缺竞品不输出伪指数。', 'P1'],
      ['MPI 市场渗透指数', '用酒店 OCC 与竞品 OCC 计算。', '市场集和可售口径一致，零分母明确不可计算。', 'P1'],
      ['RGI 收益生成指数', '用酒店 RevPAR 与竞品 RevPAR 计算。', '输入口径、日期、币种和竞品集一致，结果可追溯。', 'P1'],
      ['NRevPAR 净每房收益', '扣除渠道佣金和明确分销成本后计算。', '只扣定义成本，成本缺失时不伪造净值。', 'P1'],
      ['TRevPAR 总收益每房', '加入餐饮等已验证总经营收入计算。', '仅在具备全店收入数据时输出，否则标记范围不足。', 'P2'],
      ['GOPPAR 每房经营毛利', '用 GOP 和可售房晚计算并检查成本完整性。', '成本口径和期间完整时才输出，缺失时明确不可计算。', 'P2'],
      ['公式版本与审计', '修改指标公式或精度配置后重算历史与新数据。', '版本可追溯，历史结果不被静默改写，重算有范围和审计。', 'P0'],
    ],
    assessments: `metric_auto metric_auto metric_auto metric_auto metric_auto metric_auto metric_auto metric_auto metric_auto metric_auto metric_auto metric_auto metric_auto metric_auto metric_auto metric_partial metric_partial metric_partial metric_partial metric_partial metric_partial metric_partial metric_partial metric_partial metric_auto`.split(/\s+/),
  },
  {
    code: 'COMP', name: '竞品、渠道、流量与点评数据', scope: '核心产品链', type: '功能/数据',
    basis: 'OTA 渠道经营分析常见能力、隐私最小化、同口径竞品比较',
    precondition: '准备两个酒店、两个平台、多个竞品和日期对齐的脱敏样本；真实平台核对仅限已授权账号。',
    cases: [
      ['竞品酒店新增', '为酒店新增合法竞品及平台标识。', '竞品保存并回显来源平台、名称、状态和所属酒店。', 'P1'],
      ['竞品酒店编辑删除', '编辑竞品并删除在用与未使用竞品。', '变更可追溯；有关联数据时按保护策略处理，不产生孤儿记录。', 'P1'],
      ['竞品集租户隔离', '用酒店 A 用户读取或修改酒店 B 竞品集。', '请求被拒绝且缓存、导出中也不泄露。', 'P0'],
      ['竞品房价日志日期', '写入同一竞品多日期多房型房价。', '日期、房型、含税、取消政策和采集时间清楚，不跨日覆盖。', 'P1'],
      ['竞品集合版本', '增删竞品后查看历史指数与报告。', '报告关联当时竞品集版本，历史比较可解释。', 'P1'],
      ['携程排名数据', '写入携程列表排名和采集条件。', '排名关联关键词、日期、位置/条件和来源，不与美团混用。', 'P1'],
      ['美团排名数据', '写入美团排名并与携程同时查询。', '平台独立展示与聚合，缺失一方不复制另一方。', 'P1'],
      ['流量数据保存', '保存平台访问、访客等流量字段。', '字段单位、时间粒度和来源可追溯，非法负值拒绝。', 'P1'],
      ['曝光数据保存', '保存列表曝光并与访问数据核对。', '曝光使用平台定义，缺字段标缺失，不由访问数反推。', 'P1'],
      ['点击数据保存', '保存点击和详情访问样本。', '平台原字段与标准字段映射明确，不重复累计。', 'P1'],
      ['订单数据保存', '保存订单数、金额、房晚与状态汇总。', '酒店、平台、业务日、币种和状态口径完整。', 'P0'],
      ['渠道漏斗一致性', '构造曝光、访问、下单的正常与反常序列。', '正常计算转化；反常值触发质量警告而非静默修正。', 'P1'],
      ['点评聚合', '导入评分、数量和主题聚合样本。', '按平台和日期保存，可追溯到合法来源且不重复计数。', 'P1'],
      ['点评个人信息脱敏', '在点评样本中加入手机号、姓名和订单标识。', '分析只保留必要信息，界面与导出默认脱敏。', 'P0'],
      ['点评订单匹配边界', '尝试用点评身份反查住客或跨平台订单。', '无合法目的与授权时禁止匹配，保留最小化审计。', 'P0'],
      ['掩码信息不可还原', '使用掩码手机号、姓名片段尝试搜索和拼接。', '系统不提供绕过掩码的反查路径。', 'P0'],
      ['竞品价格同口径比较', '比较不同房型、早餐、取消、税费的报价。', '只比较标准化同口径价格；不可比项明确标识。', 'P1'],
      ['竞品日期对齐', '混合入住日、采集日和不同入住晚数。', '比较使用一致入住日与条件，采集时差可见。', 'P1'],
      ['竞品缺失处理', '竞品某日无报价或售罄。', '区分无数据、售罄、不可售和采集失败，不默认价格为 0。', 'P0'],
      ['竞品过期处理', '使用超过新鲜度阈值的房价和排名。', '标记 stale 并避免生成实时调价结论。', 'P0'],
      ['竞品来源标签', '查看图表、表格、导出和 AI 引用。', '每处都能识别来源平台、采集时间和质量状态。', 'P0'],
      ['双平台订单去重', '导入可能来自双平台汇总的相同订单样本。', '按平台订单标识和业务规则去重，不凭相似金额误合并。', 'P0'],
      ['渠道表现比较', '比较携程与美团收入、订单、房晚和转化。', '使用相同期间和指标口径，明确仅代表 OTA 渠道。', 'P1'],
      ['广告投放数据', '保存曝光、点击、消耗、订单和归因窗口。', 'ROI/ROAS 只在归因和成本完整时计算，缺失状态明确。', 'P1'],
      ['竞品与渠道真实核对', '在授权平台页面核对当天排名、房价、流量或订单摘要。', '系统值与同条件页面一致，差异保留证据和解释。', 'P0'],
    ],
    assessments: `competitor_auto competitor_auto competitor_auto competitor_auto competitor_auto competitor_auto competitor_auto competitor_auto competitor_auto competitor_auto competitor_auto competitor_auto competitor_auto privacy_partial privacy_partial privacy_partial competitor_auto competitor_auto competitor_auto competitor_auto competitor_auto competitor_auto competitor_auto competitor_partial ota_not_verified`.split(/\s+/),
  },
];

const assessmentCatalog = {
  ...assessmentCatalogAuth,
  auth_partial: {
    status: '部分满足', basis: '代码检查',
    evidence: '已有密码策略/缓存 Token，但默认密码最短仅 6 位、复杂度可关闭、Token 最长 72 小时，未达到更严格市场基线。',
    next: '提高默认密码强度、缩短高权限会话并补策略回归。',
  },
  auth_token_revoke_gap: {
    status: '不满足', basis: '代码检查',
    evidence: 'Auth::changePassword 保存新密码后未清理 token_* / user_token_*，现有会话不会被统一撤销。',
    next: '改密后撤销该用户全部会话，并增加回归测试。',
  },
  auth_lockout_gap: {
    status: '不满足', basis: '自动化+代码',
    evidence: 'tests/automation/login_lockout_removed.test.mjs 明确断言系统不启用失败次数锁定；登录入口也无独立限流。',
    next: '增加按账号+IP 的渐进限流/短时锁定，并避免账号枚举。',
  },
  auth_mfa_gap: {
    status: '不满足', basis: '定向搜索',
    evidence: '认证路由、控制器、模型与测试中未发现 MFA/TOTP/OTP/WebAuthn 能力。',
    next: '至少为超级管理员和敏感操作启用 MFA。',
  },
  tenant_auto: {
    status: '满足', basis: '自动化+代码',
    evidence: `PermissionService、HotelScopeService、Auth middleware；tests/HotelManagementScopeTest.php、OtaHotelScopeAuthorizationTest.php、OnlineDataTenantScopeTest.php、PermissionServiceTest.php；${freshEvidence}`,
    next: '保留跨酒店负向用例为发布前必跑项。',
  },
  tenant_expiry_gap: {
    status: '不满足', basis: '代码检查',
    evidence: 'user_hotel_permissions 存在 expires_at 字段，但 HotelScopeService 查询仅检查 status，未检查授权是否过期。',
    next: '在所有酒店权限查询统一加入 expires_at 判定并补边界测试。',
  },
  tenant_partial: {
    status: '部分满足', basis: '代码检查',
    evidence: '租户与酒店字段已广泛传播，但部分旧表/兼容分支仍以 hotel_id 代替 tenant_id，需用真实 MySQL 数据继续验证。',
    next: '跑跨租户数据库集成测试并检查旧数据迁移。',
  },
  hotel_auto: {
    status: '满足', basis: '自动化+代码',
    evidence: `app/controller/Hotel.php、HotelCascadeDeletionService、HotelDataMergeService；tests/Hotel*Test.php、BatchStatusPreviewServiceTest.php；${freshEvidence}`,
    next: '继续覆盖新增字段的保存、编辑、回显与旧数据兼容。',
  },
  hotel_partial: {
    status: '部分满足', basis: '数据模型检查',
    evidence: '酒店主数据支持名称、编码、地址、联系人、状态、归属与 OTA 策略；但房量、星级、时区、币种、品牌等行业常用主数据未形成统一主表字段闭环。',
    next: '按真实业务需要补最小酒店主数据字典，避免一次性扩成完整 PMS。',
  },
  admin_auto: {
    status: '满足', basis: '自动化+代码',
    evidence: `User/Role/SystemConfig/OperationLog/SystemNotification 控制器与对应测试；${freshEvidence}`,
    next: '纳入管理员日常回归。',
  },
  admin_validation_gap: {
    status: '部分满足', basis: '代码检查',
    evidence: '用户名和密码有验证，但 User 控制器直接保存 email/phone，未见格式、唯一性或长度的完整验证。',
    next: '增加邮箱、手机号格式与长度验证，并保持可选字段兼容。',
  },
  admin_default_password_partial: {
    status: '部分满足', basis: '自动化+代码',
    evidence: '存在 UserIssuedDefaultPasswordTest 与专用默认密码逻辑，但固定发放口令仍需强制首次修改和过期策略。',
    next: '增加一次性初始密码、首次登录改密和有效期。',
  },
  credential_auto: {
    status: '满足', basis: '自动化+代码',
    evidence: `AES-256-GCM 凭据封装、AAD 范围绑定、Vault、元数据脱敏；tests/OtaCredentialEnvelopeTest.php、OtaCredentialVaultTest.php、OtaCredentialResponseTest.php 等；${freshEvidence}`,
    next: '保留篡改、跨酒店和响应脱敏测试。',
  },
  credential_live_partial: {
    status: '部分满足', basis: '结构通过/运行未闭环',
    evidence: '凭据保存结构和单元测试通过，但本轮未对真实携程/美团账号执行保存→读取→采集；浏览器 E2E 登录凭据失效。',
    next: '使用专用测试账号做一次无明文回显的保存/读取/采集闭环。',
  },
  credential_rotation_gap: {
    status: '不满足', basis: '发布门禁',
    evidence: 'fresh review:release-readiness 明确失败：缺少 ota_credential_rotation_attestation.json。',
    next: '真实轮换/失效携程与美团敏感材料，提供不含凭据值的责任人证明。',
  },
  ota_auto: {
    status: '满足', basis: '自动化+代码',
    evidence: `OnlineData concerns、PlatformDataSyncService、BrowserProfileCaptureRequestService 与大量 OTA 合同测试；P0 guards 和 structural E2E contracts 通过；${freshEvidence}`,
    next: '保持失败阶段和数据质量状态显式。',
  },
  ota_live_partial: {
    status: '部分满足', basis: '未做当日真实平台闭环',
    evidence: '采集入口、任务、Profile 与保存服务存在，但本轮未使用当日真实携程/美团授权验证查询→保存→数据库回读→页面回显。',
    next: '对一间授权测试酒店分别跑携程、美团当日闭环并保存脱敏证据。',
  },
  ota_not_verified: {
    status: '未验证', basis: '外部依赖',
    evidence: '需要真实 OTA 登录态、平台权限、验证码/风控状态或当前日期数据，本轮没有该授权证据。',
    next: '由账号持有人在本机完成授权后执行。',
  },
  quality_auto: {
    status: '满足', basis: '自动化+代码',
    evidence: `OtaCollectionQualityStateService、OtaDataCredibilityGateService、OnlineDailyDataPersistenceService 及对应测试；${freshEvidence}`,
    next: '继续以来源、酒店、日期、状态和回读作为事实门禁。',
  },
  quality_partial: {
    status: '部分满足', basis: '结构通过/数据未抽样',
    evidence: '字段契约与质量状态已实现，但未对生产规模历史数据做完整率、唯一性、及时性和跨表一致性抽样。',
    next: '按酒店/平台/日期分层抽样并形成数据质量基线。',
  },
  report_auto: {
    status: '满足', basis: '自动化+代码',
    evidence: `DailyReport、MonthlyTask、ReportConfig 路由/控制器与 tests/DailyReportTest.php；${freshEvidence}`,
    next: '维持导入、保存、回读和导出一致性测试。',
  },
  report_partial: {
    status: '部分满足', basis: '代码/运行范围有限',
    evidence: '日报与映射能力存在，但文件格式、超大导入、异常行策略和全量回滚未在本轮真实文件上验证。',
    next: '准备合法、缺列、乱码、极值和大文件夹具执行集成测试。',
  },
  metric_auto: {
    status: '满足', basis: '自动化+行业公式',
    evidence: `OtaRevenueMetricService、OtaStandard、RevenuePricingRecommendationService；PHPUnit 全通过，历史综合测试亦验证 ADR/OCC/RevPAR/ARI/MPI；公式与 HSMAI 基线一致。`,
    next: '每个指标持续锁定分子、分母、单位、日期和数据源。',
  },
  metric_partial: {
    status: '部分满足', basis: '范围/公式覆盖',
    evidence: '核心 OTA 收益指标已覆盖；ALOS、提前期、取消率或全酒店利润类指标没有同等完整的真实源与公式闭环。',
    next: '只在拿到 PMS/财务事实后扩展全酒店指标。',
  },
  competitor_auto: {
    status: '满足', basis: '自动化+代码',
    evidence: `Competitor*、Ctrip/Meituan rank/traffic/review 服务与对应测试；${freshEvidence}`,
    next: '保留竞品集、日期和来源边界。',
  },
  competitor_partial: {
    status: '部分满足', basis: '结构通过/实时性未证',
    evidence: '竞品、排名、流量、点评结构存在，但本轮没有证明当前日期、同口径竞品集和平台实时数据均可用。',
    next: '以同酒店、同平台、同日期的真实响应做抽样闭环。',
  },
  ai_auto: {
    status: '满足', basis: '自动化+代码',
    evidence: `LlmClient、AiGovernance、AiEvaluationBatchReplayService、AiModelCallLog；低置信度、来源、人工确认、提示词版本均有测试；${freshEvidence}`,
    next: '保持高影响决策强制人工确认。',
  },
  ai_security_partial: {
    status: '部分满足', basis: '代码检查',
    evidence: '日志与凭据有脱敏，输出有结构门禁；但未见系统化提示词注入/间接注入攻击语料与模型红队回归。',
    next: '增加 OTA 文本、知识文档和用户输入三类提示词注入测试集。',
  },
  ai_live_partial: {
    status: '部分满足', basis: '发布证明通过/本轮未调用',
    evidence: 'fresh release gate 显示生产 LLM connectivity attestation 已通过，但本轮未用真实模型执行可复核业务样例。',
    next: '跑一条脱敏、可回放、无执行副作用的真实模型烟测。',
  },
  ops_auto: {
    status: '满足', basis: '自动化+代码',
    evidence: `OperationManagementService、Phase3OperationEffectLoopService、AiDailyReportService 与 OperationExecutionLoopTest 等；${freshEvidence}`,
    next: '持续验证状态机、审批、证据和复盘链路。',
  },
  ops_partial: {
    status: '部分满足', basis: '结构闭环/实操未证',
    evidence: '动作、审批、执行证据、复盘和 ROI 结构已存在；没有真实 OTA 平台回调或实际运营动作完成证据。',
    next: '选择低风险运营动作做人工审批后的真实闭环。',
  },
  ops_rollback_gap: {
    status: '部分满足', basis: '定向检查',
    evidence: '可记录失败与复盘，但未形成所有可执行动作的统一回滚/补偿协议。',
    next: '按动作类型定义可回滚、不可回滚和人工补偿策略。',
  },
  invest_auto: {
    status: '满足', basis: '自动化+代码',
    evidence: `StrategySimulation、QuantSimulation、FeasibilityReport、Expansion、Opening、Transfer 服务与对应测试；${freshEvidence}`,
    next: '保留输入快照、版本和人工确认。',
  },
  invest_formula_partial: {
    status: '部分满足', basis: '测试覆盖差异',
    evidence: 'ROI/回本/敏感性和模拟结构存在；IRR、NPV、现金流时点、税费等专业投决口径未全部形成可审计公式测试。',
    next: '对每个投决指标补公式说明、黄金样例和误差容忍。',
  },
  invest_live_partial: {
    status: '部分满足', basis: '外部事实不足',
    evidence: '投决流程可保存和回读，但真实市场、竞品、租赁、工程和交易数据来源没有在本轮验证。',
    next: '接入经确认的外部事实并明确人工输入/第三方来源。',
  },
  dashboard_auto: {
    status: '满足', basis: '静态合同+后端测试',
    evidence: `dashboard、notification、daily workbench、AI daily report 入口存在；structural E2E contracts 与 P0 guards 通过；${freshEvidence}`,
    next: '保留空状态、失败状态和来源标签守卫。',
  },
  dashboard_browser_unverified: {
    status: '未验证', basis: '浏览器回归阻塞',
    evidence: 'npm run test:e2e:quick 执行 9 个场景，9 个均因测试登录凭据错误/登录页未消失而失败，不能证明实际页面通过。',
    next: '刷新专用 E2E 用户凭据后重新执行并截图验收。',
  },
  dashboard_scheduler_gap: {
    status: '不满足', basis: '市场基线对照',
    evidence: '存在任务/巡检调度，但未发现面向业务用户的通用报表订阅、定时生成、邮件/消息分发闭环。',
    next: '如业务需要，新增报表订阅与发送记录，不与采集调度混用。',
  },
  api_auto: {
    status: '满足', basis: '自动化+代码',
    evidence: `route/app.php 认证路由、Base 响应/分页、ControllerRouteContractTest、RouteCoverageVerifierTest；${freshEvidence}`,
    next: '把接口契约纳入 CI。',
  },
  api_partial: {
    status: '部分满足', basis: '接口一致性检查',
    evidence: '大部分接口采用统一 JSON，但部分业务错误仍可能 HTTP 200 + code=4xx；API 版本策略和全局幂等键未统一。',
    next: '逐步统一 HTTP 语义、版本与幂等策略，保持旧客户端兼容。',
  },
  openapi_gap: {
    status: '不满足', basis: '定向搜索',
    evidence: '未发现覆盖 409 条 Route 调用点的 OpenAPI/Swagger 主规范与自动一致性门禁。',
    next: '先为对外/关键 OTA 接口生成最小 OpenAPI，并做路由契约校验。',
  },
  opentravel_partial: {
    status: '部分满足', basis: '行业互操作对照',
    evidence: '项目有自有 OTA 标准 ETL 和统一结果契约，但未验证与 OpenTravel 酒店消息/代码表的系统映射。',
    next: '仅对需要外部互操作的接口建立 OpenTravel 映射，不重写现有采集适配器。',
  },
  security_auto: {
    status: '满足', basis: '自动化+代码',
    evidence: `SecurityInputGuardTest、AuthMiddlewareAuditTest、OtaMetadataSafetyTest、正式安全扫描；review:report-security-finance 通过；${freshEvidence}`,
    next: '继续按高风险入口做参数化与秘密脱敏测试。',
  },
  security_partial: {
    status: '部分满足', basis: '代码/部署边界',
    evidence: '应用层已有鉴权、参数约束和脱敏，但 TLS、WAF、主机加固、缓存安全等依赖生产部署证据。',
    next: '在生产验收中验证 TLS、反向代理、安全头和密钥管理。',
  },
  cors_gap: {
    status: '不满足', basis: '代码检查',
    evidence: "app/middleware/Cors.php 全局返回 Access-Control-Allow-Origin: *，没有生产来源白名单。",
    next: '按环境配置可信 Origin 白名单，公共无凭据接口单独处理。',
  },
  security_headers_gap: {
    status: '不满足', basis: '定向搜索',
    evidence: '未发现统一 CSP、HSTS、X-Content-Type-Options、frame-ancestors/防点击劫持与 Referrer-Policy。',
    next: '由反向代理或全局中间件补齐并写响应头测试。',
  },
  privacy_partial: {
    status: '部分满足', basis: '代码检查',
    evidence: '点评/订单有聚合、掩码和禁止重建边界，日志也脱敏；但尚非完整个人信息治理体系。',
    next: '建立数据清单、用途、保存期限、访问主体与删除责任。',
  },
  privacy_gap: {
    status: '不满足', basis: '法规基线对照',
    evidence: '未发现完整的告知同意记录、撤回、查阅复制、更正、删除、保存期限和跨境处理工作流。',
    next: '按实际处理的个人信息最小化补齐权利请求与留存机制。',
  },
  pci_na: {
    status: '不适用', basis: '范围边界',
    evidence: '当前宿析OS产品链不处理银行卡账户数据；若未来接入支付，需重新划定 PCI DSS 范围。',
    next: '保持不采集卡号；支付能力独立立项。',
  },
  reliability_auto: {
    status: '满足', basis: '自动化+运行检查',
    evidence: `本机 MySQL alive，/api/health=200；LlmClient/OTA 具备超时、有限重试与退避；事务与幂等服务有测试；${freshEvidence}`,
    next: '保留健康、超时、重试和事务回归。',
  },
  health_db_gap: {
    status: '不满足', basis: '代码+运行检查',
    evidence: "route/app.php 的 /api/health 仅返回 status=ok/time，不探测数据库或关键依赖，因此数据库中断也可能继续报健康。",
    next: '拆分 liveness/readiness；readiness 检查数据库及关键配置且不泄露细节。',
  },
  reliability_partial: {
    status: '部分满足', basis: '本机证据',
    evidence: '单机恢复、重试和日志结构存在；未提供集群高可用、容量、监控告警、RPO/RTO 与恢复演练的新证据。',
    next: '先定义当前阶段 RPO/RTO，做一次可恢复的备份演练。',
  },
  reliability_unverified: {
    status: '未验证', basis: '需要环境/压测',
    evidence: '本轮未执行并发、长稳、故障注入、大数据量或灾备切换。',
    next: '在隔离环境使用脱敏数据执行容量与故障测试。',
  },
  perf_auto: {
    status: '部分满足', basis: '自动化',
    evidence: 'verify:performance-budget 通过：startup gzip 目标 650,000B、预警 800,000B、硬上限 950,000B；当前为目标未达但未触发预警。',
    next: '保留静态预算，并补真实浏览器 Web Vitals。',
  },
  ui_static: {
    status: '满足', basis: '静态合同',
    evidence: 'zh-CN、viewport、响应式类、加载/空/失败状态及多语言入口存在；相关静态合同测试纳入 P0 guards。',
    next: '继续在实际页面回归。',
  },
  a11y_partial: {
    status: '部分满足', basis: '静态计数',
    evidence: '47 个模板分片中发现 aria-* 57、role 5、tabindex 1、alt 3；有基础语义，但覆盖明显不完整。',
    next: '从登录、导航、筛选器、弹窗和表格补语义与键盘路径。',
  },
  a11y_gap: {
    status: '不满足', basis: 'WCAG 对照',
    evidence: '未发现 axe/WCAG 自动化、完整键盘导航、统一 focus-visible、prefers-reduced-motion 或屏幕阅读器验收。',
    next: '以 WCAG 2.2 AA 建立最小可访问性门禁。',
  },
  i18n_partial: {
    status: '部分满足', basis: '静态检查',
    evidence: '存在语言切换和部分术语映射，但大量模板仍直接写中文，日期/币种/数字本地化没有全局一致证据。',
    next: '建立文案键、日期和币种格式化清单，先覆盖核心流程。',
  },
  mojibake_gap: {
    status: '部分满足', basis: '仓库证据',
    evidence: '项目有 scan_mojibake_text.mjs，但多份历史文件/终端输出仍出现乱码，Node 测试也可能输出超长乱码上下文。',
    next: '执行乱码扫描并只修复用户可见/源文件编码问题。',
  },
  php_suite_pass: {
    status: '满足', basis: '最新执行',
    evidence: 'C:\\xampp\\php\\php.exe vendor\\bin\\phpunit --colors=never：OK (1336 tests, 12138 assertions)。',
    next: '保持全量后端门禁。',
  },
  node_suite_pass: {
    status: '满足', basis: '最新执行',
    evidence: 'node scripts/run_node_automation_tests.mjs：70 个文件串行执行，492 tests 全通过，退出码 0。',
    next: '保持完整 Node 自动化门禁，并关注顺序相关/瞬时失败。',
  },
  browser_e2e_fail: {
    status: '不满足', basis: '最新执行',
    evidence: 'npm run test:e2e:quick：9 tests，9 failed；主要因测试登录凭据错误或登录页未消失。',
    next: '修复/轮换专用 E2E 账号配置后重新跑真实浏览器流程。',
  },
  structural_pass: {
    status: '满足', basis: '最新执行',
    evidence: 'verify:e2e-contracts 2220 checks 通过；review:functional-readiness 105 structural checks 通过；P0 guards 通过。',
    next: '结构门禁与真实浏览器门禁必须同时保留。',
  },
  release_fail: {
    status: '不满足', basis: '最新发布门禁',
    evidence: 'review:release-readiness：14 passed、2 warnings、4 failures；缺设计交付、OTA 凭据轮换证明，且 staged-scope/external-state 结果过期。',
    next: '逐项关闭四个发布阻塞后再声明可发布。',
  },
  design_handoff_gap: {
    status: '不满足', basis: '发布门禁',
    evidence: '缺少受控 design_handoff_manifest.json，Figma/Canva 源交付未闭环。',
    next: '提供可复核设计源、Brand Kit、设计令牌和最新审阅记录。',
  },
  pms_na: {
    status: '不适用', basis: '产品范围',
    evidence: '该能力属于完整 PMS/CRS/支付/POS 范围，不在当前“OTA数据→收益→AI→运营→投决”产品链内，不能当作宿析OS缺陷。',
    next: '只有用户明确扩展为 PMS 时再单独立项。',
  },
};

const categories = [
  {
    code: 'AUTH', name: '身份认证、密码与会话', scope: '通用系统基线', type: '安全/功能',
    basis: 'OWASP ASVS、ISO/IEC 27001、GB/T 22239',
    precondition: '隔离测试库中准备启用账号、停用账号和普通账号；允许读取登录/操作日志。',
    cases: [
      ['有效账号密码登录', '使用正确用户名和密码调用 POST /api/auth/login，再访问 /api/auth/info。', '登录成功，返回随机 Token、有效期、用户、酒店范围和权限；info 与登录用户一致。', 'P0'],
      ['不存在账号登录', '使用随机不存在用户名和任意密码登录。', '返回统一的“用户名或密码错误”，不得泄露账号不存在。', 'P0'],
      ['错误密码登录', '对存在账号提交错误密码。', '拒绝登录并使用与不存在账号一致的外部错误文案。', 'P0'],
      ['空用户名登录', '提交空 username、有效 password。', '请求被参数校验拒绝，不创建会话。', 'P1'],
      ['空密码登录', '提交有效 username、空 password。', '请求被参数校验拒绝，不创建会话。', 'P1'],
      ['停用账号登录', '将账号置为停用后尝试登录。', '返回账号已停用，不签发 Token，并记录失败原因。', 'P0'],
      ['密码不可逆存储', '创建用户后检查 users.password，并用明文与散列分别验证。', '数据库仅保存 password_hash 结果，明文不出现在响应或日志。', 'P0'],
      ['密码最短长度', '分别提交 5、6、8、12 位密码并读取当前策略配置。', '低于策略下限的密码被拒绝，错误提示明确且策略可配置。', 'P0'],
      ['密码复杂度策略', '开启/关闭特殊字符策略，分别提交纯字母数字和含特殊字符密码。', '开启时拒绝不符合复杂度的密码；关闭时行为与配置一致。', 'P1'],
      ['公开注册固定拒绝', '不带 Token 调用 POST /api/auth/register，并检查用户与酒店授权表。', '固定返回 403，数据库不产生用户或酒店授权残留。', 'P0'],
      ['修改密码校验旧密码', '登录后使用错误 old_password 修改密码。', '修改失败，原密码仍有效，新密码无效。', 'P0'],
      ['修改密码执行新策略', '提交不符合长度/复杂度的新密码。', '修改失败，不绕过管理员建用户或修改密码使用的密码策略。', 'P0'],
      ['修改密码撤销旧会话', '在两个会话登录，修改密码后继续使用两个旧 Token。', '所有旧会话应失效，只允许用新密码重新登录。', 'P0'],
      ['登出撤销当前 Token', '登录、调用 logout，再用同一 Token 访问 info。', 'logout 成功且旧 Token 随即返回 401。', 'P0'],
      ['缺失 Token 访问保护路由', '不带 Authorization 访问酒店、OTA、AI、运营和投决路由。', '统一返回 401 和 request_id，不返回业务数据。', 'P0'],
      ['无效 Token 访问保护路由', '使用随机 Token 访问保护路由。', '返回 401，不因 Token 格式异常产生 500。', 'P0'],
      ['过期 Token', '构造超过最大年龄或缓存过期的 Token。', '返回 token_expired，并清理相关缓存引用。', 'P0'],
      ['会话期间账号被停用', '登录后停用用户，再用旧 Token 访问。', '中间件实时拒绝停用用户，不能依赖登录时状态。', 'P0'],
      ['Token 随机性与不可预测性', '同一用户连续登录 100 次并比较 Token。', 'Token 均唯一、长度稳定、无可推断序列或用户ID明文。', 'P1'],
      ['并发会话策略', '同一用户从两个客户端登录并分别操作、登出。', '系统按明确策略允许或撤销会话，不出现幽灵会话或误踢其他用户。', 'P1'],
      ['登录失败审计', '连续执行账号不存在、密码错误、停用账号三种失败。', 'login_logs 记录时间、用户名、IP、UA、失败类型且不含密码。', 'P0'],
      ['登录与登出成功审计', '完成一次登录和一次登出。', '成功日志可按用户/租户追溯，操作日志与登录日志时间一致。', 'P1'],
      ['失败次数锁定', '同账号在短时间连续提交错误密码超过阈值。', '达到阈值后短时锁定或渐进延迟，并提供可审计解除策略。', 'P0'],
      ['登录接口限流', '从同一 IP 和分布式 IP 高频调用登录接口。', '按账号+IP/设备组合限流，返回 429/Retry-After，不拖垮数据库。', 'P0'],
      ['高权限账号多因素认证', '超级管理员使用密码登录并执行凭据/删除/导出操作。', '必须完成第二因素或等效强认证，敏感操作有再次确认。', 'P0'],
    ],
    assessments: `auth_auto auth_auto auth_auto auth_auto auth_auto auth_auto auth_auto auth_partial auth_partial auth_auto auth_auto auth_auto auth_token_revoke_gap auth_auto auth_auto auth_auto auth_auto auth_auto auth_auto auth_partial auth_auto auth_auto auth_lockout_gap auth_lockout_gap auth_mfa_gap`.split(/\s+/),
  },
  {
    code: 'TENANT', name: '角色权限、租户与酒店隔离', scope: '核心产品链', type: '安全/功能',
    basis: 'OWASP ASVS、ISO/IEC 27001、酒店多物业 SaaS 市场基线',
    precondition: '准备两个租户、三家酒店、超级管理员/店长/员工/只读角色及跨店授权记录。',
    cases: [
      ['全路由默认鉴权', '枚举 route/app.php 的业务路由并无 Token 访问。', '除登录、登录支持、固定拒绝的注册墓碑、健康和受控采集接口外均要求认证。', 'P0'],
      ['角色能力列表', '读取不同角色的 permissions/capabilities。', '返回值只包含角色实际拥有能力，禁用角色不得继承权限。', 'P0'],
      ['可访问酒店集合', '分别查询主酒店、授权酒店、无授权酒店。', 'accessibleHotelIds 只返回启用且被拥有/授权的酒店。', 'P0'],
      ['跨酒店读取阻断', '用户 A 使用酒店 B 的 ID 查询酒店、日报、OTA 数据。', '返回 403/404 且不泄露酒店 B 的存在、名称或数据。', 'P0'],
      ['跨酒店写入阻断', '用户 A 向酒店 B 保存日报、配置、运营动作或投决记录。', '写入被拒绝，所有相关表无残留。', 'P0'],
      ['跨酒店删除阻断', '用户 A 尝试删除酒店 B 的 OTA 数据或配置。', '删除被拒绝并记录 protected_access_denied。', 'P0'],
      ['请求 tenant_id 注入', '普通用户在请求体伪造其他 tenant_id。', '服务端忽略/验证客户端 tenant_id，以用户酒店上下文为准。', 'P0'],
      ['租户字段传播', '创建用户、日报、OTA 数据、AI 日志和投决记录后查询 tenant_id。', '每条记录的 tenant_id 与授权酒店一致，不为空或串店。', 'P0'],
      ['超级管理员酒店范围', '超级管理员查询全部酒店并包含停用酒店测试。', '仅按产品规则返回启用酒店；敏感写入仍执行受保护能力审计。', 'P1'],
      ['停用酒店不可见', '停用一间酒店后用原授权用户刷新酒店列表。', '停用酒店不进入可操作集合，旧 Token 不能继续操作该酒店。', 'P0'],
      ['主酒店默认授权', '创建绑定主酒店的用户但不额外授权。', '用户仅可访问主酒店允许的能力。', 'P1'],
      ['跨店显式授权', '给用户增加第二家酒店的 granted 权限。', '授权后仅新增指定酒店和指定能力，不扩大到同租户其他酒店。', 'P0'],
      ['授权到期', '创建 expires_at 已过期、即将过期和未来有效的授权。', '已过期授权立即失效，未来授权在到期前有效，边界按统一时区计算。', 'P0'],
      ['授权禁用', '将 user_hotel_permissions.status 置 disabled。', '该授权立即从读取与写入判断中移除。', 'P0'],
      ['查看与编辑分离', '给用户 can_view=1、can_edit=0 后读取并编辑酒店。', '读取允许、编辑拒绝，前后端权限一致。', 'P0'],
      ['OTA 采集权限', '给用户 can_fetch_ota=0 后发起携程/美团采集。', '请求被拒绝，后台任务和平台请求均未启动。', 'P0'],
      ['OTA 删除权限', '给用户 can_delete_ota=0 后删除数据源/日数据。', '删除被拒绝且数据保持不变。', 'P0'],
      ['导出权限', '给用户 can_export=0 后导出日报/OTA 数据。', '导出被拒绝，不能通过直接 URL 绕过。', 'P0'],
      ['AI 权限', '给用户 can_ai=0 后调用 AI 分析/日报/策略。', '调用被拒绝且不产生模型费用或日志结论。', 'P0'],
      ['运营权限', '给用户 can_operation=0 后创建/审批运营动作。', '运营写入被拒绝；只读信息按角色策略展示。', 'P0'],
      ['投决权限', '给用户 can_investment=0 后运行投资模拟。', '投决调用被拒绝，不生成记录。', 'P0'],
      ['禁用角色', '将角色 status 置为禁用并使用该角色用户。', '用户不能通过角色历史 permissions 获得业务能力。', 'P0'],
      ['外部普通角色能力收敛', '给普通外部角色配置 all 或高风险删除权限。', '服务端仍收敛禁止的管理/删除能力，不依赖前端隐藏。', 'P0'],
      ['批量操作酒店范围', '批量启停/删除包含有权和无权酒店的 ID。', '操作应整体拒绝或明确部分失败，不得越权处理无权酒店。', 'P0'],
      ['权限拒绝审计范围', '触发跨店和能力不足两类拒绝。', '日志包含 tenant_id、hotel_id、required_permission、request_id，敏感参数被脱敏。', 'P0'],
    ],
    assessments: `tenant_auto tenant_auto tenant_auto tenant_auto tenant_auto tenant_auto tenant_auto tenant_auto tenant_auto tenant_auto tenant_auto tenant_auto tenant_expiry_gap tenant_auto tenant_auto tenant_auto tenant_auto tenant_auto tenant_auto tenant_auto tenant_auto tenant_auto tenant_auto tenant_partial tenant_auto`.split(/\s+/),
  },
  {
    code: 'HOTEL', name: '酒店主数据与门店生命周期', scope: '核心产品链', type: '功能/数据',
    basis: 'Oracle OPERA、Cloudbeds、多物业 SaaS 主数据基线',
    precondition: '以超级管理员和酒店归属用户准备空库、重复名称/编码及有关联数据的酒店样本。',
    cases: [
      ['创建酒店必填名称', '提交缺少 name 或仅空白字符的酒店。', '返回校验错误，数据库无新增酒店和授权记录。', 'P0'],
      ['酒店名称长度边界', '提交 100 与 101 个字符的名称。', '100 字符可保存，超限被拒绝且提示准确。', 'P1'],
      ['酒店名称去重', '以含前后空格和完全相同名称重复创建。', '标准化后识别重复并返回现有酒店提示。', 'P0'],
      ['酒店编码唯一', '使用已存在 code 创建/更新另一酒店。', '操作被拒绝，原酒店编码保持不变。', 'P0'],
      ['空酒店编码兼容', '连续创建多家 code 为空的酒店。', '空值按 NULL/无编码处理，不因唯一索引错误失败。', 'P1'],
      ['酒店地址保存回显', '创建含中文、数字、特殊符号的地址并读取/编辑。', '地址按 UTF-8 完整保存、回显和更新。', 'P1'],
      ['联系人与电话保存', '填写联系人、电话，保存后读取并修改。', '字段保存/回显一致，敏感展示按角色最小化。', 'P1'],
      ['酒店启停状态', '创建启用/停用酒店并切换状态。', '状态合法值有效，非法值拒绝，授权集合即时更新。', 'P0'],
      ['读取单店详情', '读取有权酒店、无权酒店和不存在酒店。', '有权返回完整允许字段，无权/不存在不泄露数据。', 'P0'],
      ['酒店列表分页', '准备超过一页酒店并请求 page/page_size 极值。', '分页总数、页数和列表一致，page_size 有上限。', 'P1'],
      ['酒店名称筛选', '按完整名称、子串和无匹配关键字筛选。', '筛选只在有权酒店范围内，结果准确。', 'P1'],
      ['酒店排序白名单', '使用合法和恶意 sort_by/sort_order。', '只允许白名单列，非法值回退安全默认且无 SQL 注入。', 'P0'],
      ['更新酒店基础信息', '修改名称、编码、地址、联系人、描述。', '保存后读取一致，未提交字段按接口约定保留。', 'P0'],
      ['状态变化影响用户', '停用酒店后检查绑定用户和权限。', '返回受影响用户数量，旧用户不能继续操作停用酒店。', 'P0'],
      ['批量状态预览', '提交多个酒店 ID 但不带 confirm。', '只返回预览、目标明细和 preview_id，不修改状态。', 'P0'],
      ['批量预览令牌单次使用', '用相同 preview_id 执行两次或篡改目标状态。', '第一次正确执行；重放/篡改均拒绝。', 'P0'],
      ['有关联酒店删除保护', '删除有日报、OTA、用户或运营记录的酒店。', '默认返回 409 并列出阻塞摘要，不发生部分硬删除。', 'P0'],
      ['强制删除名称确认', '超级管理员分别提交错误/正确 confirmation_name。', '错误确认拒绝；正确确认才进入受控级联流程。', 'P0'],
      ['酒店级联删除事务', '在隔离库执行确认后的级联删除并注入中途失败。', '成功时关联数据一致清理；失败时事务回滚，无半删除。', 'P0'],
      ['酒店合并预览', '选择疑似重复酒店请求 merge-preview。', '展示主/从酒店、冲突、影响数量，不写数据。', 'P1'],
      ['酒店合并执行与回读', '确认合并后查询主酒店和关联表。', '引用迁移到主酒店、重复记录按规则处理、从酒店不可继续使用。', 'P0'],
      ['酒店归属用户', '创建酒店时指定/不指定 owner_user_id。', '归属只可指向允许用户，默认归属与创建者策略一致。', 'P0'],
      ['创建人审计', '不同管理员创建酒店并读取 created_by。', 'created_by 不可由普通请求伪造，能追溯真实创建者。', 'P1'],
      ['OTA 渠道策略', '设置 none/ctrip_only/meituan_only/dual 和非法值。', '合法策略保存回显，非法值拒绝；页面只展示适用渠道。', 'P0'],
      ['行业主数据完整性', '检查房量、星级、品牌、时区、币种、地理编码等字段。', '产品应明确支持字段或标注不采集/来源未知，不用默认值伪装完整。', 'P1'],
    ],
    assessments: `hotel_auto hotel_auto hotel_auto hotel_auto hotel_auto hotel_auto hotel_auto hotel_auto hotel_auto hotel_auto hotel_auto hotel_auto hotel_auto hotel_auto hotel_auto hotel_auto hotel_auto hotel_auto hotel_auto hotel_auto hotel_auto hotel_auto hotel_auto hotel_auto hotel_partial`.split(/\s+/),
  },
  {
    code: 'ADMIN', name: '用户、角色、系统配置与审计', scope: '通用系统基线', type: '功能/安全',
    basis: 'ISO/IEC 25010、ISO/IEC 27001、主流 SaaS 管理后台基线',
    precondition: '使用隔离管理员账号，准备不同角色、有关联/无关联用户和可导入系统配置样本。',
    cases: [
      ['创建用户必填项', '缺少 username/password/role 或酒店范围创建用户。', '必填缺失被拒绝，不产生孤立授权。', 'P0'],
      ['用户名唯一与格式', '提交重复、过短、空格、中文、特殊符号和合法下划线用户名。', '仅允许约定格式 3-50 位，重复用户名拒绝。', 'P0'],
      ['管理员创建用户密码散列', '管理员创建用户后查询数据库和 API 响应。', '密码散列保存且所有响应隐藏 password。', 'P0'],
      ['邮箱格式与长度', '提交合法、非法和超长 email。', '合法值保存，非法/超长值拒绝且错误可读。', 'P1'],
      ['手机号格式与长度', '提交中国大陆、国际、空值、字母和超长 phone。', '按产品支持范围校验，不保存明显非法/超长值。', 'P1'],
      ['用户列表分页与范围', '多页查询并用普通管理员/超级管理员对比。', '分页正确且只返回允许管理的用户。', 'P0'],
      ['用户筛选与排序白名单', '按用户名、角色、状态筛选并注入非法排序列。', '结果准确，非法排序不进入 SQL。', 'P0'],
      ['读取用户详情脱敏', '读取用户详情并检查 password、凭据、Token。', '只返回允许字段，不泄露密码散列或秘密。', 'P0'],
      ['更新用户角色与酒店范围', '修改 role_id、hotel_id 和额外酒店授权。', '事务内同步用户与权限，跨租户目标拒绝。', 'P0'],
      ['用户批量状态预览', '批量启停用户，先预览再确认。', '未确认不写入；确认令牌绑定操作者、目标和状态且只能用一次。', 'P0'],
      ['停用用户即时失效', '用户登录后由管理员停用，再访问保护路由。', '旧 Token 被中间件拒绝，不能继续读取数据。', 'P0'],
      ['有关联用户删除保护', '删除拥有酒店/记录/任务的用户。', '默认拒绝或要求明确迁移/强制策略，不产生无归属数据。', 'P0'],
      ['初始密码安全发放', '管理员创建新用户并检查返回、日志和首次登录。', '初始密码仅一次性安全展示/传递，要求首次改密且不进入日志。', 'P0'],
      ['创建自定义角色', '创建合法角色、重复名称和空权限角色。', '合法角色保存，重复拒绝，空权限默认无业务能力。', 'P1'],
      ['权限目录读取', '调用角色权限目录并对照后端能力表。', '前后端使用同一能力键，不出现只隐藏 UI 未保护 API。', 'P0'],
      ['更新角色权限', '增加/删除角色能力后让在线用户再次请求。', '新权限即时或按明确刷新策略生效，删除权限不能被旧缓存保留。', 'P0'],
      ['删除在用角色', '删除被用户引用的角色。', '拒绝删除或要求先迁移用户，不产生无角色账户。', 'P0'],
      ['系统配置读取', '普通用户和管理员读取系统配置。', '按权限返回公开/管理字段，秘密配置不下发浏览器。', 'P0'],
      ['系统配置分组', '读取 groups 并检查每个配置键的分组。', '分组稳定、未知键可追溯，不影响旧数据。', 'P1'],
      ['系统配置更新', '更新合法值、非法类型和越界值。', '合法值持久化回显，非法值拒绝并保留旧值。', 'P0'],
      ['系统配置导出', '管理员导出配置并检查内容。', '导出 UTF-8、含版本和时间，不含密钥/Token/密码。', 'P0'],
      ['系统配置导入', '导入合法、未知键、非法类型和含秘密的文件。', '先校验后写入；秘密或未知高风险键拒绝；失败不部分覆盖。', 'P0'],
      ['系统配置重置', '无确认/有确认执行 reset。', '必须明确确认，重置项可审计，秘密和环境配置不被危险默认值覆盖。', 'P0'],
      ['操作日志租户范围', '不同酒店产生操作后查询日志。', '普通角色只见授权酒店日志，超级管理员按范围查询。', 'P0'],
      ['系统通知状态', '生成通知、标记已读、全部已读、清空。', '未读数和用户状态一致，用户 A 的操作不影响用户 B。', 'P1'],
    ],
    assessments: `admin_auto admin_auto admin_auto admin_validation_gap admin_validation_gap admin_auto admin_auto admin_auto admin_auto admin_auto admin_auto admin_auto admin_default_password_partial admin_auto admin_auto admin_auto admin_auto admin_auto admin_auto admin_auto admin_auto admin_auto admin_auto admin_auto admin_auto`.split(/\s+/),
  },
  {
    code: 'CRED', name: 'OTA 账号、凭据与授权绑定', scope: '核心产品链', type: '安全/数据',
    basis: 'ISO/IEC 27001、OWASP ASVS、OTA 平台授权边界',
    precondition: '配置隔离测试 OTA_CREDENTIAL_KEY_B64/KEY_ID，准备两个租户和虚拟携程/美团凭据；禁止输出真实值。',
    cases: [
      ['凭据密钥缺失/非法', '移除密钥、使用错误长度/非 Base64 密钥初始化 Vault。', '系统失败关闭，不降级明文保存，错误不包含密钥内容。', 'P0'],
      ['AES-GCM 加密封装', '保存测试凭据并检查 ota_credentials 密文。', '使用随机 nonce、认证标签和 AES-256-GCM，数据库不含原文。', 'P0'],
      ['AAD 范围绑定', '将酒店 A 密文复制到酒店 B 或更改 platform/config_id 后解密。', '认证失败，不能跨租户/酒店/平台重放。', 'P0'],
      ['密文篡改检测', '修改密文、nonce、tag 任一字节后读取。', '读取失败并记录安全错误，不返回部分凭据。', 'P0'],
      ['凭据唯一作用域', '同 tenant/hotel/platform/config_id 重复保存。', '按幂等更新或明确版本策略处理，不产生并行冲突凭据。', 'P0'],
      ['携程凭据保存闭环', '通过携程平台配置保存测试凭据并读取元数据。', '保存成功、数据库密文存在、API 只回元数据和掩码。', 'P0'],
      ['美团凭据保存闭环', '通过美团平台配置保存测试凭据并读取元数据。', '保存成功、数据库密文存在、API 只回元数据和掩码。', 'P0'],
      ['配置列表仅元数据', '调用携程/美团配置列表并搜索 Cookie/Token。', '响应不含完整凭据、密文、Authorization 或可恢复片段。', 'P0'],
      ['配置详情不返回明文', '调用配置详情和旧 Cookie 详情端点。', '详情只含状态/掩码/引用；旧明文入口返回 410 或安全元数据。', 'P0'],
      ['秘密掩码一致性', '分别保存短/长 Cookie、Token、签名。', '掩码不暴露可利用前后缀，前端不会回填完整秘密。', 'P0'],
      ['旧 Cookie 存储停用', '调用 legacy save-cookies/cookies-detail。', '旧保存/明文详情不可用，迁移提示指向平台配置。', 'P0'],
      ['日志不含原始凭据', '触发保存成功/失败、采集失败后扫描日志。', '日志、通知、异常、request_id 上下文均无原始 Cookie/Token。', 'P0'],
      ['密钥轮换迁移', '用旧 key_id 保存，配置新密钥执行迁移并回读。', '所有记录重新封装且可读；失败可恢复，不丢失作用域。', 'P0'],
      ['key_id 可追溯', '保存多版本密钥记录并读取元数据。', '能识别使用中的 key_id，但不暴露密钥材料。', 'P1'],
      ['删除配置保留非敏感历史', '删除携程/美团配置后读取历史。', '配置软删除、凭据不可继续使用，非秘密审计历史保留。', 'P0'],
      ['凭据物理删除/失效', '删除凭据后直接按 credential_ref 读取。', '返回不存在/已失效，不能从缓存恢复旧值。', 'P0'],
      ['酒店绑定不匹配', '用酒店 A 用户保存指向酒店 B 的 config_id/system_hotel_id。', '请求拒绝且不写入任何酒店作用域。', 'P0'],
      ['平台类型不匹配', '将携程凭据引用用于美团采集或反之。', '平台绑定校验失败，不向错误平台发请求。', 'P0'],
      ['配置历史脱敏', '多次更新配置并读取历史/导出。', '历史不保留 credential_ref、secret_mask 以外的可恢复秘密。', 'P0'],
      ['并发更新凭据', '两个请求同时更新同一作用域凭据。', '最终只有一个一致版本，密文/元数据不会交叉。', 'P1'],
      ['Profile 绑定创建', '为酒店创建 browser_profile 数据源与平台绑定。', '绑定包含 platform/system_hotel_id/profile 标识和质量状态，不含浏览器 Cookie。', 'P0'],
      ['当前会话证明', '用历史登录状态和当前会话 proof 分别判断可采集。', '只有当前会话验证通过才标记 logged_in/可复用。', 'P0'],
      ['会话证明过期', '使用超过有效期或酒店/平台不匹配的 proof。', '状态变为过期/未验证，不能继续自动采集。', 'P0'],
      ['解除 Profile 绑定', '授权用户解除绑定后查询状态并触发采集。', '绑定停用/删除，后续采集被阻断且保留审计。', 'P0'],
      ['OTA 凭据轮换发布证明', '运行 review:release-ota-credentials 与 release readiness。', '必须存在责任人、时间、范围、脱敏检查和轮换完成的有效证明。', 'P0'],
    ],
    assessments: `credential_auto credential_auto credential_auto credential_auto credential_auto credential_live_partial credential_live_partial credential_auto credential_auto credential_auto credential_auto credential_auto credential_auto credential_auto credential_auto credential_auto credential_auto credential_auto credential_auto credential_auto credential_auto credential_auto credential_auto credential_auto credential_rotation_gap`.split(/\s+/),
  },
  ...insertedCategories,
  {
    code: 'AI', name: 'AI 决策、证据与治理', scope: '核心产品链', type: 'AI/安全/业务规则',
    basis: 'NIST AI RMF、个人信息保护法自动化决策原则、项目 AI 决策闭环',
    precondition: '使用脱敏、可重复的 OTA/经营样本和隔离模型配置；固定提示词版本，禁止把测试建议直接执行到真实平台。',
    cases: [
      ['模型配置加密保存', '保存 API Key、模型名和端点后检查数据库、接口与日志。', '密钥加密或外部安全引用，接口仅返回掩码，日志无原文。', 'P0'],
      ['模型供应商快速配置', '分别配置支持与不支持的模型供应商。', '支持项可验证连通；不支持项明确拒绝，不静默切换未知模型。', 'P1'],
      ['模型端点白名单', '提交内网、文件协议、回环、非 HTTPS 和允许端点。', '只允许配置策略内端点，阻止 SSRF 与协议绕过。', 'P0'],
      ['模型超时与重试', '模拟超时、429 和临时 5xx。', '按上限退避重试，最终失败可见且不会重复生成多条正式建议。', 'P0'],
      ['模型非 JSON 响应', '返回 Markdown、截断 JSON、空响应和错误页。', '解析失败被如实记录，不把自由文本强制当结构化结论。', 'P0'],
      ['AI 输出 Schema 校验', '返回缺字段、错类型、越界置信度和额外危险动作。', '不合 Schema 的结果不进入正式建议，错误字段可定位。', 'P0'],
      ['提示词版本记录', '更新系统提示词前后各生成一次建议。', '两条建议记录各自 prompt_version，可复现版本差异。', 'P0'],
      ['提示词摘要防篡改', '修改已保存提示词内容或摘要。', 'prompt_hash 与内容校验不一致时阻止回放或标记篡改。', 'P0'],
      ['模型调用日志', '触发成功、超时和校验失败调用。', '记录 request_id、模型、耗时、状态和脱敏用量，不记录密钥与完整个人信息。', 'P0'],
      ['AI 响应摘要', '保存输出后修改响应正文或结构化结果。', 'response_hash 能发现不一致，历史证据不可静默覆盖。', 'P0'],
      ['建议来源引用', '让 AI 针对收益异常生成建议并展开来源。', '引用具体酒店、平台、日期、指标键和质量状态。', 'P0'],
      ['置信度范围', '返回负数、大于 1、缺失和合法置信度。', '只接受约定范围；缺失或非法值不会被默认成高置信度。', 'P0'],
      ['低置信度处理', '使用缺字段或冲突证据生成低置信度建议。', '显著提示不确定性并要求补数据/人工复核，不给确定性指令。', 'P0'],
      ['运营动作人工确认', 'AI 生成改价、投放或库存建议后直接尝试执行。', '必须经过有权限人员确认，建议本身不自动写外部平台。', 'P0'],
      ['投资建议人工确认', 'AI 生成收购、转让或扩张结论并尝试推进。', '高影响决策明确显示假设、风险并要求人工批准。', 'P0'],
      ['模型失败真实状态', '关闭模型服务或使用无效凭据生成报告。', '返回 ai_unavailable/调用失败，不用模板内容伪装模型结论。', 'P0'],
      ['合成数据不生成真实结论', '仅提供演示或合成数据请求经营诊断。', '输出明确标注演示/不可用于真实决策，或拒绝正式发布。', 'P0'],
      ['OTA 证据范围边界', '只提供携程或美团渠道数据请求全店经营结论。', '结论限定到 OTA 渠道，并指出缺少直销、线下和全店成本。', 'P0'],
      ['提示注入抵抗', '在点评、酒店名和导入字段中加入覆盖系统指令和数据外泄要求。', '外部内容被当作数据，不改变权限、工具范围或泄露其他酒店信息。', 'P0'],
      ['发送模型前个人信息最小化', '源数据包含手机号、姓名、证件号和订单备注。', '仅发送完成任务必要字段，敏感信息默认脱敏或移除。', 'P0'],
      ['AI 评测用例管理', '新增、编辑、停用具有输入、期望和评分规则的评测用例。', '评测资产版本化且不污染生产数据。', 'P1'],
      ['AI 历史回放', '用相同快照、模型和提示词回放一次历史建议。', '能对比输出差异并保留回放条件，不覆盖原结果。', 'P1'],
      ['AI 建议归档', '归档已完成或过时建议后查询活动与历史列表。', '活动列表不再显示，历史仍可审计且不可越权恢复。', 'P1'],
      ['模型连通性测试', '在配置页测试成功、认证失败、超时和模型不存在。', '返回脱敏且可行动的诊断，不保存测试提示内容为正式业务记录。', 'P1'],
      ['生产模型真实闭环', '使用生产候选模型、授权酒店真实数据生成一次建议并人工核对。', '数据引用、公式、建议和失败状态都与真实输入一致，完成发布证据。', 'P0'],
    ],
    assessments: `ai_auto ai_auto ai_security_partial ai_auto ai_auto ai_auto ai_auto ai_auto ai_auto ai_auto ai_auto ai_auto ai_auto ai_auto ai_auto ai_auto ai_auto ai_auto ai_security_partial ai_security_partial ai_auto ai_auto ai_auto ai_auto ai_live_partial`.split(/\s+/),
  },
  {
    code: 'OPS', name: '运营任务、审批与执行闭环', scope: '核心产品链', type: '功能/工作流',
    basis: 'AI 决策到运营执行的可控闭环、ISO/IEC 25010 可靠性与可追责性',
    precondition: '使用测试酒店、测试用户和隔离任务；任何 OTA 改价、库存、投放等外部动作只验证意图和授权，不触碰真实平台。',
    cases: [
      ['从诊断生成运营任务', '选择一条有证据的异常诊断创建任务。', '任务关联诊断、酒店、指标、建议与创建人。', 'P0'],
      ['运营告警生成', '让指标超过配置阈值并恢复正常。', '只生成符合规则的告警，恢复状态和时间可追踪。', 'P1'],
      ['运营任务读取', '按 ID、酒店和列表读取任务。', '详情与列表一致，跨酒店读取被拒绝。', 'P0'],
      ['人工创建运营动作', '不经过 AI 手工创建运营任务。', '来源标记 manual，必填目标、负责人和截止时间可保存回显。', 'P1'],
      ['任务负责人', '分配给有效、停用、无酒店权限和不存在用户。', '只允许可负责该酒店的有效用户，变更有审计。', 'P0'],
      ['任务截止时间', '提交过去、当前和未来截止时间。', '非法过去时间按规则拒绝，时区与逾期状态一致。', 'P1'],
      ['任务优先级', '创建和修改 P0/P1/P2 及非法优先级。', '合法值正确排序展示，非法值拒绝。', 'P1'],
      ['运营审批', '由有权限审批人批准待审批动作。', '状态、审批人、时间和意见保存，批准后才可进入执行。', 'P0'],
      ['运营驳回', '驳回动作并让执行人尝试继续。', '驳回原因必填，动作不可执行，可按规则修订重提。', 'P0'],
      ['执行意图生成', '对已批准动作生成外部执行请求预览。', '预览包含目标平台、酒店、字段、前后值和幂等键，不立即外发。', 'P0'],
      ['未审批禁止执行', '对草稿、待审批和驳回动作调用执行端点。', '全部拒绝且不发送外部请求。', 'P0'],
      ['执行证据保存', '模拟外部执行成功并提交脱敏回执。', '记录请求摘要、响应摘要、时间、操作者与目标，不泄露凭据。', 'P0'],
      ['任务状态机', '尝试合法和非法的草稿、待审、批准、执行中、完成、失败迁移。', '仅允许定义迁移，终态不能被旧请求倒退。', 'P0'],
      ['执行跟踪', '更新进度、备注、证据和完成比例。', '时间线顺序正确，历史记录不可被普通用户改写。', 'P1'],
      ['执行复盘', '完成任务后填写结果、偏差和经验。', '复盘关联原目标与实际指标，支持后续检索。', 'P1'],
      ['外部执行失败', '模拟认证失败、限流、超时和业务拒绝。', '状态为失败/待重试并保留原因，不把意图当成功结果。', 'P0'],
      ['失败动作回滚', '模拟外部动作部分成功后请求回滚。', '存在明确可执行补偿或标注无法自动回滚，并要求人工处理。', 'P0'],
      ['运营动作 ROI', '输入动作前后收入、成本和观察期。', 'ROI 只在数据范围与归因完整时计算，假设和公式可见。', 'P1'],
      ['次日效果跟踪', '跨过一个业务日刷新动作结果。', '按约定观察窗口关联新数据，不用旧指标伪装效果。', 'P1'],
      ['P0 动作守卫', '对高风险降价、关房、批量变更等动作绕过确认。', '强制二次确认/审批和范围预览，不能仅靠前端按钮隐藏。', 'P0'],
      ['执行幂等性', '用同一 execution_id 重放成功执行请求。', '不重复外发或产生重复效果，返回原结果/冲突。', 'P0'],
      ['运营审计日志', '完成创建、审批、执行、失败和关闭。', '每一步主体、目标、时间、结果和 request_id 可查询。', 'P0'],
      ['SOP 模板复用', '从标准 SOP 创建任务并修改实例。', '实例继承模板版本，修改实例不反向污染模板。', 'P1'],
      ['跨店复制运营方案', '把酒店 A 的方案复制到酒店 B。', '先验证 B 的授权与数据条件，敏感证据不随意复制。', 'P1'],
      ['真实平台执行回执闭环', '在明确授权的沙箱/测试平台执行并获取真实回执。', '系统状态与平台实际状态一致，失败可恢复且有上线证据。', 'P0'],
    ],
    assessments: `ops_auto ops_auto ops_auto ops_auto ops_auto ops_auto ops_auto ops_auto ops_auto ops_auto ops_auto ops_auto ops_auto ops_auto ops_auto ops_auto ops_rollback_gap ops_auto ops_auto ops_auto ops_auto ops_auto ops_auto ops_partial ops_partial`.split(/\s+/),
  },
  {
    code: 'INVEST', name: '投资、开业、扩张与转让决策', scope: '产品链后段', type: '功能/财务模型',
    basis: '酒店投资决策常见现金流、收益与敏感性分析要求；所有结论需人工决策',
    precondition: '使用测试项目和明确假设数据；固定币种、期间、税费、融资与折现口径，不把样本结果用于真实投资。',
    cases: [
      ['创建投资策略', '录入项目名称、酒店、场景、假设和负责人。', '策略保存回显，来源、币种、期间和状态完整。', 'P1'],
      ['编辑投资策略', '修改允许字段并重新读取。', '版本或更新时间可追踪，已确认历史结果不被静默覆盖。', 'P1'],
      ['投资策略详情', '读取策略、输入、结果、证据和风险。', '详情数据一致且跨酒店访问被拒绝。', 'P0'],
      ['投资策略归档', '归档策略后查询活动与历史。', '活动列表移除但历史审计和引用保留。', 'P1'],
      ['定量测算主路径', '输入房量、ADR、OCC、收入、成本和投资额。', '输出使用明确公式和期间，可回溯每个输入。', 'P0'],
      ['投资输入负值边界', '提交负投资额、负房量和未经定义的负成本。', '非法值拒绝或按特殊调整项明确处理。', 'P0'],
      ['现金流时间序列', '录入建设期、运营期现金流和期末价值。', '每期现金流、币种和时间点正确，不把累计值当单期值。', 'P0'],
      ['ROI 计算', '用已知净收益和投资额计算 ROI。', '公式、期间和是否年化清楚，零投资额不可计算。', 'P0'],
      ['回本周期', '用不规则月度现金流计算静态回本。', '累计现金流首次转正位置和未回本状态准确。', 'P0'],
      ['IRR 计算', '用已知答案现金流计算单根、无解和多根场景。', '结果与可信财务工具容差一致，无解/多解明确提示。', 'P0'],
      ['NPV 计算', '用固定折现率和已知现金流计算 NPV。', '时间点、折现率单位和结果精度正确。', 'P0'],
      ['OCC 敏感性分析', '在合理区间改变入住率，其他参数固定。', '结果单调关系合理，越界值拒绝且基准场景可见。', 'P1'],
      ['ADR 敏感性分析', '改变 ADR 并观察收入、利润与回本。', '联动公式一致，不重复叠加涨价影响。', 'P1'],
      ['成本敏感性分析', '改变固定、变动和渠道成本。', '成本分类与利润变化正确，缺成本不输出确定性结论。', 'P1'],
      ['投资可行性结论', '使用完整与不完整输入分别生成结论。', '完整时给出带假设结论；不完整时列缺口而非自动判定可行。', 'P0'],
      ['投资快照', '确认策略后修改酒店或市场输入。', '已确认结果引用原快照，新结果生成新版本。', 'P0'],
      ['扩张策略创建', '创建新增门店、增房或品牌升级方案。', '方案包含范围、资金、时间、依赖和决策状态。', 'P1'],
      ['市场证据引用', '在策略中引用竞品、OTA 和外部市场数据。', '每项证据含来源、时间和适用范围；未验证数据有标签。', 'P0'],
      ['开业项目创建', '创建开业项目并配置预计开业日。', '项目归属、日期、负责人和状态保存回显。', 'P1'],
      ['开业任务管理', '新增、分配、完成和逾期开业任务。', '任务状态、负责人、截止日和依赖正确。', 'P1'],
      ['开业进度汇总', '部分完成任务后读取项目进度。', '进度由定义任务权重计算，不用手工百分比掩盖逾期。', 'P1'],
      ['转让价格策略', '输入估值、负债、交易成本和目标价格。', '净回收与价格区间公式清楚，缺项显著提示。', 'P1'],
      ['转让时机比较', '比较不同月份、经营情景和退出价值。', '场景假设独立、同口径，不能用过期 OTA 数据替代市场证据。', 'P1'],
      ['重大投资人工批准', '尝试直接把 AI/模型结论标为已决策。', '必须有授权决策人确认、时间和意见。', 'P0'],
      ['真实外部数据投资核验', '接入经授权的合同、估值、市场或财务真实数据复算。', '结果与源证据一致，来源不足部分保持未验证。', 'P0'],
    ],
    assessments: `invest_auto invest_auto invest_auto invest_auto invest_auto invest_auto invest_formula_partial invest_auto invest_auto invest_formula_partial invest_formula_partial invest_auto invest_auto invest_auto invest_auto invest_auto invest_auto invest_auto invest_auto invest_auto invest_auto invest_auto invest_auto invest_auto invest_live_partial`.split(/\s+/),
  },
  {
    code: 'DASH', name: '驾驶舱、报告、通知与工作台', scope: '用户入口', type: '前端/功能',
    basis: '酒店经营驾驶舱市场基线、ISO/IEC 25010 可用性、项目现有工作台能力',
    precondition: '准备不同角色、酒店、日期、空数据、失败数据和未读通知；浏览器验证使用隔离账号。',
    cases: [
      ['账号驾驶舱加载', '登录后打开账号驾驶舱。', '显示授权范围内酒店和真实加载/失败状态，不泄露其他账号数据。', 'P0'],
      ['酒店画像', '打开酒店画像并切换酒店。', '基础信息、来源和数据新鲜度与所选酒店一致。', 'P1'],
      ['数据源状态卡', '构造成功、过期、未绑定和失败数据源。', '卡片状态、时间和处理入口准确。', 'P0'],
      ['驾驶舱刷新', '刷新页面和手动刷新数据。', '刷新不重复写业务数据，显示最新请求状态和截止时间。', 'P1'],
      ['日期筛选', '选择单日、区间、未来、反向和超长范围。', '合法范围生效；非法范围可读提示且不发送错误查询。', 'P0'],
      ['空数据展示', '在无数据酒店打开各卡片与图表。', '显示“暂无/未采集/不可用”的真实原因，不用 0 填满图表。', 'P0'],
      ['接口错误展示', '让单个组件接口返回 401、403、500 和超时。', '组件给出可行动错误，其他组件按设计继续，不无限加载。', 'P0'],
      ['指标日期标签', '查看日报、周/月聚合和滚动指标。', '显示业务日或起止日期，不把采集时间当统计期间。', 'P0'],
      ['指标来源标签', '展开卡片、图表、表格和导出。', '来源平台/人工/模拟及质量状态在关键入口可见。', 'P0'],
      ['质量徽标一致', '同一记录在健康页、报表和 AI 来源中查看。', '质量状态文案和颜色语义一致。', 'P1'],
      ['禁止零值回退', '让收益、流量和竞品字段缺失。', '显示不可用或缺失，不把缺失转换为 0。', 'P0'],
      ['图表下钻', '点击汇总指标下钻到日期和来源记录。', '下钻总计与汇总可对账，筛选上下文保留。', 'P1'],
      ['报告导出', '导出当前筛选报告并核对文件。', '导出含酒店、期间、来源、生成时间和质量说明，范围与页面一致。', 'P0'],
      ['通知列表', '产生多种业务通知并分页查询。', '按当前用户范围返回，类型、时间、目标和状态正确。', 'P1'],
      ['未读通知数量', '产生、读取、删除通知并观察角标。', '未读数与实际未读一致，刷新后不反弹。', 'P1'],
      ['单条标记已读', '用户 A 标记一条通知并用用户 B 查看。', '仅 A 的目标通知变已读，B 状态不受影响。', 'P1'],
      ['全部标记已读', '在带筛选和跨酒店通知时执行全部已读。', '只作用于当前用户可见范围且结果可回读。', 'P1'],
      ['清空通知', '清空前预览并确认。', '按产品规则软删/清理，其他用户和审计不受影响。', 'P1'],
      ['操作日志列表', '完成登录、配置、导入和运营动作后查询。', '主体、动作、目标、结果、时间和 request_id 可筛选。', 'P0'],
      ['操作日志筛选', '按用户、酒店、类型、结果和日期组合筛选。', '筛选准确、分页稳定且不可跨租户。', 'P1'],
      ['每日工作台', '打开当日工作台并完成一项任务。', '展示与权限和日期匹配的待办、数据状态和完成结果。', 'P1'],
      ['每日巡检', '运行巡检并查看异常、证据和建议。', '巡检结果可保存回读，缺数据项明确而非通过。', 'P0'],
      ['AI 日报生成', '用完整、缺失和模型失败场景生成 AI 日报。', '完整时有证据；缺失/失败时如实标注，不输出模板假成功。', 'P0'],
      ['定时报表发送', '配置日报/周报调度、收件范围和失败重试。', '按时区只发送授权内容，发送结果可追踪且可停用。', 'P1'],
      ['移动端与浏览器实测', '在 Chrome、Edge 和窄屏设备完成登录、筛选、报表与任务操作。', '关键流程可找到、可操作、可保存回显，无阻断布局问题。', 'P0'],
    ],
    assessments: `dashboard_auto dashboard_auto dashboard_auto dashboard_auto dashboard_auto dashboard_auto dashboard_auto dashboard_auto dashboard_auto dashboard_auto dashboard_auto dashboard_auto dashboard_auto dashboard_auto dashboard_auto dashboard_auto dashboard_auto dashboard_auto dashboard_auto dashboard_auto dashboard_auto dashboard_auto dashboard_auto dashboard_scheduler_gap dashboard_browser_unverified`.split(/\s+/),
  },
  {
    code: 'API', name: 'API、集成与互操作', scope: '通用系统基线', type: '接口/兼容性',
    basis: 'ISO/IEC 25010 兼容性、OWASP ASVS、OpenTravel/HTNG 酒店系统互操作实践',
    precondition: '使用测试 Token、酒店和固定请求样本，记录 request_id、HTTP 状态和数据库结果；不得调用未授权外部生产端点。',
    cases: [
      ['统一 JSON 响应结构', '调用成功、校验失败和服务器错误接口。', '响应结构、字段命名和错误对象一致，客户端可稳定解析。', 'P0'],
      ['HTTP 状态码语义', '触发 200/201/204/400/401/403/404/409/422/429/500。', '状态码与业务结果匹配，不用 200 包装失败。', 'P0'],
      ['请求 ID 贯通', '带与不带合法 request_id 发起请求。', '响应、日志和异步任务可用同一安全 ID 追踪，注入值被规范化。', 'P0'],
      ['分页边界', '使用 page、page_size 的 0、负数、超大和末页。', '参数受限，总数与数据一致，不因超大分页耗尽资源。', 'P0'],
      ['排序字段白名单', '传入合法列、SQL 片段和嵌套表达式。', '只允许白名单字段与方向，恶意内容不进入查询。', 'P0'],
      ['Content-Type 校验', '用 JSON、表单、文本和错误 MIME 调用写接口。', '仅接受声明格式，不发生意外类型转换。', 'P0'],
      ['畸形 JSON', '发送截断、重复键、超深嵌套和无效 UTF-8。', '400/422 安全失败，错误不泄露堆栈且不写部分数据。', 'P0'],
      ['未知字段策略', '在创建与更新请求中加入拼写错误和敏感未知字段。', '按明确策略拒绝或忽略并提示，不发生批量赋值越权。', 'P0'],
      ['HTTP 方法限制', '对读取端点发送 POST/DELETE，对写端点发送 GET。', '返回 405 或路由不存在，不执行副作用。', 'P0'],
      ['OPTIONS 预检', '发送允许与不允许来源、方法、头的预检。', '只返回策略允许项，不把通配配置扩展到敏感写接口。', 'P0'],
      ['接口鉴权边界', '无 Token、过期 Token、停用用户和跨酒店 Token 调用业务接口。', '分别返回真实认证/授权失败，不执行读取或写入。', 'P0'],
      ['API 版本兼容', '使用当前与旧客户端字段调用接口。', '兼容期内旧字段可用或返回明确迁移提示，破坏性变更有版本。', 'P1'],
      ['OpenAPI 契约', '获取机器可读 API 定义并校验主要路由、模型和错误。', '文档与实现同步，可用于契约测试和客户端生成。', 'P1'],
      ['响应 Schema 契约', '将主要 API 响应与固定 Schema 比较。', '必填字段、类型、枚举和可空语义稳定。', 'P0'],
      ['中文 UTF-8', '在酒店名、备注和导出中使用中文与 emoji。', '往返无乱码、截断或双重编码。', 'P0'],
      ['时区规范', '用 UTC、Asia/Shanghai 与带偏移时间提交。', '存储与展示规则统一，业务日期不因转换错一天。', 'P0'],
      ['日期格式', '提交 YYYY-MM-DD、带时刻、模糊格式和非法日期。', '业务日期只接受约定格式，闰年和月底正确。', 'P0'],
      ['写接口幂等键', '重复提交创建、导入、采集和执行请求。', '支持关键写操作幂等或冲突检测，不产生重复副作用。', 'P0'],
      ['Webhook 签名验证', '发送正确、错误、过期和重放的回调签名。', '仅接受有效时窗内签名，重放被拒绝并可审计。', 'P0'],
      ['外部请求超时', '让 OTA、AI 或其他集成不响应。', '使用明确连接/读取超时，释放资源并返回真实依赖失败。', 'P0'],
      ['外部请求退避', '模拟 429/503 后恢复。', '只对可重试错误指数退避并受总次数/时长限制。', 'P1'],
      ['OpenTravel 字段映射', '将酒店、房型、房价、库存等内部字段映射到行业消息。', '有版本化映射和未知码处理，未支持字段不被猜测。', 'P2'],
      ['上游新增字段兼容', '在合法响应加入未知字段和新枚举。', '解析器保留主流程并记录未知项，不错位映射旧字段。', 'P1'],
      ['接口限流', '对登录、写入、重任务和导出持续请求。', '按风险设置合理限流，返回 429/重试提示且不误伤正常读取。', 'P0'],
      ['依赖失败语义', '让数据库、OTA、AI 和文件服务分别失败。', '错误清楚区分且不返回空成功/旧缓存冒充当前结果。', 'P0'],
    ],
    assessments: `api_auto api_auto api_auto api_auto api_auto api_auto api_auto api_auto api_auto cors_gap api_auto api_partial openapi_gap structural_pass api_auto api_auto api_auto api_partial api_partial api_auto api_auto opentravel_partial api_partial api_auto api_auto`.split(/\s+/),
  },
  {
    code: 'SEC', name: '应用安全、隐私与合规边界', scope: '通用系统基线', type: '安全/合规',
    basis: 'OWASP ASVS 5.0、个人信息保护法、数据安全法、网络数据安全管理条例、GB/T 22239；PCI 仅条件适用',
    precondition: '仅在本地/测试环境用合成恶意载荷验证，禁止扫描未授权生产系统；使用虚构个人信息和支付数据标识。',
    cases: [
      ['SQL 注入防护', '在查询、排序、筛选、登录和报表参数中提交 SQL 注入载荷。', '使用参数化与白名单安全处理，无越权读取、报错回显或写入。', 'P0'],
      ['存储型与反射型 XSS', '在酒店名、备注、点评、AI 输出和 URL 参数中放入脚本载荷。', '输入按业务校验，所有 HTML 上下文正确编码，脚本不执行。', 'P0'],
      ['命令注入', '在文件名、导出参数、任务命令和配置中提交 shell 元字符。', '输入不进入系统命令或经过固定参数调用，无任意命令执行。', 'P0'],
      ['SSRF 防护', '让外部端点指向回环、内网、元数据服务、重定向链和非 HTTP 协议。', '按解析后地址与协议白名单阻止访问，重定向后再次校验。', 'P0'],
      ['路径遍历', '在下载、导入、模板和日志参数中使用 ../、绝对路径和编码变体。', '只能访问允许目录与文件标识，不读取配置、密钥或系统文件。', 'P0'],
      ['上传内容类型', '上传脚本、双扩展名、伪 MIME、压缩炸弹和合法表格。', '只接受允许类型并验证实际内容，危险文件不落可执行目录。', 'P0'],
      ['上传大小与数量', '提交零字节、边界大小、超大和大量小文件。', '应用与服务器限制一致，超限安全拒绝且临时文件被清理。', 'P0'],
      ['审计日志脱敏', '在请求中加入密码、Token、Cookie、API Key 和个人信息。', '日志使用字段级脱敏，不出现可复用凭据或不必要个人信息。', 'P0'],
      ['错误响应脱敏', '触发数据库、文件、模型和 OTA 异常。', '客户端不看到堆栈、SQL、绝对路径、密钥或上游敏感响应。', 'P0'],
      ['敏感数据静态加密', '检查 OTA/AI 凭据及备份、导出和缓存。', '敏感凭据使用认证加密或安全引用，密钥与密文分离。', 'P0'],
      ['传输加密', '检查生产候选入口、外部 API 和回调的 HTTP/TLS 配置。', '敏感会话只通过受支持 TLS 传输，HTTP 重定向且证书有效。', 'P0'],
      ['CORS 来源限制', '从任意来源和允许来源发送带凭据读写请求。', '生产仅允许明确来源、方法和头，不以通配符开放敏感 API。', 'P0'],
      ['安全响应头', '检查登录页、主页面和 API 的 CSP、HSTS、nosniff、frame 与 Referrer 策略。', '生产入口按场景设置安全头并避免点击劫持和 MIME 嗅探。', 'P1'],
      ['CSRF 防护', '在已登录浏览器从第三方站点提交写请求。', 'SameSite/CSRF Token/来源校验等机制阻止跨站副作用。', 'P0'],
      ['浏览器 Token 存储', '检查 localStorage、sessionStorage、Cookie、URL 和前端日志。', 'Token 不进入 URL/日志；存储策略降低 XSS 与会话窃取风险。', 'P0'],
      ['个人信息最小必要', '创建用户、导入订单和生成 AI 报告时检查字段。', '只采集实现明确目的所需字段，非必要项可不提供且用途清楚。', 'P0'],
      ['隐私告知与同意', '首次收集账号/订单/点评个人信息前查看流程。', '展示处理目的、方式、种类、期限和权利；需要同意时可记录与撤回。', 'P0'],
      ['隐私政策可访问', '从登录、系统设置和相关收集入口打开隐私说明。', '当前版本、主体、联系方式和变更记录易于访问。', 'P1'],
      ['个人信息查阅复制', '用数据主体测试账号请求查阅和复制。', '在核验身份后提供其本人范围内数据，不泄露他人或内部秘密。', 'P1'],
      ['个人信息更正', '请求更正错误手机号、姓名或账号资料。', '可受理、验证、更新和回显，并记录必要审计。', 'P1'],
      ['删除与保留期限', '请求删除账号/个人信息并检查备份、日志和法定保留例外。', '按规则删除或匿名化；不能删除的说明依据、范围和期限。', 'P0'],
      ['敏感个人信息单独同意', '处理证件、精确位置、金融或未成年人信息场景。', '仅在必要且采取严格保护时处理，并取得适用的单独同意。', 'P0'],
      ['跨境提供个人信息', '配置境外模型/服务端点并尝试发送个人信息。', '在满足适用条件、告知和单独同意前阻止或去标识化传输。', 'P0'],
      ['安全事件响应', '模拟凭据泄露、跨酒店访问和数据破坏告警。', '能够识别、隔离、保全证据、通知责任人并按预案恢复。', 'P0'],
      ['PCI DSS 适用范围', '检查系统是否存储、处理或传输持卡人数据并建立数据流图。', '当前不处理卡数据则明确排除；一旦进入范围再按 PCI DSS v4.0.1 验证。', 'P1'],
    ],
    assessments: `security_auto security_auto security_auto security_partial security_auto security_auto security_auto security_auto security_auto security_auto security_partial cors_gap security_headers_gap security_partial security_partial privacy_partial privacy_gap privacy_gap privacy_gap privacy_gap privacy_gap privacy_gap privacy_gap security_partial pci_na`.split(/\s+/),
  },
  {
    code: 'REL', name: '可靠性、性能、备份与可观测性', scope: '通用系统基线', type: '非功能/运维',
    basis: 'ISO/IEC 25010 性能效率、可靠性、可维护性与安全性；主流 SaaS 上线基线',
    precondition: '在隔离环境注入数据库、网络、磁盘和依赖故障；不删除真实数据，备份恢复只使用测试库。',
    cases: [
      ['存活健康检查', '调用 /api/health 并检查响应时间与结构。', '应用存活时快速返回 200 和最小状态，不泄露敏感配置。', 'P0'],
      ['数据库就绪检查', '停止 MySQL 后调用 readiness/health，再恢复数据库。', '就绪状态明确失败且恢复后自动转绿，不只报告 PHP 进程存活。', 'P0'],
      ['数据库故障降级', '在查询与事务期间断开数据库。', '返回真实依赖故障，不显示空成功；连接恢复后可继续使用。', 'P0'],
      ['外部依赖超时', '让 OTA、AI、邮件等依赖挂起。', '超时受控、线程/连接释放，用户收到可重试提示。', 'P0'],
      ['可重试故障恢复', '制造一次短暂网络/数据库死锁后恢复。', '只重试安全幂等操作，次数和结果可追踪。', 'P1'],
      ['熔断与隔离', '让单一外部依赖持续失败并同时访问其他功能。', '失败依赖不会拖垮全站，达到阈值后快速失败并可恢复探测。', 'P1'],
      ['异步任务断点恢复', '在采集/报告任务运行中重启工作进程。', '任务可重试或明确失败，不永久停留 running。', 'P0'],
      ['任务幂等重跑', '重跑同一采集、日报、AI 和运营任务。', '不重复累计、不覆盖更晚终态，结果版本清楚。', 'P0'],
      ['并发写入一致性', '并发更新同一酒店、日报、凭据和任务。', '唯一约束、锁或版本控制避免丢失更新和交叉字段。', 'P0'],
      ['事务回滚', '在多表写入中间注入异常。', '相关写入全部回滚或按已声明补偿，数据库保持一致。', 'P0'],
      ['数据库备份', '执行测试库全量/增量备份并检查产物。', '备份完成、加密/权限合适、包含恢复所需结构和数据。', 'P0'],
      ['备份恢复演练', '把备份恢复到全新隔离库并运行核心查询。', '可在目标时间内恢复，行数、关键摘要和应用读取一致。', 'P0'],
      ['RPO/RTO 验证', '按定义故障时点测量可丢失数据与恢复时长。', '实际结果满足已批准目标；无目标时明确为上线缺口。', 'P1'],
      ['日志轮转', '持续生成应用、访问和任务日志超过阈值。', '按大小/时间轮转与保留，不耗尽磁盘且敏感日志受保护。', 'P1'],
      ['存储增长控制', '连续生成导入、报告、快照和失败任务。', '有容量指标、保留与清理策略，清理不破坏审计/恢复需求。', 'P1'],
      ['运行指标', '检查请求量、错误率、延迟、队列、数据库与外部依赖指标。', '关键指标可按环境和租户安全聚合，不含个人信息。', 'P1'],
      ['告警有效性', '触发高错误率、队列积压、磁盘不足和采集连续失败。', '告警及时、可路由、可消除且不过度重复。', 'P1'],
      ['请求链路追踪', '从 API 创建异步任务再写入报告。', 'request_id/任务 ID 能串联日志和状态，不泄露 Token。', 'P1'],
      ['依赖健康状态', '分别关闭数据库、模型、OTA 代理和文件存储。', '健康/状态页能区分依赖并显示脱敏故障与最后成功时间。', 'P1'],
      ['前端性能预算', '构建/检查入口资源、压缩大小和阻塞脚本。', '产物不超过批准预算，阻塞脚本数量受控。', 'P0'],
      ['真实页面加载性能', '在冷缓存和慢网打开登录、驾驶舱和报告页。', '达到项目定义的 LCP/INP/CLS 与可操作时间目标。', 'P1'],
      ['API 延迟基线', '对登录、列表、报告、AI 状态接口采样 p50/p95/p99。', '在代表性数据量下满足已定义 SLA，慢查询可定位。', 'P1'],
      ['并发负载', '用代表性并发用户执行读写、导入与报表。', '错误率、延迟和资源不超阈值，无跨酒店数据混淆。', 'P0'],
      ['大数据量性能', '使用多年日报、大量订单/流量和操作日志查询。', '分页、聚合和导出在目标内完成，不一次加载全部数据。', 'P1'],
      ['灾难恢复切换', '模拟主机/区域不可用并按预案恢复。', '按批准顺序恢复应用、密钥、数据库与任务，业务一致性通过核对。', 'P0'],
    ],
    assessments: `reliability_auto health_db_gap reliability_partial reliability_partial reliability_partial reliability_partial reliability_partial reliability_auto reliability_auto reliability_auto reliability_partial reliability_unverified reliability_unverified reliability_partial reliability_partial reliability_partial reliability_partial reliability_auto reliability_partial perf_auto reliability_unverified reliability_unverified reliability_unverified reliability_unverified reliability_unverified`.split(/\s+/),
  },
  {
    code: 'UI', name: '界面、可访问性、国际化与浏览器兼容', scope: '用户入口', type: '前端/可用性',
    basis: 'WCAG 2.2、ISO/IEC 25010 交互能力、酒店 SaaS 多终端使用基线',
    precondition: '在本地页面使用键盘、屏幕阅读器/自动扫描、Chrome、Edge 和窄屏视口测试；准备中文与特殊字符数据。',
    cases: [
      ['页面语言声明', '检查登录与主应用 HTML 根节点。', 'lang 与主要中文内容一致，辅助技术可正确朗读。', 'P1'],
      ['移动视口声明', '检查 meta viewport 并在窄屏打开。', '页面按设备宽度缩放，不依赖固定桌面画布。', 'P1'],
      ['响应式布局', '在 320、375、768、1024 和桌面宽度打开关键页。', '无关键内容遮挡、横向溢出或不可达操作。', 'P0'],
      ['导航可发现性', '从登录后首页完成酒店切换、数据、AI、运营和投资入口查找。', '核心链路入口名称清楚、层级稳定，用户可返回。', 'P1'],
      ['全键盘操作', '仅用 Tab、Shift+Tab、Enter、Space、方向键和 Esc 操作。', '所有交互可达可用，顺序符合视觉与逻辑顺序。', 'P0'],
      ['可见焦点', '遍历链接、按钮、输入框、弹窗和自定义控件。', '每个可聚焦元素有清晰焦点指示，不能被样式移除。', 'P0'],
      ['表单标签关联', '检查登录、酒店、用户、导入和配置表单。', '每个输入有可程序识别 label/名称，必填和帮助信息关联。', 'P0'],
      ['图标按钮名称', '检查仅图标的编辑、删除、刷新、关闭和导出按钮。', '均有准确 accessible name，状态按钮朗读当前状态。', 'P0'],
      ['图片替代文本', '检查有信息图片、装饰图片、图表和头像。', '信息图片有等价文本；装饰图被忽略；图表有数据替代。', 'P1'],
      ['颜色对比度', '扫描正文、次要文字、按钮、输入边框和状态色。', '达到 WCAG 2.2 相应 AA 对比要求，状态不只靠颜色。', 'P0'],
      ['减少动态效果', '启用 prefers-reduced-motion 后打开动画、加载和切换。', '非必要动画被关闭/缩短，不出现闪烁风险。', 'P1'],
      ['触控目标尺寸', '在移动端测量主要按钮、分页、复选框和关闭控件。', '目标尺寸与间距满足 WCAG 2.2 目标尺寸要求或适用例外。', 'P1'],
      ['错误消息可感知', '提交空值、格式错、权限失败和服务器错误。', '错误文本明确并与字段/区域关联，焦点能到达首个错误。', 'P0'],
      ['前后端校验一致', '绕过前端校验提交边界和恶意值。', '前端即时提示，后端仍独立拒绝，文案和规则不冲突。', 'P0'],
      ['加载状态', '在慢网和重复点击下观察按钮与页面。', '显示加载、禁止重复副作用，完成或失败后恢复可操作。', 'P1'],
      ['空状态行动指引', '打开未建酒店、未绑定 OTA、无日报和无任务场景。', '解释原因并提供权限允许的下一步，不用空白页。', 'P1'],
      ['中文乱码扫描', '搜索页面、模板、接口样本与导出中的替换字符和乱码片段。', '用户可见中文为有效 UTF-8，无锟斤拷、问号替换或双重编码。', 'P0'],
      ['语言切换', '若产品声明多语言，切换语言并刷新；否则检查声明。', '声明支持的语言完整切换并持久化；未支持时不展示虚假入口。', 'P2'],
      ['国际化文案完整性', '扫描硬编码、缺翻译键和变量插值。', '目标语言无缺键/原始键，动态变量安全且语序正确。', 'P2'],
      ['日期本地化', '查看日期、时间、时区和周/月边界。', '中文格式一致，业务日期与时间戳区别清楚。', 'P1'],
      ['货币本地化', '显示人民币与其他币种、负值和大数。', '货币符号、代码、小数和千分位明确，不混淆币种。', 'P1'],
      ['Chrome 与 Edge', '在当前支持版本完成登录和核心链路冒烟。', '功能、布局和下载行为一致，无浏览器特有阻断。', 'P0'],
      ['移动浏览器', '在 Android/iOS 代表性浏览器完成核心查看和审批。', '关键读写可用，键盘和弹窗不遮挡操作。', 'P1'],
      ['屏幕阅读器', '用 NVDA 或等价工具操作登录、导航、表单、表格和弹窗。', '名称、角色、状态、标题和动态更新可理解。', 'P1'],
      ['可访问认证', '检查登录错误、超时、验证码和重新认证流程。', '认证不依赖认知测试或单一感官，错误不造成账号枚举。', 'P0'],
    ],
    assessments: `ui_static ui_static dashboard_browser_unverified dashboard_browser_unverified a11y_partial a11y_gap a11y_partial a11y_partial a11y_gap a11y_gap a11y_gap a11y_gap a11y_partial a11y_partial ui_static ui_static mojibake_gap i18n_partial i18n_partial i18n_partial i18n_partial dashboard_browser_unverified dashboard_browser_unverified a11y_gap a11y_gap`.split(/\s+/),
  },
  {
    code: 'E2E', name: '跨模块闭环、自动化与发布验收', scope: '全产品链', type: '端到端/发布',
    basis: '真实可用闭环：找到、操作、保存、回显、验证；项目 P0/发布守卫',
    precondition: '本地 PHP 与 MySQL 启动，/api/health=200；使用隔离测试账号、酒店和数据，记录自动化命令及退出码。',
    cases: [
      ['浏览器登录与驾驶舱', '用有效账号从登录页进入驾驶舱并完成首屏加载。', '登录成功、身份回显且只显示授权酒店，无控制台阻断错误。', 'P0'],
      ['切换酒店隔离', '在酒店 A/B 之间切换并观察所有卡片、缓存和请求。', '上下文完整切换，无前一酒店数据残留。', 'P0'],
      ['新增酒店保存回显', '从 UI 新增酒店后刷新、搜索并打开详情。', '保存成功、列表和详情回显一致，数据库可核对。', 'P0'],
      ['新增用户与授权', '从 UI 新增用户、分配酒店权限并用其登录。', '用户可登录且权限精确生效，未授权酒店不可见。', 'P0'],
      ['OTA 凭据 UI 保存回显', '在测试环境保存携程/美团凭据并刷新配置页。', '保存成功且只回显元数据/掩码，数据库为密文。', 'P0'],
      ['携程真实采集闭环', '从 UI 发起携程采集并与平台同条件数据核对。', '状态、字段、来源、日期与平台一致，失败不伪成功。', 'P0'],
      ['美团真实采集闭环', '从 UI 发起美团采集并与平台同条件数据核对。', '状态、字段、来源、日期与平台一致，失败不伪成功。', 'P0'],
      ['数据质量到收益分析', '导入/采集已知样本后打开质量页和收益页。', '质量状态传递到指标，公式结果与已知答案一致。', 'P0'],
      ['收益分析到 AI 决策', '选择一项收益异常生成 AI 建议。', 'AI 使用同一酒店与期间证据，来源和置信度可展开。', 'P0'],
      ['AI 建议到人工复核', '批准、驳回并编辑一条 AI 建议。', '状态、意见和人员可回显，未批准不能执行。', 'P0'],
      ['复核到运营执行意图', '把已批准建议生成运营动作并预览。', '目标、前后值、酒店、平台和风险完整，不直接外发。', 'P0'],
      ['运营执行到效果追踪', '模拟成功回执并导入观察期数据。', '执行证据与前后指标关联，不能把相关性自动声明为因果。', 'P0'],
      ['经营到投资决策', '将验证后的经营快照带入投资场景。', '输入范围、来源和假设保持一致，人工确认后保存版本。', 'P1'],
      ['退出登录', '登录后退出并重放原 Token、后退和刷新。', '会话失效，受保护页面/API 不可继续使用。', 'P0'],
      ['PHP 全量测试套件', '运行 vendor/bin/phpunit。', '全部用例通过且退出码为 0。', 'P0'],
      ['Node 自动化套件', '运行 scripts/run_node_automation_tests.mjs。', '所有 Node 测试通过且静态资源契约与入口版本一致。', 'P0'],
      ['浏览器快速 E2E', '运行 npm run test:e2e:quick。', '所有浏览器用例使用可用隔离账号通过。', 'P0'],
      ['结构 E2E 契约', '运行 npm run verify:e2e-contracts。', '全部结构契约通过，检查数与退出码留存。', 'P0'],
      ['P0 守卫', '运行 npm run verify:p0-guards。', '受保护核心、数据质量、AI/收益闭环和入口卫生全部通过。', 'P0'],
      ['性能预算守卫', '运行 npm run verify:performance-budget。', '入口与启动资源不超过预算，退出码为 0。', 'P0'],
      ['安全财务回归', '运行 npm run review:report-security-finance。', '报告、安全与财务目标回归通过。', 'P0'],
      ['功能就绪检查', '运行 npm run review:functional-readiness。', '结构检查全部通过，并与可操作浏览器结果共同验收。', 'P0'],
      ['发布就绪检查', '运行 npm run review:release-readiness。', '所有必需证据新鲜且无失败项后才允许发布。', 'P0'],
      ['设计交付清单', '检查生产界面设计 handoff manifest 与验收签字。', '清单存在、版本匹配当前构建并记录关键页面验收。', 'P1'],
      ['OTA 凭据轮换证明', '检查发布前凭据轮换、脱敏扫描和责任人证明。', '证据在有效期内、范围完整且不含真实凭据。', 'P0'],
    ],
    assessments: `browser_e2e_fail dashboard_browser_unverified dashboard_browser_unverified dashboard_browser_unverified credential_live_partial ota_not_verified ota_not_verified structural_pass structural_pass structural_pass ops_partial ops_partial invest_live_partial auth_auto php_suite_pass node_suite_pass browser_e2e_fail structural_pass structural_pass perf_auto security_auto structural_pass release_fail design_handoff_gap credential_rotation_gap`.split(/\s+/),
  },
  {
    code: 'PMS', name: '完整 PMS 扩展能力（当前产品范围外）', scope: '范围外扩展', type: '行业功能/不适用',
    basis: 'Oracle OPERA Cloud、Cloudbeds 等完整 PMS 市场能力；宿析OS当前定位为 OTA 数据到决策闭环，不据此误判失败',
    precondition: '仅当产品正式扩展为前台 PMS、中央预订或渠道管理系统时启用；当前审计记录为不适用。',
    cases: [
      ['散客预订创建', '创建带入住人、房型、价格计划和担保的直接预订。', '完整 PMS 应保存预订并占用正确库存。', 'P2'],
      ['预订修改取消', '修改日期/房型并取消预订。', '库存、费用、取消政策和审计同步更新。', 'P2'],
      ['团队预订与配额', '创建团队、房量配额和名单。', '团队主账与成员预订一致。', 'P2'],
      ['候补与超售控制', '满房时加入候补并测试超售阈值。', '按策略控制风险并清楚告警。', 'P2'],
      ['入住登记', '核验身份、分房并办理入住。', '房态、宾客和账务状态同步。', 'P2'],
      ['换房与续住', '入住中换房、延住和提前离店。', '房态、房费和历史轨迹正确。', 'P2'],
      ['退房结账', '结清费用并办理退房。', '账务平衡、房态进入待清洁。', 'P2'],
      ['夜审', '执行跨业务日夜审。', '账务、房费和业务日期安全结转。', 'P2'],
      ['实时房态', '查看空房、住客房、脏房、维修房。', '状态在前台与客房部一致。', 'P2'],
      ['排房与房间锁定', '自动/手工排房和锁定房间。', '避免冲突并保留操作审计。', 'P2'],
      ['客房清扫任务', '创建、分配、完成清扫。', '清扫状态驱动可售房态。', 'P2'],
      ['维修停用房', '创建维修单并停用房间。', '库存和维修状态联动。', 'P2'],
      ['房型与实体房间', '维护房型、房号、楼层和设施。', '主数据关系完整且可用。', 'P2'],
      ['价格计划', '配置门市、会员、套餐和限制条件。', '价格规则按入住日正确生效。', 'P2'],
      ['库存控制', '调整每日房型库存和超售量。', '所有预订渠道库存一致。', 'P2'],
      ['渠道管理器同步', '向 OTA 推送房价、房态、库存。', '双向同步、失败重试和差异告警完整。', 'P2'],
      ['中央预订系统', '跨门店查询与预订。', '门店库存、价格和客户资料按范围共享。', 'P2'],
      ['会员与忠诚度', '注册会员、累计与兑换权益。', '等级、积分、过期和隐私规则正确。', 'P2'],
      ['宾客档案去重', '合并重复宾客档案。', '在授权与隐私边界内合并且可追溯。', 'P2'],
      ['应收账与公司账', '挂账、结算和账龄管理。', '财务分录和余额准确。', 'P2'],
      ['支付与退款', '预授权、支付、撤销和退款。', '支付状态、金额和对账一致。', 'P2'],
      ['发票管理', '开票、红冲和作废。', '符合适用财税规则并可审计。', 'P2'],
      ['POS 挂账集成', '餐饮/康乐消费挂入房账。', '签单、金额和账务实时一致。', 'P2'],
      ['门锁与证件设备集成', '发卡、补卡、失效和设备故障。', '房间权限与入住状态一致且安全。', 'P2'],
      ['多物业集中运营', '从集团层查询和配置多门店 PMS。', '主数据、权限、报表和配置按集团/门店隔离。', 'P2'],
    ],
    assessments: Array(25).fill('pms_na'),
  },
];

const allowedStatuses = new Set(['满足', '部分满足', '不满足', '未验证', '不适用']);
const statusOrder = ['满足', '部分满足', '不满足', '未验证', '不适用'];

function escapeMd(value) {
  return String(value ?? '').replaceAll('|', '\\|').replaceAll('\n', '<br>');
}

function padCaseId(index) {
  return `TC-${String(index).padStart(3, '0')}`;
}

if (categories.length !== 20) {
  throw new Error(`Expected 20 categories, received ${categories.length}`);
}

const tests = [];
for (const [categoryIndex, category] of categories.entries()) {
  if (category.cases.length !== 25 || category.assessments.length !== 25) {
    throw new Error(`${category.code} must contain 25 cases and 25 assessments; got ${category.cases.length}/${category.assessments.length}`);
  }

  category.cases.forEach(([title, action, expected, priority], caseIndex) => {
    const assessmentKey = category.assessments[caseIndex];
    const assessment = assessmentCatalog[assessmentKey];
    if (!assessment) throw new Error(`${category.code} case ${caseIndex + 1}: unknown assessment ${assessmentKey}`);
    if (!allowedStatuses.has(assessment.status)) throw new Error(`${assessmentKey}: invalid status ${assessment.status}`);

    const ordinal = categoryIndex * 25 + caseIndex + 1;
    tests.push({
      id: padCaseId(ordinal),
      ordinal,
      category_code: category.code,
      category_name: category.name,
      scope: category.scope,
      type: category.type,
      benchmark_basis: category.basis,
      priority,
      title,
      precondition: category.precondition,
      steps: [
        `建立隔离测试上下文：${category.precondition}`,
        action,
        '记录页面/API 响应、HTTP 状态、request_id，并按需要核对数据库、任务状态、日志或导出结果。',
        '用授权与越权/正常与边界样本复核，清理测试数据且不触碰真实酒店或外部生产状态。',
      ],
      expected,
      assessment_status: assessment.status,
      assessment_basis: assessment.basis,
      assessment_key: assessmentKey,
      project_evidence: assessment.evidence,
      next_action: assessment.next,
    });
  });
}

if (tests.length !== 500) throw new Error(`Expected 500 tests, received ${tests.length}`);
const expectedIds = Array.from({ length: 500 }, (_, index) => padCaseId(index + 1));
if (tests.some((test, index) => test.id !== expectedIds[index])) throw new Error('Case IDs are not continuous TC-001..TC-500');
if (new Set(tests.map((test) => test.id)).size !== 500) throw new Error('Duplicate case IDs detected');

const requiredFields = ['id', 'category_code', 'category_name', 'scope', 'type', 'benchmark_basis', 'priority', 'title', 'precondition', 'expected', 'assessment_status', 'assessment_basis', 'project_evidence', 'next_action'];
for (const test of tests) {
  for (const field of requiredFields) {
    if (!String(test[field] ?? '').trim()) throw new Error(`${test.id}: missing ${field}`);
  }
  if (!Array.isArray(test.steps) || test.steps.length < 3 || test.steps.some((step) => !String(step).trim())) {
    throw new Error(`${test.id}: incomplete steps`);
  }
}

const statusCounts = Object.fromEntries(statusOrder.map((status) => [status, tests.filter((test) => test.assessment_status === status).length]));
const categoryCounts = categories.map((category) => {
  const rows = tests.filter((test) => test.category_code === category.code);
  return {
    code: category.code,
    name: category.name,
    total: rows.length,
    ...Object.fromEntries(statusOrder.map((status) => [status, rows.filter((test) => test.assessment_status === status).length])),
  };
});

const applicable = tests.length - statusCounts['不适用'];
const determined = statusCounts['满足'] + statusCounts['部分满足'] + statusCounts['不满足'];
const passRate = determined ? (statusCounts['满足'] / determined * 100).toFixed(1) : '0.0';
const evidenceCoverage = applicable ? (determined / applicable * 100).toFixed(1) : '0.0';
const p0Gaps = tests.filter((test) => test.priority === 'P0' && ['部分满足', '不满足', '未验证'].includes(test.assessment_status));

const generatedMeta = {
  title: '宿析OS：酒店数据管理系统 500 条测试用例与项目符合性审计',
  audit_date: auditDate,
  generated_at: generatedAt,
  project: '宿析OS（SUXIOS）',
  product_scope: 'OTA 数据 → 收益分析 → AI 决策 → 运营管理 → 投资决策',
  methodology: '市场/标准基线 + 项目代码与自动化证据 + 本地运行结果；未执行项不推定通过。',
  disclaimer: '本报告是工程符合性差距分析，不等同于 ISO、等保、PCI、隐私或法律认证/合规意见。',
  totals: { total: tests.length, applicable, determined, pass_rate_percent: Number(passRate), evidence_coverage_percent: Number(evidenceCoverage), ...statusCounts },
  sources: sources.map(([name, use, url]) => ({ name, use, url })),
};

mkdirSync(outputDir, { recursive: true });

const jsonPath = path.join(outputDir, `hotel_system_500_test_cases_${auditDate}.json`);
writeFileSync(jsonPath, `${JSON.stringify({ metadata: generatedMeta, category_summary: categoryCounts, test_cases: tests }, null, 2)}\n`, 'utf8');

const lines = [];
lines.push('# 宿析OS：酒店数据管理系统 500 条测试用例与项目符合性审计', '');
lines.push(`- 审计日期：${auditDate}`);
lines.push('- 产品范围：OTA 数据 → 收益分析 → AI 决策 → 运营管理 → 投资决策');
lines.push('- 判定原则：只把代码、自动化或本地运行证据支持的能力判为“满足”；依赖真实账号/浏览器/生产环境的项目保持“部分满足”或“未验证”。');
lines.push('- 范围说明：TC-476～TC-500 是完整 PMS 扩展能力。宿析OS当前并非前台 PMS，因此单列“不适用”，不计为产品缺陷。');
lines.push(`- 免责声明：${generatedMeta.disclaimer}`, '');
lines.push('## 一、市场与标准基线', '');
lines.push('| 基线 | 本审计使用范围 | 官方来源 |', '|---|---|---|');
for (const [name, use, url] of sources) lines.push(`| ${escapeMd(name)} | ${escapeMd(use)} | [链接](${url}) |`);
lines.push('', '## 二、当前审计结论', '');
lines.push(`- 总用例：**500**；适用：**${applicable}**；不适用（25 条完整 PMS 扩展 + 1 条当前未进入支付卡数据范围的 PCI 条件项）：**${statusCounts['不适用']}**。`);
lines.push(`- 满足：**${statusCounts['满足']}**；部分满足：**${statusCounts['部分满足']}**；不满足：**${statusCounts['不满足']}**；未验证：**${statusCounts['未验证']}**。`);
lines.push(`- 已确定项目中的满足率：**${passRate}%**（满足 ÷ 满足/部分满足/不满足）；适用用例证据覆盖率：**${evidenceCoverage}%**。`);
lines.push('- 总体判断：核心后端、租户隔离、数据质量、收益/AI 结构契约与 P0 守卫基础较强，但浏览器端到端、真实 OTA 双平台闭环、身份增强、隐私权利流程、灾备/负载和发布证据尚不足，当前不能判定达到完整市场生产就绪。', '');
lines.push('### 新鲜验证证据', '');
lines.push('- `vendor/bin/phpunit --colors=never`：通过，1336 tests / 12138 assertions。');
lines.push('- `npm run verify:p0-guards`：通过；Revenue AI closure 1733 checks，E2E structural contract 2220 checks。');
lines.push('- `npm run verify:e2e-contracts`、`verify:performance-budget`、`review:report-security-finance`、`review:functional-readiness`：通过。');
lines.push('- `/api/health`：HTTP 200；MySQL ping：alive。现有 health 只报告应用存活，未验证数据库就绪。');
lines.push('- `node scripts/run_node_automation_tests.mjs`：通过；70 个测试文件、492 tests 全通过。');
lines.push('- `test:e2e:quick`：9/9 失败，测试登录凭据返回 400“用户名或密码错误”，因此浏览器闭环未通过。');
lines.push('- `review:release-readiness`：14 通过、2 警告、4 失败；缺设计交付清单和 OTA 凭据轮换证明，且 staged-scope / external-state 证据过期。', '');
lines.push('## 三、分类统计', '');
lines.push('| 类别 | 总数 | 满足 | 部分满足 | 不满足 | 未验证 | 不适用 |', '|---|---:|---:|---:|---:|---:|---:|');
for (const row of categoryCounts) {
  lines.push(`| ${row.code} ${escapeMd(row.name)} | ${row.total} | ${row['满足']} | ${row['部分满足']} | ${row['不满足']} | ${row['未验证']} | ${row['不适用']} |`);
}
lines.push('', '## 四、500 条测试用例与逐项判定', '');

for (const category of categories) {
  lines.push(`### ${category.code} ${category.name}`, '');
  lines.push(`范围：${category.scope}；类型：${category.type}；基线：${category.basis}`, '');
  lines.push('| ID | 优先级 | 测试用例 | 前置条件 | 测试步骤 | 预期结果 | 项目判定 | 证据/缺口 | 下一步 |', '|---|---|---|---|---|---|---|---|---|');
  for (const test of tests.filter((item) => item.category_code === category.code)) {
    const stepText = test.steps.map((step, index) => `${index + 1}. ${step}`).join('<br>');
    lines.push(`| ${test.id} | ${test.priority} | ${escapeMd(test.title)} | ${escapeMd(test.precondition)} | ${escapeMd(stepText)} | ${escapeMd(test.expected)} | ${test.assessment_status}（${escapeMd(test.assessment_basis)}） | ${escapeMd(test.project_evidence)} | ${escapeMd(test.next_action)} |`);
  }
  lines.push('');
}

lines.push('## 五、验收使用方法', '');
lines.push('1. P0 用例先行；任何“不满足”或“未验证”不得用默认值、旧数据或口头说明替代证据。');
lines.push('2. 真实 OTA 用例必须记录授权酒店、平台、业务日期、采集时间、来源方式和质量状态，禁止保存或粘贴真实凭据。');
lines.push('3. 每次修复后至少回归同类正向、越权、边界和失败状态，并更新本报告中的证据与日期。');
lines.push('4. 完整 PMS 扩展用例只有在产品正式进入预订、前台、房态、支付等范围时再启用。', '');

const markdownPath = path.join(outputDir, `hotel_system_500_test_cases_and_audit_${auditDate}.md`);
writeFileSync(markdownPath, `${lines.join('\n')}\n`, 'utf8');

const summary = [];
summary.push('# 宿析OS 酒店系统符合性审计摘要', '');
summary.push(`审计日期：${auditDate}`, '');
summary.push('## 结论', '');
summary.push(`500 条用例已逐项映射：满足 ${statusCounts['满足']}、部分满足 ${statusCounts['部分满足']}、不满足 ${statusCounts['不满足']}、未验证 ${statusCounts['未验证']}、不适用 ${statusCounts['不适用']}。`);
summary.push(`排除 25 条完整 PMS 范围外用例和 1 条当前未进入支付卡数据范围的 PCI 条件项后，适用 ${applicable} 条；已确定项满足率 ${passRate}%，证据覆盖率 ${evidenceCoverage}%。`);
summary.push('宿析OS已具备较强的核心后端与结构守卫，但当前发布门禁和浏览器 E2E 失败，真实携程/美团与生产模型闭环也未形成新鲜证据，因此结论是“功能基础较强，尚未达到完整市场生产就绪”。', '');
summary.push('## 最高优先级缺口', '');
const groupedGaps = [
  '修复浏览器 E2E 测试账号/种子数据，使登录、酒店、用户、凭据保存与核心页面可真实操作回显。',
  '在授权酒店完成携程与美团同日采集、字段对账、质量状态和收益指标闭环。',
  '补改密撤销会话、登录渐进限流/锁定、高权限 MFA、授权 expires_at 校验。',
  '收紧 CORS 并补 CSP/HSTS/nosniff/frame/referrer 等生产响应头。',
  '落地个人信息告知同意、查阅复制、更正删除、保留期限和跨境/模型传输控制。',
  '补数据库就绪探针、备份恢复/RPO-RTO、负载与灾难恢复实测。',
  '补齐设计交付清单、OTA 凭据轮换证明及新鲜 staged/external-state 发布证据。',
];
for (const [index, gap] of groupedGaps.entries()) summary.push(`${index + 1}. ${gap}`);
summary.push('', '## P0 非满足项索引', '');
summary.push(`共 ${p0Gaps.length} 条；完整步骤、证据和下一步见主报告。`, '');
summary.push('| ID | 用例 | 判定 | 下一步 |', '|---|---|---|---|');
for (const test of p0Gaps) summary.push(`| ${test.id} | ${escapeMd(test.title)} | ${test.assessment_status} | ${escapeMd(test.next_action)} |`);
summary.push('', '## 生成物', '');
summary.push(`- 完整 Markdown：\`docs/qa/${path.basename(markdownPath)}\``);
summary.push(`- 机器可读 JSON：\`docs/qa/${path.basename(jsonPath)}\``);
summary.push(`- 生成脚本：\`scripts/${path.basename(fileURLToPath(import.meta.url))}\``, '');
summary.push(`> ${generatedMeta.disclaimer}`, '');

const summaryPath = path.join(outputDir, `hotel_system_audit_summary_${auditDate}.md`);
writeFileSync(summaryPath, `${summary.join('\n')}\n`, 'utf8');

console.log(JSON.stringify({ markdownPath, jsonPath, summaryPath, totals: generatedMeta.totals, p0GapCount: p0Gaps.length }, null, 2));

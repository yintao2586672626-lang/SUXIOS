-- Seed reusable room-type operating analysis communication guidance.
-- The user-provided HTML is distilled into generic methods only; hotel identity and sample metrics are excluded.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @room_analysis_unit_name := '房型经营分析报告解读话术库';
SET @room_analysis_source := 'room_type_analysis_communication';
SET @room_analysis_description := '从用户提供的房型经营分析逐字稿中提炼可复用的 OTA、ADR、RevPAR、出租率、渠道、客源、房型、价格、库存、时租和经营复盘话术。仅作沟通与分析框架参考，不包含原稿酒店身份和个案数值，不替代当前门店已验证经营数据。';

INSERT INTO `knowledge_units` (`hotel_id`, `name`, `source`, `status`, `description`, `tags`, `created_by`, `created_at`, `updated_at`)
SELECT
  0,
  @room_analysis_unit_name,
  @room_analysis_source,
  'done',
  @room_analysis_description,
  JSON_ARRAY('房型经营', '报告解读', '收益管理', 'ADR', 'RevPAR', '渠道结构', '客源结构', '时租管理', '运营话术', 'user_provided_unverified', 'communication_reference'),
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @room_analysis_unit_name AND `source` = @room_analysis_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @room_analysis_description,
  `tags` = JSON_ARRAY('房型经营', '报告解读', '收益管理', 'ADR', 'RevPAR', '渠道结构', '客源结构', '时租管理', '运营话术', 'user_provided_unverified', 'communication_reference'),
  `updated_at` = NOW()
WHERE `name` = @room_analysis_unit_name AND `source` = @room_analysis_source;

SET @room_analysis_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @room_analysis_unit_name AND `source` = @room_analysis_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DELETE FROM `knowledge_chunks`
WHERE `unit_id` = @room_analysis_unit_id
  AND `type` IN (
    '使用边界',
    '解读话术框架',
    '标准表达模板',
    '房型角色判断',
    '渠道客源与时租',
    '行动闭环',
    '经营金句',
    '禁止事项'
  );

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @room_analysis_unit_id,
  '使用边界',
  JSON_OBJECT(
    'source_document', 'docs/room_type_operation_analysis_communication_playbook.md',
    'source_kind', 'user_provided_html_distilled',
    'verification_status', 'user_provided_unverified_communication_reference',
    'scope', 'generic_room_type_analysis_communication_not_hotel_fact',
    'rules', JSON_ARRAY(
      '原稿酒店名称、统计周期和经营数值不进入通用知识事实层。',
      '实际报告必须替换为当前门店、当前日期、同一范围和同一口径的已验证数据。',
      '只有 OTA 数据时只能描述对应 OTA 渠道表现，不能扩大为全酒店经营结论。',
      '指标缺分母、口径不一致或来源未验证时，明确标记不可计算、口径待确认或仅供参考。',
      '沟通话术不能直接触发 OTA 改价、关房、开房、库存写回或收益承诺。'
    )
  ),
  0,
  NOW()
WHERE @room_analysis_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @room_analysis_unit_id,
  '解读话术框架',
  JSON_OBJECT(
    'sequence', JSON_ARRAY(
      JSON_OBJECT('step', 1, 'name', '给出冲突', 'rule', '用看起来不错但仍有结构问题建立经营张力，不用夸张标题代替证据。'),
      JSON_OBJECT('step', 2, 'name', '引用证据', 'rule', '只引用同周期、同范围、同口径的已验证数据。'),
      JSON_OBJECT('step', 3, 'name', '解释机制', 'rule', '解释数字可能如何形成，不把相关性直接写成确定因果。'),
      JSON_OBJECT('step', 4, 'name', '排除误解', 'rule', '明确不能直接得出的结论，例如渠道占比高不等于应立即砍渠道。'),
      JSON_OBJECT('step', 5, 'name', '给出动作', 'rule', '动作必须绑定房型、日期、渠道、价格、库存和执行人。'),
      JSON_OBJECT('step', 6, 'name', '设置观察', 'rule', '说明 ADR、RevPAR、间夜、转化、取消率或剩余库存中的观察指标。'),
      JSON_OBJECT('step', 7, 'name', '金句收束', 'rule', '用一句克制、可复述的话总结，但金句不能替代事实。')
    ),
    'analysis_order', JSON_ARRAY('数据口径与闭合', '出租率-ADR-RevPAR经营阶段', '渠道结构', '客源结构', '房型角色', '时租与过夜机会成本', '行动与复盘')
  ),
  0,
  NOW()
WHERE @room_analysis_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @room_analysis_unit_id,
  '标准表达模板',
  JSON_OBJECT(
    'master_template', '在[统计周期]、[数据范围]下，[指标A]为[数值]，[指标B]为[数值]。这说明[经营判断]，但还不能直接证明[不可下结论项]。下一步针对[房型/渠道/日期]做[动作]，由[责任人]执行，在[复盘时间]观察[指标]；若[停止条件]出现，则暂停或回滚。',
    'opening', '今天不是把表里的数字重新念一遍，而是要回答：钱从哪里来，库存被谁消耗，下一步具体改什么。',
    'data_anomaly', '这个数字先不要急着判错。先拆分过夜、时租、取消、赠房和多次周转，看分项能否与总数闭合；闭合后再解释，闭合不了就先标记数据问题。',
    'stage_judgement', '出租率回答卖出去多少，ADR回答成交价格质量，RevPAR回答每间可售房创造多少收入；三个指标必须同口径联读。',
    'closing', '结论只有落到房型、日期、渠道、价格、库存、责任人和复盘指标，才真正进入经营。'
  ),
  0,
  NOW()
WHERE @room_analysis_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @room_analysis_unit_id,
  '房型角色判断',
  JSON_OBJECT(
    'roles', JSON_ARRAY(
      JSON_OBJECT(
        'role', '高产低价房型',
        'signal', '间夜或收入贡献高，但 ADR 低于同层级房型。',
        'task', '低峰保量、旺日提价、分层释放库存。',
        'phrase', '它既是收入发动机，也可能成为全店价格上限。'
      ),
      JSON_OBJECT(
        'role', '高价稀缺房型',
        'signal', 'ADR、RevPAR 高，库存和间夜体量小。',
        'task', '守价、展示、升级承接。',
        'phrase', '不用普通房的销量逻辑考核稀缺房型。'
      ),
      JSON_OBJECT(
        'role', '场景溢价房型',
        'signal', '有影音、浴缸、家庭、空间等特色，但溢价不稳定。',
        'task', '重构图片、标题、卖点和前台话术。',
        'phrase', '配置是酒店拥有的东西，场景才是客人购买的价值。'
      ),
      JSON_OBJECT(
        'role', '基础填房房型',
        'signal', '价格敏感，主要承担低峰补量。',
        'task', '限量引流、设置底价、避免旺日外溢。',
        'phrase', '引流房负责打开入口，不负责长期占满全部库存。'
      ),
      JSON_OBJECT(
        'role', '升级承接房型',
        'signal', '与基础房差异清晰，具备额外支付理由。',
        'task', '设计升级链路并记录转化。',
        'phrase', '升级不是多收多少钱，而是让客人看懂为什么更值。'
      )
    )
  ),
  0,
  NOW()
WHERE @room_analysis_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @room_analysis_unit_id,
  '渠道客源与时租',
  JSON_OBJECT(
    'patterns', JSON_ARRAY(
      JSON_OBJECT(
        'scene', '渠道占比高',
        'phrase', '大渠道承担基础流量没有问题，问题在于它是否同时决定了全店价格。先比较绝对间夜、收入、ADR、取消率、佣金和旺日低价占用，再决定库存分配。'
      ),
      JSON_OBJECT(
        'scene', '高价客源规模小',
        'phrase', '高价客源不是偶尔成交一次，而是能被识别、再次触达并形成复购；规模小优先说明承接与沉淀不足。'
      ),
      JSON_OBJECT(
        'scene', '时租是否保留',
        'phrase', '低需求时段时租可以补充收入；高需求日期若占用过夜库存，就形成机会成本。按日期、房型、库存、最晚开放时间和最低价格动态管理。'
      )
    ),
    'guard', '占比变化必须结合绝对间夜；高价成交必须结合样本量；时租与过夜必须使用可比收入和库存口径。'
  ),
  0,
  NOW()
WHERE @room_analysis_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @room_analysis_unit_id,
  '行动闭环',
  JSON_OBJECT(
    'phases', JSON_ARRAY(
      JSON_OBJECT('period', '1-15天', 'focus', '统一数据口径、房型角色、底价、库存和时租规则。', 'acceptance', '数据能闭合；主力房型有角色与底价；执行人明确。'),
      JSON_OBJECT('period', '16-45天', 'focus', '做小范围提价、场景包装、升级话术、直订和复访。', 'acceptance', '同步观察转化、取消率、ADR、间夜和升级成交。'),
      JSON_OBJECT('period', '46-90天', 'focus', '建立价格日历、旺日库存保护、周复盘和低效活动淘汰。', 'acceptance', '形成日看库存、周看结构、月修价格日历的机制。')
    ),
    'action_contract', JSON_ARRAY('改哪个房型', '在哪些日期', '通过哪个渠道', '调整什么价格或库存', '由谁执行', '观察什么指标', '什么时候复盘', '什么条件下停止或回滚'),
    'decision_rule', '情景测算用于比较选择，不是收益承诺；小范围测试通过后才扩大。'
  ),
  0,
  NOW()
WHERE @room_analysis_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @room_analysis_unit_id,
  '经营金句',
  JSON_OBJECT(
    'phrases', JSON_ARRAY(
      '报表的终点不是解释数字，而是决定下一步做什么。',
      '异常数字先查口径，口径闭合后才谈经营。',
      '满房是容量结果，收益质量还要看价格和结构。',
      '出租率回答卖了多少，ADR回答卖得多贵，RevPAR回答库存创造了多少收入。',
      '高产低价房型既可能是发动机，也可能是价格天花板。',
      '收入贡献越大的房型，越需要价格纪律。',
      '稀缺房型的价值是守价、展示和升级，不是低价冲量。',
      '配置是供给，场景才是客人愿意付费的价值。',
      '引流房可以打开入口，但不能无边界占用库存。',
      '房型没有角色，就会出现内部低价竞争。',
      '渠道规模大不等于渠道效率高，量、价、取消和成本要一起看。',
      '基础流量可以依赖渠道，全店价格不能被单一渠道定义。',
      '低峰价格不能外溢到旺日，旺日库存不能提前被低价透支。',
      '时租的关键不是做不做，而是有没有服从过夜收益和库存机会成本。',
      '收益管理不是所有房型一起涨价，而是让不同房型承担不同任务。',
      '一条建议必须说明改什么、何时改、谁执行、看什么、何时复盘。',
      '涨价测试没有转化和取消率观察，就只是主观调价。',
      '情景测算用于比较选择，不是收益承诺。',
      '日看库存，周看结构，月修价格日历。'
    ),
    'tone', 'professional_restrained_evidence_first_action_oriented'
  ),
  0,
  NOW()
WHERE @room_analysis_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @room_analysis_unit_id,
  '禁止事项',
  JSON_OBJECT(
    'blocked_claims', JSON_ARRAY(
      '不得把原稿个案数值写成其他酒店事实。',
      '不得把 OTA 渠道数据写成全酒店出租率、ADR 或 RevPAR。',
      '不得使用肯定有效、一定增收、保证满房等确定性承诺。',
      '不得因产量率超过100%就自动认定为时租或多次周转，必须验证分项。',
      '不得把渠道占比高直接等同于应削减渠道。',
      '不得把高价小体量房型直接判为低效。',
      '不得输出没有日期、房型、库存、责任人和复盘指标的空泛建议。',
      '不得让沟通话术直接触发 OTA 改价、关房、开房或库存写回。'
    )
  ),
  0,
  NOW()
WHERE @room_analysis_unit_id IS NOT NULL;

SET @room_analysis_category_name := '收益管理与经营解读';
SET @room_analysis_category_description := '酒店收益、房型、渠道、客源和经营报告的分析与沟通方法。';

INSERT INTO `knowledge_categories` (`hotel_id`, `parent_id`, `name`, `description`, `sort_order`, `is_enabled`, `create_time`, `update_time`)
SELECT
  0,
  0,
  @room_analysis_category_name,
  @room_analysis_category_description,
  0,
  1,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_categories`
  WHERE `hotel_id` = 0 AND `parent_id` = 0 AND `name` = @room_analysis_category_name
);

UPDATE `knowledge_categories`
SET
  `description` = @room_analysis_category_description,
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `parent_id` = 0 AND `name` = @room_analysis_category_name;

SET @room_analysis_category_id := (
  SELECT `id` FROM `knowledge_categories`
  WHERE `hotel_id` = 0 AND `parent_id` = 0 AND `name` = @room_analysis_category_name
  ORDER BY `id` ASC
  LIMIT 1
);

SET @room_analysis_staff_content := CONCAT(
  '# 房型经营分析报告解读话术库', '\n\n',
  '## 使用边界', '\n',
  '本条目是用户资料中提炼出的通用沟通框架，不是任何门店的实时经营事实。实际输出必须绑定当前门店、日期、来源和质量状态；只有 OTA 数据时不得扩大为全酒店结论。', '\n\n',
  '## 解读顺序', '\n',
  '1. 先核对统计周期、范围和分项闭合。', '\n',
  '2. 同口径联读出租率、ADR 和 RevPAR，判断增长来自房量还是价格结构。', '\n',
  '3. 再看渠道、客源、房型角色以及时租对过夜库存的机会成本。', '\n',
  '4. 动作必须绑定房型、日期、渠道、价格/库存、责任人、复盘指标和回滚条件。', '\n\n',
  '## 核心表达', '\n',
  '- 异常数字先查口径，口径闭合后才谈经营。', '\n',
  '- 满房是容量结果，收益质量还要看价格和结构。', '\n',
  '- 高产低价房型既可能是发动机，也可能是价格天花板。', '\n',
  '- 基础流量可以依赖渠道，全店价格不能被单一渠道定义。', '\n',
  '- 配置是供给，场景才是客人愿意付费的价值。', '\n',
  '- 日看库存，周看结构，月修价格日历。', '\n\n',
  '## 禁止事项', '\n',
  '- 不承诺固定收益，不把样例数字写成当前事实。', '\n',
  '- 不让沟通话术直接触发 OTA 改价、关房、开房或库存写回。'
);

INSERT INTO `knowledge_base` (
  `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0,
  COALESCE(@room_analysis_category_id, 0),
  @room_analysis_unit_name,
  @room_analysis_staff_content,
  '房型经营,房型分析,报告解读,收益管理,ADR,RevPAR,出租率,渠道结构,客源结构,时租,库存,价格,运营话术,经营复盘,OTA,user_provided_unverified',
  JSON_ARRAY('房型经营', '报告解读', '收益管理', 'ADR', 'RevPAR', '运营话术', 'communication_reference'),
  0,
  1,
  0,
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_base`
  WHERE `hotel_id` = 0 AND `title` = @room_analysis_unit_name
);

UPDATE `knowledge_base`
SET
  `category_id` = COALESCE(@room_analysis_category_id, `category_id`),
  `content` = @room_analysis_staff_content,
  `keywords` = '房型经营,房型分析,报告解读,收益管理,ADR,RevPAR,出租率,渠道结构,客源结构,时租,库存,价格,运营话术,经营复盘,OTA,user_provided_unverified',
  `tags` = JSON_ARRAY('房型经营', '报告解读', '收益管理', 'ADR', 'RevPAR', '运营话术', 'communication_reference'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @room_analysis_unit_name;

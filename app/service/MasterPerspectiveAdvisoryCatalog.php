<?php
declare(strict_types=1);

namespace app\service;

/**
 * Curated, deterministic lens catalog distilled from the user-supplied
 * 165-perspective package. The source package is never installed or executed;
 * names and probes are reference frameworks, not simulated human authority.
 */
final class MasterPerspectiveAdvisoryCatalog
{
    public const CONTRACT_VERSION = 'master_perspective_advisory_catalog.v1';
    public const SOURCE_OUTER_ZIP_SHA256 = '32c06de45983119efd6f7cfa9b1e8ca5ce59f8a4e5339267dc383a5fc0ee3970';
    public const SOURCE_ENTRY_COUNT = 165;
    public const METHOD_VERSION = '2026-08-23.1';

    /** @var list<string> */
    private const REQUIRED_LENSES = [
        'evidence_and_uncertainty',
        'customer_and_value',
    ];

    /** @var list<string> */
    private const OPTIONAL_LENS_ORDER = [
        'competition_and_strategy',
        'operations_and_execution',
        'risk_and_resilience',
        'communication_and_alignment',
        'ethics_and_fairness',
    ];

    /** @var array<string,list<string>> */
    private const KEYWORDS = [
        'competition_and_strategy' => [
            '竞品', '竞争', '排名', '流量', '渠道', '市场', '定位', '价格', '定价',
            '收益', '转化', '投放', '资源', '携程', '美团', 'ota',
        ],
        'operations_and_execution' => [
            '执行', '行动', '下一步', '怎么做', '任务', '流程', 'sop', '负责人',
            '复盘', '运营', '落地', '检查', '培训', '排班',
        ],
        'risk_and_resilience' => [
            '风险', '不可逆', '投资', '预算', '成本', '亏损', '止损', '尾部',
            '降价', '涨价', '库存', '审批', '回滚', '停止条件',
        ],
        'communication_and_alignment' => [
            '员工', '团队', '协同', '沟通', '客诉', '评论', '店长', '交接',
            '共识', '异议', '培训', '负责人',
        ],
        'ethics_and_fairness' => [
            '隐私', '权限', '歧视', '公平', '员工评价', '员工评分', '员工排名',
            '跨酒店', '跨门店', '客群差异', '个人信息', '手机号',
        ],
    ];

    /** @return array<string,mixed> */
    public function select(string $questionText, array $answer = []): array
    {
        $questionText = mb_strtolower(trim($questionText));
        $scores = array_fill_keys(self::OPTIONAL_LENS_ORDER, 0);
        $reasons = array_fill_keys(self::OPTIONAL_LENS_ORDER, []);

        foreach (self::KEYWORDS as $lensId => $keywords) {
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && str_contains($questionText, mb_strtolower($keyword))) {
                    $scores[$lensId]++;
                    $reasons[$lensId][] = $keyword;
                }
            }
        }

        if ($this->hasActionDraft($answer)) {
            $scores['operations_and_execution'] += 3;
            $scores['risk_and_resilience'] += 1;
            $reasons['operations_and_execution'][] = 'primary_action_draft_available';
            $reasons['risk_and_resilience'][] = 'execution_risk_review';
        }
        if ($this->hasDataGaps($answer)) {
            $scores['risk_and_resilience'] += 1;
            $reasons['risk_and_resilience'][] = 'saved_data_gap';
        }

        $ranked = self::OPTIONAL_LENS_ORDER;
        usort($ranked, static function (string $left, string $right) use ($scores): int {
            $scoreOrder = $scores[$right] <=> $scores[$left];
            if ($scoreOrder !== 0) {
                return $scoreOrder;
            }
            return array_search($left, self::OPTIONAL_LENS_ORDER, true)
                <=> array_search($right, self::OPTIONAL_LENS_ORDER, true);
        });

        $selectedIds = self::REQUIRED_LENSES;
        foreach ($ranked as $lensId) {
            if ($scores[$lensId] <= 0 || count($selectedIds) >= 5) {
                continue;
            }
            $selectedIds[] = $lensId;
        }
        if (count($selectedIds) < 3) {
            $selectedIds[] = 'risk_and_resilience';
            $reasons['risk_and_resilience'][] = 'default_countercheck';
        }

        $catalog = $this->lenses();
        $selected = [];
        foreach (array_values(array_unique($selectedIds)) as $lensId) {
            if (!isset($catalog[$lensId])) {
                continue;
            }
            $lens = $catalog[$lensId];
            $lens['selection_reason'] = in_array($lensId, self::REQUIRED_LENSES, true)
                ? ['required_by_operating_advisory_contract']
                : array_values(array_unique($reasons[$lensId]));
            $selected[] = $lens;
        }

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'method_version' => self::METHOD_VERSION,
            'source' => $this->source(),
            'selected_lenses' => $selected,
            'selection_count' => count($selected),
            'selection_basis' => 'question_text_and_saved_evidence_gap',
            'selection_contract' => [
                'minimum_lenses' => 2,
                'maximum_lenses' => 5,
                'required_lenses' => self::REQUIRED_LENSES,
                'preserve_disagreement' => true,
                'votes_are_not_evidence' => true,
            ],
            'boundaries' => [
                'reference_lens_only' => true,
                'personality_impersonation' => false,
                'real_human_opinion' => false,
                'automatic_action' => false,
                'external_write_authorized' => false,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function source(): array
    {
        return [
            'package_label' => '165位大师视角Skill',
            'outer_zip_sha256' => self::SOURCE_OUTER_ZIP_SHA256,
            'source_entry_count' => self::SOURCE_ENTRY_COUNT,
            'attachment_status' => 'hash_verified_binary_duplicate',
            'source_package_execution' => 'not_installed_not_executed',
            'adaptation' => 'seven_domain_lens_synthesis',
            'authenticity_status' => 'source_package_synthesis_unverified',
            'quote_policy' => 'paraphrase_only',
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function lenses(): array
    {
        return [
            'evidence_and_uncertainty' => $this->lens(
                'evidence_and_uncertainty',
                '证据与不确定性',
                '我们真正测到了什么，哪些只是噪声、偏差或未经反证的解释？',
                '先核对分母、样本、范围、质量状态和反证；事实不足时只列缺口。',
                [
                    ['name' => '费曼', 'probe' => '能否用可观察字段、分母和简单语言重述结论？'],
                    ['name' => '香农', 'probe' => '哪些信号重复、缺失或被采集噪声污染？'],
                    ['name' => '卡尼曼', 'probe' => '是否忽略基准率、样本量、选择偏差或事后解释？'],
                    ['name' => '达尔文', 'probe' => '哪条反证最可能推翻当前假设，能否渐进验证？'],
                ]
            ),
            'customer_and_value' => $this->lens(
                'customer_and_value',
                '客户与价值',
                '这个变化对应哪类客人的哪一步真实体验与选择？',
                '把指标变化映射到可观察的客人路径；没有客群或行为证据时保持假设。',
                [
                    ['name' => '德鲁克', 'probe' => '目标客人认可的价值是什么，现有指标是否测到了它？'],
                    ['name' => '贝佐斯', 'probe' => '从客人最终结果倒推，最早的阻塞步骤在哪里？'],
                    ['name' => '铃木敏文', 'probe' => '能否把消费心理判断写成一个可验证假设？'],
                    ['name' => '张小龙', 'probe' => '能否减少一步操作、一个认知负担或一次无效打扰？'],
                ]
            ),
            'competition_and_strategy' => $this->lens(
                'competition_and_strategy',
                '竞争与战略',
                '在信息、资源和约束下，哪里是最值得投入且可验证的突破口？',
                '只依据同范围渠道事实讨论取舍，不把渠道数据扩成全酒店结论。',
                [
                    ['name' => '孙子', 'probe' => '行动前哪些胜负条件尚未具备，哪些信息仍缺失？'],
                    ['name' => '大前研一', 'probe' => '本店、客人和竞争环境三者的约束是否被同时考虑？'],
                    ['name' => '安迪·格鲁夫', 'probe' => '这是日常波动还是需要改变资源配置的战略转折？'],
                    ['name' => '梁建章', 'probe' => '数据结论能否经得住非主流解释与长期影响的检验？'],
                ]
            ),
            'operations_and_execution' => $this->lens(
                'operations_and_execution',
                '运营与执行',
                '怎样把认知变成最小动作、负责人、停止条件和回读证据？',
                '最多提出一个有边界、可停止、可回读且必须由用户触发的行动建议。',
                [
                    ['name' => '王阳明', 'probe' => '当前最小知行闭环是什么，何时回读结果？'],
                    ['name' => '曾国藩', 'probe' => '能否把复杂方案改成稳定重复的日课与检查点？'],
                    ['name' => '安迪·格鲁夫', 'probe' => '哪个动作具备最高管理杠杆，负责人和复核节奏是什么？'],
                    ['name' => '杜威', 'probe' => '能否通过一次真实操作产生下一轮学习证据？'],
                ]
            ),
            'risk_and_resilience' => $this->lens(
                'risk_and_resilience',
                '风险与韧性',
                '下行风险、不可逆性和模型失效点在哪里，怎样先保留选择权？',
                '明确最坏情形、安全边际、停止条件和回滚；不得把结果好等同于决策正确。',
                [
                    ['name' => '塔勒布', 'probe' => '哪种尾部事件会让当前方案失效，如何限制暴露？'],
                    ['name' => '霍华德·马克斯', 'probe' => '被忽略的风险是什么，结果好是否只是承担了更多风险？'],
                    ['name' => '巴菲特', 'probe' => '是否存在足够安全边际，是否超出可解释与可控制范围？'],
                    ['name' => '达利欧', 'probe' => '决策原则、错误记录和复盘触发条件是否显式？'],
                ]
            ),
            'communication_and_alignment' => $this->lens(
                'communication_and_alignment',
                '沟通与协同',
                '哪些概念、利益、异议或未说出口的信息阻碍了协同？',
                '先区分事实、角色和异议，再提出沟通或交接建议。',
                [
                    ['name' => '苏格拉底', 'probe' => '关键概念和前提是否经得住连续追问？'],
                    ['name' => '查理·罗斯', 'probe' => '是否先听清对方事实、约束和异议，再形成方案？'],
                    ['name' => '奥威尔', 'probe' => '表达是否被空话、模糊词或无证据因果污染？'],
                    ['name' => '孔子', 'probe' => '角色、责任和相互尊重是否清楚且一致？'],
                ]
            ),
            'ethics_and_fairness' => $this->lens(
                'ethics_and_fairness',
                '伦理与公平',
                '方案是否尊重人，是否存在跨酒店、歧视、操纵或不公平归因？',
                '不得做员工人格诊断、歧视性推断、跨酒店事实复用或未经授权的隐私处理。',
                [
                    ['name' => '墨子', 'probe' => '资源与影响是否被一致、公平地衡量？'],
                    ['name' => '康德', 'probe' => '这个规则能否普遍适用，是否把客人或员工仅当作手段？'],
                    ['name' => '曼德拉', 'probe' => '冲突处理是否保留尊严、制度修复与长期协作？'],
                    ['name' => '荀子', 'probe' => '制度是否按真实行为风险设计，而不是依赖理想化假设？'],
                ]
            ),
        ];
    }

    /** @param list<array{name:string,probe:string}> $sourceLenses @return array<string,mixed> */
    private function lens(
        string $key,
        string $label,
        string $businessQuestion,
        string $instruction,
        array $sourceLenses
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'business_question' => $businessQuestion,
            'instruction' => $instruction,
            'source_lenses' => $sourceLenses,
            'reference_only' => true,
        ];
    }

    private function hasActionDraft(array $answer): bool
    {
        return array_values(array_filter(
            is_array($answer['action_drafts'] ?? null) ? $answer['action_drafts'] : [],
            'is_array'
        )) !== [];
    }

    private function hasDataGaps(array $answer): bool
    {
        return array_values(array_filter(
            is_array($answer['data_gaps'] ?? null) ? $answer['data_gaps'] : [],
            'is_array'
        )) !== [];
    }
}

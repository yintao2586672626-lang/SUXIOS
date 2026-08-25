<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;

/**
 * Turns the user-provided eight-dimension revenue framework into a transparent
 * analysis lens. It never treats the framework, its RM codes, or keyword
 * matching as hotel facts or execution authority.
 */
final class RevenueDecisionFrameService
{
    public const CONTRACT_VERSION = 'revenue_decision_frame.v1';
    public const SOURCE_FINGERPRINT = 'sha256:CFA29096DC140E655903A244A824E5764D5393A9E3F2ECD444C7E6FEB48B1FBF';

    /** @var array<string,array<string,mixed>> */
    private const OBJECTS = [
        'demand' => [
            'label' => '需求',
            'primary_methods' => ['RM-M01', 'RM-M04'],
            'supporting_methods' => ['RM-M02', 'RM-M07'],
            'key_inputs' => ['历史', '在手', '拾取', '事件', '供给'],
            'core_boundary' => '区分受限销量与无约束需求。',
            'keywords' => ['需求', '旺季', '淡季', '节假日', '活动', '事件', '供给', '拾取', 'pickup', '预订趋势'],
        ],
        'segment' => [
            'label' => '客群',
            'primary_methods' => ['RM-M02'],
            'supporting_methods' => ['RM-M03', 'RM-M04', 'RM-M08'],
            'key_inputs' => ['客群', '提前期', '停留', '支付意愿'],
            'core_boundary' => '标签必须服务决策，不处理个人敏感信息。',
            'keywords' => ['客群', '客源', '商务', '商旅', '亲子', '情侣', '团队', '长住', '度假', '提前期', '停留', '连住', '支付意愿'],
        ],
        'product' => [
            'label' => '产品',
            'primary_methods' => ['RM-M05'],
            'supporting_methods' => ['RM-M02', 'RM-M08'],
            'key_inputs' => ['房型', '权益', '内容', '评价', '转化'],
            'core_boundary' => '高价必须有可感知价值支撑。',
            'keywords' => ['产品', '房型', '早餐', '权益', '套餐', '内容', '图片', '评价', '点评', '转化'],
        ],
        'price' => [
            'label' => '价格',
            'primary_methods' => ['RM-M03'],
            'supporting_methods' => ['RM-M04', 'RM-M07'],
            'key_inputs' => ['成本', '需求', '竞争', '净价', '目标'],
            'core_boundary' => '调价幅度不复制案例，保留审批。',
            'keywords' => ['价格', '房价', '调价', 'adr', '净价', '折扣', '促销', '价差', '底价', '毛价'],
        ],
        'channel' => [
            'label' => '渠道',
            'primary_methods' => ['RM-M06'],
            'supporting_methods' => ['RM-M02', 'RM-M08'],
            'key_inputs' => ['漏斗', '间夜', '收入', '佣金', '取消'],
            'core_boundary' => '毛收入和订单量不等于净贡献。',
            'keywords' => ['渠道', '携程', '美团', 'ota', '流量', '曝光', '漏斗', '佣金', '取消', '订单量', '间夜', '渠道收入'],
        ],
        'inventory_progress' => [
            'label' => '库存与进度',
            'primary_methods' => ['RM-M04', 'RM-M07'],
            'supporting_methods' => ['RM-M03'],
            'key_inputs' => ['在手', '剩余库存', '取消', '拒单'],
            'core_boundary' => '超订和关房为高风险动作。',
            'keywords' => ['库存', '可售', '剩余', '关房', '超订', '拒单', '满房', '进度', '房态'],
        ],
        'competition' => [
            'label' => '竞争',
            'primary_methods' => ['RM-M05'],
            'supporting_methods' => ['RM-M03', 'RM-M06'],
            'key_inputs' => ['可比竞对', '价格权益', '指数', '流向'],
            'core_boundary' => '竞争群失真时指数不可解释。',
            'keywords' => ['竞争', '竞品', '竞对', '商圈', '竞争群', '价格权益', '指数', '流向'],
        ],
        'organization_review' => [
            'label' => '组织复盘',
            'primary_methods' => ['RM-M09'],
            'supporting_methods' => ['全部方法'],
            'key_inputs' => ['策略日志', '责任', '审批', '结果'],
            'core_boundary' => '区分策略失效与执行偏差。',
            'keywords' => ['组织复盘', '复盘', '策略日志', '责任', '审批', '执行偏差', '策略失效', '结果验证'],
        ],
    ];

    /** @return array<string,mixed> */
    public function build(string $question, string $requestedObject, array $answer): array
    {
        $requestedObject = strtolower(trim($requestedObject));
        if ($requestedObject !== '' && !isset(self::OBJECTS[$requestedObject])) {
            throw new InvalidArgumentException('决策对象无效');
        }

        $selection = $requestedObject !== ''
            ? [
                'status' => 'selected',
                'primary_object' => $requestedObject,
                'candidates' => [$this->candidate($requestedObject, 0)],
                'matched_terms' => [],
            ]
            : $this->classify($question);
        $primaryObject = (string)($selection['primary_object'] ?? '');
        $definition = self::OBJECTS[$primaryObject] ?? null;
        $factCount = max(0, (int)($answer['evidence_counts']['facts'] ?? 0));
        $blocked = (string)($answer['status'] ?? '') === 'blocked_by_missing_facts' || $factCount === 0;

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'framework_name' => '收益决策八维框架',
            'source' => [
                'material_type' => 'user_provided_reference_image',
                'observed_on' => '2026-08-16',
                'fingerprint' => self::SOURCE_FINGERPRINT,
            ],
            'requested_object' => $requestedObject,
            'classification_status' => (string)$selection['status'],
            'selection_basis' => $requestedObject !== '' ? 'user_selected' : 'question_keyword_match',
            'primary_object' => $primaryObject,
            'primary_label' => is_array($definition) ? (string)$definition['label'] : '',
            'candidate_objects' => array_values((array)($selection['candidates'] ?? [])),
            'matched_terms' => array_values((array)($selection['matched_terms'] ?? [])),
            'key_inputs' => is_array($definition) ? array_values((array)$definition['key_inputs']) : [],
            'core_boundary' => is_array($definition)
                ? (string)$definition['core_boundary']
                : ($selection['status'] === 'ambiguous'
                    ? '问题同时涉及多个决策对象，请先锁定主对象再判断。'
                    : '尚未识别主决策对象；框架不会替用户补造选择。'),
            'method_refs' => [
                'primary' => is_array($definition) ? array_values((array)$definition['primary_methods']) : [],
                'supporting' => is_array($definition) ? array_values((array)$definition['supporting_methods']) : [],
                'definition_status' => 'source_codes_only_definitions_not_provided',
            ],
            'evidence_gate' => [
                'status' => $blocked ? 'blocked_by_missing_facts' : 'fact_packet_available_inputs_not_assessed',
                'fact_count' => $factCount,
                'key_input_coverage' => 'not_assessed',
                'key_inputs_verified' => false,
                'can_execute' => false,
                'message' => $blocked
                    ? '缺少同酒店、同平台、同日期严格回读事实；框架仅展示检查路径。'
                    : '已存在严格回读事实包，但尚未证明关键输入逐项齐全；仍需按输入清单核对。',
            ],
            'framework_boundary' => '该框架只组织分析，不生成经营事实；RM代码仅保留来源索引，因定义未提供，不执行或解释未知方法。',
        ];
    }

    /** @return array<string,mixed> */
    private function classify(string $question): array
    {
        $normalized = mb_strtolower(trim($question));
        $scores = [];
        $terms = [];
        foreach (self::OBJECTS as $key => $definition) {
            $scores[$key] = 0;
            foreach ((array)$definition['keywords'] as $keyword) {
                $keyword = mb_strtolower((string)$keyword);
                if ($keyword !== '' && str_contains($normalized, $keyword)) {
                    $scores[$key]++;
                    $terms[$keyword] = true;
                }
            }
        }
        $maxScore = $scores === [] ? 0 : max($scores);
        if ($maxScore <= 0) {
            return [
                'status' => 'unclassified',
                'primary_object' => '',
                'candidates' => [],
                'matched_terms' => [],
            ];
        }

        $keys = array_keys(array_filter($scores, static fn(int $score): bool => $score === $maxScore));
        $candidates = array_map(fn(string $key): array => $this->candidate($key, $scores[$key]), $keys);
        return [
            'status' => count($keys) === 1 ? 'inferred' : 'ambiguous',
            'primary_object' => count($keys) === 1 ? $keys[0] : '',
            'candidates' => $candidates,
            'matched_terms' => array_slice(array_keys($terms), 0, 12),
        ];
    }

    /** @return array<string,mixed> */
    private function candidate(string $key, int $score): array
    {
        return [
            'key' => $key,
            'label' => (string)(self::OBJECTS[$key]['label'] ?? $key),
            'score' => max(0, $score),
        ];
    }
}

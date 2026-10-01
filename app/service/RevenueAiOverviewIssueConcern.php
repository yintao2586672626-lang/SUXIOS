<?php
declare(strict_types=1);

namespace app\service;

trait RevenueAiOverviewIssueConcern
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function uniqueIssueRows(array $rows): array
    {
        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            $key = (string)($row['key'] ?? $row['reason'] ?? json_encode($row));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $row;
        }
        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function enrichIssueRows(array $rows, string $type): array
    {
        return array_map(fn(array $row): array => $this->enrichIssueRow($row, $type), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function enrichIssueRow(array $row, string $type): array
    {
        $reason = trim((string)($row['reason'] ?? 'data_not_complete'));
        $channel = strtolower(trim((string)($row['channel'] ?? '')));
        $meta = $this->issueReasonMeta($reason, $channel, $type);
        $label = trim((string)($row['label'] ?? ''));
        if ($label === '' && in_array($channel, self::CHANNELS, true)) {
            $label = $this->channelLabel($channel) . '数据状态';
        }
        if ($label === '') {
            $label = $type === 'missing_dataset' ? '缺失数据集' : '数据质量问题';
        }
        return array_merge($row, [
            'type' => $row['type'] ?? $type,
            'label' => $label,
            'severity' => $row['severity'] ?? $meta['severity'],
            'category' => $row['category'] ?? $meta['category'],
            'display_reason' => $row['display_reason'] ?? $meta['display_reason'],
            'next_action' => $row['next_action'] ?? $meta['next_action'],
            'target_page' => $row['target_page'] ?? $meta['target_page'],
            'target_tab' => $row['target_tab'] ?? $meta['target_tab'],
            'target_platform' => $row['target_platform'] ?? ($channel !== '' ? $channel : $meta['target_platform']),
            'evidence' => $row['evidence'] ?? ($row['message'] ?? $row['detail'] ?? $this->issueMessage($reason)),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function issueReasonMeta(string $reason, string $channel = '', string $type = 'quality_issue'): array
    {
        $platformLabel = in_array($channel, self::CHANNELS, true) ? $this->channelLabel($channel) : 'OTA';
        $base = [
            'severity' => 'medium',
            'category' => 'data',
            'display_reason' => $this->issueMessage($reason),
            'next_action' => '进入数据健康面板复核原始状态。',
            'target_page' => 'online-data',
            'target_tab' => 'data-health',
            'target_platform' => $channel,
        ];
        $overrides = [
            'AUTH_EXPIRED' => ['severity' => 'high', 'category' => 'auth', 'display_reason' => $platformLabel . '登录或授权已失效，Cookie/Profile 状态需复核。', 'next_action' => '进入数据健康面板复核登录/Cookie 状态，必要时重新登录。'],
            'CAPTCHA_REQUIRED' => ['severity' => 'high', 'category' => 'auth', 'display_reason' => $platformLabel . '需要验证码或人工登录确认。', 'next_action' => '进入平台账号状态处理验证码或人工登录。'],
            'PAGE_CHANGED' => ['severity' => 'high', 'category' => 'parser', 'display_reason' => $platformLabel . '页面结构变化，采集解析需复核。', 'next_action' => '复核最近一次采集证据和字段映射。'],
            'FIELD_MISSING' => ['severity' => 'high', 'category' => 'field', 'display_reason' => $platformLabel . '关键字段缺失。', 'next_action' => '进入数据健康面板查看缺字段明细。'],
            'PARSER_MISMATCH' => ['severity' => 'high', 'category' => 'parser', 'display_reason' => $platformLabel . '解析器与平台返回结构不匹配。', 'next_action' => '复核平台返回样本和解析规则。'],
            'NETWORK_ERROR' => ['severity' => 'medium', 'category' => 'network', 'display_reason' => $platformLabel . '平台请求网络异常。', 'next_action' => '查看同步日志并重试采集。'],
            'RATE_LIMITED' => ['severity' => 'medium', 'category' => 'platform', 'display_reason' => $platformLabel . '平台请求被限流。', 'next_action' => '暂停高频重试，稍后复核采集任务。'],
            'DATE_NOT_AVAILABLE' => ['severity' => 'medium', 'category' => 'data', 'display_reason' => $platformLabel . '未命中目标经营日期入库数据。', 'next_action' => '进入数据健康面板检查目标日期采集和入库记录。'],
            'overview_dataset_read_failed' => ['severity' => 'high', 'category' => 'data', 'display_reason' => '当前 OTA 渠道汇总读取失败。', 'next_action' => '进入数据健康面板检查本次汇总读取错误，恢复后按同酒店、渠道及经营日期核对实际记录。'],
            'target_date_dataset_failed' => ['severity' => 'high', 'category' => 'data', 'display_reason' => $platformLabel . '目标经营日期数据读取失败。', 'next_action' => '进入数据健康面板检查目标日期的数据读取错误，恢复读取后核对实际记录。'],
            'DATA_STALE' => ['severity' => 'high', 'category' => 'stale', 'display_reason' => $platformLabel . '数据过期，目标经营日期没有新入库证据。', 'next_action' => '进入数据健康面板复核最后同步时间并重新采集。'],
            'overview_scope_mismatch' => ['severity' => 'high', 'category' => 'scope', 'display_reason' => '事实的酒店、平台或业务日期与当前总览范围不一致，已排除出目标日指标。', 'next_action' => '按当前酒店、平台和业务日期重新采集或导入，并核对来源 trace。'],
            'metric_scope_mismatch' => ['severity' => 'high', 'category' => 'scope', 'display_reason' => $platformLabel . '指标名称、事实身份与当前酒店、平台或业务日期不一致，或币种、单位不符合目标口径。', 'next_action' => '核对指标名称、目标范围、币种、单位、来源 trace、保存记录和精确回读后再使用该指标。'],
            'metric_truth_unverified' => ['severity' => 'high', 'category' => 'truth', 'display_reason' => $platformLabel . '指标数值缺少完整来源或精确回读证据。', 'next_action' => '补齐来源 trace、保存成功和精确回读证据。'],
            'metric_truth_partial' => ['severity' => 'medium', 'category' => 'truth', 'display_reason' => $platformLabel . '指标只有部分事实通过真实性门禁。', 'next_action' => '补齐未验证记录的来源和精确回读证据。'],
            'metric_truth_collection_failed' => ['severity' => 'high', 'category' => 'truth', 'display_reason' => $platformLabel . '指标来源采集失败。', 'next_action' => '先修复采集失败并重新保存、精确回读。'],
            'available_room_nights_missing' => ['severity' => 'high', 'category' => 'denominator', 'display_reason' => '暂缺可信 OTA 渠道可售房晚分母，不能计算或外推全酒店 RevPAR。', 'next_action' => '补齐并核验 OTA 渠道可售房晚口径后再计算 OTA 渠道贡献RevPAR。', 'target_platform' => 'ota'],
            'online_daily_data_empty' => ['severity' => 'medium', 'category' => 'data', 'display_reason' => $platformLabel . '目标经营日期没有可用 OTA 入库数据。', 'next_action' => '进入数据健康面板检查该日期采集、导入和字段校验状态。'],
            'ota_revenue_metrics_missing' => ['severity' => 'high', 'category' => 'metric', 'display_reason' => '已命中 OTA 目标日数据，但房费收入或间夜指标缺失。', 'next_action' => '复核 online_daily_data 的 revenue、room_revenue、room_nights 字段映射和入库值。'],
            'ota_room_nights_zero' => ['severity' => 'medium', 'category' => 'metric', 'display_reason' => '已命中 OTA 目标日数据，但间夜为 0，无法计算 ADR。', 'next_action' => '复核携程目标日 business 行的 room_nights/order_count；若确认为 0，则只做观察，不生成调价建议。'],
            'ZERO_CONFIRMED' => ['severity' => 'low', 'category' => 'data', 'display_reason' => $platformLabel . '明确确认目标经营日期无数据。', 'next_action' => '无需填充假数据；如业务预期应有数据，再进入数据健康面板复核采集范围。'],
            'source_not_loaded' => ['severity' => 'medium', 'category' => 'source', 'display_reason' => $platformLabel . '数据源未加载或未接入。', 'next_action' => '进入数据健康面板检查平台数据源配置。'],
            'source_status_missing' => ['severity' => 'medium', 'category' => 'source', 'display_reason' => $platformLabel . '缺少平台数据源状态。', 'next_action' => '进入数据健康面板检查 platform_data_sources 绑定。'],
            'source_status_unknown' => ['severity' => 'medium', 'category' => 'source', 'display_reason' => $platformLabel . '平台同步状态未知。', 'next_action' => '进入数据健康面板复核最近一次同步记录。'],
            'waiting_config' => ['severity' => 'high', 'category' => 'auth', 'display_reason' => $platformLabel . '平台数据源待授权或配置。', 'next_action' => '补齐平台账号/授权配置后重新同步。'],
            'source_disabled' => ['severity' => 'high', 'category' => 'source', 'display_reason' => $platformLabel . '平台数据源已禁用。', 'next_action' => '确认是否恢复该平台数据源。'],
            'sync_failed' => ['severity' => 'high', 'category' => 'sync', 'display_reason' => $platformLabel . '平台同步失败。', 'next_action' => '进入数据健康面板查看失败原因并重试。'],
            'competitor_price_fields_missing' => ['severity' => 'medium', 'category' => 'competitor', 'display_reason' => '暂缺竞对价格字段。', 'next_action' => '补齐竞对价格采集字段后再判断倒挂风险。'],
            'competitor_price_above_competitor' => ['severity' => 'medium', 'category' => 'competitor', 'display_reason' => '本店均价高于竞对均价，需人工复核是否存在价格倒挂或竞争力风险。', 'next_action' => '复核竞对样本、房型口径和最低保护价后再进入人工调价审核。'],
            'competitor_price_below_competitor_review_required' => ['severity' => 'medium', 'category' => 'competitor', 'display_reason' => '本店均价低于竞对均价，需复核是否低于保护价后再判断调价。', 'next_action' => '补齐最低保护价和需求信号后再形成可审核调价建议。'],
            'competitor_price_aligned' => ['severity' => 'low', 'category' => 'competitor', 'display_reason' => '本店均价与竞对均价接近。', 'next_action' => '继续观察需求和转化数据，不自动生成调价建议。'],
            'holiday_signal_not_loaded' => ['severity' => 'medium', 'category' => 'event_signal', 'display_reason' => '节假日/事件信号尚未读取。', 'next_action' => '等待 Revenue AI 总览接口返回节假日窗口。', 'target_platform' => 'hotel'],
            'holiday_calendar_missing' => ['severity' => 'medium', 'category' => 'event_signal', 'display_reason' => '暂缺目标年份节假日日历。', 'next_action' => '补齐节假日日历后再判断事件影响。', 'target_platform' => 'hotel'],
            'holiday_event_in_window' => ['severity' => 'medium', 'category' => 'event_signal', 'display_reason' => '当前处于节假日窗口。', 'next_action' => '复核库存、底价、竞对价格和渠道活动。', 'target_platform' => 'hotel'],
            'holiday_event_nearby' => ['severity' => 'medium', 'category' => 'event_signal', 'display_reason' => '近期存在节假日窗口。', 'next_action' => '提前确认库存、底价、连住和高需求日调价节奏。', 'target_platform' => 'hotel'],
            'holiday_event_upcoming' => ['severity' => 'low', 'category' => 'event_signal', 'display_reason' => '30 天内存在节假日窗口。', 'next_action' => '纳入人工调价复核，但不自动改价。', 'target_platform' => 'hotel'],
            'holiday_event_none_nearby' => ['severity' => 'low', 'category' => 'event_signal', 'display_reason' => '30 天内暂无节假日窗口。', 'next_action' => '继续每日滚动观察需求和竞对变化。', 'target_platform' => 'hotel'],
            'demand_forecasts_not_loaded' => ['severity' => 'medium', 'category' => 'demand_signal', 'display_reason' => '未来 7 天需求预测尚未读取。', 'next_action' => '等待 Revenue AI 总览接口返回 demand_forecasts 摘要。', 'target_platform' => 'hotel'],
            'demand_forecasts_missing' => ['severity' => 'high', 'category' => 'demand_signal', 'display_reason' => '需求预测表 demand_forecasts 不存在。', 'next_action' => '恢复需求预测表后再展示未来 7 天信号。', 'target_platform' => 'hotel'],
            'demand_forecasts_required_fields_missing' => ['severity' => 'high', 'category' => 'demand_signal', 'display_reason' => '需求预测表缺少 hotel_id 或 forecast_date 等必要字段。', 'next_action' => '修复 demand_forecasts 字段契约后再展示未来 7 天信号。', 'target_platform' => 'hotel'],
            'demand_forecasts_metric_fields_missing' => ['severity' => 'high', 'category' => 'demand_signal', 'display_reason' => '需求预测表缺少 predicted_occupancy 或 predicted_demand。', 'next_action' => '补齐预测指标字段后再判断未来 7 天需求。', 'target_platform' => 'hotel'],
            'demand_forecasts_read_failed' => ['severity' => 'high', 'category' => 'demand_signal', 'display_reason' => '未来 7 天需求预测读取失败。', 'next_action' => '检查 demand_forecasts 读取权限和数据库错误。', 'target_platform' => 'hotel'],
            'demand_forecasts_empty' => ['severity' => 'medium', 'category' => 'demand_signal', 'display_reason' => '未来 7 天暂无需求预测记录。', 'next_action' => '生成或导入未来 7 天需求预测后再进入人工调价判断。', 'target_platform' => 'hotel'],
            'demand_forecasts_metric_missing' => ['severity' => 'medium', 'category' => 'demand_signal', 'display_reason' => '需求预测记录缺少可计算指标。', 'next_action' => '补齐入住率或需求间夜后再判断未来需求。', 'target_platform' => 'hotel'],
            'demand_forecasts_low_confidence' => ['severity' => 'medium', 'category' => 'demand_signal', 'display_reason' => '未来 7 天需求预测置信度偏低。', 'next_action' => '用近期订单和竞对样本校准预测后再进入调价审核。', 'target_platform' => 'hotel'],
            'demand_forecasts_high_demand' => ['severity' => 'medium', 'category' => 'demand_signal', 'display_reason' => '未来 7 天存在高需求日期。', 'next_action' => '结合最低保护价和竞对价格进入人工调价复核。', 'target_platform' => 'hotel'],
            'demand_forecasts_available' => ['severity' => 'low', 'category' => 'demand_signal', 'display_reason' => '已读取未来 7 天需求预测。', 'next_action' => '继续结合竞对、保护价和人工审核判断调价。', 'target_platform' => 'hotel'],
            'floor_price_missing' => ['severity' => 'high', 'category' => 'pricing_guard', 'display_reason' => '暂缺最低保护价。', 'next_action' => '补齐房型/价格计划级最低保护价后再允许生成可审核调价建议。'],
            'manual_review_workflow_not_connected' => ['severity' => 'high', 'category' => 'pricing_guard', 'display_reason' => '暂未接入人工审核工作流。', 'next_action' => '接入建议版本、批准/拒绝/转执行审计流后再开放调价建议。'],
            'price_suggestions_missing' => ['severity' => 'high', 'category' => 'pricing_review', 'display_reason' => '定价建议表 price_suggestions 不存在。', 'next_action' => '恢复定价建议表后再展示人工审核队列。'],
            'price_suggestions_required_fields_missing' => ['severity' => 'high', 'category' => 'pricing_review', 'display_reason' => '定价建议表缺少 status 或 suggestion_date 等必要字段。', 'next_action' => '修复 price_suggestions 字段契约后再展示人工审核队列。'],
            'price_suggestions_read_failed' => ['severity' => 'high', 'category' => 'pricing_review', 'display_reason' => '定价建议审核队列读取失败。', 'next_action' => '检查 price_suggestions 读取权限和数据库错误。'],
            'price_suggestions_empty' => ['severity' => 'low', 'category' => 'pricing_review', 'display_reason' => '目标经营日期暂无存量调价建议。', 'next_action' => '继续补齐需求、竞对、保护价等前置条件后再生成可审核建议。'],
            'price_suggestions_pending_review' => ['severity' => 'medium', 'category' => 'pricing_review', 'display_reason' => '存在待人工审核调价建议。', 'next_action' => '进入定价建议列表完成人工批准、修改后批准、拒绝或转执行。'],
            'price_suggestions_reviewed' => ['severity' => 'low', 'category' => 'pricing_review', 'display_reason' => '目标经营日期调价建议已处理。', 'next_action' => '复核已处理建议是否需要转执行或补充效果复盘证据。'],
            'agent_logs_not_loaded' => ['severity' => 'medium', 'category' => 'agent_activity', 'display_reason' => '收益管理 Agent 日志尚未读取。', 'next_action' => '等待 Revenue AI 总览接口返回 Agent 日志摘要。'],
            'agent_logs_missing' => ['severity' => 'high', 'category' => 'agent_activity', 'display_reason' => 'Agent 日志表 agent_logs 不存在。', 'next_action' => '恢复 Agent 日志表后再展示操作追溯。'],
            'agent_logs_required_fields_missing' => ['severity' => 'high', 'category' => 'agent_activity', 'display_reason' => 'Agent 日志表缺少 agent_type、log_level 或 create_time 等必要字段。', 'next_action' => '修复 agent_logs 字段契约后再展示操作追溯。'],
            'agent_logs_read_failed' => ['severity' => 'high', 'category' => 'agent_activity', 'display_reason' => '收益管理 Agent 日志读取失败。', 'next_action' => '检查 agent_logs 读取权限和数据库错误。'],
            'agent_logs_empty' => ['severity' => 'low', 'category' => 'agent_activity', 'display_reason' => '目标经营日期暂无收益管理 Agent 操作日志。', 'next_action' => '如预期应有动作，检查收益管理 Agent 触发链路。'],
            'agent_logs_available' => ['severity' => 'low', 'category' => 'agent_activity', 'display_reason' => '已读取收益管理 Agent 操作日志。', 'next_action' => '继续只读追踪，不把日志数量当作业务成功证据。'],
            'agent_logs_warning_present' => ['severity' => 'medium', 'category' => 'agent_activity', 'display_reason' => '收益管理 Agent 存在警告日志。', 'next_action' => '复核警告是否影响今日调价判断。'],
            'agent_logs_error_present' => ['severity' => 'high', 'category' => 'agent_activity', 'display_reason' => '收益管理 Agent 存在错误日志。', 'next_action' => '先处理错误日志，再继续生成或执行建议。'],
            'operation_execution_not_loaded' => ['severity' => 'medium', 'category' => 'operation_execution', 'display_reason' => '运营执行闭环尚未读取。', 'next_action' => '等待 Revenue AI 总览接口返回执行摘要。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_intents_missing' => ['severity' => 'high', 'category' => 'operation_execution', 'display_reason' => '执行意图表 operation_execution_intents 不存在。', 'next_action' => '恢复执行意图表后再展示执行进度。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_tasks_missing' => ['severity' => 'high', 'category' => 'operation_execution', 'display_reason' => '执行任务表 operation_execution_tasks 不存在。', 'next_action' => '恢复执行任务表后再展示执行进度。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_evidence_missing' => ['severity' => 'high', 'category' => 'operation_execution', 'display_reason' => '执行证据表 operation_execution_evidence 不存在或缺少执行证据。', 'next_action' => '补齐执行证据后再判断效果复盘。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_read_failed' => ['severity' => 'high', 'category' => 'operation_execution', 'display_reason' => '运营执行闭环读取失败。', 'next_action' => '检查执行闭环表读取权限和数据库错误。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_empty' => ['severity' => 'low', 'category' => 'operation_execution', 'display_reason' => '目标经营日期暂无调价执行记录。', 'next_action' => '如已有人工审核建议，请在运营执行页转为执行意图后再追踪。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_pending_approval' => ['severity' => 'medium', 'category' => 'operation_execution', 'display_reason' => '存在待审批的调价执行意图。', 'next_action' => '进入运营执行页完成人工审批；Revenue AI 首页不直接批准。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_in_progress' => ['severity' => 'medium', 'category' => 'operation_execution', 'display_reason' => '存在待执行或执行中的调价任务。', 'next_action' => '进入运营执行页记录实际执行结果和执行人。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_evidence_needed' => ['severity' => 'medium', 'category' => 'operation_execution', 'display_reason' => '调价任务已执行但缺少执行前后证据。', 'next_action' => '补充执行前后价格、收入或平台回执证据。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_review_needed' => ['severity' => 'medium', 'category' => 'operation_execution', 'display_reason' => '调价执行已具备证据，等待效果复盘。', 'next_action' => '进入运营执行页触发效果复盘。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_reviewed' => ['severity' => 'low', 'category' => 'operation_execution', 'display_reason' => '目标经营日期调价执行已完成复盘。', 'next_action' => '复核 ROI 或增量收入证据，作为明日调价判断输入。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_blocked' => ['severity' => 'high', 'category' => 'operation_execution', 'display_reason' => '调价执行存在阻塞、拒绝或失败记录。', 'next_action' => '先处理审批、执行或平台回写阻塞原因。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_partial' => ['severity' => 'medium', 'category' => 'operation_execution', 'display_reason' => '调价执行闭环尚未形成完整进度。', 'next_action' => '继续在运营执行页维护执行记录和复盘证据。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_not_executed' => ['severity' => 'medium', 'category' => 'operation_execution', 'display_reason' => '调价任务尚未记录实际执行完成。', 'next_action' => '先记录执行结果，再做效果复盘。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_effect_review_pending' => ['severity' => 'medium', 'category' => 'operation_execution', 'display_reason' => '调价效果复盘待处理。', 'next_action' => '区分执行完成和收益验证，补齐复盘记录。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_effect_review_ready' => ['severity' => 'low', 'category' => 'operation_execution', 'display_reason' => '调价效果已有复盘和 ROI 证据。', 'next_action' => '将复盘结果作为明日调价判断输入。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_roi_missing' => ['severity' => 'medium', 'category' => 'operation_execution', 'display_reason' => '调价复盘缺少 ROI 或增量收入证据。', 'next_action' => '补齐执行前后收入、成本或平台回执后再判断效果。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'adr_denominator_zero' => ['severity' => 'medium', 'category' => 'metric', 'display_reason' => 'OTA 间夜为 0，ADR 不可计算。', 'next_action' => '复核目标日期 OTA 间夜是否为渠道确认零值。'],
        ];
        $meta = array_merge($base, $overrides[$reason] ?? []);
        $pricingGenerationMeta = match ($reason) {
            'price_suggestion_generation_not_loaded' => ['severity' => 'medium', 'category' => 'pricing_generation', 'display_reason' => '调价建议生成预检尚未加载。', 'next_action' => '先加载 room_types、demand_forecasts、competitor_analysis 和 price_suggestions 的只读预检，再决定是否生成待审建议。'],
            'pricing_generation_hotel_scope_missing' => ['severity' => 'high', 'category' => 'pricing_generation', 'display_reason' => '调价建议生成缺少目标系统酒店范围。', 'next_action' => '先选择或导入可映射到系统酒店的携程 OTA 数据，再生成待审调价建议。'],
            'room_types_empty' => ['severity' => 'high', 'category' => 'pricing_generation', 'display_reason' => '携程目标酒店暂无启用房型，不能生成待审调价建议。', 'next_action' => '为携程目标酒店配置启用房型、基础价和最低保护价后，再补需求预测与竞对样本。'],
            'pricing_candidate_signals_missing' => ['severity' => 'medium', 'category' => 'pricing_generation', 'display_reason' => '调价候选信号不足，当前不会生成待审建议。', 'next_action' => '补齐需求预测、竞对价格、历史价格变化和保护价信号，直到只读预检出现可生成候选。'],
            'pricing_generation_candidates_ready' => ['severity' => 'low', 'category' => 'pricing_generation', 'display_reason' => '已存在可生成待审调价建议的只读候选。', 'next_action' => '进入收益 Agent 生成待审建议；生成后仍需人工审核，不写 OTA。'],
            default => [],
        };
        if ($pricingGenerationMeta !== []) {
            $meta = array_merge($meta, $pricingGenerationMeta);
        }
        if (str_starts_with($reason, 'price_suggestions_')
            || in_array($reason, [
                'price_suggestion_generation_not_loaded',
                'pricing_generation_hotel_scope_missing',
                'room_types_empty',
                'pricing_candidate_signals_missing',
                'pricing_generation_candidates_ready',
            ], true)) {
            $meta['target_page'] = 'agent-center';
            $meta['target_tab'] = 'suggestions';
            $meta['target_agent_tab'] = 'revenue';
            $meta['target_revenue_tab'] = 'suggestions';
        }
        return $meta;
    }

    /**
     * @param array<int, array<string, mixed>> $missingDatasets
     * @param array<int, array<string, mixed>> $qualityIssues
     * @return array<string, mixed>
     */
    private function issueSummary(array $missingDatasets, array $qualityIssues): array
    {
        $rows = array_merge($missingDatasets, $qualityIssues);
        $bySeverity = [];
        $byCategory = [];
        foreach ($rows as $row) {
            $severity = (string)($row['severity'] ?? 'medium');
            $category = (string)($row['category'] ?? 'data');
            $bySeverity[$severity] = ($bySeverity[$severity] ?? 0) + 1;
            $byCategory[$category] = ($byCategory[$category] ?? 0) + 1;
        }
        return [
            'total' => count($rows),
            'missing_count' => count($missingDatasets),
            'quality_count' => count($qualityIssues),
            'high_count' => (int)($bySeverity['high'] ?? 0),
            'by_severity' => $bySeverity,
            'by_category' => $byCategory,
        ];
    }
}

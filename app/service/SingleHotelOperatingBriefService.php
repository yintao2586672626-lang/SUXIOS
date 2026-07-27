<?php
declare(strict_types=1);

namespace app\service;

/**
 * Builds a local-only, target-independent preview for the single-hotel
 * Dingdandao + Ctrip + Meituan digest.
 *
 * This service does not persist, schedule or deliver anything externally.
 */
final class SingleHotelOperatingBriefService
{
    public const CONTRACT_VERSION = 'suxios.single_hotel_operating_brief.v1';

    /** @param array<string,mixed> $digest @return array<string,mixed> */
    public function preview(array $digest): array
    {
        $sources = is_array($digest['sources'] ?? null) ? $digest['sources'] : [];
        $pms = is_array($sources['pms'] ?? null) ? $sources['pms'] : [];
        $ctrip = is_array($sources['ctrip'] ?? null) ? $sources['ctrip'] : [];
        $meituan = is_array($sources['meituan'] ?? null) ? $sources['meituan'] : [];
        $pmsFacts = is_array($pms['facts'] ?? null) ? $pms['facts'] : [];
        $ctripFacts = is_array($ctrip['facts'] ?? null) ? $ctrip['facts'] : [];
        $meituanFacts = is_array($meituan['facts'] ?? null) ? $meituan['facts'] : [];

        $sourceGatePassed = ($digest['applies'] ?? false) === true
            && ($digest['delivery_allowed'] ?? false) === true
            && ($pms['delivery_evidence_ready'] ?? false) === true
            && ($ctrip['delivery_evidence_ready'] ?? false) === true
            && ($meituan['delivery_evidence_ready'] ?? false) === true;
        $status = $sourceGatePassed ? 'preview_ready' : 'blocked';
        $targetStatus = (string)($digest['operating_target_status'] ?? 'not_set');
        $targetLine = $targetStatus === 'present'
            ? '经营目标：已设置（本简报仍保持三源事实分列）'
            : '经营目标：未设置（不影响三源数据预览）';
        $lines = [
            '# 宿析OS｜敦煌漠蓝新三源经营简报',
            '> 日期：' . $this->text($digest['business_date'] ?? null, '未验证'),
            '> 状态：' . ($sourceGatePassed
                ? '三源同店同日证据已通过，可供本地预览'
                : '三源证据未全部通过，仅展示缺失/阻断状态'),
            '> ' . $targetLine,
            '',
            '## 订单来了PMS｜住宿经营指标',
            '- 总房费：' . $this->money($pmsFacts['room_fee_revenue'] ?? null)
                . '；ADR：' . $this->money($pmsFacts['adr'] ?? null),
            '- 入住率：' . $this->percent($pmsFacts['occupancy_rate_percent'] ?? null)
                . '；RevPAR：' . $this->money($pmsFacts['revpar'] ?? null),
            '- 累计售出间夜：' . $this->count($pmsFacts['sold_room_nights'] ?? null)
                . '；平均每日间夜：' . $this->count(
                    $pmsFacts['average_daily_room_nights'] ?? null
                ),
            '- 可售房夜：' . $this->count($pmsFacts['sellable_room_nights'] ?? null)
                . '；明细房费合计：' . $this->money(
                    $pmsFacts['detail_room_fee_total'] ?? null
                ),
            '',
            '## 携程｜渠道事实',
            '- 渠道收入：' . $this->money($ctripFacts['channel_revenue'] ?? null)
                . '；订单：' . $this->count($ctripFacts['orders'] ?? null)
                . '；间夜：' . $this->count($ctripFacts['room_nights'] ?? null),
            '',
            '## 美团｜流量与订单事实',
            '- 列表曝光：' . $this->count($meituanFacts['list_exposure'] ?? null)
                . '；详情曝光：' . $this->count($meituanFacts['detail_exposure'] ?? null),
            '- 流量转化率：' . $this->percent($meituanFacts['flow_rate_percent'] ?? null)
                . '；支付订单：' . $this->count($meituanFacts['paid_orders'] ?? null),
            '- 目标日期订单：' . $this->count(
                $meituanFacts['target_date_order_count'] ?? null
            )
                . '；渠道收入：' . $this->money($meituanFacts['channel_revenue'] ?? null)
                . '；间夜：' . $this->count($meituanFacts['room_nights'] ?? null),
            '',
            '> 口径：PMS住宿房费、携程渠道事实和美团渠道事实分别展示，禁止直接相加；'
                . '“未获取”不会用0、旧数据或默认值代替。',
        ];
        $blockers = array_values(array_filter(
            (array)($digest['blockers'] ?? []),
            'is_array'
        ));
        if (!$sourceGatePassed && $blockers !== []) {
            $lines[] = '';
            $lines[] = '## 当前阻断';
            foreach (array_slice($blockers, 0, 6) as $blocker) {
                $lines[] = '- ' . $this->text(
                    $blocker['message'] ?? $blocker['code'] ?? null,
                    '来源证据未通过'
                );
            }
        }

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $status,
            'preview_only' => true,
            'message_sent' => false,
            'external_delivery_authorized' => false,
            'source_gate_passed' => $sourceGatePassed,
            'tenant_id' => (int)($digest['tenant_id'] ?? 0),
            'hotel_id' => (int)($digest['hotel_id'] ?? 0),
            'hotel_name' => $this->text($digest['hotel_name'] ?? null, ''),
            'business_date' => $this->text($digest['business_date'] ?? null, ''),
            'operating_target_status' => $targetStatus,
            'content' => mb_strcut(implode("\n", $lines), 0, 3800, 'UTF-8'),
            'blockers' => $blockers,
            'gaps' => array_values(array_filter(
                (array)($digest['gaps'] ?? []),
                'is_array'
            )),
        ];
    }

    private function money(mixed $value): string
    {
        $number = $this->number($value);

        return $number === null ? '未获取' : '¥' . number_format($number, 2, '.', ',');
    }

    private function percent(mixed $value): string
    {
        $number = $this->number($value);

        return $number === null ? '未获取' : $this->decimal($number) . '%';
    }

    private function count(mixed $value): string
    {
        $number = $this->number($value);

        return $number === null ? '未获取' : $this->decimal($number);
    }

    private function decimal(float $number): string
    {
        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    private function number(mixed $value): ?float
    {
        if (is_bool($value) || $value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $number = (float)$value;

        return is_finite($number) && $number >= 0 ? $number : null;
    }

    private function text(mixed $value, string $fallback): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return $fallback;
        }

        return mb_strcut(
            preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? $fallback,
            0,
            120,
            'UTF-8'
        );
    }
}

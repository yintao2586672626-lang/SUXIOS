<?php
declare(strict_types=1);

namespace app\exception;

use think\exception\ValidateException;

final class MeituanHistoricalCaptureRejectedException extends ValidateException
{
    public const REASON = 'historical_snapshot_incomplete';
    public const STAGE = 'persistence';

    public function __construct(array $missing)
    {
        $labels = array_values(array_intersect(
            ['收入', '间夜', 'ADR', '订单数', '曝光', '访客', '转化率', '支付订单数', '起价'],
            $missing
        ));
        parent::__construct('本次美团历史采集缺少已有指标（'
            . implode('、', $labels) . '），未覆盖历史记录；请补齐字段后重采。');
    }
}

<?php
declare(strict_types=1);

namespace app\service\concern;

/** Requires verified competitor pairs before the manual pricing review gate. */
trait RevenueAiOverviewCompetitorSignalConcern
{
    /**
     * @param array<string, mixed> $competitorSignal
     * @return array<string, mixed>
     */
    private function competitorPriceGate(array $competitorSignal): array
    {
        $competitorPriceReady = ($competitorSignal['truth']['status'] ?? '') === 'verified'
            && ($competitorSignal['value'] ?? '--') !== '--'
            && in_array(($competitorSignal['reason'] ?? ''), [
                'competitor_price_above_competitor',
                'competitor_price_below_competitor_review_required',
                'competitor_price_aligned',
            ], true);
        $competitorPriceGate = $this->pricingGate(
            'competitor_price',
            '竞对价格位置',
            $competitorPriceReady,
            'ok',
            (string)($competitorSignal['reason'] ?? 'competitor_price_fields_missing'),
            $competitorPriceReady
                ? '已有通过来源、保存和精确回读的 OTA 价格样本，仅供对应渠道范围的人工复核。'
                : (string)($competitorSignal['detail'] ?? '竞对价格样本缺失，或来源、保存和精确回读尚未确认。')
        );
        if (!$competitorPriceReady && ($competitorSignal['detail'] ?? '') !== '') {
            $competitorPriceGate['display_reason'] = $competitorSignal['detail'];
        }
        return $competitorPriceGate;
    }
}

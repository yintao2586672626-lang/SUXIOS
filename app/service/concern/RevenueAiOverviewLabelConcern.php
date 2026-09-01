<?php
declare(strict_types=1);

namespace app\service\concern;

trait RevenueAiOverviewLabelConcern
{
    private function channelLabel(string $channel): string
    {
        return match ($channel) {
            'ctrip' => '携程',
            'meituan' => '美团',
            default => $channel,
        };
    }
}

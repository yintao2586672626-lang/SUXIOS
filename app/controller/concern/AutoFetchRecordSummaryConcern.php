<?php
declare(strict_types=1);

namespace app\controller\concern;

trait AutoFetchRecordSummaryConcern
{
    private function formatAutoFetchModuleSummary(array $modules): string
    {
        $parts = [];
        foreach ($modules as $module) {
            if (!is_array($module)) {
                continue;
            }
            $name = trim((string)($module['module'] ?? ''));
            if ($name === '') {
                continue;
            }
            $savedCount = (int)($module['saved_count'] ?? 0);
            $state = !empty($module['success']) ? 'ok' : (!empty($module['skipped']) ? 'skip' : 'fail');
            $strategy = trim((string)($module['strategy'] ?? ''));
            $strategyText = $strategy !== '' ? $strategy . ':' : '';
            $parts[] = $name . '[' . $strategyText . $state . ':' . $savedCount . ']';
        }

        return implode(' / ', $parts);
    }
}

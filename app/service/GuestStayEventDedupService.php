<?php
declare(strict_types=1);
namespace app\service;

final class GuestStayEventDedupService
{
    public function identical(array $current, array $next): bool
    {
        ksort($current); ksort($next);
        if ($current === $next) return true;
        if (($current['source_method'] ?? '') !== 'jd06_file_import' || ($next['source_method'] ?? '') !== 'jd06_file_import') return false;
        // Re-exported XLS/CSV files can change references while retaining the same anonymous completed stay.
        unset($current['source_reference'], $next['source_reference']);
        return $current === $next;
    }
}

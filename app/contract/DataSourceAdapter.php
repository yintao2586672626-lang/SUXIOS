<?php
declare(strict_types=1);

namespace app\contract;

interface DataSourceAdapter
{
    public function supports(array $source): bool;

    /**
     * @return array{status: string, message: string, payload?: array, http_status?: int}
     */
    public function fetch(array $source, array $options = []): array;
}

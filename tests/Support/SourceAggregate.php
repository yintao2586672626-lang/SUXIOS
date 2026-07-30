<?php
declare(strict_types=1);

namespace Tests\Support;

final class SourceAggregate
{
    /**
     * Read a concrete source together with its behavior-preserving concern files.
     *
     * Static contract tests should validate the effective class boundary rather
     * than forcing extracted methods back into a single oversized file.
     */
    public static function read(string $root, string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', ltrim($relativePath, '\\/'));
        $paths = [
            $relativePath,
            ...self::concernsFor($relativePath),
        ];

        $sources = [];
        foreach ($paths as $path) {
            $absolutePath = rtrim($root, '\\/') . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (!is_file($absolutePath)) {
                throw new \RuntimeException("Source aggregate member is missing: {$path}");
            }
            $sources[] = (string)file_get_contents($absolutePath);
        }

        return implode("\n", $sources);
    }

    /**
     * @return list<string>
     */
    private static function concernsFor(string $relativePath): array
    {
        return match ($relativePath) {
            'app/controller/Agent.php' => [
                'app/controller/concern/AgentOtaExecutionIntentConcern.php',
                'app/controller/concern/AgentCapturedOtaAnalysisConcern.php',
                'app/controller/concern/AgentOtaDiagnosisBuildConcern.php',
                'app/controller/concern/AgentOtaDiagnosisPersistenceConcern.php',
            ],
            'app/controller/concern/AutoFetchConcern.php' => [
                'app/controller/concern/AutoFetchProfileSyncConcern.php',
                'app/controller/concern/CtripAutoFetchExecutionConcern.php',
                'app/controller/concern/MeituanAutoFetchExecutionConcern.php',
            ],
            'app/service/PlatformDataSyncService.php' => [
                'app/service/concern/PlatformDataSourceExecutionConcern.php',
                'app/service/concern/PlatformSyncTaskConcern.php',
                'app/service/concern/PlatformDataPersistenceConcern.php',
            ],
            'app/service/OperationManagementService.php' => [
                'app/service/operation/OperationSnapshotConcern.php',
                'app/service/operation/OperationAlertConcern.php',
            ],
            default => [],
        };
    }
}

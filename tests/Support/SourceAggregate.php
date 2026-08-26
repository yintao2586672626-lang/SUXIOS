<?php
declare(strict_types=1);

namespace Tests\Support;

final class SourceAggregate
{
    /** @var array<string,array<string,list<string>>> */
    private static array $registryByRoot = [];

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
            ...self::concernsFor($root, $relativePath),
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
    private static function concernsFor(string $root, string $relativePath): array
    {
        $root = rtrim($root, '\\/');
        if (!isset(self::$registryByRoot[$root])) {
            $registryPath = $root . DIRECTORY_SEPARATOR . 'rules'
                . DIRECTORY_SEPARATOR . 'source-concern-contract-registry.json';
            if (!is_file($registryPath)) {
                throw new \RuntimeException('Source concern registry is missing.');
            }
            $registry = json_decode((string)file_get_contents($registryPath), true);
            if (!is_array($registry)
                || ($registry['schema_version'] ?? null) !== 'suxios.source_concern_registry.v1'
                || !is_array($registry['aggregates'] ?? null)
            ) {
                throw new \RuntimeException('Source concern registry is invalid.');
            }
            $aggregates = [];
            foreach ($registry['aggregates'] as $parent => $members) {
                if (!is_string($parent) || !is_array($members)) {
                    throw new \RuntimeException('Source concern registry aggregate is invalid.');
                }
                $normalizedMembers = [];
                foreach ($members as $member) {
                    if (!is_string($member) || trim($member) === '') {
                        throw new \RuntimeException("Source concern registry member is invalid: {$parent}");
                    }
                    $normalizedMembers[] = str_replace('\\', '/', ltrim($member, '\\/'));
                }
                $aggregates[str_replace('\\', '/', ltrim($parent, '\\/'))] = $normalizedMembers;
            }
            self::$registryByRoot[$root] = $aggregates;
        }

        return self::$registryByRoot[$root][$relativePath] ?? [];
    }
}

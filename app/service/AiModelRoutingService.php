<?php
declare(strict_types=1);

namespace app\service;

use app\model\AiModelConfig;
use Throwable;

final class AiModelRoutingService
{
    /** @var callable|null */
    private $configLoader;

    public function __construct(?callable $configLoader = null)
    {
        $this->configLoader = $configLoader;
    }

    /**
     * Resolve a model without overriding an explicit user or caller choice.
     *
     * @return array{
     *   model_key:string,
     *   provider:string,
     *   usage_scene:string,
     *   selection_mode:string,
     *   requested_model_key:string,
     *   config_id:int
     * }
     */
    public function resolve(
        string $requestedModelKey,
        string $usageScene,
        string $fallbackModelKey = 'deepseek_v4_default',
        string $preferredProvider = ''
    ): array {
        $requestedModelKey = trim($requestedModelKey);
        $usageScene = strtolower(trim($usageScene));
        $fallbackModelKey = trim($fallbackModelKey) !== '' ? trim($fallbackModelKey) : 'deepseek_v4_default';
        $preferredProvider = strtolower(trim($preferredProvider));

        if ($requestedModelKey !== '') {
            return $this->selection(
                $requestedModelKey,
                '',
                $usageScene,
                'explicit',
                $requestedModelKey,
                0
            );
        }

        $configs = $this->enabledConfigs();
        $candidates = array_values(array_filter(
            $configs,
            static fn(array $config): bool => in_array(
                $usageScene,
                self::usageSceneTokens((string)($config['usage_scene'] ?? '')),
                true
            )
        ));

        usort($candidates, static function (array $left, array $right) use ($preferredProvider): int {
            $leftPreferred = $preferredProvider !== ''
                && strtolower(trim((string)($left['provider'] ?? ''))) === $preferredProvider;
            $rightPreferred = $preferredProvider !== ''
                && strtolower(trim((string)($right['provider'] ?? ''))) === $preferredProvider;
            if ($leftPreferred !== $rightPreferred) {
                return $leftPreferred ? -1 : 1;
            }

            $defaultCompare = (int)($right['is_default'] ?? 0) <=> (int)($left['is_default'] ?? 0);
            if ($defaultCompare !== 0) {
                return $defaultCompare;
            }

            return (int)($left['id'] ?? PHP_INT_MAX) <=> (int)($right['id'] ?? PHP_INT_MAX);
        });

        foreach ($candidates as $candidate) {
            $modelKey = trim((string)($candidate['model_key'] ?? ''));
            if ($modelKey === '') {
                continue;
            }

            return $this->selection(
                $modelKey,
                (string)($candidate['provider'] ?? ''),
                $usageScene,
                'usage_scene',
                '',
                (int)($candidate['id'] ?? 0)
            );
        }

        return $this->selection(
            $fallbackModelKey,
            '',
            $usageScene,
            'fallback',
            '',
            0
        );
    }

    /** @return array<int, string> */
    public static function usageSceneTokens(string $usageScene): array
    {
        $tokens = preg_split('/[\s,，;；]+/u', strtolower(trim($usageScene))) ?: [];
        return array_values(array_unique(array_filter(
            array_map('trim', $tokens),
            static fn(string $token): bool => $token !== ''
        )));
    }

    /** @return array<int, array<string, mixed>> */
    private function enabledConfigs(): array
    {
        try {
            $rows = $this->configLoader !== null
                ? call_user_func($this->configLoader)
                : AiModelConfig::where('is_enabled', 1)
                    ->order('is_default', 'desc')
                    ->order('id', 'asc')
                    ->select()
                    ->toArray();
        } catch (Throwable $e) {
            return [];
        }

        if (!is_array($rows)) {
            return [];
        }

        $configs = [];
        foreach ($rows as $row) {
            if (is_object($row) && method_exists($row, 'toArray')) {
                $row = $row->toArray();
            }
            if (!is_array($row) || (int)($row['is_enabled'] ?? 1) !== 1) {
                continue;
            }
            $configs[] = $row;
        }

        return $configs;
    }

    /** @return array<string, int|string> */
    private function selection(
        string $modelKey,
        string $provider,
        string $usageScene,
        string $selectionMode,
        string $requestedModelKey,
        int $configId
    ): array {
        return [
            'model_key' => trim($modelKey),
            'provider' => strtolower(trim($provider)),
            'usage_scene' => $usageScene,
            'selection_mode' => $selectionMode,
            'requested_model_key' => trim($requestedModelKey),
            'config_id' => max(0, $configId),
        ];
    }
}

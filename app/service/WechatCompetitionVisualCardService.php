<?php
declare(strict_types=1);

namespace app\service;

/**
 * Builds and renders the table-like PNG used by the WeCom competition report.
 *
 * It reads one persisted competition bundle only. It never collects OTA data,
 * recalculates metrics, or writes back to OTA platforms.
 */
final class WechatCompetitionVisualCardService
{
    private const MAX_IMAGE_BYTES = 2 * 1024 * 1024;

    /** @var array<string, string> */
    private const GROUP_LABELS = [
        'direct' => '直接竞品',
        'attack_benchmark' => '进攻标杆',
        'traffic_benchmark' => '流量标杆',
        'conversion_benchmark' => '转化标杆',
    ];

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    public function buildModel(array $report, string $hotelName, string $edition): array
    {
        $edition = (new WechatCompetitionReportRendererService())->normalizeEdition($edition);
        $bundle = is_array($report['competition_circle_bundle'] ?? null)
            ? $report['competition_circle_bundle']
            : [];
        if ($bundle === []) {
            throw new \InvalidArgumentException('competition_circle_bundle_missing');
        }

        $quality = is_array($bundle['quality'] ?? null) ? $bundle['quality'] : [];
        $qualityStatus = strtolower(trim((string)($quality['status'] ?? 'blocked')));
        $statusOnly = $qualityStatus !== 'available'
            || ($quality['decision_eligible'] ?? false) !== true;

        return [
            'schema' => 'suxi.wecom.competition.visual-card.v1',
            'hotel_name' => $this->text($hotelName, 80),
            'report_date' => $this->date((string)($report['report_date'] ?? '')),
            'edition' => $edition,
            'edition_label' => $edition === WechatCompetitionReportRendererService::EDITION_FLAGSHIP
                ? '旗舰版'
                : '简版',
            'quality_status' => $qualityStatus,
            'quality_label' => $this->qualityLabel($qualityStatus),
            'status_only' => $statusOnly,
            'platforms' => $this->platformRows($bundle, $statusOnly),
            'competitor_groups' => $this->competitorGroups($bundle),
            'actions' => $this->actions($bundle, $statusOnly),
            'gaps' => $this->gaps($quality),
            'source_fingerprint' => $this->text((string)($bundle['source_fingerprint'] ?? ''), 80),
            'bundle_id' => $this->text((string)($bundle['bundle_id'] ?? ''), 120),
            'scope_note' => '仅限携程/美团OTA渠道，不代表全酒店经营事实。',
            'automation_note' => '不自动改价、库存或投放；经营动作仍需人工批准。',
        ];
    }

    /**
     * @param array<string, mixed> $model
     * @return array{
     *   payload: array{msgtype:string,image:array{base64:string,md5:string}},
     *   image_bytes:int,
     *   image_md5:string
     * }
     */
    public function renderImagePayload(array $model): array
    {
        if (($model['schema'] ?? '') !== 'suxi.wecom.competition.visual-card.v1') {
            throw new \InvalidArgumentException('competition_visual_card_schema_invalid');
        }

        $root = dirname(__DIR__, 2);
        $token = bin2hex(random_bytes(10));
        $temporaryDirectory = rtrim(sys_get_temp_dir(), "\\/");
        $modelPath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'suxi-competition-' . $token . '.json';
        $imagePath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'suxi-competition-' . $token . '.png';

        try {
            $json = json_encode(
                $model,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            if (file_put_contents($modelPath, $json, LOCK_EX) === false) {
                throw new \RuntimeException('competition_visual_model_write_failed');
            }

            $node = $this->resolveNodeExecutable();
            $command = [
                $node,
                $root . '/scripts/render_wechat_competition_visual_card.mjs',
                '--input=' . $modelPath,
                '--output=' . $imagePath,
            ];
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open(
                $command,
                $descriptors,
                $pipes,
                $root,
                null,
                ['bypass_shell' => true]
            );
            if (!is_resource($process)) {
                throw new \RuntimeException('competition_visual_renderer_unavailable');
            }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            if ($exitCode !== 0) {
                $detail = trim((string)($stderr !== '' ? $stderr : $stdout));
                throw new \RuntimeException(
                    'competition_visual_render_failed'
                    . ($detail !== '' ? ': ' . mb_substr($detail, 0, 240, 'UTF-8') : '')
                );
            }

            $bytes = is_file($imagePath) ? file_get_contents($imagePath) : false;
            if (!is_string($bytes) || $bytes === '') {
                throw new \RuntimeException('competition_visual_image_missing');
            }
            $size = strlen($bytes);
            if ($size > self::MAX_IMAGE_BYTES) {
                throw new \RuntimeException('competition_visual_image_exceeds_wecom_limit');
            }
            if (!str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
                throw new \RuntimeException('competition_visual_image_not_png');
            }
            $md5 = md5($bytes);

            return [
                'payload' => [
                    'msgtype' => 'image',
                    'image' => [
                        'base64' => base64_encode($bytes),
                        'md5' => $md5,
                    ],
                ],
                'image_bytes' => $size,
                'image_md5' => $md5,
            ];
        } finally {
            if (is_file($modelPath)) {
                @unlink($modelPath);
            }
            if (is_file($imagePath)) {
                @unlink($imagePath);
            }
        }
    }

    private function resolveNodeExecutable(): string
    {
        $configured = trim((string)getenv('SUXI_NODE'));
        if ($configured !== '') {
            return $configured;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $candidates = [];
            foreach (['ProgramFiles', 'ProgramFiles(x86)', 'LOCALAPPDATA'] as $environmentKey) {
                $base = trim((string)getenv($environmentKey));
                if ($base === '') {
                    continue;
                }
                $candidates[] = $environmentKey === 'LOCALAPPDATA'
                    ? $base . '\\Programs\\nodejs\\node.exe'
                    : $base . '\\nodejs\\node.exe';
            }
            $candidates[] = 'C:\\Program Files\\nodejs\\node.exe';
            $candidates[] = 'C:\\Program Files (x86)\\nodejs\\node.exe';
            foreach (array_values(array_unique($candidates)) as $candidate) {
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
            return 'node.exe';
        }

        return 'node';
    }

    /** @param array<string, mixed> $bundle @return array<int, array<string, mixed>> */
    private function platformRows(array $bundle, bool $statusOnly): array
    {
        $rows = [];
        foreach (['ctrip' => '携程', 'meituan' => '美团'] as $platform => $label) {
            $analysis = is_array($bundle['analysis'][$platform] ?? null)
                ? $bundle['analysis'][$platform]
                : [];
            $status = strtolower(trim((string)($analysis['status'] ?? 'blocked')));
            $rows[] = [
                'platform' => $platform,
                'label' => $label,
                'status' => $status,
                'status_label' => $status === 'available' ? '证据可用' : '证据未通过',
                'channel_role' => $statusOnly
                    ? '暂不判断'
                    : $this->text((string)($analysis['channel_role'] ?? '未形成'), 80),
                'first_conflict' => $statusOnly
                    ? '等待数据缺口补齐'
                    : $this->text((string)($analysis['first_conflict'] ?? '未形成'), 180),
                'blocked_reason' => $this->text((string)($analysis['blocked_reason'] ?? ''), 80),
            ];
        }
        return $rows;
    }

    /** @param array<string, mixed> $bundle @return array<int, array<string, mixed>> */
    private function competitorGroups(array $bundle): array
    {
        $groups = [];
        $seenSignatures = [];
        foreach (self::GROUP_LABELS as $groupKey => $groupLabel) {
            $items = [];
            foreach (['ctrip' => '携程', 'meituan' => '美团'] as $platform => $platformLabel) {
                $candidates = array_values(array_filter(
                    (array)($bundle['candidate_competitors'][$platform][$groupKey] ?? []),
                    'is_array'
                ));
                foreach (array_slice($candidates, 0, 3) as $candidate) {
                    $name = $this->text((string)($candidate['hotel_name'] ?? ''), 72);
                    if ($name === '') {
                        continue;
                    }
                    $items[] = [
                        'platform' => $platformLabel,
                        'hotel_name' => $name,
                        'adr' => is_numeric($candidate['adr'] ?? null)
                            ? round((float)$candidate['adr'], 2)
                            : null,
                        'room_nights' => is_numeric($candidate['room_nights'] ?? null)
                            ? (float)$candidate['room_nights']
                            : null,
                        'candidate_only' => ($candidate['candidate_only'] ?? true) === true,
                    ];
                }
            }
            $items = array_slice($items, 0, 3);
            $signature = implode('|', array_map(
                static fn(array $item): string => (string)$item['platform'] . ':' . (string)$item['hotel_name'],
                $items
            ));
            $overlapNote = '';
            if ($signature !== '' && array_key_exists($signature, $seenSignatures)) {
                $overlapNote = '与' . $seenSignatures[$signature]
                    . '候选重合，待流量/转化证据补齐后区分。';
                $items = [];
            } elseif ($signature !== '') {
                $seenSignatures[$signature] = $groupLabel;
            }
            $groups[] = [
                'key' => $groupKey,
                'label' => $groupLabel,
                'items' => $items,
                'overlap_note' => $overlapNote,
            ];
        }
        return $groups;
    }

    /** @param array<string, mixed> $bundle @return array<int, string> */
    private function actions(array $bundle, bool $statusOnly): array
    {
        if ($statusOnly) {
            return [];
        }
        $recommendations = is_array($bundle['recommendations'] ?? null)
            ? $bundle['recommendations']
            : [];
        $actions = [];
        foreach (array_values(array_filter((array)($recommendations['items'] ?? []), 'is_array')) as $item) {
            $action = $this->text(
                (string)($item['action'] ?? $item['title'] ?? $item['reason'] ?? ''),
                180
            );
            if ($action !== '') {
                $actions[] = $action;
            }
        }
        return array_slice($actions, 0, 3);
    }

    /** @param array<string, mixed> $quality @return array<int, string> */
    private function gaps(array $quality): array
    {
        $gaps = [];
        foreach (array_values(array_filter((array)($quality['data_gaps'] ?? []), 'is_array')) as $gap) {
            $message = $this->text((string)($gap['message'] ?? $gap['code'] ?? ''), 180);
            if ($message !== '') {
                $gaps[] = $message;
            }
        }
        return array_slice($gaps, 0, 6);
    }

    private function qualityLabel(string $status): string
    {
        return match ($status) {
            'available' => '可用于人工决策',
            'partial' => '部分可用，仅供核对',
            'synthetic' => '模拟数据，不可执行',
            default => '证据阻断，仅展示缺口',
        };
    }

    private function text(string $value, int $maxLength): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        return mb_substr($value, 0, max(1, $maxLength), 'UTF-8');
    }

    private function date(string $value): string
    {
        $value = trim($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '未返回';
    }
}

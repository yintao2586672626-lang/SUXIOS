<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;

final class CampaignCreativePayloadService
{
    public function videoNote(array $p): string
    {
        $files = $p['local_media_manifest'] ?? [];
        $note = '<p>制作单：浏览器' . ($files === [] ? '文字画面' : '本地酒店素材') . 'WebM，时长' . (int)$p['duration_seconds'] . '秒；' . ($files === [] ? '不包含自动取得的酒店实拍或音乐。' : '素材未上传，重新打开须选择匹配的原文件。') . '</p>';
        foreach ($files as $file) $note .= '<p>' . htmlspecialchars($file['name'] . ' · ' . $file['kind'] . ' · ' . $file['size_bytes'] . '字节 · SHA256 ' . $file['sha256'], ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>';
        return $note;
    }

    public static function capability(): array
    {
        return ['schema_version' => 'campaign_creative_capability.v1', 'ai_image' => ['status' => 'configuration_required',
            'reason' => '当前模型接入仅有文本路由，未建立受支持的图片生成接口、图片存储与回读合同。尚未调用模型或生成AI图片。',
            'next_step' => '管理员确认图片模型提供方、授权及图片接口合同后接入。'],
            'poster' => 'saved_version_svg_html', 'local_media_video' => 'browser_canvas_mediarecorder_webm',
            'boundary' => '本地照片、视频与音乐仅在当前浏览器处理；保存素材名称、类型、大小和SHA256，重新打开需重新选择匹配文件。未上传、未发布、未安装FFmpeg。'];
    }

    public function normalize(string $kind, array $p, ?array $old): array
    {
        $color = $this->text($p['brand_color'] ?? '#143a31', 7, true);
        if (!preg_match('/^#[a-fA-F0-9]{6}$/', $color)) throw new InvalidArgumentException('品牌色必须是六位十六进制颜色');
        $out = ['hotel_name' => $this->text($p['hotel_name'] ?? '', 120, true), 'title' => $this->text($p['title'] ?? '', 80, true),
            'copy' => $this->text($p['copy'] ?? '', 1200, true), 'brand_color' => strtolower($color),
            'material_notes' => $this->text($p['material_notes'] ?? '', 500, true)];
        foreach (['brand_review_status', 'material_review_status'] as $review) {
            $value = (string)($p[$review] ?? 'pending_review');
            if (!in_array($value, ['pending_review', 'reviewed', 'rejected'], true)) throw new InvalidArgumentException('素材或品牌审核状态不支持');
            $out[$review] = $value;
        }
        if ($kind === 'video_brief') {
            $duration = $p['duration_seconds'] ?? 8;
            if (!is_numeric($duration) || floor((float)$duration) !== (float)$duration || (float)$duration < 3 || (float)$duration > 30) throw new InvalidArgumentException('视频时长须为3至30秒的整数');
            $out['duration_seconds'] = (int)$duration;
            $out['local_media_manifest'] = $this->manifest($p['local_media_manifest'] ?? []);
            $out['render_method'] = $out['local_media_manifest'] === [] ? 'browser_canvas_webm' : 'browser_canvas_local_media_webm';
            $out['real_footage_status'] = $out['local_media_manifest'] === [] ? 'not_provided' : 'local_files_manifest_only';
        }
        if ($old !== null) {
            foreach (['hotel_name', 'title', 'copy', 'brand_color', 'material_notes', 'duration_seconds', 'local_media_manifest'] as $field) {
                if (($old[$field] ?? ($field === 'local_media_manifest' ? [] : null)) !== ($out[$field] ?? ($field === 'local_media_manifest' ? [] : null))) {
                    $out['brand_review_status'] = $out['material_review_status'] = 'pending_review'; break;
                }
            }
        }
        return $out;
    }

    private function manifest(mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 21) throw new InvalidArgumentException('本地素材清单须为最多20个画面和1个音乐文件');
        $out = []; $visual = $audio = $bytes = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('本地素材清单格式无效');
            $mime = (string)($row['mime_type'] ?? ''); $kind = (string)($row['kind'] ?? '');
            $allowed = ['image' => ['image/jpeg', 'image/png', 'image/webp'], 'video' => ['video/mp4', 'video/webm'], 'audio' => ['audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/mp4', 'audio/webm']];
            if (!isset($allowed[$kind]) || !in_array($mime, $allowed[$kind], true)) throw new InvalidArgumentException('素材须为JPEG/PNG/WebP、MP4/WebM或受支持的音乐文件');
            $size = $row['size_bytes'] ?? null;
            if (!is_int($size) || $size < 1 || $size > 200 * 1024 * 1024 || !preg_match('/^[a-f0-9]{64}$/', (string)($row['sha256'] ?? ''))) throw new InvalidArgumentException('本地素材大小或SHA256无效');
            $bytes += $size; $kind === 'audio' ? $audio++ : $visual++;
            $name = $this->text($row['name'] ?? '', 180, true);
            if (str_contains($name, '/') || str_contains($name, '\\')) throw new InvalidArgumentException('素材清单仅保存文件名称');
            $out[] = ['name' => $name, 'kind' => $kind, 'mime_type' => $mime, 'size_bytes' => $size, 'sha256' => $row['sha256']];
        }
        if ($visual > 20 || $audio > 1 || $bytes > 300 * 1024 * 1024 || ($audio > 0 && $visual === 0)) throw new InvalidArgumentException('最多20个画面和1个音乐，总计300MB；音乐须配合酒店画面');
        return $out;
    }

    private function text(mixed $value, int $limit, bool $required = false): string
    {
        if (!is_string($value) && !is_numeric($value)) throw new InvalidArgumentException('文本格式无效');
        $value = trim((string)$value);
        if (($required && $value === '') || mb_strlen($value) > $limit) throw new InvalidArgumentException('必填内容缺失或文本超长');
        if (preg_match('~(?:https?://|data:|javascript:|bearer\s|(?:password|token|cookie|secret)\s*[=:])~iu', $value)) throw new InvalidArgumentException('工作区只保存素材名称与来源说明，不保存媒体URL、凭据或令牌');
        return $value;
    }
}

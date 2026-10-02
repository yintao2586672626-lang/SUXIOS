<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use Throwable;

/** Local, append-only work records. Never creates platform orders or sends media. */
final class CampaignOperationsService
{
    public const TABLE = 'campaign_operation_versions';
    public const CONTRACT = 'campaign_operations.v1';
    public const KINDS = ['handover', 'marketing', 'poster', 'video_brief', 'report_reconciliation'];
    private const INTEGRITY_CONTRACT = 'campaign_operations.immutable_metadata.v2';

    public function hotelTenantId(int $hotelId): int
    {
        $hotel = Db::name('hotels')->where('id', $hotelId)->where('status', 1)->field('id,tenant_id')->find();
        if (!is_array($hotel) || (int)($hotel['tenant_id'] ?? 0) <= 0) throw new RuntimeException('酒店或租户边界未就绪', 404);
        return (int)$hotel['tenant_id'];
    }

    public function overview(int $tenantId, int $hotelId, string $date): array
    {
        $this->scope($tenantId, $hotelId);
        $date = $this->date($date);
        $this->ready();
        $heads = $this->query($tenantId, $hotelId)->field('MAX(id) AS latest_id')->group('kind,record_key')->select()->toArray();
        $ids = array_map(static fn(array $row): int => (int)$row['latest_id'], $heads);
        $total = $ids === [] ? 0 : $this->query($tenantId, $hotelId)->whereIn('id', $ids)->where('business_date', $date)->count();
        $rows = $ids === [] ? [] : $this->query($tenantId, $hotelId)->whereIn('id', $ids)->where('business_date', $date)->order('id', 'desc')->limit(100)->select()->toArray();
        $previous = $ids === [] ? null : $this->query($tenantId, $hotelId)->whereIn('id', $ids)->where('kind', 'handover')
            ->where('business_date', '<=', $date)->order('id', 'desc')->find();
        return [
            'schema_version' => self::CONTRACT, 'tenant_id' => $tenantId, 'hotel_id' => $hotelId,
            'business_date' => $date, 'records' => array_map(fn(array $row): array => $this->stored($row), $rows),
            'total' => $total, 'data_status' => $total > 100 ? 'partial' : ($total === 0 ? 'empty' : 'unverified'),
            'previous_handover' => is_array($previous) ? $this->stored($previous) : null,
            'existing_daily_report' => ['page' => 'operating-targets', 'api' => '/daily-reports', 'note' => '复用现有经营目标/每日事实录入、保存、回读；不重复83项。'],
            'media_capability' => ['poster' => 'saved_version_svg_html', 'video' => 'browser_canvas_webm',
                'video_note' => '浏览器根据保存制作单生成文字画面WebM；需要Canvas captureStream和MediaRecorder，未自动取得酒店实拍素材。'],
            'boundary' => '人工记录仅作参考；预约/有效线索和实际到店结果分别保存；补录不生成平台订单。',
        ];
    }

    public function read(int $tenantId, int $hotelId, int $id): array
    {
        $this->scope($tenantId, $hotelId);
        $this->ready();
        $row = $this->query($tenantId, $hotelId)->where('id', $id)->find();
        if (!is_array($row)) throw new RuntimeException('当前酒店未找到该记录版本', 404);
        return $this->stored($row);
    }

    /** Repeated identical imports reuse a saved version; edits require its exact current ID. */
    public function save(int $tenantId, int $hotelId, int $actorId, array $input): array
    {
        $this->scope($tenantId, $hotelId, $actorId);
        $this->ready();
        $kind = $this->text($input['kind'] ?? '', 30, true);
        if (!in_array($kind, self::KINDS, true)) throw new InvalidArgumentException('记录类型不支持');
        $date = $this->date((string)($input['business_date'] ?? ''));
        $source = $this->text($input['source_label'] ?? '', 240, true);
        $body = $input['payload'] ?? null;
        if (!is_array($body)) throw new InvalidArgumentException('记录内容必须是对象');
        $key = $this->text($input['record_key'] ?? '', 64);
        if ($kind === 'marketing') {
            $identity = $this->text($body['work_id'] ?? '', 120, true);
            $platform = $this->text($body['platform'] ?? '', 20, true);
            $key = hash('sha256', $platform . '|' . $identity . '|' . $date);
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $key)) throw new InvalidArgumentException('记录标识须为8至64位字母、数字或连字符');

        try {
            return Db::transaction(function () use ($tenantId, $hotelId, $actorId, $input, $kind, $key, $date, $source, $body): array {
                // All local versions serialize on the hotel row, including creation/import races.
                Db::name('hotels')->where('id', $hotelId)->where('tenant_id', $tenantId)->lock(true)->find();
                $latest = $this->latest($tenantId, $hotelId, $kind, $key);
                $payload = $this->normalize($tenantId, $hotelId, $kind, $body, $latest, $date);
                $canonical = ['kind' => $kind, 'business_date' => $date, 'source_label' => $source, 'payload' => $payload];
                $digest = $this->digest($canonical);
                $latestRecord = is_array($latest) ? $this->stored($latest) : null;
                if ($latestRecord !== null && hash_equals($this->digest([
                    'kind' => $latestRecord['kind'], 'business_date' => $latestRecord['business_date'],
                    'source_label' => $latestRecord['source_label'], 'payload' => $latestRecord['payload'],
                ]), $digest)) {
                    return $this->receipt($latestRecord, true);
                }
                if (is_array($latest) && (int)($input['expected_id'] ?? 0) !== (int)$latest['id']) {
                    throw new RuntimeException('记录已存在或已有新版本，请回读当前版本后编辑', 409);
                }
                if (!is_array($latest) && (int)($input['expected_id'] ?? 0) > 0) throw new RuntimeException('待编辑记录不在当前酒店范围', 404);
                if ($kind === 'handover' && is_array($latest)) {
                    $old = $this->stored($latest)['payload'];
                    if (($old['acknowledged_by'] ?? null) !== null) {
                        // A changed handover must be acknowledged again by the receiving shift.
                        $payload['acknowledged_by'] = null;
                        $payload['acknowledged_at'] = null;
                    }
                }
                $row = $this->sealedRow([
                    'tenant_id' => $tenantId, 'hotel_id' => $hotelId,
                    'source_hotel_id' => $latestRecord['source_hotel_id'] ?? $hotelId,
                    'kind' => $kind, 'record_key' => $key,
                    'version_no' => is_array($latest) ? (int)$latest['version_no'] + 1 : 1,
                    'parent_id' => is_array($latest) ? (int)$latest['id'] : null, 'business_date' => $date,
                    'source_method' => 'manual_entry', 'source_label' => $source, 'data_status' => 'unverified',
                    'created_by' => $actorId,
                    'created_at' => date('Y-m-d H:i:s'),
                ], $payload);
                $id = (int)$this->query($tenantId, $hotelId)->insertGetId($row);
                $saved = $this->read($tenantId, $hotelId, $id);
                if (!hash_equals($row['content_sha256'], $saved['content_sha256'])) throw new RuntimeException('保存版本精确回读校验失败', 500);
                return $this->receipt($saved, false);
            });
        } catch (Throwable $e) {
            if ($e instanceof InvalidArgumentException || $e instanceof RuntimeException) throw $e;
            throw new RuntimeException('记录保存失败，请刷新后重试；没有确认保存成功', 503, $e);
        }
    }

    public function handoverAction(int $tenantId, int $hotelId, int $actorId, int $id, array $input): array
    {
        $this->scope($tenantId, $hotelId, $actorId);
        $this->ready();
        return Db::transaction(function () use ($tenantId, $hotelId, $actorId, $id, $input): array {
            Db::name('hotels')->where('id', $hotelId)->where('tenant_id', $tenantId)->lock(true)->find();
            $record = $this->read($tenantId, $hotelId, $id);
            if ($record['kind'] !== 'handover') throw new InvalidArgumentException('只支持交接记录操作');
            $latest = $this->latest($tenantId, $hotelId, 'handover', $record['record_key']);
            if ((int)($latest['id'] ?? 0) !== $id) throw new RuntimeException('交接已有新版本，请刷新后操作', 409);
            $body = $record['payload'];
            $action = (string)($input['action'] ?? '');
            if ($action === 'acknowledge') {
                if ($body['acknowledged_by'] !== null) return $this->receipt($record, true);
                $body['acknowledged_by'] = $actorId;
                $body['acknowledged_at'] = date('c');
            } elseif ($action === 'close_item') {
                if ($body['acknowledged_by'] === null) throw new RuntimeException('先由接班人确认接班，再关闭事项', 409);
                $itemId = $this->text($input['item_id'] ?? '', 64, true);
                $evidence = $this->text($input['closure_evidence'] ?? '', 1000, true);
                $found = false;
                foreach ($body['items'] as &$item) {
                    if ($item['item_id'] !== $itemId) continue;
                    $found = true;
                    if ($item['status'] === 'closed') return $this->receipt($record, true);
                    $item['status'] = 'closed'; $item['closure_evidence'] = $evidence;
                    $item['closed_by'] = $actorId; $item['closed_at'] = date('c');
                }
                unset($item);
                if (!$found) throw new RuntimeException('交接事项不存在', 404);
            } else throw new InvalidArgumentException('交接操作不支持');
            $row = $this->sealedRow([
                'tenant_id' => $tenantId, 'hotel_id' => $hotelId, 'source_hotel_id' => $record['source_hotel_id'], 'kind' => 'handover', 'record_key' => $record['record_key'],
                'version_no' => $record['version_no'] + 1, 'parent_id' => $id, 'business_date' => $record['business_date'],
                'source_method' => 'manual_entry', 'source_label' => $record['source_label'], 'data_status' => 'unverified',
                'created_by' => $actorId, 'created_at' => date('Y-m-d H:i:s'),
            ], $body);
            $newId = (int)Db::name(self::TABLE)->insertGetId($row);
            return $this->receipt($this->read($tenantId, $hotelId, $newId), false);
        });
    }

    public function artifact(int $tenantId, int $hotelId, int $id, string $format): array
    {
        $record = $this->read($tenantId, $hotelId, $id);
        if (!in_array($record['kind'], ['poster', 'video_brief'], true)) throw new InvalidArgumentException('该记录没有作品导出');
        $p = $record['payload'];
        $ink = $this->brandInk($p['brand_color']);
        $esc = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $provenance = '当前酒店' . $hotelId . ' · 来源酒店' . $record['source_hotel_id'] . ' · ' . $record['business_date'] . ' · 版本' . $record['version_no'] . ' / #' . $id . ' · 来源：' . $record['source_label'] . ' · 品牌审核：' . $p['brand_review_status'] . ' · 素材审核：' . $p['material_review_status'];
        if ($format === 'svg' && $record['kind'] === 'poster') {
            $lines = $this->wrappedLines($p['copy'], 26);
            $titles = $this->wrappedLines($p['title'], 16);
            $hotels = $this->wrappedLines($p['hotel_name'], 23);
            $titleStart = 90 + count($hotels) * 48;
            $headerHeight = $titleStart + count($titles) * 74;
            $footerY = $headerHeight + 100 + count($lines) * 54;
            $footerLines = $this->wrappedLines('素材：' . $p['material_notes'] . "\n" . $provenance, 38);
            $height = max(1440, $footerY + count($footerLines) * 34 + 100);
            $text = '';
            foreach ($lines as $index => $line) $text .= '<text x="72" y="' . ($headerHeight + 70 + $index * 54) . '" font-size="36" fill="#1c3028">' . $esc($line) . '</text>';
            $titleText = '';
            foreach ($titles as $index => $line) $titleText .= '<text x="72" y="' . ($titleStart + $index * 74) . '" fill="' . $ink . '" font-size="58">' . $esc($line) . '</text>';
            $hotelText = '';
            foreach ($hotels as $index => $line) $hotelText .= '<text x="72" y="' . (75 + $index * 48) . '" fill="' . $ink . '" font-size="36">' . $esc($line) . '</text>';
            $footerText = '';
            foreach ($footerLines as $index => $line) $footerText .= '<text x="72" y="' . ($footerY + $index * 34) . '" font-size="22" fill="#5b6d63">' . $esc($line) . '</text>';
            $content = '<svg xmlns="http://www.w3.org/2000/svg" width="1080" height="' . $height . '" viewBox="0 0 1080 ' . $height . '"><rect width="1080" height="' . $height . '" fill="#f4f6f4"/><rect width="1080" height="' . $headerHeight . '" fill="' . $esc($p['brand_color']) . '"/>' . $hotelText . $titleText . $text . $footerText . '</svg>';
            $mime = 'image/svg+xml';
        } elseif ($format === 'html') {
            $content = '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $esc($p['title']) . '</title><body style="font:18px/1.8 system-ui;color:#1c3028;background:#f4f6f4;margin:24px"><article style="max-width:900px;margin:auto;overflow-wrap:anywhere"><header style="background:' . $esc($p['brand_color']) . ';color:' . $ink . ';padding:32px"><p>' . $esc($p['hotel_name']) . '</p><h1>' . $esc($p['title']) . '</h1></header><p style="white-space:pre-wrap">' . $esc($p['copy']) . '</p><p>素材说明：' . $esc($p['material_notes']) . '</p><p>' . $esc($provenance) . '</p>' . ($record['kind'] === 'video_brief' ? '<p>制作单：浏览器文字画面WebM，时长' . $p['duration_seconds'] . '秒；不包含自动取得的酒店实拍或音乐。</p>' : '') . '</article></body></html>';
            $mime = 'text/html';
        } else throw new InvalidArgumentException('导出格式不支持');
        return ['id' => $id, 'tenant_id' => $tenantId, 'hotel_id' => $hotelId, 'source_hotel_id' => $record['source_hotel_id'], 'source_scope' => $record['source_scope'], 'business_date' => $record['business_date'], 'version_no' => $record['version_no'],
            'source_label' => $record['source_label'], 'filename' => $record['kind'] . '-' . $id . '-v' . $record['version_no'] . '.' . $format,
            'mime_type' => $mime, 'content' => $content, 'content_sha256' => hash('sha256', $content), 'data_status' => 'unverified'];
    }

    private function normalize(int $tenantId, int $hotelId, string $kind, array $p, ?array $latest, string $businessDate): array
    {
        if ($kind === 'handover') {
            $items = [];
            $previousId = (int)($p['previous_id'] ?? 0);
            if (is_array($latest)) {
                $prior = $this->stored($latest)['payload'];
                $items = $prior['items'];
                $previousId = (int)($prior['previous_id'] ?? 0);
            } elseif ($previousId > 0) {
                $previous = $this->read($tenantId, $hotelId, $previousId);
                if ($previous['business_date'] > $businessDate) throw new InvalidArgumentException('不能继承未来业务日的交班');
                $current = $this->latest($tenantId, $hotelId, 'handover', $previous['record_key']);
                if ($previous['kind'] !== 'handover') throw new InvalidArgumentException('上一交班引用类型错误');
                if ((int)($current['id'] ?? 0) !== $previousId) throw new RuntimeException('上一交班已有新版本，先回读再继承', 409);
                $items = array_values(array_filter($previous['payload']['items'], static fn(array $item): bool => $item['status'] !== 'closed'));
                foreach ($items as &$item) $item['inherited_from_id'] = $previousId;
                unset($item);
            }
            if (is_array($latest) && $previousId > 0) {
                $source = $this->read($tenantId, $hotelId, $previousId);
                if ($source['kind'] !== 'handover') throw new InvalidArgumentException('上一交班引用类型错误');
                if ($source['business_date'] > $businessDate) throw new InvalidArgumentException('不能继承未来业务日的交班');
            }
            $newItems = $p['new_items'] ?? [];
            if (!is_array($newItems) || count($newItems) > 50 || count($items) > 100) throw new InvalidArgumentException('交接事项超出单次上限');
            foreach ($newItems as $item) {
                if (!is_array($item)) throw new InvalidArgumentException('交接事项格式错误');
                $taskId = (int)($item['task_id'] ?? 0);
                if ($taskId > 0) {
                    $task = Db::name('operation_execution_tasks')->where('id', $taskId)->where('tenant_id', $tenantId)->where('hotel_id', $hotelId)->find();
                    if (!is_array($task)) throw new InvalidArgumentException('原任务不存在或不属于当前酒店');
                }
                $title = $this->text($item['title'] ?? '', 240, true);
                $owner = $this->text($item['owner'] ?? '', 120, true);
                $due = $this->text($item['due_at'] ?? '', 25, true);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/', $due)) throw new InvalidArgumentException('截止时间须为上海本地日期时间');
                $this->date(substr($due, 0, 10));
                if ((int)substr($due, 11, 2) > 23 || (int)substr($due, 14, 2) > 59 || (strlen($due) === 19 && (int)substr($due, 17, 2) > 59)) throw new InvalidArgumentException('截止时间无效');
                $itemId = hash('sha256', $taskId . '|' . $title . '|' . $owner . '|' . $due);
                if (in_array($itemId, array_column($items, 'item_id'), true)) continue;
                $items[] = ['item_id' => $itemId, 'title' => $title, 'task_id' => $taskId ?: null, 'owner' => $owner, 'due_at' => $due,
                    'status' => 'open', 'closure_evidence' => null, 'closed_by' => null, 'closed_at' => null, 'inherited_from_id' => null];
            }
            return ['shift_label' => $this->text($p['shift_label'] ?? '', 120, true), 'notes' => $this->text($p['notes'] ?? '', 1000),
                'previous_id' => $previousId ?: null, 'items' => $items, 'acknowledged_by' => is_array($latest) ? ($prior['acknowledged_by'] ?? null) : null,
                'acknowledged_at' => is_array($latest) ? ($prior['acknowledged_at'] ?? null) : null];
        }
        if ($kind === 'marketing') {
            $platform = $this->text($p['platform'] ?? '', 20, true);
            if (!in_array($platform, ['douyin', 'xiaohongshu', 'other'], true)) throw new InvalidArgumentException('营销来源平台不支持');
            $out = ['platform' => $platform, 'work_id' => $this->text($p['work_id'] ?? '', 120, true),
                'title' => $this->text($p['title'] ?? '', 240, true), 'attribution_notes' => $this->text($p['attribution_notes'] ?? '', 1000),
                'result_source_label' => $this->text($p['result_source_label'] ?? '', 240),
                'result_business_date' => empty($p['result_business_date']) ? null : $this->date((string)$p['result_business_date'])];
            foreach (['views', 'likes', 'reservations', 'effective_leads', 'actual_arrivals', 'actual_room_nights', 'actual_revenue'] as $metric) {
                $value = $p[$metric] ?? null;
                if ($value === '' || $value === null) $out[$metric] = null;
                elseif (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > 999999999999
                    || ($metric !== 'actual_revenue' && floor((float)$value) !== (float)$value)) throw new InvalidArgumentException('营销指标必须为有效非负数；未知请留空');
                else $out[$metric] = $metric === 'actual_revenue' ? round((float)$value, 2) : (int)$value;
            }
            if (($out['actual_arrivals'] !== null || $out['actual_room_nights'] !== null || $out['actual_revenue'] !== null)
                && ($out['result_source_label'] === '' || $out['result_business_date'] === null || $out['attribution_notes'] === '')) {
                throw new InvalidArgumentException('实际结果须保留结果日期、来源和归因核对说明；线索不自动视为到店收入');
            }
            return $out;
        }
        if (in_array($kind, ['poster', 'video_brief'], true)) {
            $color = $this->text($p['brand_color'] ?? '#143a31', 7, true);
            if (!preg_match('/^#[a-fA-F0-9]{6}$/', $color)) throw new InvalidArgumentException('品牌色必须是六位十六进制颜色');
            $out = ['hotel_name' => $this->text($p['hotel_name'] ?? '', 120, true), 'title' => $this->text($p['title'] ?? '', 80, true),
                'copy' => $this->text($p['copy'] ?? '', 1200, true), 'brand_color' => strtolower($color),
                'material_notes' => $this->text($p['material_notes'] ?? '', 500, true),
                'brand_review_status' => 'pending_review', 'material_review_status' => 'pending_review'];
            foreach (['brand_review_status', 'material_review_status'] as $review) {
                $value = (string)($p[$review] ?? 'pending_review');
                if (!in_array($value, ['pending_review', 'reviewed', 'rejected'], true)) throw new InvalidArgumentException('素材或品牌审核状态不支持');
                $out[$review] = $value;
            }
            if ($kind === 'video_brief') {
                $duration = $p['duration_seconds'] ?? 8;
                if (!is_numeric($duration) || floor((float)$duration) !== (float)$duration || (float)$duration < 3 || (float)$duration > 30) throw new InvalidArgumentException('视频时长须为3至30秒的整数');
                $out['duration_seconds'] = (int)$duration;
                $out['render_method'] = 'browser_canvas_webm';
                $out['real_footage_status'] = 'not_provided';
            }
            if (is_array($latest)) {
                $old = $this->stored($latest)['payload'];
                foreach (['hotel_name', 'title', 'copy', 'brand_color', 'material_notes', 'duration_seconds'] as $field) {
                    if (($old[$field] ?? null) !== ($out[$field] ?? null)) {
                        $out['brand_review_status'] = $out['material_review_status'] = 'pending_review';
                        break;
                    }
                }
            }
            return $out;
        }
        $reportId = (int)($p['daily_report_id'] ?? 0);
        if ($reportId <= 0) throw new InvalidArgumentException('请选择现有日报编号');
        $report = Db::name('daily_reports')->where('id', $reportId)->where('hotel_id', $hotelId)->where('tenant_id', $tenantId)->find();
        if (!is_array($report)) throw new InvalidArgumentException('日报不存在或不属于当前酒店');
        if ((string)$report['report_date'] !== $businessDate) throw new InvalidArgumentException('日报日期与当前业务日期不匹配');
        return ['daily_report_id' => $reportId, 'report_date' => $this->date((string)$report['report_date']),
            'notes' => $this->text($p['notes'] ?? '', 1200, true), 'record_type' => 'report_entry_or_reconciliation', 'creates_platform_order' => false];
    }

    private function scope(int $tenantId, int $hotelId, ?int $actorId = null): void
    {
        if ($tenantId <= 0 || $hotelId <= 0 || ($actorId !== null && $actorId <= 0)) throw new InvalidArgumentException('缺少酒店、租户或操作人');
        if ($this->hotelTenantId($hotelId) !== $tenantId) throw new RuntimeException('当前酒店与租户不匹配', 403);
    }

    private function ready(): void
    {
        try { Db::name(self::TABLE)->field('id')->limit(1)->select(); }
        catch (Throwable $e) { throw new RuntimeException('业务工作区数据表未就绪，需受控迁移后保存', 503, $e); }
    }

    private function query(int $tenantId, int $hotelId): \think\db\Query
    {
        return Db::name(self::TABLE)->where('tenant_id', $tenantId)->where('hotel_id', $hotelId);
    }

    private function latest(int $tenantId, int $hotelId, string $kind, string $key): ?array
    {
        return $this->query($tenantId, $hotelId)->where('kind', $kind)->where('record_key', $key)->order('version_no', 'desc')->find();
    }

    private function stored(array $row): array
    {
        $storedPayload = json_decode((string)$row['payload_json'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($storedPayload)) throw new RuntimeException('保存内容格式无效，未作为有效记录展示', 503);
        $protected = array_key_exists('integrity_contract', $storedPayload);
        if ($protected && (($storedPayload['integrity_contract'] ?? null) !== self::INTEGRITY_CONTRACT
            || !is_array($storedPayload['metadata'] ?? null) || !is_array($storedPayload['payload'] ?? null)
            || $this->canonicalContent($storedPayload['metadata']) !== $this->canonicalContent($this->immutableMetadata($row)))) {
            throw new RuntimeException('保存版本不可变元数据不匹配，需核对原记录', 503);
        }
        $payload = $protected ? $storedPayload['payload'] : $storedPayload;
        $content = ['kind' => $row['kind'], 'business_date' => $row['business_date'], 'source_label' => $row['source_label'], 'payload' => $storedPayload];
        $digest = $this->digest($content);
        // Existing local rows retain their original digest; never rewrite saved evidence during a read.
        $legacyDigest = hash('sha256', $this->json($content));
        if (!hash_equals((string)$row['content_sha256'], $digest) && ($protected || !hash_equals((string)$row['content_sha256'], $legacyDigest))) {
            throw new RuntimeException('保存内容摘要不匹配，需核对原记录', 503);
        }
        foreach (['id', 'tenant_id', 'hotel_id', 'version_no', 'created_by'] as $key) $row[$key] = (int)$row[$key];
        $row['source_hotel_id'] = (int)($row['source_hotel_id'] ?? 0) > 0 ? (int)$row['source_hotel_id'] : $row['hotel_id'];
        $row['source_scope'] = ['tenant_id' => $row['tenant_id'], 'hotel_id' => $row['source_hotel_id'], 'business_date' => $row['business_date']];
        $row['parent_id'] = $row['parent_id'] === null ? null : (int)$row['parent_id'];
        $row['payload'] = $payload; unset($row['payload_json']);
        $row['schema_version'] = self::CONTRACT;
        $row['integrity_status'] = $protected ? 'immutable_metadata_verified' : 'legacy_content_only';
        return $row;
    }

    private function sealedRow(array $row, array $payload): array
    {
        $envelope = ['integrity_contract' => self::INTEGRITY_CONTRACT, 'metadata' => $this->immutableMetadata($row), 'payload' => $payload];
        $row['payload_json'] = $this->json($envelope);
        $row['content_sha256'] = $this->digest(['kind' => $row['kind'], 'business_date' => $row['business_date'], 'source_label' => $row['source_label'], 'payload' => $envelope]);
        return $row;
    }

    private function immutableMetadata(array $row): array
    {
        return ['tenant_id' => (int)$row['tenant_id'], 'source_hotel_id' => (int)($row['source_hotel_id'] ?? 0),
            'record_key' => (string)$row['record_key'], 'version_no' => (int)$row['version_no'],
            'parent_id' => $row['parent_id'] === null ? null : (int)$row['parent_id'],
            'source_method' => (string)$row['source_method'], 'data_status' => (string)$row['data_status'],
            'created_by' => (int)$row['created_by'], 'created_at' => (string)$row['created_at']];
    }

    private function receipt(array $record, bool $reused): array
    {
        return ['request_status' => 'saved_and_readback_verified', 'reused' => $reused, 'record' => $record];
    }

    private function date(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException('业务日期无效');
        return $date;
    }

    private function text(mixed $value, int $max, bool $required = false): string
    {
        if (!is_string($value) && !is_numeric($value)) throw new InvalidArgumentException('文本格式无效');
        $value = trim((string)$value);
        if (($required && $value === '') || mb_strlen($value) > $max) throw new InvalidArgumentException('必填内容缺失或文本超长');
        if (preg_match('~(?:https?://|data:|javascript:|bearer\s|(?:password|token|cookie|secret)\s*[=:])~iu', $value)) throw new InvalidArgumentException('工作区只保存素材名称与来源说明，不保存媒体URL、凭据或令牌');
        return $value;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function digest(array $value): string { return hash('sha256', $this->json($this->canonicalContent($value))); }

    private function canonicalContent(array $value): array
    {
        // SQL JSON may reorder object members. List order remains part of the saved content.
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $child) {
            if (is_array($child)) $value[$key] = $this->canonicalContent($child);
        }
        return $value;
    }

    private function wrappedLines(string $text, int $width): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if ($line === '') $out[] = '';
            for ($i = 0, $length = mb_strlen($line); $i < $length; $i += $width) $out[] = mb_substr($line, $i, $width);
        }
        return $out;
    }

    private function brandInk(string $color): string
    {
        $rgb = array_map(static function (string $part): float {
            $s = hexdec($part) / 255;
            return $s <= 0.04045 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($color, 1), 2));
        return ($rgb[0] * 0.2126 + $rgb[1] * 0.7152 + $rgb[2] * 0.0722) > 0.179 ? '#1c3028' : '#ffffff';
    }
}

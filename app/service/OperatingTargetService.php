<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use think\facade\Db;

/**
 * Keeps explicitly-scoped operating targets separate from OTA channel facts.
 *
 * Numeric zero is valid only when it was explicitly supplied with a usable
 * fact-quality status. Unknown values remain null and produce a gap.
 */
final class OperatingTargetService
{
    private const DEFAULT_FACT_SCOPE = 'whole_hotel';
    private const FACT_SCOPES = ['whole_hotel', 'accommodation_room_fee'];
    private const SOURCE_TYPES = ['manual', 'daily_report', 'pms', 'import'];
    private const QUALITY_STATUSES = [
        'verified',
        'manual_confirmed',
        'unverified',
        'missing',
        'collection_failed',
        'identity_mismatch',
    ];
    private const USABLE_QUALITY_STATUSES = ['verified', 'manual_confirmed'];

    public function current(int $tenantId, int $hotelId, string $targetDate): array
    {
        $targetDate = $this->normalizeDate($targetDate);
        $row = $this->findRow($tenantId, $hotelId, $targetDate);
        if ($row === null) {
            return [
                'status' => 'missing',
                'record' => null,
                'report_preview' => $this->missingPreview($hotelId, $targetDate),
            ];
        }

        $record = $this->presentRecord($row);
        return [
            'status' => (string)$record['calculation']['status'],
            'record' => $record,
            'report_preview' => $this->reportPreview($record),
        ];
    }

    public function save(int $tenantId, int $hotelId, int $userId, array $input): array
    {
        if ($tenantId <= 0 || $hotelId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('operating_target_scope_invalid');
        }

        $normalized = $this->normalizeInput($input);
        $targetDate = $normalized['target_date'];

        return Db::transaction(function () use ($tenantId, $hotelId, $userId, $normalized, $targetDate): array {
            $existing = $this->findRow($tenantId, $hotelId, $targetDate, true);
            $payload = $this->storagePayload($normalized, $existing, $userId);
            $calculation = $this->calculate($payload);
            $payload['calculation_status'] = $calculation['status'];
            $payload['gap_codes_json'] = $this->encodeJson(array_column($calculation['gaps'], 'code'));
            $payload['calculation_json'] = $this->encodeJson($calculation);

            if ($existing === null) {
                $payload['tenant_id'] = $tenantId;
                $payload['hotel_id'] = $hotelId;
                $recordId = (int)Db::name('operating_target_daily_records')->insertGetId($payload);
                if ($recordId <= 0) {
                    throw new \RuntimeException('operating_target_create_failed');
                }
            } else {
                $recordId = (int)$existing['id'];
                Db::name('operating_target_daily_records')->where('id', $recordId)->update($payload);
            }

            $stored = Db::name('operating_target_daily_records')->where('id', $recordId)->find();
            if (!is_array($stored)) {
                throw new \RuntimeException('operating_target_readback_failed');
            }
            $revisionNo = (int)Db::name('operating_target_daily_snapshots')
                ->where('record_id', $recordId)
                ->max('revision_no') + 1;
            $record = $this->presentRecord($stored);
            $record['revision_no'] = $revisionNo;
            Db::name('operating_target_daily_snapshots')->insert([
                'record_id' => $recordId,
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'target_date' => $targetDate,
                'revision_no' => $revisionNo,
                'change_reason' => $normalized['change_reason'],
                'snapshot_json' => $this->encodeJson($record),
                'created_by' => $userId,
                'create_time' => date('Y-m-d H:i:s'),
            ]);

            return [
                'status' => $record['calculation']['status'],
                'record' => $record,
                'report_preview' => $this->reportPreview($record),
                'revision_no' => $revisionNo,
            ];
        });
    }

    public function history(int $tenantId, int $hotelId, int $limit = 60): array
    {
        $limit = min(180, max(1, $limit));
        $rows = Db::name('operating_target_daily_records')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->order('target_date', 'desc')
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        return [
            'list' => array_map(fn(array $row): array => $this->presentRecord($row), $rows),
            'limit' => $limit,
        ];
    }

    /**
     * Read immutable revisions for one exact tenant, hotel, and operating
     * date.  This deliberately never returns a revision as the current
     * record, so viewing an old version cannot overwrite the latest facts.
     */
    public function snapshotHistory(
        int $tenantId,
        int $hotelId,
        string $targetDate,
        int $limit = 20
    ): array {
        $targetDate = $this->normalizeDate($targetDate);
        $limit = min(60, max(1, $limit));
        $rows = Db::name('operating_target_daily_snapshots')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('target_date', $targetDate)
            ->order('revision_no', 'desc')
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        return [
            'target_date' => $targetDate,
            'limit' => $limit,
            'list' => array_map(
                fn(array $row): array => $this->presentSnapshot($row, $tenantId, $hotelId, $targetDate),
                $rows
            ),
        ];
    }

    /**
     * Reads a same-hotel, same-date whole-hotel daily report. It never marks
     * the result verified automatically: the user must explicitly confirm the
     * imported fact when saving the target record.
     */
    public function prefillFromDailyReport(int $tenantId, int $hotelId, string $targetDate): array
    {
        $targetDate = $this->normalizeDate($targetDate);
        if (!$this->tableExists('daily_reports')) {
            return $this->prefillGap('daily_report_table_missing', '当前环境未安装经营日报表，无法复用全酒店事实。');
        }

        if (!$this->tableHasField('daily_reports', 'tenant_id')) {
            return $this->prefillGap(
                'daily_report_tenant_scope_unverifiable',
                '当前经营日报表缺少租户归属，无法核验跨租户边界，已拒绝自动预填。'
            );
        }

        $query = Db::name('daily_reports')
            ->where('hotel_id', $hotelId)
            ->where('report_date', $targetDate)
            ->where('tenant_id', $tenantId);
        $row = $query->order('id', 'desc')->find();
        if (!is_array($row)) {
            return $this->prefillGap('daily_report_missing', '该门店、该日期没有可复用的全酒店经营日报。');
        }

        $reportData = $this->decodeJson($row['report_data'] ?? null);
        $actualRevenue = $this->firstNumeric($row, $reportData, ['revenue', 'day_revenue']);
        $soldRoomNights = $this->firstInteger($row, $reportData, ['total_rooms', 'day_total_rooms']);
        $sellableRoomNights = $this->firstInteger($row, $reportData, ['salable_rooms']);
        $gaps = [];
        if ($actualRevenue === null) {
            $gaps[] = ['code' => 'daily_report_actual_revenue_missing', 'message' => '日报未提供全酒店总营收，未预填实际完成额。'];
        }
        if ($soldRoomNights === null) {
            $gaps[] = ['code' => 'daily_report_sold_room_nights_missing', 'message' => '日报未提供总出租间夜，未预填销售进度。'];
        }
        if ($sellableRoomNights === null) {
            $gaps[] = ['code' => 'daily_report_sellable_room_nights_missing', 'message' => '日报未提供可售房夜，未预填所需均价。'];
        }

        return [
            'status' => $gaps === [] ? 'unverified' : 'partial',
            'prefill' => [
                'target_date' => $targetDate,
                'actual_revenue' => $actualRevenue,
                'sold_room_nights' => $soldRoomNights,
                'sellable_room_nights' => $sellableRoomNights,
                'fact_scope' => self::DEFAULT_FACT_SCOPE,
                'source_type' => 'daily_report',
                'source_reference' => 'daily_reports:' . (int)($row['id'] ?? 0),
                'quality_status' => 'unverified',
                'quality_reason' => '已从同门店同日期经营日报预填，保存前需要人工确认事实口径。',
            ],
            'gaps' => $gaps,
        ];
    }

    public function exists(int $tenantId, int $hotelId, string $targetDate): bool
    {
        return $this->findRow($tenantId, $hotelId, $this->normalizeDate($targetDate)) !== null;
    }

    private function storagePayload(array $input, ?array $existing, int $userId): array
    {
        $now = date('Y-m-d H:i:s');
        $createdBy = $existing === null ? $userId : (int)($existing['created_by'] ?? $userId);
        $payload = [
            'target_date' => $input['target_date'],
            'target_revenue' => $input['target_revenue'],
            'actual_revenue' => $input['actual_revenue'],
            'sold_room_nights' => $input['sold_room_nights'],
            'sellable_room_nights' => $input['sellable_room_nights'],
            'fact_scope' => $input['fact_scope'],
            'source_type' => $input['source_type'],
            'source_reference' => $input['source_reference'],
            'quality_status' => $input['quality_status'],
            'quality_reason' => $input['quality_reason'],
            'fact_captured_at' => $input['fact_captured_at'],
            'report_status' => 'draft',
            'created_by' => $createdBy,
            'updated_by' => $userId,
            'create_time' => $existing === null ? $now : (string)($existing['create_time'] ?? $now),
            'update_time' => $now,
        ];
        if ($this->tableHasField('operating_target_daily_records', 'target_occupancy_rate_percent')) {
            $payload['target_occupancy_rate_percent'] = $input['target_occupancy_rate_percent'];
        }
        if ($this->tableHasField('operating_target_daily_records', 'target_revpar')) {
            $payload['target_revpar'] = $input['target_revpar'];
        }
        return $payload;
    }

    private function normalizeInput(array $input): array
    {
        $targetDate = $this->normalizeDate((string)($input['target_date'] ?? ''));
        $sourceType = strtolower(trim((string)($input['source_type'] ?? 'manual')));
        if (!in_array($sourceType, self::SOURCE_TYPES, true)) {
            throw new \InvalidArgumentException('operating_target_source_invalid');
        }

        $qualityStatus = strtolower(trim((string)($input['quality_status'] ?? '')));
        if ($qualityStatus === '') {
            $qualityStatus = $sourceType === 'manual' ? 'manual_confirmed' : 'unverified';
        }
        if (!in_array($qualityStatus, self::QUALITY_STATUSES, true)) {
            throw new \InvalidArgumentException('operating_target_quality_invalid');
        }

        $factScope = strtolower(trim((string)($input['fact_scope'] ?? self::DEFAULT_FACT_SCOPE)));
        if (!in_array($factScope, self::FACT_SCOPES, true)) {
            throw new \InvalidArgumentException('operating_target_scope_invalid');
        }

        return [
            'target_date' => $targetDate,
            'target_revenue' => $this->decimalOrNull($input['target_revenue'] ?? null, 'target_revenue'),
            'target_occupancy_rate_percent' => $this->percentOrNull(
                $input['target_occupancy_rate_percent'] ?? null,
                'target_occupancy_rate_percent'
            ),
            'target_revpar' => $this->decimalOrNull($input['target_revpar'] ?? null, 'target_revpar'),
            'actual_revenue' => $this->decimalOrNull($input['actual_revenue'] ?? null, 'actual_revenue'),
            'sold_room_nights' => $this->integerOrNull($input['sold_room_nights'] ?? null, 'sold_room_nights'),
            'sellable_room_nights' => $this->integerOrNull($input['sellable_room_nights'] ?? null, 'sellable_room_nights'),
            'fact_scope' => $factScope,
            'source_type' => $sourceType,
            'source_reference' => $this->textOrNull($input['source_reference'] ?? null, 255),
            'quality_status' => $qualityStatus,
            'quality_reason' => $this->textOrNull($input['quality_reason'] ?? null, 255),
            'fact_captured_at' => $this->dateTimeOrNull($input['fact_captured_at'] ?? null),
            'change_reason' => $this->textOrNull($input['change_reason'] ?? null, 500),
        ];
    }

    private function presentRecord(array $row): array
    {
        $calculation = $this->calculate($row);
        $revisionNo = (int)Db::name('operating_target_daily_snapshots')
            ->where('record_id', (int)$row['id'])
            ->max('revision_no');
        return [
            'id' => (int)$row['id'],
            'tenant_id' => (int)$row['tenant_id'],
            'hotel_id' => (int)$row['hotel_id'],
            'target_date' => (string)$row['target_date'],
            'facts' => [
                'target_revenue' => $this->decimalOrNull($row['target_revenue'] ?? null, 'target_revenue'),
                'target_occupancy_rate_percent' => $this->percentOrNull(
                    $row['target_occupancy_rate_percent'] ?? null,
                    'target_occupancy_rate_percent'
                ),
                'target_revpar' => $this->decimalOrNull($row['target_revpar'] ?? null, 'target_revpar'),
                'actual_revenue' => $this->decimalOrNull($row['actual_revenue'] ?? null, 'actual_revenue'),
                'sold_room_nights' => $this->integerOrNull($row['sold_room_nights'] ?? null, 'sold_room_nights'),
                'sellable_room_nights' => $this->integerOrNull($row['sellable_room_nights'] ?? null, 'sellable_room_nights'),
                'fact_scope' => in_array(
                    strtolower(trim((string)($row['fact_scope'] ?? ''))),
                    self::FACT_SCOPES,
                    true
                )
                    ? strtolower(trim((string)$row['fact_scope']))
                    : self::DEFAULT_FACT_SCOPE,
                'source_type' => (string)($row['source_type'] ?? 'manual'),
                'source_reference' => $this->textOrNull($row['source_reference'] ?? null, 255),
                'quality_status' => (string)($row['quality_status'] ?? 'unverified'),
                'quality_reason' => $this->textOrNull($row['quality_reason'] ?? null, 255),
                'fact_captured_at' => $row['fact_captured_at'] ?? null,
            ],
            'calculation' => $calculation,
            'revision_no' => $revisionNo,
            'report_status' => (string)($row['report_status'] ?? 'draft'),
            'created_at' => $row['create_time'] ?? null,
            'updated_at' => $row['update_time'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSnapshot(array $row, int $tenantId, int $hotelId, string $targetDate): array
    {
        $snapshot = $this->decodeJson($row['snapshot_json'] ?? null);
        $snapshotMatchesScope = $snapshot !== []
            && (int)($snapshot['tenant_id'] ?? 0) === $tenantId
            && (int)($snapshot['hotel_id'] ?? 0) === $hotelId
            && (string)($snapshot['target_date'] ?? '') === $targetDate;
        $facts = is_array($snapshot['facts'] ?? null) ? $snapshot['facts'] : null;
        $calculation = is_array($snapshot['calculation'] ?? null) ? $snapshot['calculation'] : null;
        $readable = $snapshotMatchesScope && $facts !== null && $calculation !== null;

        return [
            'id' => (int)($row['id'] ?? 0),
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'target_date' => $targetDate,
            'revision_no' => (int)($row['revision_no'] ?? 0),
            'change_reason' => $this->textOrNull($row['change_reason'] ?? null, 500),
            'created_by' => (int)($row['created_by'] ?? 0),
            'created_at' => $row['create_time'] ?? null,
            'readback_status' => $readable ? 'readback_verified' : 'snapshot_integrity_blocked',
            'record' => $readable ? [
                'target_date' => $targetDate,
                'facts' => $facts,
                'calculation' => $calculation,
                'revision_no' => (int)($row['revision_no'] ?? 0),
                'report_status' => (string)($snapshot['report_status'] ?? 'draft'),
                'created_at' => $snapshot['created_at'] ?? null,
                'updated_at' => $snapshot['updated_at'] ?? null,
            ] : null,
            'gaps' => $readable ? [] : [$this->gap(
                'operating_target_snapshot_integrity_mismatch',
                '历史版本与当前租户、门店或经营日期不一致，已拒绝展示为可用事实。'
            )],
        ];
    }

    private function calculate(array $input): array
    {
        $targetRevenue = $this->decimalOrNull($input['target_revenue'] ?? null, 'target_revenue');
        $targetOccupancyRate = $this->percentOrNull(
            $input['target_occupancy_rate_percent'] ?? null,
            'target_occupancy_rate_percent'
        );
        $targetRevpar = $this->decimalOrNull($input['target_revpar'] ?? null, 'target_revpar');
        $actualRevenue = $this->decimalOrNull($input['actual_revenue'] ?? null, 'actual_revenue');
        $soldRoomNights = $this->integerOrNull($input['sold_room_nights'] ?? null, 'sold_room_nights');
        $sellableRoomNights = $this->integerOrNull($input['sellable_room_nights'] ?? null, 'sellable_room_nights');
        $qualityStatus = strtolower(trim((string)($input['quality_status'] ?? 'unverified')));
        $factScope = strtolower(trim((string)($input['fact_scope'] ?? self::DEFAULT_FACT_SCOPE)));
        $actualRevenueLabel = $factScope === 'accommodation_room_fee'
            ? '住宿房费实际额'
            : '全酒店实际营收';
        $gaps = [];
        $reminders = [];

        if ($targetRevenue === null) {
            $gaps[] = $this->gap('target_revenue_missing', '未设置当日营收目标，不能计算完成率和剩余目标。');
        } elseif ($targetRevenue <= 0) {
            $gaps[] = $this->gap('target_revenue_not_positive', '当日营收目标必须大于 0，不能用 0% 代替完成率。');
        }
        if (!in_array($qualityStatus, self::USABLE_QUALITY_STATUSES, true)) {
            $gaps[] = $this->gap('fact_quality_' . ($qualityStatus ?: 'unverified'), '经营事实尚未达到可计算质量，结果仅保留原始输入，不生成正式完成结论。');
        }
        if ($actualRevenue === null) {
            $gaps[] = $this->gap('actual_revenue_missing', '未取得' . $actualRevenueLabel . '，不能计算完成率和剩余目标。');
        }
        if ($soldRoomNights === null) {
            $gaps[] = $this->gap('sold_room_nights_missing', '未取得已售间夜，不能计算销售进度。');
        }
        if ($sellableRoomNights === null) {
            $gaps[] = $this->gap('sellable_room_nights_missing', '未取得可售房夜，不能计算剩余可售房夜和所需均价。');
        } elseif ($sellableRoomNights <= 0) {
            $gaps[] = $this->gap('sellable_room_nights_not_positive', '可售房夜必须大于 0，所需均价无定义。');
        }
        if ($soldRoomNights !== null && $sellableRoomNights !== null && $soldRoomNights > $sellableRoomNights) {
            $gaps[] = $this->gap('input_inconsistent', '已售间夜大于可售房夜，数据口径冲突，已阻断派生计算。');
        }
        if ($targetRevpar !== null && $factScope !== 'accommodation_room_fee') {
            $gaps[] = $this->gap(
                'revpar_fact_scope_mismatch',
                '目标 RevPAR 只能与住宿房费口径的收入和可售房量比较，不能使用全酒店总营收替代。'
            );
        }

        $qualityUsable = in_array($qualityStatus, self::USABLE_QUALITY_STATUSES, true);
        $targetUsable = $targetRevenue !== null && $targetRevenue > 0;
        $factsUsable = $qualityUsable && $actualRevenue !== null;
        $roomsConsistent = $soldRoomNights !== null
            && $sellableRoomNights !== null
            && $sellableRoomNights > 0
            && $soldRoomNights <= $sellableRoomNights;
        $isBlocked = $soldRoomNights !== null
            && $sellableRoomNights !== null
            && $soldRoomNights > $sellableRoomNights;

        $completionRate = $targetUsable && $factsUsable
            ? round(($actualRevenue / $targetRevenue) * 100, 2)
            : null;
        $remainingRevenue = $targetUsable && $factsUsable
            ? round(max(0, $targetRevenue - $actualRevenue), 2)
            : null;
        $sellingProgress = $qualityUsable && $roomsConsistent
            ? round(($soldRoomNights / $sellableRoomNights) * 100, 2)
            : null;
        $actualOccupancyRate = $sellingProgress;
        $actualRevpar = $qualityUsable
            && $roomsConsistent
            && $actualRevenue !== null
            && $factScope === 'accommodation_room_fee'
            ? round($actualRevenue / $sellableRoomNights, 2)
            : null;
        $occupancyGapPoints = $targetOccupancyRate !== null && $actualOccupancyRate !== null
            ? round($actualOccupancyRate - $targetOccupancyRate, 2)
            : null;
        $revparGap = $targetRevpar !== null && $actualRevpar !== null
            ? round($actualRevpar - $targetRevpar, 2)
            : null;
        $remainingSellableRoomNights = $qualityUsable && $roomsConsistent
            ? $sellableRoomNights - $soldRoomNights
            : null;
        $requiredAverageRate = null;
        if (!$isBlocked && $remainingRevenue !== null && $remainingRevenue > 0) {
            if ($remainingSellableRoomNights !== null && $remainingSellableRoomNights > 0) {
                $requiredAverageRate = round($remainingRevenue / $remainingSellableRoomNights, 2);
            } elseif ($remainingSellableRoomNights === 0) {
                $gaps[] = $this->gap('remaining_sellable_room_nights_zero', '仍有未完成营收，但剩余可售房夜为 0，所需均价无定义。');
            }
        }

        if ($targetUsable && $factsUsable && $actualRevenue >= $targetRevenue) {
            $reminders[] = [
                'level' => 'success',
                'code' => 'target_achieved',
                'message' => $actualRevenue > $targetRevenue ? '当日营收目标已超额完成。' : '当日营收目标已完成。',
            ];
        } elseif ($remainingRevenue !== null) {
            $reminders[] = [
                'level' => 'warning',
                'code' => 'target_remaining',
                'message' => '当前仍有营收目标待完成，请结合剩余可售房夜核对经营节奏。',
            ];
        }
        if ($occupancyGapPoints !== null) {
            $reminders[] = [
                'level' => $occupancyGapPoints >= 0 ? 'success' : 'warning',
                'code' => $occupancyGapPoints >= 0 ? 'occupancy_target_achieved' : 'occupancy_target_below',
                'message' => $occupancyGapPoints >= 0
                    ? '实际入住率已达到目标入住率。'
                    : '实际入住率低于目标入住率，请结合剩余可售房量制定执行动作。',
            ];
        }
        if ($revparGap !== null) {
            $reminders[] = [
                'level' => $revparGap >= 0 ? 'success' : 'warning',
                'code' => $revparGap >= 0 ? 'revpar_target_achieved' : 'revpar_target_below',
                'message' => $revparGap >= 0
                    ? '实际 RevPAR 已达到目标 RevPAR。'
                    : '实际 RevPAR 低于目标 RevPAR，请复核价格、入住率和剩余经营空间。',
            ];
        }
        foreach ($gaps as $gap) {
            $reminders[] = [
                'level' => $gap['code'] === 'input_inconsistent' ? 'danger' : 'info',
                'code' => $gap['code'],
                'message' => $gap['message'],
            ];
        }

        $status = $isBlocked ? 'blocked' : ($gaps === [] ? 'ready' : 'partial');
        return [
            'status' => $status,
            'metrics' => [
                'completion_rate_percent' => $completionRate,
                'remaining_revenue' => $remainingRevenue,
                'selling_progress_percent' => $sellingProgress,
                'actual_occupancy_rate_percent' => $actualOccupancyRate,
                'occupancy_gap_points' => $occupancyGapPoints,
                'actual_revpar' => $actualRevpar,
                'revpar_gap' => $revparGap,
                'remaining_sellable_room_nights' => $remainingSellableRoomNights,
                'required_average_rate' => $requiredAverageRate,
            ],
            'gaps' => $gaps,
            'reminders' => $reminders,
        ];
    }

    private function reportPreview(array $record): array
    {
        return [
            'title' => '每日经营目标报告预览',
            'status' => $record['calculation']['status'],
            'hotel_id' => $record['hotel_id'],
            'target_date' => $record['target_date'],
            'facts' => $record['facts'],
            'metrics' => $record['calculation']['metrics'],
            'gaps' => $record['calculation']['gaps'],
            'reminders' => $record['calculation']['reminders'],
            'delivery_status' => 'preview_only',
            'delivery_note' => '当前仅生成预览，未向企业微信或任何外部群发送。',
        ];
    }

    private function missingPreview(int $hotelId, string $targetDate): array
    {
        return [
            'title' => '每日经营目标报告预览',
            'status' => 'missing',
            'hotel_id' => $hotelId,
            'target_date' => $targetDate,
            'facts' => null,
            'metrics' => null,
            'gaps' => [$this->gap('operating_target_record_missing', '尚未保存该日期的经营目标和经营事实。')],
            'reminders' => [[
                'level' => 'info',
                'code' => 'operating_target_record_missing',
                'message' => '请先录入或复用同日期全酒店经营事实，再保存经营目标。',
            ]],
            'delivery_status' => 'preview_unavailable',
            'delivery_note' => '没有可回读记录，未生成外部推送。',
        ];
    }

    private function findRow(int $tenantId, int $hotelId, string $targetDate, bool $lock = false): ?array
    {
        $query = Db::name('operating_target_daily_records')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('target_date', $targetDate);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        return is_array($row) ? $row : null;
    }

    private function prefillGap(string $code, string $message): array
    {
        return [
            'status' => 'missing',
            'prefill' => null,
            'gaps' => [$this->gap($code, $message)],
        ];
    }

    private function gap(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('operating_target_date_invalid');
        }
        return $value;
    }

    private function dateTimeOrNull(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new \InvalidArgumentException('operating_target_fact_captured_at_invalid');
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function decimalOrNull(mixed $value, string $field): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value) || !is_numeric($value)) {
            throw new \InvalidArgumentException('operating_target_' . $field . '_invalid');
        }
        $number = (float)$value;
        if (!is_finite($number) || $number < 0) {
            throw new \InvalidArgumentException('operating_target_' . $field . '_invalid');
        }
        return round($number, 2);
    }

    private function percentOrNull(mixed $value, string $field): ?float
    {
        $number = $this->decimalOrNull($value, $field);
        if ($number === null) {
            return null;
        }
        if ($number > 100) {
            throw new \InvalidArgumentException('operating_target_' . $field . '_invalid');
        }
        return $number;
    }

    private function integerOrNull(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value) || !is_numeric($value)) {
            throw new \InvalidArgumentException('operating_target_' . $field . '_invalid');
        }
        $number = (float)$value;
        if (!is_finite($number) || $number < 0 || floor($number) !== $number) {
            throw new \InvalidArgumentException('operating_target_' . $field . '_invalid');
        }
        return (int)$number;
    }

    private function textOrNull(mixed $value, int $limit): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return null;
        }
        return mb_substr($value, 0, $limit);
    }

    private function tableExists(string $table): bool
    {
        try {
            return Db::getTableInfo($table, 'fields') !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    private function tableHasField(string $table, string $field): bool
    {
        try {
            return in_array($field, Db::getTableInfo($table, 'fields'), true);
        } catch (\Throwable) {
            return false;
        }
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function encodeJson(array $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function firstNumeric(array $row, array $reportData, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $reportData[$key] ?? $row[$key] ?? null;
            if ($value !== null && $value !== '' && is_numeric($value)) {
                return $this->decimalOrNull($value, $key);
            }
        }
        return null;
    }

    private function firstInteger(array $row, array $reportData, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $reportData[$key] ?? $row[$key] ?? null;
            if ($value !== null && $value !== '' && is_numeric($value)) {
                return $this->integerOrNull($value, $key);
            }
        }
        return null;
    }
}

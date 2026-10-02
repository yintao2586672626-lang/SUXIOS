<?php
declare(strict_types=1);

namespace app\service;

use app\model\User;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use RuntimeException;
use think\facade\Db;
use Throwable;
use ZipArchive;

/** Local preview followed by an explicit, atomic, tenant-scoped manual import. */
class InvestmentPaybackImportService
{
    private const MAX_BYTES = 10485760;
    private const MAX_ROWS = 500;
    private const MAX_COLUMNS = 32;
    private InvestmentPaybackService $ledger;
    private int $tenantId;
    private int $actorId;

    public function __construct(User $user, ?InvestmentPaybackService $ledger = null, private ?LocalImageOcrService $ocr = null)
    {
        // Reuse the ledger's existing login, capability and tenant checks.
        $this->ledger = $ledger ?? new InvestmentPaybackService($user);
        $this->tenantId = (int)($user->tenant_id ?? 0);
        $this->actorId = (int)($user->id ?? 0);
        if ($this->tenantId <= 0 || $this->actorId <= 0) {
            throw new RuntimeException('当前账号缺少登录或租户归属', 403);
        }
    }

    public function preview(array $input): array
    {
        if (($input['review_rows'] ?? false) === true) {
            return $this->reviewRows($input);
        }
        $name = self::fileName($input['file_name'] ?? null);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xlsx', 'xls', 'png', 'jpg', 'jpeg', 'webp'], true)) {
            throw new InvalidArgumentException('请选择 CSV、XLSX、XLS 或 PNG/JPG/WebP 图片');
        }
        $encoded = $input['file_base64'] ?? null;
        if (!is_string($encoded) || $encoded === '' || strlen($encoded) > (int)ceil(self::MAX_BYTES / 3) * 4 + 4) {
            throw new InvalidArgumentException('上传文件不能为空，且不得超过10MB');
        }
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException('文件编码无效或超过10MB');
        }
        $result = ['file_name' => $name, 'sha256' => hash('sha256', $bytes), 'source_method' => 'spreadsheet', 'sheets' => [], 'warnings' => []];
        $path = tempnam(sys_get_temp_dir(), 'suxi-payback-import-');
        if ($path === false) {
            throw new RuntimeException('导入临时文件创建失败');
        }
        try {
            if (file_put_contents($path, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('导入临时文件写入失败');
            }
            if (in_array($extension, ['png', 'jpg', 'jpeg', 'webp'], true)) {
                $size = @getimagesize($path);
                if ($size === false || !in_array($size['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp'], true)
                    || $size[0] <= 0 || $size[1] <= 0 || $size[0] * $size[1] > 40000000) {
                    throw new InvalidArgumentException('图片格式无效或像素超过4000万');
                }
                $ocr = ($this->ocr ?? new LocalImageOcrService())->recognize($path);
                $result['source_method'] = 'image_ocr';
                $result['raw_text'] = (string)($ocr['text'] ?? '');
                $result['ocr_source_method'] = (string)($ocr['source_method'] ?? 'local_windows_ocr');
                $result['language'] = (string)($ocr['language'] ?? '');
                $result['sheets'] = [['name' => '图片识别', 'rows' => self::imageRows($ocr, $result['warnings'])]];
                $result['warnings'][] = '图片识别结果未核验，请逐项核对金额、日期、项目和主体后确认导入。';
            } elseif ($extension === 'csv') {
                $result['sheets'] = [['name' => 'CSV', 'rows' => self::csvRows($bytes)]];
            } else {
                $result['sheets'] = self::workbookRows($path, $extension, $result['warnings']);
            }
            $hasRows = false;
            foreach ($result['sheets'] as $sheet) {
                $hasRows = $hasRows || $sheet['rows'] !== [];
                foreach ($sheet['rows'] as $row) {
                    foreach ($row as $cell) {
                        if (str_starts_with(ltrim($cell), '=')) {
                            $result['warnings'][] = '公式仅显示原文，未计算；导入金额须改成已核对的明确数值。';
                            break 3;
                        }
                    }
                }
            }
            if (!$hasRows) {
                throw new InvalidArgumentException('文件中没有可识别的表格或文字');
            }
            $result['warnings'] = array_values(array_unique($result['warnings']));
            $result['data_status'] = 'unverified';
            return $result;
        } finally {
            @unlink($path);
        }
    }

    /** Read-only review of edited rows. No temporary import or ledger row is persisted. */
    private function reviewRows(array $input, bool $currentRead = false): array
    {
        $mode = $input['mode'] ?? null;
        if (!in_array($mode, ['projects', 'entries'], true)) {
            throw new InvalidArgumentException('请选择累计项目或资金明细导入');
        }
        $rows = $input['rows'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows) < 1 || count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException('每次预览须包含1至500行');
        }
        $projectId = $mode === 'entries' ? self::positiveInt($input['project_id'] ?? null, '目标项目编号') : null;
        $asOf = InvestmentPaybackCalculator::today();
        $project = null;
        $existing = [];
        $inaccessibleProjectKeys = [];
        if ($projectId !== null) {
            // detail reuses the ledger's tenant and hotel access checks.
            $detail = $this->ledger->detail($projectId, $asOf);
            $project = $detail['project'];
            if ($currentRead) {
                // Under MySQL RR, use current reads after the project lock rather
                // than the earlier authorization read's transaction snapshot.
                $project = Db::name('investment_payback_projects')->where('tenant_id', $this->tenantId)->where('id', $projectId)->lock(true)->find();
                unset($project['input_digest']);
                $detail['entries'] = Db::name('investment_payback_entries')->where('tenant_id', $this->tenantId)->where('project_id', $projectId)->order('id')->lock(true)->select()->toArray();
                foreach ($detail['entries'] as &$entry) {
                    $entry['date'] = $entry['business_date'];
                    unset($entry['business_date'], $entry['input_digest']);
                }
                unset($entry);
            }
            $existing = array_values(array_filter($detail['entries'], static fn(array $row): bool => !$row['is_planned'] && $row['voided_at'] === null));
            $snapshot = self::snapshot($project, $detail['entries']);
        } else {
            // Only inspect names present in this batch; inaccessible hotel projects
            // never appear as candidate details in the response.
            $names = array_values(array_unique(array_filter(array_map(static fn($row): string => is_array($row) ? trim((string)($row['project_name'] ?? '')) : '', $rows))));
            $candidates = $names === [] ? [] : Db::name('investment_payback_projects')->where('tenant_id', $this->tenantId)->whereIn('project_name', $names)->order('id')->lock($currentRead)->select()->toArray();
            foreach ($candidates as $candidate) {
                try {
                    $this->ledger->detail((int)$candidate['id']);
                    $existing[] = $candidate;
                } catch (RuntimeException $exception) {
                    if ($exception->getCode() !== 403) {
                        throw $exception;
                    }
                    // Full-tenant uniqueness still applies, but no private
                    // candidate ID, hotel, balance or source is returned.
                    $inaccessibleProjectKeys[$candidate['project_name'] . "\0" . $candidate['investor_name']] = true;
                }
            }
            $snapshot = [];
        }
        $reviewed = [];
        $tokenRows = [];
        $rowNumbers = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('第' . ($index + 1) . '行格式无效');
            }
            $number = self::positiveInt($row['row_number'] ?? ($index + 1), '来源行号');
            if (isset($rowNumbers[$number])) {
                throw new InvalidArgumentException('来源行号不能重复');
            }
            $rowNumbers[$number] = true;
            $selected = self::rowSelected($row);
            $result = ['row_number' => $number, 'selected' => $selected, 'errors' => [], 'exact_matches' => [], 'batch_duplicates' => [], 'similar_matches' => [], 'impact_excluded_reason' => null];
            try {
                if ($mode === 'projects') {
                    $date = self::requiredString($row['opening_as_of'] ?? null, '期初截至日');
                    $data = InvestmentPaybackService::normalizeProject([
                        'project_name' => self::requiredString($row['project_name'] ?? null, '项目名称'),
                        'investor_name' => self::requiredString($row['investor_name'] ?? null, '投资主体'),
                        'opening_invested' => self::requiredMoney($row['opening_invested'] ?? null, false),
                        'opening_recovered' => self::requiredMoney($row['opening_recovered'] ?? null, true),
                        'opening_as_of' => $date, 'forecast_as_of' => $date, 'opening_source' => '人工导入预览，待核对',
                    ]);
                    $key = mb_strtolower($data['project_name']) . "\0" . mb_strtolower($data['investor_name']);
                    if (isset($inaccessibleProjectKeys[$data['project_name'] . "\0" . $data['investor_name']])) {
                        $result['errors'][] = '当前租户已存在同名且同投资主体的项目，当前账号无权查看；请核对项目归属后处理';
                        $result['impact_excluded_reason'] = 'inaccessible_project_duplicate';
                    }
                } else {
                    if (!in_array($row['kind'] ?? null, ['investment', 'recovery'], true)) {
                        throw new InvalidArgumentException('类型须为实际投入或实际收回');
                    }
                    $data = InvestmentPaybackService::normalizeEntry([
                        'date' => self::requiredString($row['date'] ?? null, '资金日期'), 'precision' => self::requiredString($row['precision'] ?? null, '日期粒度'),
                        'kind' => $row['kind'], 'amount' => self::requiredMoney($row['amount'] ?? null, false),
                        'is_planned' => $row['is_planned'] ?? false,
                        'confirmed_zero' => ($row['confirmed_zero'] ?? false) === true, 'notes' => $row['note'] ?? '', 'source' => '人工导入预览，待核对',
                    ]);
                    if ($data['is_planned']) {
                        throw new InvalidArgumentException('计划记录不能作为实际资金导入');
                    }
                    [$start, $end] = InvestmentPaybackCalculator::period($data['business_date'], $data['precision']);
                    if ($project['archived_at'] !== null) {
                        $result['errors'][] = '归档项目不能导入资金明细';
                    }
                    if ($project['opening_as_of'] !== null && $start <= $project['opening_as_of']) {
                        $result['errors'][] = '此日期已包含在期初汇总内，请修正或排除';
                        $result['impact_excluded_reason'] = 'opening_overlap';
                    } elseif ($end > $asOf) {
                        $result['impact_excluded_reason'] = 'period_after_today';
                    }
                    if ($data['kind'] === 'investment' && $project['first_invested_on'] !== null && $end < $project['first_invested_on']) {
                        $result['errors'][] = '此投入早于项目首次投入日期';
                    }
                    $key = self::entryKey($data);
                }
                unset($data['source'], $data['opening_source']);
                if ($selected) {
                    $tokenRows[] = ['row_number' => $number, 'data' => $data];
                }
                $result['data'] = $data;
                $result['key'] = $key;
            } catch (InvalidArgumentException $exception) {
                $result['errors'][] = $exception->getMessage();
                $result['impact_excluded_reason'] = 'invalid';
                if ($selected) {
                    $tokenRows[] = ['row_number' => $number, 'invalid' => $row];
                }
            }
            $reviewed[] = $result;
        }
        $invested = 0;
        $recovered = 0;
        $openingInvested = 0;
        $openingRecovered = 0;
        $invalidCount = 0;
        $exactCount = 0;
        $similarCount = 0;
        $selectedCount = 0;
        foreach ($reviewed as &$row) {
            if (isset($row['data'])) {
                foreach ($existing as $candidate) {
                    if ($mode === 'projects') {
                        if ($candidate['project_name'] === $row['data']['project_name'] && $candidate['investor_name'] === $row['data']['investor_name']) {
                            $row['exact_matches'][] = self::candidate($candidate, '已存在同名且同投资主体的项目', $mode);
                        } elseif ($candidate['project_name'] === $row['data']['project_name']) {
                            $row['similar_matches'][] = self::candidate($candidate, '项目同名，投资主体不同', $mode);
                        }
                    } else {
                        $candidateData = ['business_date' => $candidate['date'], 'precision' => $candidate['precision'], 'kind' => $candidate['kind'], 'amount' => $candidate['amount'], 'notes' => trim((string)$candidate['notes'])];
                        if (self::entryKey($candidateData) === $row['key']) {
                            $row['exact_matches'][] = self::candidate($candidate, '日期、粒度、类型、金额和备注完全相同', $mode);
                        } elseif (($reason = self::similarReason($row['data'], $candidateData)) !== null) {
                            $row['similar_matches'][] = self::candidate($candidate, $reason, $mode);
                        }
                    }
                }
                foreach ($reviewed as $other) {
                    if ($other['row_number'] === $row['row_number'] || !$other['selected'] || !isset($other['data'])) {
                        continue;
                    }
                    if ($other['key'] === $row['key']) {
                        $row['batch_duplicates'][] = ['row_number' => $other['row_number'], 'reason' => '与本批选中行完全相同'];
                    } elseif ($mode === 'entries' && ($reason = self::similarReason($row['data'], $other['data'])) !== null) {
                        $row['similar_matches'][] = ['row_number' => $other['row_number'], 'date' => $other['data']['business_date'], 'precision' => $other['data']['precision'], 'kind' => $other['data']['kind'], 'amount' => $other['data']['amount'], 'note' => $other['data']['notes'], 'reason' => $reason . '（本批）'];
                    }
                }
            }
            $row['similar_match_count'] = count($row['similar_matches']);
            $row['similar_matches'] = array_slice($row['similar_matches'], 0, 20);
            if ($row['selected']) {
                ++$selectedCount;
                $invalidCount += $row['errors'] !== [] ? 1 : 0;
                $exactCount += $row['exact_matches'] !== [] || $row['batch_duplicates'] !== [] ? 1 : 0;
                $similarCount += $row['similar_match_count'] > 0 ? 1 : 0;
                if ($row['errors'] === [] && $row['exact_matches'] === [] && $row['batch_duplicates'] === []) {
                    if ($mode === 'projects') {
                        $openingInvested += InvestmentPaybackCalculator::fen($row['data']['opening_invested']);
                        $openingRecovered += InvestmentPaybackCalculator::fen($row['data']['opening_recovered'], true);
                    } elseif ($row['impact_excluded_reason'] === null) {
                        $amount = InvestmentPaybackCalculator::fen($row['data']['amount']);
                        if ($row['data']['kind'] === 'investment') {
                            $invested += $amount;
                        } else {
                            $recovered += $amount;
                        }
                    }
                }
            }
        }
        unset($row);
        foreach ($reviewed as &$row) {
            unset($row['data'], $row['key']);
        }
        unset($row);
        if ($projectId === null) {
            $selectedNames = array_column(array_column($tokenRows, 'data'), 'project_name');
            foreach ($existing as $candidate) {
                if (in_array($candidate['project_name'], $selectedNames, true)) {
                    $snapshot[] = self::snapshot($candidate, []);
                }
            }
        }
        $impact = ['actual_invested_delta' => InvestmentPaybackCalculator::yuan($invested), 'actual_net_recovered_delta' => InvestmentPaybackCalculator::yuan($recovered),
            'opening_invested_total' => InvestmentPaybackCalculator::yuan($openingInvested), 'opening_net_recovered_total' => InvestmentPaybackCalculator::yuan($openingRecovered)];
        return [
            // A month crossing its end date changes actual cash impact even
            // when the rows and project version have not changed.
            'review_token' => hash('sha256', json_encode([$this->tenantId, $mode, $projectId, $asOf, $snapshot, $tokenRows, $impact], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'mode' => $mode, 'project_id' => $projectId, 'project_version' => $project['version'] ?? null, 'as_of' => $asOf,
            'rows' => $reviewed, 'selected_count' => $selectedCount, 'invalid_count' => $invalidCount, 'exact_count' => $exactCount, 'similar_count' => $similarCount,
            'can_confirm' => $selectedCount > 0 && $selectedCount <= 200 && $invalidCount === 0 && $exactCount === 0,
            'impact' => $impact,
            'data_status' => 'manual_preview_unverified',
        ];
    }

    private static function candidate(array $row, string $reason, string $mode): array
    {
        if ($mode === 'projects') {
            return ['id' => $row['id'], 'project_name' => $row['project_name'], 'investor_name' => $row['investor_name'], 'opening_as_of' => $row['opening_as_of'], 'opening_invested' => $row['opening_invested'], 'opening_recovered' => $row['opening_recovered'], 'reason' => $reason];
        }
        return ['id' => $row['id'], 'date' => $row['date'], 'precision' => $row['precision'], 'kind' => $row['kind'], 'amount' => $row['amount'], 'note' => $row['notes'], 'reason' => $reason];
    }

    private static function snapshot(array $project, array $entries): array
    {
        $snapshot = ['project' => [(int)$project['id'], (int)$project['version'], $project['project_name'], $project['investor_name'], $project['opening_as_of'], $project['first_invested_on'], $project['archived_at']], 'entries' => []];
        foreach ($entries as $entry) {
            $snapshot['entries'][] = [(int)$entry['id'], (int)$entry['version'], $entry['date'], $entry['precision'], $entry['kind'], InvestmentPaybackCalculator::yuan(InvestmentPaybackCalculator::fen($entry['amount'])), (bool)$entry['is_planned'], $entry['voided_at'], trim((string)$entry['notes'])];
        }
        usort($snapshot['entries'], static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        return $snapshot;
    }

    private static function similarReason(array $row, array $candidate): ?string
    {
        if ($row['kind'] !== $candidate['kind']) {
            return null;
        }
        if ($row['amount'] === $candidate['amount']) {
            return '类型和金额相同，日期、粒度或备注不同；可能是另一笔真实资金';
        }
        [$start, $end] = InvestmentPaybackCalculator::period($row['business_date'], $row['precision']);
        [$otherStart, $otherEnd] = InvestmentPaybackCalculator::period($candidate['business_date'], $candidate['precision']);
        return $start <= $otherEnd && $otherStart <= $end ? '同类型资金日期区间重叠，金额不同' : null;
    }

    public function confirm(array $input): array
    {
        if (($input['confirmed'] ?? null) !== true) {
            throw new InvalidArgumentException('请核对预览并明确确认导入');
        }
        $requestId = $input['client_request_id'] ?? null;
        if (!is_string($requestId) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $requestId)) {
            throw new InvalidArgumentException('导入请求标识须为UUID');
        }
        $requestId = strtolower($requestId);
        $name = self::fileName($input['source_file_name'] ?? null);
        $sha = $input['source_sha256'] ?? null;
        if (!is_string($sha) || !preg_match('/^[0-9a-f]{64}$/Di', $sha)) {
            throw new InvalidArgumentException('来源文件SHA256无效');
        }
        $sha = strtolower($sha);
        $method = $input['source_method'] ?? null;
        if (!in_array($method, ['spreadsheet', 'image_ocr', 'pasted_table'], true)) {
            throw new InvalidArgumentException('导入来源方式无效');
        }
        $mode = $input['mode'] ?? null;
        if (!in_array($mode, ['projects', 'entries'], true)) {
            throw new InvalidArgumentException('请选择累计项目或资金明细导入');
        }
        $rows = $input['rows'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows) < 1 || count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException('每次导入须包含1至500行');
        }
        $projectId = $mode === 'entries' ? self::positiveInt($input['project_id'] ?? null, '目标项目编号') : null;
        $source = '人工确认文件导入；来源未独立核验；file=' . $name . '；sha256=' . $sha . '；method=' . $method;
        $normalized = [];
        $seen = [];
        $rowNumbers = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('第' . ($index + 1) . '行格式无效');
            }
            $rowNumber = self::positiveInt($row['row_number'] ?? ($index + 1), '来源行号');
            if (isset($rowNumbers[$rowNumber])) {
                throw new InvalidArgumentException('来源行号不能重复');
            }
            $rowNumbers[$rowNumber] = true;
            if (!self::rowSelected($row)) {
                continue;
            }
            try {
                if ($mode === 'projects') {
                    // An explicit as-of date is required; absent dates never become today's facts.
                    $openingDate = self::requiredString($row['opening_as_of'] ?? null, '期初截至日');
                    $data = InvestmentPaybackService::normalizeProject([
                        'project_name' => self::requiredString($row['project_name'] ?? null, '项目名称'),
                        'investor_name' => self::requiredString($row['investor_name'] ?? null, '投资主体'),
                        'opening_invested' => self::requiredMoney($row['opening_invested'] ?? null, false),
                        'opening_recovered' => self::requiredMoney($row['opening_recovered'] ?? null, true),
                        'opening_as_of' => $openingDate, 'forecast_as_of' => $openingDate,
                        'opening_source' => $source . '；row=' . $rowNumber,
                    ]);
                    $key = mb_strtolower($data['project_name']) . "\0" . mb_strtolower($data['investor_name']);
                } else {
                    $kind = $row['kind'] ?? null;
                    if (!in_array($kind, ['investment', 'recovery'], true)) {
                        throw new InvalidArgumentException('类型须为实际投入或实际收回');
                    }
                    $data = InvestmentPaybackService::normalizeEntry([
                        'date' => self::requiredString($row['date'] ?? null, '资金日期'),
                        'precision' => self::requiredString($row['precision'] ?? null, '日期粒度'),
                        'kind' => $kind, 'amount' => self::requiredMoney($row['amount'] ?? null, false),
                        'is_planned' => $row['is_planned'] ?? false,
                        'source' => $source . '；row=' . $rowNumber,
                        'confirmed_zero' => ($row['confirmed_zero'] ?? false) === true,
                        'notes' => $row['note'] ?? '',
                    ]);
                    if ($data['is_planned']) {
                        throw new InvalidArgumentException('计划记录不能作为实际资金导入');
                    }
                    $key = self::entryKey($data);
                }
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException('第' . $rowNumber . '行：' . $exception->getMessage(), 0, $exception);
            }
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('第' . $rowNumber . '行与第' . $seen[$key] . '行重复，请核对后保留一条');
            }
            $seen[$key] = $rowNumber;
            $normalized[] = ['row_number' => $rowNumber, 'data' => $data];
        }
        if ($normalized === []) {
            throw new InvalidArgumentException('请至少选择一条要导入的记录');
        }
        $digest = hash('sha256', json_encode([$mode, $projectId, $name, $sha, $method, $normalized], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        // system_config.config_key is VARCHAR(50). Hash the full identity rather
        // than truncate a tenant/user prefix or the caller's request UUID.
        $markerKey = 'payback_import_' . substr(hash('sha256', $this->tenantId . "\0" . $this->actorId . "\0" . $requestId), 0, 32);
        $legacyMarkerKey = 'payback_import_t' . $this->tenantId . '_u' . $this->actorId . '_' . str_replace('-', '', $requestId);
        try {
            return Db::transaction(function () use ($normalized, $mode, $projectId, $markerKey, $legacyMarkerKey, $digest, $requestId, $name, $sha, $method, $input): array {
                // Existing row locks serialize same-project entry batches and tenant project batches.
                if ($mode === 'entries') {
                    $this->ledger->detail($projectId);
                    Db::name('investment_payback_projects')->where('tenant_id', $this->tenantId)->where('id', $projectId)->lock(true)->find();
                } else {
                    Db::name('investment_payback_projects')->where('tenant_id', $this->tenantId)->order('id')->lock(true)->find();
                }
                $marker = $this->findMarker($markerKey, $legacyMarkerKey, true);
                if ($marker) {
                    return $this->replay($marker, $digest);
                }
                if (array_key_exists('review_token', $input)) {
                    $review = $this->reviewRows($input, true);
                    if (!is_string($input['review_token']) || !hash_equals($review['review_token'], $input['review_token'])) {
                        throw new RuntimeException('项目账目或导入内容已变化，请重新检查重复记录和金额影响', 409);
                    }
                    if (!$review['can_confirm']) {
                        throw new RuntimeException('选中行仍有无效或完全重复记录，请修正或明确排除后重新检查', 409);
                    }
                    if ($review['similar_count'] > 0 && ($input['similar_confirmed'] ?? false) !== true) {
                        throw new InvalidArgumentException('请核对相似候选，明确确认它们是不同的真实记录');
                    }
                }
                $result = ['imported_count' => count($normalized), 'project_ids' => [], 'entry_ids' => [], 'mode' => $mode,
                    'source_file_name' => $name, 'source_sha256' => $sha, 'source_method' => $method, 'replayed' => false];
                foreach ($normalized as $row) {
                    $data = $row['data'];
                    $data['client_request_id'] = self::rowUuid($requestId, [$mode, $projectId, $row]);
                    try {
                        if ($mode === 'projects') {
                            if (Db::name('investment_payback_projects')->where('tenant_id', $this->tenantId)
                                ->where('project_name', $data['project_name'])->where('investor_name', $data['investor_name'])->find()) {
                                throw new RuntimeException('已存在同名且同投资主体的项目，请核对，不能重复新建', 409);
                            }
                            $saved = $this->ledger->saveProject($data);
                            $result['project_ids'][] = $saved['project']['id'];
                        } else {
                            $duplicate = Db::name('investment_payback_entries')->where('tenant_id', $this->tenantId)->where('project_id', $projectId)
                                ->where('kind', $data['kind'])->where('business_date', $data['business_date'])->where('precision', $data['precision'])
                                // Current read sees a prior batch committed while
                                // we waited for the project lock, even under RR.
                                ->where('is_planned', 0)->whereNull('voided_at')->lock(true)->select()->toArray();
                            foreach ($duplicate as $existing) {
                                if (InvestmentPaybackCalculator::fen($existing['amount']) === InvestmentPaybackCalculator::fen($data['amount'])
                                    && trim((string)$existing['notes']) === $data['notes']) {
                                    throw new RuntimeException('已有相同日期、粒度、类型、金额和备注的有效明细，请核对，不能重复导入', 409);
                                }
                            }
                            $data['date'] = $data['business_date'];
                            $this->ledger->saveEntry($projectId, $data);
                            $entry = Db::name('investment_payback_entries')->where('tenant_id', $this->tenantId)->where('project_id', $projectId)
                                ->where('created_by', $this->actorId)->where('client_request_id', $data['client_request_id'])->find();
                            if (!$entry) {
                                throw new RuntimeException('导入明细保存回读失败');
                            }
                            $result['entry_ids'][] = (int)$entry['id'];
                        }
                    } catch (InvalidArgumentException|RuntimeException $exception) {
                        $message = '第' . $row['row_number'] . '行：' . $exception->getMessage();
                        throw $exception instanceof InvalidArgumentException
                            ? new InvalidArgumentException($message, 0, $exception)
                            : new RuntimeException($message, $exception->getCode(), $exception);
                    }
                }
                if ($projectId !== null) {
                    $result['project_ids'] = [$projectId];
                }
                $value = json_encode(['digest' => $digest, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                Db::name('system_config')->insert(['config_key' => $markerKey, 'config_value' => $value, 'description' => '人工投资回本导入幂等记录']);
                if (Db::name('system_config')->where('config_key', $markerKey)->value('config_value') !== $value) {
                    throw new RuntimeException('导入结果保存回读不一致');
                }
                return $result;
            });
        } catch (Throwable $exception) {
            // A concurrent retry can lose the unique marker insertion race. Its whole
            // transaction has rolled back; only the winner's exact digest can be replayed.
            $marker = $this->findMarker($markerKey, $legacyMarkerKey);
            if ($marker) {
                return $this->replay($marker, $digest);
            }
            throw $exception;
        }
    }

    private function findMarker(string $key, string $legacyKey, bool $lock = false): ?array
    {
        foreach ([$key, $legacyKey] as $candidate) {
            $marker = Db::name('system_config')->where('config_key', $candidate)->lock($lock)->find();
            if ($marker) {
                return $marker;
            }
        }
        return null;
    }

    private function replay(array $marker, string $digest): array
    {
        $saved = json_decode((string)$marker['config_value'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($saved) || !is_string($saved['digest'] ?? null) || !hash_equals($saved['digest'], $digest)) {
            throw new RuntimeException('此导入请求标识已用于不同内容，请重新预览确认', 409);
        }
        foreach ($saved['result']['project_ids'] as $projectId) {
            $this->ledger->detail((int)$projectId);
        }
        return array_merge($saved['result'], ['replayed' => true]);
    }

    private static function rowSelected(array $row): bool
    {
        if (!array_key_exists('selected', $row)) {
            return true;
        }
        if (!is_bool($row['selected'])) {
            throw new InvalidArgumentException('选中状态须为布尔值');
        }
        return $row['selected'];
    }

    private static function fileName($value): string
    {
        $value = self::requiredString($value, '来源文件名');
        if (mb_strlen($value) > 160 || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $value)) {
            throw new InvalidArgumentException('来源文件名无效或过长');
        }
        return $value;
    }

    private static function requiredString($value, string $label): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($label . '不能为空');
        }
        return trim($value);
    }

    private static function requiredMoney($value, bool $signed): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException('金额须为明确的元金额字符串，最多两位小数');
        }
        return InvestmentPaybackCalculator::yuan(InvestmentPaybackCalculator::fen($value, $signed));
    }

    private static function positiveInt($value, string $label): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new InvalidArgumentException($label . '须为正整数');
        }
        return $value;
    }

    private static function entryKey(array $row): string
    {
        return json_encode([$row['business_date'], $row['precision'], $row['kind'], $row['amount'], $row['notes']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function rowUuid(string $requestId, array $payload): string
    {
        $hex = hash('sha256', $requestId . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-5' . substr($hex, 13, 3) . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }

    private static function csvRows(string $bytes): array
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        } elseif (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) {
            $bytes = mb_convert_encoding(substr($bytes, 2), 'UTF-8', str_starts_with($bytes, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        } elseif (!mb_check_encoding($bytes, 'UTF-8')) {
            $bytes = mb_convert_encoding($bytes, 'UTF-8', 'GB18030');
        }
        if (str_contains($bytes, "\0")) {
            throw new InvalidArgumentException('CSV包含无效的二进制字符');
        }
        $first = strtok($bytes, "\r\n") ?: '';
        $delimiter = ',';
        $maximum = 0;
        foreach ([',', "\t", ';'] as $candidate) {
            $count = count(str_getcsv($first, $candidate, '"', ''));
            if ($count > $maximum) {
                $maximum = $count;
                $delimiter = $candidate;
            }
        }
        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            throw new RuntimeException('CSV读取失败');
        }
        try {
            fwrite($handle, $bytes);
            rewind($handle);
            $rows = [];
            while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $row = array_map(static fn($cell): string => trim((string)$cell), $row);
                self::appendRow($rows, $row);
            }
            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private static function appendRow(array &$rows, array $row): void
    {
        while ($row !== [] && end($row) === '') {
            array_pop($row);
        }
        if ($row === []) {
            return;
        }
        if (count($row) > self::MAX_COLUMNS || count($rows) >= self::MAX_ROWS) {
            throw new InvalidArgumentException('导入最多支持500行、32列，请拆分文件后重试');
        }
        foreach ($row as $cell) {
            if (mb_strlen($cell) > 10000) {
                throw new InvalidArgumentException('单元格文字超过10000字，请精简文件');
            }
        }
        $rows[] = array_values($row);
    }

    private static function workbookRows(string $path, string $extension, array &$warnings = []): array
    {
        if ($extension === 'xlsx') {
            self::validateXlsx($path);
        }
        $workbook = null;
        try {
            $reader = IOFactory::createReader($extension === 'xlsx' ? 'Xlsx' : 'Xls');
            if (!$reader->canRead($path)) {
                throw new InvalidArgumentException('文件内容与扩展名不匹配或已损坏');
            }
            $info = $reader->listWorksheetInfo($path);
            if (count($info) > 10) {
                throw new InvalidArgumentException('每个文件最多支持10个工作表');
            }
            $total = 0;
            foreach ($info as $sheet) {
                $total += (int)$sheet['totalRows'];
                if ((int)$sheet['totalColumns'] > self::MAX_COLUMNS || $total > self::MAX_ROWS) {
                    throw new InvalidArgumentException('导入最多支持500行、32列，请拆分或清除多余格式后重试');
                }
            }
            $reader->setReadDataOnly(false)->setReadEmptyCells(false)->setIncludeCharts(false)->setAllowExternalImages(false);
            $reader->setReadFilter(new class implements IReadFilter {
                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= 500 && Coordinate::columnIndexFromString($columnAddress) <= 32;
                }
            });
            $workbook = $reader->load($path);
            $sheets = [];
            $total = 0;
            foreach ($workbook->getWorksheetIterator() as $sheet) {
                $rows = [];
                $lastColumn = min(self::MAX_COLUMNS, Coordinate::columnIndexFromString($sheet->getHighestDataColumn()));
                $lastRow = min(self::MAX_ROWS, $sheet->getHighestDataRow());
                for ($rowNumber = 1; $rowNumber <= $lastRow; $rowNumber++) {
                    $row = [];
                    for ($column = 1; $column <= $lastColumn; $column++) {
                        $cell = $sheet->getCell([$column, $rowNumber]);
                        $value = $cell->getValue();
                        if ($value instanceof RichText) {
                            $value = $value->getPlainText();
                        }
                        if ($cell->getDataType() !== DataType::TYPE_FORMULA && is_numeric($value) && Date::isDateTime($cell)) {
                            $format = self::excelPreviewDateFormat($cell->getStyle()->getNumberFormat()->getFormatCode());
                            if ($format === null) {
                                $value = $cell->getFormattedValue();
                                $warnings[] = '表格存在不完整日期或仅时间的显示，已保留来源显示而不补全日期；请核对后填写有效日期。';
                            } else {
                                $value = Date::excelToDateTimeObject((float)$value)->format($format);
                            }
                        }
                        $row[] = trim((string)($value ?? ''));
                    }
                    self::appendRow($rows, $row);
                }
                $total += count($rows);
                if ($total > self::MAX_ROWS) {
                    throw new InvalidArgumentException('全部工作表合计最多支持500行');
                }
                if ($rows !== []) {
                    $sheets[] = ['name' => $sheet->getTitle(), 'rows' => $rows];
                }
            }
            return $sheets;
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('表格无法解析，请确认文件未加密、未损坏，或另存为CSV', 0, $exception);
        } finally {
            $workbook?->disconnectWorksheets();
        }
    }

    private static function excelPreviewDateFormat(string $format): ?string
    {
        // Excel serials may contain a day even when the source only displays a
        // month. Ignore literal text and locale/color directives before checking
        // date tokens, so quoted words such as "days" do not invent day precision.
        $tokens = strtolower(preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./u', '', $format) ?? $format);
        $tokens = str_replace(['am/pm', 'a/p'], '', $tokens);
        // A minute token beside hours/seconds does not establish a month.
        $dateTokens = preg_replace('/h+[^ymdhs]*m+|m+[^ymdhs]*s+/u', '', $tokens) ?? $tokens;
        if (!str_contains($dateTokens, 'y') || !str_contains($dateTokens, 'm')) {
            return null;
        }
        if (preg_match('/(?<!d)d{1,2}(?!d)/u', $dateTokens)) {
            return 'Y-m-d';
        }
        // ddd/dddd shows only a weekday, not the day number.
        return str_contains($dateTokens, 'd') ? null : 'Y-m';
    }

    private static function validateXlsx(string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('XLSX压缩包无效');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 256) {
                throw new InvalidArgumentException('XLSX压缩包文件数量超过限制');
            }
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat)) {
                    throw new InvalidArgumentException('XLSX压缩包目录无效');
                }
                $name = str_replace('\\', '/', $stat['name']);
                $size = (int)$stat['size'];
                $compressed = (int)$stat['comp_size'];
                $total += $size;
                if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) || in_array('..', explode('/', $name), true)
                    || ($stat['encryption_method'] ?? 0) !== 0 || $size > 15728640 || $total > 52428800
                    || ($size > 1048576 && $size > max(1, $compressed) * 200)) {
                    throw new InvalidArgumentException('XLSX压缩包路径、加密或展开大小超过安全限制');
                }
                if (str_ends_with(strtolower($name), '.xml') || str_ends_with(strtolower($name), '.rels')) {
                    $xml = $zip->getFromIndex($index);
                    if ($xml === false || preg_match('/<!\s*(DOCTYPE|ENTITY)\b/i', $xml)) {
                        throw new InvalidArgumentException('XLSX包含不支持的XML声明');
                    }
                }
            }
        } finally {
            $zip->close();
        }
    }

    /** Group OCR words by visual row, then split cells only at visible inter-cell gaps. */
    private static function imageRows(array $ocr, array &$warnings = []): array
    {
        $lines = $ocr['lines'] ?? [];
        if (!is_array($lines) || count($lines) > 3000) {
            throw new InvalidArgumentException('图片文字行数超限，请裁剪图片后重试');
        }
        $groups = [];
        foreach ($lines as $line) {
            if (!is_array($line) || trim((string)($line['text'] ?? '')) === '') {
                continue;
            }
            $height = max(1.0, (float)($line['height'] ?? 20));
            $center = (float)($line['y'] ?? count($groups) * 30) + $height / 2;
            $groupIndex = null;
            foreach ($groups as $index => $group) {
                if (abs($group['center'] - $center) <= max($height, $group['height']) * 0.45) {
                    $groupIndex = $index;
                    break;
                }
            }
            $words = $line['words'] ?? [];
            if (!is_array($words) || $words === []) {
                $words = [['text' => trim((string)$line['text']), 'x' => $line['x'] ?? 0, 'y' => $line['y'] ?? 0,
                    'width' => $line['width'] ?? mb_strlen((string)$line['text']) * $height, 'height' => $height]];
            }
            if ($groupIndex === null) {
                $groups[] = ['center' => $center, 'height' => $height, 'words' => $words];
            } else {
                $groups[$groupIndex]['words'] = array_merge($groups[$groupIndex]['words'], $words);
            }
        }
        usort($groups, static fn(array $left, array $right): int => $left['center'] <=> $right['center']);
        $visualRows = [];
        foreach ($groups as $group) {
            usort($group['words'], static fn(array $left, array $right): int => ($left['x'] ?? 0) <=> ($right['x'] ?? 0));
            $cells = [];
            $lastEnd = null;
            foreach ($group['words'] as $word) {
                $text = trim((string)($word['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $x = (float)($word['x'] ?? 0);
                if ($lastEnd === null || $x - $lastEnd > max(12, $group['height'] * 1.25)) {
                    $cells[] = ['text' => $text, 'x' => $x];
                } else {
                    $last = count($cells) - 1;
                    $separator = preg_match('/[A-Za-z]$/u', $cells[$last]['text']) && preg_match('/^[A-Za-z]/u', $text) ? ' ' : '';
                    $cells[$last]['text'] .= $separator . $text;
                }
                $lastEnd = $x + (float)($word['width'] ?? 0);
            }
            if ($cells !== []) {
                $visualRows[] = $cells;
            }
        }
        $rows = [];
        if ($visualRows !== []) {
            $reference = $visualRows[0];
            $bestScore = -1;
            foreach ($visualRows as $cells) {
                if (count($cells) > self::MAX_COLUMNS) {
                    throw new InvalidArgumentException('图片识别超过32列，请裁剪后重试');
                }
                $headers = 0;
                foreach ($cells as $cell) {
                    $compact = preg_replace('/\s+/u', '', $cell['text']);
                    if (preg_match('/项目|名称|主体|投资人|截至|日期|金额|类型|投入|收回|备注|期初/u', $compact)) {
                        $headers++;
                    }
                }
                $score = ($headers >= 2 ? 1000 + $headers * 10 : 0) + count($cells);
                if ($score > $bestScore) {
                    $reference = $cells;
                    $bestScore = $score;
                }
            }
            $anchors = array_column($reference, 'x');
            foreach ($visualRows as $cells) {
                $aligned = array_fill(0, count($anchors), '');
                if (count($cells) !== count($anchors)) {
                    $warnings[] = '图片部分行缺列或列位置不齐，已按表头位置保留空格；请核对每行列映射。';
                }
                foreach ($cells as $cell) {
                    $distances = array_map(static fn(float $anchor): float => abs($anchor - $cell['x']), $anchors);
                    $column = array_search(min($distances), $distances, true);
                    if ($aligned[$column] !== '') {
                        $warnings[] = '图片列位置存在歧义，多个文字块落在同一列；请检查并修正识别预览。';
                        $aligned[$column] .= ' ';
                    }
                    $aligned[$column] .= self::normalizeOcrCell($cell['text']);
                }
                // Fixed column count is intentional: a missing investor/date must
                // never shift a money cell left into another financial field.
                if (count($rows) >= self::MAX_ROWS) {
                    throw new InvalidArgumentException('图片识别超过500行，请裁剪后重试');
                }
                foreach ($aligned as $value) {
                    if (mb_strlen($value) > 10000) {
                        throw new InvalidArgumentException('图片单元格文字超过10000字');
                    }
                }
                $rows[] = $aligned;
            }
        }
        if ($rows === [] && trim((string)($ocr['text'] ?? '')) !== '') {
            foreach (preg_split('/\R/u', (string)$ocr['text']) ?: [] as $line) {
                self::appendRow($rows, array_map(self::normalizeOcrCell(...), preg_split('/\t|\s{2,}/u', trim($line)) ?: []));
            }
        }
        return $rows;
    }

    private static function normalizeOcrCell(string $value): string
    {
        $value = strtr(mb_convert_kana($value, 'n', 'UTF-8'), ['，' => ',', '．' => '.', '。' => '.', '／' => '/', '－' => '-']);
        $value = preg_replace('/(?<=\d)\s*·\s*(?=\d)/u', '.', $value) ?? $value;
        $value = preg_replace('/(?<=\d)\s*一\s*(?=\d)/u', '-', $value) ?? $value;
        if (preg_match('/^[\d\s,.+\-]+$/u', $value)) {
            $value = preg_replace('/\s+/u', '', $value) ?? $value;
        }
        return trim($value);
    }
}

<?php
declare(strict_types=1);
namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use RuntimeException;
use think\facade\Db;

/** JD06 uploads are parsed in the upload temporary file; only anonymous events reach persistence. */
final class GuestStayFileImportService
{
    public function preview(int $tenant, array $ids, int $hotel, int $actor, string $path, string $extension, array $input): array
    {
        $store = new GuestOperationRecordStore(); $tenant = $store->scope($tenant, $ids, $hotel, $actor);
        if (!is_file($path) || filesize($path) < 1 || filesize($path) > 5 * 1024 * 1024 || !in_array(strtolower($extension), ['xlsx', 'xls', 'csv'], true)) throw new InvalidArgumentException('请选择不超过5MB的 XLSX、XLS 或 CSV 文件');
        if (array_diff(array_keys($input), ['mapping', 'date_start', 'date_end', 'completed_values', 'confirmed_single_hotel', 'confirmed_hotel_id', 'source_reference', 'idempotency_key'])) throw new InvalidArgumentException('导入字段无效');
        $rows = $this->rows($path, strtolower($extension));
        if (count($rows) < 2) throw new InvalidArgumentException('文件没有表头和数据行');
        $headers = array_shift($rows); $columns = [];
        foreach ($headers as $index => $header) {
            $label = trim((string)$header);
            // Uploaded header cells are untrusted too; never echo contact/identity values.
            if (!in_array($label, ['订单号', '入住单号', '事件键', '客人标识', '客人姓名', '姓名', '手机号', '手机', '证件号', '客人ID', '客人编号', '离店日期', '退房日期', '完成日期', '入住状态', '状态', '酒店', '酒店名称', '酒店ID', 'event_key', 'guest_id', 'guest_name', 'phone', 'stay_date', 'status', 'hotel_id'], true)) $label = '列' . ($index + 1) . '（未识别表头）';
            $columns[] = ['index' => $index, 'label' => $label ?: '列' . ($index + 1)];
        }
        $base = ['contract_version' => GuestOperationsService::VERSION, 'tenant_id' => $tenant, 'hotel_id' => $hotel, 'columns' => $columns, 'source_method' => 'jd06_file_import', 'source_quality' => 'unverified', 'evidence_boundary' => '人工上传和范围声明，仅证明所导入匿名完成入住事件，不证明PMS全量或全酒店复购', 'file_digest' => hash_file('sha256', $path)];
        if (!isset($input['mapping'])) return $base + ['preview_status' => 'mapping_required', 'events' => []];
        $mapping = $input['mapping']; $required = ['event', 'identity', 'stay_date', 'status'];
        if (!is_array($mapping) || array_diff(array_keys($mapping), [...$required, 'hotel']) || array_diff($required, array_keys($mapping))) throw new InvalidArgumentException('请明确映射入住事件、客人标识、完成入住日期和状态列');
        foreach ($mapping as $key => $index) {
            if ($key === 'hotel' && $index === null) continue;
            if (!is_int($index) || $index < 0 || $index >= count($headers)) throw new InvalidArgumentException('列映射超出文件表头');
        }
        if (count(array_unique(array_filter($mapping, static fn($value): bool => $value !== null))) !== count(array_filter($mapping, static fn($value): bool => $value !== null))) throw new InvalidArgumentException('不同业务字段必须映射到不同列');
        $start = $this->date($input['date_start'] ?? ''); $end = $this->date($input['date_end'] ?? '');
        if ($start > $end) throw new InvalidArgumentException('导入时期起止日期颠倒');
        $completed = $input['completed_values'] ?? [];
        if (!is_array($completed) || !array_is_list($completed) || count($completed) < 1 || count($completed) > 10 || array_filter($completed, static fn($v): bool => !is_string($v) || trim($v) === '' || mb_strlen($v) > 30 || preg_match('/\d{6,}|@/', $v))) throw new InvalidArgumentException('请明确填写不含身份信息的完成入住状态值');
        $hotelColumn = $mapping['hotel'] ?? null;
        if ($hotelColumn === null && (($input['confirmed_single_hotel'] ?? false) !== true || ($input['confirmed_hotel_id'] ?? null) !== $hotel)) throw new InvalidArgumentException('缺酒店列时必须人工确认文件仅包含当前酒店');
        $hotelRow = Db::name('hotels')->where('id', $hotel)->find(); $secret = $this->key($tenant, $hotel, $actor, $store);
        $events = []; $errors = []; $duplicates = 0; $skipped = 0; $today = substr($store->now(), 0, 10);
        foreach ($rows as $index => $row) {
            if (array_filter($row, static fn($v): bool => $v !== null && trim((string)$v) !== '') === []) continue;
            $line = $index + 2;
            try {
                foreach ($mapping as $column) if ($column !== null && str_starts_with(trim((string)($row[$column] ?? '')), '=')) throw new InvalidArgumentException('业务映射列不能包含公式，请导出静态值');
                if ($hotelColumn !== null && !in_array(trim((string)($row[$hotelColumn] ?? '')), [(string)$hotel, trim((string)$hotelRow['name'])], true)) throw new InvalidArgumentException('酒店列与当前酒店不一致');
                if (!in_array(trim((string)($row[$mapping['status']] ?? '')), $completed, true)) { $skipped++; continue; }
                $identity = trim((string)($row[$mapping['identity']] ?? '')); $event = trim((string)($row[$mapping['event']] ?? ''));
                if ($identity === '' || $event === '' || mb_strlen($identity) > 256 || mb_strlen($event) > 256) throw new InvalidArgumentException('完成入住的事件键或客人标识缺失/过长');
                $date = $this->date($row[$mapping['stay_date']] ?? '');
                if ($date > $today || $date < $start || $date > $end) throw new InvalidArgumentException('完成入住日期必须已发生且在当前导入时期内');
                $hash = hash_hmac('sha256', $tenant . ':' . $hotel . ':' . $identity, $secret);
                $key = 'jd06-' . hash_hmac('sha256', $tenant . ':' . $hotel . ':' . $event, $secret);
                $normalized = ['event_key' => $key, 'guest_hash' => $hash, 'stay_date' => $date, 'status' => 'completed'];
                if (isset($events[$key])) {
                    if ($events[$key] !== $normalized) throw new InvalidArgumentException('同一入住事件编号对应不同客人或日期，请核对事件列');
                    $duplicates++; continue;
                }
                $existing = $store->latest($tenant, $hotel, 'stay_event', 'pms:' . $key);
                if ($existing && ($existing['document']['guest_hash'] !== $hash || $existing['document']['stay_date'] !== $date || $existing['document']['status'] !== 'completed')) throw new InvalidArgumentException('已有事件身份、日期或状态发生变化，请在匿名事件更正入口按原版本更正');
                $events[$key] = $normalized;
            } catch (InvalidArgumentException $error) { $errors[] = ['row' => $line, 'message' => $error->getMessage()]; }
        }
        return $base + ['preview_status' => $errors ? 'blocked' : ($events ? 'ready' : 'empty'), 'date_start' => $start, 'date_end' => $end, 'platform' => 'pms', 'scope_evidence' => $hotelColumn === null ? 'staff_declared_single_hotel' : 'matched_hotel_column', 'events' => array_values($events), 'valid_count' => count($events), 'duplicate_rows' => $duplicates, 'skipped_non_completed' => $skipped, 'errors' => $errors];
    }
    public function import(int $tenant, array $ids, int $hotel, int $actor, string $path, string $extension, array $input): array
    {
        $preview = $this->preview($tenant, $ids, $hotel, $actor, $path, $extension, $input);
        if ($preview['preview_status'] !== 'ready') throw new InvalidArgumentException('文件预览未通过，先修正行错误、完成状态和范围映射');
        $source = trim((string)($input['source_reference'] ?? ''));
        if ($source === '' || mb_strlen($source) > 80) throw new InvalidArgumentException('请填写不超过80字的脱敏来源证据引用');
        return Db::transaction(function () use ($tenant, $ids, $hotel, $actor, $input, $preview, $source): array {
            $receipt = (new GuestOperationsService())->importStays($tenant, $ids, $hotel, $actor, ['idempotency_key' => $input['idempotency_key'] ?? '', 'platform' => 'pms', 'source_reference' => $source, 'import_method' => 'jd06_file_import', 'events' => $preview['events']]);
            $store = new GuestOperationRecordStore(); $key = 'jd06:' . (string)$input['idempotency_key'];
            $batch = $store->latest($preview['tenant_id'], $hotel, 'stay_import', $key);
            if ($batch && !hash_equals($batch['document']['file_digest'], $preview['file_digest'])) throw new RuntimeException('guest_idempotency_conflict：同一提交标识的文件已变化', 409);
            if (!$batch) $batch = $store->append($preview['tenant_id'], $hotel, $actor, 'stay_import', $key, 0, ['file_digest' => $preview['file_digest'], 'source_reference' => $source, 'source_method' => 'jd06_file_import', 'source_quality' => 'unverified', 'date_start' => $preview['date_start'], 'date_end' => $preview['date_end'], 'scope_evidence' => $preview['scope_evidence'], 'mapping' => $input['mapping'], 'completed_values' => $input['completed_values'], 'event_count' => $preview['valid_count'], 'duplicate_rows' => $preview['duplicate_rows'], 'skipped_non_completed' => $preview['skipped_non_completed'], 'evidence_boundary' => $preview['evidence_boundary']], 'pms', $preview['date_end']);
            $receipt['records'][] = $batch;
            return $receipt + ['source_quality' => 'unverified', 'import_method' => 'jd06_file_import', 'evidence_boundary' => $preview['evidence_boundary'], 'duplicate_rows' => $preview['duplicate_rows'], 'skipped_non_completed' => $preview['skipped_non_completed']];
        });
    }
    private function rows(string $path, string $extension): array
    {
        try {
            if ($extension === 'csv') {
                $sample = file_get_contents($path, false, null, 0, 8192);
                $reader = IOFactory::createReader('Csv'); $reader->setInputEncoding(mb_check_encoding($sample, 'UTF-8') ? 'UTF-8' : 'GB18030');
            } else {
                if ($extension === 'xlsx') $this->checkArchive($path);
                $type = IOFactory::identify($path);
                if (!in_array($type, ['Xlsx', 'Xls'], true) || strtolower($type) !== $extension) throw new InvalidArgumentException('文件内容与扩展名不一致');
                $reader = IOFactory::createReader($type);
            }
            foreach ($reader->listWorksheetInfo($path) as $info) {
                if ($info['totalRows'] > 501 || $info['totalColumns'] > 40) throw new InvalidArgumentException('每次导入最多500行、40列，请拆分文件');
            }
            $reader->setReadDataOnly(true); $reader->setReadFilter(new class implements IReadFilter {
                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool { return $row <= 502 && \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress) <= 41; }
            });
            $sheet = $reader->load($path);
            if ($sheet->getSheetCount() !== 1) throw new InvalidArgumentException('请选择只有一个数据表的 JD06 文件');
            $active = $sheet->getActiveSheet();
            if ($active->getHighestDataRow() > 501 || \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($active->getHighestDataColumn()) > 40) throw new InvalidArgumentException('每次导入最多500行、40列，请拆分文件');
            $rows = $active->toArray(null, false, false, false); $sheet->disconnectWorksheets(); return $rows;
        } catch (InvalidArgumentException $error) { throw $error; }
        catch (\Throwable) { throw new InvalidArgumentException('文件解析失败，请检查格式、编码或是否损坏'); }
    }
    private function date(mixed $value): string
    {
        if (is_float($value) || is_int($value) || (is_string($value) && preg_match('/^\d{4,5}(\.\d+)?$/D', $value))) {
            if ($value < 1 || $value > 100000) throw new InvalidArgumentException('完成入住日期无效');
            $value = Date::excelToDateTimeObject((float)$value)->format('Y-m-d');
        }
        $text = trim((string)$value); $text = str_replace('/', '-', substr($text, 0, 10));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if (!$date || $date->format('Y-m-d') !== $text) throw new InvalidArgumentException('日期必须是有效 YYYY-MM-DD 或Excel日期');
        return $text;
    }
    private function checkArchive(string $path): void
    {
        $archive = new \ZipArchive();
        if ($archive->open($path) !== true) throw new InvalidArgumentException('文件解析失败，请检查XLSX格式');
        try {
            if ($archive->numFiles > 200) throw new InvalidArgumentException('XLSX结构过于复杂，请导出单表文件');
            $bytes = 0;
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index); $bytes += $entry['size'];
                if ($entry['size'] > 10 * 1024 * 1024 || $bytes > 20 * 1024 * 1024) throw new InvalidArgumentException('XLSX解压内容过大，请拆分为较小文件');
            }
        } finally { $archive->close(); }
    }
    private function key(int $tenant, int $hotel, int $actor, GuestOperationRecordStore $store): string
    {
        return Db::transaction(function () use ($tenant, $hotel, $actor, $store): string {
            Db::name('hotels')->where('id', $hotel)->lock(true)->find();
            $row = $store->latest($tenant, $hotel, 'guest_import_key', 'jd06');
            if (!$row) $row = $store->append($tenant, $hotel, $actor, 'guest_import_key', 'jd06', 0, ['key' => bin2hex(random_bytes(32))]);
            return $row['document']['key'];
        });
    }
}

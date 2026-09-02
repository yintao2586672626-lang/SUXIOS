<?php
declare(strict_types=1);

namespace app\service;

use DateTimeZone;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;

final class OtaSettlementFileParserService
{
    public const CONTRACT_VERSION = 'ota_settlement_file_parser.v1';

    private const HEADER_ALIASES = [
        '行号' => 'source_line_no',
        '序号' => 'source_line_no',
        '业务日期' => 'business_date',
        '结算日期' => 'business_date',
        '金额口径' => 'amount_scope',
        '平台订单号' => 'ota_order_ref',
        'OTA订单号' => 'ota_order_ref',
        '订单号' => 'ota_order_ref',
        'PMS住宿号' => 'pms_stay_ref',
        'PMS预订号' => 'pms_stay_ref',
        '住宿号' => 'pms_stay_ref',
        '成交总额' => 'gross_amount',
        '订单金额' => 'gross_amount',
        '佣金金额' => 'commission_amount',
        '佣金' => 'commission_amount',
        '平台补贴' => 'subsidy_amount',
        '补贴金额' => 'subsidy_amount',
        '退款金额' => 'refund_amount',
        '结算金额' => 'settlement_amount',
        '实际结算' => 'settlement_amount',
        '净收入' => 'net_revenue',
        '匹配状态' => 'match_status',
        'OTA对比金额' => 'ota_comparison_amount',
        'PMS对比金额' => 'pms_comparison_amount',
        '对比口径' => 'comparison_basis',
        '差异金额' => 'discrepancy_amount',
        '差异依据' => 'discrepancy_basis',
    ];
    private const MONEY_KEYS = [
        'gross_amount', 'commission_amount', 'subsidy_amount', 'refund_amount',
        'settlement_amount', 'net_revenue', 'ota_comparison_amount',
        'pms_comparison_amount', 'discrepancy_amount',
    ];
    private const DIRECT_BASIS_KEYS = [
        'gross_amount', 'commission_amount', 'subsidy_amount', 'refund_amount',
        'settlement_amount', 'net_revenue',
    ];

    /** @return array{contract_version:string,parser_version:string,file_sha256:string,row_count:int,lines:list<array<string,mixed>>} */
    public function parse(string $path, string $originalName, string $defaultAmountScope = 'settlement'): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('settlement_file_unreadable');
        }
        $defaultAmountScope = strtolower(trim($defaultAmountScope));
        if (!in_array($defaultAmountScope, ['booking', 'stay', 'settlement', 'adjustment'], true)) {
            throw new InvalidArgumentException('settlement_file_amount_scope_invalid');
        }
        $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['json', 'csv', 'xlsx'], true)) {
            throw new InvalidArgumentException('settlement_file_type_invalid');
        }
        $rows = (new PlatformDataSyncService())->parseImportFile($path, $originalName);
        if ($extension === 'json') {
            try {
                $decoded = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new InvalidArgumentException('settlement_json_invalid');
            }
            if (!is_array($decoded) || !array_is_list($decoded)) {
                throw new InvalidArgumentException('settlement_json_root_array_required');
            }
            foreach ($decoded as $row) {
                if (!is_array($row)) {
                    throw new InvalidArgumentException('settlement_json_business_row_invalid');
                }
            }
            $rows = $decoded;
        }
        $lines = [];
        foreach (array_values($rows) as $index => $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $line = [];
            foreach ($raw as $header => $value) {
                $key = $this->header((string)$header);
                if ($key === '') {
                    continue;
                }
                if (array_key_exists($key, $line)) {
                    throw new InvalidArgumentException('settlement_file_canonical_header_collision:' . $key);
                }
                $line[$key] = is_string($value) ? trim($value) : $value;
            }
            if ($line === []) {
                continue;
            }
            $line['source_line_no'] = isset($line['source_line_no']) && filter_var($line['source_line_no'], FILTER_VALIDATE_INT)
                ? (int)$line['source_line_no']
                : $index + 2;
            if ($extension === 'xlsx'
                && isset($line['business_date'])
                && is_numeric($line['business_date'])
            ) {
                $serial = (float)$line['business_date'];
                if ($serial > 0 && $serial < 2958466) {
                    try {
                        $line['business_date'] = SpreadsheetDate::excelToDateTimeObject(
                            $serial,
                            new DateTimeZone('Asia/Shanghai')
                        )->format('Y-m-d');
                    } catch (\Throwable) {
                        // Preserve the original cell so reconciliation marks it invalid.
                    }
                }
            }
            $line['amount_scope'] = strtolower(trim((string)($line['amount_scope'] ?? $defaultAmountScope)));
            $line['match_status'] = strtolower(trim((string)($line['match_status'] ?? 'not_evaluated')));
            foreach (self::MONEY_KEYS as $key) {
                if (!array_key_exists($key, $line) || $line[$key] === '') {
                    unset($line[$key]);
                    continue;
                }
                if (is_numeric($line[$key])) {
                    $line[$key] = (float)$line[$key];
                }
            }
            foreach (self::DIRECT_BASIS_KEYS as $key) {
                if (array_key_exists($key, $line) && !array_key_exists($key . '_basis', $line)) {
                    $line[$key . '_basis'] = 'source_direct';
                }
            }
            $lines[] = $line;
        }
        if ($lines === []) {
            throw new InvalidArgumentException('settlement_file_has_no_business_rows');
        }
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'parser_version' => 'canonical_settlement_' . $extension . '.v1',
            'file_sha256' => hash_file('sha256', $path),
            'row_count' => count($lines),
            'lines' => $lines,
        ];
    }

    private function header(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', trim($header)) ?? trim($header);
        if ($header === '') {
            return '';
        }
        if (isset(self::HEADER_ALIASES[$header])) {
            return self::HEADER_ALIASES[$header];
        }
        $key = strtolower(trim(preg_replace('/[\s-]+/', '_', $header) ?? $header, '_'));
        $allowed = [
            'source_line_no', 'business_date', 'amount_scope', 'ota_order_ref', 'pms_stay_ref',
            'gross_amount', 'gross_amount_basis', 'commission_amount', 'commission_amount_basis',
            'subsidy_amount', 'subsidy_amount_basis', 'refund_amount', 'refund_amount_basis',
            'settlement_amount', 'settlement_amount_basis', 'net_revenue', 'net_revenue_basis',
            'net_revenue_derivation', 'match_status', 'ota_comparison_amount', 'pms_comparison_amount',
            'comparison_basis', 'discrepancy_amount', 'discrepancy_basis',
        ];
        return in_array($key, $allowed, true) ? $key : '';
    }
}

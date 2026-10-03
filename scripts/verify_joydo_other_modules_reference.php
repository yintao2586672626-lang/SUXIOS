#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Own reference contracts and pure service replays. No App, DB, network, LLM or business write. */
function joydoOtherModulesReplay(string $root): array
{
    $f = json_decode((string)file_get_contents($root . '/docs/knowledge/joydo-jhira-other-20261001/sources/replay-fixture.json'), true, 512, JSON_THROW_ON_ERROR);
    if ($f['version'] !== 'joydo-other-replay-20261001-v1') {
        throw new RuntimeException('other_fixture_identity_conflict');
    }
    require_once $root . '/vendor/autoload.php';
    $cases = [];
    $check = static function (bool $ok, string $code) use (&$cases): void {
        if (!$ok) {
            throw new RuntimeException('other_replay_failed:' . $code);
        }
        $cases[] = $code;
    };
    $near = static fn(float $a, float $b): bool => abs($a - $b) < 0.00001;
    $s = $f['source_sample'];
    $available = $s['rooms'] * $s['days'];
    $check($available === 1500 && $available !== $available * $s['sales_window_hours'] / 24, 'sales_window_not_physical_inventory_fraction');
    $modulesReady = static function (array $modules): bool {
        $selected = array_filter($modules, static fn(array $m): bool => ($m['enabled'] ?? false) === true);
        return $selected !== [] && count(array_filter($selected, static fn(array $m): bool => ($m['evidence_ready'] ?? false) === true)) === count($selected);
    };
    $check(!$modulesReady([]) && !$modulesReady([['enabled' => true]]) && !$modulesReady([['enabled' => false, 'evidence_ready' => true]])
        && $modulesReady([['enabled' => true, 'evidence_ready' => true], ['enabled' => false, 'evidence_ready' => false]]), 'empty_enabled_or_selected_not_evidence_ready');
    $check($s['total_investment'] - $s['own_capital'] - $s['visible_other_funding'] === 1500000 && !$s['nonzero_debt_reproduction_verified'], 'source_funding_scope_gap_not_verified_funding_fact');

    // Synthetic loan: integer fen, nominal rate / 12, opening-balance interest.
    $schedule = static function (?int $principalFen, ?float $annualRate, ?int $months, ?string $method): array {
        $missing = ['status' => 'inputs_missing', 'rows' => null, 'principal_fen' => null, 'interest_fen' => null, 'debt_service_fen' => null];
        if ($principalFen === null || $principalFen < 0) {
            return $missing;
        }
        if ($principalFen === 0) {
            return ['status' => 'no_debt', 'rows' => [], 'principal_fen' => 0, 'interest_fen' => 0, 'debt_service_fen' => 0];
        }
        if ($annualRate === null || $annualRate < 0 || $months === null || $months < 1 || !in_array($method, ['equal_principal', 'equal_payment', 'balloon'], true)) {
            return $missing;
        }
        $rate = $annualRate / 12;
        $payment = $rate > 0 ? (int)round($principalFen * $rate / (1 - pow(1 + $rate, -$months))) : (int)round($principalFen / $months);
        $balance = $principalFen;
        $rows = [];
        for ($month = 1; $month <= $months; ++$month) {
            $opening = $balance;
            $interest = (int)round($opening * $rate);
            if ($month === $months) {
                $principal = $balance;
            } elseif ($method === 'equal_principal') {
                $principal = min($balance, intdiv($principalFen, $months));
            } elseif ($method === 'balloon') {
                $principal = 0;
            } else {
                $principal = min($balance, max(0, $payment - $interest));
            }
            $balance -= $principal;
            $rows[] = ['month' => $month, 'opening_fen' => $opening, 'principal_fen' => $principal, 'interest_fen' => $interest, 'closing_fen' => $balance, 'service_fen' => $principal + $interest];
        }
        return ['status' => 'calculated', 'rows' => $rows, 'principal_fen' => array_sum(array_column($rows, 'principal_fen')),
            'interest_fen' => array_sum(array_column($rows, 'interest_fen')), 'debt_service_fen' => array_sum(array_column($rows, 'service_fen'))];
    };
    $loan = $f['synthetic_finance'];
    $p = (int)round($loan['principal_yuan'] * 100);
    $plans = [];
    foreach (['equal_principal', 'equal_payment', 'balloon'] as $method) {
        $plans[$method] = $schedule($p, $loan['nominal_annual_rate'], $loan['months'], $method);
        $plan = $plans[$method];
        $valid = $plan['principal_fen'] === $p && $plan['rows'][$loan['months'] - 1]['closing_fen'] === 0;
        foreach ($plan['rows'] as $row) {
            $valid = $valid && $row['opening_fen'] - $row['principal_fen'] === $row['closing_fen'] && $row['service_fen'] === $row['principal_fen'] + $row['interest_fen'];
        }
        $check($valid, $method . '_principal_and_cash_conservation');
    }
    $check($plans['equal_principal']['interest_fen'] === 3900000 && $plans['equal_principal']['debt_service_fen'] === 63900000, 'equal_principal_known_arithmetic');
    $check($plans['balloon']['debt_service_fen'] === 67200000 && $plans['balloon']['rows'][11]['principal_fen'] === 60000000 && $plans['balloon']['rows'][0]['principal_fen'] === 0, 'balloon_maturity_concentration_not_annual_average');
    $check($plans['equal_payment']['rows'][0]['service_fen'] === 5330927 && $plans['equal_payment']['interest_fen'] !== $plans['equal_principal']['interest_fen'], 'repayment_method_changes_interest_and_timing');
    $zeroRate = $schedule(10001, 0.0, 3, 'equal_payment');
    $check($zeroRate['principal_fen'] === 10001 && $zeroRate['interest_fen'] === 0 && $zeroRate['rows'][2]['closing_fen'] === 0, 'zero_rate_and_integer_fen_remainder');
    $check($schedule(null, 0.12, 12, 'balloon')['debt_service_fen'] === null && $schedule($p, null, 12, 'balloon')['status'] === 'inputs_missing'
        && $schedule($p, 0.12, null, 'balloon')['status'] === 'inputs_missing' && $schedule($p, 0.12, 12, null)['status'] === 'inputs_missing', 'missing_loan_contract_not_zero_filled');
    $coverage = static function (?float $cash, ?float $debt): array {
        if ($debt === null || $debt < 0) {
            return ['status' => 'inputs_missing', 'ratio' => null];
        }
        if ($debt === 0.0) {
            return ['status' => 'not_applicable', 'ratio' => null];
        }
        return $cash === null ? ['status' => 'inputs_missing', 'ratio' => null] : ['status' => 'calculated', 'ratio' => $cash / $debt];
    };
    $dscr = $coverage($loan['cash_available_for_debt_service'], $plans['equal_principal']['debt_service_fen'] / 100);
    $check($near($dscr['ratio'], 560000 / 639000) && $coverage(null, 639000.0)['ratio'] === null && $coverage(560000.0, null)['ratio'] === null
        && $coverage(0.0, 639000.0)['ratio'] === 0.0 && $coverage(560000.0, 0.0)['status'] === 'not_applicable', 'dscr_known_zero_missing_and_no_debt_distinct');
    $annualRemainder = $loan['cash_available_for_debt_service'] - $plans['equal_principal']['debt_service_fen'] / 100;
    $check(!$near($dscr['ratio'], $annualRemainder / ($plans['equal_principal']['debt_service_fen'] / 100)), 'dscr_numerator_before_same_debt_service');

    $remainingPaybackMonths = static function (?float $remaining, ?float $cash): ?float {
        if ($remaining === null || $remaining < 0) {
            return null;
        }
        if ($remaining === 0.0) {
            return 0.0;
        }
        return $cash !== null && $cash > 0 ? $remaining / $cash : null;
    };
    $unitContribution = $s['net_adr'] - $s['variable_cost'];
    $target = static fn(?float $amount, ?float $months, ?float $debt, ?float $capacity, ?float $contribution): ?float => $amount !== null && $amount >= 0
        && $months !== null && $months > 0 && $debt !== null && $debt >= 0 && $capacity !== null && $capacity > 0 && $contribution !== null && $contribution > 0
        ? ($s['fixed_cost'] + $debt + $amount / $months) / ($capacity * $contribution) : null;
    $payback = $remainingPaybackMonths((float)$s['remaining_investment'], (float)$s['monthly_cash_proxy']);
    $check(round($payback / 12, 2) === 6.68 && round($target(3000000, 36, 0, $available, $unitContribution) * 100, 2) === 88.07
        && round($target(3000000, 12, 0, $available, $unitContribution) * 100, 2) === 171.79, 'visible_target_and_remaining_payback');
    $check($target(3000000, 12, 0, $available, $unitContribution) > 1 && is_finite($payback) && $payback > 12, 'short_target_unreachable_longer_payback_valid');
    $check($target(3000000, 36, 10000, $available, $unitContribution) > $target(3000000, 36, 0, $available, $unitContribution)
        && $target(3000000, 0, 0, $available, $unitContribution) === null && $target(3000000, 36, null, $available, $unitContribution) === null, 'debt_target_and_missing_period');
    $check($target(3000000, 36, 0, 0, $unitContribution) === null && $target(3000000, 36, 0, null, $unitContribution) === null
        && $target(3000000, 36, 0, $available, 0) === null && $target(3000000, 36, 0, $available, -1) === null
        && $target(3000000, 36, 0, $available, null) === null, 'target_invalid_or_unknown_capacity_and_contribution');
    $check($remainingPaybackMonths(2000000, 37402) < $payback && $remainingPaybackMonths(0.0, null) === 0.0
        && $remainingPaybackMonths(3000000, 0.0) === null && $remainingPaybackMonths(3000000, -1.0) === null, 'remaining_investment_zero_and_nonpositive_cash');
    $margin = static fn(?float $contractMonths, ?float $recoveryMonths): ?float => $contractMonths !== null && $contractMonths >= 0 && $recoveryMonths !== null ? $contractMonths - $recoveryMonths : null;
    $conditionalContractMonths = (float)$f['conditional_contract']['remaining_months'];
    $check($conditionalContractMonths === $s['remaining_contract_years'] * 12.0 && round($margin($conditionalContractMonths, $payback) / 12, 2) === $f['conditional_contract']['source_display_margin_years']
        && $margin(60.0, $payback) < 0 && $margin($payback, $payback) === 0.0 && $margin(null, $payback) === null
        && $margin($conditionalContractMonths, null) === null, 'contract_positive_zero_negative_and_unknown');

    $scope = $f['synthetic_scope'];
    $comparable = static function (array $a, array $b, array $keys): bool {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $a) || !array_key_exists($key, $b) || $a[$key] === null || $b[$key] === null || $a[$key] !== $b[$key]) {
                return false;
            }
        }
        return true;
    };
    $otaKeys = ['platform', 'query', 'observation_batch', 'checkin', 'checkout', 'device', 'login_mode', 'location_mode', 'sort', 'filters'];
    $check($comparable($scope, $scope, $otaKeys) && !$comparable($scope, array_replace($scope, ['platform' => 'meituan']), $otaKeys)
        && !$comparable($scope, array_replace($scope, ['query' => '其他测试词']), $otaKeys) && !$comparable($scope, array_replace($scope, ['checkin' => null]), $otaKeys), 'ota_competitor_context_matching');
    // Hotel identities differ for competitors; platform/search context must match.
    $check($comparable($scope, array_replace($scope, ['hotel_id' => 43, 'observed_at' => '2026-10-01 12:05:00']), $otaKeys)
        && !$comparable($scope, array_replace($scope, ['observation_batch' => 'another_batch']), $otaKeys), 'competitor_identity_and_comparable_window');
    $rank = $scope['rank_interval'];
    $homepage = static fn(array $interval, ?int $capacity): ?bool => $capacity === null || $capacity < 1 ? null
        : ($interval['max'] <= $capacity ? true : ($interval['min'] > $capacity ? false : null));
    $check($rank['min'] === 21 && $rank['max'] === 50 && $homepage($rank, $scope['page_capacity']) === null && $homepage($rank, 30) === null
        && $homepage(['min' => 1, 'max' => 20], 50) === true && $homepage(['min' => 51, 'max' => 100], 50) === false
        && $s['ota_rank'] === null, 'rank_interval_no_midpoint_or_unknown_homepage');
    $check(!$s['ota_live_collection_verified'] && $s['ota_ledger_write_disabled'] && !$s['ota_diagnosed'], 'source_ota_missing_collection_and_permission_preserved');

    $digest = new \app\service\KnowledgeContentDigestService();
    $fingerprint = static fn(array $value): string => $digest->digest($value);
    $snapshot = ['report_id' => 1, 'hotel_id' => 42, 'date' => '2026-10-01', 'audience' => 'owner', 'input_version' => 1, 'model' => 'synthetic_contract_v1'];
    $snapshotDigest = $fingerprint($snapshot);
    $canDeliver = static function (array $current, string $frozenDigest, array $checks, bool $reviewApproved, string $reviewDigest, bool $exportPermission) use ($fingerprint): bool {
        return $fingerprint($current) === $frozenDigest && $checks !== [] && count(array_filter($checks, static fn(mixed $passed): bool => $passed === true)) === count($checks)
            && $reviewApproved && $reviewDigest === $frozenDigest && $exportPermission;
    };
    $check($canDeliver($snapshot, $snapshotDigest, [true, true], true, $snapshotDigest, true)
        && !$canDeliver($snapshot, $snapshotDigest, [true, false], true, $snapshotDigest, true)
        && !$canDeliver($snapshot, $snapshotDigest, [true, null], true, $snapshotDigest, true)
        && !$canDeliver($snapshot, $snapshotDigest, [], true, $snapshotDigest, true)
        && !$canDeliver($snapshot, $snapshotDigest, ['pending'], true, $snapshotDigest, true), 'report_known_checks_missing_and_empty');
    $check(!$canDeliver($snapshot, $snapshotDigest, [true], false, $snapshotDigest, true)
        && !$canDeliver($snapshot, $snapshotDigest, [true], true, 'old_review_digest', true)
        && !$canDeliver($snapshot, $snapshotDigest, [true], true, $snapshotDigest, false), 'report_review_version_and_permission_separate');
    $check(!$canDeliver(array_replace($snapshot, ['input_version' => 2]), $snapshotDigest, [true], true, $snapshotDigest, true)
        && !$canDeliver(array_replace($snapshot, ['hotel_id' => 43]), $snapshotDigest, [true], true, $snapshotDigest, true), 'report_stale_input_and_hotel_mismatch');
    $check($s['report_review_ready'] < $s['report_review_total'] && $s['all_download_buttons_disabled'] && $s['report_exported_files'] === 0, 'source_preview_not_formal_export_or_parity');

    $mentionRate = static function (array $responses): ?float {
        if ($responses === []) {
            return null;
        }
        foreach ($responses as $response) {
            if (!array_key_exists('mention', $response) || !is_bool($response['mention'])) {
                return null;
            }
        }
        return count(array_filter($responses, static fn(array $r): bool => $r['mention'] === true)) / count($responses);
    };
    $responses = [['mention' => true, 'citation_valid' => false, 'fact_match' => null], ['mention' => false, 'citation_valid' => false, 'fact_match' => null]];
    $check($mentionRate([]) === null && $mentionRate([['mention' => false]]) === 0.0 && $mentionRate($responses) === 0.5
        && $mentionRate([['mention' => null]]) === null && $mentionRate([[]]) === null && $mentionRate([['mention' => true], ['mention' => null]]) === null
        && $responses[0]['citation_valid'] === false && $responses[0]['fact_match'] === null, 'geo_missing_mention_citation_and_fact_separated');
    $geoContext = ['question_set' => 'synthetic_v1', 'model' => 'synthetic_model_a', 'comparison_protocol' => 'synthetic_repeat_same_conditions', 'region' => 'test_region', 'search_mode' => 'test_offline'];
    $check($comparable($geoContext, $geoContext, array_keys($geoContext)) && !$comparable($geoContext, array_replace($geoContext, ['model' => 'synthetic_model_b']), array_keys($geoContext))
        && !$f['source_geo']['backend_verified'] && !$f['source_geo']['growth_effect_verified'], 'geo_public_claims_and_cross_model_not_effect_evidence');

    // Reuse existing pure services. Source content is never inserted as hotel facts.
    require_once $root . '/vendor/autoload.php';
    $paths = ['app/service/InvestmentScenarioCalculator.php', 'app/service/CtripOperatingRadarDiagnosisService.php', 'app/service/AiDailyReportPresentationSpecService.php'];
    $hashes = [];
    foreach ($paths as $path) {
        $hashes[$path] = strtoupper((string)hash_file('sha256', $root . '/' . $path));
    }
    $radar = new \app\service\CtripOperatingRadarDiagnosisService();
    $diagnosis = ['platform' => 'ctrip', 'hotel_id' => 42, 'date_range' => ['start_date' => '2026-10-01', 'end_date' => '2026-10-01'], 'metrics' => [], 'evidence_sources' => [], 'data_summary' => ['has_ota_data' => false]];
    $blocked = $radar->build($diagnosis);
    $check($blocked['status'] === 'blocked_by_data' && $blocked['summary']['blocked_count'] === 5 && $blocked['score_policy']['composite_score'] === null
        && !$blocked['guards']['decision_safe'] && !$blocked['guards']['external_write_authorized'], 'existing_radar_missing_no_score_or_action');
    $historic = $radar->build(array_replace($diagnosis, ['requested_date_range' => ['start_date' => '2026-10-01', 'end_date' => '2026-10-01'], 'effective_date_range' => ['start_date' => '2026-09-30', 'end_date' => '2026-09-30'],
        'data_summary' => ['has_ota_data' => true, 'used_latest_available_data' => true]]));
    $check($historic['scope']['uses_latest_available_history'] && $historic['scope']['requested_start_date'] !== $historic['scope']['effective_start_date'] && !$historic['guards']['decision_safe'], 'existing_radar_history_not_current_fact');
    $wrongPlatformBlocked = false;
    try {
        $radar->build(array_replace($diagnosis, ['platform' => 'meituan']));
    } catch (\InvalidArgumentException) {
        $wrongPlatformBlocked = true;
    }
    $check($wrongPlatformBlocked, 'existing_radar_wrong_platform_rejected');
    $calculator = new \app\service\InvestmentScenarioCalculator();
    $input = ['scenario_name' => '其他板块合成融资映射（非酒店事实）', 'as_of' => '2026-10-01', 'currency' => 'CNY', 'rooms' => 50, 'leased_rooms' => 50, 'years' => 1,
        'adr_first_year' => 160, 'occupancy_first_year' => 0.65, 'occupancy_mature' => 0.65, 'mature_from_year' => 1, 'adr_growth_rate' => 0, 'adr_growth_from_year' => 2,
        'operating_cost_basis' => 'fixed_variable', 'operating_cost_per_night' => 27.28, 'fixed_annual_operating_cost' => 504000, 'operating_cost_growth_rate' => 0,
        'monthly_rent_per_room' => 1000, 'rent_escalations' => [], 'rent_free_months' => 0, 'construction_months' => 0,
        'renovation_cash' => 3000000, 'franchise_cash' => 0, 'refundable_deposit_cash' => 0, 'other_initial_cash' => 0, 'working_capital_cash' => 0,
        'depreciable_amount' => 0, 'depreciation_years' => 1, 'management_fee_rate' => 0, 'days_per_year' => 360,
        'source_label' => '来源月样例重复12次与合成偿债，非真实现金', 'reference_example' => true,
        'cash_adjustments' => [['year' => 1, 'tax_cash' => 0, 'financing_net_cash' => -639000, 'maintenance_capex' => 0, 'working_capital_change' => 0, 'deposit_refund' => 0, 'salvage_cash' => 0]]];
    $mapped = $calculator->calculate($input);
    $cashRow = $mapped['annual_rows'][0];
    $check($near($cashRow['pretax_profit'], 448824) && $near($cashRow['scenario_cashflow'], -190176) && $mapped['source'] === 'scenario_assumption', 'existing_scenario_debt_cash_not_profit_scope');
    $input['cash_adjustments'][0]['financing_net_cash'] = 600000;
    $draw = $calculator->calculate($input)['annual_rows'][0];
    $check($near($draw['pretax_profit'], 448824) && $near($draw['scenario_cashflow'], 1048824), 'existing_scenario_loan_inflow_not_operating_income');
    $spec = (new \app\service\AiDailyReportPresentationSpecService())->build(['id' => 1, 'tenant_id' => 1, 'hotel_id' => 42, 'report_date' => '2026-10-01', 'source_refs' => [], 'summary' => '合成缺证样例，仅验证呈现合同'], 'owner');
    $check($spec['qa']['human_review_status'] === 'pending' && $spec['qa']['source_readback_status'] === 'unverified'
        && $spec['render_contract']['html']['status'] === 'not_rendered' && !$spec['authorization']['external_write_authorized'], 'existing_report_spec_pending_not_rendered_or_verified');
    foreach ($hashes as $path => $hash) {
        $check(strtoupper((string)hash_file('sha256', $root . '/' . $path)) === $hash, 'stable_' . strtolower(pathinfo($path, PATHINFO_FILENAME)));
    }
    return ['status' => 'passed', 'cases_passed' => count($cases), 'cases' => $cases,
        'synthetic_debt_service_yuan' => array_map(static fn(array $plan): float => $plan['debt_service_fen'] / 100, $plans),
        'synthetic_dscr' => $dscr, 'source_sample_replayed_payback_years' => $payback / 12,
        'conditional_contract_margin_years' => $margin($conditionalContractMonths, $payback) / 12, 'source_display_contract_margin_years' => $f['conditional_contract']['source_display_margin_years'],
        'runtime_baselines' => ['file_hashes' => $hashes, 'radar_missing' => $blocked['status'], 'radar_history_scope_preserved' => true, 'spec_review' => $spec['qa']['human_review_status'], 'spec_render' => $spec['render_contract']['html']['status']],
        'source_nonzero_finance_verified' => false, 'source_exports_verified' => false, 'geo_backend_verified' => false,
        'business_integration' => false, 'scope' => 'own_reference_contracts_and_pure_existing_services_no_db_llm_network_or_business_write'];
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        echo json_encode(joydoOtherModulesReplay(dirname(__DIR__)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } catch (Throwable $error) {
        $code = preg_match('/^[a-z_]+(?::[a-z_]+)?$/D', $error->getMessage()) ? $error->getMessage() : 'other_runtime_validation_failed';
        fwrite(STDERR, json_encode(['status' => 'failed', 'code' => $code, 'exception' => get_class($error)], JSON_THROW_ON_ERROR) . PHP_EOL);
        exit(2);
    }
}

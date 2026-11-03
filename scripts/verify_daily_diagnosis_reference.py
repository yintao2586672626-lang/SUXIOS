"""Read the supplied report as data; never execute its HTML or JavaScript."""
import argparse
import collections
import hashlib
import json
from pathlib import Path
import statistics

ROOT = Path(__file__).resolve().parents[1]
BASE = ROOT / 'docs/knowledge/daily-diagnosis-20260930'
EXPECTED = '56FB49531A7BF1BBB22E52F3802CB2F12D8E4BD599E36779AFF7BA331FF8F5B6'


def audit(raw):
    assert hashlib.sha256(raw).hexdigest().upper() == EXPECTED, 'source_hash_changed'
    html = raw.decode('utf-8')
    data, _ = json.JSONDecoder().raw_decode(html.split('const DATA = ', 1)[1])
    daily, forward = data['daily'], data['fwd']
    assert len(daily) == data['n_daily'] == 89
    assert len(forward) == data['n_fwd'] == 18
    assert len({r['date'] for r in daily}) == 89
    assert len({r['date'] for r in forward}) == 18
    correlations = {}
    for a, b in [('adr_c', 'mp'), ('dev', 'g'), ('occ_e', 'g')]:
        rows = [r for r in daily if isinstance(r[a], (int, float)) and isinstance(r[b], (int, float))]
        correlations[f'{a}:{b}'] = {'n': len(rows), 'r': statistics.correlation([r[a] for r in rows], [r[b] for r in rows])}
    net_bad = [r['date'] for r in daily if abs(r['adr_e'] - r['adr_s'] - r['adr_c']) > .001]
    stages = [r for r in daily if r['stage'] == '开盘定价环节']
    threshold_bad = [r['date'] for r in stages if r['adr_c'] > -12]
    threshold_eligible_not_selected = [r['date'] for r in daily if r['adr_c'] <= -12 and r['stage'] != '开盘定价环节']
    loss = sum(-r['adr_c'] * r['nights'] for r in stages)
    source_loss_bad = [r['date'] for r in data['loss'] if abs(-r['adr_c'] * r['nights'] - r['amt']) > .001]
    # Arithmetic agreement only: thresholds, labels and revenue causality are not validated.
    assert not net_bad and not threshold_bad and not source_loss_bad
    assert loss == data['loss_total'] == 6341
    assert round(correlations['adr_c:mp']['r'], 3) == .884
    assert round(correlations['dev:g']['r'], 3) == .774
    assert round(correlations['occ_e:g']['r'], 3) == .461
    missing = [r['date'] for r in forward if r['ly_occ'] is None or r['ly_adr'] is None]
    contradictions = [r['date'] for r in forward if r['dl'] <= 7 and '超过 7 天' in r['ntxt']]
    risk_zero = [r['date'] for r in forward if r['rt'] == 0 and '风险极高' in r['detail'].get('在手进度风险·含义', '')]
    assert '2026-09-30' in missing and '2026-09-30' in contradictions and '2026-09-30' in risk_zero
    # Golden source example and critical failure example retained with precise locators.
    example = next(r for r in daily if r['date'] == '2025-10-02')
    assert example['adr_e'] - example['adr_s'] == -55 and -example['adr_c'] * example['nights'] == 1485
    return {
        'status': 'reference_audit_complete_with_source_limitations',
        'source_sha256': EXPECTED, 'generated_as_declared': data['generated'],
        'daily_rows': len(daily), 'forward_rows': len(forward),
        'periods': dict(collections.Counter(r['period'] for r in daily)),
        'recomputed_correlations': correlations,
        'price_difference_arithmetic_rows': 89, 'selected_days_meet_necessary_threshold': len(stages),
        'threshold_eligible_days': len(stages) + len(threshold_eligible_not_selected),
        'threshold_eligible_not_selected': threshold_eligible_not_selected,
        'source_threshold_selected_days': len(stages), 'difference_times_nights': loss,
        'difference_times_nights_is_realized_loss': False,
        'review_no_count': sum(r['review'] == '否' for r in daily),
        'false_positive_rate_verified': False,
        'forward_missing_comparator_dates': missing,
        'forward_lead_time_label_conflicts': contradictions,
        'forward_risk_zero_but_extreme_text_dates': risk_zero,
        'golden_source_example': {'locator': 'DATA.daily[date=2025-10-02]', 'start': example['adr_s'], 'end': example['adr_e'], 'difference': -55, 'nights': example['nights'], 'arithmetic_exposure': 1485},
        'failure_source_example': {'locator': 'DATA.fwd[date=2026-09-30]', 'expected_adaptation': 'missing_comparator_and_label_conflict_require_review_no_pricing_advice'},
        'not_verified': ['original_spreadsheets', 'hotel_platform_identity', 'hourly_snapshots', 'independent_ground_truth', '72_of_84_passive_agreement_rule', '89_of_89_mispricing_direction_rule', 'model_reexecution', 'model_calibration', 'causal_pricing_effect', 'original_ui_interaction'],
        'usage_policy': 'reference_only', 'capability_disposition': 'absorption_candidate',
        'decision_safe': False, 'task_draft_safe': False, 'external_write_authorized': False,
    }


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--source', type=Path)
    parser.add_argument('--persist', action='store_true')
    args = parser.parse_args()
    source = args.source or BASE / 'sources/report.html.txt'
    raw = source.read_bytes()
    result = audit(raw)
    if args.persist:
        BASE.joinpath('sources').mkdir(parents=True, exist_ok=True)
        target = BASE / 'sources/report.html.txt'
        if target.exists():
            assert target.read_bytes() == raw, 'retained_source_conflict'
        else:
            target.write_bytes(raw)
        (BASE / 'verification.json').write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    print(json.dumps(result, ensure_ascii=False, indent=2))

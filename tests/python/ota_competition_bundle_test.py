from __future__ import annotations

import importlib.util
import sys
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "build_ota_competition_bundle.py"
SPEC = importlib.util.spec_from_file_location("ota_competition_bundle", SCRIPT)
assert SPEC and SPEC.loader
MODULE = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


def meituan_loaded() -> dict:
    rows = [
        {
            "rank": "1",
            "hotel_name": "本店",
            "hotel_id": "00123",
            "is_target": "1",
            "stay_room_nights": "100",
            "room_revenue": "30000",
            "stay_adr": "300",
            "sold_room_nights": "120",
            "sales_revenue": "36000",
            "sales_adr": "300",
            "impressions": "10000",
            "views": "1000",
            "orders": "100",
            "platform_view_conversion": "10%",
            "platform_pay_conversion": "10%",
            "platform_absolute_conversion": "1%",
        },
        {
            "rank": "2",
            "hotel_name": "竞品",
            "hotel_id": "00456",
            "is_target": "0",
            "stay_room_nights": "100",
            "room_revenue": "28000",
            "stay_adr": "280",
            "sold_room_nights": "120",
            "sales_revenue": "33600",
            "sales_adr": "280",
            "impressions": "10000",
            "views": "900",
            "orders": "90",
            "platform_view_conversion": "9%",
            "platform_pay_conversion": "10%",
            "platform_absolute_conversion": "0.9%",
        },
    ]
    summary = {
        "hotel_count": 2,
        "total_stay_room_nights": 200,
        "total_room_revenue": 58000,
        "total_sold_room_nights": 240,
        "total_sales_revenue": 69600,
        "total_impressions": 20000,
        "total_views": 1900,
        "total_orders": 190,
        "platform_avg_view_conversion": "9.5%",
        "platform_avg_pay_conversion": "10%",
        "platform_avg_absolute_conversion": "0.95%",
    }
    context = {
        "platform": "meituan",
        "system_hotel_id": "80",
        "platform_hotel_id": "00123",
        "binding_status": "verified",
        "data_date": "2026-07-23",
        "collected_at": "2026-07-24T08:00:00+08:00",
        "source_method": "authorized_export",
        "source_trace_id": "unit-fixture",
        "verification_status": "available",
        "dataset_kind": "live",
    }
    return {
        "platform": "meituan",
        "config": {"edition": "auto", "hotel_id": "00123"},
        "market_summary": summary,
        "hotels": rows,
        "context": context,
        "source_contract": "unit_fixture",
        "input_fingerprint": "a" * 64,
    }


class OtaCompetitionBundleTest(unittest.TestCase):
    def test_verified_meituan_bundle_is_decision_eligible(self):
        bundle = MODULE.build_bundle(meituan_loaded())
        self.assertEqual("available", bundle["quality"]["status"])
        self.assertTrue(bundle["quality"]["decision_eligible"])
        self.assertEqual("00123", bundle["facts"]["target"]["platform_hotel_id"])
        self.assertEqual(["lite"], bundle["render_contract"]["requested_editions"])
        self.assertEqual("ready", bundle["recommendations"]["status"])

    def test_missing_source_totals_are_not_backfilled_from_details(self):
        loaded = meituan_loaded()
        loaded["market_summary"] = {}
        loaded["input_fingerprint"] = "b" * 64
        bundle = MODULE.build_bundle(loaded)
        self.assertEqual("partial", bundle["quality"]["status"])
        self.assertFalse(bundle["quality"]["decision_eligible"])
        self.assertIsNone(
            bundle["facts"]["source_market_totals"]["total_sales_revenue"]
        )
        self.assertEqual(
            69600.0,
            bundle["facts"]["detail_totals_for_closure_only"][
                "total_sales_revenue"
            ],
        )
        self.assertEqual("withheld", bundle["recommendations"]["status"])

    def test_all_missing_detail_values_remain_null_not_zero(self):
        loaded = meituan_loaded()
        for row in loaded["hotels"]:
            row["sales_revenue"] = ""
        bundle = MODULE.build_bundle(loaded)
        self.assertIsNone(
            bundle["facts"]["detail_totals_for_closure_only"][
                "total_sales_revenue"
            ]
        )
        self.assertEqual("partial", bundle["quality"]["status"])

    def test_explicit_zero_adr_is_preserved(self):
        loaded = meituan_loaded()
        loaded["hotels"][0]["sales_adr"] = "0"
        bundle = MODULE.build_bundle(loaded)
        self.assertEqual(0.0, bundle["facts"]["target"]["sales_adr"])

    def test_missing_platform_rate_withholds_role_even_when_totals_close(self):
        loaded = meituan_loaded()
        loaded["market_summary"]["platform_avg_absolute_conversion"] = ""
        bundle = MODULE.build_bundle(loaded)
        self.assertEqual("partial", bundle["quality"]["status"])
        self.assertFalse(bundle["quality"]["decision_eligible"])
        self.assertEqual(
            "withheld",
            bundle["analysis"]["channel_role_and_conflict"]["status"],
        )

    def test_duplicate_hotel_id_blocks_instead_of_silently_deduplicating(self):
        loaded = meituan_loaded()
        loaded["hotels"][1]["hotel_id"] = "00123"
        bundle = MODULE.build_bundle(loaded)
        self.assertEqual("blocked", bundle["quality"]["status"])
        self.assertFalse(bundle["quality"]["decision_eligible"])
        self.assertTrue(
            any("唯一键重复" in item for item in bundle["quality"]["blocking_issues"])
        )

    def test_synthetic_data_can_compute_but_cannot_issue_actions(self):
        loaded = meituan_loaded()
        loaded["context"]["dataset_kind"] = "synthetic"
        loaded["context"]["verification_status"] = "synthetic"
        bundle = MODULE.build_bundle(loaded)
        self.assertEqual("synthetic", bundle["quality"]["status"])
        self.assertFalse(bundle["quality"]["decision_eligible"])
        self.assertTrue(bundle["derived_metrics"]["target"]["sales_share"] > 0)
        self.assertEqual("withheld", bundle["recommendations"]["status"])

    def test_ctrip_hotel_id_keeps_leading_zero_and_bare_one_rate_is_rejected(self):
        gaps: list[str] = []
        rows = MODULE.normalize_rows(
            "ctrip",
            [
                {
                    "hotel_id": "00077",
                    "hotel_name": "本店",
                    "is_subject": "1",
                    "sales": "1000",
                    "room_nights": "5",
                    "ctrip_visitors": "100",
                    "ctrip_orders": "5",
                    "ctrip_platform_conversion": "1",
                }
            ],
            gaps,
        )
        self.assertEqual("00077", rows[0]["platform_hotel_id"])
        self.assertIsNone(rows[0]["ctrip_platform_conversion"])
        self.assertTrue(any("含义不明确" in item for item in gaps))

    def test_dual_delivery_routes_once_to_one_shared_bundle(self):
        bundle = MODULE.build_bundle(
            meituan_loaded(),
            request_text="请同时给我简版和旗舰版双版报告",
        )
        self.assertEqual(
            ["lite", "flagship"],
            bundle["render_contract"]["requested_editions"],
        )
        self.assertTrue(bundle["render_contract"]["single_calculation"])
        self.assertEqual("analysis_bundle.json", bundle["render_contract"]["canonical_file"])


if __name__ == "__main__":
    unittest.main()

import copy
import unittest
from datetime import date
from pathlib import Path

from catalog import evaluate, in_period, load, matrix_markdown, validate

CATALOG_DIR = Path(__file__).resolve().parents[3] / "catalog" / "legal" / "mg"
DEFESO = date(2026, 12, 15)
OFF_SEASON = date(2027, 6, 15)


class CatalogTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.catalog = load(CATALOG_DIR)

    def test_catalog_is_consistent(self):
        self.assertEqual(validate(self.catalog), [])

    def test_no_rule_grants_permission(self):
        self.assertTrue(all(rule["effect"] != "allowed" for rule in self.catalog.rules))
        for basin in self.catalog.basins:
            for day in (DEFESO, OFF_SEASON):
                for species in ("native", "non_native"):
                    self.assertNotEqual(evaluate(self.catalog, basin, day, species).status, "allowed")

    def test_rules_never_point_to_revoked_or_unread_acts(self):
        broken = copy.deepcopy(self.catalog)
        broken.rules[0]["act"] = "ief-40-2017"
        self.assertIn("revoked", " ".join(validate(broken)))
        broken.rules[0]["act"] = "ibama-in-25-2009"
        self.assertIn("was not read", " ".join(validate(broken)))

    def test_defeso_prohibits_natives_in_every_basin(self):
        for basin in ("sao_francisco", "paraopeba", "grande", "paranaiba", "leste", "doce"):
            self.assertEqual(evaluate(self.catalog, basin, DEFESO, "native").status, "prohibited", basin)

    def test_doce_and_paraopeba_prohibit_natives_all_year(self):
        doce = evaluate(self.catalog, "doce", OFF_SEASON, "native")
        self.assertEqual((doce.status, doce.rules), ("prohibited", ["doce-nativas-permanente"]))
        self.assertEqual(evaluate(self.catalog, "paraopeba", OFF_SEASON, "native").status, "prohibited")

    def test_off_season_without_rules_is_undetermined(self):
        for basin in ("sao_francisco", "grande", "paranaiba", "leste"):
            result = evaluate(self.catalog, basin, OFF_SEASON, "native")
            self.assertEqual(result.status, "undetermined", basin)
            self.assertTrue(result.gaps)

    def test_non_natives_in_defeso_carry_every_applicable_condition(self):
        result = evaluate(self.catalog, "doce", DEFESO, "non_native")
        self.assertEqual(result.status, "conditional")
        self.assertEqual(set(result.rules), {"doce-nao-nativas", "leste-defeso-nao-nativas"})
        self.assertIn("limite de 3 kg + 1 exemplar por jornada (amador e profissional)", result.conditions)

    def test_location_rules_are_reported_as_caveats(self):
        result = evaluate(self.catalog, "grande", DEFESO, "non_native")
        self.assertIn("gp-defeso-trechos-fechados", result.location_caveats)
        self.assertIn("mg-ucs-estaduais", result.location_caveats)

    def test_sao_francisco_marginal_lagoons_stay_closed_until_april(self):
        result = evaluate(self.catalog, "sao_francisco", date(2027, 4, 10), "native")
        self.assertEqual(result.status, "undetermined")
        self.assertIn("sf-lagoas-marginais-federal", result.location_caveats)

    def test_period_boundaries(self):
        piracema = {"kind": "annual", "start": "11-01", "end": "02-28"}
        self.assertFalse(in_period(piracema, date(2026, 10, 31)))
        self.assertTrue(in_period(piracema, date(2026, 11, 1)))
        self.assertTrue(in_period(piracema, date(2027, 2, 28)))
        self.assertTrue(in_period(piracema, date(2028, 2, 29)))
        self.assertFalse(in_period(piracema, date(2027, 3, 1)))

    def test_matrix_lists_every_basin_and_period(self):
        table = matrix_markdown(self.catalog)
        self.assertEqual(len(table.splitlines()), 2 + 6 * 2)


if __name__ == "__main__":
    unittest.main()

import copy
import unittest
from datetime import date
from pathlib import Path

import json

from catalog import evaluate, export_json, in_period, is_verified, lineage, load, matrix_markdown, qualify, validate

LEGAL_DIR = Path(__file__).resolve().parents[3] / "catalog" / "legal"
CATALOG_DIR = LEGAL_DIR / "mg"
DEFESO = date(2026, 12, 15)
OFF_SEASON = date(2027, 6, 15)


class CatalogTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.catalog = load(CATALOG_DIR)
        cls.federal = cls.catalog.linked["br"]

    def test_catalog_is_consistent(self):
        self.assertEqual(validate(self.catalog), [])
        self.assertEqual(validate(self.federal), [])

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
        federal = copy.deepcopy(self.federal)
        federal.rules[0]["act"] = "ibama-in-25-2009"
        self.assertIn("was not read", " ".join(validate(federal)))

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

    def test_every_rule_with_stored_text_is_verified_against_it(self):
        stored = [(c, rule) for c in (self.catalog, self.federal) for rule in c.rules if rule["act"] in c.segments]
        self.assertGreaterEqual(len(stored), 13)
        self.assertTrue(all(is_verified(c, rule) for c, rule in stored))

    def test_altered_quote_is_rejected(self):
        broken = copy.deepcopy(self.catalog)
        rule = next(r for r in broken.rules if r["id"] == "doce-nao-nativas")
        rule["evidence"][1]["quote"] = "limite de cota de 20 kg (vinte quilogramas)"
        self.assertIn("quote not found in ief-31-2025 art4/I/b", " ".join(validate(broken)))

    def test_missing_evidence_or_segment_is_rejected(self):
        broken = copy.deepcopy(self.catalog)
        rule = next(r for r in broken.rules if r["id"] == "sf-defeso-nativas")
        rule["evidence"][0]["segment"] = "art99"
        self.assertIn("segment art99 not found", " ".join(validate(broken)))
        rule["evidence"] = []
        self.assertIn("needs evidence", " ".join(validate(broken)))

    def test_species_lists_must_match_the_cited_text(self):
        broken = copy.deepcopy(self.catalog)
        species = next(item for item in broken.species_lists if item["id"] == "ief-156-2011-art7")
        species["non_native"].append("Dourado (Salminus brasiliensis)")
        self.assertIn("'Salminus brasiliensis' not found in ief-156-2011 art7/IV", " ".join(validate(broken)))

    def test_threatened_list_is_loaded_from_the_official_annex(self):
        listed = next(item for item in self.federal.species_lists if item["id"] == "mma-1667-2026-anexo-i")
        names = {entry["name"]: entry["category"] for entry in listed["threatened"]}
        self.assertEqual(names["Pseudoplatystoma corruscans"], "VU")
        self.assertEqual(names["Steindachneridion doceanum"], "EN")
        self.assertTrue(all(entry["group"] in listed["groups"] for entry in listed["threatened"]))
        rule = next(r for r in self.federal.rules if r["id"] == "br-ameacadas-protecao-integral")
        self.assertTrue(is_verified(self.federal, rule))

    def test_threatened_rules_need_a_list(self):
        broken = copy.deepcopy(self.federal)
        next(r for r in broken.rules if r["id"] == "br-ameacadas-protecao-integral")["species_list"] = "inexistente"
        self.assertIn("threatened rule needs a species_list", " ".join(validate(broken)))

    def test_unverified_rules_are_flagged_as_gaps(self):
        result = evaluate(self.catalog, "paraopeba", OFF_SEASON, "native")
        self.assertIn("paraopeba-nativas-permanente: sem trecho literal conferido no texto armazenado", result.gaps)

    def test_export_is_canonical_and_lists_verified_rules(self):
        exported = export_json(self.catalog)
        self.assertEqual(exported, export_json(load(CATALOG_DIR)))
        self.assertIn('"doce-nativas-permanente"', exported.split('"verified_rules"')[1])

    def test_export_qualifies_every_area_code(self):
        document = json.loads(export_json(self.catalog))
        areas = {area["id"]: area for area in document["areas"]}
        self.assertEqual(document["jurisdiction"], "mg")
        self.assertEqual(areas["mg:doce"]["parent"], "mg:leste")
        self.assertNotIn("parent", areas["mg"])
        self.assertEqual(areas["mg:sao_francisco"]["within"], ["br:sao_francisco"])
        doce = next(rule for rule in document["rules"] if rule["id"] == "doce-nativas-permanente")
        self.assertEqual(doce["basins"], ["mg:doce"])
        lists = {item["id"]: item["basin"] for item in document["species_lists"]}
        self.assertEqual(lists["ief-156-2011-art7"], "mg:grande,mg:paranaiba")
        federal = json.loads(export_json(self.federal))
        self.assertEqual({rule["id"]: rule["basins"] for rule in federal["rules"]}["br-ameacadas-protecao-integral"], ["br"])

    def test_lineage_reaches_federal_areas_through_within(self):
        codes = [qualify(owner.jurisdiction, area) for owner, area in lineage(self.catalog, "doce")]
        self.assertEqual(codes, ["mg:doce", "mg:leste", "mg", "br:leste", "br"])
        codes = [qualify(owner.jurisdiction, area) for owner, area in lineage(self.catalog, "paraopeba")]
        self.assertEqual(codes, ["mg:paraopeba", "mg:sao_francisco", "mg", "br:sao_francisco", "br"])

    def test_federal_rules_apply_through_the_state_areas(self):
        leste = evaluate(self.catalog, "doce", DEFESO, "native")
        self.assertIn("leste-defeso-federal", leste.rules)
        self.assertNotIn("leste-defeso-federal", evaluate(self.catalog, "grande", DEFESO, "native").rules)

    def test_within_must_point_to_an_area_of_another_loaded_catalog(self):
        broken = copy.deepcopy(self.catalog)
        broken.linked = self.catalog.linked
        broken.basins["grande"]["within"] = ["br:parana"]
        self.assertIn("within br:parana is not an area of a loaded catalog", " ".join(validate(broken)))
        broken.basins["grande"]["within"] = ["mg:leste"]
        self.assertIn("must point to another jurisdiction", " ".join(validate(broken)))

    def test_rule_ids_are_unique_across_jurisdictions(self):
        broken = copy.deepcopy(self.catalog)
        broken.linked = self.catalog.linked
        broken.rules[0]["id"] = "br-ameacadas-protecao-integral"
        self.assertIn("also used by catalog br", " ".join(validate(broken)))

    def test_every_catalog_directory_is_a_jurisdiction_with_a_root_area(self):
        for directory in LEGAL_DIR.iterdir():
            if (directory / "acts.toml").exists():
                catalog = load(directory)
                self.assertEqual(validate(catalog), [], directory.name)
                exported = (directory / "catalog.json").read_text()
                self.assertEqual(exported, export_json(catalog), f"{directory.name}/catalog.json out of date")

    def test_matrix_lists_every_basin_and_period(self):
        table = matrix_markdown(self.catalog)
        self.assertEqual(len(table.splitlines()), 2 + 6 * 2)


if __name__ == "__main__":
    unittest.main()

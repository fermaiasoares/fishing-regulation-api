import gzip
import hashlib
import json
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from legal_areas import area_for, check, load_mapping, validate  # noqa: E402

MAPPING = Path(__file__).resolve().parents[3] / "catalog" / "legal" / "mg" / "area_mapping.toml"
BASINS = MAPPING.with_name("basins.toml")
SQUARE = {"type": "Polygon", "coordinates": [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]]}


FEDERAL = {"GD": "Rio Grande", "SF": "Rio São Francisco", "DO": "Rio Doce", "PN": "Rio Paranaíba"}


def feature(sigla, nome, federal=None):
    # Synthetic geometry: only the attributes matter for these tests.
    properties = {"sigla": sigla, "nome": nome, "bacia_fede": federal or FEDERAL.get(sigla[:2], "")}
    return {"type": "Feature", "properties": properties, "geometry": SQUARE}


class LegalAreasTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.mapping = load_mapping(MAPPING)

    def test_exact_code_wins_over_prefix(self):
        self.assertEqual(area_for("SF3", self.mapping), "paraopeba")
        self.assertEqual(area_for("SF5", self.mapping), "sao_francisco")
        self.assertEqual(area_for("DO4", self.mapping), "doce")
        self.assertEqual(area_for("JQ2", self.mapping), "leste")
        self.assertEqual(area_for("PJ1", self.mapping), "piracicaba_jaguari")

    def test_unknown_districts_are_never_guessed(self):
        with self.assertRaisesRegex(ValueError, "XX1"):
            validate({"type": "FeatureCollection", "features": [feature("XX1", "Inexistente")]}, self.mapping)

    def test_mapping_is_checked_against_official_attributes(self):
        with self.assertRaisesRegex(ValueError, "belongs to 'Rio Doce'"):
            validate({"type": "FeatureCollection", "features": [feature("GD3", "x", "Rio Doce")]}, self.mapping)
        with self.assertRaisesRegex(ValueError, "expected it to mention 'Paraopeba'"):
            validate({"type": "FeatureCollection", "features": [feature("SF3", "SF3: Rio Pará")]}, self.mapping)

    def test_rejects_duplicates_and_bad_geometry(self):
        with self.assertRaisesRegex(ValueError, "duplicate"):
            validate({"type": "FeatureCollection", "features": [feature("GD1", "a"), feature("GD1", "b")]}, self.mapping)
        line = feature("GD2", "c") | {"geometry": {"type": "LineString", "coordinates": [[0, 0], [1, 1]]}}
        with self.assertRaisesRegex(ValueError, "invalid geometry"):
            validate({"type": "FeatureCollection", "features": [line]}, self.mapping)

    def test_check_detects_tampering_and_stale_mapping(self):
        raw = json.dumps({"type": "FeatureCollection", "features": [feature("GD1", "Alto Rio Grande")]}).encode()
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            (folder / "circunscricoes_hidrograficas.geojson.gz").write_bytes(gzip.compress(raw))
            manifest = {"sha256": hashlib.sha256(raw).hexdigest(), "districts": [{"sigla": "GD1", "area": "grande"}]}
            (folder / "manifest.json").write_text(json.dumps(manifest))
            check(folder, MAPPING, BASINS)
            manifest["districts"][0]["area"] = "leste"
            (folder / "manifest.json").write_text(json.dumps(manifest))
            with self.assertRaisesRegex(ValueError, "out of date"):
                check(folder, MAPPING)
            manifest["sha256"] = "0" * 64
            (folder / "manifest.json").write_text(json.dumps(manifest))
            with self.assertRaisesRegex(ValueError, "SHA-256"):
                check(folder, MAPPING)


if __name__ == "__main__":
    unittest.main()

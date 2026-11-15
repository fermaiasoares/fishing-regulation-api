"""Acquire the official hydrographic districts of Minas Gerais and map them to legal areas.

Source: IDE-Sisema / Igam, layer ``ide_1108_mg_circunscricoes_hidrograficas_pol``
(Circunscrições Hidrográficas, DN CERH-MG nº 66/2020 as amended by DN 95/2024),
served by the state GeoServer as GeoJSON in SIRGAS 2000 (EPSG:4674).

The raw response is stored gzip-compressed with its SHA-256; geometry repair
and the transformation to EPSG:4326 happen in PostGIS at import time, so the
stored file is exactly what the official service returned. Each district is
assigned to a legal area by ``catalog/legal/mg/area_mapping.toml``; a district
the mapping does not cover makes the run fail instead of being guessed.
"""

import argparse
import gzip
import hashlib
import json
import re
import tomllib
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

LAYER = "IDE:ide_1108_mg_circunscricoes_hidrograficas_pol"
SOURCE_URL = (
    "https://geoserver.meioambiente.mg.gov.br/IDE/ows?service=WFS&version=1.0.0&request=GetFeature"
    "&typeName=IDE%3Aide_1108_mg_circunscricoes_hidrograficas_pol&maxFeatures=10000000"
    "&outputFormat=application%2Fjson&srsName=EPSG:4674"
)
METADATA_URL = "https://idesisema.meioambiente.mg.gov.br/geonetwork/srv/api/records/061bca40-9d5e-42a2-a99f-4c0042a5e821"
USER_AGENT = "fishing-regulation-api/geo-ingestion (+https://github.com/fermaiasoares/fishing-regulation-api)"
SOURCE_SRID = 4674
GEOMETRY_TYPES = {"Polygon", "MultiPolygon"}


def load_mapping(path: Path) -> list[dict]:
    with path.open("rb") as handle:
        return tomllib.load(handle)["district"]


def area_for(sigla: str, mapping: list[dict]) -> str:
    """Legal area of a district code such as "SF3"; exact codes win over prefixes."""
    exact = [rule for rule in mapping if rule.get("code") == sigla]
    if exact:
        return exact[0]["area"]
    prefix = re.match(r"^[A-Z]+", sigla)
    matches = [rule for rule in mapping if prefix and rule.get("prefix") == prefix.group(0)]
    if len(matches) != 1:
        raise ValueError(f"district {sigla!r} is not covered by the area mapping")
    return matches[0]["area"]


def validate(collection: dict, mapping: list[dict]) -> list[dict]:
    """Check structure and return one summary row per district."""
    if collection.get("type") != "FeatureCollection" or not collection.get("features"):
        raise ValueError("response is not a non-empty GeoJSON FeatureCollection")
    crs = json.dumps(collection.get("crs", {}))
    if crs != "{}" and "4674" not in crs:
        raise ValueError(f"unexpected CRS {crs}")
    rows, seen, unmapped = [], set(), []
    for feature in collection["features"]:
        properties = {key.lower(): value for key, value in (feature.get("properties") or {}).items()}
        sigla = str(properties.get("sigla") or "").strip().upper()
        name = str(properties.get("nome") or "").strip()
        geometry = feature.get("geometry") or {}
        if not sigla or not name:
            raise ValueError(f"feature {feature.get('id')} has no sigla/nome")
        if sigla in seen:
            raise ValueError(f"duplicate district {sigla}")
        if geometry.get("type") not in GEOMETRY_TYPES or not geometry.get("coordinates"):
            raise ValueError(f"district {sigla} has invalid geometry type {geometry.get('type')}")
        seen.add(sigla)
        try:
            area = area_for(sigla, mapping)
        except ValueError:
            unmapped.append(f"{sigla} ({name})")
            continue
        rows.append({"sigla": sigla, "nome": name, "area": area, "geometry_type": geometry["type"]})
    if unmapped:
        raise ValueError("districts not covered by the area mapping: " + "; ".join(sorted(unmapped)))
    return sorted(rows, key=lambda row: (re.sub(r"\d+$", "", row["sigla"]), int(re.sub(r"^\D+", "", row["sigla"]) or 0)))


def acquire(output_dir: Path, mapping_path: Path, url: str = SOURCE_URL) -> dict:
    request = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(request, timeout=300) as response:
        raw = response.read()
    collection = json.loads(raw)
    rows = validate(collection, load_mapping(mapping_path))

    output_dir.mkdir(parents=True, exist_ok=True)
    digest = hashlib.sha256(raw).hexdigest()
    data_path = output_dir / "circunscricoes_hidrograficas.geojson.gz"
    manifest_path = output_dir / "manifest.json"
    previous = json.loads(manifest_path.read_text()) if manifest_path.exists() else {}
    if previous.get("sha256") != digest:
        # mtime=0 keeps the gzip bytes reproducible for identical content.
        data_path.write_bytes(gzip.compress(raw, mtime=0))
    manifest = {
        "dataset": "ide-sisema-circunscricoes-hidrograficas",
        "publisher": "Igam / IDE-Sisema",
        "layer": LAYER,
        "url": url,
        "metadata_url": METADATA_URL,
        "legal_basis": "DN CERH-MG nº 66/2020, alterada pela DN CERH-MG nº 95/2024",
        "license": "Acesso livre (metadado IDE-Sisema); sem licença nomeada",
        "source_srid": SOURCE_SRID,
        "sha256": digest,
        "bytes": len(raw),
        "retrieved_at": previous.get("retrieved_at") if previous.get("sha256") == digest
        else datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
        "feature_count": len(rows),
        "districts": rows,
    }
    if previous.get("sha256") and previous["sha256"] != digest:
        manifest["previous_sha256"] = previous["sha256"]
    manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=1) + "\n")
    return manifest


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("output_dir", type=Path)
    parser.add_argument("--mapping", type=Path, required=True)
    args = parser.parse_args()
    manifest = acquire(args.output_dir, args.mapping)
    print(json.dumps({key: manifest[key] for key in ("sha256", "bytes", "feature_count")}, indent=1))
    for row in manifest["districts"]:
        print(f"{row['sigla']:6} {row['area']:16} {row['geometry_type']:13} {row['nome']}")


if __name__ == "__main__":
    main()

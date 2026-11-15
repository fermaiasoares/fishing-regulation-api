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


def area_for(sigla: str, mapping: list[dict], name: str | None = None) -> str:
    """Legal area of a district code such as "SF3"; exact codes win over prefixes."""
    exact = [rule for rule in mapping if rule.get("code") == sigla]
    if exact:
        expected = exact[0].get("name_contains")
        if expected and name is not None and expected.casefold() not in name.casefold():
            raise ValueError(f"district {sigla} is named {name!r}, expected it to mention {expected!r}")
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
        federal = str(properties.get("bacia_fede") or "").strip()
        rule = next((r for r in mapping if r.get("code") == sigla), None) or next(
            (r for r in mapping if r.get("prefix") and re.match(r"^[A-Z]+", sigla).group(0) == r["prefix"]), None)
        if rule and rule.get("federal_basin") and rule["federal_basin"] != federal:
            raise ValueError(f"district {sigla} belongs to {federal!r}, but the mapping expects {rule['federal_basin']!r}")
        if sigla in {rule.get("code") for rule in mapping}:
            area = area_for(sigla, mapping, name)  # a renamed district must fail loudly
            rows.append({"sigla": sigla, "nome": name, "area": area, "geometry_type": geometry["type"]})
            continue
        try:
            area = area_for(sigla, mapping)
        except ValueError:
            unmapped.append(f"{sigla} ({name})")
            continue
        rows.append({"sigla": sigla, "nome": name, "area": area, "geometry_type": geometry["type"]})
    if unmapped:
        raise ValueError("districts not covered by the area mapping: " + "; ".join(sorted(unmapped)))
    return sorted(rows, key=lambda row: (re.sub(r"\d+$", "", row["sigla"]), int(re.sub(r"^\D+", "", row["sigla"]) or 0)))


VOLATILE_KEYS = ("timeStamp",)


def content_sha256(collection: dict) -> str:
    """Hash of the data itself, ignoring per-response fields such as GeoServer's timeStamp."""
    stable = {key: value for key, value in collection.items() if key not in VOLATILE_KEYS}
    return hashlib.sha256(json.dumps(stable, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode()).hexdigest()


def acquire(output_dir: Path, mapping_path: Path, url: str = SOURCE_URL) -> dict:
    request = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(request, timeout=300) as response:
        raw = response.read()
    collection = json.loads(raw)
    rows = validate(collection, load_mapping(mapping_path))

    output_dir.mkdir(parents=True, exist_ok=True)
    data_path = output_dir / "circunscricoes_hidrograficas.geojson.gz"
    manifest_path = output_dir / "manifest.json"
    previous = json.loads(manifest_path.read_text()) if manifest_path.exists() else {}
    content = content_sha256(collection)
    if previous.get("content_sha256") == content and data_path.exists():
        # Same data as stored: keep the stored bytes and their hash untouched.
        raw = gzip.decompress(data_path.read_bytes())
    else:
        # mtime=0 keeps the gzip bytes reproducible for identical content.
        data_path.write_bytes(gzip.compress(raw, mtime=0))
    digest = hashlib.sha256(raw).hexdigest()
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
        "content_sha256": content,
        "bytes": len(raw),
        "retrieved_at": previous.get("retrieved_at") if previous.get("content_sha256") == content
        else datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
        "feature_count": len(rows),
        "districts": rows,
    }
    if previous.get("content_sha256") == content and previous.get("previous_content_sha256"):
        manifest["previous_content_sha256"] = previous["previous_content_sha256"]
    elif previous.get("content_sha256") and previous["content_sha256"] != content:
        manifest["previous_content_sha256"] = previous["content_sha256"]
    manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=1) + "\n")
    return manifest


def check(output_dir: Path, mapping_path: Path, basins_path: Path | None = None) -> dict:
    """Offline check: stored file matches its hash and the current mapping."""
    manifest = json.loads((output_dir / "manifest.json").read_text())
    raw = gzip.decompress((output_dir / "circunscricoes_hidrograficas.geojson.gz").read_bytes())
    if hashlib.sha256(raw).hexdigest() != manifest["sha256"]:
        raise ValueError("stored districts do not match the manifest SHA-256")
    if manifest.get("content_sha256") and content_sha256(json.loads(raw)) != manifest["content_sha256"]:
        raise ValueError("stored districts do not match the manifest content SHA-256")
    mapping = load_mapping(mapping_path)
    rows = validate(json.loads(raw), mapping)
    if [(r["sigla"], r["area"]) for r in rows] != [(r["sigla"], r["area"]) for r in manifest["districts"]]:
        raise ValueError("manifest districts are out of date with the area mapping; rerun the acquisition")
    if basins_path is not None:
        with basins_path.open("rb") as handle:
            known = {basin["id"] for basin in tomllib.load(handle)["basin"]}
        unknown = sorted({rule["area"] for rule in mapping} - known)
        if unknown:
            raise ValueError(f"area mapping uses areas missing from basins.toml: {unknown}")
    return manifest


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("output_dir", type=Path)
    parser.add_argument("--mapping", type=Path, required=True)
    parser.add_argument("--check", action="store_true", help="verify stored data offline instead of downloading")
    parser.add_argument("--basins", type=Path, help="basins.toml to check mapped areas against")
    args = parser.parse_args()
    manifest = check(args.output_dir, args.mapping, args.basins) if args.check else acquire(args.output_dir, args.mapping)
    print(json.dumps({key: manifest[key] for key in ("sha256", "bytes", "feature_count")}, indent=1))
    for row in manifest["districts"]:
        print(f"{row['sigla']:6} {row['area']:16} {row['geometry_type']:13} {row['nome']}")


if __name__ == "__main__":
    main()

"""Acquire and load the ANA drainage segments (Base Hidrográfica Atlas-Estudos, BHAE).

Source: ANA, BHAE v1.0, layer ``geoft_bhae_trecho_drenagem`` (GeoPackage, SIRGAS 2000,
EPSG:4674): every watercourse with 10 km² or more of drainage area and every
watercourse under federal domain, with its name (``noriocomp``) and domain
(``dedominial``). See docs/adr/0002-cobertura-nacional.md.

Subcommands:
  acquire  download the GeoPackage into the archive (content-addressed by SHA-256),
           validate it and write catalog/geo/br/bhae/manifest.json
  check    verify the archived file against the manifest, offline
  load     load the archived file into river_segments (PostGIS), once per version

The raw file is never committed: the manifest (URL, SHA-256, size, date, counts) is
the record, and the archive holds the bytes (ADR 0002, section 3).
"""

import argparse
import hashlib
import json
import os
import shutil
import subprocess
import tempfile
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

DATASET = "ana-bhae-trecho-drenagem"
PUBLISHER = "ANA"
NAME = "Base Hidrográfica Atlas-Estudos (BHAE) — trechos de drenagem"
SOURCE_URL = "https://metadados.snirh.gov.br/files/8ad07d33-1677-481d-bc61-ed5ca204926f/geoft_bhae_trecho_drenagem.gpkg"
METADATA_URL = "https://metadados.snirh.gov.br/geonetwork/srv/api/records/8ad07d33-1677-481d-bc61-ed5ca204926f"
LICENSE = "Acesso livre (metadado SNIRH/ANA); sem licença nomeada"
USER_AGENT = "fishing-regulation-api/geo-ingestion"
SOURCE_SRID = 4674
REQUIRED_FIELDS = ("cotrecho", "noriocomp", "cobacia", "cocursodag", "dedominial", "nustrahler")
# "Internacional": rivers that cross or form a border with another country. They are
# also federal property (Federal Constitution, art. 20, III), kept apart as recorded.
DOMAINS = {"Federal": "federal", "Estadual": "state", "Internacional": "international", "Linha de Costa": "coastline"}
CHUNK = 8 * 1024 * 1024


def archive_path(archive: Path, digest: str, suffix: str = ".gpkg") -> Path:
    return archive / "sha256" / digest[:2] / f"{digest}{suffix}"


def download(url: str, archive: Path) -> tuple[Path, str, int, str | None]:
    """Stream the file into the archive, hashing it on the way; returns path, hash, size, Last-Modified."""
    archive.mkdir(parents=True, exist_ok=True)
    sha = hashlib.sha256()
    size = 0
    request = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    with tempfile.NamedTemporaryFile(dir=archive, delete=False, suffix=".part") as tmp:
        try:
            with urllib.request.urlopen(request, timeout=600) as response:
                last_modified = response.headers.get("Last-Modified")
                while chunk := response.read(CHUNK):
                    sha.update(chunk)
                    tmp.write(chunk)
                    size += len(chunk)
        except BaseException:
            os.unlink(tmp.name)
            raise
    digest = sha.hexdigest()
    target = archive_path(archive, digest)
    target.parent.mkdir(parents=True, exist_ok=True)
    os.replace(tmp.name, target)
    return target, digest, size, last_modified


def sha256_of(path: Path) -> str:
    sha = hashlib.sha256()
    with path.open("rb") as handle:
        while chunk := handle.read(CHUNK):
            sha.update(chunk)
    return sha.hexdigest()


def inspect(path: Path) -> dict:
    """Validate layer, CRS and fields; count features and domains."""
    import pyogrio  # available in the geo-worker image

    layers = [name for name, _ in pyogrio.list_layers(path)]
    if len(layers) != 1:
        raise ValueError(f"expected one layer, found {layers}")
    layer = layers[0]
    info = pyogrio.read_info(path, layer=layer)
    if "4674" not in str(info.get("crs")):
        raise ValueError(f"unexpected CRS {info.get('crs')}")
    fields = [field.lower() for field in info["fields"]]
    missing = [field for field in REQUIRED_FIELDS if field not in fields]
    if missing:
        raise ValueError(f"missing fields {missing}")
    if "line" not in str(info.get("geometry_type", "")).lower():
        raise ValueError(f"unexpected geometry type {info.get('geometry_type')}")

    table = pyogrio.read_dataframe(path, layer=layer, columns=["cotrecho", "dedominial", "dsversao"], read_geometry=False)
    if table["cotrecho"].isna().any() or table["cotrecho"].duplicated().any():
        raise ValueError("cotrecho is missing or not unique")
    domains = table["dedominial"].fillna("").value_counts().to_dict()
    unknown = sorted(set(domains) - set(DOMAINS) - {""})
    if unknown:
        raise ValueError(f"unknown dedominial values {unknown}: update DOMAINS before loading")
    # dsversao names the source sheet of each segment (dozens of values): keep the most common.
    version_counts = table["dsversao"].dropna().astype(str).value_counts()
    return {
        "layer": layer,
        "geometry_type": info.get("geometry_type"),
        "feature_count": int(len(table)),
        "domains": {key or "(vazio)": int(value) for key, value in sorted(domains.items())},
        "source_versions": {"distinct": int(len(version_counts)), "most_common": {k: int(v) for k, v in version_counts.head(5).items()}},
    }


def acquire(archive: Path, manifest_dir: Path, url: str = SOURCE_URL, archived: str | None = None) -> dict:
    if archived:
        # Re-validate a file already in the archive (e.g. after a validation rule changed).
        path = archive_path(archive, archived)
        digest, size = sha256_of(path), path.stat().st_size
        head = urllib.request.Request(url, method="HEAD", headers={"User-Agent": USER_AGENT})
        try:
            with urllib.request.urlopen(head, timeout=60) as response:
                last_modified = response.headers.get("Last-Modified")
                if int(response.headers.get("Content-Length") or -1) != size:
                    last_modified = None  # the source changed since this file was archived
        except OSError:
            last_modified = None
        if digest != archived:
            raise ValueError(f"{path} does not hash to {archived}")
    else:
        path, digest, size, last_modified = download(url, archive)
    details = inspect(path)
    manifest_path = manifest_dir / "manifest.json"
    previous = json.loads(manifest_path.read_text()) if manifest_path.exists() else {}
    same = previous.get("sha256") == digest
    manifest = {
        "dataset": DATASET,
        "publisher": PUBLISHER,
        "name": NAME,
        "url": url,
        "metadata_url": METADATA_URL,
        "license": LICENSE,
        "source_srid": SOURCE_SRID,
        "sha256": digest,
        "bytes": size,
        "archive_path": str(archive_path(Path("."), digest).as_posix()),
        "source_last_modified": last_modified or (previous.get("source_last_modified") if same else None),
        "retrieved_at": previous["retrieved_at"] if same else datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
        **details,
    }
    if not same and previous.get("sha256"):
        manifest["previous_sha256"] = previous["sha256"]
    manifest_dir.mkdir(parents=True, exist_ok=True)
    manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=1) + "\n")
    return manifest


def check(archive: Path, manifest_dir: Path) -> dict:
    manifest = json.loads((manifest_dir / "manifest.json").read_text())
    path = archive / manifest["archive_path"]
    if not path.exists():
        raise FileNotFoundError(f"{path} is not in the archive; run acquire or fetch it from the archive remote")
    if sha256_of(path) != manifest["sha256"]:
        raise ValueError("archived file does not match the manifest SHA-256")
    return manifest


LOAD_SQL = """
INSERT INTO river_segments
    (dataset_version_id, source_feature_id, name, river_code, basin_code, domain, strahler, geometry, created_at, updated_at)
SELECT %(version)s, cotrecho, name, river_code, basin_code, domain, strahler, geometry, now(), now()
FROM (
    SELECT cotrecho::bigint::text AS cotrecho,
           nullif(trim(noriocomp), '') AS name,
           nullif(trim(cocursodag::text), '') AS river_code,
           nullif(trim(cobacia::text), '') AS basin_code,
           CASE dedominial WHEN 'Federal' THEN 'federal' WHEN 'Estadual' THEN 'state'
                WHEN 'Internacional' THEN 'international' WHEN 'Linha de Costa' THEN 'coastline' END AS domain,
           nustrahler::smallint AS strahler,
           ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_Transform(ST_SetSRID(geom, %(srid)s), 4326)), 2)) AS geometry
    FROM bhae_import
    WHERE geom IS NOT NULL
) AS rows
WHERE NOT ST_IsEmpty(geometry)
"""


def load(archive: Path, manifest_dir: Path) -> dict:
    import psycopg  # available in the geo-worker image; connection from PG* variables

    manifest = check(archive, manifest_dir)
    path = archive / manifest["archive_path"]
    version_label = f"{manifest['retrieved_at'][:10]}-{manifest['sha256'][:12]}"

    with psycopg.connect() as conn:
        with conn.cursor() as cur:
            cur.execute("SELECT id FROM geographic_datasets WHERE slug = %s", (DATASET,))
            row = cur.fetchone()
            dataset_id = row[0] if row else None
            if dataset_id is not None:
                cur.execute(
                    "SELECT v.id FROM geographic_dataset_versions v WHERE v.dataset_id = %s AND v.checksum_sha256 = %s"
                    " AND EXISTS (SELECT 1 FROM river_segments r WHERE r.dataset_version_id = v.id)",
                    (dataset_id, manifest["sha256"]),
                )
                loaded = cur.fetchone()
                if loaded:
                    return {"dataset_version_id": loaded[0], "created": False, "segments": 0}

    # ogr2ogr streams the GeoPackage into a staging table; PostGIS does the rest.
    if shutil.which("ogr2ogr") is None:
        raise RuntimeError("ogr2ogr not found: run this in the geo-worker container")
    subprocess.run(
        ["ogr2ogr", "-f", "PostgreSQL", "PG:", str(path), manifest["layer"],
         "-nln", "bhae_import", "-overwrite", "-lco", "GEOMETRY_NAME=geom", "-lco", "SPATIAL_INDEX=NONE",
         "-lco", "FID=ogc_fid", "-select", ",".join(REQUIRED_FIELDS), "-nlt", "PROMOTE_TO_MULTI",
         "-a_srs", f"EPSG:{SOURCE_SRID}", "-gt", "65536", "--config", "PG_USE_COPY", "YES"],
        check=True,
    )

    with psycopg.connect() as conn:
        try:
            with conn.cursor() as cur:
                cur.execute("SELECT count(*) FROM bhae_import")
                staged = cur.fetchone()[0]
                if staged != manifest["feature_count"]:
                    raise ValueError(f"staged {staged} segments, manifest says {manifest['feature_count']}")
                cur.execute(
                    "INSERT INTO geographic_datasets (slug, name, publisher, created_at, updated_at) VALUES (%s, %s, %s, now(), now())"
                    " ON CONFLICT (slug) DO UPDATE SET name = EXCLUDED.name RETURNING id",
                    (DATASET, NAME, PUBLISHER),
                )
                dataset_id = cur.fetchone()[0]
                cur.execute(
                    "INSERT INTO geographic_dataset_versions (dataset_id, version, source_url, collected_at, license, checksum_sha256, source_crs, created_at, updated_at)"
                    " VALUES (%s, %s, %s, %s, %s, %s, %s, now(), now())"
                    " ON CONFLICT (dataset_id, version) DO UPDATE SET updated_at = now() RETURNING id",
                    (dataset_id, version_label, manifest["url"], manifest["retrieved_at"], manifest["license"], manifest["sha256"], f"EPSG:{SOURCE_SRID}"),
                )
                version_id = cur.fetchone()[0]
                # One version at a time: a new version replaces the segments of the previous ones.
                cur.execute(
                    "DELETE FROM river_segments WHERE dataset_version_id IN (SELECT id FROM geographic_dataset_versions WHERE dataset_id = %s)",
                    (dataset_id,),
                )
                cur.execute(LOAD_SQL, {"version": version_id, "srid": SOURCE_SRID})
                inserted = cur.rowcount
                cur.execute("DROP TABLE bhae_import")
            conn.commit()
        except BaseException:
            conn.rollback()
            with conn.cursor() as cur:
                cur.execute("DROP TABLE IF EXISTS bhae_import")
            conn.commit()
            raise
    with psycopg.connect(autocommit=True) as conn:
        conn.execute("ANALYZE river_segments")  # fresh statistics keep the nearest-river lookup on its index
    return {"dataset_version_id": version_id, "created": True, "segments": inserted, "staged": staged}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("command", choices=["acquire", "check", "load"])
    parser.add_argument("--archive", type=Path, required=True, help="local archive directory (ADR 0002, section 3)")
    parser.add_argument("--manifest-dir", type=Path, required=True, help="catalog/geo/br/bhae")
    parser.add_argument("--archived", metavar="SHA256", help="acquire: validate this archived file instead of downloading")
    args = parser.parse_args()
    if args.command == "acquire":
        result = acquire(args.archive, args.manifest_dir, archived=args.archived)
    else:
        result = {"check": check, "load": load}[args.command](args.archive, args.manifest_dir)
    shown = {key: result[key] for key in result if key not in ("domains",)}
    print(json.dumps(shown, ensure_ascii=False, indent=1))
    if "domains" in result:
        print(json.dumps(result["domains"], ensure_ascii=False, indent=1))


if __name__ == "__main__":
    main()

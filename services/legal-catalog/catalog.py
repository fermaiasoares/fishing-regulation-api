"""Validate the legal catalog and evaluate basin-level fishing status.

This is a reference implementation for reviewing the catalog. The public API will
reimplement the same rules in Laravel; this module keeps the catalog honest
(every rule traceable to a read act) and produces the per-basin matrix.
"""

import argparse
import json
import re
import tomllib
import unicodedata
from dataclasses import dataclass, field
from datetime import date
from pathlib import Path

EFFECTS = {"prohibited", "conditional"}
SPECIES = {"native", "non_native", "all"}
SCOPES = {"basin", "buffer", "stretch"}
READ_STATUSES = {"full_text_read", "official_summary"}
MMDD = re.compile(r"^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$")


@dataclass
class Catalog:
    acts: dict
    rules: list
    basins: dict
    species_lists: list
    segments: dict = field(default_factory=dict)  # act id -> {segment id: text}
    sources: dict = field(default_factory=dict)  # act id -> manifest entry


@dataclass
class Evaluation:
    status: str
    rules: list = field(default_factory=list)
    conditions: list = field(default_factory=list)
    location_caveats: list = field(default_factory=list)
    gaps: list = field(default_factory=list)


def load(directory: Path) -> Catalog:
    def read(name):
        with (directory / name).open("rb") as handle:
            return tomllib.load(handle)

    acts = {act["id"]: act for act in read("acts.toml")["act"]}
    basins = {basin["id"]: basin for basin in read("basins.toml")["basin"]}
    sources_dir = directory / "sources"
    manifest_path = sources_dir / "manifest.json"
    sources = json.loads(manifest_path.read_text()) if manifest_path.exists() else {}
    segments = {
        act_id: {unit["id"]: unit["text"] for unit in json.loads((sources_dir / f"{act_id}.segments.json").read_text())}
        for act_id in sources
        if (sources_dir / f"{act_id}.segments.json").exists()
    }
    return Catalog(acts, read("rules.toml")["rule"], basins, read("species.toml")["list"], segments, sources)


def normalize(text: str) -> str:
    """Same canonical form as sources.normalize, kept local to avoid coupling."""
    text = unicodedata.normalize("NFKC", text).replace("\u00ad", "")
    text = re.sub(r"-\s*\n\s*", "", text)
    return re.sub(r"\s+", " ", text).strip().casefold()


def evidence_errors(catalog: Catalog, rule: dict) -> list[str]:
    where = f"rule {rule.get('id')}"
    act_id = rule.get("act")
    evidence = rule.get("evidence", [])
    if act_id not in catalog.segments:
        return [f"{where}: evidence given but {act_id} has no stored text"] if evidence else []
    if not evidence:
        return [f"{where}: {act_id} text is stored, so the rule needs evidence"]
    errors = []
    for item in evidence:
        text = catalog.segments[act_id].get(item.get("segment"))
        if text is None:
            errors.append(f"{where}: segment {item.get('segment')} not found in {act_id}")
        elif not item.get("quote") or normalize(item["quote"]) not in normalize(text):
            errors.append(f"{where}: quote not found in {act_id} {item['segment']}: {item.get('quote', '')[:60]!r}")
    return errors


def is_verified(catalog: Catalog, rule: dict) -> bool:
    return rule.get("act") in catalog.segments and bool(rule.get("evidence")) and not evidence_errors(catalog, rule)


def validate(catalog: Catalog) -> list[str]:
    """Return every problem found; an empty list means the catalog is consistent."""
    errors = []
    ids = [rule["id"] for rule in catalog.rules]
    errors += [f"duplicate rule id: {rule_id}" for rule_id in sorted({i for i in ids if ids.count(i) > 1})]

    for basin in catalog.basins.values():
        parent = basin.get("parent")
        if parent is not None and parent not in catalog.basins:
            errors.append(f"basin {basin['id']}: unknown parent {parent}")

    for act in catalog.acts.values():
        if act.get("text_status") not in READ_STATUSES | {"not_read"}:
            errors.append(f"act {act['id']}: invalid text_status")

    for rule in catalog.rules:
        where = f"rule {rule.get('id')}"
        act = catalog.acts.get(rule.get("act"))
        if act is None:
            errors.append(f"{where}: unknown act {rule.get('act')}")
        else:
            if act.get("status") == "revoked":
                errors.append(f"{where}: act {act['id']} is revoked")
            if act.get("text_status") not in READ_STATUSES:
                errors.append(f"{where}: act {act['id']} was not read")
            if not (act.get("official_url") or act.get("read_from")):
                errors.append(f"{where}: act {act['id']} has no source URL")
        if rule.get("effect") not in EFFECTS:
            errors.append(f"{where}: effect must be one of {sorted(EFFECTS)}")
        if rule.get("species") not in SPECIES:
            errors.append(f"{where}: invalid species scope")
        if rule.get("scope") not in SCOPES:
            errors.append(f"{where}: invalid scope")
        if rule.get("scope") in {"buffer", "stretch"} and rule.get("geo_mapping") != "pending":
            errors.append(f"{where}: location rules need geo_mapping = pending until mapped")
        if rule.get("scope") == "stretch" and not rule.get("stretches"):
            errors.append(f"{where}: stretch rule without stretches")
        if rule.get("effect") == "conditional" and not rule.get("conditions"):
            errors.append(f"{where}: conditional rule without conditions")
        if not rule.get("basins") or any(b not in catalog.basins for b in rule.get("basins", [])):
            errors.append(f"{where}: unknown or missing basin")
        if not rule.get("summary") or not rule.get("articles"):
            errors.append(f"{where}: summary and articles are required")
        errors += evidence_errors(catalog, rule)
        period = rule.get("period", {})
        if period.get("kind") == "annual":
            if not (MMDD.match(period.get("start", "")) and MMDD.match(period.get("end", ""))):
                errors.append(f"{where}: annual period needs start/end as MM-DD")
        elif period.get("kind") != "permanent":
            errors.append(f"{where}: period.kind must be annual or permanent")
    return errors


def in_period(period: dict, day: date) -> bool:
    if period["kind"] == "permanent":
        return True
    key = (day.month, day.day)
    start = tuple(int(part) for part in period["start"].split("-"))
    end = tuple(int(part) for part in period["end"].split("-"))
    # Conservative choice, pending approval: a period written as ending on
    # 28 February also covers 29 February in leap years (2023/2024 notices
    # announced the piracema until 29/02/2024).
    if end == (2, 28):
        end = (2, 29)
    if start <= end:
        return start <= key <= end
    return key >= start or key <= end


def lineage(catalog: Catalog, basin_id: str) -> list[str]:
    chain = []
    while basin_id is not None:
        if basin_id in chain or basin_id not in catalog.basins:
            raise ValueError(f"invalid basin lineage at {basin_id}")
        chain.append(basin_id)
        basin_id = catalog.basins[basin_id].get("parent")
    return chain


def evaluate(catalog: Catalog, basin_id: str, day: date, species: str) -> Evaluation:
    """Basin-level status for a native or non-native species on a given day.

    Never returns "allowed": the catalog only records restrictions, so the absence
    of a restriction is reported as "undetermined".
    """
    if species not in {"native", "non_native"}:
        raise ValueError("species must be native or non_native")
    chain = set(lineage(catalog, basin_id))
    active = [
        rule for rule in catalog.rules
        if chain.intersection(rule["basins"]) and in_period(rule["period"], day)
        and rule["species"] in {species, "all"}
    ]
    basin_rules = [rule for rule in active if rule["scope"] == "basin"]
    result = Evaluation(status="undetermined")
    result.location_caveats = [rule["id"] for rule in active if rule["scope"] != "basin"]

    prohibited = [rule for rule in basin_rules if rule["effect"] == "prohibited"]
    conditional = [rule for rule in basin_rules if rule["effect"] == "conditional"]
    if prohibited:
        result.status = "prohibited"
        result.rules = [rule["id"] for rule in prohibited]
    elif conditional:
        result.status = "conditional"
        result.rules = [rule["id"] for rule in conditional]
        result.conditions = [c for rule in conditional for c in rule["conditions"]]
    else:
        result.gaps.append("nenhuma regra de bacia carregada para este caso")

    for rule_id in result.rules:
        rule = next(r for r in catalog.rules if r["id"] == rule_id)
        if not is_verified(catalog, rule):
            result.gaps.append(f"{rule_id}: sem trecho literal conferido no texto armazenado")
    if species == "non_native":
        result.gaps.append("classificação da espécie como não nativa precisa constar de lista oficial da bacia")
    return result


MATRIX_BASINS = ["sao_francisco", "paraopeba", "grande", "paranaiba", "leste", "doce"]
MATRIX_DAYS = {"defeso (15/12/2026)": date(2026, 12, 15), "fora do defeso (15/06/2027)": date(2027, 6, 15)}


def matrix_markdown(catalog: Catalog) -> str:
    lines = [
        "| Bacia | Período | Nativas | Não nativas | Restrições por local (pendentes de mapa) |",
        "| --- | --- | --- | --- | --- |",
    ]
    for basin_id in MATRIX_BASINS:
        name = catalog.basins[basin_id]["name"]
        for label, day in MATRIX_DAYS.items():
            native = evaluate(catalog, basin_id, day, "native")
            other = evaluate(catalog, basin_id, day, "non_native")
            cell = lambda e: f"`{e.status}`" + (f" ({', '.join(e.rules)})" if e.rules else "")
            caveats = sorted(set(native.location_caveats) | set(other.location_caveats))
            lines.append(f"| {name} | {label} | {cell(native)} | {cell(other)} | {', '.join(caveats) or '—'} |")
    return "\n".join(lines)


EXPORT_FORMAT = "fishing-regulation-legal-catalog/1"


def export_json(catalog: Catalog, jurisdiction: str) -> str:
    """Canonical JSON consumed by the Laravel importer (`php artisan legal:import`)."""
    document = {
        "format": EXPORT_FORMAT,
        "jurisdiction": jurisdiction,
        "acts": sorted(catalog.acts.values(), key=lambda act: act["id"]),
        "areas": sorted(catalog.basins.values(), key=lambda basin: basin["id"]),
        "rules": sorted(catalog.rules, key=lambda rule: rule["id"]),
        "species_lists": sorted(catalog.species_lists, key=lambda item: item["id"]),
        "sources": dict(sorted(catalog.sources.items())),
        "verified_rules": sorted(rule["id"] for rule in catalog.rules if is_verified(catalog, rule)),
    }
    return json.dumps(document, ensure_ascii=False, indent=1, sort_keys=True) + "\n"


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("directory", type=Path, help="catalog directory, e.g. catalog/legal/mg")
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--matrix", action="store_true", help="print the per-basin matrix")
    mode.add_argument("--export", type=Path, help="write the canonical JSON export to this path")
    mode.add_argument("--check-export", type=Path, help="fail if this JSON export is out of date")
    args = parser.parse_args()
    catalog = load(args.directory)
    errors = validate(catalog)
    if errors:
        parser.exit(1, "\n".join(errors) + "\n")
    if args.matrix:
        print(matrix_markdown(catalog))
        return
    exported = export_json(catalog, args.directory.name)
    if args.export:
        args.export.write_text(exported)
    if args.check_export:
        if not args.check_export.exists() or args.check_export.read_text() != exported:
            parser.exit(1, f"{args.check_export} is out of date; run catalog.py --export\n")
    print(f"OK: {len(catalog.acts)} atos, {len(catalog.rules)} regras")


if __name__ == "__main__":
    main()

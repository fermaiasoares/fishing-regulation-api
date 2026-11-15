"""Extract Annex I of Portaria GM/MMA 1.667/2026 (threatened aquatic species).

Reads the official PDF stored by sources.py and writes one CSV row per listed
species. The table is read with pdfplumber, whose cell boundaries keep genus
and epithet together (plain text extraction splits them across columns).
``--check`` regenerates the CSV in memory and fails if the stored one differs,
so the CSV can never drift from the PDF.

The annex marks some rows with "*" but has no legend; the mark is kept as
``marked`` and is not interpreted.
"""

import argparse
import csv
import hashlib
import io
import json
import re
from pathlib import Path

ACT_ID = "mma-1667-2026"
COLUMNS = ["row", "marked", "group", "class", "order", "family", "species", "category"]
CATEGORIES = {"EW", "CR", "EN", "VU"}
EXPECTED_ROWS = 490


def clean(cell: str | None) -> str:
    return re.sub(r"\s+", " ", cell or "").strip()


def extract(pdf_path: Path) -> list[dict]:
    import pdfplumber  # imported lazily so the rest of the catalog tooling needs only stdlib

    rows = []
    with pdfplumber.open(pdf_path) as pdf:
        for page in pdf.pages:
            for table in page.extract_tables():
                for cells in table:
                    if not cells or not clean(cells[0]).isdigit():
                        continue  # header rows
                    values = [clean(cell) for cell in cells]
                    if len(values) != len(COLUMNS):
                        raise ValueError(f"unexpected column count in row {values[0]}: {values}")
                    row = dict(zip(COLUMNS, values))
                    row["marked"] = "yes" if row["marked"] == "*" else "no"
                    rows.append(row)
    numbers = [int(row["row"]) for row in rows]
    if numbers != list(range(1, EXPECTED_ROWS + 1)):
        raise ValueError(f"expected rows 1..{EXPECTED_ROWS}, got {len(numbers)} rows")
    bad = [row["row"] for row in rows if row["category"] not in CATEGORIES or not re.match(r"^[A-Z][a-z]+ [a-z]", row["species"])]
    if bad:
        raise ValueError(f"rows with unexpected species or category: {bad[:10]}")
    return rows


def to_csv(rows: list[dict]) -> str:
    buffer = io.StringIO()
    writer = csv.DictWriter(buffer, fieldnames=COLUMNS, lineterminator="\n")
    writer.writeheader()
    writer.writerows(rows)
    return buffer.getvalue()


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("catalog_dir", type=Path)
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    sources = args.catalog_dir / "sources"
    manifest = json.loads((sources / "manifest.json").read_text())[ACT_ID]
    pdf_path = sources / f"{ACT_ID}.pdf"
    if hashlib.sha256(pdf_path.read_bytes()).hexdigest() != manifest["sha256"]:
        raise SystemExit(f"{pdf_path} does not match the manifest SHA-256")
    output = sources / f"{ACT_ID}.annex.csv"
    generated = to_csv(extract(pdf_path))
    if args.check:
        if not output.exists() or output.read_text() != generated:
            raise SystemExit(f"{output} is out of date; run annex_1667.py without --check")
        print(f"OK: {output.name} matches the PDF")
        return
    output.write_text(generated)
    print(f"wrote {output} ({generated.count(chr(10)) - 1} rows)")


if __name__ == "__main__":
    main()

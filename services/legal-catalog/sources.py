"""Download official legal texts, extract their text and split it into articles.

For every act with ``download_urls`` in acts.toml, the first URL that answers is
stored under ``<catalog>/sources/``. The manifest records the URL actually used,
its SHA-256, size and retrieval time, so every citation can be traced back to the
exact bytes it was checked against. Nothing is overwritten silently: a changed
hash for an already stored act is reported as a new revision in the manifest.
"""

import argparse
import hashlib
import html.parser
import json
import re
import subprocess
import tomllib
import unicodedata
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

USER_AGENT = "fishing-regulation-api/legal-sources (+https://github.com/fermaiasoares/fishing-regulation-api)"
TIMEOUT_SECONDS = 120
EXTRACTOR = "legal-sources/3"

# Case-sensitive on purpose: a wrapped line starting with "art. 4º da Lei..." is a
# reference inside a sentence, not a new article.
ARTICLE = re.compile(r"^\s*Art(?:igo)?\.?\s*(\d+)\s*(?:º|°|o)?\.?\s*(?:[-–—.:]\s*)?(?=\S)")
PARAGRAPH = re.compile(r"^\s*(?:§\s*(\d+)\s*(?:º|°|o)?|Par[áa]grafo\s+[úÚuU]nico)\s*[-–—.:]?\s*")
RUNNING_HEADER_MIN_REPEATS = 3
INCISO = re.compile(r"^\s*([IVXLC]+)\s*[-–—]\s+")
ALINEA = re.compile(r"^\s*([a-z])\s*\)\s+")


def normalize(text: str) -> str:
    """Canonical form used to compare citations with source text."""
    text = unicodedata.normalize("NFKC", text)
    text = text.replace("­", "")
    text = re.sub(r"-\s*\n\s*", "", text)  # words broken across lines
    return re.sub(r"\s+", " ", text).strip().casefold()


def strip_running_headers(text: str) -> str:
    """Drop page headers/footers: identical lines repeated on many pages.

    Lines that open a legal unit are never dropped, even if repeated.
    """
    lines = text.splitlines()
    counts: dict[str, int] = {}
    for line in lines:
        if line.strip():
            counts[line.strip()] = counts.get(line.strip(), 0) + 1
    def is_unit(line: str) -> bool:
        return any(pattern.match(line) for pattern in (ARTICLE, PARAGRAPH, INCISO, ALINEA))
    return "\n".join(
        line for line in lines
        if not (line.strip() and counts[line.strip()] >= RUNNING_HEADER_MIN_REPEATS and not is_unit(line))
    )


def segment(text: str) -> list[dict]:
    """Split a legal text into article, paragraph, inciso and alínea units.

    Each unit keeps its own text only; the parent's text is not repeated. Ids are
    stable paths such as ``art2/III/g`` or ``art1/par-unico``.
    """
    units: list[dict] = []
    article = inciso = paragraph = None
    current: dict | None = None

    def open_unit(unit_id: str, label: str, first_line: str) -> dict:
        unit = {"id": unit_id, "label": label, "lines": [first_line] if first_line else []}
        units.append(unit)
        return unit

    for raw in text.splitlines():
        line = raw.rstrip()
        if not line.strip():
            continue
        if match := ARTICLE.match(line):
            article, inciso, paragraph = f"art{int(match.group(1))}", None, None
            current = open_unit(article, f"Art. {int(match.group(1))}", line[match.end():].strip())
            continue
        if article is None:
            current = current or open_unit("preamble", "Preâmbulo", "")
            current["lines"].append(line.strip())
            continue
        if match := PARAGRAPH.match(line):
            number = match.group(1)
            paragraph = f"par{int(number)}" if number else "par-unico"
            inciso = None
            label = f"§ {int(number)}" if number else "Parágrafo único"
            current = open_unit(f"{article}/{paragraph}", label, line[match.end():].strip())
            continue
        if match := INCISO.match(line):
            inciso = match.group(1)
            base = f"{article}/{paragraph}" if paragraph else article
            current = open_unit(f"{base}/{inciso}", inciso, line[match.end():].strip())
            continue
        if (inciso or paragraph) and (match := ALINEA.match(line)):
            base = f"{article}/{paragraph}" if paragraph else article
            parent = f"{base}/{inciso}" if inciso else base
            current = open_unit(f"{parent}/{match.group(1)}", f"{match.group(1)})", line[match.end():].strip())
            continue
        current["lines"].append(line.strip())

    seen: dict[str, int] = {}
    result = []
    for unit in units:
        # Duplicate ids happen when a numbering restarts (e.g. annexes); keep them distinct.
        count = seen.get(unit["id"], 0)
        seen[unit["id"]] = count + 1
        unit_id = unit["id"] if count == 0 else f"{unit['id']}#{count + 1}"
        result.append({"id": unit_id, "label": unit["label"], "text": re.sub(r"\s+", " ", " ".join(unit["lines"])).strip()})
    return result


class _TextExtractor(html.parser.HTMLParser):
    BLOCK = {"p", "div", "br", "li", "tr", "h1", "h2", "h3", "h4", "h5", "h6", "section", "article"}
    SKIP = {"script", "style", "nav", "header", "footer", "noscript"}

    def __init__(self):
        super().__init__()
        self.parts: list[str] = []
        self.skip_depth = 0

    def handle_starttag(self, tag, attrs):
        if tag in self.SKIP:
            self.skip_depth += 1
        elif tag in self.BLOCK:
            self.parts.append("\n")

    def handle_endtag(self, tag):
        if tag in self.SKIP and self.skip_depth:
            self.skip_depth -= 1
        elif tag in self.BLOCK:
            self.parts.append("\n")

    def handle_data(self, data):
        if not self.skip_depth:
            self.parts.append(data)


CHARSET = re.compile(rb"""<meta[^>]+charset=["']?([A-Za-z0-9_-]+)""", re.IGNORECASE)


def decode_html(content: bytes) -> str:
    """Declared charset first; otherwise UTF-8, falling back to Windows-1252
    (many official Brazilian pages are Latin-1 without declaring it)."""
    declared = CHARSET.search(content[:4096])
    if declared:
        try:
            return content.decode(declared.group(1).decode("ascii"))
        except (LookupError, UnicodeDecodeError):
            pass
    try:
        return content.decode("utf-8")
    except UnicodeDecodeError:
        return content.decode("cp1252", errors="replace")


def html_to_text(content: bytes) -> str:
    parser = _TextExtractor()
    parser.feed(decode_html(content))
    lines = (re.sub(r"[ \t]+", " ", line).strip() for line in "".join(parser.parts).splitlines())
    return "\n".join(line for line in lines if line)


def pdf_to_text(path: Path) -> str:
    completed = subprocess.run(["pdftotext", "-enc", "UTF-8", str(path), "-"], capture_output=True, check=True)
    return completed.stdout.decode("utf-8")


def download(url: str) -> tuple[bytes, str]:
    request = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(request, timeout=TIMEOUT_SECONDS) as response:
        return response.read(), response.headers.get_content_type()


def detect_kind(content: bytes, content_type: str) -> str:
    if content.startswith(b"%PDF"):
        return "pdf"
    if "html" in content_type or content.lstrip()[:15].lower().startswith((b"<!doctype", b"<html")):
        return "html"
    raise ValueError(f"unsupported content type {content_type}")


def acquire(catalog_dir: Path) -> dict:
    with (catalog_dir / "acts.toml").open("rb") as handle:
        acts = tomllib.load(handle)["act"]
    sources_dir = catalog_dir / "sources"
    sources_dir.mkdir(exist_ok=True)
    manifest_path = sources_dir / "manifest.json"
    manifest = json.loads(manifest_path.read_text()) if manifest_path.exists() else {}
    report = {}

    for act in acts:
        urls = act.get("download_urls", [])
        if not urls:
            continue
        errors = []
        for url in urls:
            try:
                content, content_type = download(url)
                kind = detect_kind(content, content_type)
            except Exception as error:  # network, HTTP and content errors are all recorded
                errors.append(f"{url}: {type(error).__name__}: {error}")
                continue
            digest = hashlib.sha256(content).hexdigest()
            raw_path = sources_dir / f"{act['id']}.{kind}"
            raw_path.write_bytes(content)
            text = extract(raw_path)
            (sources_dir / f"{act['id']}.txt").write_text(text)
            segments = segment(text)
            (sources_dir / f"{act['id']}.segments.json").write_text(json.dumps(segments, ensure_ascii=False, indent=1) + "\n")
            previous = manifest.get(act["id"])
            entry = {
                "url": url,
                "official": url == act.get("official_url") or url in act.get("official_urls", []),
                "sha256": digest,
                "bytes": len(content),
                "kind": kind,
                "retrieved_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
                "extractor": EXTRACTOR,
                "segments": len(segments),
                "text_chars": len(text),
            }
            if previous and previous["sha256"] == digest:
                entry["retrieved_at"] = previous["retrieved_at"]
            elif previous:
                entry["previous_sha256"] = previous["sha256"]
            manifest[act["id"]] = entry
            report[act["id"]] = {"status": "ok", **entry, "failed_urls": errors}
            break
        else:
            report[act["id"]] = {"status": "failed", "failed_urls": errors}

    manifest_path.write_text(json.dumps(dict(sorted(manifest.items())), ensure_ascii=False, indent=1) + "\n")
    return report


def extract(raw_path: Path) -> str:
    content = raw_path.read_bytes()
    text = pdf_to_text(raw_path) if raw_path.suffix == ".pdf" else html_to_text(content)
    return strip_running_headers(text)


def reextract(catalog_dir: Path) -> dict:
    """Rebuild text and segments from the stored raw files, without network access."""
    sources_dir = catalog_dir / "sources"
    manifest_path = sources_dir / "manifest.json"
    manifest = json.loads(manifest_path.read_text())
    for act_id, entry in manifest.items():
        raw_path = sources_dir / f"{act_id}.{entry['kind']}"
        if hashlib.sha256(raw_path.read_bytes()).hexdigest() != entry["sha256"]:
            raise ValueError(f"{raw_path} does not match the SHA-256 in the manifest")
        text = extract(raw_path)
        segments = segment(text)
        (sources_dir / f"{act_id}.txt").write_text(text)
        (sources_dir / f"{act_id}.segments.json").write_text(json.dumps(segments, ensure_ascii=False, indent=1) + "\n")
        entry.update({"extractor": EXTRACTOR, "segments": len(segments), "text_chars": len(text)})
    manifest_path.write_text(json.dumps(dict(sorted(manifest.items())), ensure_ascii=False, indent=1) + "\n")
    return manifest


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("catalog_dir", type=Path)
    parser.add_argument("--reextract", action="store_true", help="rebuild texts from stored raw files only")
    args = parser.parse_args()
    report = reextract(args.catalog_dir) if args.reextract else acquire(args.catalog_dir)
    print(json.dumps(report, ensure_ascii=False, indent=1))


if __name__ == "__main__":
    main()

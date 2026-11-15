#!/usr/bin/env python3
"""Drive POST /api/fishing/evaluate on the running local API.

  evaluate.py                         run the real-world scenarios and compare with the expected status
  evaluate.py LAT LON SPECIES [DATE]  one query, full JSON response
  evaluate.py --town NAME SPECIES [DATE]

Needs the stack up and the real data imported (stack.sh up && stack.sh import).
Uses only the standard library. API_URL overrides http://127.0.0.1:8000.
"""
import json
import os
import sys
import urllib.error
import urllib.request

BASE = os.environ.get("API_URL", "http://127.0.0.1:8000")

# Coordinates from apps/api/tests/Feature/LegalAreaImportTest.php (plus Extrema for PJ1).
TOWNS = {
    "caratinga": (-19.7895, -42.1393),       # mg:doce
    "brumadinho": (-20.1430, -44.2000),      # mg:paraopeba
    "bh": (-19.9167, -43.9345),              # mg:sao_francisco
    "montes-claros": (-16.7350, -43.8617),   # mg:sao_francisco
    "uberaba": (-19.7472, -47.9381),         # mg:grande
    "patos-de-minas": (-18.5789, -46.5181),  # mg:paranaiba
    "teofilo-otoni": (-17.8575, -41.5053),   # mg:leste
    "juiz-de-fora": (-21.7642, -43.3496),    # mg:leste
    "extrema": (-22.8547, -46.3178),         # mg:piracicaba_jaguari (no rules)
    "sao-paulo": (-23.5505, -46.6333),       # outside every mapped area
}

# (town, species, date, expected status, why). Statuses observed on 2026-11-15 with
# catalogs br/mg at master dc4a003; "gap" marks answers limited by known catalog gaps.
SCENARIOS = [
    ("caratinga", "Prochilodus vimboides", "2027-06-15", "prohibited", "VU nacional + nativas do Doce o ano todo"),
    ("caratinga", "tilápia", "2026-12-15", "conditional", "não nativa no Doce na piracema"),
    ("bh", "tilápia", "2026-12-15", "conditional", "não nativa no SF na piracema"),
    ("bh", "tambaqui", "2026-12-15", "prohibited", "VU nacional vence a permissão da IEF 154"),
    ("uberaba", "tambaqui", "2026-06-01", "undetermined", "antes da vigência da 1.666 (27/10/2026)"),
    ("patos-de-minas", "piauçu", "2026-12-15", "prohibited", "excluído da permissão da IEF 156"),
    ("teofilo-otoni", "Steindachneridion doceanum", "2027-06-15", "prohibited", "EN nacional"),
    ("montes-claros", "Pseudoplatystoma corruscans", "2027-06-15", "prohibited", "surubim VU pelo nome científico"),
    ("juiz-de-fora", "tucunaré", "2026-12-15", "conditional", "Cichla spp. no Leste na piracema"),
    ("extrema", "dourado", "2026-12-15", "undetermined", "PJ1 sem regras (decisão pendente)"),
    ("sao-paulo", "dourado", "2026-12-15", "undetermined", "fora do mapa"),
    ("bh", "peixe-inventado", "2026-12-15", "undetermined", "espécie desconhecida"),
    # Known gaps: no official native lists for SF/Grande/Paranaíba/Leste, and threatened
    # species only match common names that appear in a state list.
    ("bh", "dourado", "2026-12-15", "undetermined", "gap: sem lista de nativas do SF"),
    ("montes-claros", "surubim", "2027-06-15", "undetermined", "gap: 'surubim' não está em lista estadual"),
    ("uberaba", "tambaqui", "2026-12-15", "undetermined", "gap: tambaqui fora da lista da IEF 156"),
]

INVALID = [
    ({}, "campos obrigatórios"),
    ({"latitude": 91, "longitude": -43.9, "species": "tilapia"}, "latitude fora de WGS 84"),
    ({"latitude": -19.9, "longitude": -43.9, "species": "tilapia", "date": "2026-02-30"}, "data inexistente"),
]


def post(body):
    req = urllib.request.Request(
        f"{BASE}/api/fishing/evaluate", data=json.dumps(body).encode(), method="POST",
        headers={"Content-Type": "application/json", "Accept": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return resp.status, json.load(resp)
    except urllib.error.HTTPError as err:
        return err.code, json.load(err)


def summary(code, r):
    sp = r.get("species") or {}
    lines = [
        "      areas=" + (",".join(a["code"] for a in (r.get("location") or {}).get("areas", [])) or "-")
        + f" class={sp.get('classification')}"
        + " threatened=" + (",".join(f"{t['name']} {t['category']}" for t in sp.get("threatened", [])) or "-"),
        "      rules=" + (",".join(f"{x['jurisdiction']}:{x['code']}" for x in r.get("rules", [])) or "-")
        + f" conditions={len(r.get('conditions', []))} location_restrictions={len(r.get('location_restrictions', []))}",
    ]
    return "\n".join(lines)


def run_scenarios():
    failures = 0
    for town, species, date, expected, why in SCENARIOS:
        lat, lon = TOWNS[town]
        code, r = post({"latitude": lat, "longitude": lon, "species": species, "date": date})
        status = r.get("status")
        ok = code == 200 and status == expected
        failures += not ok
        print(f"[{'OK' if ok else 'XX'}] {code} {str(status):<12} {town} | {species} | {date}  ({why}; esperado {expected})")
        print(summary(code, r))
    for body, why in INVALID:
        code, _ = post(body)
        ok = code == 422
        failures += not ok
        print(f"[{'OK' if ok else 'XX'}] {code} inválido: {why}")
    print(f"\n{failures} divergência(s)")
    return 1 if failures else 0


def main(argv):
    if not argv:
        return run_scenarios()
    if argv[0] == "--town":
        lat, lon = TOWNS[argv[1]]
        rest = argv[2:]
    else:
        lat, lon = float(argv[0]), float(argv[1])
        rest = argv[2:]
    body = {"latitude": lat, "longitude": lon, "species": rest[0]}
    if len(rest) > 1:
        body["date"] = rest[1]
    code, r = post(body)
    print(code)
    print(json.dumps(r, ensure_ascii=False, indent=2))
    return 0 if code == 200 else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))

// Client for POST /api/fishing/evaluate (docs/api.md). The API never answers
// "allowed": only prohibited, conditional or undetermined.

export type Status = 'prohibited' | 'conditional' | 'undetermined';

export interface Evidence { segment: string; quote: string }

export interface Rule {
  code: string;
  jurisdiction: string;
  effect: 'prohibited' | 'conditional';
  summary: string;
  act: { code: string; title: string; kind: string; issuer: string; published_on: string | null; url: string | null };
  articles: string;
  citation: string;
  period: { kind: 'permanent' } | { kind: 'annual'; start: string; end: string };
  species_scope: string;
  verified: boolean;
  evidence: Evidence[];
  geo_mapping?: string;
}

export interface Evaluation {
  status: Status;
  location: {
    latitude: number;
    longitude: number;
    geographic_match: boolean;
    states: string[];
    jurisdictions: string[];
    water_domain: string;
    districts: { code: string; name: string; area: string; source: string }[];
    nearest_river: { name: string | null; code: string | null; domain: string | null; segment: string; distance_m: number; source: string } | null;
    areas: { code: string; name: string; jurisdiction: string }[];
  };
  species: {
    input: string;
    classification: string;
    threatened: { category?: string }[];
  };
  rules: Rule[];
  conditions: { text: string; rule: string; jurisdiction: string }[];
  location_restrictions: Rule[];
  gaps: string[];
  catalogs: { jurisdiction: string; version_id: number; checksum_sha256: string; published_at: string }[];
  disclaimer: string;
}

export interface Query { latitude: number; longitude: number; species: string; date: string }

export interface Result { evaluation: Evaluation; savedAt: string; offline: boolean }

export class ApiError extends Error {
  constructor(message: string, readonly fields: Record<string, string[]> = {}) {
    super(message);
  }
}

const BASE = (import.meta.env.VITE_API_URL as string | undefined)?.replace(/\/$/, '') ?? '';
const CACHE_KEY = 'evaluations.v1';
const CACHE_LIMIT = 30;

// Rivers often have no signal: the last answers are kept on the device and shown,
// clearly marked, when the network fails.
function cacheKey(q: Query): string {
  return [q.latitude.toFixed(4), q.longitude.toFixed(4), q.species.trim().toLowerCase(), q.date].join('|');
}

function readCache(): Record<string, { evaluation: Evaluation; savedAt: string }> {
  try {
    return JSON.parse(localStorage.getItem(CACHE_KEY) ?? '{}');
  } catch {
    return {};
  }
}

function writeCache(q: Query, evaluation: Evaluation, savedAt: string): void {
  try {
    const all = readCache();
    all[cacheKey(q)] = { evaluation, savedAt };
    const keys = Object.keys(all).sort((a, b) => all[b].savedAt.localeCompare(all[a].savedAt));
    for (const key of keys.slice(CACHE_LIMIT)) delete all[key];
    localStorage.setItem(CACHE_KEY, JSON.stringify(all));
  } catch {
    // Storage full or blocked: the answer is still shown, just not kept.
  }
}

export async function evaluate(q: Query): Promise<Result> {
  let response: Response;
  try {
    response = await fetch(`${BASE}/api/fishing/evaluate`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(q),
    });
  } catch {
    const cached = readCache()[cacheKey(q)];
    if (cached) return { ...cached, offline: true };
    throw new ApiError('Sem conexão e sem consulta salva para este ponto, espécie e data.');
  }

  if (response.status === 422) {
    const body = await response.json().catch(() => ({}));
    throw new ApiError('Confira os dados informados.', body.errors ?? {});
  }
  if (response.status === 429) throw new ApiError('Muitas consultas seguidas. Aguarde um minuto.');
  if (response.status === 503) throw new ApiError('Serviço indisponível: o catálogo de normas não está publicado.');
  if (!response.ok) throw new ApiError(`Erro inesperado da API (HTTP ${response.status}).`);

  const evaluation = (await response.json()) as Evaluation;
  const savedAt = new Date().toISOString();
  writeCache(q, evaluation, savedAt);
  return { evaluation, savedAt, offline: false };
}

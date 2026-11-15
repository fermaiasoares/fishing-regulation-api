import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import './style.css';
import { ApiError, evaluate, type Evaluation, type Query, type Result, type Rule, type Status } from './api';

// Test points: one per legal area loaded today, plus the PJ1 district with no rules.
const PRESETS = [
  { name: 'Belo Horizonte', lat: -19.92, lng: -43.94 },
  { name: 'Três Marias', lat: -18.2, lng: -45.24 },
  { name: 'Uberlândia', lat: -18.92, lng: -48.28 },
  { name: 'Gov. Valadares', lat: -18.85, lng: -41.95 },
  { name: 'Juiz de Fora', lat: -21.76, lng: -43.35 },
  { name: 'Brumadinho', lat: -20.14, lng: -44.2 },
  { name: 'Teófilo Otoni', lat: -17.86, lng: -41.5 },
  { name: 'Extrema', lat: -22.85, lng: -46.32 },
];

// Names the catalog recognizes, plus native ones it does not list yet (they come back "undetermined").
const SPECIES = ['tilápia', 'tucunaré', 'pacu', 'black bass', 'bagre africano', 'tambaqui', 'dourado', 'surubim', 'traíra', 'curimatã', 'piau', 'lambari'];

const STATUS_TEXT: Record<Status, { verdict: string; short: string }> = {
  prohibited: { verdict: 'Pesca proibida nesta data', short: 'Proibida' },
  conditional: { verdict: 'Permitida só com condições', short: 'Com condições' },
  undetermined: { verdict: 'Sem regra confirmada', short: 'Sem confirmação' },
};

const CLASSIFICATION: Record<string, string> = {
  native: 'Nativa da bacia',
  non_native: 'Não nativa na bacia',
  excluded: 'Excluída da permissão',
  conflicting: 'Classificação conflitante nas normas',
  unknown: 'Fora das listas oficiais carregadas',
};

const ICONS: Record<Status, string> = {
  prohibited: '<circle cx="12" cy="12" r="9"/><path d="M6 6l12 12"/>',
  conditional: '<path d="M9 11l3 3 8-8"/><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9"/>',
  undetermined: '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6V14M12 17v.5"/>',
};

const $ = <T extends HTMLElement>(id: string) => document.getElementById(id) as T;

function esc(value: unknown): string {
  return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
}

function icon(paths: string, size = 26): string {
  return `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths}</svg>`;
}

function today(): string {
  // The API's default is today in America/Sao_Paulo; send the same day explicitly.
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Sao_Paulo' }).format(new Date());
}

function formatDate(iso: string): string {
  const [y, m, d] = iso.slice(0, 10).split('-');
  return `${d}/${m}/${y}`;
}

function formatPeriod(rule: Rule): string {
  if (rule.period.kind === 'permanent') return 'o ano todo';
  const md = (v: string) => v.split('-').reverse().join('/');
  return `de ${md(rule.period.start)} a ${md(rule.period.end)}`;
}

// --- Map -------------------------------------------------------------------

let point: { lat: number; lng: number; label: string } | null = null;
let last: { query: Query; result: Result } | null = null;

const map = L.map('map', { zoomControl: false }).setView([-18.6, -44.6], 6);
L.control.zoom({ position: 'topright' }).addTo(map);
L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
  maxZoom: 18,
  attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
}).addTo(map);

const pin = L.divIcon({
  className: '',
  html: `<div style="width:44px;height:44px;border-radius:50%;background:#0B2F45;border:4px solid #FF8A3D;color:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 6px 16px rgba(11,47,69,.3)">${icon('<path d="M3 12c3-5 8-6 12-3l5-3v12l-5-3c-4 3-9 2-12-3z"/><circle cx="8" cy="11" r=".8"/>', 22)}</div>`,
  iconSize: [44, 44],
  iconAnchor: [22, 22],
});
let marker: L.Marker | null = null;

function setPoint(lat: number, lng: number, label: string, fly = false): void {
  point = { lat: Number(lat.toFixed(5)), lng: Number(lng.toFixed(5)), label };
  if (marker) marker.setLatLng([lat, lng]);
  else marker = L.marker([lat, lng], { icon: pin, keyboard: false }).addTo(map);
  if (fly) map.flyTo([lat, lng], 10, { duration: 0.6 });
  $('point-label').textContent = `${label} · ${point.lat.toFixed(4)}, ${point.lng.toFixed(4)}`;
  $('point-error').textContent = '';
  document.querySelectorAll<HTMLButtonElement>('#presets .chip').forEach((chip) => {
    chip.setAttribute('aria-pressed', String(chip.dataset.name === label));
  });
}

map.on('click', (e: L.LeafletMouseEvent) => setPoint(e.latlng.lat, e.latlng.lng, 'Ponto escolhido no mapa'));

$('presets').innerHTML = PRESETS.map((p) => `<button type="button" class="chip" aria-pressed="false" data-name="${esc(p.name)}">${esc(p.name)}</button>`).join('');
$('presets').addEventListener('click', (e) => {
  const chip = (e.target as HTMLElement).closest<HTMLButtonElement>('.chip');
  const preset = PRESETS.find((p) => p.name === chip?.dataset.name);
  if (preset) setPoint(preset.lat, preset.lng, preset.name, true);
});

$('locate').addEventListener('click', () => {
  if (!('geolocation' in navigator)) {
    $('point-error').textContent = 'Este navegador não informa a localização.';
    return;
  }
  $('point-label').textContent = 'Buscando sua localização…';
  navigator.geolocation.getCurrentPosition(
    (pos) => setPoint(pos.coords.latitude, pos.coords.longitude, 'Sua localização', true),
    () => {
      $('point-label').textContent = 'Toque no mapa, use sua localização ou escolha uma cidade.';
      $('point-error').textContent = 'Não foi possível obter a localização. No celular, o app precisa abrir em HTTPS.';
    },
    { enableHighAccuracy: true, timeout: 15000 },
  );
});

// --- Query -------------------------------------------------------------------

$('species-list').innerHTML = SPECIES.map((s) => `<option value="${esc(s)}"></option>`).join('');
$<HTMLInputElement>('date').value = today();

$('query').addEventListener('submit', async (e) => {
  e.preventDefault();
  for (const id of ['species-error', 'date-error', 'point-error']) $(id).textContent = '';

  const species = $<HTMLInputElement>('species').value.trim();
  const date = $<HTMLInputElement>('date').value;
  if (!point) $('point-error').textContent = 'Escolha um ponto no mapa.';
  if (species.length < 2) $('species-error').textContent = 'Informe a espécie (nome comum ou científico).';
  if (!date) $('date-error').textContent = 'Informe a data.';
  if (!point || species.length < 2 || !date) return;

  const query: Query = { latitude: point.lat, longitude: point.lng, species, date };
  const submit = $<HTMLButtonElement>('submit');
  submit.disabled = true;
  submit.textContent = 'Consultando…';
  try {
    const result = await evaluate(query);
    last = { query, result };
    renderSummary(result);
  } catch (err) {
    last = null;
    const error = err instanceof ApiError ? err : new ApiError('Erro inesperado.');
    if (error.fields.species) $('species-error').textContent = error.fields.species.join(' ');
    if (error.fields.date) $('date-error').textContent = error.fields.date.join(' ');
    $('summary').innerHTML = `<div class="card status-prohibited summary"><div class="icon">${icon(ICONS.prohibited)}</div><div><strong>Não foi possível consultar</strong><span>${esc(error.message)}</span></div></div>`;
  } finally {
    submit.disabled = false;
    submit.textContent = 'Consultar regras';
  }
});

const STATE_NAMES: Record<string, string> = { mg: 'Minas Gerais' };

function areaName(ev: Evaluation): string {
  return ev.location.areas[0]?.name ?? 'Área sem mapeamento legal';
}

// A hydrographic district (CH) is a management unit that groups several rivers and is
// named after the main one: "DO1: Rio Piranga" also covers the Matipó. Never present
// its name as the river at the point.
function districtLabel(ev: Evaluation): string | null {
  const d = ev.location.districts[0];
  return d ? `Circunscrição ${d.code}` : null;
}

function placeName(ev: Evaluation): string {
  const ch = districtLabel(ev);
  return ch ? `${areaName(ev)} · ${ch}` : areaName(ev);
}

function jurisdictionName(code: string): string {
  return code === 'br' ? 'Federal' : (STATE_NAMES[code] ?? code.toUpperCase());
}

function rulesByJurisdiction(rules: Rule[], restriction = false): string {
  const order = [...new Set(rules.map((r) => r.jurisdiction))].sort((a, b) => (a === 'br' ? 1 : b === 'br' ? -1 : a.localeCompare(b)));
  return order
    .map((j) => {
      const own = rules.filter((r) => r.jurisdiction === j);
      const label = j === 'br' ? 'Normas federais' : `Normas de ${jurisdictionName(j)}`;
      return `<h3 class="group-title">${esc(label)} (${own.length})</h3>${own.map((r) => ruleCard(r, restriction)).join('')}`;
    })
    .join('');
}

function placeCard(ev: Evaluation): string {
  const loc = ev.location;
  if (!loc.geographic_match) {
    return '<div class="card"><p class="note" style="margin:0">Este ponto está fora das áreas legais mapeadas (hoje só Minas Gerais). Só valem as normas federais carregadas.</p></div>';
  }
  const district = loc.districts[0];
  const basin = loc.areas.find((a) => a.jurisdiction !== 'br' && a.code.includes(':'));
  const federalBasin = loc.areas.find((a) => a.jurisdiction === 'br' && a.code.includes(':'));
  const domain = loc.water_domain === 'federal' ? 'Federal' : loc.water_domain === 'state' ? 'Estadual' : 'Ainda não identificado';
  const rows: [string, string][] = [
    ['Bacia (norma estadual)', basin?.name ?? 'Não identificada'],
    ['Circunscrição Hidrográfica', district ? district.name : 'Não identificada'],
    ['Rio no ponto', 'Ainda não identificado'],
    ['Bacia (norma federal)', federalBasin?.name ?? 'Nenhuma norma federal por bacia'],
    ['Estado', loc.states.map(jurisdictionName).join(', ') || 'Não identificado'],
    ['Domínio do rio', domain],
  ];
  return `
    <div class="card">
      <dl class="facts">${rows.map(([k, v]) => `<div><dt>${esc(k)}</dt><dd>${esc(v)}</dd></div>`).join('')}</dl>
      ${district ? `<p class="note" style="margin:8px 0 0">A Circunscrição Hidrográfica é a unidade de gestão das águas do ${esc(district.source)}. Ela reúne vários rios e leva o nome do principal, então não indica o rio exato do ponto.</p>` : ''}
    </div>`;
}

function summaryLine(ev: Evaluation): string {
  if (!ev.location.geographic_match) return 'Ponto fora das áreas mapeadas. Confira a norma do local.';
  if (ev.species.threatened.length) return 'Espécie da Lista Nacional de ameaçadas.';
  if (ev.status === 'conditional') return `${ev.conditions.length} condições a cumprir.`;
  if (ev.status === 'prohibited') return ev.rules[0]?.citation ?? '';
  return 'Nenhuma norma carregada decide este caso.';
}

function renderSummary(result: Result): void {
  const ev = result.evaluation;
  const s = STATUS_TEXT[ev.status];
  $('summary').innerHTML = `
    <div class="card summary status-${ev.status}">
      <div class="icon">${icon(ICONS[ev.status])}</div>
      <div><strong>${esc(s.verdict)}</strong><span>${esc(placeName(ev))}. ${esc(summaryLine(ev))}</span></div>
    </div>
    ${result.offline ? `<p class="note">Resultado salvo em ${esc(formatDate(result.savedAt))}, sem conexão agora.</p>` : ''}
    <div class="summary-actions"><button type="button" class="btn btn-primary" id="open-detail">Ver regras e fontes</button></div>`;
  $('open-detail').addEventListener('click', () => {
    location.hash = 'detalhes';
  });
}

// --- Detail screen ----------------------------------------------------------------

function ruleCard(rule: Rule, restriction = false): string {
  const quotes = rule.evidence
    .map((e) => `<blockquote>“${esc(e.quote)}”<cite>${esc(e.segment)}</cite></blockquote>`)
    .join('');
  const verified = rule.verified
    ? '<span class="pill pill-ok">Trecho conferido</span>'
    : '<span class="pill pill-warn">Trecho não conferido</span>';
  const effect = rule.effect === 'prohibited' ? '<span class="pill pill-red">Proíbe</span>' : '<span class="pill pill-plain">Condiciona</span>';
  return `
    <article class="card rule">
      <div class="pills">${effect}${verified}${restriction ? '<span class="pill pill-warn">Local não verificado no mapa</span>' : ''}</div>
      <h3>${esc(rule.summary)}</h3>
      <div class="citation">${esc(rule.citation)}</div>
      <div class="meta">Vale ${esc(formatPeriod(rule))}</div>
      ${quotes ? `<details><summary>Ver o texto da norma (${rule.evidence.length})</summary>${quotes}</details>` : ''}
      ${rule.act.url ? `<p><a href="${esc(rule.act.url)}" target="_blank" rel="noopener">Link informativo da norma</a></p>` : ''}
    </article>`;
}

function renderDetail(): void {
  if (!last) {
    location.hash = '';
    return;
  }
  const { query, result } = last;
  const ev = result.evaluation;
  const s = STATUS_TEXT[ev.status];
  const threatened = ev.species.threatened.map((t) => t.category).filter(Boolean).join(', ');
  const lead = ev.status === 'conditional'
    ? 'A pesca só é permitida cumprindo todas as condições abaixo.'
    : ev.status === 'prohibited'
      ? 'Ao menos uma norma vigente proíbe esta pesca neste local e data.'
      : 'As normas carregadas não permitem afirmar se é proibida ou condicionada. Confira o órgão ambiental.';

  $('view-detail').className = `view status-${ev.status}`;
  $('view-detail').innerHTML = `
    <header class="hero">
      <div class="hero-top">
        <button type="button" class="back" id="back" aria-label="Voltar">${icon('<path d="M15 5l-7 7 7 7"/>', 22)}</button>
        <h1>${esc(areaName(ev))}</h1>
      </div>
      <div class="verdict">${esc(s.verdict)}</div>
      <p class="lead">${esc(lead)}</p>
    </header>
    <svg class="wave" viewBox="0 0 390 40" preserveAspectRatio="none" aria-hidden="true"><path d="M0 20C60 0 120 40 195 20S330 0 390 20V40H0z" fill="#EAF4F4"/></svg>
    <div class="content">
      ${result.offline ? `<p class="banner" style="border-radius:16px">Resultado salvo em ${esc(formatDate(result.savedAt))}. Pode estar desatualizado.</p>` : ''}

      <h2 class="section-title">Seu local</h2>
      ${placeCard(ev)}

      <h2 class="section-title">Sua pescaria</h2>
      <div class="card">
        <div class="pills">
          <span class="pill pill-plain">${esc(ev.species.input)}</span>
          <span class="pill pill-plain">${esc(CLASSIFICATION[ev.species.classification] ?? ev.species.classification)}</span>
          ${threatened ? `<span class="pill pill-red">Ameaçada (${esc(threatened)})</span>` : ''}
          <span class="pill pill-plain">${esc(formatDate(query.date))}</span>
        </div>
      </div>

      ${ev.conditions.length ? `
        <h2 class="section-title">Condições a cumprir</h2>
        <div class="card"><ul class="list">${ev.conditions.map((c) => `<li>${esc(c.text)} <span class="meta">· ${esc(jurisdictionName(c.jurisdiction))}</span></li>`).join('')}</ul></div>` : ''}

      ${ev.rules.length ? `<h2 class="section-title">Normas que decidiram</h2>${rulesByJurisdiction(ev.rules)}` : ''}

      ${ev.location_restrictions.length ? `
        <h2 class="section-title">Atenção nesta região</h2>
        <p class="note">Valem só em trechos, faixas ou áreas específicas que o app ainda não verifica no mapa. Confira se o seu ponto está dentro delas.</p>
        ${rulesByJurisdiction(ev.location_restrictions, true)}` : ''}

      ${ev.gaps.length ? `
        <h2 class="section-title">O que ainda não sabemos</h2>
        <div class="card"><ul class="list">${ev.gaps.map((g) => `<li>${esc(g)}</li>`).join('')}</ul></div>` : ''}

      <p class="disclaimer">${esc(ev.disclaimer)}</p>
      <p class="disclaimer">Catálogos: ${ev.catalogs.map((c) => `${esc(c.jurisdiction.toUpperCase())} v${c.version_id}, publicado em ${esc(formatDate(c.published_at))} <span class="mono">${esc(c.checksum_sha256.slice(0, 12))}</span>`).join(' · ')}</p>
    </div>`;
  $('back').addEventListener('click', () => history.back());
  window.scrollTo(0, 0);
}

function route(): void {
  const detail = location.hash === '#detalhes' && last !== null;
  $('view-map').hidden = detail;
  $('view-detail').hidden = !detail;
  if (detail) renderDetail();
  else {
    if (location.hash) history.replaceState(null, '', location.pathname);
    map.invalidateSize();
  }
}
window.addEventListener('hashchange', route);
route();

// --- Offline ------------------------------------------------------------------

function updateOnline(): void {
  $('offline-banner').hidden = navigator.onLine;
}
window.addEventListener('online', updateOnline);
window.addEventListener('offline', updateOnline);
updateOnline();

if ('serviceWorker' in navigator && import.meta.env.PROD) {
  navigator.serviceWorker.register('/sw.js').catch(() => {
    // The app still works online without the service worker.
  });
}

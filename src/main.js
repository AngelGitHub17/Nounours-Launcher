// NounoursLauncher — interface
import { DEFAULT_API, LS, apiUrl, loadCatalog, loadStatus, loadProject, clearCache } from './api.js';
import { esc, md } from './md.js';

const isTauri = '__TAURI_INTERNALS__' in window;
const $ = (s, el = document) => el.querySelector(s);

const TYPES = {
  site:    { label: 'Sites',        one: 'Site',        icon: '🌐' },
  app:     { label: 'Applications', one: 'Application', icon: '🧩' },
  plugin:  { label: 'Plugins',      one: 'Plugin',      icon: '⛏️' },
  service: { label: 'Services',     one: 'Service',     icon: '🛰️' },
  bot:     { label: 'Bots',         one: 'Bot',         icon: '🤖' },
  tool:    { label: 'Outils',       one: 'Outil',       icon: '🛠️' },
};
const STATUS = { online: 'Disponible', beta: 'Bêta', dev: 'En développement', archived: 'Archivé' };
const PLATFORM_NAMES = {
  'windows-x86_64': 'Windows', 'windows-aarch64': 'Windows ARM', 'darwin-aarch64': 'macOS', 'darwin-x86_64': 'macOS (Intel)',
  'linux-x86_64': 'Linux', 'linux-aarch64': 'Linux ARM', android: 'Android', ios: 'iOS', web: 'Web', server: 'Serveur',
};

// ---------- Préférences (Réglages) ----------
const DEFAULT_PREFS = {
  theme: 'system',        // system | dark | light
  accent: '#ff9a1f',
  density: 'cards',       // cards | compact | list
  sidebar: 'auto',        // auto | expanded | collapsed
  startView: 'last',      // last | all | fav | mine
  sort: 'catalog',        // catalog | name | recent
  showDev: true,
  showArchived: false,
  groupByType: true,
};
const ACCENTS = [
  ['#ff9a1f', 'Orange NounoursCorp'], ['#3d9bff', 'Bleu'], ['#9b6bff', 'Violet'], ['#2fc788', 'Vert'],
  ['#ff5c8a', 'Rose'], ['#ff5a4e', 'Rouge'], ['#f5c400', 'Jaune'], ['#14b8c6', 'Turquoise'],
];
const prefs = { ...DEFAULT_PREFS, ...LS.get('nl.prefs', {}) };

const state = {
  platform: 'windows-x86_64',
  channel: LS.get('nl.channel', 'stable'),
  projects: [],
  status: {},
  view: prefs.startView === 'last' ? LS.get('nl.view', 'all') : prefs.startView,
  query: '',
  favs: new Set(LS.get('nl.favs', [])),
  offline: false,
  fetchedAt: 0,
  loading: true,
  error: null,
  appVersion: '0.0.0',
  update: null,
  installed: {},          // applis installées par le launcher (registre local)
  progress: {},           // installations en cours : slug → { phase, done, total }
  autoApps: LS.get('nl.autoApps', true),
};

// Types de fichiers préférés pour installer, par système
const PREFERRED_KINDS = {
  windows: ['installer', 'msi', 'portable', 'zip'],
  darwin: ['dmg', 'pkg', 'zip', 'portable'],
  linux: ['appimage', 'zip', 'portable', 'deb', 'rpm'],
};
// Installables sans intervention (mise à jour automatique possible)
const SILENT_KINDS = ['zip', 'portable', 'appimage'];

// ---------- Outils natifs (avec repli navigateur pour `npm run dev`) ----------

async function openExternal(url) {
  if (!/^https?:\/\//i.test(url)) return;
  if (isTauri) {
    const { openUrl } = await import('@tauri-apps/plugin-opener');
    await openUrl(url);
  } else {
    window.open(url, '_blank', 'noopener');
  }
}

let invoke = async () => { throw new Error("Disponible uniquement dans l'application"); };

async function initNative() {
  if (!isTauri) return;
  ({ invoke } = await import('@tauri-apps/api/core'));
  const { getVersion } = await import('@tauri-apps/api/app');
  const { listen } = await import('@tauri-apps/api/event');
  state.platform = await invoke('platform_key');
  state.appVersion = await getVersion();
  await loadInstalled();
  await listen('nl-progress', ev => {
    const p = ev.payload;
    if (p.phase === 'done' || p.phase === 'error') delete state.progress[p.slug];
    else state.progress[p.slug] = p;
    paintProgress(p.slug);
  });
}

async function loadInstalled() {
  if (!isTauri) return;
  try { state.installed = await invoke('installed_apps'); } catch (e) { console.warn(e); }
}

function toast(msg, kind = '') {
  const t = document.createElement('div');
  t.className = 'toast ' + kind;
  t.textContent = msg;
  $('#toasts').append(t);
  setTimeout(() => t.classList.add('out'), 3200);
  setTimeout(() => t.remove(), 3600);
}

// ---------- Données ----------

async function refreshCatalog({ silent = false } = {}) {
  if (!silent) { state.loading = state.projects.length === 0; render(); }
  try {
    const r = await loadCatalog(state.platform, state.channel);
    state.projects = r.projects.filter(p => p.type !== 'launcher');
    state.offline = r.offline;
    state.fetchedAt = r.fetchedAt;
    state.error = r.offline ? r.error : null;
  } catch (e) {
    state.error = e.message;
    state.offline = true;
  }
  state.loading = false;
  render();
}

async function refreshStatus() {
  const s = await loadStatus();
  if (s) { state.status = s; renderGrid(); renderNet(); }
}

// ---------- Rendu ----------

function lastUpdate(p) {
  let t = Date.parse(p.updated_at) || 0;
  for (const r of Object.values(p.latest || {})) t = Math.max(t, Date.parse(r.pub_date) || 0);
  return t;
}

function visibleProjects() {
  const q = state.query.trim().toLowerCase();
  const list = state.projects.filter(p => {
    // Les applis installées restent visibles même si leur statut est masqué
    if (!state.installed[p.slug]) {
      if (p.status === 'dev' && !prefs.showDev) return false;
      if (p.status === 'archived' && !prefs.showArchived) return false;
    }
    if (state.view === 'fav' && !state.favs.has(p.slug)) return false;
    if (state.view === 'mine' && !state.installed[p.slug]) return false;
    if (!['all', 'fav', 'mine'].includes(state.view) && p.type !== state.view) return false;
    if (!q) return true;
    return [p.name, p.short_desc, p.slug, ...(p.tags || [])].join(' ').toLowerCase().includes(q);
  });
  if (prefs.sort === 'name') list.sort((a, b) => a.name.localeCompare(b.name, 'fr', { sensitivity: 'base' }));
  else if (prefs.sort === 'recent') list.sort((a, b) => lastUpdate(b) - lastUpdate(a));
  return list;
}

function render() {
  renderNav();
  renderNet();
  renderHeader();
  renderGrid();
}

function renderNav() {
  const counts = {};
  state.projects.forEach(p => { counts[p.type] = (counts[p.type] || 0) + 1; });
  const items = [
    ['all', '🏠', 'Tous les projets', state.projects.length],
    ['fav', '★', 'Favoris', state.projects.filter(p => state.favs.has(p.slug)).length],
    ...(isTauri ? [['mine', '📦', 'Mes applis', state.projects.filter(p => state.installed[p.slug]).length, pendingUpdates().length]] : []),
    ...Object.entries(TYPES).filter(([t]) => counts[t]).map(([t, v]) => [t, v.icon, v.label, counts[t]]),
  ];
  $('#nav').innerHTML = items.map(([v, ic, label, n, upd]) =>
    `<button class="navbtn ${state.view === v ? 'on' : ''}" data-view="${v}" title="${esc(label)}"><span class="ic">${ic}</span><span class="lbl">${esc(label)}</span>${upd ? `<span class="count upd" title="Mises à jour">${upd}↑</span>` : `<span class="count">${n}</span>`}</button>`
  ).join('');
}

function timeFr(ts) {
  return ts ? new Date(ts).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : '';
}

function renderNet() {
  const el = $('#netState');
  if (state.offline) {
    el.className = 'net off';
    el.innerHTML = `<i></i><span>Hors ligne</span>${state.fetchedAt ? `<small>copie de ${timeFr(state.fetchedAt)}</small>` : ''}`;
    el.title = 'Hors ligne';
  } else {
    const st = Object.values(state.status);
    const down = st.filter(s => !s.up).length;
    el.className = 'net ' + (down ? 'warn' : 'on');
    const label = down ? `${down} service${down > 1 ? 's' : ''} hors ligne` : 'Tout fonctionne';
    el.innerHTML = `<i></i><span>${label}</span><small>mis à jour à ${timeFr(state.fetchedAt)}</small>`;
    el.title = label;
  }
}

function renderHeader() {
  const v = state.view;
  $('#title').textContent = v === 'all' ? 'Tous les projets' : v === 'fav' ? 'Favoris' : v === 'mine' ? 'Mes applis' : TYPES[v]?.label ?? 'Projets';
  const n2 = pendingUpdates().length;
  $('#updateAll').classList.toggle('hidden', !n2);
  $('#updateAll').textContent = `Tout mettre à jour (${n2})`;
  const n = visibleProjects().length;
  $('#subtitle').textContent = state.loading ? 'Chargement du catalogue…' : `${n} projet${n > 1 ? 's' : ''} · ${PLATFORM_NAMES[state.platform] || state.platform}`;
}

function iconHtml(p, cls = 'pic') {
  const style = `--acc:${esc(p.accent || '#4aa8ff')}`;
  return p.icon
    ? `<div class="${cls}" style="${style}"><img src="${esc(p.icon)}" alt="" loading="lazy"><span>${esc(p.name.charAt(0))}</span></div>`
    : `<div class="${cls}" style="${style}"><span>${esc(p.name.charAt(0))}</span></div>`;
}

function cmpVer(a, b) {
  const [ca, pa] = String(a).replace(/^v/, '').split('-', 2);
  const [cb, pb] = String(b).replace(/^v/, '').split('-', 2);
  const x = ca.split('.').map(Number), y = cb.split('.').map(Number);
  for (let i = 0; i < 3; i++) if ((x[i] || 0) !== (y[i] || 0)) return (x[i] || 0) - (y[i] || 0);
  if (!pa && !pb) return 0;
  if (!pa) return 1;
  if (!pb) return -1;
  return pa.localeCompare(pb, 'en', { numeric: true });
}

/** Meilleur fichier à installer sur ce système */
function bestAsset(p) {
  const rel = p.latest?.[state.platform];
  if (!rel?.assets?.length) return null;
  const order = PREFERRED_KINDS[state.platform.split('-')[0]] || [];
  const assets = rel.assets.filter(a => a.kind !== 'updater');
  assets.sort((a, b) => (order.indexOf(a.kind) + 1 || 99) - (order.indexOf(b.kind) + 1 || 99));
  return assets[0] ? { rel, asset: assets[0] } : null;
}

/** Applis installées qui ont une version plus récente dans le catalogue */
function pendingUpdates() {
  return state.projects.filter(p => {
    const inst = state.installed[p.slug], b = bestAsset(p);
    return inst && b && cmpVer(b.rel.version, inst.version) > 0;
  });
}

/** Action principale d'un projet sur cette plateforme */
function primaryAction(p) {
  const b = bestAsset(p);
  const inst = state.installed[p.slug];
  if (state.progress[p.slug]) return { kind: 'busy', label: 'Installation…' };
  if (b && isTauri) {
    if (inst && cmpVer(b.rel.version, inst.version) > 0) return { kind: 'install', label: 'Mettre à jour', ...b };
    if (inst && !inst.exists) return { kind: 'install', label: 'Réinstaller', ...b };
    if (inst) return { kind: 'launch', label: 'Lancer' };
    return { kind: 'install', label: 'Installer', ...b };
  }
  if (b) return { kind: 'download', label: 'Télécharger', ...b };
  if (p.website_url) return { kind: 'open', label: 'Ouvrir' };
  return { kind: 'details', label: 'Voir' };
}

function progressText(pr) {
  if (!pr) return '';
  if (pr.phase === 'download') return pr.total ? `Téléchargement ${Math.round(pr.done * 100 / pr.total)} %` : `Téléchargement ${fmtSize(pr.done)}`;
  if (pr.phase === 'verify') return 'Vérification…';
  return 'Installation…';
}

/** Met à jour la progression sans tout redessiner */
function paintProgress(slug) {
  const pr = state.progress[slug];
  document.querySelectorAll(`[data-prog="${CSS.escape(slug)}"]`).forEach(el => {
    el.classList.toggle('hidden', !pr);
    const pct = pr?.phase === 'download' && pr.total ? (pr.done * 100 / pr.total) : pr ? 100 : 0;
    el.querySelector('i').style.width = pct + '%';
    el.classList.toggle('indet', !!pr && !(pr.phase === 'download' && pr.total));
    const label = el.querySelector('span');
    if (label) label.textContent = progressText(pr);
  });
  if (!pr) return;
  document.querySelectorAll(`[data-act][data-slug="${CSS.escape(slug)}"]`).forEach(b => { b.disabled = true; b.textContent = 'Installation…'; });
}

async function installProject(p, { quiet = false } = {}) {
  const b = bestAsset(p);
  if (!b || state.progress[p.slug]) return false;
  const { rel, asset } = b;
  if (!asset.sha256 && !quiet && !confirm(`Le fichier de ${p.name} n'a pas d'empreinte de sécurité (SHA-256) : son intégrité ne pourra pas être vérifiée. Continuer ?`)) return false;
  state.progress[p.slug] = { phase: 'download', done: 0, total: asset.size || 0 };
  renderGrid(); paintProgress(p.slug);
  const was = state.installed[p.slug];
  try {
    await invoke('install_app', { req: {
      slug: p.slug, version: rel.version, url: asset.url, sha256: asset.sha256 || null,
      kind: asset.kind, install_args: asset.install_args || null, launch_path: asset.launch_path || null,
    } });
    delete state.progress[p.slug];
    await loadInstalled();
    toast(was ? `${p.name} mis à jour en v${rel.version}` : `${p.name} est installé`);
    return true;
  } catch (e) {
    delete state.progress[p.slug];
    toast(`${p.name} : ${e}`, 'err');
    return false;
  } finally {
    render();
    if (!$('#detail').classList.contains('hidden') && $('#sheet').dataset.slug === p.slug) openDetail(p.slug, true);
  }
}

async function launchProject(p) {
  const b = bestAsset(p), inst = state.installed[p.slug];
  if (b && inst && b.rel.mandatory && cmpVer(b.rel.version, inst.version) > 0) {
    toast(`Mise à jour obligatoire de ${p.name}…`);
    if (!(await installProject(p))) return;
  }
  try { await invoke('launch_app', { slug: p.slug }); toast(`${p.name} démarre…`); }
  catch (e) { toast(String(e), 'err'); }
}

async function removeProject(p) {
  const inst = state.installed[p.slug];
  if (!inst) return;
  const msg = inst.removable
    ? `Désinstaller ${p.name} ? Ses fichiers seront supprimés.`
    : `Retirer ${p.name} du launcher ? Il a été installé dans le système : pour le supprimer complètement, passe par les paramètres de ton ordinateur.`;
  if (!confirm(msg)) return;
  try { await invoke('remove_app', { slug: p.slug }); await loadInstalled(); toast(`${p.name} retiré`); render(); openDetail(p.slug, true); }
  catch (e) { toast(String(e), 'err'); }
}

async function updateAll({ auto = false } = {}) {
  const list = pendingUpdates().filter(p => !auto || SILENT_KINDS.includes(bestAsset(p).asset.kind) || bestAsset(p).asset.install_args);
  for (const p of list) await installProject(p, { quiet: auto });
}

function statusBadge(p) {
  const s = state.status[p.slug];
  if (s) {
    const extra = s.minecraft ? ` · ${s.minecraft.online}/${s.minecraft.max} joueurs` : '';
    return s.up
      ? `<span class="dot ok"></span>En ligne${extra}`
      : `<span class="dot bad"></span>Hors ligne`;
  }
  if (p.status !== 'online') return `<span class="dot ${p.status}"></span>${STATUS[p.status] || p.status}`;
  return '';
}

function cardHtml(p) {
  const a = primaryAction(p);
  const rel = p.latest?.[state.platform];
  return `<article class="card" data-slug="${esc(p.slug)}" style="--acc:${esc(p.accent || '#4aa8ff')}" tabindex="0">
    <div class="card-top">
      ${iconHtml(p)}
      <button class="star ${state.favs.has(p.slug) ? 'on' : ''}" data-fav="${esc(p.slug)}" title="Favori">★</button>
    </div>
    <h3>${esc(p.name)}</h3>
    <p>${esc(p.short_desc || '')}</p>
    <div class="card-foot">
      <span class="state">${statusBadge(p)}</span>
      ${state.installed[p.slug] ? `<span class="ver mine" title="Version installée">v${esc(state.installed[p.slug].version)}</span>` : rel ? `<span class="ver">v${esc(rel.version)}</span>` : ''}
      <button class="btn sm ${a.kind === 'details' ? 'ghost' : a.kind === 'launch' ? 'go' : 'primary'}" data-act="${a.kind}" data-slug="${esc(p.slug)}" ${a.kind === 'busy' ? 'disabled' : ''}>${a.label}</button>
    </div>
    <div class="prog ${state.progress[p.slug] ? '' : 'hidden'}" data-prog="${esc(p.slug)}"><span></span><div class="progress"><i></i></div></div>
  </article>`;
}

function renderGrid() {
  renderHeader();
  const box = $('#content');
  if (state.loading) {
    box.innerHTML = `<div class="grid">${'<div class="card skeleton"></div>'.repeat(8)}</div>`;
    return;
  }
  const list = visibleProjects();
  let html = '';
  if (state.error && !state.projects.length) {
    html = `<div class="empty"><div class="big">📡</div><h2>Impossible de joindre le catalogue</h2><p>${esc(state.error)}</p><button class="btn primary" data-retry>Réessayer</button></div>`;
  } else if (!list.length && state.view === 'mine' && !state.query) {
    html = `<div class="empty"><div class="big">📦</div><h2>Aucune appli installée</h2><p>Installe une application depuis le catalogue : elle apparaîtra ici et se mettra à jour toute seule.</p></div>`;
  } else if (!list.length) {
    html = state.view === 'fav' && !state.query
      ? `<div class="empty"><div class="big">★</div><h2>Aucun favori</h2><p>Clique sur l'étoile d'un projet pour le retrouver ici.</p></div>`
      : `<div class="empty"><div class="big">🔍</div><h2>Aucun résultat</h2><p>Essaie un autre mot-clé.</p></div>`;
  } else if (state.view === 'all' && !state.query && prefs.groupByType) {
    // Accueil : favoris d'abord, puis une section par type
    const favs = list.filter(p => state.favs.has(p.slug));
    if (favs.length) html += `<h2 class="section">★ Favoris</h2><div class="grid">${favs.map(cardHtml).join('')}</div>`;
    for (const [t, v] of Object.entries(TYPES)) {
      const group = list.filter(p => p.type === t);
      if (group.length) html += `<h2 class="section">${v.icon} ${v.label}</h2><div class="grid">${group.map(cardHtml).join('')}</div>`;
    }
  } else {
    html = `<div class="grid">${list.map(cardHtml).join('')}</div>`;
  }
  box.innerHTML = html;
  Object.keys(state.progress).forEach(paintProgress);
}

// ---------- Fiche projet ----------

function fmtSize(n) {
  if (!n) return '';
  const u = ['o', 'Ko', 'Mo', 'Go']; let i = 0;
  while (n >= 1024 && i < 3) { n /= 1024; i++; }
  return `${n.toFixed(i ? 1 : 0)} ${u[i]}`;
}

async function openDetail(slug, refresh = false) {
  const p = state.projects.find(x => x.slug === slug);
  if (!p) return;
  const rel = p.latest?.[state.platform];
  const b = bestAsset(p);
  const asset = b?.asset;
  const inst = state.installed[p.slug];
  const act = primaryAction(p);
  const scroll = $('#sheet').scrollTop;
  const s = state.status[p.slug];
  const others = (p.platforms || []).filter(x => x !== state.platform && x !== 'web').map(x => PLATFORM_NAMES[x] || x);

  $('#sheet').innerHTML = `
    <div class="hero" style="--acc:${esc(p.accent || '#4aa8ff')}${p.banner ? `;background-image:linear-gradient(180deg,transparent 30%,var(--bg) 100%),url('${esc(p.banner)}')` : ''}">
      <button class="iconbtn close" data-close>✕</button>
    </div>
    <div class="sheet-body detail">
      <div class="dhead">
        ${iconHtml(p, 'pic big')}
        <div>
          <h2>${esc(p.name)}</h2>
          <div class="chips">
            <span class="chip">${esc(TYPES[p.type]?.one || p.type)}</span>
            ${p.status !== 'online' ? `<span class="chip st-${esc(p.status)}">${esc(STATUS[p.status] || p.status)}</span>` : ''}
            ${rel ? `<span class="chip">v${esc(rel.version)}${rel.channel === 'beta' ? ' bêta' : ''}</span>` : ''}
            ${(p.tags || []).map(t => `<span class="chip ghost">#${esc(t)}</span>`).join('')}
          </div>
        </div>
      </div>

      <div class="actions">
        ${act.kind === 'launch' ? `<button class="btn go" data-act="launch" data-slug="${esc(p.slug)}">▶ Lancer</button>` : ''}
        ${act.kind === 'install' ? `<button class="btn primary" data-act="install" data-slug="${esc(p.slug)}">${esc(act.label)}${inst && act.label === 'Mettre à jour' ? ` · v${esc(inst.version)} → v${esc(b.rel.version)}` : asset?.size ? ` · ${fmtSize(asset.size)}` : ''}</button>` : ''}
        ${act.kind === 'busy' ? `<button class="btn" disabled>Installation…</button>` : ''}
        ${act.kind === 'download' ? `<button class="btn primary" data-open="${esc(asset.url)}">Télécharger pour ${esc(PLATFORM_NAMES[state.platform] || state.platform)}${asset.size ? ` · ${fmtSize(asset.size)}` : ''}</button>` : ''}
        ${p.website_url ? `<button class="btn ${['launch', 'install', 'download', 'busy'].includes(act.kind) ? '' : 'primary'}" data-open="${esc(p.website_url)}">Ouvrir ${p.type === 'site' ? 'le site' : "l'appli web"} ↗</button>` : ''}
        ${p.repo_url ? `<button class="btn ghost" data-open="${esc(p.repo_url)}">Code source ↗</button>` : ''}
        ${inst ? `<button class="btn ghost danger" data-remove="${esc(p.slug)}">${inst.removable ? 'Désinstaller' : 'Retirer du launcher'}</button>` : ''}
      </div>
      <div class="prog wide ${state.progress[p.slug] ? '' : 'hidden'}" data-prog="${esc(p.slug)}"><span></span><div class="progress"><i></i></div></div>
      ${inst ? `<p class="hint">Installé : v${esc(inst.version)}${inst.exists ? '' : ' — <b>programme introuvable</b>, réinstalle-le'}${b?.rel.mandatory && cmpVer(b.rel.version, inst.version) > 0 ? ' · <b>mise à jour obligatoire</b>' : ''}</p>` : ''}
      ${!asset && others.length ? `<p class="hint">Disponible aussi sur : ${esc(others.join(', '))}</p>` : ''}

      ${s ? `<div class="statusbox ${s.up ? 'ok' : 'bad'}">
        <span class="dot ${s.up ? 'ok' : 'bad'}"></span>
        <div><b>${s.up ? 'En ligne' : 'Hors ligne'}</b>${s.ms != null ? ` · ${s.ms} ms` : ''}
        ${s.minecraft ? `<div class="mc">${esc(s.minecraft.motd || '')}<br>${s.minecraft.online}/${s.minecraft.max} joueurs · ${esc(s.minecraft.version || '')}</div>` : ''}</div>
      </div>` : ''}

      ${p.description ? `<div class="md">${md(p.description)}</div>` : p.short_desc ? `<p class="lead">${esc(p.short_desc)}</p>` : ''}

      <div id="releases"></div>
    </div>`;
  $('#sheet').dataset.slug = p.slug;
  if (refresh) $('#sheet').scrollTop = scroll; else showOverlay('#detail');
  paintProgress(p.slug);

  // Historique des versions (chargé à part)
  if (!(p.platforms || []).some(x => x !== 'web')) return;
  try {
    const full = await loadProject(p.slug, state.platform, state.channel);
    const rels = (full.releases || []).filter(r => r.notes || r.assets.length);
    const inst = state.installed[p.slug];
    const relHtml = r => `
        <article class="rel ${inst && r.version === inst.version ? 'current' : ''}">
          <div class="rel-h">
            <b>v${esc(r.version)}</b>
            ${r.channel === 'beta' ? '<span class="chip">bêta</span>' : ''}
            ${inst && r.version === inst.version ? '<span class="chip mine">installée</span>' : ''}
            <span class="muted">${new Date(r.pub_date).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })}</span>
          </div>
          ${r.notes ? `<div class="md notes">${md(r.notes)}</div>` : '<p class="muted small">Pas de notes pour cette version.</p>'}
        </article>`;
    if (rels.length && $('#releases')) {
      const first = rels.slice(0, 3), more = rels.slice(3, 20);
      $('#releases').innerHTML = `<h3 class="section">Nouveautés</h3>` + first.map(relHtml).join('')
        + (more.length ? `<details class="more-rels"><summary>Voir les ${more.length} versions précédentes</summary>${more.map(relHtml).join('')}</details>` : '');
    }
  } catch { /* hors ligne : pas d'historique */ }
}

function showOverlay(sel) {
  const el = $(sel);
  el.classList.remove('hidden');
  requestAnimationFrame(() => el.classList.add('show'));
}
function hideOverlays() {
  document.querySelectorAll('.overlay.show').forEach(el => {
    el.classList.remove('show');
    setTimeout(() => el.classList.add('hidden'), 180);
  });
}

// ---------- Mise à jour du launcher ----------

async function checkUpdate(manual = false) {
  const msg = t => { if (manual) $('#updateMsg').textContent = t; };
  if (!isTauri) { msg("Les mises à jour ne fonctionnent que dans l'application installée."); return; }
  msg('Recherche…');
  try {
    const { check } = await import('@tauri-apps/plugin-updater');
    const u = await check({ timeout: 20000, headers: { 'X-NL-Channel': state.channel } });
    if (!u) { msg(`Tu as déjà la dernière version (${state.appVersion}).`); return; }
    state.update = u;
    msg(`Version ${u.version} disponible.`);
    renderUpdateBar();
  } catch (e) {
    msg('Impossible de vérifier : ' + (e?.message || e));
    console.warn('updater', e);
  }
}

function renderUpdateBar(progress = null) {
  const u = state.update;
  const bar = $('#updateBar');
  if (!u) { bar.classList.add('hidden'); return; }
  bar.classList.remove('hidden');
  bar.innerHTML = progress === null
    ? `<span>✨ <b>NounoursLauncher ${esc(u.version)}</b> est disponible.</span>
       <button class="btn sm primary" id="doUpdate">Installer et redémarrer</button>
       <button class="iconbtn sm" id="laterUpdate" title="Plus tard">✕</button>`
    : `<span>Téléchargement de la mise à jour… ${progress}%</span><div class="progress"><i style="width:${progress}%"></i></div>`;
}

async function installUpdate() {
  const u = state.update;
  if (!u) return;
  let total = 0, done = 0;
  renderUpdateBar(0);
  try {
    await u.downloadAndInstall(ev => {
      if (ev.event === 'Started') total = ev.data.contentLength || 0;
      if (ev.event === 'Progress') { done += ev.data.chunkLength; if (total) renderUpdateBar(Math.min(100, Math.round(done * 100 / total))); }
    });
    const { relaunch } = await import('@tauri-apps/plugin-process');
    await relaunch();
  } catch (e) {
    toast('Échec de la mise à jour : ' + (e?.message || e), 'err');
    renderUpdateBar();
  }
}

// ---------- Réglages ----------

// ---------- Apparence ----------

function inkFor(hex) {
  const n = parseInt(hex.slice(1), 16), r = n >> 16, g = (n >> 8) & 255, b = n & 255;
  return (0.299 * r + 0.587 * g + 0.114 * b) > 150 ? '#1c1200' : '#ffffff';
}

function applySidebar() {
  const collapsed = prefs.sidebar === 'collapsed' || (prefs.sidebar === 'auto' && window.innerWidth < 900);
  $('.shell').classList.toggle('side-collapsed', collapsed);
  $('#sideToggle .lbl').textContent = collapsed ? 'Agrandir la barre' : 'Réduire la barre';
}

function applyPrefs() {
  const dark = prefs.theme === 'dark' || (prefs.theme === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.dataset.theme = dark ? 'dark' : 'light';
  document.documentElement.style.setProperty('--accent', prefs.accent);
  document.documentElement.style.setProperty('--accent-ink', inkFor(prefs.accent));
  document.body.classList.remove('density-cards', 'density-compact', 'density-list');
  document.body.classList.add('density-' + prefs.density);
  applySidebar();
}

function savePrefs() {
  LS.set('nl.prefs', prefs);
  applyPrefs();
}

async function renderAutostart() {
  const box = $('#autostart');
  const desktop = isTauri && !['android', 'ios'].includes(state.platform);
  document.querySelectorAll('.desktop-only').forEach(el => el.classList.toggle('hidden', !desktop));
  if (!desktop) return;
  try {
    const { isEnabled } = await import('@tauri-apps/plugin-autostart');
    box.checked = await isEnabled();
  } catch { box.closest('.settings-group').classList.add('hidden'); }
}

function renderSettings() {
  document.querySelectorAll('[data-pref]').forEach(seg => seg.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.v === prefs[seg.dataset.pref])));
  document.querySelectorAll('[data-pref-select]').forEach(sel => { sel.value = prefs[sel.dataset.prefSelect]; });
  document.querySelectorAll('[data-pref-check]').forEach(c => { c.checked = !!prefs[c.dataset.prefCheck]; });
  $('#accentSwatches').innerHTML = ACCENTS.map(([c, name]) =>
    `<button class="swatch ${c === prefs.accent ? 'on' : ''}" data-accent="${c}" title="${esc(name)}" style="background:${c}"></button>`).join('');
  renderAutostart();
  document.querySelectorAll('#channelSeg button').forEach(b => b.classList.toggle('on', b.dataset.v === state.channel));
  $('#versionInfo').textContent = `Version installée : ${state.appVersion} · ${PLATFORM_NAMES[state.platform] || state.platform}`;
  $('#apiUrl').value = apiUrl();
  $('#autoApps').checked = state.autoApps;
  if (isTauri) invoke('apps_folder').then(d => { $('#appsDir').textContent = d; }).catch(() => {});
}

// ---------- Événements ----------

document.addEventListener('click', async e => {
  const t = e.target;
  const nav = t.closest('[data-view]');
  if (nav) { state.view = nav.dataset.view; LS.set('nl.view', state.view); render(); $('#content').scrollTop = 0; return; }

  const fav = t.closest('[data-fav]');
  if (fav) {
    e.stopPropagation();
    const s = fav.dataset.fav;
    state.favs.has(s) ? state.favs.delete(s) : state.favs.add(s);
    LS.set('nl.favs', [...state.favs]);
    render();
    return;
  }

  const act = t.closest('[data-act]');
  if (act) {
    e.stopPropagation();
    const p = state.projects.find(x => x.slug === act.dataset.slug);
    const kind = act.dataset.act;
    if (!p || kind === 'busy') return;
    if (kind === 'install') installProject(p);
    else if (kind === 'launch') launchProject(p);
    else if (kind === 'open') openExternal(p.website_url);
    else if (kind === 'download') openExternal(bestAsset(p).asset.url);
    else openDetail(p.slug);
    return;
  }

  const rm = t.closest('[data-remove]');
  if (rm) { const p = state.projects.find(x => x.slug === rm.dataset.remove); if (p) removeProject(p); return; }

  const open = t.closest('[data-open]');
  if (open) { openExternal(open.dataset.open); return; }

  const ext = t.closest('a[data-ext]');
  if (ext) { e.preventDefault(); openExternal(ext.getAttribute('href')); return; }

  if (t.closest('[data-close]') || t.classList.contains('overlay')) { hideOverlays(); return; }
  if (t.closest('[data-retry]')) { refreshCatalog(); return; }

  const card = t.closest('.card[data-slug]');
  if (card) { openDetail(card.dataset.slug); return; }

  if (t.id === 'doUpdate') { installUpdate(); return; }
  if (t.id === 'updateAll') { updateAll(); return; }
  if (t.id === 'laterUpdate') { $('#updateBar').classList.add('hidden'); return; }
});

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') hideOverlays();
  if (e.key === 'F5') { e.preventDefault(); refreshCatalog(); refreshStatus(); }
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') { e.preventDefault(); $('#search').focus(); }
  if (e.key === 'Enter' && document.activeElement?.classList.contains('card')) openDetail(document.activeElement.dataset.slug);
});

$('#search').addEventListener('input', e => { state.query = e.target.value; renderGrid(); });
$('#refresh').addEventListener('click', () => { refreshCatalog(); refreshStatus(); toast('Catalogue actualisé'); });
$('#openSettings').addEventListener('click', () => { renderSettings(); $('#updateMsg').textContent = ''; showOverlay('#settings'); });
$('#checkUpdate').addEventListener('click', () => checkUpdate(true));

$('#channelSeg').addEventListener('click', e => {
  const b = e.target.closest('button[data-v]');
  if (!b || b.dataset.v === state.channel) return;
  state.channel = b.dataset.v;
  LS.set('nl.channel', state.channel);
  renderSettings();
  refreshCatalog();
  toast(state.channel === 'beta' ? 'Canal bêta activé' : 'Canal stable activé');
});
$('#saveApi').addEventListener('click', () => {
  const v = $('#apiUrl').value.trim();
  try { new URL(v); } catch { toast('Adresse invalide', 'err'); return; }
  LS.set('nl.api', v); clearCache(); refreshCatalog(); refreshStatus(); toast('Adresse enregistrée');
});
$('#resetApi').addEventListener('click', () => { LS.set('nl.api', DEFAULT_API); renderSettings(); clearCache(); refreshCatalog(); refreshStatus(); });
// Réglages d'apparence et de catalogue
document.querySelectorAll('[data-pref]').forEach(seg => seg.addEventListener('click', e => {
  const b = e.target.closest('button[data-v]');
  if (!b) return;
  prefs[seg.dataset.pref] = b.dataset.v;
  savePrefs(); renderSettings(); render();
}));
document.querySelectorAll('[data-pref-select]').forEach(sel => sel.addEventListener('change', () => {
  prefs[sel.dataset.prefSelect] = sel.value; savePrefs(); render();
}));
document.querySelectorAll('[data-pref-check]').forEach(c => c.addEventListener('change', () => {
  prefs[c.dataset.prefCheck] = c.checked; savePrefs(); render();
}));
$('#accentSwatches').addEventListener('click', e => {
  const b = e.target.closest('[data-accent]');
  if (!b) return;
  prefs.accent = b.dataset.accent; savePrefs(); renderSettings();
});
$('#resetPrefs').addEventListener('click', () => {
  Object.assign(prefs, DEFAULT_PREFS); savePrefs(); renderSettings(); render(); toast('Apparence réinitialisée');
});
$('#autostart').addEventListener('change', async e => {
  try {
    const m = await import('@tauri-apps/plugin-autostart');
    if (e.target.checked) await m.enable(); else await m.disable();
    toast(e.target.checked ? 'Lancement au démarrage activé' : 'Lancement au démarrage désactivé');
  } catch (err) { toast('Impossible : ' + (err?.message || err), 'err'); e.target.checked = !e.target.checked; }
});
$('#sideToggle').addEventListener('click', () => {
  prefs.sidebar = $('.shell').classList.contains('side-collapsed') ? 'expanded' : 'collapsed';
  savePrefs();
});
window.addEventListener('resize', applySidebar);
matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => { if (prefs.theme === 'system') applyPrefs(); });

// Bouton « revenir en haut »
$('#content').addEventListener('scroll', e => $('#toTop').classList.toggle('show', e.target.scrollTop > 500), { passive: true });
$('#toTop').addEventListener('click', () => $('#content').scrollTo({ top: 0 }));

$('#autoApps').addEventListener('change', e => { state.autoApps = e.target.checked; LS.set('nl.autoApps', state.autoApps); });
$('#clearCache').addEventListener('click', () => { clearCache(); refreshCatalog(); toast('Cache vidé'); });

// Icône introuvable → on garde l'initiale (pas de gestionnaire inline à cause de la CSP)
document.addEventListener('error', e => { if (e.target.tagName === 'IMG' && e.target.closest('.pic')) e.target.remove(); }, true);

// Désactive le menu contextuel natif (sauf dans les champs de saisie)
document.addEventListener('contextmenu', e => { if (!e.target.closest('input, textarea, .md')) e.preventDefault(); });

// ---------- Démarrage ----------

applyPrefs();

(async () => {
  try { await initNative(); } catch (e) { console.warn(e); }
  render();
  await refreshCatalog();
  refreshStatus();
  setInterval(async () => { await refreshCatalog({ silent: true }); if (state.autoApps) updateAll({ auto: true }); }, 5 * 60 * 1000);
  setInterval(refreshStatus, 60 * 1000);
  setTimeout(() => checkUpdate(false), 4000);
  if (state.autoApps) setTimeout(() => updateAll({ auto: true }), 8000);
  setInterval(() => checkUpdate(false), 6 * 60 * 60 * 1000);
})();

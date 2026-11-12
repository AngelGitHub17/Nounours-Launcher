// NounoursLauncher — interface
import { DEFAULT_API, LS, apiUrl, loadCatalog, loadStatus, loadProject, clearCache, auth, authStart, authPoll, authMe, authLogout, unlockRelease, adminCall } from './api.js';
import { esc, md } from './md.js';

const isTauri = '__TAURI_INTERNALS__' in window;
const isMobilePlatform = pf => pf === 'android' || pf === 'ios';
/** Appli installée sur ordinateur : installe et lance les applis elle-même */
const canInstall = () => isTauri && !isMobilePlatform(state.platform);
const $ = (s, el = document) => el.querySelector(s);

const TYPES = {
  site:    { label: 'Sites',        one: 'Site',        icon: '🌐' },
  app:     { label: 'Applications', one: 'Application', icon: '🧩' },
  plugin:  { label: 'Plugins',      one: 'Plugin',      icon: '⛏️' },
  pack:    { label: 'Packs de ressources', one: 'Pack de ressources', icon: '🧱' },
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
  launcherUpdates: 'auto', // auto | ask
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
  user: auth.token ? LS.get('nl.user') : null, // compte NounoursAccount connecté
  releases: {},           // historique chargé par projet (fiche)
  login: null,            // connexion en cours : { code, url, poll, until, timer }
  admin: { pending: [], loaded: false }, // compte admin : versions en attente de décision
};
const isAdmin = () => !!state.user?.admin;

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
  document.body.classList.toggle('mobile', isMobilePlatform(state.platform));
  if (isMobilePlatform(state.platform)) return; // pas d'installation d'applis ni de suivi sur mobile
  await loadInstalled();
  await listen('nl-progress', ev => {
    const p = ev.payload;
    if (p.phase === 'done' || p.phase === 'error') delete state.progress[p.slug];
    else state.progress[p.slug] = p;
    paintProgress(p.slug);
  });
}

async function loadInstalled() {
  if (!canInstall()) return;
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
  if (state.refreshing) return state.refreshing; // une seule actualisation à la fois
  state.refreshing = (async () => {
  if (!silent) { state.loading = state.projects.length === 0; render(); }
  const wasOffline = state.offline;
  let changed = true;
  try {
    const r = await loadCatalog(state.platform, state.channel);
    changed = r.changed !== false || !state.projects.length;
    if (changed) state.projects = r.projects.filter(p => p.type !== 'launcher');
    state.offline = r.offline;
    state.fetchedAt = r.fetchedAt;
    state.error = r.offline ? r.error : null;
  } catch (e) {
    state.error = e.message;
    state.offline = true;
  }
  state.loading = false;
  if (changed || !silent || wasOffline !== state.offline) {
    render();
    // Fiche ouverte : on la met à jour aussi (nouvelle version, verrou levé…)
    const slug = $('#sheet').dataset.slug;
    if (changed && slug && !$('#detail').classList.contains('hidden')) openDetail(slug, true);
  } else {
    renderNet(); // rien de neuf : on ne redessine pas (saisies et défilement conservés)
  }
  // Hors ligne : nouvel essai dans 30 s (au lieu d'attendre la prochaine actualisation)
  clearTimeout(state.retryTimer);
  if (state.offline) state.retryTimer = setTimeout(() => refreshCatalog({ silent: true }), 30 * 1000);
  })();
  try { await state.refreshing; } finally { state.refreshing = null; }
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
    ...(canInstall() ? [['mine', '📦', 'Mes applis', state.projects.filter(p => state.installed[p.slug]).length, pendingUpdates().length]] : []),
    ...Object.entries(TYPES).filter(([t]) => counts[t]).map(([t, v]) => [t, v.icon, v.label, counts[t]]),
    ...(isAdmin() ? [['admin', '🛠', 'Administration', 0, state.admin.pending.length]] : []),
  ];
  $('#nav').innerHTML = items.map(([v, ic, label, n, upd]) =>
    `<button class="navbtn ${state.view === v ? 'on' : ''}" data-view="${v}" title="${esc(label)}"><span class="ic">${ic}</span><span class="lbl">${esc(label)}</span>${v === 'admin' && !upd ? '' : upd ? (v === 'admin' ? `<span class="count upd" title="Versions en attente">${upd}</span>` : `<span class="count upd" title="Mises à jour">${upd}↑</span>`) : `<span class="count">${n}</span>`}</button>`
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
  $('#title').textContent = v === 'all' ? 'Tous les projets' : v === 'fav' ? 'Favoris' : v === 'mine' ? 'Mes applis' : v === 'admin' ? 'Administration' : TYPES[v]?.label ?? 'Projets';
  const n2 = pendingUpdates().length;
  $('#updateAll').classList.toggle('hidden', !n2);
  $('#updateAll').textContent = `Tout mettre à jour (${n2})`;
  const n = visibleProjects().length;
  if (v === 'admin') { $('#subtitle').textContent = `Catalogue du launcher · ${state.user?.name || ''}`; return; }
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
  const assets = rel.assets.filter(a => a.kind !== 'updater' && a.url);
  assets.sort((a, b) => (order.indexOf(a.kind) + 1 || 99) - (order.indexOf(b.kind) + 1 || 99));
  return assets[0] ? { rel, asset: assets[0] } : null;
}

/** Version la plus récente encore verrouillée pour ce compte (pièces, connexion) */
function lockedRel(p) {
  const rel = p.latest?.[state.platform];
  return rel?.access && !rel.access.allowed ? rel : null;
}
const relName = r => r.label || 'v' + r.version;
function lockLabel(a) {
  if (a.reason === 'coins' || (a.reason === 'login' && a.coins > 0)) return state.user ? `Débloquer · ${a.coins} 🪙` : `Se connecter · ${a.coins} 🪙`;
  if (a.reason === 'login') return 'Se connecter';
  return 'Bientôt';
}
/** Compte à rebours lisible : « 2 j 03:12:09 » */
function countdown(iso) {
  let s = Math.max(0, Math.round((new Date(iso).getTime() - Date.now()) / 1000));
  const d = Math.floor(s / 86400); s %= 86400;
  const pad = n => String(n).padStart(2, '0');
  return (d ? `${d} j ` : '') + `${pad(Math.floor(s / 3600))}:${pad(Math.floor(s % 3600 / 60))}:${pad(s % 60)}`;
}
const dateFr = iso => new Date(iso).toLocaleString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });

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
  const lk = lockedRel(p);
  if (lk && (!b || cmpVer(lk.version, b.rel.version) > 0) && !(inst && cmpVer(inst.version, lk.version) >= 0)) {
    // Dernière version verrouillée : si une ancienne est installée, on peut toujours la lancer depuis la fiche
    if (inst && canInstall() && !b) return { kind: 'launch', label: p.type === 'pack' ? 'Ouvrir' : 'Lancer' };
    return { kind: 'unlock', label: lockLabel(lk.access), rel: lk };
  }
  if (b && canInstall()) {
    if (inst && cmpVer(b.rel.version, inst.version) > 0) return { kind: 'install', label: 'Mettre à jour', ...b };
    if (inst && !inst.exists) return { kind: 'install', label: 'Réinstaller', ...b };
    if (inst) return { kind: 'launch', label: p.type === 'pack' ? 'Ouvrir' : 'Lancer' };
    return { kind: 'install', label: p.type === 'pack' ? 'Ajouter à Minecraft' : 'Installer', ...b };
  }
  if (b) return { kind: 'download', label: state.platform === 'android' ? 'Installer' : 'Télécharger', ...b };
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

async function installProject(p, { quiet = false, pick = null } = {}) {
  const b = pick || bestAsset(p);
  if (!b || !b.asset?.url || state.progress[p.slug]) return false;
  const { rel, asset } = b;
  if (!asset.sha256 && !quiet && !confirm(`Le fichier de ${p.name} n'a pas d'empreinte de sécurité (SHA-256) : son intégrité ne pourra pas être vérifiée. Continuer ?`)) return false;
  state.progress[p.slug] = { phase: 'download', done: 0, total: asset.size || 0 };
  renderGrid(); paintProgress(p.slug);
  const was = state.installed[p.slug];
  try {
    await invoke('install_app', { req: {
      slug: p.slug, version: rel.version, url: asset.url, sha256: asset.sha256 || null,
      kind: asset.kind, install_args: asset.install_args || null, launch_path: asset.launch_path || null,
      name: p.name, file_name: asset.name || null,
    } });
    delete state.progress[p.slug];
    await loadInstalled();
    toast(p.type === 'pack' ? `${p.name} ${relName(rel)} ajouté aux packs de ressources de Minecraft`
      : was ? `${p.name} : ${relName(rel)} installée` : `${p.name} est installé`);
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
      ${state.installed[p.slug] ? `<span class="ver mine" title="Version installée">v${esc(state.installed[p.slug].version)}</span>` : rel ? `<span class="ver">${esc(relName(rel))}</span>` : ''}
      ${!rel && p.upcoming?.length ? `<span class="ver soon" title="${esc(dateFr(p.upcoming[0].access.available_at))}">⏳ <span data-cd="${esc(p.upcoming[0].access.available_at)}">${countdown(p.upcoming[0].access.available_at)}</span></span>` : ''}
      <button class="btn sm ${a.kind === 'details' ? 'ghost' : a.kind === 'launch' ? 'go' : a.kind === 'unlock' ? 'lock' : 'primary'}" data-act="${a.kind}" data-slug="${esc(p.slug)}" ${a.kind === 'busy' ? 'disabled' : ''}>${a.label}</button>
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
  if (state.view === 'admin') {
    if (!isAdmin()) { state.view = 'all'; return render(); }
    box.innerHTML = adminHtml();
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
            ${rel ? `<span class="chip">${esc(relName(rel))}${rel.channel === 'beta' ? ' bêta' : ''}</span>` : ''}
            ${(p.tags || []).map(t => `<span class="chip ghost">#${esc(t)}</span>`).join('')}
          </div>
        </div>
      </div>

      <div class="actions">
        ${act.kind === 'launch' ? `<button class="btn go" data-act="launch" data-slug="${esc(p.slug)}">▶ Lancer</button>` : ''}
        ${act.kind === 'install' ? `<button class="btn primary" data-act="install" data-slug="${esc(p.slug)}">${esc(act.label)}${inst && act.label === 'Mettre à jour' ? ` · v${esc(inst.version)} → v${esc(b.rel.version)}` : asset?.size ? ` · ${fmtSize(asset.size)}` : ''}</button>` : ''}
        ${act.kind === 'busy' ? `<button class="btn" disabled>Installation…</button>` : ''}
        ${act.kind === 'unlock' ? `<button class="btn lock" data-act="unlock" data-slug="${esc(p.slug)}" data-rel="${act.rel.id}">${esc(lockLabel(act.rel.access))}</button>` : ''}
        ${act.kind === 'download' ? `<button class="btn primary" data-open="${esc(asset.url)}">${state.platform === 'android' ? 'Installer sur Android' : 'Télécharger pour ' + esc(PLATFORM_NAMES[state.platform] || state.platform)}${asset.size ? ` · ${fmtSize(asset.size)}` : ''}</button>` : ''}
        ${p.website_url ? `<button class="btn ${['launch', 'install', 'download', 'busy'].includes(act.kind) ? '' : 'primary'}" data-open="${esc(p.website_url)}">Ouvrir ${p.type === 'site' ? 'le site' : "l'appli web"} ↗</button>` : ''}
        ${p.repo_url ? `<button class="btn ghost" data-open="${esc(p.repo_url)}">Code source ↗</button>` : ''}
        ${isAdmin() ? `<button class="btn ghost" data-admin-panel="${esc(p.slug)}" title="Ouvrir ce projet dans le panel d'administration">🛠 Gérer</button>` : ''}
        ${inst ? `<button class="btn ghost danger" data-remove="${esc(p.slug)}">${inst.removable ? 'Désinstaller' : 'Retirer du launcher'}</button>` : ''}
      </div>
      <div class="prog wide ${state.progress[p.slug] ? '' : 'hidden'}" data-prog="${esc(p.slug)}"><span></span><div class="progress"><i></i></div></div>
      ${inst ? `<p class="hint">Installé : v${esc(inst.version)}${inst.exists ? '' : ' — <b>programme introuvable</b>, réinstalle-le'}${b?.rel.mandatory && cmpVer(b.rel.version, inst.version) > 0 ? ' · <b>mise à jour obligatoire</b>' : ''}</p>` : ''}
      ${!asset && !lockedRel(p) && others.length ? `<p class="hint">Disponible aussi sur : ${esc(others.join(', '))}</p>` : ''}
      ${lockBox(p)}
      ${(p.upcoming || []).length ? `<h3 class="section">Bientôt</h3><div class="soon-list">${p.upcoming.map(r => `
        <div class="soon-item">
          <div><b>${esc(relName(r))}</b> <span class="muted">· ${esc(dateFr(r.access.available_at))}</span>
            ${r.access.coins ? `<span class="chip">${r.access.coins} 🪙</span>` : r.access.login ? '<span class="chip">🔒 compte</span>' : ''}</div>
          <div class="cd" data-cd="${esc(r.access.available_at)}">${countdown(r.access.available_at)}</div>
        </div>`).join('')}</div>` : ''}

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
    state.releases[p.slug] = rels;
    const inst = state.installed[p.slug];
    const relBtn = r => {
      const a = r.access || { allowed: true };
      if (!a.allowed) return `<button class="btn sm lock" data-act="unlock" data-slug="${esc(p.slug)}" data-rel="${r.id}">${esc(lockLabel(a))}</button>`;
      const pick = pickAsset(r);
      if (!pick || (inst && r.version === inst.version)) return '';
      if (canInstall()) return `<button class="btn sm ghost" data-relinstall="${r.id}" data-slug="${esc(p.slug)}">${p.type === 'pack' ? 'Ajouter à Minecraft' : 'Installer cette version'}</button>`;
      return `<button class="btn sm ghost" data-open="${esc(pick.url)}">Télécharger</button>`;
    };
    const relHtml = r => `
        <article class="rel ${inst && r.version === inst.version ? 'current' : ''}">
          <div class="rel-h">
            <b>${esc(relName(r))}</b>
            ${r.channel === 'beta' ? '<span class="chip">bêta</span>' : ''}
            ${inst && r.version === inst.version ? '<span class="chip mine">installée</span>' : ''}
            ${r.access?.coins ? `<span class="chip">${r.access.owned ? '✓ débloquée' : r.access.coins + ' 🪙'}</span>` : r.access?.login ? '<span class="chip">🔒 compte</span>' : ''}
            <span class="muted">${new Date(r.pub_date).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })}</span>
            <span class="grow"></span>${relBtn(r)}
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

/** Fichier d'une version (n'importe laquelle) pour ce système */
function pickAsset(r) {
  const order = PREFERRED_KINDS[state.platform.split('-')[0]] || [];
  const list = (r.assets || []).filter(a => a.kind !== 'updater' && a.url);
  list.sort((a, b) => (order.indexOf(a.kind) + 1 || 99) - (order.indexOf(b.kind) + 1 || 99));
  return list[0] || null;
}

/** Encadré « version verrouillée » de la fiche */
function lockBox(p) {
  const lk = lockedRel(p);
  if (!lk) return '';
  const a = lk.access;
  const why = a.coins > 0
    ? `${esc(relName(lk))} se débloque avec <b>${a.coins} pièces</b> NounoursAccount (une seule fois, pour toujours).`
    : `${esc(relName(lk))} est réservée aux comptes NounoursAccount.`;
  const you = state.user ? `<p class="muted small">Ton solde : <b>${state.user.coins} pièces</b>${a.reason === 'coins' && state.user.coins < a.coins ? ' — pas assez pour l\'instant' : ''}</p>` : '';
  return `<div class="lockbox"><div class="lk-ic">🔒</div><div><p>${why}</p>${you}</div></div>`;
}

/** Débloque (pièces) ou propose la connexion */
async function unlockFlow(p, relId) {
  const rel = [p.latest?.[state.platform], ...(p.upcoming || []), ...(state.releases[p.slug] || [])].find(r => r && r.id === relId);
  if (!rel) return;
  const a = rel.access;
  if (a.reason === 'scheduled') { toast(`Sortie ${dateFr(a.available_at)}`); return; }
  if (!state.user) { openAccount(true); return; }
  if (a.reason !== 'coins') { toast('Cette version n\'est pas accessible avec ce compte', 'err'); return; }
  if (state.user.coins < a.coins) { toast(`Il faut ${a.coins} pièces, tu en as ${state.user.coins}`, 'err'); return; }
  if (!confirm(`Débloquer ${p.name} ${relName(rel)} pour ${a.coins} pièces ?\nTu en as ${state.user.coins}. C'est définitif pour ton compte.`)) return;
  try {
    const r = await unlockRelease(relId);
    state.user = { ...state.user, coins: r.coins }; LS.set('nl.user', state.user);
    toast(`${relName(rel)} débloquée ! Il te reste ${r.coins} pièces.`);
    await refreshCatalog({ silent: true });
    if (!$('#detail').classList.contains('hidden')) openDetail(p.slug, true);
  } catch (e) {
    if (e.data?.coins != null) { state.user = { ...state.user, coins: e.data.coins }; LS.set('nl.user', state.user); }
    toast(e.message, 'err');
  }
}

// ---------- Compte NounoursAccount ----------
// Connexion par code (comme une TV) : le launcher affiche un code, la page du site le valide
// une fois connecté à NounoursAccount ; le launcher reçoit alors son propre jeton.

function renderAccountBtn() {
  const b = $('#accountBtn');
  if (!b) return;
  const u = state.user;
  b.querySelector('.ic').innerHTML = u?.avatar ? `<img src="${esc(u.avatar)}" alt="">` : '👤';
  b.querySelector('.lbl').innerHTML = u ? `${esc(u.name)} <small>${u.coins} 🪙</small>` : 'Se connecter';
  b.title = u ? `${u.name} · ${u.coins} pièces` : 'Se connecter avec NounoursAccount';
}

function renderAccount() {
  const u = state.user, L = state.login;
  $('#accountBody').innerHTML = u ? `
      <div class="acct">
        ${u.avatar ? `<img src="${esc(u.avatar)}" alt="">` : '<div class="pic">👤</div>'}
        <div><b>${esc(u.name)}</b><div class="muted">@${esc(u.username || '')}</div></div>
      </div>
      <div class="coins"><span>🪙</span><b>${u.coins}</b> pièces</div>
      ${u.admin ? `<p><button class="btn" data-view="admin" data-close>🛠 Administration du launcher</button></p>` : ''}
      <p class="muted small">Les pièces se gagnent sur les sites NounoursCorp. Elles débloquent des versions réservées, définitivement pour ton compte.</p>
      <div class="row"><button class="btn" id="acctRefresh">Actualiser</button><button class="btn ghost danger" id="acctLogout">Se déconnecter</button></div>`
    : L ? `
      <p>Sur la page qui vient de s'ouvrir, connecte-toi à NounoursAccount et vérifie ce code :</p>
      <div class="code">${esc(L.code)}</div>
      <p class="muted small">En attente de validation… <span id="loginLeft"></span></p>
      <div class="row"><button class="btn primary" data-open="${esc(L.url)}">Rouvrir la page ↗</button><button class="btn ghost" id="loginCancel">Annuler</button></div>`
    : `
      <p>Connecte le launcher à ton compte NounoursAccount pour :</p>
      <ul class="muted"><li>voir les projets réservés aux membres ;</li><li>débloquer des versions avec tes pièces ;</li><li>télécharger les versions réservées aux comptes.</li></ul>
      <button class="btn primary" id="loginStart">Se connecter avec NounoursAccount</button>`;
}

function openAccount(withHint = false) {
  renderAccount();
  showOverlay('#account');
  if (withHint && !state.user && !state.login) toast('Connecte-toi pour débloquer cette version');
}

async function startLogin() {
  try {
    const r = await authStart(`NounoursLauncher · ${PLATFORM_NAMES[state.platform] || state.platform}`);
    state.login = { ...r, until: Date.now() + r.expires_in * 1000 };
    renderAccount();
    openExternal(r.url);
    pollLogin();
  } catch (e) { toast(e.message, 'err'); }
}

async function pollLogin() {
  const L = state.login;
  if (!L) return;
  if (Date.now() > L.until) { state.login = null; renderAccount(); toast('Code expiré : recommence', 'err'); return; }
  const left = $('#loginLeft');
  if (left) left.textContent = `(expire dans ${Math.ceil((L.until - Date.now()) / 60000)} min)`;
  try {
    const r = await authPoll(L.poll);
    if (r.token) {
      auth.token = r.token;
      state.user = r.user; LS.set('nl.user', r.user);
      state.login = null;
      renderAccount(); renderAccountBtn();
      toast(`Connecté : ${r.user.name}${r.user.admin ? ' (admin)' : ''}`);
      if (r.user.admin) loadAdmin();
      clearCache(); await refreshCatalog({ silent: true });
      return;
    }
  } catch (e) {
    if (e.status === 410) { state.login = null; renderAccount(); toast('Code expiré : recommence', 'err'); return; }
  }
  if (state.login === L) L.timer = setTimeout(pollLogin, 3000);
}

async function refreshUser() {
  if (!auth.token) return;
  try {
    const u = await authMe();
    if (!u) { auth.token = null; state.user = null; LS.del('nl.user'); toast('Session expirée : reconnecte-toi', 'err'); }
    else { state.user = u; LS.set('nl.user', u); }
  } catch { /* hors ligne : on garde le dernier état */ }
  renderAccountBtn();
  if (isAdmin()) loadAdmin(); else render();
  if (!$('#account').classList.contains('hidden')) renderAccount();
}

// Comptes à rebours (fiches et cartes) ; une version qui sort → catalogue actualisé
setInterval(() => {
  let due = false;
  document.querySelectorAll('[data-cd]').forEach(el => {
    if (new Date(el.dataset.cd).getTime() <= Date.now()) { el.textContent = 'Disponible !'; due = true; }
    else el.textContent = countdown(el.dataset.cd);
  });
  if (due && !state.cdRefresh) {
    state.cdRefresh = true;
    setTimeout(async () => {
      await refreshCatalog({ silent: true });
      const slug = $('#sheet').dataset.slug;
      if (slug && !$('#detail').classList.contains('hidden')) openDetail(slug, true);
      state.cdRefresh = false;
    }, 2000);
  }
}, 1000);

// ---------- Administration (comptes admin NounoursAccount) ----------

async function loadAdmin() {
  if (!isAdmin()) return;
  const before = state.admin.loaded ? JSON.stringify(state.admin.pending) : null;
  try { state.admin = { pending: (await adminCall('summary')).pending || [], loaded: true, error: null }; }
  catch (e) {
    state.admin = { ...state.admin, loaded: true, error: e.message };
    if (e.status === 403) state.user = { ...state.user, admin: false };
  }
  if (before !== null && before === JSON.stringify(state.admin.pending) && !state.admin.error) return; // rien de neuf
  renderNav();
  if (state.view === 'admin') renderGrid();
}

function adminHtml() {
  const A = state.admin;
  const minLocal = (() => { const d = new Date(Date.now() + 5 * 60000); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 16); })();
  const rows = A.pending.map(r => `
      <div class="adm-row">
        <div class="adm-name"><b>${esc(r.project)}</b> ${esc(r.name)}<small class="muted">détectée le ${esc(new Date(r.detected_at).toLocaleString('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }))}</small></div>
        <button class="btn sm primary" data-adm-pub="${r.id}">Publier maintenant</button>
        <span class="adm-sched"><input type="datetime-local" min="${minLocal}" data-adm-date="${r.id}"><button class="btn sm" data-adm-sched="${r.id}">Programmer</button></span>
        <button class="btn sm ghost" data-admin-panel="${esc(r.slug)}">Détails…</button>
      </div>`).join('');
  return `<div class="admin-view">
    <div class="adm-actions">
      <button class="btn primary" data-admin-panel="">🛠 Ouvrir le panel complet</button>
      <button class="btn" data-admin-watch>🔎 Chercher les nouvelles versions</button>
      <button class="btn ghost" data-admin-reload>⟳ Actualiser</button>
    </div>
    <p class="muted small">Le panel s'ouvre dans une fenêtre du launcher, déjà connecté avec ton compte : projets, versions, fichiers, verrous et surveillance.</p>
    <h2 class="section">Versions en attente de sortie</h2>
    ${A.error ? `<div class="lockbox"><div class="lk-ic">⚠️</div><div><p>Impossible de joindre l'administration du serveur : ${esc(A.error)}</p><p class="muted small">Vérifie que les fichiers du serveur sont à jour (panel → « Version du serveur »).</p></div></div>` : ''}
    ${!A.loaded ? '<div class="card skeleton" style="height:70px"></div>' : A.error ? ''
      : rows || '<div class="empty small-empty"><div class="big">✅</div><p>Aucune version en attente. Les nouvelles versions détectées sur GitHub ou dans les dossiers du serveur apparaîtront ici (et par mail).</p></div>'}
  </div>`;
}

async function openAdminPanel(slug = '') {
  try {
    const { url } = await adminCall('panel-link', { slug });
    if (canInstall()) await invoke('open_admin_window', { url });
    else openExternal(url);
  } catch (e) { toast('Panel : ' + (e.message || e), 'err'); }
}

async function adminSchedule(id, when, local = '') {
  try {
    await adminCall('release/schedule', { id, when, local });
    toast(when === 'now' ? 'Version publiée ✓' : 'Sortie programmée ✓');
    await Promise.all([loadAdmin(), refreshCatalog({ silent: true })]);
  } catch (e) { toast(e.message, 'err'); }
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
// Mode « automatique » (par défaut) : le launcher vérifie au démarrage, toutes les 30 minutes et
// quand on revient sur sa fenêtre. Une mise à jour trouvée au démarrage s'installe tout de suite ;
// trouvée en cours d'utilisation, elle se télécharge en silence puis redémarre après un compte à
// rebours (annulable : elle s'installera alors au prochain démarrage).

const UPD = { lastCheck: 0, checking: false, downloaded: false, countdown: null, retry: null };

/** Android : pas de mise à jour intégrée ; on compare avec l'APK du catalogue et on propose de le télécharger */
async function checkUpdateAndroid(manual) {
  const msg = t => { if (manual) $('#updateMsg').textContent = t; };
  try {
    const p = await loadProject('nounours-launcher', 'android', state.channel);
    const rel = p?.latest?.android;
    const apk = rel?.assets?.find(a => a.kind === 'apk');
    UPD.lastCheck = Date.now(); renderUpdateInfo();
    if (!apk || cmpVer(rel.version, state.appVersion) <= 0) { msg(`Tu as déjà la dernière version (${state.appVersion}).`); return; }
    msg(`Version ${rel.version} disponible.`);
    const bar = $('#updateBar');
    bar.classList.remove('hidden');
    bar.innerHTML = `<span>✨ <b>NounoursLauncher ${esc(rel.version)}</b> est disponible.</span>
      <button class="btn sm primary" data-open="${esc(apk.url)}">Télécharger</button>
      <button class="iconbtn sm" id="laterUpdate" title="Plus tard">✕</button>`;
  } catch (e) { msg('Impossible de vérifier : ' + (e?.message || e)); }
}

async function checkUpdate(manual = false, { startup = false } = {}) {
  const msg = t => { if (manual) $('#updateMsg').textContent = t; };
  if (!isTauri) { msg("Les mises à jour ne fonctionnent que dans l'application installée."); return; }
  if (state.platform === 'android') return checkUpdateAndroid(manual);
  if (state.platform === 'ios') { msg("Sur iPhone, les mises à jour passent par l'App Store / TestFlight."); return; }
  if (UPD.checking || state.update?.busy) return;
  UPD.checking = true;
  msg('Recherche…');
  try {
    const { check } = await import('@tauri-apps/plugin-updater');
    const u = await check({ timeout: 20000, headers: { 'X-NL-Channel': state.channel } });
    UPD.lastCheck = Date.now();
    clearTimeout(UPD.retry);
    renderUpdateInfo();
    if (!u) { msg(`Tu as déjà la dernière version (${state.appVersion}).`); return; }
    if (state.update && state.update.version === u.version) { msg(`Version ${u.version} prête.`); return; }
    state.update = u;
    msg(`Version ${u.version} disponible.`);
    if (prefs.launcherUpdates === 'auto' && !manual) {
      if (startup) installUpdate();          // au démarrage : on installe tout de suite
      else autoDownloadThenRestart();        // en cours d'utilisation : en arrière-plan
    } else {
      renderUpdateBar();
    }
  } catch (e) {
    msg('Impossible de vérifier : ' + (e?.message || e));
    console.warn('updater', e);
    clearTimeout(UPD.retry);
    UPD.retry = setTimeout(() => checkUpdate(false), 5 * 60 * 1000); // hors ligne : on réessaie dans 5 min
  } finally {
    UPD.checking = false;
  }
}

function renderUpdateInfo() {
  const el = $('#updateLast');
  if (el) el.textContent = UPD.lastCheck ? `Dernière vérification : ${timeFr(UPD.lastCheck)}` : '';
}

function renderUpdateBar(mode = 'ask', value = 0) {
  const u = state.update;
  const bar = $('#updateBar');
  if (!u) { bar.classList.add('hidden'); return; }
  bar.classList.remove('hidden');
  if (mode === 'progress') {
    bar.innerHTML = `<span>⬇ Mise à jour vers <b>${esc(u.version)}</b>… ${value}%</span><div class="progress"><i style="width:${value}%"></i></div>`;
  } else if (mode === 'installing') {
    bar.innerHTML = `<span>⚙ Installation de NounoursLauncher <b>${esc(u.version)}</b>… le launcher va redémarrer.</span>`;
  } else if (mode === 'countdown') {
    bar.innerHTML = `<span>✨ <b>NounoursLauncher ${esc(u.version)}</b> est prêt : redémarrage dans <b>${value}</b> s.</span>
       <button class="btn sm primary" id="doUpdate">Redémarrer maintenant</button>
       <button class="btn sm ghost" id="laterUpdate">Plus tard</button>`;
  } else {
    bar.innerHTML = `<span>✨ <b>NounoursLauncher ${esc(u.version)}</b> est disponible.</span>
       <button class="btn sm primary" id="doUpdate">Installer et redémarrer</button>
       <button class="iconbtn sm" id="laterUpdate" title="Plus tard">✕</button>`;
  }
}

async function downloadUpdate() {
  const u = state.update;
  if (UPD.downloaded) return;
  let total = 0, done = 0;
  await u.download(ev => {
    if (ev.event === 'Started') total = ev.data.contentLength || 0;
    if (ev.event === 'Progress') { done += ev.data.chunkLength; if (total) renderUpdateBar('progress', Math.min(100, Math.round(done * 100 / total))); }
  });
  UPD.downloaded = true;
}

/** Télécharge puis installe et redémarre */
async function installUpdate() {
  const u = state.update;
  if (!u || u.busy) return;
  u.busy = true;
  clearInterval(UPD.countdown);
  try {
    renderUpdateBar('progress', 0);
    await downloadUpdate();
    // Attendre la fin des installations d'applis en cours
    while (Object.keys(state.progress).length) await new Promise(r => setTimeout(r, 1000));
    renderUpdateBar('installing');
    await u.install();                      // Windows : l'installeur ferme puis relance le launcher
    const { relaunch } = await import('@tauri-apps/plugin-process');
    await relaunch();                       // macOS / Linux
  } catch (e) {
    u.busy = false;
    toast('Échec de la mise à jour : ' + (e?.message || e), 'err');
    renderUpdateBar();
  }
}

/** En cours d'utilisation : téléchargement silencieux, puis compte à rebours avant redémarrage */
async function autoDownloadThenRestart() {
  const u = state.update;
  try {
    u.busy = true;
    await downloadUpdate();
    u.busy = false;
  } catch (e) {
    u.busy = false;
    console.warn('updater download', e);
    renderUpdateBar();
    return;
  }
  let left = 15;
  renderUpdateBar('countdown', left);
  clearInterval(UPD.countdown);
  UPD.countdown = setInterval(() => {
    // Pas de redémarrage pendant une installation d'appli : on attend
    if (Object.keys(state.progress).length) return;
    left -= 1;
    if (left <= 0) { clearInterval(UPD.countdown); installUpdate(); }
    else renderUpdateBar('countdown', left);
  }, 1000);
}

function postponeUpdate() {
  clearInterval(UPD.countdown);
  $('#updateBar').classList.add('hidden');
  if (state.update) toast(`La version ${state.update.version} s'installera au prochain démarrage`);
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
  renderUpdateInfo();
  document.querySelectorAll('#channelSeg button').forEach(b => b.classList.toggle('on', b.dataset.v === state.channel));
  $('#versionInfo').textContent = `Version installée : ${state.appVersion} · ${PLATFORM_NAMES[state.platform] || state.platform}`;
  $('#apiUrl').value = apiUrl();
  $('#autoApps').checked = state.autoApps;
  document.querySelectorAll('.desktop-apps').forEach(el => el.classList.toggle('hidden', !canInstall()));
  $('#launcherUpdMode').classList.toggle('hidden', isMobilePlatform(state.platform));
  if (canInstall()) invoke('apps_folder').then(d => { $('#appsDir').textContent = d; }).catch(() => {});
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
    else if (kind === 'unlock') { if (act.dataset.rel) unlockFlow(p, +act.dataset.rel); else openDetail(p.slug); }
    else openDetail(p.slug);
    return;
  }

  const ri = t.closest('[data-relinstall]');
  if (ri) {
    const p = state.projects.find(x => x.slug === ri.dataset.slug);
    const r = (state.releases[ri.dataset.slug] || []).find(x => x.id === +ri.dataset.relinstall);
    const a = r && pickAsset(r);
    if (p && a) {
      const inst = state.installed[p.slug];
      if (inst && cmpVer(r.version, inst.version) < 0 && !confirm(`Revenir à ${relName(r)} ? Les mises à jour automatiques réinstalleront ensuite la dernière version (désactivable dans les réglages).`)) return;
      installProject(p, { pick: { rel: r, asset: a } });
    }
    return;
  }
  if (t.closest('#accountBtn')) { openAccount(); return; }
  const ap = t.closest('[data-admin-panel]');
  if (ap) { openAdminPanel(ap.dataset.adminPanel); return; }
  const pub = t.closest('[data-adm-pub]');
  if (pub) { if (confirm('Publier cette version maintenant ?')) adminSchedule(+pub.dataset.admPub, 'now'); return; }
  const sch = t.closest('[data-adm-sched]');
  if (sch) {
    const v = $(`[data-adm-date="${sch.dataset.admSched}"]`)?.value;
    if (!v) { toast('Choisis d\'abord la date et l\'heure de sortie', 'err'); return; }
    adminSchedule(+sch.dataset.admSched, 'at', v); return;
  }
  if (t.closest('[data-admin-watch]')) {
    try { const r = await adminCall('watch/run'); toast(r.new.length ? `${r.new.length} nouvelle(s) version(s) trouvée(s)` : 'Rien de nouveau'); await loadAdmin(); }
    catch (err) { toast(err.message, 'err'); }
    return;
  }
  if (t.closest('[data-admin-reload]')) { loadAdmin(); return; }
  if (t.id === 'loginStart') { startLogin(); return; }
  if (t.id === 'loginCancel') { clearTimeout(state.login?.timer); state.login = null; renderAccount(); return; }
  if (t.id === 'acctRefresh') { refreshUser().then(() => toast('Solde actualisé')); return; }
  if (t.id === 'acctLogout') {
    if (!confirm('Déconnecter le launcher de ton compte ?')) return;
    await authLogout(); state.user = null; LS.del('nl.user'); clearCache();
    renderAccount(); renderAccountBtn(); refreshCatalog({ silent: true }); toast('Déconnecté');
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
  if (t.id === 'laterUpdate') { postponeUpdate(); return; }
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
document.addEventListener('error', e => {
  if (e.target.tagName !== 'IMG') return;
  if (e.target.closest('.pic')) e.target.remove();
  else if (e.target.closest('.navbtn.account, .acct')) e.target.replaceWith(Object.assign(document.createElement('span'), { textContent: '👤' }));
}, true);

// Désactive le menu contextuel natif (sauf dans les champs de saisie)
document.addEventListener('contextmenu', e => { if (!e.target.closest('input, textarea, .md')) e.preventDefault(); });

// ---------- Démarrage ----------

applyPrefs();
renderAccountBtn();
window.addEventListener('nl-auth-lost', () => { state.user = null; LS.del('nl.user'); renderAccountBtn(); render(); });
// Retour du réseau : on actualise tout de suite
window.addEventListener('online', () => { refreshCatalog({ silent: true }); refreshStatus(); });

(async () => {
  try { await initNative(); } catch (e) { console.warn(e); }
  render();
  await refreshCatalog();
  refreshStatus();
  refreshUser();
  setInterval(refreshUser, 10 * 60 * 1000); // solde de pièces + versions en attente (admin)
  // Catalogue : vérifié toutes les minutes quand la fenêtre est visible (réponse minuscule si rien n'a changé),
  // toutes les 5 minutes sinon, et dès qu'on revient sur le launcher
  setInterval(() => { if (document.visibilityState === 'visible' || Date.now() - state.fetchedAt > 5 * 60 * 1000) refreshCatalog({ silent: true }); }, 60 * 1000);
  const comeBack = () => {
    if (document.visibilityState !== 'visible') return;
    if (Date.now() - state.fetchedAt > 15 * 1000) refreshCatalog({ silent: true });
    if (isAdmin()) loadAdmin();
  };
  window.addEventListener('focus', comeBack);
  document.addEventListener('visibilitychange', comeBack);
  setInterval(() => { if (state.autoApps) updateAll({ auto: true }); }, 5 * 60 * 1000);
  setInterval(refreshStatus, 60 * 1000);
  setTimeout(() => checkUpdate(false, { startup: true }), 1500);
  if (state.autoApps) setTimeout(() => updateAll({ auto: true }), 8000);
  setInterval(() => checkUpdate(false), 30 * 60 * 1000);
  // Retour sur la fenêtre après un moment : on revérifie
  window.addEventListener('focus', () => { if (Date.now() - UPD.lastCheck > 10 * 60 * 1000) checkUpdate(false); });
})();

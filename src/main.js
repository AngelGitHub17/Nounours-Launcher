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

const state = {
  platform: 'windows-x86_64',
  channel: LS.get('nl.channel', 'stable'),
  projects: [],
  status: {},
  view: LS.get('nl.view', 'all'),
  query: '',
  favs: new Set(LS.get('nl.favs', [])),
  offline: false,
  fetchedAt: 0,
  loading: true,
  error: null,
  appVersion: '0.0.0',
  update: null,
};

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

async function initNative() {
  if (!isTauri) return;
  const { invoke } = await import('@tauri-apps/api/core');
  const { getVersion } = await import('@tauri-apps/api/app');
  state.platform = await invoke('platform_key');
  state.appVersion = await getVersion();
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

function visibleProjects() {
  const q = state.query.trim().toLowerCase();
  return state.projects.filter(p => {
    if (state.view === 'fav' && !state.favs.has(p.slug)) return false;
    if (state.view !== 'all' && state.view !== 'fav' && p.type !== state.view) return false;
    if (!q) return true;
    return [p.name, p.short_desc, p.slug, ...(p.tags || [])].join(' ').toLowerCase().includes(q);
  });
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
    ...Object.entries(TYPES).filter(([t]) => counts[t]).map(([t, v]) => [t, v.icon, v.label, counts[t]]),
  ];
  $('#nav').innerHTML = items.map(([v, ic, label, n]) =>
    `<button class="navbtn ${state.view === v ? 'on' : ''}" data-view="${v}"><span class="ic">${ic}</span>${esc(label)}<span class="count">${n}</span></button>`
  ).join('');
}

function timeFr(ts) {
  return ts ? new Date(ts).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : '';
}

function renderNet() {
  const el = $('#netState');
  if (state.offline) {
    el.className = 'net off';
    el.innerHTML = `<i></i>Hors ligne${state.fetchedAt ? `<small>copie de ${timeFr(state.fetchedAt)}</small>` : ''}`;
  } else {
    const st = Object.values(state.status);
    const down = st.filter(s => !s.up).length;
    el.className = 'net ' + (down ? 'warn' : 'on');
    el.innerHTML = `<i></i>${down ? `${down} service${down > 1 ? 's' : ''} hors ligne` : 'Tout fonctionne'}<small>mis à jour à ${timeFr(state.fetchedAt)}</small>`;
  }
}

function renderHeader() {
  const v = state.view;
  $('#title').textContent = v === 'all' ? 'Tous les projets' : v === 'fav' ? 'Favoris' : TYPES[v]?.label ?? 'Projets';
  const n = visibleProjects().length;
  $('#subtitle').textContent = state.loading ? 'Chargement du catalogue…' : `${n} projet${n > 1 ? 's' : ''} · ${PLATFORM_NAMES[state.platform] || state.platform}`;
}

function iconHtml(p, cls = 'pic') {
  const style = `--acc:${esc(p.accent || '#4aa8ff')}`;
  return p.icon
    ? `<div class="${cls}" style="${style}"><img src="${esc(p.icon)}" alt="" loading="lazy"><span>${esc(p.name.charAt(0))}</span></div>`
    : `<div class="${cls}" style="${style}"><span>${esc(p.name.charAt(0))}</span></div>`;
}

/** Action principale d'un projet sur cette plateforme */
function primaryAction(p) {
  const rel = p.latest?.[state.platform];
  if (rel && rel.assets?.length) return { kind: 'download', label: `Télécharger`, rel };
  if (p.website_url) return { kind: 'open', label: 'Ouvrir' };
  return { kind: 'details', label: 'Voir' };
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
      ${rel ? `<span class="ver">v${esc(rel.version)}</span>` : ''}
      <button class="btn sm ${a.kind === 'details' ? 'ghost' : 'primary'}" data-act="${a.kind}" data-slug="${esc(p.slug)}">${a.label}</button>
    </div>
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
  } else if (!list.length) {
    html = state.view === 'fav' && !state.query
      ? `<div class="empty"><div class="big">★</div><h2>Aucun favori</h2><p>Clique sur l'étoile d'un projet pour le retrouver ici.</p></div>`
      : `<div class="empty"><div class="big">🔍</div><h2>Aucun résultat</h2><p>Essaie un autre mot-clé.</p></div>`;
  } else if (state.view === 'all' && !state.query) {
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
}

// ---------- Fiche projet ----------

function fmtSize(n) {
  if (!n) return '';
  const u = ['o', 'Ko', 'Mo', 'Go']; let i = 0;
  while (n >= 1024 && i < 3) { n /= 1024; i++; }
  return `${n.toFixed(i ? 1 : 0)} ${u[i]}`;
}

async function openDetail(slug) {
  const p = state.projects.find(x => x.slug === slug);
  if (!p) return;
  const rel = p.latest?.[state.platform];
  const asset = rel?.assets?.[0];
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
        ${p.website_url ? `<button class="btn primary" data-open="${esc(p.website_url)}">Ouvrir ${p.type === 'site' ? 'le site' : "l'appli web"} ↗</button>` : ''}
        ${asset ? `<button class="btn ${p.website_url ? '' : 'primary'}" data-open="${esc(asset.url)}">Télécharger pour ${esc(PLATFORM_NAMES[state.platform] || state.platform)}${asset.size ? ` · ${fmtSize(asset.size)}` : ''}</button>` : ''}
        ${p.repo_url ? `<button class="btn ghost" data-open="${esc(p.repo_url)}">Code source ↗</button>` : ''}
      </div>
      ${!asset && others.length ? `<p class="hint">Disponible aussi sur : ${esc(others.join(', '))}</p>` : ''}

      ${s ? `<div class="statusbox ${s.up ? 'ok' : 'bad'}">
        <span class="dot ${s.up ? 'ok' : 'bad'}"></span>
        <div><b>${s.up ? 'En ligne' : 'Hors ligne'}</b>${s.ms != null ? ` · ${s.ms} ms` : ''}
        ${s.minecraft ? `<div class="mc">${esc(s.minecraft.motd || '')}<br>${s.minecraft.online}/${s.minecraft.max} joueurs · ${esc(s.minecraft.version || '')}</div>` : ''}</div>
      </div>` : ''}

      ${p.description ? `<div class="md">${md(p.description)}</div>` : p.short_desc ? `<p class="lead">${esc(p.short_desc)}</p>` : ''}

      <div id="releases"></div>
    </div>`;
  showOverlay('#detail');

  // Historique des versions (chargé à part)
  if (!(p.platforms || []).some(x => x !== 'web')) return;
  try {
    const full = await loadProject(p.slug, state.platform, state.channel);
    const rels = (full.releases || []).filter(r => r.notes || r.assets.length);
    if (rels.length && $('#releases')) {
      $('#releases').innerHTML = `<h3 class="section">Nouveautés</h3>` + rels.slice(0, 8).map(r => `
        <div class="rel">
          <div class="rel-h"><b>v${esc(r.version)}</b>${r.channel === 'beta' ? '<span class="chip">bêta</span>' : ''}<span class="muted">${new Date(r.pub_date).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })}</span></div>
          ${r.notes ? `<div class="md">${md(r.notes)}</div>` : ''}
        </div>`).join('');
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

function renderSettings() {
  document.querySelectorAll('#channelSeg button').forEach(b => b.classList.toggle('on', b.dataset.v === state.channel));
  $('#versionInfo').textContent = `Version installée : ${state.appVersion} · ${PLATFORM_NAMES[state.platform] || state.platform}`;
  $('#apiUrl').value = apiUrl();
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
    const a = p && primaryAction(p);
    if (a?.kind === 'open') openExternal(p.website_url);
    else if (a?.kind === 'download') openExternal(a.rel.assets[0].url);
    else if (p) openDetail(p.slug);
    return;
  }

  const open = t.closest('[data-open]');
  if (open) { openExternal(open.dataset.open); return; }

  const ext = t.closest('a[data-ext]');
  if (ext) { e.preventDefault(); openExternal(ext.getAttribute('href')); return; }

  if (t.closest('[data-close]') || t.classList.contains('overlay')) { hideOverlays(); return; }
  if (t.closest('[data-retry]')) { refreshCatalog(); return; }

  const card = t.closest('.card[data-slug]');
  if (card) { openDetail(card.dataset.slug); return; }

  if (t.id === 'doUpdate') { installUpdate(); return; }
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
$('#clearCache').addEventListener('click', () => { clearCache(); refreshCatalog(); toast('Cache vidé'); });

// Icône introuvable → on garde l'initiale (pas de gestionnaire inline à cause de la CSP)
document.addEventListener('error', e => { if (e.target.tagName === 'IMG' && e.target.closest('.pic')) e.target.remove(); }, true);

// Désactive le menu contextuel natif (sauf dans les champs de saisie)
document.addEventListener('contextmenu', e => { if (!e.target.closest('input, textarea, .md')) e.preventDefault(); });

// ---------- Démarrage ----------

(async () => {
  try { await initNative(); } catch (e) { console.warn(e); }
  render();
  await refreshCatalog();
  refreshStatus();
  setInterval(() => refreshCatalog({ silent: true }), 5 * 60 * 1000);
  setInterval(refreshStatus, 60 * 1000);
  setTimeout(() => checkUpdate(false), 4000);
  setInterval(() => checkUpdate(false), 6 * 60 * 60 * 1000);
})();

<?php
/**
 * NounoursLauncher — panel d'administration
 * Accès : compte NounoursAccount administrateur (même connexion que sur les 5 sites).
 */
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
while (ob_get_level() > 0) { ob_end_clean(); }

if (!nl_na_load()) {
    http_response_code(500);
    exit('shared/auth.php introuvable — vérifie « shared_auth » dans launcher/config.php');
}
auth_require_login(); // pas connecté → page de connexion NounoursAccount, puis retour ici

if (!auth_is_admin()) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8"><title>Accès refusé</title><body style="font:16px system-ui;padding:40px">'
       . '<h1>Accès refusé</h1><p>Le catalogue du launcher est réservé aux administrateurs NounoursAccount.</p>'
       . '<p><a href="/">← Retour à NounoursCorp</a></p>');
}

nl_ensure_schema();
$csrf = nl_csrf_token();
$me = ['name' => auth_display_name(), 'avatar' => auth_avatar_src()];
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>NounoursLauncher — Catalogue</title>
<style>
:root{
  --bg:#0d1626; --bg2:#13213a; --glass:rgba(255,255,255,.07); --glass2:rgba(255,255,255,.12);
  --line:rgba(255,255,255,.14); --text:#e8eef8; --muted:#94a3bb; --accent:#4aa8ff; --accent2:#7fd0ff;
  --ok:#3ccf8e; --warn:#f2b84b; --bad:#ff6b6b; --r:14px;
}
*{box-sizing:border-box}
html,body{margin:0;height:100%}
body{font:14px/1.45 "Segoe UI",system-ui,-apple-system,sans-serif;color:var(--text);
  background:radial-gradient(1200px 600px at 10% -10%,#1d4f8f55,transparent 60%),
             radial-gradient(900px 500px at 100% 0%,#2a8fd955,transparent 55%),
             linear-gradient(180deg,var(--bg2),var(--bg));background-attachment:fixed}
button,input,select,textarea{font:inherit;color:inherit}
.glass{background:linear-gradient(180deg,rgba(255,255,255,.13),rgba(255,255,255,.05));border:1px solid var(--line);
  border-radius:var(--r);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);
  box-shadow:0 10px 30px rgba(0,0,0,.35),inset 0 1px 0 rgba(255,255,255,.18)}
.btn{border:1px solid var(--line);background:var(--glass2);padding:8px 14px;border-radius:10px;cursor:pointer;transition:.15s}
.btn:hover{background:rgba(255,255,255,.2)}
.btn.primary{background:linear-gradient(180deg,#5cb6ff,#2b7fd6);border-color:#6fc0ff;box-shadow:inset 0 1px 0 rgba(255,255,255,.45)}
.btn.primary:hover{filter:brightness(1.08)}
.btn.danger{background:rgba(255,107,107,.15);border-color:rgba(255,107,107,.45);color:#ffc4c4}
.btn.sm{padding:4px 10px;font-size:12.5px;border-radius:8px}
.btn:disabled{opacity:.5;cursor:default}
input,select,textarea{width:100%;background:rgba(0,0,0,.25);border:1px solid var(--line);border-radius:9px;padding:8px 10px;outline:none}
input:focus,select:focus,textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px #4aa8ff33}
select option{background:#13213a}
textarea{min-height:80px;resize:vertical}
label{display:block;font-size:12px;color:var(--muted);margin:0 0 4px}
.field{margin-bottom:12px}
.row{display:grid;gap:12px;grid-template-columns:1fr 1fr}
.row3{display:grid;gap:12px;grid-template-columns:1fr 1fr 1fr}
.hidden{display:none!important}
.muted{color:var(--muted)}
.logo{display:flex;align-items:center;gap:10px;font-weight:700;font-size:18px}
.logo i{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#7fd0ff,#2b7fd6);display:grid;place-items:center;font-style:normal;box-shadow:inset 0 1px 0 #fff8}
/* app */
header.top{position:sticky;top:0;z-index:5;display:flex;align-items:center;gap:14px;padding:12px 20px;
  background:rgba(13,22,38,.7);backdrop-filter:blur(14px);border-bottom:1px solid var(--line)}
header.top .grow{flex:1}
header.top input{max-width:280px}
main{padding:20px;max-width:1300px;margin:0 auto}
.filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.chip{padding:5px 12px;border-radius:999px;border:1px solid var(--line);background:var(--glass);cursor:pointer;font-size:12.5px}
.chip.on{background:var(--accent);border-color:var(--accent);color:#04121f;font-weight:600}
.grid{display:grid;gap:14px;grid-template-columns:repeat(auto-fill,minmax(250px,1fr))}
.card{padding:14px;cursor:pointer;transition:.15s;position:relative;overflow:hidden}
.card:hover{transform:translateY(-2px);border-color:rgba(255,255,255,.3)}
.card .bar{position:absolute;left:0;top:0;right:0;height:3px}
.card .h{display:flex;gap:12px;align-items:center}
.ico{width:46px;height:46px;border-radius:12px;flex:none;background:var(--glass2) center/cover no-repeat;display:grid;place-items:center;font-weight:700;font-size:18px;border:1px solid var(--line)}
.card .name{font-weight:600;font-size:15px}
.card .desc{color:var(--muted);font-size:12.5px;margin-top:8px;min-height:18px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.meta{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
.badge{font-size:11px;padding:2px 8px;border-radius:999px;background:var(--glass2);border:1px solid var(--line)}
.b-online{background:#3ccf8e22;border-color:#3ccf8e66;color:#a6f0cf}
.b-beta{background:#4aa8ff22;border-color:#4aa8ff66;color:#bfe1ff}
.b-dev{background:#f2b84b22;border-color:#f2b84b66;color:#ffe1a6}
.b-archived{opacity:.6}
.b-private,.b-members{background:#b07cff22;border-color:#b07cff66;color:#e0ccff}
.add-card{display:grid;place-items:center;min-height:120px;border-style:dashed;color:var(--muted);font-size:15px}
/* drawer */
#drawer{position:fixed;inset:0;z-index:20;display:flex;justify-content:flex-end;background:rgba(0,0,0,.45)}
#drawer .panel{width:min(820px,100%);height:100%;overflow:auto;border-radius:0;border-width:0 0 0 1px;padding:0;background:rgba(16,28,48,.92)}
.panel .ph{position:sticky;top:0;z-index:2;display:flex;align-items:center;gap:12px;padding:14px 20px;background:rgba(16,28,48,.95);border-bottom:1px solid var(--line)}
.panel .ph h2{margin:0;font-size:17px;flex:1}
.panel section{padding:18px 20px;border-bottom:1px solid var(--line)}
.panel h3{margin:0 0 12px;font-size:13px;letter-spacing:.06em;text-transform:uppercase;color:var(--accent2)}
.iconpick{display:flex;gap:12px;align-items:center}
.iconpick .ico{width:64px;height:64px;border-radius:16px}
.release{padding:12px;margin-bottom:10px;border-radius:12px;background:rgba(0,0,0,.2);border:1px solid var(--line)}
.release .rh{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.release .rh b{font-size:15px}
.release .rh .grow{flex:1}
.assets{width:100%;border-collapse:collapse;margin-top:10px;font-size:12.5px}
.assets td,.assets th{padding:5px 6px;border-top:1px solid var(--line);text-align:left;vertical-align:middle}
.assets th{color:var(--muted);font-weight:500}
.assets td.url{max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.assetform{display:grid;gap:8px;grid-template-columns:150px 110px 1fr;margin-top:10px}
.assetform .full{grid-column:1/-1}
.toggle{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--text);margin:0;cursor:pointer}
.toggle input{width:auto}
details summary{cursor:pointer;color:var(--accent2);font-size:13px;margin-top:6px}
#toast{position:fixed;bottom:20px;right:20px;z-index:50;display:flex;flex-direction:column;gap:8px;align-items:flex-end;pointer-events:none}
.t{padding:10px 16px;border-radius:10px;background:#1e3354;border:1px solid var(--line);box-shadow:0 8px 24px #0008;animation:fade .2s}
.t.err{background:#4a1f26;border-color:#ff6b6b88}
@keyframes fade{from{opacity:0;transform:translateY(6px)}}
.empty{padding:40px;text-align:center;color:var(--muted)}
.me{display:flex;align-items:center;gap:8px;color:var(--text);text-decoration:none;padding:4px 10px 4px 4px;border-radius:999px;border:1px solid var(--line);background:var(--glass)}
.me img{width:30px;height:30px;border-radius:50%;object-fit:cover}
.settings{padding:16px 20px;margin-bottom:18px}
.settings h3{margin:0 0 10px;font-size:13px;letter-spacing:.06em;text-transform:uppercase;color:var(--accent2)}
code,pre{background:rgba(0,0,0,.3);border:1px solid var(--line);border-radius:6px;padding:2px 6px;font-family:Consolas,monospace;font-size:12.5px;word-break:break-all}
pre{padding:10px;white-space:pre-wrap;user-select:all}
@media(max-width:640px){.me span{display:none}}
@media(max-width:640px){.row,.row3,.assetform{grid-template-columns:1fr}header.top input{display:none}}
</style>
</head>
<body>

<div id="app">
  <header class="top">
    <div class="logo"><i>N</i> Catalogue du launcher</div>
    <div class="grow"></div>
    <input id="search" placeholder="Rechercher un projet…">
    <button class="btn primary" id="newBtn">+ Nouveau projet</button>
    <button class="btn" id="settingsBtn" title="Réglages">⚙</button>
    <a class="me" href="/" title="Retour à NounoursCorp"><img src="<?= htmlspecialchars($me['avatar'], ENT_QUOTES) ?>" alt=""><span><?= htmlspecialchars($me['name'], ENT_QUOTES) ?></span></a>
  </header>
  <main>
    <div class="glass settings hidden" id="settings">
      <h3>Réglages</h3>
      <p><b>Adresse de l'API</b> (à mettre dans le launcher et dans la variable GitHub <code>NL_API_URL</code>) :<br><code id="apiUrl"></code></p>
      <p><b>Jeton CI</b> — permet à GitHub Actions de publier tes versions toutes seules. <span id="ciState" class="muted"></span></p>
      <p><button class="btn primary sm" id="ciNew">Générer un jeton CI</button> <button class="btn danger sm hidden" id="ciRevoke">Révoquer</button></p>
      <div id="ciShow" class="hidden"><p>Copie-le maintenant dans le secret GitHub <code>NL_CI_TOKEN</code> : il ne sera plus jamais affiché.</p><pre id="ciToken"></pre></div>
    </div>
    <div class="filters" id="filters"></div>
    <div class="grid" id="grid"></div>
  </main>
</div>

<div id="drawer" class="hidden">
  <div class="panel glass">
    <div class="ph"><h2 id="dTitle">Projet</h2>
      <button class="btn danger sm hidden" id="delProject">Supprimer</button>
      <button class="btn primary" id="saveProject">Enregistrer</button>
      <button class="btn" id="closeDrawer">✕</button>
    </div>
    <form id="pForm" autocomplete="off">
      <section>
        <h3>Identité</h3>
        <div class="iconpick field">
          <div class="ico" id="iconPrev">?</div>
          <div style="flex:1">
            <label>Icône (PNG/JPEG/WebP, carrée de préférence)</label>
            <div style="display:flex;gap:8px"><input name="icon_url" placeholder="URL ou upload →"><button type="button" class="btn sm" data-upload="icon_url">Uploader</button></div>
          </div>
        </div>
        <div class="row">
          <div class="field"><label>Nom *</label><input name="name" required></div>
          <div class="field"><label>Slug (identifiant unique)</label><input name="slug" placeholder="auto"></div>
        </div>
        <div class="row3">
          <div class="field"><label>Type</label><select name="type">
            <option value="site">Site web</option><option value="app">Application</option><option value="plugin">Plugin Minecraft</option>
            <option value="service">Service</option><option value="bot">Bot</option><option value="tool">Outil</option><option value="launcher">Launcher</option></select></div>
          <div class="field"><label>Statut</label><select name="status">
            <option value="online">En ligne</option><option value="beta">Bêta</option><option value="dev">En développement</option><option value="archived">Archivé</option></select></div>
          <div class="field"><label>Visibilité</label><select name="visibility">
            <option value="public">Public</option><option value="members">Membres NounoursAccount</option><option value="private">Privé (admin)</option></select></div>
        </div>
        <div class="field"><label>Description courte</label><input name="short_desc" maxlength="255"></div>
        <div class="field"><label>Description complète (Markdown)</label><textarea name="description"></textarea></div>
      </section>
      <section>
        <h3>Liens & apparence</h3>
        <div class="row">
          <div class="field"><label>Site / lien « Ouvrir »</label><input name="website_url" placeholder="https://"></div>
          <div class="field"><label>Dépôt</label><input name="repo_url" placeholder="https://github.com/…"></div>
        </div>
        <div class="row">
          <div class="field"><label>Vérification d'état</label><input name="status_check_url" placeholder="https://… · tcp://hote:port · mc://hote:25565"></div>
          <div class="field"><label>Tags (séparés par des virgules)</label><input name="tags"></div>
        </div>
        <div class="row3">
          <div class="field"><label>Couleur d'accent</label><div style="display:flex;gap:6px"><input type="color" id="accentPick" style="width:46px;padding:2px"><input name="accent_color" placeholder="#4aa8ff"></div></div>
          <div class="field"><label>Ordre d'affichage</label><input name="sort_order" type="number" value="100"></div>
          <div class="field"><label>Bannière</label><div style="display:flex;gap:6px"><input name="banner_url" placeholder="URL"><button type="button" class="btn sm" data-upload="banner_url">↑</button></div></div>
        </div>
      </section>
    </form>
    <section id="relSection" class="hidden">
      <h3>Versions</h3>
      <div id="releases"></div>
      <details id="newRelDetails">
        <summary>+ Ajouter une version</summary>
        <form id="relForm" style="margin-top:10px">
          <div class="row3">
            <div class="field"><label>Version *</label><input name="version" placeholder="1.0.0" required></div>
            <div class="field"><label>Canal</label><select name="channel"><option value="stable">Stable</option><option value="beta">Bêta</option></select></div>
            <div class="field" style="display:flex;flex-direction:column;justify-content:flex-end;gap:6px">
              <label class="toggle"><input type="checkbox" name="published" checked> Publiée</label>
              <label class="toggle"><input type="checkbox" name="mandatory"> Obligatoire</label>
            </div>
          </div>
          <div class="field"><label>Notes de version (Markdown)</label><textarea name="notes" placeholder="- Nouveauté…"></textarea></div>
          <button class="btn primary">Créer la version</button>
        </form>
      </details>
    </section>
  </div>
</div>

<input type="file" id="fileInput" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden">
<div id="toast"></div>

<script>
'use strict';
const API = '../api.php';
const PLATFORMS = {
  'windows-x86_64':'Windows x64','windows-aarch64':'Windows ARM','darwin-aarch64':'macOS Apple Silicon','darwin-x86_64':'macOS Intel',
  'linux-x86_64':'Linux x64','linux-aarch64':'Linux ARM','android':'Android','ios':'iOS','web':'Web','server':'Serveur (plugin)'
};
const KINDS = ['installer','updater','portable','msi','apk','dmg','appimage','deb','rpm','jar','zip','store','web'];
const TYPES = {site:'Sites',app:'Applications',plugin:'Plugins',service:'Services',bot:'Bots',tool:'Outils',launcher:'Launcher'};
const STATUS = {online:'En ligne',beta:'Bêta',dev:'En dev',archived:'Archivé'};

const $ = (s, el = document) => el.querySelector(s);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const CSRF = <?= json_encode($csrf) ?>;
let projects = [], current = null, filterType = 'all';

function toast(msg, err = false) {
  const t = document.createElement('div');
  t.className = 't' + (err ? ' err' : '');
  t.textContent = msg;
  $('#toast').append(t);
  setTimeout(() => t.remove(), err ? 5000 : 2600);
}

async function api(route, body = {}, isForm = false) {
  const opt = { method: 'POST', credentials: 'same-origin', headers: { 'X-NL-CSRF': CSRF } };
  if (isForm) opt.body = body; else { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
  const res = await fetch(API + '?r=' + route, opt);
  let data; try { data = await res.json(); } catch { data = { error: 'Réponse invalide du serveur (' + res.status + ')' }; }
  if (res.status === 401 || res.status === 419) { toast((data.error || 'Session expirée') + ' — rechargement…', true); setTimeout(() => location.reload(), 1500); throw new Error(data.error || 'Session expirée'); }
  if (!res.ok || data.error) throw new Error(data.error || 'Erreur ' + res.status);
  return data;
}

// ---------- Réglages ----------
async function loadSettings() {
  try {
    const s = await api('admin/settings');
    $('#apiUrl').textContent = s.api_url;
    $('#ciState').textContent = s.ci_token_set ? `(un jeton existe, créé le ${new Date(s.ci_token_at).toLocaleString('fr-FR')})` : '(aucun jeton pour l\'instant)';
    $('#ciNew').textContent = s.ci_token_set ? 'Remplacer le jeton CI' : 'Générer un jeton CI';
    $('#ciRevoke').classList.toggle('hidden', !s.ci_token_set);
  } catch (e) { toast(e.message, true); }
}
$('#settingsBtn').onclick = () => { const s = $('#settings'); s.classList.toggle('hidden'); if (!s.classList.contains('hidden')) loadSettings(); };
$('#ciNew').onclick = async () => {
  if ($('#ciRevoke').classList.contains('hidden') === false && !confirm('Remplacer le jeton ? L\'ancien ne marchera plus dans GitHub.')) return;
  try { const r = await api('admin/ci-token/new'); $('#ciToken').textContent = r.token; $('#ciShow').classList.remove('hidden'); loadSettings(); }
  catch (e) { toast(e.message, true); }
};
$('#ciRevoke').onclick = async () => {
  if (!confirm('Révoquer le jeton CI ? Les publications automatiques s\'arrêteront.')) return;
  try { await api('admin/ci-token/revoke'); $('#ciShow').classList.add('hidden'); toast('Jeton révoqué'); loadSettings(); }
  catch (e) { toast(e.message, true); }
};

async function load() {
  try { projects = (await api('admin/projects')).projects; render(); if (current) openProject(projects.find(p => p.id === current.id) || null, true); }
  catch (e) { toast(e.message, true); }
}

// ---------- Grille ----------
function latestVersion(p) {
  const r = p.releases.find(r => r.published);
  return r ? r.version : null;
}
function iconHtml(p, el) {
  const url = p.icon_abs || p.icon_url;
  if (url) { el.style.backgroundImage = `url("${encodeURI(url)}")`; el.textContent = ''; }
  else { el.style.backgroundImage = ''; el.textContent = (p.name || '?').charAt(0).toUpperCase(); }
}
function render() {
  const counts = {};
  projects.forEach(p => counts[p.type] = (counts[p.type] || 0) + 1);
  $('#filters').innerHTML = [`<span class="chip ${filterType==='all'?'on':''}" data-t="all">Tout (${projects.length})</span>`]
    .concat(Object.keys(TYPES).filter(t => counts[t]).map(t => `<span class="chip ${filterType===t?'on':''}" data-t="${t}">${TYPES[t]} (${counts[t]})</span>`)).join('');
  const q = $('#search').value.toLowerCase().trim();
  const list = projects.filter(p => (filterType === 'all' || p.type === filterType)
    && (!q || (p.name + ' ' + p.slug + ' ' + p.tags + ' ' + p.short_desc).toLowerCase().includes(q)));
  const grid = $('#grid');
  grid.innerHTML = '';
  list.forEach(p => {
    const c = document.createElement('div');
    c.className = 'card glass';
    const v = latestVersion(p);
    c.innerHTML = `<div class="bar" style="background:${esc(p.accent_color || 'transparent')}"></div>
      <div class="h"><div class="ico"></div><div><div class="name">${esc(p.name)}</div><div class="muted" style="font-size:12px">${esc(p.slug)}</div></div></div>
      <div class="desc">${esc(p.short_desc)}</div>
      <div class="meta"><span class="badge b-${esc(p.status)}">${esc(STATUS[p.status] || p.status)}</span>
        <span class="badge">${esc(TYPES[p.type] || p.type)}</span>
        ${v ? `<span class="badge">v${esc(v)}</span>` : ''}
        ${p.visibility !== 'public' ? `<span class="badge b-${esc(p.visibility)}">${p.visibility === 'private' ? 'Privé' : 'Membres'}</span>` : ''}</div>`;
    iconHtml(p, $('.ico', c));
    c.onclick = () => openProject(p);
    grid.append(c);
  });
  const add = document.createElement('div');
  add.className = 'card glass add-card'; add.textContent = '+ Ajouter un projet';
  add.onclick = () => openProject(null);
  grid.append(add);
}
$('#filters').addEventListener('click', e => { const t = e.target.closest('.chip'); if (t) { filterType = t.dataset.t; render(); } });
$('#search').addEventListener('input', render);
$('#newBtn').onclick = () => openProject(null);

// ---------- Fiche projet ----------
const pForm = $('#pForm');
function openProject(p, keepScroll = false) {
  current = p;
  $('#drawer').classList.remove('hidden');
  $('#dTitle').textContent = p ? p.name : 'Nouveau projet';
  $('#delProject').classList.toggle('hidden', !p);
  $('#relSection').classList.toggle('hidden', !p);
  if (!keepScroll) {
    pForm.reset();
    const v = p || { type: filterType !== 'all' ? filterType : 'app', status: 'dev', visibility: 'public', sort_order: 100 };
    for (const el of pForm.elements) if (el.name) el.value = v[el.name] ?? '';
    $('#accentPick').value = /^#[0-9a-f]{6}$/i.test(v.accent_color || '') ? v.accent_color : '#4aa8ff';
    $('.panel').scrollTop = 0;
  }
  updateIconPrev();
  if (p) renderReleases(p);
}
function updateIconPrev() {
  const url = pForm.icon_url.value.trim();
  iconHtml({ name: pForm.name.value, icon_url: url && !/^https?:/i.test(url) ? '../' + url : url }, $('#iconPrev'));
}
pForm.icon_url.addEventListener('input', updateIconPrev);
pForm.name.addEventListener('input', updateIconPrev);
$('#accentPick').addEventListener('input', e => pForm.accent_color.value = e.target.value);
$('#closeDrawer').onclick = () => { $('#drawer').classList.add('hidden'); current = null; };
$('#drawer').addEventListener('mousedown', e => { if (e.target.id === 'drawer') $('#closeDrawer').click(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !$('#drawer').classList.contains('hidden')) $('#closeDrawer').click(); });

$('#saveProject').onclick = async () => {
  if (!pForm.reportValidity()) return;
  const body = Object.fromEntries(new FormData(pForm));
  if (current) body.id = current.id;
  try {
    const r = await api('admin/project/save', body);
    toast('Projet enregistré ✓');
    current = { id: r.id };
    await load();
  } catch (e) { toast(e.message, true); }
};
$('#delProject').onclick = async () => {
  if (!current || !confirm(`Supprimer « ${current.name} » et toutes ses versions ?`)) return;
  try { await api('admin/project/delete', { id: current.id }); toast('Projet supprimé'); $('#closeDrawer').click(); await load(); }
  catch (e) { toast(e.message, true); }
};

// Upload d'images
let uploadTarget = null;
document.querySelectorAll('[data-upload]').forEach(b => b.onclick = () => { uploadTarget = b.dataset.upload; $('#fileInput').click(); });
$('#fileInput').onchange = async e => {
  const f = e.target.files[0]; e.target.value = '';
  if (!f || !uploadTarget) return;
  const fd = new FormData(); fd.append('file', f);
  try { const r = await api('admin/upload', fd, true); pForm[uploadTarget].value = r.path; updateIconPrev(); toast('Image envoyée — pense à enregistrer'); }
  catch (err) { toast(err.message, true); }
};

// ---------- Versions & fichiers ----------
function fmtSize(n) { if (!n) return '—'; const u = ['o','Ko','Mo','Go']; let i = 0; while (n >= 1024 && i < 3) { n /= 1024; i++; } return n.toFixed(i ? 1 : 0) + ' ' + u[i]; }

function renderReleases(p) {
  const box = $('#releases');
  box.innerHTML = p.releases.length ? '' : '<p class="muted">Aucune version pour l\'instant. Pour un site web, ce n\'est pas nécessaire.</p>';
  p.releases.forEach(r => {
    const el = document.createElement('div');
    el.className = 'release';
    el.innerHTML = `<div class="rh"><b>v${esc(r.version)}</b>
        <span class="badge ${r.channel === 'beta' ? 'b-beta' : ''}">${r.channel === 'beta' ? 'Bêta' : 'Stable'}</span>
        ${r.mandatory ? '<span class="badge b-dev">Obligatoire</span>' : ''}
        <span class="muted" style="font-size:12px">${esc(new Date(r.pub_date.replace(' ', 'T') + 'Z').toLocaleString('fr-FR'))}</span>
        <span class="grow"></span>
        <label class="toggle"><input type="checkbox" data-act="pub" ${r.published ? 'checked' : ''}> Publiée</label>
        <button class="btn danger sm" data-act="del">Supprimer</button></div>
      ${r.notes ? `<div class="muted" style="white-space:pre-wrap;font-size:12.5px;margin-top:6px">${esc(r.notes)}</div>` : ''}
      <table class="assets"><thead><tr><th>Plateforme</th><th>Type</th><th>Fichier</th><th>Taille</th><th></th></tr></thead><tbody>
      ${r.assets.map(a => `<tr><td>${esc(PLATFORMS[a.platform] || a.platform)}</td><td>${esc(a.kind)}${a.signature ? ' 🔏' : ''}</td>
        <td class="url"><a href="${esc(a.url)}" target="_blank" rel="noopener" style="color:var(--accent2)">${esc(a.url.split('/').pop() || a.url)}</a></td>
        <td>${fmtSize(a.size)}</td><td><button class="btn sm" data-del-asset="${a.id}">✕</button></td></tr>`).join('')
        || '<tr><td colspan="5" class="muted">Aucun fichier</td></tr>'}
      </tbody></table>
      <details><summary>+ Ajouter un fichier</summary>
        <form class="assetform">
          <select name="platform">${Object.entries(PLATFORMS).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select>
          <select name="kind">${KINDS.map(k => `<option>${k}</option>`).join('')}</select>
          <input name="url" placeholder="https://github.com/…/releases/download/…" required>
          <input name="size" type="number" placeholder="Taille (octets)">
          <input name="sha256" placeholder="SHA-256 (64 hex)" style="grid-column:span 2">
          <input name="install_args" placeholder="Arguments d'install (ex. /S)">
          <input name="signature" placeholder="Signature (updater Tauri)" style="grid-column:span 2">
          <div class="full"><button class="btn primary sm">Ajouter le fichier</button></div>
        </form></details>`;
    $('[data-act="pub"]', el).onchange = async e => {
      try { await api('admin/release/save', { ...r, published: e.target.checked }); toast(e.target.checked ? 'Version publiée' : 'Version dépubliée'); await load(); }
      catch (err) { toast(err.message, true); e.target.checked = !e.target.checked; }
    };
    $('[data-act="del"]', el).onclick = async () => {
      if (!confirm(`Supprimer la version ${r.version} et ses fichiers ?`)) return;
      try { await api('admin/release/delete', { id: r.id }); toast('Version supprimée'); await load(); } catch (err) { toast(err.message, true); }
    };
    el.querySelectorAll('[data-del-asset]').forEach(b => b.onclick = async () => {
      try { await api('admin/asset/delete', { id: +b.dataset.delAsset }); toast('Fichier retiré'); await load(); } catch (err) { toast(err.message, true); }
    });
    $('form', el).onsubmit = async e => {
      e.preventDefault();
      const body = Object.fromEntries(new FormData(e.target));
      body.release_id = r.id;
      try { await api('admin/asset/save', body); toast('Fichier ajouté ✓'); await load(); } catch (err) { toast(err.message, true); }
    };
    box.append(el);
  });
}

$('#relForm').onsubmit = async e => {
  e.preventDefault();
  const f = e.target;
  const body = { project_id: current.id, version: f.version.value, channel: f.channel.value, notes: f.notes.value,
                 published: f.published.checked, mandatory: f.mandatory.checked };
  try { await api('admin/release/save', body); toast('Version créée ✓'); f.reset(); f.published.checked = true; $('#newRelDetails').open = false; await load(); }
  catch (err) { toast(err.message, true); }
};

load();
</script>
</body>
</html>

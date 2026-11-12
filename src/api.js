// Accès au catalogue NounoursLauncher (API PHP sur nounourscorp.fr)
// Le catalogue est gardé en cache local : le launcher reste utilisable hors ligne.

export const DEFAULT_API = 'https://nounourscorp.fr/launcher/api.php';

const LS = {
  get(k, d = null) { try { const v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch { return d; } },
  set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch { /* stockage indisponible */ } },
  del(k) { try { localStorage.removeItem(k); } catch { /* idem */ } },
};
export { LS };

export function apiUrl() {
  return LS.get('nl.api', DEFAULT_API);
}

// ---------- Compte NounoursAccount (jeton du launcher) ----------
export const auth = {
  get token() { return LS.get('nl.token'); },
  set token(v) { if (v) LS.set('nl.token', v); else LS.del('nl.token'); },
};

async function getJson(route, params = {}, { etag, timeout = 10000, method = 'GET', body } = {}) {
  const url = new URL(apiUrl());
  url.searchParams.set('r', route);
  for (const [k, v] of Object.entries(params)) if (v != null && v !== '') url.searchParams.set(k, v);
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), timeout);
  try {
    const headers = {};
    if (etag) headers['If-None-Match'] = etag;
    if (auth.token) headers.Authorization = 'Bearer ' + auth.token;
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    const res = await fetch(url, { method, headers, signal: ctrl.signal, cache: 'no-store', body: body !== undefined ? JSON.stringify(body) : undefined });
    if (res.status === 401 && auth.token && route !== 'auth/logout') {
      // Jeton révoqué ou expiré côté serveur : on se considère déconnecté
      auth.token = null;
      window.dispatchEvent(new CustomEvent('nl-auth-lost'));
    }
    if (res.status === 304) return { notModified: true };
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.error) { const err = new Error(data.error || `Erreur ${res.status}`); err.status = res.status; err.data = data; throw err; }
    return { data, etag: res.headers.get('ETag') };
  } catch (e) {
    if (e.name === 'AbortError') throw new Error('Le serveur ne répond pas');
    throw e;
  } finally {
    clearTimeout(t);
  }
}

/**
 * Catalogue complet pour cette plateforme et ce canal.
 * Renvoie { projects, offline, fetchedAt } — en cas d'échec réseau, la dernière copie connue.
 */
export async function loadCatalog(platform, channel) {
  // Une copie par compte : les liens et verrous dépendent de l'utilisateur
  const key = `nl.catalog.${platform}.${channel}.${auth.token ? auth.token.slice(-8) : 'anon'}`;
  const cached = LS.get(key);
  try {
    const r = await getJson('catalog', { platform, channel }, { etag: cached?.etag });
    if (r.notModified && cached) {
      cached.fetchedAt = Date.now();
      LS.set(key, cached);
      return { projects: cached.projects, offline: false, fetchedAt: cached.fetchedAt };
    }
    const entry = { etag: r.etag, projects: r.data.projects || [], fetchedAt: Date.now() };
    LS.set(key, entry);
    return { projects: entry.projects, offline: false, fetchedAt: entry.fetchedAt };
  } catch (e) {
    if (cached) return { projects: cached.projects, offline: true, fetchedAt: cached.fetchedAt, error: e.message };
    throw e;
  }
}

export async function loadStatus() {
  try { return (await getJson('status', {}, { timeout: 15000 })).data.status || {}; }
  catch { return null; }
}

export async function loadProject(slug, platform, channel) {
  return (await getJson('project', { slug, platform, channel })).data.project;
}

export function clearCache() {
  Object.keys(localStorage).filter(k => k.startsWith('nl.catalog.')).forEach(k => LS.del(k));
}

const post = (route, body = {}) => getJson(route, {}, { method: 'POST', body }).then(r => r.data);

/** Connexion par code : { code, url, poll, expires_in } */
export const authStart = label => post('auth/start', { label });
/** { pending: true } tant que le code n'est pas validé, puis { token, user } */
export const authPoll = poll => post('auth/poll', { poll });
export async function authMe() { return (await getJson('auth/me')).data.user; }
export async function authLogout() { try { await post('auth/logout'); } catch { /* déjà invalide */ } auth.token = null; }
/** Débloque une version avec des pièces : { ok, coins } */
export const unlockRelease = id => post('unlock', { release_id: id });

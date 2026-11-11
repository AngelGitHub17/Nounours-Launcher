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

async function getJson(route, params = {}, { etag, timeout = 10000 } = {}) {
  const url = new URL(apiUrl());
  url.searchParams.set('r', route);
  for (const [k, v] of Object.entries(params)) if (v != null && v !== '') url.searchParams.set(k, v);
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), timeout);
  try {
    const headers = {};
    if (etag) headers['If-None-Match'] = etag;
    const res = await fetch(url, { headers, signal: ctrl.signal, cache: 'no-store' });
    if (res.status === 304) return { notModified: true };
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.error) throw new Error(data.error || `Erreur ${res.status}`);
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
  const key = `nl.catalog.${platform}.${channel}`;
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

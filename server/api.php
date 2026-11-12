<?php
/**
 * NounoursLauncher — API du catalogue
 * Routage par paramètre ?r= (le routage par chemin est peu fiable sur OVH mutualisé).
 * Toutes les écritures passent en POST (OVH bloque PUT/DELETE).
 *
 * Public (GET) :
 *   ?r=catalog[&platform=windows-x86_64][&channel=stable|beta]
 *   ?r=project&slug=xxx[&platform=...][&channel=...]
 *   ?r=update/check&slug=xxx&platform=...&current=1.0.0[&channel=...][&kind=...]
 *   ?r=launcher/update&target={{target}}&arch={{arch}}&current={{current_version}}[&channel=...]  (format Tauri)
 *   ?r=status
 *   ?r=meta
 *   ?r=asset&id=N[&u&e&s]            → téléchargement relais (fichiers protégés, dépôts privés, dossiers du serveur)
 *   ?r=auth/start, auth/poll (POST)  → connexion du launcher avec un code ; ?r=auth/me ; auth/logout (POST)
 *   ?r=unlock (POST, connecté)       → débloquer une version avec des pièces
 *   ?r=download[&slug=xxx][&platform=auto|windows-x86_64|…][&kind=…][&channel=…]  → redirige vers le bon installeur
 * Admin (POST, Authorization: Bearer <jeton>) :
 *   ?r=admin/projects, admin/project/save, admin/project/delete,
 *   admin/release/save, admin/release/delete, admin/asset/save, admin/asset/delete, admin/upload
 * CI (POST, jeton CI) :
 *   ?r=ci/release
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/catalog.php';

set_error_handler(function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) { return false; } // avertissement masqué par @
    throw new ErrorException($str, 0, $no, $file, $line);
});

$route = trim((string)($_GET['r'] ?? ''), '/');
$isAdmin = str_starts_with($route, 'admin/') || str_starts_with($route, 'ci/');
nl_cors($isAdmin);

// Surveillance des nouvelles versions : après la réponse, au plus toutes les 15 min
// (seulement si PHP peut renvoyer la réponse avant : sinon le launcher attendrait GitHub → cron.php)
if (!$isAdmin && $route !== 'asset' && $route !== 'download' && function_exists('fastcgi_finish_request')) {
    register_shutdown_function(function (): void {
        @fastcgi_finish_request();
        try { nl_watch_tick(); } catch (Throwable $e) { nl_watch_log('tick : ' . $e->getMessage()); }
    });
}

try {
    nl_ensure_schema();
    nl_setup_pandorpacks();
    switch ($route) {
        case '':
        case 'meta':
            nl_json([
                'name'        => 'NounoursLauncher API',
                'api_version' => NL_API_VERSION,
                'server_version' => NL_SERVER_VERSION,
                'github_changelog' => function_exists('nl_gh_changelog'),
                'platforms'   => NL_PLATFORMS,
                'kinds'       => NL_KINDS,
                'types'       => NL_TYPES,
                'statuses'    => NL_STATUSES,
                'time'        => gmdate('c'),
            ]);

        case 'catalog':
            route_catalog();

        case 'project':
            route_project();

        case 'update/check':
            route_update_check();

        case 'launcher/update':
            route_launcher_update();

        case 'download':
            route_download();

        case 'asset':
            route_asset();

        case 'auth/start':  route_auth_start();
        case 'auth/poll':   route_auth_poll();
        case 'auth/me':     route_auth_me();
        case 'auth/logout': route_auth_logout();
        case 'unlock':      route_unlock();

        case 'status':
            require __DIR__ . '/lib/status.php';
            nl_json(['status' => (object)nl_status_all(nl_viewer_level())], 200, ['Cache-Control' => 'public, max-age=30']);

        default:
            if ($isAdmin) {
                require __DIR__ . '/lib/admin.php';
                nl_admin_dispatch($route);
            }
            nl_fail('Route inconnue', 404);
    }
} catch (Throwable $e) {
    $detail = $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
    $ref = nl_log_error($route, $detail);
    $msg = 'Erreur serveur (réf. ' . $ref . ')';
    // Détail visible en mode debug, ou pour un appel déjà authentifié (admin / CI)
    if ((nl_config()['debug'] ?? false) || !empty($GLOBALS['NL_TRUSTED'])) { $msg .= ' : ' . $detail; }
    nl_json(['error' => $msg], 500);
}

// ---------------------------------------------------------------------------

function q_platform(bool $required = false): ?string
{
    $p = $_GET['platform'] ?? null;
    if ($p === null || $p === '') {
        if ($required) { nl_fail('Paramètre platform requis'); }
        return null;
    }
    if (!in_array($p, NL_PLATFORMS, true)) { nl_fail('Plateforme inconnue'); }
    return $p;
}

function q_channel(): string
{
    // Le plugin updater de Tauri ne peut pas changer l'URL : le canal arrive alors par en-tête
    $c = $_GET['channel'] ?? ($_SERVER['HTTP_X_NL_CHANNEL'] ?? 'stable');
    return $c === 'beta' ? 'beta' : 'stable';
}

function route_catalog(): never
{
    $platform = q_platform();
    $channel = q_channel();
    $level = nl_viewer_level();
    $projects = nl_load($level);

    $list = [];
    foreach ($projects as $p) {
        $f = nl_fmt_project($p, $channel, $platform);
        // Avec un filtre de plateforme : on garde ce qui est utilisable dessus (ou un site web)
        if ($platform !== null && !in_array($platform, $f['platforms'], true) && !in_array('web', $f['platforms'], true) && !in_array('any', $f['platforms'], true)) {
            continue;
        }
        $list[] = $f;
    }

    $payload = ['api_version' => NL_API_VERSION, 'channel' => $channel, 'platform' => $platform, 'projects' => $list];
    $etag = '"' . md5(json_encode($payload) . $level) . '"';
    // Pas de « 304 Not Modified » : Apache (OVH) y retire les en-têtes CORS, et le launcher
    // croyait alors le serveur hors ligne. Réponse 200 minuscule à la place.
    // (les launchers ≤ 0.2 envoient If-None-Match : ils reçoivent simplement le catalogue complet)
    $sent = trim((string)($_GET['etag'] ?? ''));
    if ($sent !== '' && $sent === $etag) {
        nl_json(['not_modified' => true, 'etag' => $etag], 200, ['ETag' => $etag, 'Cache-Control' => 'no-cache']);
    }
    $payload['etag'] = $etag;
    $payload['generated_at'] = gmdate('c');
    nl_json($payload, 200, ['ETag' => $etag, 'Cache-Control' => 'no-cache']);
}

function route_project(): never
{
    $slug = (string)($_GET['slug'] ?? '');
    if ($slug === '') { nl_fail('Paramètre slug requis'); }
    $projects = nl_load(nl_viewer_level(), $slug);
    if (!$projects) { nl_fail('Projet introuvable', 404); }
    nl_json(['project' => nl_fmt_project(reset($projects), q_channel(), q_platform(), true)]);
}

function route_update_check(): never
{
    $slug = (string)($_GET['slug'] ?? '');
    $platform = q_platform(true);
    $current = (string)($_GET['current'] ?? '0.0.0');
    $kind = isset($_GET['kind']) && in_array($_GET['kind'], NL_KINDS, true) ? $_GET['kind'] : null;
    if ($slug === '') { nl_fail('Paramètre slug requis'); }
    if (!nl_valid_version($current)) { $current = '0.0.0'; }

    $projects = nl_load(nl_viewer_level(), $slug);
    if (!$projects) { nl_fail('Projet introuvable', 404); }
    $l = nl_latest_for(reset($projects), $platform, q_channel(), $kind);

    if (!$l || nl_vcmp($l['release']['version'], $current) <= 0) {
        nl_json(['update' => false, 'current' => $current, 'latest' => $l['release']['version'] ?? null]);
    }
    $project = reset($projects);
    $rel = nl_fmt_release($l['release'], $platform, $project);
    nl_json([
        'update'    => true,
        'current'   => $current,
        'version'   => $rel['version'],
        'channel'   => $rel['channel'],
        'pub_date'  => $rel['pub_date'],
        'notes'     => $rel['notes'],
        'mandatory' => $rel['mandatory'],
        'asset'     => nl_fmt_asset($l['asset'], $l['release'], $project),
    ]);
}

/**
 * Endpoint dynamique du plugin updater de Tauri v2.
 * tauri.conf.json → plugins.updater.endpoints :
 *   "https://.../api.php?r=launcher/update&target={{target}}&arch={{arch}}&current={{current_version}}"
 * Réponse : 204 si pas de mise à jour, sinon {version, notes, pub_date, url, signature}.
 */
function route_launcher_update(): never
{
    $target = strtolower((string)($_GET['target'] ?? ''));
    $arch = strtolower((string)($_GET['arch'] ?? 'x86_64'));
    $current = (string)($_GET['current'] ?? '0.0.0');
    $map = ['windows' => 'windows', 'darwin' => 'darwin', 'macos' => 'darwin', 'linux' => 'linux'];
    if (!isset($map[$target])) { nl_fail('target invalide'); }
    $platform = $map[$target] . '-' . ($arch === 'aarch64' || $arch === 'arm64' ? 'aarch64' : 'x86_64');
    if (!in_array($platform, NL_PLATFORMS, true)) { nl_fail('Plateforme non supportée'); }

    $projects = nl_load(0, (string)nl_config()['launcher_slug']);
    if (!$projects) { nl_no_content(); }
    $l = nl_latest_for(reset($projects), $platform, q_channel(), 'updater');
    if (!$l || empty($l['asset']['signature']) || nl_vcmp($l['release']['version'], $current) <= 0) {
        nl_no_content();
    }
    nl_json([
        'version'   => nl_norm_version($l['release']['version']),
        'notes'     => $l['release']['notes'] ?? '',
        'pub_date'  => gmdate('Y-m-d\TH:i:s\Z', strtotime($l['release']['pub_date'] . ' UTC')),
        'url'       => nl_public_asset_url($l['asset']),
        'signature' => $l['asset']['signature'],
    ]);
}

/**
 * Lien de téléchargement stable, à mettre sur les sites et dans le README :
 *   https://nounourscorp.fr/launcher/api.php?r=download  → le launcher pour le système du visiteur
 * Sans fichier pour ce système : renvoie vers la page de téléchargement (ou la release GitHub).
 */
function route_download(): never
{
    $cfg = nl_config();
    $slug = (string)($_GET['slug'] ?? $cfg['launcher_slug']);
    $platform = (string)($_GET['platform'] ?? 'auto');
    if ($platform === 'auto' || $platform === '') {
        $platform = nl_platform_from_ua((string)($_SERVER['HTTP_USER_AGENT'] ?? '')) ?? '';
    }
    $kind = isset($_GET['kind']) && in_array($_GET['kind'], NL_KINDS, true) ? $_GET['kind'] : null;

    $projects = nl_load(nl_viewer_level(), $slug);
    $best = ($projects && in_array($platform, NL_PLATFORMS, true))
        ? nl_best_download(reset($projects), $platform, q_channel(), $kind)
        : null;

    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Cache-Control: no-store');
    if (!$best) {
        $fallback = $cfg['download_fallback'] ?? (rtrim((string)$cfg['base_url'], '/') . '/');
        header('Location: ' . $fallback . (str_contains($fallback, '?') ? '&' : '?') . 'indisponible=' . rawurlencode($platform ?: 'inconnu'), true, 302);
        exit;
    }
    try {
        nl_db()->prepare("INSERT INTO nl_settings (k, v) VALUES (?, '1') ON DUPLICATE KEY UPDATE v = CAST(v AS UNSIGNED) + 1")
               ->execute(['dl:' . $slug]);
    } catch (Throwable) { /* le compteur ne doit jamais bloquer un téléchargement */ }
    $url = nl_asset_url_for($best['asset'], $best['release'], reset($projects));
    if ($url === null) { // version verrouillée : on renvoie vers la page pour se connecter / débloquer
        header('Location: ' . (rtrim((string)$cfg['base_url'], '/') . '/'), true, 302);
        exit;
    }
    header('Location: ' . $url, true, 302);
    exit;
}

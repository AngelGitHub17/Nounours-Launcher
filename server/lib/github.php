<?php
/**
 * NounoursLauncher — dépôts GitHub privés
 *
 * Le serveur garde un jeton GitHub (config.php → github_token, en lecture seule).
 * Pour un fichier publié dans une release d'un dépôt PRIVÉ, le catalogue ne donne jamais
 * l'adresse GitHub : il donne un lien relais (api.php?r=asset&id=…). Ce lien vérifie les
 * droits du visiteur, demande à GitHub une adresse de téléchargement temporaire (quelques
 * minutes) et y redirige. Le visiteur récupère l'installeur, sans jamais accéder au dépôt,
 * à son code ni au jeton.
 */
declare(strict_types=1);

function nl_gh_token(): string
{
    return trim((string)(nl_config()['github_token'] ?? ''));
}

/**
 * Appel à l'API GitHub. Renvoie [code HTTP, corps décodé ou null, en-têtes].
 * $follow = false : ne suit pas les redirections (pour récupérer l'adresse temporaire d'un fichier).
 */
function nl_gh_request(string $path, string $accept = 'application/vnd.github+json', bool $follow = true): array
{
    $headers = [];
    $ch = curl_init(rtrim((string)(nl_config()['github_api'] ?? 'https://api.github.com'), '/') . $path);
    $h = [
        'Accept: ' . $accept,
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: NounoursLauncher-Catalogue',
    ];
    if (nl_gh_token() !== '') { $h[] = 'Authorization: Bearer ' . nl_gh_token(); }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$headers) {
            $p = strpos($line, ':');
            if ($p !== false) { $headers[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1)); }
            return strlen($line);
        },
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = is_string($body) && $body !== '' ? json_decode($body, true) : null;
    return [$code, $json, $headers];
}

/** « owner/repo » depuis une adresse GitHub (dépôt, release, fichier) — ou null */
function nl_gh_repo_from_url(?string $url): ?string
{
    if (!$url || !preg_match('#^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?(?:/|$)#', $url, $m)) { return null; }
    return $m[1] . '/' . $m[2];
}

/** Décompose l'adresse d'un fichier de release : [repo, tag, nom de fichier] — ou null */
function nl_gh_parse_asset_url(string $url): ?array
{
    if (!preg_match('#^https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)/releases/download/([^/]+)/([^/?]+)$#', $url, $m)) { return null; }
    return [$m[1], rawurldecode($m[2]), rawurldecode($m[3])];
}

/**
 * Le dépôt est-il privé ? Réponse mise en cache 6 h dans nl_settings.
 * Sans jeton configuré, on ne peut rien relayer : on répond false (lien GitHub direct).
 */
function nl_gh_is_private(string $repo): bool
{
    static $mem = [];
    if (nl_gh_token() === '') { return false; }
    if (isset($mem[$repo])) { return $mem[$repo]; }
    $key = 'gh:private:' . strtolower($repo);
    $cached = nl_setting($key);
    if ($cached !== null) {
        [$val, $ts] = array_pad(explode('|', $cached, 2), 2, '0');
        if (time() - (int)$ts < 6 * 3600) { return $mem[$repo] = $val === '1'; }
    }
    [$code, $json] = nl_gh_request('/repos/' . $repo);
    // 404 avec jeton = dépôt privé auquel le jeton n'a pas accès : on le traite comme privé
    $private = $code === 200 ? (bool)($json['private'] ?? false) : ($code === 404);
    if ($code === 200 || $code === 404) { nl_setting_set($key, ($private ? '1' : '0') . '|' . time()); }
    return $mem[$repo] = $private;
}

/** Adresse à donner au public pour un fichier : relais si le fichier vient d'un dépôt privé */
function nl_public_asset_url(array $a): string
{
    $parsed = nl_gh_parse_asset_url((string)$a['url']);
    if ($parsed && isset($a['id']) && nl_gh_is_private($parsed[0])) {
        return rtrim((string)nl_config()['base_url'], '/') . '/api.php?r=asset&id=' . (int)$a['id'];
    }
    return (string)$a['url'];
}

/** Lien « code source » : masqué si le dépôt est privé (on ne partage que les installeurs) */
function nl_public_repo_url(?string $url): ?string
{
    $repo = nl_gh_repo_from_url($url);
    return ($repo && nl_gh_is_private($repo)) ? null : $url;
}

/**
 * ?r=asset&id=N — téléchargement relais d'un fichier du catalogue.
 * Vérifie que la version est publiée et que le visiteur peut voir le projet.
 */
function route_asset(): never
{
    $id = (int)($_GET['id'] ?? 0);
    $st = nl_db()->prepare('SELECT a.*, r.published, r.version, p.visibility, p.slug
                            FROM nl_assets a JOIN nl_releases r ON r.id = a.release_id JOIN nl_projects p ON p.id = r.project_id
                            WHERE a.id = ?');
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a || !(int)$a['published'] || !in_array($a['visibility'], nl_visibilities_for(nl_viewer_level()), true)) {
        nl_fail('Fichier introuvable', 404);
    }
    $parsed = nl_gh_parse_asset_url((string)$a['url']);
    if (!$parsed || nl_gh_token() === '') {
        nl_redirect((string)$a['url']);
    }
    [$repo, $tag, $name] = $parsed;

    // Identifiant GitHub du fichier (mis en cache : il ne change pas pour une release donnée)
    $key = 'gh:asset:' . $id;
    $ghId = (int)(nl_setting($key) ?? 0);
    if ($ghId === 0) {
        [$code, $rel] = nl_gh_request('/repos/' . $repo . '/releases/tags/' . rawurlencode($tag));
        if ($code !== 200) { nl_fail("Release $tag introuvable sur GitHub (HTTP $code) : vérifie le jeton GitHub du serveur", 502); }
        foreach ($rel['assets'] ?? [] as $ga) {
            if (($ga['name'] ?? '') === $name) { $ghId = (int)$ga['id']; break; }
        }
        if ($ghId === 0) { nl_fail("Fichier $name absent de la release $tag", 404); }
        nl_setting_set($key, (string)$ghId);
    }

    // GitHub répond 302 vers une adresse signée valable quelques minutes : on y renvoie le visiteur
    [$code, , $headers] = nl_gh_request('/repos/' . $repo . '/releases/assets/' . $ghId, 'application/octet-stream', false);
    if (in_array($code, [301, 302, 307], true) && !empty($headers['location'])) {
        nl_redirect($headers['location']);
    }
    if ($code === 404) { nl_setting_set($key, null); } // release refaite : on rechargera l'identifiant
    nl_fail("GitHub n'a pas fourni le fichier (HTTP $code)", 502);
}

function nl_redirect(string $url): never
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Cache-Control: no-store');
    header('Location: ' . $url, true, 302);
    exit;
}

// ---------- Import des releases d'un dépôt (panel) ----------

/** Plateforme et type d'un fichier d'après son nom ; null = pas un installeur (ignoré) */
function nl_gh_classify(string $name): ?array
{
    $n = strtolower($name);
    if (preg_match('/\.(sig|sha256|txt|md|json|yml|yaml|blockmap|pdb|symbols)$/', $n)) { return null; }
    $arm = (bool)preg_match('/(arm64|aarch64)/', $n);
    $mac = (bool)preg_match('/(mac|darwin|osx|apple)/', $n);
    $lin = (bool)preg_match('/(linux|ubuntu|debian)/', $n);
    return match (true) {
        str_ends_with($n, '.app.tar.gz')                     => null, // artefact de mise à jour Tauri
        str_ends_with($n, '.exe')                            => [$arm ? 'windows-aarch64' : 'windows-x86_64', preg_match('/(setup|install)/', $n) ? 'installer' : 'portable'],
        str_ends_with($n, '.msi')                            => [$arm ? 'windows-aarch64' : 'windows-x86_64', 'msi'],
        str_ends_with($n, '.dmg')                            => [preg_match('/(x64|x86_64|intel)/', $n) ? 'darwin-x86_64' : 'darwin-aarch64', 'dmg'],
        str_ends_with($n, '.appimage')                       => [$arm ? 'linux-aarch64' : 'linux-x86_64', 'appimage'],
        str_ends_with($n, '.deb')                            => [$arm ? 'linux-aarch64' : 'linux-x86_64', 'deb'],
        str_ends_with($n, '.rpm')                            => [$arm ? 'linux-aarch64' : 'linux-x86_64', 'rpm'],
        str_ends_with($n, '.apk')                            => ['android', 'apk'],
        str_ends_with($n, '.jar')                            => ['server', 'jar'],
        str_ends_with($n, '.zip')                            => [$mac ? ($arm || !preg_match('/(x64|intel)/', $n) ? 'darwin-aarch64' : 'darwin-x86_64') : ($lin ? 'linux-x86_64' : ($arm ? 'windows-aarch64' : 'windows-x86_64')), 'zip'],
        default                                              => null,
    };
}

/**
 * Importe (ou complète) les releases GitHub d'un projet dans le catalogue.
 * Seuls les installeurs sont repris : jamais le code source ni les fichiers techniques.
 */
function nl_gh_import(int $projectId, int $limit = 15): array
{
    $db = nl_db();
    $st = $db->prepare('SELECT id, slug, github_repo, repo_url FROM nl_projects WHERE id = ?');
    $st->execute([$projectId]);
    $p = $st->fetch();
    if (!$p) { nl_fail('Projet introuvable', 404); }
    $repo = trim((string)($p['github_repo'] ?? '')) ?: nl_gh_repo_from_url($p['repo_url'] ?? null);
    if (!$repo) { nl_fail('Renseigne le dépôt GitHub du projet (ex. AngelGitHub17/MonAppli)'); }

    [$code, $releases] = nl_gh_request('/repos/' . $repo . '/releases?per_page=' . max(1, min(50, $limit)));
    if ($code === 404) { nl_fail("Dépôt $repo introuvable : s'il est privé, ajoute-le au jeton GitHub du serveur (config.php → github_token)", 404); }
    if ($code === 401) { nl_fail('Jeton GitHub refusé (expiré ?) : remplace github_token dans config.php', 502); }
    if ($code !== 200 || !is_array($releases)) { nl_fail("GitHub a répondu HTTP $code", 502); }

    $report = ['repo' => $repo, 'releases' => 0, 'assets' => 0, 'skipped' => []];
    foreach ($releases as $r) {
        if (!empty($r['draft'])) { continue; }
        $version = nl_norm_version((string)($r['tag_name'] ?? ''));
        if (!nl_valid_version($version)) { $report['skipped'][] = (string)($r['tag_name'] ?? '?'); continue; }
        $assets = [];
        foreach ($r['assets'] ?? [] as $ga) {
            $cls = nl_gh_classify((string)$ga['name']);
            if (!$cls) { continue; }
            $sha = (isset($ga['digest']) && str_starts_with((string)$ga['digest'], 'sha256:')) ? substr((string)$ga['digest'], 7) : null;
            $assets[] = ['platform' => $cls[0], 'kind' => $cls[1], 'url' => (string)$ga['browser_download_url'],
                         'size' => (int)($ga['size'] ?? 0) ?: null, 'sha256' => $sha, 'signature' => null,
                         'install_args' => null, 'launch_path' => null];
        }
        if (!$assets) { continue; }

        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO nl_releases (project_id, version, channel, notes, published, mandatory, pub_date)
                          VALUES (:p, :v, :c, :n, 1, 0, :d)
                          ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)')
               ->execute([
                   'p' => $projectId, 'v' => $version,
                   'c' => (!empty($r['prerelease']) || str_contains($version, '-')) ? 'beta' : 'stable',
                   'n' => mb_substr((string)($r['body'] ?? ''), 0, 50000) ?: null,
                   'd' => gmdate('Y-m-d H:i:s', strtotime((string)($r['published_at'] ?? 'now')) ?: time()),
               ]);
            $rid = (int)$db->lastInsertId();
            foreach ($assets as $a) {
                // Les réglages déjà saisis à la main (arguments, chemin à lancer) ne sont pas écrasés
                $db->prepare('INSERT INTO nl_assets (release_id, platform, kind, url, size, sha256)
                              VALUES (?, ?, ?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE url = VALUES(url), size = VALUES(size), sha256 = COALESCE(VALUES(sha256), sha256)')
                   ->execute([$rid, $a['platform'], $a['kind'], $a['url'], $a['size'], $a['sha256']]);
                $report['assets']++;
            }
            $db->commit();
            $report['releases']++;
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
    if (!$p['github_repo']) {
        $db->prepare('UPDATE nl_projects SET github_repo = ? WHERE id = ?')->execute([$repo, $projectId]);
    }
    return $report;
}

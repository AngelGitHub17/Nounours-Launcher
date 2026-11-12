<?php
/**
 * NounoursLauncher — routes d'administration et de CI (toutes en POST)
 */
declare(strict_types=1);

function nl_admin_dispatch(string $route): never
{
    nl_require_post();

    if ($route === 'ci/release') {
        nl_require_ci();
        admin_ci_release();
    }

    nl_require_admin();
    match ($route) {
        'admin/whoami'         => nl_json(['ok' => true]),
        'admin/settings'       => admin_settings(),
        'admin/ci-token/new'   => admin_ci_token(true),
        'admin/ci-token/revoke'=> admin_ci_token(false),
        'admin/projects'       => admin_projects(),
        'admin/project/save'   => admin_project_save(),
        'admin/project/delete' => admin_delete('nl_projects'),
        'admin/release/save'   => admin_release_save(),
        'admin/release/delete' => admin_delete('nl_releases'),
        'admin/asset/save'     => admin_asset_save(),
        'admin/asset/delete'   => admin_delete('nl_assets'),
        'admin/upload'         => admin_upload(),
        'admin/github/import'  => nl_json(['ok' => true] + nl_gh_import((int)(nl_input()['project_id'] ?? 0))),
        'admin/github/check'   => admin_github_check(),
        'admin/folder/import'  => admin_folder_import(),
        'admin/watch/run'      => nl_json(['ok' => true, 'new' => nl_watch_tick(true, (int)(nl_input()['project_id'] ?? 0) ?: null)]),
        'admin/release/schedule' => admin_release_schedule(),
        default                => nl_fail('Route admin inconnue', 404),
    };
}

function nl_github_repo_input(array $in): ?string
{
    $v = nl_str($in, 'github_repo', 140);
    if ($v === null) { return null; }
    $v = nl_gh_repo_from_url($v) ?? $v; // accepte aussi une adresse github.com/…
    if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $v)) { nl_fail('Dépôt GitHub invalide : attendu « propriétaire/nom »'); }
    return $v;
}

/** Vérifie le jeton GitHub du serveur et l'accès à un dépôt */
function admin_github_check(): never
{
    if (nl_gh_token() === '') { nl_json(['token' => false]); }
    [$code, $rate] = nl_gh_request('/rate_limit');
    $out = ['token' => true, 'valid' => $code === 200, 'remaining' => $rate['resources']['core']['remaining'] ?? null];
    $repo = nl_str(nl_input(), 'repo', 140);
    if ($repo) {
        [$c2, $r] = nl_gh_request('/repos/' . $repo);
        $out['repo'] = ['name' => $repo, 'access' => $c2 === 200, 'private' => (bool)($r['private'] ?? false)];
        if ($c2 === 200) { nl_setting_set('gh:private:' . strtolower($repo), (($r['private'] ?? false) ? '1' : '0') . '|' . time()); }
    }
    nl_json($out);
}

function admin_settings(): never
{
    nl_json([
        'ci_token_set' => nl_setting('ci_token_hash') !== null,
        'ci_token_at'  => nl_setting('ci_token_at'),
        'api_url'      => rtrim((string)nl_config()['base_url'], '/') . '/api.php',
        'page_url'     => rtrim((string)nl_config()['base_url'], '/') . '/',
        'downloads'    => (int)(nl_setting('dl:' . nl_config()['launcher_slug']) ?? 0),
        'github_token' => nl_gh_token() !== '',
        'server_version' => NL_SERVER_VERSION,
    ]);
}

/** Génère (affiché une seule fois) ou révoque le jeton utilisé par GitHub Actions */
function admin_ci_token(bool $create): never
{
    if (!$create) {
        nl_setting_set('ci_token_hash', null);
        nl_setting_set('ci_token_at', null);
        nl_json(['ok' => true]);
    }
    $token = 'nlci_' . bin2hex(random_bytes(24));
    nl_setting_set('ci_token_hash', hash('sha256', $token));
    nl_setting_set('ci_token_at', gmdate('c'));
    nl_json(['ok' => true, 'token' => $token]);
}

/** Liste complète (privés et versions non publiées compris) pour le panel */
function admin_projects(): never
{
    $projects = nl_load(2, null, true);
    $out = [];
    foreach ($projects as $p) {
        foreach ($p['releases'] as &$r) {
            $r['id'] = (int)$r['id'];
            $r['published'] = (bool)$r['published'];
            $r['mandatory'] = (bool)$r['mandatory'];
            $r['lock_login'] = (bool)($r['lock_login'] ?? 0);
            $r['lock_coins'] = (int)($r['lock_coins'] ?? 0);
            $r['available_local'] = nl_utc_to_paris_input($r['available_at'] ?? null);
            $r['state'] = !$r['published'] ? 'pending' : (nl_release_available($r) ? 'live' : 'scheduled');
            $st = nl_db()->prepare('SELECT COUNT(*) FROM nl_purchases WHERE release_id = ?');
            $st->execute([$r['id']]);
            $r['purchases'] = (int)$st->fetchColumn();
            foreach ($r['assets'] as &$a) { $a['id'] = (int)$a['id']; $a['size'] = $a['size'] !== null ? (int)$a['size'] : null; }
            unset($a);
        }
        unset($r);
        $p['id'] = (int)$p['id'];
        $p['sort_order'] = (int)$p['sort_order'];
        $p['default_lock_login'] = (bool)($p['default_lock_login'] ?? 0);
        $p['default_lock_coins'] = (int)($p['default_lock_coins'] ?? 0);
        $p['pending'] = count(array_filter($p['releases'], fn($r) => $r['state'] === 'pending'));
        $p['icon_abs'] = nl_abs_url($p['icon_url']);
        $out[] = $p;
    }
    nl_json(['projects' => $out]);
}

function admin_project_save(): never
{
    $in = nl_input();
    $id = isset($in['id']) ? (int)$in['id'] : 0;
    $name = nl_str($in, 'name', 120, true);
    $slug = nl_slugify(nl_str($in, 'slug', 64) ?? $name);
    if ($slug === '') { nl_fail('Slug invalide'); }

    $accent = nl_str($in, 'accent_color', 9);
    if ($accent !== null && !preg_match('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $accent)) { nl_fail('Couleur invalide (#rrggbb)'); }

    $status = nl_str($in, 'status_check_url', 500);
    if ($status !== null) {
        $sch = strtolower((string)parse_url($status, PHP_URL_SCHEME));
        if (!in_array($sch, ['http','https','tcp','mc'], true) || !parse_url($status, PHP_URL_HOST)) {
            nl_fail('URL de statut invalide (https://, tcp://hote:port ou mc://hote:port)');
        }
    }

    $data = [
        'slug'             => $slug,
        'name'             => $name,
        'type'             => nl_enum($in, 'type', NL_TYPES, 'app'),
        'short_desc'       => nl_str($in, 'short_desc', 255) ?? '',
        'description'      => nl_str($in, 'description', 20000),
        'icon_url'         => nl_str($in, 'icon_url', 500),
        'banner_url'       => nl_str($in, 'banner_url', 500),
        'accent_color'     => $accent,
        'website_url'      => nl_url($in, 'website_url'),
        'repo_url'         => nl_url($in, 'repo_url'),
        'github_repo'      => nl_github_repo_input($in),
        'status'           => nl_enum($in, 'status', NL_STATUSES, 'dev'),
        'visibility'       => nl_enum($in, 'visibility', NL_VISIBILITIES, 'public'),
        'status_check_url' => $status,
        'tags'             => implode(',', array_filter(array_map('trim', explode(',', nl_str($in, 'tags', 255) ?? '')))),
        'sort_order'       => (int)($in['sort_order'] ?? 100),
        'watch_mode'       => nl_enum($in, 'watch_mode', ['off','ask','publish'], 'off'),
        'files_dir'        => nl_files_dir_input($in),
        'files_url'        => nl_url($in, 'files_url'),
        'default_lock_login' => nl_bool($in, 'default_lock_login'),
        'default_lock_coins' => max(0, min(1_000_000, (int)($in['default_lock_coins'] ?? 0))),
    ];
    if ($data['files_dir'] !== null && $data['files_url'] === null) { nl_fail("Indique aussi l'adresse publique du dossier (files_url)"); }

    $db = nl_db();
    $cols = array_keys($data);
    try {
        if ($id > 0) {
            $set = implode(', ', array_map(fn($c) => "$c = :$c", $cols));
            $st = $db->prepare("UPDATE nl_projects SET $set WHERE id = :id");
            $st->execute($data + ['id' => $id]);
        } else {
            $st = $db->prepare('INSERT INTO nl_projects (' . implode(',', $cols) . ') VALUES (:' . implode(', :', $cols) . ')');
            $st->execute($data);
            $id = (int)$db->lastInsertId();
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') { nl_fail("Le slug « $slug » existe déjà", 409); }
        throw $e;
    }
    nl_json(['ok' => true, 'id' => $id, 'slug' => $slug]);
}

function admin_release_save(): never
{
    $in = nl_input();
    $id = isset($in['id']) ? (int)$in['id'] : 0;
    $projectId = (int)($in['project_id'] ?? 0);
    $version = nl_str($in, 'version', 40, true);
    if (!nl_valid_version($version)) { nl_fail('Version invalide (attendu : 1.2.3 ou 1.2.3-beta.1)'); }
    $version = nl_norm_version($version);

    $pubDate = nl_str($in, 'pub_date', 40);
    $pubTs = $pubDate !== null ? strtotime($pubDate) : time();
    if ($pubTs === false) { nl_fail('Date invalide'); }

    $data = [
        'version'   => $version,
        'channel'   => nl_enum($in, 'channel', ['stable','beta'], 'stable'),
        'notes'     => nl_str($in, 'notes', 50000),
        'published' => nl_bool($in, 'published'),
        'mandatory' => nl_bool($in, 'mandatory'),
        'pub_date'  => gmdate('Y-m-d H:i:s', $pubTs),
        'label'     => nl_str($in, 'label', 40),
        'lock_login' => nl_bool($in, 'lock_login'),
        'lock_coins' => max(0, min(1_000_000, (int)($in['lock_coins'] ?? 0))),
        // Saisie à l'heure de Paris ; vide ou passée = disponible tout de suite
        'available_at' => nl_paris_to_utc(nl_str($in, 'available_local', 25)),
    ];
    if ($data['available_at'] !== null) { $data['pub_date'] = $data['available_at']; }
    $db = nl_db();
    try {
        if ($id > 0) {
            $set = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($data)));
            $db->prepare("UPDATE nl_releases SET $set WHERE id = :id")->execute($data + ['id' => $id]);
        } else {
            if ($projectId <= 0) { nl_fail('project_id requis'); }
            $data['project_id'] = $projectId;
            $cols = array_keys($data);
            $db->prepare('INSERT INTO nl_releases (' . implode(',', $cols) . ') VALUES (:' . implode(', :', $cols) . ')')->execute($data);
            $id = (int)$db->lastInsertId();
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') { nl_fail("La version $version existe déjà pour ce projet (ou projet introuvable)", 409); }
        throw $e;
    }
    nl_json(['ok' => true, 'id' => $id]);
}

function nl_asset_data(array $in): array
{
    $sha = nl_str($in, 'sha256', 64);
    if ($sha !== null && !preg_match('/^[0-9a-fA-F]{64}$/', $sha)) { nl_fail('sha256 invalide'); }
    $size = $in['size'] ?? null;
    return [
        'platform'     => nl_enum($in, 'platform', NL_PLATFORMS),
        'kind'         => nl_enum($in, 'kind', NL_KINDS, 'installer'),
        'url'          => nl_url($in, 'url', true),
        'size'         => ($size === null || $size === '') ? null : max(0, (int)$size),
        'sha256'       => $sha !== null ? strtolower($sha) : null,
        'signature'    => nl_str($in, 'signature', 4000),
        'install_args' => nl_str($in, 'install_args', 255),
        'launch_path'  => nl_str($in, 'launch_path', 500),
    ];
}

function nl_asset_upsert(int $releaseId, array $data): int
{
    $db = nl_db();
    $data['release_id'] = $releaseId;
    $cols = array_keys($data);
    $upd = implode(', ', array_map(fn($c) => "$c = VALUES($c)", array_diff($cols, ['release_id','platform','kind'])));
    $db->prepare('INSERT INTO nl_assets (' . implode(',', $cols) . ') VALUES (:' . implode(', :', $cols) . ")
                  ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), $upd")->execute($data);
    return (int)$db->lastInsertId();
}

function admin_asset_save(): never
{
    $in = nl_input();
    $id = isset($in['id']) ? (int)$in['id'] : 0;
    $data = nl_asset_data($in);
    $db = nl_db();
    if ($id > 0) {
        $set = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($data)));
        try {
            $db->prepare("UPDATE nl_assets SET $set WHERE id = :id")->execute($data + ['id' => $id]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') { nl_fail('Un fichier existe déjà pour cette plateforme et ce type', 409); }
            throw $e;
        }
    } else {
        $rid = (int)($in['release_id'] ?? 0);
        if ($rid <= 0) { nl_fail('release_id requis'); }
        try {
            $id = nl_asset_upsert($rid, $data);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') { nl_fail('Version introuvable', 404); }
            throw $e;
        }
    }
    nl_json(['ok' => true, 'id' => $id]);
}

function admin_delete(string $table): never
{
    $id = (int)(nl_input()['id'] ?? 0);
    if ($id <= 0) { nl_fail('id requis'); }
    // $table vient uniquement de la liste fixe du dispatch
    $st = nl_db()->prepare("DELETE FROM $table WHERE id = ?");
    $st->execute([$id]);
    nl_json(['ok' => true, 'deleted' => $st->rowCount()]);
}

/** Upload d'icône / bannière (PNG, JPEG, WebP, GIF — pas de SVG pour éviter le XSS) */
function admin_upload(): never
{
    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { nl_fail('Aucun fichier reçu'); }
    if ($f['size'] > 3 * 1024 * 1024) { nl_fail('Fichier trop lourd (3 Mo max)'); }
    $info = @getimagesize($f['tmp_name']);
    $types = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($types[$info[2]])) { nl_fail('Image PNG, JPEG, WebP ou GIF attendue'); }

    $sub = 'uploads/' . gmdate('Y');
    $dir = dirname(__DIR__) . '/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { nl_fail('Impossible de créer le dossier uploads', 500); }
    $name = bin2hex(random_bytes(8)) . '.' . $types[$info[2]];
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) { nl_fail('Échec de l\'enregistrement', 500); }
    nl_json(['ok' => true, 'path' => "$sub/$name", 'url' => nl_abs_url("$sub/$name"), 'width' => $info[0], 'height' => $info[1]]);
}

/**
 * Publication automatique depuis une CI (GitHub Actions).
 * Corps JSON : { slug, version, channel?, notes?, published?, mandatory?, assets: [{platform, kind, url, size?, sha256?, signature?, install_args?}] }
 * Crée ou met à jour la version, et remplace/ajoute ses fichiers.
 */
function admin_ci_release(): never
{
    $in = nl_input();
    $slug = nl_str($in, 'slug', 64, true);
    $version = nl_str($in, 'version', 40, true);
    if (!nl_valid_version($version)) { nl_fail('Version invalide'); }
    $version = nl_norm_version($version);
    $assets = $in['assets'] ?? [];
    if (!is_array($assets) || count($assets) > 50) { nl_fail('assets invalide'); }
    $assetData = array_map(fn($a) => nl_asset_data(is_array($a) ? $a : []), $assets);

    $db = nl_db();
    $st = $db->prepare('SELECT id FROM nl_projects WHERE slug = ?');
    $st->execute([$slug]);
    $pid = (int)($st->fetchColumn() ?: 0);
    if ($pid === 0) { nl_fail("Projet « $slug » introuvable — crée-le d'abord dans le panel", 404); }

    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO nl_releases (project_id, version, channel, notes, published, mandatory, pub_date)
                      VALUES (:p, :v, :c, :n, :pub, :m, UTC_TIMESTAMP())
                      ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), channel = VALUES(channel),
                        notes = COALESCE(VALUES(notes), notes), published = VALUES(published), mandatory = VALUES(mandatory)')
           ->execute([
               'p' => $pid, 'v' => $version,
               'c' => nl_enum($in, 'channel', ['stable','beta'], str_contains($version, '-') ? 'beta' : 'stable'),
               'n' => nl_str($in, 'notes', 50000),
               'pub' => nl_bool($in, 'published', true),
               'm' => nl_bool($in, 'mandatory'),
           ]);
        $rid = (int)$db->lastInsertId();
        foreach ($assetData as $a) { nl_asset_upsert($rid, $a); }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    nl_json(['ok' => true, 'release_id' => $rid, 'slug' => $slug, 'version' => $version, 'assets' => count($assetData)]);
}

/** Dossier du serveur contenant les fichiers d'un projet (ex. /home/didikaz/www2/PandorPacks) */
function nl_files_dir_input(array $in): ?string
{
    $v = nl_str($in, 'files_dir', 255);
    if ($v === null) { return null; }
    $real = realpath($v);
    if (!$real || !is_dir($real)) { nl_fail("Dossier introuvable sur le serveur : $v"); }
    $roots = (array)(nl_config()['files_roots'] ?? [dirname(__DIR__, 3)]);
    foreach ($roots as $root) {
        $r = realpath((string)$root);
        if ($r && ($real === $r || str_starts_with($real, $r . '/'))) {
            if (str_starts_with($real . '/', dirname(__DIR__) . '/')) { nl_fail('Choisis un dossier hors du launcher'); }
            return $real;
        }
    }
    nl_fail('Dossier hors des emplacements autorisés (config.php → files_roots)');
}

/** Importe (ou met à jour) toutes les versions trouvées dans le dossier du projet */
function admin_folder_import(): never
{
    $id = (int)(nl_input()['project_id'] ?? 0);
    $st = nl_db()->prepare('SELECT * FROM nl_projects WHERE id = ?');
    $st->execute([$id]);
    $p = $st->fetch() ?: nl_fail('Projet introuvable', 404);
    if (empty($p['files_dir'])) { nl_fail("Renseigne d'abord le dossier du serveur du projet"); }
    $r = nl_folder_import($p, false);
    unset($r['new']);
    nl_json(['ok' => true] + $r);
}

/** Publier maintenant ou programmer une version (when = now | at, local = date à l'heure de Paris) */
function admin_release_schedule(): never
{
    $in = nl_input();
    $id = (int)($in['id'] ?? 0);
    if (($in['when'] ?? 'now') === 'at') {
        $at = nl_paris_to_utc(nl_str($in, 'local', 25));
        if ($at === null) { nl_fail('Choisis une date dans le futur'); }
    } else { $at = null; }
    nl_release_schedule($id, $at,
        array_key_exists('lock_login', $in) ? nl_bool($in, 'lock_login') : null,
        array_key_exists('lock_coins', $in) ? (int)$in['lock_coins'] : null);
    nl_json(['ok' => true]);
}

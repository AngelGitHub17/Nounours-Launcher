<?php
/**
 * NounoursLauncher — surveillance des nouvelles versions
 *
 * Pour chaque projet dont la surveillance est active (panel → « Nouvelles versions ») :
 *  - releases du dépôt GitHub (github_repo, privé possible avec le jeton du serveur) ;
 *  - fichiers d'un dossier du serveur (files_dir, ex. /home/didikaz/www2/PandorPacks).
 * Une version inconnue est ajoutée au catalogue :
 *  - mode « ask »     : non publiée, l'admin reçoit un mail « Publier maintenant / Programmer » ;
 *  - mode « publish » : publiée tout de suite, l'admin reçoit un mail d'information.
 * Lancement : au plus toutes les 15 min pendant les visites de l'API, ou par la tâche planifiée cron.php.
 */
declare(strict_types=1);

const NL_WATCH_EVERY = 900; // secondes

/** Petit journal (cache/watch.log, 100 Ko max) */
function nl_watch_log(string $msg): void
{
    $f = dirname(__DIR__) . '/cache/watch.log';
    if (is_file($f) && filesize($f) > 100_000) { @rename($f, $f . '.old'); }
    @file_put_contents($f, gmdate('c') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

/** Version « décimale » (2.91a, 2.8, 3.0) → semver triable (2.91.1, 2.80.0, 3.0.0) ; semver inchangée */
function nl_label_to_semver(string $v): ?string
{
    $v = ltrim(trim($v), 'vV');
    if (nl_valid_version($v)) { return nl_norm_version($v); }
    if (!preg_match('/^(\d+)(?:\.(\d+))?([a-z])?$/i', $v, $m)) { return null; }
    $minor = $m[2] ?? '0';
    if (strlen($minor) === 1) { $minor .= '0'; }           // 2.8 → 2.80 (vient après 2.71)
    $patch = isset($m[3]) && $m[3] !== '' ? ord(strtolower($m[3])) - 96 : 0; // a → 1, b → 2
    return (int)$m[1] . '.' . (int)$minor . '.' . $patch;
}

/** Un zip est-il un pack de ressources Minecraft (pack.mcmeta) ? */
function nl_is_resourcepack(string $file): bool
{
    if (!class_exists('ZipArchive')) { return (bool)preg_match('/pack/i', basename($file)); }
    $z = new ZipArchive();
    if ($z->open($file) !== true) { return false; }
    $ok = $z->locateName('pack.mcmeta', ZipArchive::FL_NOCASE | ZipArchive::FL_NODIR) !== false;
    $z->close();
    return $ok;
}

/**
 * Versions trouvées dans le dossier du projet (sous-dossiers compris, 2 niveaux).
 * @return array<string, array> semver => ['label','notes','pub_date','assets'=>[…]]
 */
function nl_folder_scan(array $p, ?array $only = null): array
{
    $dir = rtrim((string)$p['files_dir'], '/');
    $base = rtrim((string)$p['files_url'], '/');
    if ($base === '') { return []; }
    if ($dir === '' || !is_dir($dir)) { return nl_remote_scan($base, $only); } // dossier d'un autre serveur : liste web
    $exts = 'zip|jar|exe|msi|dmg|pkg|apk|appimage|deb|rpm|mcpack|mrpack';
    $found = [];
    $walk = [[$dir, '']];
    while ($walk) {
        [$d, $rel] = array_shift($walk);
        foreach (scandir($d) ?: [] as $f) {
            if ($f === '' || $f[0] === '.') { continue; }
            $path = "$d/$f";
            $relPath = $rel === '' ? $f : "$rel/$f";
            if (is_dir($path)) { if (substr_count($relPath, '/') < 1) { $walk[] = [$path, $relPath]; } continue; }
            if (!preg_match("/\\.($exts)$/i", $f)) { continue; }
            // Version : « V2.91a » dans le nom, sinon le premier numéro ; à défaut le nom du sous-dossier
            $ver = null;
            if (preg_match('/[Vv](\d+(?:\.\d+)*[a-z]?)(?=[^a-z0-9]|$)/', $f, $m)) { $ver = $m[1]; }
            elseif ($rel !== '' && preg_match('/(\d+(?:\.\d+)*[a-z]?)/', basename($rel), $m)) { $ver = $m[1]; }
            elseif (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $f, $m)) { $ver = $m[1]; }
            $semver = $ver !== null ? nl_label_to_semver($ver) : null;
            if ($semver === null) { continue; }
            if ($only !== null && !in_array($semver, $only, true)) { continue; }
            $mc = preg_match('/-(1\.\d+(?:\.\d+)?)\.[a-z]+$/i', $f, $m) ? $m[1] : null; // version de Minecraft
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if ($ext === 'zip' || $ext === 'mcpack') {
                $cls = nl_is_resourcepack($path) ? ['any', 'resourcepack'] : (nl_gh_classify($f) ?? ['any', 'file']);
            } else {
                $cls = nl_gh_classify($f) ?? ['any', 'file'];
            }
            $url = $base . '/' . implode('/', array_map('rawurlencode', explode('/', $relPath)));
            $found[$semver] ??= [
                'label'    => 'V' . $ver,
                'notes'    => $mc ? "Pour **Minecraft $mc**." : null,
                'pub_date' => gmdate('Y-m-d H:i:s', (int)filemtime($path)),
                'assets'   => [],
                'path'     => [],
            ];
            $found[$semver]['assets'][] = ['platform' => $cls[0], 'kind' => $cls[1], 'url' => $url, 'size' => filesize($path), 'sha256' => null];
            $found[$semver]['path'][] = $path;
        }
    }
    return $found;
}

/** Lit une page « Index of » (Apache) : [noms de fichiers, noms de sous-dossiers] */
function nl_http_listing(string $url): array
{
    $ch = curl_init(rtrim($url, '/') . '/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'NounoursLauncher/' . NL_SERVER_VERSION]);
    $html = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) { return [[], []]; }
    preg_match_all('/href="([^"?#]+)"/i', $html, $m);
    $files = []; $dirs = [];
    foreach (array_unique($m[1]) as $h) {
        if (str_starts_with($h, '/') || str_contains($h, '://') || str_starts_with($h, '..')) { continue; }
        $name = rawurldecode($h);
        if (str_ends_with($name, '/')) { $dirs[] = rtrim($name, '/'); } else { $files[] = $name; }
    }
    return [$files, $dirs];
}

/** Même analyse que nl_folder_scan, mais sur un dossier publié en ligne (liste web du serveur) */
function nl_remote_scan(string $base, ?array $only = null): array
{
    [$files, $dirs] = nl_http_listing($base);
    $all = array_map(fn($f) => ['', $f], $files);
    foreach (array_slice($dirs, 0, 80) as $d) {
        [$sub] = nl_http_listing($base . '/' . rawurlencode($d));
        foreach ($sub as $f) { $all[] = [$d, $f]; }
    }
    $found = [];
    foreach ($all as [$rel, $f]) {
        if (!preg_match('/\.(zip|jar|exe|msi|dmg|pkg|apk|appimage|deb|rpm|mcpack|mrpack)$/i', $f)) { continue; }
        $ver = null;
        if (preg_match('/[Vv](\d+(?:\.\d+)*[a-z]?)(?=[^a-z0-9]|$)/', $f, $m)) { $ver = $m[1]; }
        elseif ($rel !== '' && preg_match('/(\d+(?:\.\d+)*[a-z]?)/', $rel, $m)) { $ver = $m[1]; }
        $semver = $ver !== null ? nl_label_to_semver($ver) : null;
        if ($semver === null || ($only !== null && !in_array($semver, $only, true))) { continue; }
        $mc = preg_match('/-(1\.\d+(?:\.\d+)?)\.[a-z]+$/i', $f, $m) ? $m[1] : null;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        $cls = in_array($ext, ['zip', 'mcpack'], true) && preg_match('/pack/i', $f) ? ['any', 'resourcepack'] : (nl_gh_classify($f) ?? ['any', 'file']);
        $url = $base . '/' . ($rel !== '' ? rawurlencode($rel) . '/' : '') . rawurlencode($f);
        $found[$semver] ??= ['label' => 'V' . $ver, 'notes' => $mc ? "Pour **Minecraft $mc**." : null,
                             'pub_date' => gmdate('Y-m-d H:i:s'), 'assets' => [], 'path' => []];
        $found[$semver]['assets'][] = ['platform' => $cls[0], 'kind' => $cls[1], 'url' => $url, 'size' => null, 'sha256' => null];
        $found[$semver]['path'][] = null;
    }
    return $found;
}

/** Versions déjà connues d'un projet */
function nl_known_versions(int $projectId): array
{
    $st = nl_db()->prepare('SELECT version FROM nl_releases WHERE project_id = ?');
    $st->execute([$projectId]);
    return array_column($st->fetchAll(), 'version');
}

/**
 * Ajoute une version détectée (ou importée) et ses fichiers.
 * $published : 1 = visible tout de suite ; 0 = en attente de décision.
 */
function nl_store_release(array $p, string $version, array $data, string $source, int $published, bool $detected): int
{
    $db = nl_db();
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO nl_releases (project_id, version, label, channel, notes, published, mandatory, pub_date, lock_login, lock_coins, source, detected_at)
                      VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ' . ($detected ? 'UTC_TIMESTAMP()' : 'NULL') . ')
                      ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)')
           ->execute([
               (int)$p['id'], $version, $data['label'] ?? null,
               str_contains($version, '-') ? 'beta' : 'stable',
               $data['notes'] ?? null, $published, $data['pub_date'] ?? gmdate('Y-m-d H:i:s'),
               $detected ? (int)($p['default_lock_login'] ?? 0) : 0, $detected ? (int)($p['default_lock_coins'] ?? 0) : 0, $source,
           ]);
        $rid = (int)$db->lastInsertId();
        foreach ($data['assets'] as $a) {
            $db->prepare('INSERT INTO nl_assets (release_id, platform, kind, url, size, sha256) VALUES (?, ?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE url = VALUES(url), size = VALUES(size), sha256 = COALESCE(VALUES(sha256), sha256)')
               ->execute([$rid, $a['platform'], $a['kind'], $a['url'], $a['size'] ?: null, $a['sha256'] ?? null]);
        }
        $db->commit();
        return $rid;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Importe le dossier d'un projet. $onlyNew = true (surveillance) : seulement les versions inconnues.
 * @return array{releases:int, assets:int, new:int[]}
 */
function nl_folder_import(array $p, bool $onlyNew, int $published = 1, bool $detected = false): array
{
    $known = nl_known_versions((int)$p['id']);
    $scan = nl_folder_scan($p);
    $report = ['releases' => 0, 'assets' => 0, 'new' => []];
    uksort($scan, 'nl_vcmp');
    foreach ($scan as $version => $data) {
        $isNew = !in_array($version, $known, true);
        if ($onlyNew && !$isNew) { continue; }
        // Empreinte SHA-256 des nouveaux fichiers (vérifiée par le launcher avant installation)
        foreach ($data['assets'] as $i => &$a) { $a['sha256'] = $data['path'][$i] ? (@hash_file('sha256', $data['path'][$i]) ?: null) : null; }
        unset($a);
        $rid = nl_store_release($p, $version, $data, 'folder', $isNew ? $published : 1, $detected && $isNew);
        $report['releases']++;
        $report['assets'] += count($data['assets']);
        if ($isNew) { $report['new'][] = $rid; }
    }
    return $report;
}

/** Une passe de surveillance (au plus toutes les 15 min, sauf $force) */
function nl_watch_tick(bool $force = false, ?int $onlyProject = null): array
{
    $flag = dirname(__DIR__) . '/cache/.watch_tick';
    if (!$force && is_file($flag) && time() - (int)filemtime($flag) < NL_WATCH_EVERY) { return []; }
    @touch($flag);
    $sql = "SELECT * FROM nl_projects WHERE watch_mode <> 'off'" . ($onlyProject ? ' AND id = ' . (int)$onlyProject : '');
    $found = [];
    foreach (nl_db()->query($sql)->fetchAll() as $p) {
        $published = $p['watch_mode'] === 'publish' ? 1 : 0;
        // Premier passage sur un projet vide : les versions existantes forment le catalogue de départ
        // (publiées sans verrou ni mail) ; seules les suivantes seront signalées.
        $first = !nl_known_versions((int)$p['id']);
        try {
            if (!empty($p['files_dir']) || !empty($p['files_url'])) {
                $r = $first ? nl_folder_import($p, false) : nl_folder_import($p, true, $published, true);
                if (!$first) { $found = array_merge($found, $r['new']); }
            }
            if (!empty($p['github_repo'])) {
                $r = $first ? nl_gh_import((int)$p['id'], 15) : nl_gh_import((int)$p['id'], 15, ['only_new' => true, 'published' => $published, 'detected' => true]);
                if (!$first) { $found = array_merge($found, $r['new'] ?? []); }
            }
        } catch (Throwable $e) {
            nl_watch_log("{$p['slug']} : " . $e->getMessage());
        }
    }
    if ($found) { nl_watch_log('Nouvelles versions : ' . implode(', ', $found)); }
    nl_notify_admins();
    return $found;
}

// ---------- Mail à l'admin ----------

/** Lien d'action signé pour le mail (valable 30 jours) */
function nl_action_url(int $releaseId, string $action): string
{
    $exp = time() + 30 * 86400;
    $sig = nl_sign("action|$releaseId|$exp");
    return rtrim((string)nl_config()['base_url'], '/') . "/action.php?r=$releaseId&a=$action&e=$exp&s=$sig";
}

function nl_admin_emails(): array
{
    $cfg = array_values(array_filter((array)(nl_config()['admin_emails'] ?? []), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    if ($cfg) { return $cfg; }
    try {
        $rows = nl_db()->query("SELECT email FROM users WHERE role_code >= 2 AND is_banned = 0 AND email <> ''")->fetchAll();
        return array_values(array_filter(array_column($rows, 'email'), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    } catch (Throwable) { return []; }
}

/** Envoie un mail pour chaque version détectée pas encore signalée */
function nl_notify_admins(): int
{
    $db = nl_db();
    $rows = $db->query("SELECT r.*, p.name AS project_name, p.slug, p.watch_mode, p.accent_color
                        FROM nl_releases r JOIN nl_projects p ON p.id = r.project_id
                        WHERE r.detected_at IS NOT NULL AND r.notified = 0 ORDER BY r.id LIMIT 10")->fetchAll();
    if (!$rows) { return 0; }
    $to = nl_admin_emails();
    $sharedMail = dirname((string)(nl_config()['shared_auth'] ?? '/home/didikaz/shared/auth.php')) . '/mail.php';
    $canMail = $to && is_file($sharedMail) && nl_na_load();
    if ($canMail) { $prev = set_error_handler(null); require_once $sharedMail; set_error_handler($prev); }
    $sent = 0;
    foreach ($rows as $r) {
        $name = $r['project_name'] . ' ' . ($r['label'] ?: 'v' . $r['version']);
        $published = (int)$r['published'] === 1;
        $subject = $published ? "Nouvelle version publiée : $name" : "Nouvelle version détectée : $name";
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
        $notes = trim((string)$r['notes']);
        $body = '<p>' . ($published
                ? "<b>{$e($name)}</b> a été détectée et <b>publiée automatiquement</b> dans NounoursLauncher."
                : "<b>{$e($name)}</b> vient d'être détectée. Elle est <b>en attente</b> : choisis quand la sortir.") . '</p>'
            . ($notes !== '' ? '<p style="color:#555;white-space:pre-wrap">' . $e(mb_substr($notes, 0, 1200)) . '</p>' : '')
            . ((int)$r['lock_coins'] > 0 || (int)$r['lock_login'] ? '<p>Verrou appliqué : ' . ((int)$r['lock_coins'] > 0 ? (int)$r['lock_coins'] . ' pièces' : 'compte NounoursAccount') . '.</p>' : '')
            . '<p>'
            . (!$published ? '<a href="' . $e(nl_action_url((int)$r['id'], 'publish')) . '" style="display:inline-block;padding:12px 20px;margin:4px;border-radius:10px;background:#f07c00;color:#fff;text-decoration:none;font-weight:bold">Publier maintenant</a>' : '')
            . '<a href="' . $e(nl_action_url((int)$r['id'], 'schedule')) . '" style="display:inline-block;padding:12px 20px;margin:4px;border-radius:10px;background:#2b7fd6;color:#fff;text-decoration:none;font-weight:bold">' . ($published ? 'Modifier (date, verrous)' : 'Programmer la sortie…') . '</a>'
            . '</p><p style="color:#888;font-size:13px">Ou depuis le panel : ' . $e(rtrim((string)nl_config()['base_url'], '/') . '/admin/') . '</p>';
        $html = function_exists('na_mail_layout') ? na_mail_layout($subject, $body, ['color' => $r['accent_color'] ?: '#f07c00']) : $body;
        $ok = false;
        if ($canMail) { foreach ($to as $addr) { $ok = na_mail_send($addr, $subject, $html) || $ok; } }
        else { nl_watch_log("mail non envoyé (pas d'adresse admin ou shared/mail.php absent) : $subject"); $ok = true; }
        if ($ok) { $db->prepare('UPDATE nl_releases SET notified = 1 WHERE id = ?')->execute([(int)$r['id']]); $sent++; }
    }
    return $sent;
}

/** Sortie d'une version : maintenant ($atUtc null) ou à une date (UTC « Y-m-d H:i:s ») */
function nl_release_schedule(int $releaseId, ?string $atUtc, ?int $lockLogin = null, ?int $lockCoins = null): void
{
    $sets = ['published = 1', 'available_at = ?'];
    $args = [$atUtc];
    if ($lockLogin !== null) { $sets[] = 'lock_login = ?'; $args[] = $lockLogin ? 1 : 0; }
    if ($lockCoins !== null) { $sets[] = 'lock_coins = ?'; $args[] = max(0, $lockCoins); }
    if ($atUtc === null) { $sets[] = 'pub_date = UTC_TIMESTAMP()'; } else { $sets[] = 'pub_date = ?'; $args[] = $atUtc; }
    $args[] = $releaseId;
    nl_db()->prepare('UPDATE nl_releases SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
}

/** Date saisie à l'heure de Paris (datetime-local) → UTC ; null si vide ou passée */
function nl_paris_to_utc(?string $local): ?string
{
    $local = trim((string)$local);
    if ($local === '') { return null; }
    try {
        $d = new DateTime($local, new DateTimeZone('Europe/Paris'));
    } catch (Throwable) { return null; }
    $d->setTimezone(new DateTimeZone('UTC'));
    return $d->getTimestamp() <= time() ? null : $d->format('Y-m-d H:i:s');
}

function nl_utc_to_paris_input(?string $utc): string
{
    if (!$utc) { return ''; }
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone('Europe/Paris'));
    return $d->format('Y-m-d\TH:i');
}

/** PandorPacks : réglage automatique (dossier de nsdkplus.fr) puis premier import, une seule fois */
function nl_setup_pandorpacks(): void
{
    $flag = dirname(__DIR__) . '/cache/.pandorpacks_setup';
    if (is_file($flag)) { return; }
    @file_put_contents($flag, gmdate('c'));
    $db = nl_db();
    $st = $db->prepare("SELECT * FROM nl_projects WHERE slug = 'pandorpacks'");
    $st->execute();
    $p = $st->fetch();
    if (!$p || !empty($p['files_dir']) || !empty($p['files_url'])) { return; } // déjà réglé à la main
    $guess = realpath(dirname(__DIR__, 3) . '/www2/PandorPacks') ?: null;
    $db->prepare("UPDATE nl_projects SET type = 'pack', files_dir = ?, files_url = 'https://nsdkplus.fr/PandorPacks', watch_mode = 'ask' WHERE id = ?")
       ->execute([$guess, (int)$p['id']]);
    $st->execute();
    $p = $st->fetch();
    if (!nl_known_versions((int)$p['id'])) {
        try { nl_folder_import($p, false); } catch (Throwable $e) { nl_watch_log('pandorpacks : ' . $e->getMessage()); }
    }
}

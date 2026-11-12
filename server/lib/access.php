<?php
/**
 * NounoursLauncher — droits de téléchargement
 *
 * Une version peut être :
 *  - programmée : visible (grisée, avec sa date) mais pas téléchargeable avant available_at ;
 *  - réservée aux comptes : connexion NounoursAccount obligatoire ;
 *  - payante : un prix en pièces, payé une seule fois par compte (table nl_purchases).
 * Les admins (rôle ≥ 2) ont toujours accès.
 *
 * Les fichiers protégés ne sont jamais donnés par leur adresse réelle : le catalogue fournit un
 * lien relais signé (api.php?r=asset&id=…&u=…&e=…&s=…) valable jusqu'au lendemain soir.
 */
declare(strict_types=1);

function nl_secret(): string
{
    static $s = null;
    if ($s !== null) { return $s; }
    $s = (string)(nl_config()['secret'] ?? '');
    if ($s === '') {
        $s = (string)(nl_setting('hmac_secret') ?? '');
        if ($s === '') { $s = bin2hex(random_bytes(32)); nl_setting_set('hmac_secret', $s); }
    }
    return $s;
}

function nl_sign(string $data): string
{
    return substr(hash_hmac('sha256', $data, nl_secret()), 0, 32);
}

/** Achats de l'utilisateur courant : [release_id => true] */
function nl_owned(): array
{
    static $owned = null;
    if ($owned !== null) { return $owned; }
    $owned = [];
    $u = nl_user();
    if ($u) {
        $st = nl_db()->prepare('SELECT release_id FROM nl_purchases WHERE user_id = ?');
        $st->execute([$u['id']]);
        foreach ($st->fetchAll() as $r) { $owned[(int)$r['release_id']] = true; }
    }
    return $owned;
}

function nl_release_available(array $r): bool
{
    return empty($r['available_at']) || strtotime($r['available_at'] . ' UTC') <= time();
}

/** État d'accès d'une version pour l'utilisateur courant */
function nl_release_access(array $r): array
{
    $u = nl_user();
    $admin = $u && $u['role'] >= 2;
    $login = (bool)($r['lock_login'] ?? 0);
    $coins = (int)($r['lock_coins'] ?? 0);
    $owned = isset(nl_owned()[(int)$r['id']]);
    $available = nl_release_available($r);
    $reason = null;
    if (!$available) { $reason = 'scheduled'; }
    elseif (($login || $coins > 0) && !$u) { $reason = 'login'; }
    elseif ($coins > 0 && !$owned && !$admin) { $reason = 'coins'; }
    return [
        'login'        => $login || $coins > 0,
        'coins'        => $coins,
        'owned'        => $owned,
        'available'    => $available,
        'available_at' => $available || empty($r['available_at']) ? null : gmdate('c', strtotime($r['available_at'] . ' UTC')),
        'allowed'      => $reason === null,
        'reason'       => $reason, // null | scheduled | login | coins
    ];
}

/** Le fichier vient-il du dossier surveillé du projet ? → chemin local, sinon null */
function nl_local_path(array $a, ?array $project): ?string
{
    $dir = rtrim((string)($project['files_dir'] ?? ''), '/');
    $url = rtrim((string)($project['files_url'] ?? ''), '/');
    if ($dir === '' || $url === '' || !str_starts_with((string)$a['url'], $url . '/')) { return null; }
    $rel = rawurldecode(substr((string)$a['url'], strlen($url) + 1));
    if (str_contains($rel, '..')) { return null; }
    $path = $dir . '/' . $rel;
    $real = realpath($path);
    return ($real && str_starts_with($real, realpath($dir) ?: $dir)) ? $real : null;
}

/** Lien relais signé pour un fichier (valable jusqu'à la fin du lendemain, UTC) */
function nl_signed_asset_url(int $assetId, int $uid): string
{
    $exp = (int)(floor(time() / 86400) + 2) * 86400;
    $sig = nl_sign("asset|$assetId|$uid|$exp");
    return rtrim((string)nl_config()['base_url'], '/') . "/api.php?r=asset&id=$assetId&u=$uid&e=$exp&s=$sig";
}

/**
 * Adresse à donner pour un fichier : null si le visiteur n'y a pas droit,
 * un lien relais signé si le fichier est protégé (verrou, dépôt privé, dossier local),
 * sinon l'adresse publique.
 */
function nl_asset_url_for(array $a, array $release, ?array $project): ?string
{
    $acc = nl_release_access($release);
    if (!$acc['allowed']) { return null; }
    $restricted = $acc['login'] || $acc['coins'] > 0;
    $local = nl_local_path($a, $project) !== null;
    $gh = nl_gh_parse_asset_url((string)$a['url']);
    if ($restricted || $local || ($gh && nl_gh_is_private($gh[0]))) {
        return nl_signed_asset_url((int)$a['id'], (int)(nl_user()['id'] ?? 0));
    }
    return (string)$a['url'];
}

/**
 * ?r=asset&id=N[&u=&e=&s=] — téléchargement relais.
 * Avec une signature valide : accès accordé à l'utilisateur u (vérifié à la création du lien).
 * Sans signature : droits du visiteur (session NounoursAccount ou jeton du launcher).
 */
function route_asset(): never
{
    $id = (int)($_GET['id'] ?? 0);
    $st = nl_db()->prepare('SELECT a.*, r.published, r.version, r.available_at, r.lock_login, r.lock_coins, r.id AS rid,
                                   p.visibility, p.slug, p.files_dir, p.files_url
                            FROM nl_assets a JOIN nl_releases r ON r.id = a.release_id JOIN nl_projects p ON p.id = r.project_id
                            WHERE a.id = ?');
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a || !(int)$a['published']) { nl_fail('Fichier introuvable', 404); }
    $release = ['id' => $a['rid'], 'available_at' => $a['available_at'], 'lock_login' => $a['lock_login'], 'lock_coins' => $a['lock_coins']];

    $u = (int)($_GET['u'] ?? -1); $e = (int)($_GET['e'] ?? 0); $s = (string)($_GET['s'] ?? '');
    $signed = $s !== '' && $e >= time() && hash_equals(nl_sign("asset|$id|$u|$e"), $s);
    if ($signed) {
        if (!nl_release_available($release)) { nl_fail('Cette version n\'est pas encore sortie', 403); }
    } else {
        if (!in_array($a['visibility'], nl_visibilities_for(nl_viewer_level()), true)) { nl_fail('Fichier introuvable', 404); }
        $acc = nl_release_access($release);
        if (!$acc['allowed']) {
            $msg = ['scheduled' => 'Cette version n\'est pas encore sortie', 'login' => 'Connexion NounoursAccount requise',
                    'coins' => 'Version à débloquer avec des pièces'][$acc['reason']] ?? 'Accès refusé';
            nl_fail($msg, $acc['reason'] === 'login' ? 401 : 403);
        }
    }

    // 1) Fichier du dossier surveillé : servi directement
    $local = nl_local_path($a, $a);
    if ($local !== null) { nl_send_file($local); }
    // 2) Dépôt GitHub privé : adresse temporaire
    if (nl_gh_parse_asset_url((string)$a['url']) && nl_gh_token() !== '') { nl_gh_redirect_asset($a); }
    // 3) Sinon : adresse publique
    nl_redirect((string)$a['url']);
}

function nl_send_file(string $path): never
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    $name = basename($path);
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($path));
    header("Content-Disposition: attachment; filename=\"" . str_replace('"', '', $name) . "\"; filename*=UTF-8''" . rawurlencode($name));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    $fh = fopen($path, 'rb');
    while ($fh && !feof($fh)) { echo fread($fh, 1 << 16); flush(); }
    if ($fh) { fclose($fh); }
    exit;
}

/**
 * Achat d'une version avec des pièces (une fois par compte). Utilisé par l'API et par les sites.
 * Renvoie ['ok' => bool, 'coins' => solde, 'error' => ?string]
 */
function nl_unlock_release(int $uid, int $releaseId): array
{
    $db = nl_db();
    $st = $db->prepare('SELECT r.*, p.name AS project_name FROM nl_releases r JOIN nl_projects p ON p.id = r.project_id WHERE r.id = ? AND r.published = 1');
    $st->execute([$releaseId]);
    $r = $st->fetch();
    $u = nl_user_by_id($uid);
    if (!$r || !$u) { return ['ok' => false, 'error' => 'Version introuvable', 'coins' => $u['balance'] ?? 0]; }
    if (!nl_release_available($r)) { return ['ok' => false, 'error' => 'Cette version n\'est pas encore sortie', 'coins' => $u['balance']]; }
    $price = (int)$r['lock_coins'];
    $chk = $db->prepare('SELECT 1 FROM nl_purchases WHERE user_id = ? AND release_id = ?');
    $chk->execute([$uid, $releaseId]);
    if ($price <= 0 || $chk->fetchColumn()) { return ['ok' => true, 'coins' => $u['balance']]; }

    $db->beginTransaction();
    try {
        $up = $db->prepare('UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?');
        $up->execute([$price, $uid, $price]);
        if ($up->rowCount() !== 1) {
            $db->rollBack();
            return ['ok' => false, 'error' => "Pas assez de pièces ($price nécessaires, tu en as {$u['balance']})", 'coins' => $u['balance']];
        }
        $db->prepare('INSERT INTO nl_purchases (user_id, release_id, coins) VALUES (?, ?, ?)')->execute([$uid, $releaseId, $price]);
        try { // journal des pièces de NounoursAccount (si la table existe)
            $label = mb_substr($r['project_name'] . ' ' . ($r['label'] ?: 'v' . $r['version']), 0, 100);
            $db->prepare("INSERT INTO na_coin_log (user_id, amount, reason, source) VALUES (?, ?, ?, 'launcher')")
               ->execute([$uid, -$price, 'Déblocage : ' . $label]);
        } catch (Throwable) { /* journal facultatif */ }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    if (session_status() === PHP_SESSION_ACTIVE && (int)($_SESSION['user_id'] ?? 0) === $uid && isset($_SESSION['balance'])) {
        $_SESSION['balance'] = (float)$_SESSION['balance'] - $price;
    }
    return ['ok' => true, 'coins' => $u['balance'] - $price, 'spent' => $price];
}

/** POST ?r=unlock {release_id} */
function route_unlock(): never
{
    nl_require_post();
    $u = nl_require_user();
    $res = nl_unlock_release($u['id'], (int)(nl_input()['release_id'] ?? 0));
    if (!$res['ok']) { nl_json(['error' => $res['error'], 'coins' => $res['coins']], 402); }
    nl_json($res);
}

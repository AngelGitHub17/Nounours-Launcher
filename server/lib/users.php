<?php
/**
 * NounoursLauncher — comptes NounoursAccount
 *
 * Qui fait la requête ?
 *  - le launcher : en-tête « Authorization: Bearer nlu_… » (jeton obtenu par connexion avec un code) ;
 *  - un navigateur sur un site du réseau : la session NounoursAccount (shared/auth.php).
 *
 * Connexion du launcher sans mot de passe dans l'appli :
 *  1. le launcher demande un code (?r=auth/start) et ouvre connect.php?code=XXXX-XXXX dans le navigateur ;
 *  2. sur le site (connecté à NounoursAccount), on clique « Autoriser » ;
 *  3. le launcher interroge ?r=auth/poll et reçoit son jeton.
 */
declare(strict_types=1);

const NL_TOKEN_PREFIX = 'nlu_';

/** Utilisateur courant : ['id','name','username','avatar','balance','role','email'] ou null */
function nl_user(): ?array
{
    static $cache = false;
    if ($cache !== false) { return $cache; }
    $cache = null;
    $uid = null;

    $tok = nl_bearer();
    if ($tok !== null && str_starts_with($tok, NL_TOKEN_PREFIX)) {
        $st = nl_db()->prepare('SELECT user_id, last_used FROM nl_tokens WHERE token_hash = ?');
        $st->execute([hash('sha256', $tok)]);
        $row = $st->fetch();
        if ($row) {
            $uid = (int)$row['user_id'];
            if (!$row['last_used'] || strtotime($row['last_used'] . ' UTC') < time() - 3600) {
                nl_db()->prepare('UPDATE nl_tokens SET last_used = UTC_TIMESTAMP() WHERE token_hash = ?')->execute([hash('sha256', $tok)]);
            }
        }
    } elseif (nl_has_credentials() && nl_na_load() && auth_is_logged()) {
        $uid = (int)auth_user_id();
    }
    if (!$uid) { return null; }
    return $cache = nl_user_by_id($uid);
}

function nl_user_by_id(int $uid): ?array
{
    try {
        $st = nl_db()->prepare('SELECT id, username, display_name, email, role_code, balance, is_banned FROM users WHERE id = ?');
        $st->execute([$uid]);
        $u = $st->fetch();
    } catch (Throwable) {
        return null; // table users absente (installation de test)
    }
    if (!$u || (int)($u['is_banned'] ?? 0) === 1) { return null; }
    return [
        'id'       => (int)$u['id'],
        'username' => (string)$u['username'],
        'name'     => (string)(($u['display_name'] ?? '') ?: $u['username']),
        'email'    => (string)($u['email'] ?? ''),
        'role'     => (int)$u['role_code'],
        'balance'  => (int)floor((float)($u['balance'] ?? 0)),
        'avatar'   => 'https://didikaria.fr/avatar.php?u=' . (int)$u['id'],
    ];
}

function nl_require_user(): array
{
    $u = nl_user();
    if (!$u) { nl_fail('Connexion NounoursAccount requise', 401); }
    return $u;
}

function nl_public_user(array $u): array
{
    return ['id' => $u['id'], 'name' => $u['name'], 'username' => $u['username'], 'avatar' => $u['avatar'],
            'coins' => $u['balance'], 'admin' => $u['role'] >= 2];
}

// ---------- Connexion du launcher par code ----------

function nl_auth_code(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sans 0/O ni 1/I
    $c = '';
    for ($i = 0; $i < 8; $i++) { $c .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
    return $c;
}

/** POST ?r=auth/start {label?} → {code, url, poll, expires_in} */
function route_auth_start(): never
{
    nl_require_post();
    $db = nl_db();
    $db->exec('DELETE FROM nl_auth_codes WHERE expires_at < UTC_TIMESTAMP()');
    $code = nl_auth_code();
    $poll = bin2hex(random_bytes(24));
    $label = nl_str(nl_input(), 'label', 80) ?? 'NounoursLauncher';
    $db->prepare('INSERT INTO nl_auth_codes (code, poll_hash, label, expires_at) VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL 10 MINUTE)')
       ->execute([$code, hash('sha256', $poll), $label]);
    $pretty = substr($code, 0, 4) . '-' . substr($code, 4);
    nl_json([
        'code'       => $pretty,
        'url'        => rtrim((string)nl_config()['base_url'], '/') . '/connect.php?code=' . $pretty,
        'poll'       => $poll,
        'expires_in' => 600,
    ]);
}

/** POST ?r=auth/poll {poll} → {pending:true} | {token, user} */
function route_auth_poll(): never
{
    nl_require_post();
    $poll = (string)(nl_input()['poll'] ?? '');
    $db = nl_db();
    $st = $db->prepare('SELECT code, user_id, label, expires_at < UTC_TIMESTAMP() AS expired FROM nl_auth_codes WHERE poll_hash = ?');
    $st->execute([hash('sha256', $poll)]);
    $row = $st->fetch();
    if (!$row || (int)$row['expired']) { nl_fail('Code expiré : recommence la connexion', 410); }
    if (!$row['user_id']) { nl_json(['pending' => true]); }

    $token = NL_TOKEN_PREFIX . bin2hex(random_bytes(24));
    $db->prepare('INSERT INTO nl_tokens (token_hash, user_id, label) VALUES (?, ?, ?)')->execute([hash('sha256', $token), (int)$row['user_id'], $row['label']]);
    $db->prepare('DELETE FROM nl_auth_codes WHERE code = ?')->execute([$row['code']]);
    $u = nl_user_by_id((int)$row['user_id']);
    if (!$u) { nl_fail('Compte indisponible', 403); }
    nl_json(['token' => $token, 'user' => nl_public_user($u)]);
}

/** Validation du code depuis le site (connect.php) */
function nl_auth_approve(string $code, int $uid): bool
{
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    $st = nl_db()->prepare('UPDATE nl_auth_codes SET user_id = ? WHERE code = ? AND user_id IS NULL AND expires_at > UTC_TIMESTAMP()');
    $st->execute([$uid, $code]);
    return $st->rowCount() === 1;
}

/** GET ?r=auth/me → utilisateur connecté (ou null) */
function route_auth_me(): never
{
    $u = nl_user();
    nl_json(['user' => $u ? nl_public_user($u) : null]);
}

/** POST ?r=auth/logout → supprime le jeton du launcher */
function route_auth_logout(): never
{
    nl_require_post();
    $tok = nl_bearer();
    if ($tok && str_starts_with($tok, NL_TOKEN_PREFIX)) {
        nl_db()->prepare('DELETE FROM nl_tokens WHERE token_hash = ?')->execute([hash('sha256', $tok)]);
    }
    nl_json(['ok' => true]);
}

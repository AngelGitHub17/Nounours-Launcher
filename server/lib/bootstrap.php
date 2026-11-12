<?php
/**
 * NounoursLauncher — noyau commun (config, PDO, JSON, auth, helpers)
 */
declare(strict_types=1);

ob_start(); // toute sortie parasite (warnings, BOM...) est jetée avant le JSON
date_default_timezone_set('UTC'); // dates manipulées et stockées en UTC (le launcher convertit en heure locale)

const NL_API_VERSION = 1;

/** Plateformes reconnues par le catalogue */
const NL_PLATFORMS = [
    'windows-x86_64', 'windows-aarch64',
    'darwin-aarch64', 'darwin-x86_64',
    'linux-x86_64',   'linux-aarch64',
    'android', 'ios', 'web', 'server',
];
const NL_KINDS = ['installer','updater','portable','msi','apk','dmg','pkg','appimage','deb','rpm','jar','zip','store','web'];
const NL_TYPES = ['site','app','plugin','service','bot','tool','launcher'];
const NL_STATUSES = ['online','beta','dev','archived'];
const NL_VISIBILITIES = ['public','members','private'];

function nl_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = dirname(__DIR__) . '/config.php';
        if (!is_file($file)) {
            nl_json(['error' => 'config.php manquant (copier config.sample.php)'], 500);
        }
        $cfg = require $file;
    }
    return $cfg;
}

function nl_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = nl_config()['db'];
        $pdo = new PDO(
            "mysql:host={$c['host']};dbname={$c['name']};charset={$c['charset']}",
            $c['user'], $c['pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]
        );
        $pdo->exec("SET time_zone = '+00:00'"); // toutes les dates stockées en UTC
    }
    return $pdo;
}

/** Envoie une réponse JSON propre et termine */
function nl_json(mixed $data, int $code = 200, array $headers = []): never
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    foreach ($headers as $k => $v) { header("$k: $v"); }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function nl_no_content(): never
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(204);
    exit;
}

function nl_fail(string $message, int $code = 400): never
{
    nl_json(['error' => $message], $code);
}

/** Corps JSON d'une requête POST (ou $_POST pour les formulaires) */
function nl_input(): array
{
    static $in = null;
    if ($in === null) {
        $raw = file_get_contents('php://input') ?: '';
        $in = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($in)) { $in = $_POST; }
    }
    return $in;
}

function nl_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        nl_fail('Méthode POST requise', 405);
    }
}

function nl_bearer(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? getallheaders()['authorization'] ?? '') : '');
    if (preg_match('/^Bearer\s+(\S+)$/i', (string)$h, $m)) {
        return $m[1];
    }
    // Repli : certains hébergements suppriment Authorization → en-tête dédié
    $alt = $_SERVER['HTTP_X_NL_TOKEN'] ?? '';
    return $alt !== '' ? $alt : null;
}

function nl_token_matches(?string $token, array $hashes): bool
{
    if ($token === null || $token === '') { return false; }
    $h = hash('sha256', $token);
    foreach ($hashes as $known) {
        if (is_string($known) && hash_equals(strtolower($known), $h)) { return true; }
    }
    return false;
}

// ---------- NounoursAccount (shared/auth.php) ----------

/** Charge l'auth commune du réseau. Renvoie false si le fichier est introuvable. */
function nl_na_load(): bool
{
    static $loaded = null;
    if ($loaded === null) {
        $file = nl_config()['shared_auth'] ?? '/home/didikaz/shared/auth.php';
        $loaded = is_file($file);
        if ($loaded) {
            // auth.php contient des @mkdir/@session_start : on ne transforme pas ses avertissements en exceptions
            $prev = set_error_handler(null);
            try { require_once $file; } finally { set_error_handler($prev); }
        }
    }
    return $loaded && function_exists('auth_is_logged');
}

/** La requête porte-t-elle des identifiants (cookie de session ou en-tête) ? */
function nl_has_credentials(): bool
{
    return nl_bearer() !== null || !empty($_COOKIE);
}

/** Jeton de secours (config) pour un admin — facultatif */
function nl_is_token_admin(): bool
{
    return nl_token_matches(nl_bearer(), nl_config()['admin_token_hashes'] ?? []);
}

function nl_is_admin(): bool
{
    if (nl_is_token_admin()) { return true; }
    return nl_has_credentials() && nl_na_load() && auth_is_admin();
}

/**
 * Exige un administrateur NounoursAccount (rôle ≥ 2).
 * Connexion par cookie de session → jeton CSRF obligatoire (en-tête X-NL-CSRF).
 * Connexion par Bearer (launcher) → pas de CSRF (en-tête personnalisé non falsifiable par un autre site).
 */
function nl_require_admin(): void
{
    if (nl_is_token_admin()) { $GLOBALS['NL_TRUSTED'] = true; return; }
    if (!nl_na_load()) { nl_fail('shared/auth.php introuvable (voir shared_auth dans config.php)', 500); }
    if (!auth_is_logged()) { nl_fail('Connexion NounoursAccount requise', 401); }
    if (!auth_is_admin()) { nl_fail('Réservé aux administrateurs', 403); }
    $viaHeader = preg_match('/^Bearer\s/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (!$viaHeader) {
        $sent = (string)($_SERVER['HTTP_X_NL_CSRF'] ?? '');
        if ($sent === '' || !hash_equals((string)($_SESSION['nl_csrf'] ?? ''), $sent)) {
            nl_fail('Jeton de sécurité expiré : recharge la page', 419);
        }
    }
    $GLOBALS['NL_TRUSTED'] = true;
}

/** Jeton CSRF de la session (créé à l'ouverture du panel) */
function nl_csrf_token(): string
{
    if (empty($_SESSION['nl_csrf'])) { $_SESSION['nl_csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['nl_csrf'];
}

function nl_require_ci(): void
{
    $t = nl_bearer();
    $cfg = nl_config();
    $hashes = $cfg['ci_token_hashes'] ?? [];
    $stored = nl_setting('ci_token_hash');
    if ($stored) { $hashes[] = $stored; }
    if (!nl_token_matches($t, $hashes) && !nl_is_token_admin()) {
        nl_fail('Jeton CI invalide', 401);
    }
    $GLOBALS['NL_TRUSTED'] = true;
}

/** Journal des erreurs serveur (cache/errors.log, 200 Ko max) ; renvoie une référence courte */
function nl_log_error(string $route, string $detail): string
{
    $ref = substr(bin2hex(random_bytes(4)), 0, 8);
    $file = dirname(__DIR__) . '/cache/errors.log';
    if (is_file($file) && filesize($file) > 200_000) { @rename($file, $file . '.old'); }
    @file_put_contents($file, gmdate('c') . " [$ref] $route : $detail\n", FILE_APPEND | LOCK_EX);
    return $ref;
}

/** Niveau de visibilité accessible par l'appelant : public < members < private */
function nl_viewer_level(): int
{
    if (nl_is_token_admin()) { return 2; }
    if (!nl_has_credentials() || !nl_na_load()) { return 0; }
    if (auth_is_admin()) { return 2; }
    return auth_is_logged() ? 1 : 0;
}

// ---------- Réglages stockés en base ----------

function nl_setting(string $key): ?string
{
    $st = nl_db()->prepare('SELECT v FROM nl_settings WHERE k = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

function nl_setting_set(string $key, ?string $value): void
{
    if ($value === null) {
        nl_db()->prepare('DELETE FROM nl_settings WHERE k = ?')->execute([$key]);
        return;
    }
    nl_db()->prepare('INSERT INTO nl_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$key, $value]);
}

// ---------- Création automatique des tables ----------

function nl_ensure_schema(): void
{
    $dir = dirname(__DIR__) . '/cache';
    if (is_file("$dir/.schema_v3")) { return; }
    $flag = "$dir/.schema_v1";
    if (is_file($flag)) { nl_migrate_v2(); nl_migrate_v3(); return; }
    $db = nl_db();
    $sql = (string)file_get_contents(dirname(__DIR__) . '/schema.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $db->exec($stmt);
    }
    require_once __DIR__ . '/seed.php';
    nl_seed($db);
    if (!is_dir(dirname($flag))) { @mkdir(dirname($flag), 0755, true); }
    @file_put_contents($flag, gmdate('c'));
    nl_migrate_v2();
    nl_migrate_v3();
}

function nl_has_column(string $table, string $column): bool
{
    $st = nl_db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
}

/** v3 : dépôt GitHub du projet (import des releases, dépôts privés) */
function nl_migrate_v3(): void
{
    if (!nl_has_column('nl_projects', 'github_repo')) {
        nl_db()->exec('ALTER TABLE nl_projects ADD COLUMN github_repo VARCHAR(140) NULL AFTER repo_url');
    }
    @file_put_contents(dirname(__DIR__) . '/cache/.schema_v3', gmdate('c'));
}

/** v2 : chemin de lancement des applis installées par le launcher */
function nl_migrate_v2(): void
{
    $db = nl_db();
    $st = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nl_assets' AND COLUMN_NAME = 'launch_path'");
    if ((int)$st->fetchColumn() === 0) {
        $db->exec('ALTER TABLE nl_assets ADD COLUMN launch_path VARCHAR(500) NULL AFTER install_args');
    }
    @file_put_contents(dirname(__DIR__) . '/cache/.schema_v2', gmdate('c'));
}

function nl_cors(bool $adminRoute): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!$adminRoute) {
        header('Access-Control-Allow-Origin: *');
    } elseif ($origin !== '' && in_array($origin, nl_config()['admin_origins'] ?? [], true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-NL-Token, X-NL-CSRF, X-NL-Channel, If-None-Match');
    header('Access-Control-Expose-Headers: ETag');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { nl_no_content(); }
}

// ---------- Helpers de validation ----------

function nl_str(array $in, string $key, int $max, bool $required = false): ?string
{
    $v = $in[$key] ?? null;
    if ($v === null || (is_string($v) && trim($v) === '')) {
        if ($required) { nl_fail("Champ requis : $key"); }
        return null;
    }
    if (!is_scalar($v)) { nl_fail("Champ invalide : $key"); }
    $v = trim((string)$v);
    if (mb_strlen($v) > $max) { nl_fail("Champ trop long : $key (max $max)"); }
    return $v;
}

function nl_enum(array $in, string $key, array $allowed, ?string $default = null): string
{
    $v = $in[$key] ?? $default;
    if (!in_array($v, $allowed, true)) { nl_fail("Valeur invalide pour $key"); }
    return $v;
}

function nl_url(array $in, string $key, bool $required = false, array $schemes = ['https','http']): ?string
{
    $v = nl_str($in, $key, 700, $required);
    if ($v === null) { return null; }
    $scheme = strtolower((string)parse_url($v, PHP_URL_SCHEME));
    if (!in_array($scheme, $schemes, true) || !parse_url($v, PHP_URL_HOST)) {
        nl_fail("URL invalide pour $key");
    }
    return $v;
}

function nl_bool(array $in, string $key, bool $default = false): int
{
    if (!array_key_exists($key, $in)) { return $default ? 1 : 0; }
    return filter_var($in[$key], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
}

function nl_slugify(string $s): string
{
    $s = strtolower(trim($s));
    $s = strtr($s, ['é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','à'=>'a','â'=>'a','ä'=>'a','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-');
}

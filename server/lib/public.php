<?php
/**
 * NounoursLauncher — catalogue pour les autres sites du réseau (ex. nsdkplus.fr/?page=pandorpacks)
 *
 *   $pp = @include '/home/didikaz/www4/launcher/lib/public.php';
 *   $data = $pp ? nl_pub_project('pandorpacks') : null;   // null = launcher indisponible → ancien affichage
 *   $msg  = $pp ? nl_pub_handle_unlock() : null;          // à appeler avant tout affichage (POST « Débloquer »)
 *
 * Les liens donnés sont personnels (signés pour le compte NounoursAccount connecté) ;
 * les versions verrouillées ou programmées n'ont pas de lien.
 */
declare(strict_types=1);

if (!is_file(dirname(__DIR__) . '/config.php')) { return false; }

$__nl_tz = date_default_timezone_get();
$__nl_ob = ob_get_level();
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/catalog.php';
while (ob_get_level() > $__nl_ob) { ob_end_flush(); } // le site garde la main sur sa sortie
date_default_timezone_set($__nl_tz);

/** Projet + versions (récentes d'abord) pour le visiteur actuel, ou null si introuvable / erreur */
function nl_pub_project(string $slug, int $limit = 40): ?array
{
    try {
        nl_ensure_schema();
        $level = nl_viewer_level();
        $list = nl_load($level, $slug);
        $p = $list ? reset($list) : null;
        if (!$p) { return null; }
        $live = []; $upcoming = [];
        foreach ($p['releases'] as $r) {
            if (!(int)$r['published']) { continue; }
            $f = nl_fmt_release($r, null, $p);
            $f['name'] = $r['label'] ?: 'v' . $r['version'];
            $f['mc'] = preg_match('/Minecraft\s+\**\s*([\d.]+)/i', (string)$r['notes'], $m) ? $m[1] : null;
            $f['url'] = $f['assets'][0]['url'] ?? null;
            $f['filename'] = $f['assets'][0]['name'] ?? null;
            if (nl_release_available($r)) { $live[] = $f; } else { $upcoming[] = $f; }
        }
        usort($upcoming, fn($a, $b) => strcmp((string)$a['access']['available_at'], (string)$b['access']['available_at']));
        $u = nl_user();
        return [
            'name'     => $p['name'],
            'releases' => array_slice($live, 0, $limit),
            'upcoming' => $upcoming,
            'user'     => $u ? ['name' => $u['name'], 'coins' => (int)$u['balance']] : null,
            'csrf'     => session_status() === PHP_SESSION_ACTIVE ? nl_csrf_token() : '',
        ];
    } catch (Throwable $e) {
        nl_log_error('public', $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
        return null;
    }
}

/**
 * Formulaire « Débloquer » (POST nl_unlock = id de version, nl_csrf) : renvoie ['ok'=>bool,'msg'=>…] ou null.
 * Redirige ensuite vers la même page (évite un second envoi au rechargement).
 */
function nl_pub_handle_unlock(): ?array
{
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['nl_pub_flash'])) {
        $f = $_SESSION['nl_pub_flash']; unset($_SESSION['nl_pub_flash']);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { return $f; }
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !isset($_POST['nl_unlock'])) { return null; }
    try {
        $u = nl_user();
        if (!$u) { $res = ['ok' => false, 'msg' => 'Connecte-toi à ton compte NounoursAccount pour débloquer cette version.']; }
        elseif (!hash_equals(nl_csrf_token(), (string)($_POST['nl_csrf'] ?? ''))) { $res = ['ok' => false, 'msg' => 'Session expirée : réessaie.']; }
        else {
            $r = nl_unlock_release($u['id'], (int)$_POST['nl_unlock']);
            $res = $r['ok'] ? ['ok' => true, 'msg' => 'Version débloquée ! Il te reste ' . (int)$r['coins'] . ' pièces.'] : ['ok' => false, 'msg' => $r['error']];
        }
    } catch (Throwable $e) {
        nl_log_error('public/unlock', $e->getMessage());
        $res = ['ok' => false, 'msg' => 'Erreur serveur, réessaie plus tard.'];
    }
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        $_SESSION['nl_pub_flash'] = $res;
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/'), true, 303);
        exit;
    }
    return $res;
}

/** Texte du bouton d'une version selon son accès */
function nl_pub_lock_text(array $access): string
{
    return match ($access['reason']) {
        'login'     => 'Réservé aux comptes NounoursAccount',
        'coins'     => 'Débloquer pour ' . (int)$access['coins'] . ' pièces',
        'scheduled' => 'Bientôt disponible',
        default     => '',
    };
}

return true;

<?php
/**
 * NounoursLauncher — connecter le launcher à un compte NounoursAccount
 * Le launcher affiche un code et ouvre cette page ; on la valide une fois connecté au site.
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

if (!nl_na_load()) { http_response_code(500); exit('shared/auth.php introuvable'); }
auth_require_login(); // pas connecté → connexion NounoursAccount, puis retour ici
nl_ensure_schema();

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$code = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string)($_POST['code'] ?? $_GET['code'] ?? '')) ?? '');
$csrf = nl_csrf_token();
$done = null; $error = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $error = 'Session expirée : recharge la page.';
    } elseif (nl_auth_approve($code, (int)auth_user_id())) {
        $done = true;
    } else {
        $error = 'Code inconnu ou expiré. Relance la connexion depuis le launcher.';
    }
}
$st = nl_db()->prepare('SELECT label FROM nl_auth_codes WHERE code = ? AND user_id IS NULL AND expires_at > UTC_TIMESTAMP()');
$st->execute([preg_replace('/[^A-Z0-9]/', '', $code)]);
$label = $st->fetchColumn();
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connecter NounoursLauncher</title>
<link rel="icon" href="logo.png">
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;font:16px/1.5 system-ui,"Segoe UI",sans-serif;color:#e9eef7;
  background:radial-gradient(800px 400px at 10% 0%,#ff9a1f33,transparent 60%),radial-gradient(800px 500px at 100% 100%,#2a7fd955,transparent 60%),#0c1424;padding:20px}
.box{width:min(440px,100%);padding:30px;border-radius:22px;background:#ffffff10;border:1px solid #ffffff22;box-shadow:0 20px 50px #0008;text-align:center}
.box img.logo{width:72px;height:72px;border-radius:18px}
h1{font-size:1.4rem;margin:12px 0 4px}
.muted{color:#93a2ba}
.me{display:flex;align-items:center;justify-content:center;gap:10px;margin:18px 0}
.me img{width:40px;height:40px;border-radius:50%}
.code{font:700 2rem/1 ui-monospace,Consolas,monospace;letter-spacing:.12em;padding:14px;border-radius:14px;background:#0006;border:1px dashed #ffffff40;margin:16px 0}
input{width:100%;box-sizing:border-box;font:700 1.4rem ui-monospace,Consolas,monospace;text-align:center;letter-spacing:.1em;padding:12px;border-radius:12px;border:1px solid #ffffff30;background:#0006;color:inherit;text-transform:uppercase}
button{width:100%;margin-top:14px;padding:13px;border:0;border-radius:12px;font-weight:700;font-size:1rem;cursor:pointer;color:#241200;background:linear-gradient(180deg,#ffad42,#f07c00)}
.ok{font-size:3rem}
.err{color:#ff9b9b;margin-top:10px}
</style>
</head>
<body>
<div class="box">
  <img class="logo" src="logo.png" alt="">
  <?php if ($done): ?>
    <div class="ok">✅</div>
    <h1>Launcher connecté !</h1>
    <p class="muted">Tu peux fermer cette page et revenir dans NounoursLauncher.</p>
  <?php else: ?>
    <h1>Connecter NounoursLauncher</h1>
    <p class="muted">Autoriser <?= $h($label ?: 'le launcher') ?> à utiliser ton compte : voir les projets réservés, débloquer des versions avec tes pièces.</p>
    <div class="me"><img src="<?= $h(auth_avatar_src()) ?>" alt=""><b><?= $h(auth_display_name()) ?></b></div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <?php if ($label): ?>
        <div class="code"><?= $h($code) ?></div>
        <input type="hidden" name="code" value="<?= $h($code) ?>">
        <p class="muted" style="font-size:.9rem">Vérifie que ce code est bien celui affiché dans le launcher.</p>
      <?php else: ?>
        <p class="muted">Saisis le code affiché dans le launcher :</p>
        <input name="code" value="<?= $h($code) ?>" placeholder="XXXX-XXXX" maxlength="9" autocomplete="off" autofocus>
      <?php endif; ?>
      <button>Autoriser ce launcher</button>
    </form>
    <?php if ($error): ?><p class="err"><?= $h($error) ?></p><?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>

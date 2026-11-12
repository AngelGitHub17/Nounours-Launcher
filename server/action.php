<?php
/**
 * NounoursLauncher — actions depuis le mail « Nouvelle version détectée »
 * Le lien est signé (30 jours) : il suffit, pas besoin d'être connecté.
 * La page demande toujours une confirmation (bouton) : un simple clic dans le mail ne publie rien.
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/catalog.php';
while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
nl_ensure_schema();

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$rid = (int)($src['r'] ?? 0);
$exp = (int)($src['e'] ?? 0);
$sig = (string)($src['s'] ?? '');
$action = ($src['a'] ?? '') === 'publish' ? 'publish' : 'schedule';

$valid = $rid > 0 && $exp >= time()
    && (hash_equals(nl_sign("action|$rid|$exp"), $sig));
$st = nl_db()->prepare('SELECT r.*, p.name AS project_name, p.slug FROM nl_releases r JOIN nl_projects p ON p.id = r.project_id WHERE r.id = ?');
$st->execute([$rid]);
$rel = $valid ? $st->fetch() : null;

$done = null; $error = null;
if ($rel && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $lockCoins = max(0, min(1_000_000, (int)($_POST['lock_coins'] ?? 0)));
    $lockLogin = !empty($_POST['lock_login']) || $lockCoins > 0 ? 1 : 0;
    if (($_POST['when'] ?? 'now') === 'at') {
        $at = nl_paris_to_utc((string)($_POST['local'] ?? ''));
        if ($at === null) { $error = 'Choisis une date et une heure dans le futur.'; }
        else { nl_release_schedule($rid, $at, $lockLogin, $lockCoins); $done = 'Sortie programmée le ' . date_create($at . ' UTC')->setTimezone(new DateTimeZone('Europe/Paris'))->format('d/m/Y à H:i') . ' (heure de Paris).'; }
    } else {
        nl_release_schedule($rid, null, $lockLogin, $lockCoins);
        $done = 'Version publiée : elle est disponible dans le launcher et sur les sites.';
    }
    $st->execute([$rid]);
    $rel = $st->fetch();
}
$name = $rel ? $rel['project_name'] . ' ' . ($rel['label'] ?: 'v' . $rel['version']) : '';
$state = !$rel ? '' : (!(int)$rel['published'] ? 'En attente' : (nl_release_available($rel) ? 'Publiée' : 'Programmée'));
$minLocal = (new DateTime('now', new DateTimeZone('Europe/Paris')))->modify('+5 minutes')->format('Y-m-d\TH:i');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sortie d'une version — NounoursLauncher</title>
<link rel="icon" href="logo.png">
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;font:16px/1.5 system-ui,"Segoe UI",sans-serif;color:#e9eef7;
  background:radial-gradient(800px 400px at 10% 0%,#ff9a1f33,transparent 60%),radial-gradient(800px 500px at 100% 100%,#2a7fd955,transparent 60%),#0c1424;padding:20px}
.box{width:min(480px,100%);padding:30px;border-radius:22px;background:#ffffff10;border:1px solid #ffffff22;box-shadow:0 20px 50px #0008}
.logo{width:56px;height:56px;border-radius:14px;display:block;margin:0 auto}
h1{font-size:1.35rem;margin:12px 0 2px;text-align:center}
.muted{color:#93a2ba}.center{text-align:center}
.badge{display:inline-block;padding:2px 10px;border-radius:99px;background:#ffffff1a;font-size:.85rem}
fieldset{border:1px solid #ffffff22;border-radius:14px;margin:16px 0;padding:12px 16px}
legend{padding:0 6px;color:#93a2ba;font-size:.9rem}
label{display:flex;align-items:center;gap:10px;margin:8px 0;cursor:pointer}
input[type=datetime-local],input[type=number]{font:inherit;padding:8px 10px;border-radius:10px;border:1px solid #ffffff30;background:#0006;color:inherit;color-scheme:dark}
input[type=number]{width:110px}
button{width:100%;margin-top:8px;padding:13px;border:0;border-radius:12px;font-weight:700;font-size:1rem;cursor:pointer;color:#241200;background:linear-gradient(180deg,#ffad42,#f07c00)}
.ok{color:#8fe3a5;text-align:center;font-weight:600}.err{color:#ff9b9b;text-align:center}
pre{white-space:pre-wrap;font:inherit;font-size:.9rem;color:#c3cede;max-height:180px;overflow:auto;background:#0004;padding:10px;border-radius:10px}
</style>
</head>
<body>
<div class="box">
  <img class="logo" src="logo.png" alt="">
  <?php if (!$rel): ?>
    <h1>Lien invalide ou expiré</h1>
    <p class="muted center">Ouvre le panel admin pour gérer cette version.</p>
  <?php else: ?>
    <h1><?= $h($name) ?></h1>
    <p class="center"><span class="badge"><?= $h($state) ?></span></p>
    <?php if ($done): ?><p class="ok">✅ <?= $h($done) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="err"><?= $h($error) ?></p><?php endif; ?>
    <?php if (trim((string)$rel['notes']) !== ''): ?><pre><?= $h(mb_substr((string)$rel['notes'], 0, 2000)) ?></pre><?php endif; ?>
    <form method="post">
      <input type="hidden" name="r" value="<?= $rid ?>"><input type="hidden" name="e" value="<?= $exp ?>"><input type="hidden" name="s" value="<?= $h($sig) ?>">
      <fieldset><legend>Sortie</legend>
        <label><input type="radio" name="when" value="now" <?= $action === 'publish' ? 'checked' : '' ?>> Publier maintenant</label>
        <label><input type="radio" name="when" value="at" id="at" <?= $action !== 'publish' ? 'checked' : '' ?>> Programmer le
          <input type="datetime-local" name="local" min="<?= $minLocal ?>" value="<?= $h(nl_utc_to_paris_input($rel['available_at']) ?: '') ?>" onfocus="document.getElementById('at').checked=true"></label>
        <p class="muted" style="font-size:.85rem;margin:4px 0 0">Heure de Paris. En attendant, la version apparaît grisée avec un compte à rebours.</p>
      </fieldset>
      <fieldset><legend>Accès</legend>
        <label><input type="checkbox" name="lock_login" value="1" <?= (int)$rel['lock_login'] ? 'checked' : '' ?>> Réservée aux comptes NounoursAccount</label>
        <label>Prix : <input type="number" name="lock_coins" min="0" step="1" value="<?= (int)$rel['lock_coins'] ?>"> pièces <span class="muted">(0 = gratuit)</span></label>
      </fieldset>
      <button>Confirmer</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>

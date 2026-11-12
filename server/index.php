<?php
/**
 * NounoursLauncher — page publique de téléchargement
 * https://nounourscorp.fr/launcher/
 *
 * Utilise le design commun du réseau (shared/ui.php + header/footer) quand il est disponible,
 * sinon une mise en page autonome.
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/catalog.php';
while (ob_get_level() > 0) { ob_end_clean(); }

$cfg = nl_config();
$slug = (string)$cfg['launcher_slug'];
$github = (string)($cfg['github_url'] ?? '');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

try {
    nl_ensure_schema();
    $projects = nl_load(0, $slug);
    $project = $projects ? reset($projects) : null;
} catch (Throwable) {
    $project = null;
}

// Plateformes proposées, dans l'ordre d'affichage
$targets = [
    'windows-x86_64' => ['Windows', 'fab fa-windows', 'Windows 10 et 11 (64 bits)'],
    'darwin-aarch64' => ['macOS', 'fab fa-apple', 'Mac Apple Silicon (M1 et plus)'],
    'darwin-x86_64'  => ['macOS Intel', 'fab fa-apple', 'Mac avec processeur Intel'],
    'linux-x86_64'   => ['Linux', 'fab fa-linux', 'AppImage, .deb ou .rpm'],
    'android'        => ['Android', 'fab fa-android', 'Téléphones et tablettes Android'],
    'ios'            => ['iPhone', 'fab fa-apple', 'iPhone et iPad'],
];
$kindLabels = ['installer' => 'Installeur', 'msi' => 'MSI', 'dmg' => 'Image disque', 'appimage' => 'AppImage',
               'deb' => '.deb', 'rpm' => '.rpm', 'apk' => 'APK', 'zip' => 'Zip', 'portable' => 'Portable', 'store' => 'Store'];

$downloads = [];
$latest = null;
if ($project) {
    foreach (array_keys($targets) as $pf) {
        $files = [];
        foreach (nl_download_kinds($pf) as $k) {
            $l = nl_latest_for($project, $pf, 'stable', $k);
            // autres formats proposés seulement s'ils sont de la même version que le principal
            if ($l && (!$files || nl_vcmp($l['release']['version'], $files[0]['version']) === 0)) {
                $files[] = ['kind' => $k, 'version' => $l['release']['version'], 'size' => $l['asset']['size'], 'release' => $l['release']];
            }
        }
        if ($files) {
            $downloads[$pf] = $files;
            if (!$latest || nl_vcmp($files[0]['version'], $latest['version']) > 0) { $latest = $files[0]['release']; }
        }
    }
}
$dl = fn(string $pf, ?string $kind = null) => 'api.php?r=download&platform=' . rawurlencode($pf) . ($kind ? '&kind=' . rawurlencode($kind) : '');
$size = function ($n): string {
    if (!$n) { return ''; }
    $u = ['o', 'Ko', 'Mo', 'Go']; $i = 0;
    while ($n >= 1024 && $i < 3) { $n /= 1024; $i++; }
    return number_format($n, $i ? 1 : 0, ',', ' ') . ' ' . $u[$i];
};
/** Notes de version : liste à puces et texte simple, tout échappé */
$notes = function (?string $md) use ($h): string {
    $out = ''; $inList = false;
    foreach (preg_split('/\R/', (string)$md) as $line) {
        $t = trim($line);
        if (preg_match('/^[-*+]\s+(.*)$/', $t, $m)) {
            if (!$inList) { $out .= '<ul>'; $inList = true; }
            $out .= '<li>' . $h($m[1]) . '</li>';
        } else {
            if ($inList) { $out .= '</ul>'; $inList = false; }
            if ($t !== '') { $out .= '<p>' . $h(ltrim($t, '# ')) . '</p>'; }
        }
    }
    return $out . ($inList ? '</ul>' : '');
};
$unavailable = isset($_GET['indisponible']) ? (string)$_GET['indisponible'] : null;

$sharedDir = dirname((string)($cfg['shared_auth'] ?? '/home/didikaz/shared/auth.php'));
$sharedUi = $sharedDir . '/ui.php';
$useShared = is_file($sharedUi) && nl_na_load();
if ($useShared) {
    require_once $sharedUi;
    $SITE = nui_site('nounourscorp', ['nav' => [
        ['href' => '/',          'icon' => 'fas fa-house',    'label' => 'Accueil'],
        ['href' => '/#produits', 'icon' => 'fas fa-grip',     'label' => 'Produits'],
        ['href' => '/launcher/', 'icon' => 'fas fa-download', 'label' => 'Launcher', 'active' => true],
        ['href' => '/communaute/', 'icon' => 'fas fa-comments', 'label' => 'Communauté'],
    ]]);
}
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Télécharger NounoursLauncher — NounoursCorp</title>
<meta name="description" content="NounoursLauncher : tous les sites, applis et services NounoursCorp au même endroit, avec mises à jour automatiques. Windows, macOS, Linux.">
<meta property="og:title" content="NounoursLauncher">
<meta property="og:description" content="Tous les projets NounoursCorp au même endroit, avec mises à jour automatiques.">
<meta property="og:image" content="<?= $h(rtrim((string)$cfg['base_url'], '/')) ?>/logo.png">
<link rel="icon" href="logo.png">
<?php if ($useShared) { nui_head($SITE); } else { ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
body{margin:0;font:16px/1.55 system-ui,"Segoe UI",sans-serif;background:#0d1626;color:#e8eef8}
.wrap{max-width:1100px;margin:0 auto;padding:24px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:12px;background:#ff9a1f;color:#241200;font-weight:600;text-decoration:none}
.btn-ghost{background:transparent;color:inherit;border:1px solid #ffffff33}
.card{background:#ffffff0d;border:1px solid #ffffff1f;border-radius:16px;padding:20px}
.muted,.small{color:#93a2ba}.small{font-size:.88rem}
.badge{font-size:.75rem;padding:2px 8px;border-radius:999px;background:#ffffff1a}
.row{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
</style>
<?php } ?>
<style>
.lhero{display:grid;grid-template-columns:auto 1fr;gap:28px;align-items:center;padding:36px;border-radius:24px;margin-bottom:24px;color:#fff;
  background:radial-gradient(600px 300px at 10% 0%,#ffb44d55,transparent 60%),radial-gradient(700px 400px at 100% 100%,#2a7fd966,transparent 60%),linear-gradient(135deg,#1b2a4a,#0e1830)}
.lhero img{width:132px;height:132px;border-radius:30px;box-shadow:0 18px 40px #0008}
.lhero h1{margin:0 0 6px;font-size:2.3rem;line-height:1.1}
.lhero p{margin:0 0 18px;opacity:.88;max-width:620px}
.lhero .eyebrow{color:#ffd29a !important}
.big-dl{font-size:1.08rem;padding:14px 24px !important}
.big-dl small{display:block;font-weight:400;opacity:.8;font-size:.78rem}
.plats{display:grid;gap:14px;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));margin:10px 0 26px}
.plat{display:flex;flex-direction:column;gap:10px;padding:18px;border-radius:16px;border:1px solid var(--line,#ffffff1f);background:var(--card,#ffffff0d)}
.plat.me{border-color:var(--acc,#ff9a1f);box-shadow:0 0 0 3px var(--acc-soft,#ff9a1f33)}
.plat .ph{display:flex;align-items:center;gap:10px}
.plat .ph i{font-size:1.6rem;width:30px;text-align:center;color:var(--acc,#ff9a1f)}
.plat .links{display:flex;flex-wrap:wrap;gap:6px;margin-top:auto}
.plat.off{opacity:.55}
.feats{display:grid;gap:14px;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));margin-bottom:26px}
.feat{padding:16px;border-radius:16px;background:var(--card,#ffffff0d);border:1px solid var(--line,#ffffff1f)}
.feat .e{font-size:1.5rem}
.notes ul{margin:6px 0;padding-left:20px}
.tip{border-left:3px solid var(--acc,#ff9a1f);padding:8px 14px;margin:8px 0;background:var(--acc-soft,#ff9a1f14);border-radius:0 10px 10px 0}
@media(max-width:700px){.lhero{grid-template-columns:1fr;text-align:center;padding:26px}.lhero img{margin:0 auto;width:100px;height:100px}.lhero .row{justify-content:center}}
</style>
</head>
<body class="nui">
<?php if ($useShared) { require $sharedDir . '/header.php'; } ?>
<main class="page"><div class="wrap">

  <section class="lhero">
    <img src="logo.png" alt="Logo NounoursLauncher">
    <div>
      <div class="eyebrow">🐻 NounoursCorp</div>
      <h1>NounoursLauncher</h1>
      <p>Tous les sites, applis et services NounoursCorp au même endroit. Installe, lance et mets à jour tes applis en un clic — le launcher se met à jour tout seul.</p>
      <div class="row">
        <?php if ($downloads): ?>
          <a class="btn btn-lg big-dl" id="mainDl" href="<?= $h($dl('auto')) ?>">
            <i class="fas fa-download"></i><span><span id="mainLabel">Télécharger</span><small id="mainSub">v<?= $h($latest['version'] ?? '') ?></small></span>
          </a>
        <?php else: ?>
          <span class="btn btn-lg big-dl" style="opacity:.75;cursor:default"><i class="fas fa-hourglass-half"></i>Bientôt disponible</span>
        <?php endif; ?>
        <?php if ($github): ?>
          <a class="btn btn-lg btn-ghost" href="<?= $h($github) ?>/releases/latest" target="_blank" rel="noopener"><i class="fab fa-github"></i>Sur GitHub</a>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <?php if ($unavailable !== null): ?>
    <div class="tip">Aucune version n'est encore disponible pour ton appareil<?= $unavailable !== 'inconnu' ? ' (' . $h($targets[$unavailable][0] ?? $unavailable) . ')' : '' ?>. Choisis une autre plateforme ci-dessous ou reviens bientôt !</div>
  <?php endif; ?>

  <h2 id="telecharger" style="scroll-margin-top:80px">Choisis ta plateforme</h2>
  <div class="plats">
    <?php foreach ($targets as $pf => [$name, $icon, $desc]): $files = $downloads[$pf] ?? []; ?>
      <div class="plat <?= $files ? '' : 'off' ?>" data-pf="<?= $h($pf) ?>">
        <div class="ph"><i class="<?= $h($icon) ?>"></i><div><b><?= $h($name) ?></b><div class="small muted"><?= $h($desc) ?></div></div></div>
        <?php if ($files): ?>
          <div class="small muted">Version <?= $h($files[0]['version']) ?><?= $files[0]['size'] ? ' · ' . $h($size($files[0]['size'])) : '' ?></div>
          <div class="links">
            <?php foreach ($files as $i => $f): ?>
              <a class="btn <?= $i ? 'btn-ghost' : '' ?> btn-sm" href="<?= $h($dl($pf, $f['kind'])) ?>"><i class="fas fa-download"></i><?= $h($kindLabels[$f['kind']] ?? $f['kind']) ?></a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="small muted"><?= $pf === 'ios' ? 'En préparation (App Store / TestFlight).' : 'Bientôt disponible.' ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="feats">
    <div class="feat"><div class="e">🗂️</div><b>Tout l'écosystème</b><p class="small muted">Sites, applis, plugins et services NounoursCorp, rangés et cherchables.</p></div>
    <div class="feat"><div class="e">⚡</div><b>Installer en un clic</b><p class="small muted">Chaque fichier est vérifié avant installation, puis se lance d'un clic.</p></div>
    <div class="feat"><div class="e">🔄</div><b>Toujours à jour</b><p class="small muted">Le launcher et tes applis se mettent à jour tout seuls (canal stable ou bêta).</p></div>
    <div class="feat"><div class="e">🟢</div><b>État des services</b><p class="small muted">Vois d'un coup d'œil ce qui est en ligne, et qui joue sur le serveur Minecraft.</p></div>
  </div>

  <div class="card" style="margin-bottom:22px">
    <h2 style="margin-top:0"><i class="fas fa-circle-info" style="color:var(--acc,#ff9a1f)"></i> Première installation</h2>
    <div class="tip"><b>Windows :</b> si « Windows a protégé votre ordinateur » s'affiche, clique sur <i>Informations complémentaires</i> puis <i>Exécuter quand même</i>. C'est normal tant que le launcher n'a pas de certificat payant ; les mises à jour suivantes n'afficheront plus rien.</div>
    <div class="tip"><b>macOS :</b> au premier lancement, fais un clic droit sur NounoursLauncher → <i>Ouvrir</i>, puis confirme.</div>
    <div class="tip"><b>Linux :</b> rends l'AppImage exécutable (<code>chmod +x</code>) ou installe le paquet .deb / .rpm.</div>
  </div>

  <?php if ($latest && trim((string)$latest['notes']) !== ''): ?>
    <div class="card notes">
      <h2 style="margin-top:0">Nouveautés de la version <?= $h($latest['version']) ?></h2>
      <p class="small muted">Publiée le <?= $h(date('d/m/Y', strtotime($latest['pub_date'] . ' UTC'))) ?><?php if ($github): ?> · <a href="<?= $h($github) ?>/releases" target="_blank" rel="noopener">toutes les versions</a><?php endif; ?></p>
      <?= $notes($latest['notes']) ?>
    </div>
  <?php endif; ?>

</div></main>
<?php if ($useShared) { require $sharedDir . '/footer.php'; } ?>
<script>
// Met en avant la bonne plateforme et précise le gros bouton
(async () => {
  const ua = navigator.userAgent;
  let pf = /Android/i.test(ua) ? 'android'
    : /iPhone|iPad|iPod/i.test(ua) ? 'ios'
    : /Windows/i.test(ua) ? 'windows-x86_64'
    : /Macintosh|Mac OS X/i.test(ua) ? 'darwin-aarch64'
    : /Linux|X11|CrOS/i.test(ua) ? 'linux-x86_64' : null;
  try { // Chrome/Edge sur Mac : distinguer Intel et Apple Silicon
    if (pf === 'darwin-aarch64' && navigator.userAgentData?.getHighEntropyValues) {
      const v = await navigator.userAgentData.getHighEntropyValues(['architecture']);
      if (v.architecture === 'x86') pf = 'darwin-x86_64';
    }
  } catch (e) {}
  const card = pf && document.querySelector(`.plat[data-pf="${pf}"]`);
  if (!card) return;
  card.classList.add('me');
  const main = document.getElementById('mainDl');
  const first = card.querySelector('.links a');
  if (main && first) {
    main.href = first.href;
    document.getElementById('mainLabel').textContent = 'Télécharger pour ' + card.querySelector('b').textContent;
  } else if (main) {
    main.href = '#telecharger';
    document.getElementById('mainLabel').textContent = 'Voir les plateformes';
  }
})();
</script>
</body>
</html>

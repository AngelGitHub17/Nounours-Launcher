<?php
/**
 * NounoursLauncher — lecture du catalogue et résolution des versions
 */
declare(strict_types=1);

function nl_norm_version(string $v): string
{
    return ltrim(trim($v), 'vV');
}

/** Compare deux versions semver (1.2.0 < 1.10.0, 2.0.0-beta.1 < 2.0.0) */
function nl_vcmp(string $a, string $b): int
{
    $a = nl_norm_version($a);
    $b = nl_norm_version($b);
    [$coreA, $preA] = array_pad(explode('-', $a, 2), 2, null);
    [$coreB, $preB] = array_pad(explode('-', $b, 2), 2, null);
    $c = version_compare($coreA, $coreB);
    if ($c !== 0) { return $c; }
    if ($preA === null && $preB === null) { return 0; }
    if ($preA === null) { return 1; }   // version finale > pré-version
    if ($preB === null) { return -1; }
    // Comparaison des identifiants de pré-version (beta.2 < beta.10, alpha < beta < rc)
    $pa = explode('.', $preA);
    $pb = explode('.', $preB);
    for ($i = 0, $n = max(count($pa), count($pb)); $i < $n; $i++) {
        if (!isset($pa[$i])) { return -1; }
        if (!isset($pb[$i])) { return 1; }
        $x = $pa[$i]; $y = $pb[$i];
        if (ctype_digit($x) && ctype_digit($y)) { $r = (int)$x <=> (int)$y; }
        elseif (ctype_digit($x)) { $r = -1; }
        elseif (ctype_digit($y)) { $r = 1; }
        else { $r = strcmp($x, $y); }
        if ($r !== 0) { return $r; }
    }
    return 0;
}

function nl_valid_version(string $v): bool
{
    return (bool)preg_match('/^v?\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', trim($v));
}

/** Canaux visibles selon le canal demandé : beta voit aussi stable */
function nl_channels_for(string $channel): array
{
    return $channel === 'beta' ? ['stable', 'beta'] : ['stable'];
}

function nl_visibilities_for(int $level): array
{
    return array_slice(NL_VISIBILITIES, 0, $level + 1);
}

/**
 * Charge projets + versions publiées + fichiers, visibles pour le niveau donné.
 * @return array<int, array> projets indexés par id, avec ['releases'] triées décroissantes
 */
function nl_load(int $level, ?string $slug = null, bool $includeUnpublished = false): array
{
    $db = nl_db();
    $vis = nl_visibilities_for($level);
    $ph = implode(',', array_fill(0, count($vis), '?'));
    $sql = "SELECT * FROM nl_projects WHERE visibility IN ($ph)";
    $params = $vis;
    if ($slug !== null) { $sql .= ' AND slug = ?'; $params[] = $slug; }
    $sql .= ' ORDER BY sort_order, name';
    $st = $db->prepare($sql);
    $st->execute($params);

    $projects = [];
    foreach ($st->fetchAll() as $p) {
        $p['releases'] = [];
        $projects[(int)$p['id']] = $p;
    }
    if (!$projects) { return []; }

    $ids = array_keys($projects);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rq = "SELECT * FROM nl_releases WHERE project_id IN ($ph)" . ($includeUnpublished ? '' : ' AND published = 1');
    $st = $db->prepare($rq);
    $st->execute($ids);
    $releases = [];
    foreach ($st->fetchAll() as $r) {
        $r['assets'] = [];
        $releases[(int)$r['id']] = $r;
    }

    if ($releases) {
        $rids = array_keys($releases);
        $ph = implode(',', array_fill(0, count($rids), '?'));
        $st = $db->prepare("SELECT * FROM nl_assets WHERE release_id IN ($ph) ORDER BY platform, kind");
        $st->execute($rids);
        foreach ($st->fetchAll() as $a) {
            $releases[(int)$a['release_id']]['assets'][] = $a;
        }
    }

    foreach ($releases as $r) {
        $projects[(int)$r['project_id']]['releases'][] = $r;
    }
    foreach ($projects as &$p) {
        usort($p['releases'], fn($a, $b) => nl_vcmp($b['version'], $a['version']));
        nl_inherit_hints($p);
    }
    unset($p);
    return $projects;
}

/**
 * Une nouvelle version publiée par la CI n'a ni arguments d'installation ni chemin de lancement :
 * on reprend ceux de la version précédente pour la même plateforme et le même type de fichier.
 */
function nl_inherit_hints(array &$p): void
{
    $known = [];
    for ($i = count($p['releases']) - 1; $i >= 0; $i--) { // de la plus ancienne à la plus récente
        foreach ($p['releases'][$i]['assets'] as &$a) {
            $k = $a['platform'] . '|' . $a['kind'];
            foreach (['install_args', 'launch_path'] as $f) {
                if (($a[$f] ?? null) === null || $a[$f] === '') { $a[$f] = $known[$k][$f] ?? null; }
                else { $known[$k][$f] = $a[$f]; }
            }
        }
        unset($a);
    }
}

function nl_fmt_asset(array $a): array
{
    return [
        'platform'     => $a['platform'],
        'kind'         => $a['kind'],
        'url'          => $a['url'],
        'size'         => $a['size'] !== null ? (int)$a['size'] : null,
        'sha256'       => $a['sha256'],
        'signature'    => $a['signature'],
        'install_args' => $a['install_args'],
        'launch_path'  => $a['launch_path'] ?? null,
    ];
}

function nl_fmt_release(array $r, ?string $platform = null): array
{
    $assets = $r['assets'];
    if ($platform !== null) {
        $assets = array_values(array_filter($assets, fn($a) => $a['platform'] === $platform));
    }
    return [
        'version'   => $r['version'],
        'channel'   => $r['channel'],
        'pub_date'  => gmdate('c', strtotime($r['pub_date'] . ' UTC')),
        'notes'     => $r['notes'] ?? '',
        'mandatory' => (bool)$r['mandatory'],
        'assets'    => array_map('nl_fmt_asset', $assets),
    ];
}

/** Dernière version publiée ayant un fichier pour cette plateforme, dans les canaux autorisés */
function nl_latest_for(array $project, string $platform, string $channel, ?string $kind = null): ?array
{
    $channels = nl_channels_for($channel);
    foreach ($project['releases'] as $r) { // déjà triées décroissantes
        if (!(int)$r['published'] || !in_array($r['channel'], $channels, true)) { continue; }
        foreach ($r['assets'] as $a) {
            if ($a['platform'] === $platform && ($kind === null || $a['kind'] === $kind)) {
                return ['release' => $r, 'asset' => $a];
            }
        }
    }
    return null;
}

function nl_project_platforms(array $p): array
{
    $set = [];
    foreach ($p['releases'] as $r) {
        foreach ($r['assets'] as $a) { $set[$a['platform']] = true; }
    }
    if (!empty($p['website_url'])) { $set['web'] = true; }
    return array_values(array_intersect(NL_PLATFORMS, array_keys($set)));
}

function nl_abs_url(?string $u): ?string
{
    if ($u === null || $u === '') { return null; }
    if (preg_match('#^https?://#i', $u)) { return $u; }
    return rtrim((string)nl_config()['base_url'], '/') . '/' . ltrim($u, '/');
}

/** Projet formaté pour le launcher */
function nl_fmt_project(array $p, string $channel, ?string $platform, bool $withHistory = false): array
{
    $platforms = nl_project_platforms($p);
    $latest = [];
    foreach (($platform !== null ? [$platform] : $platforms) as $pf) {
        if ($pf === 'web') { continue; }
        $l = nl_latest_for($p, $pf, $channel);
        if ($l) { $latest[$pf] = nl_fmt_release($l['release'], $pf); }
    }
    $out = [
        'slug'         => $p['slug'],
        'name'         => $p['name'],
        'type'         => $p['type'],
        'short_desc'   => $p['short_desc'],
        'description'  => $p['description'] ?? '',
        'icon'         => nl_abs_url($p['icon_url']),
        'banner'       => nl_abs_url($p['banner_url']),
        'accent'       => $p['accent_color'],
        'website_url'  => $p['website_url'],
        'repo_url'     => $p['repo_url'],
        'status'       => $p['status'],
        'visibility'   => $p['visibility'],
        'has_status'   => !empty($p['status_check_url']),
        'tags'         => array_values(array_filter(array_map('trim', explode(',', (string)$p['tags'])))),
        'sort'         => (int)$p['sort_order'],
        'platforms'    => $platforms,
        'latest'       => (object)$latest,
        'updated_at'   => gmdate('c', strtotime($p['updated_at'] . ' UTC')),
    ];
    if ($withHistory) {
        $channels = nl_channels_for($channel);
        $hist = array_filter($p['releases'], fn($r) => (int)$r['published'] && in_array($r['channel'], $channels, true));
        $out['releases'] = array_map(fn($r) => nl_fmt_release($r, $platform), array_slice(array_values($hist), 0, 20));
    }
    return $out;
}

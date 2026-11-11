<?php
/**
 * NounoursLauncher — vérification de l'état des services
 * Schémas supportés dans status_check_url :
 *   https://... / http://...  → requête HTTP (en ligne si code < 500)
 *   tcp://hote:port           → ouverture de connexion TCP
 *   mc://hote:port            → ping Minecraft (Server List Ping) avec joueurs/version
 * Résultats mis en cache dans cache/status.json (status_ttl secondes).
 */
declare(strict_types=1);

function nl_status_all(int $level): array
{
    $cfg = nl_config();
    $ttl = (int)($cfg['status_ttl'] ?? 60);
    $dir = dirname(__DIR__) . '/cache';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $file = $dir . '/status.json';

    $all = null;
    if (is_file($file) && (time() - (int)filemtime($file)) < $ttl) {
        $all = json_decode((string)file_get_contents($file), true);
    }
    if (!is_array($all)) {
        $fh = @fopen($file . '.lock', 'c');
        $locked = $fh && flock($fh, LOCK_EX | LOCK_NB);
        if (!$locked && is_file($file)) {
            // Un autre appel rafraîchit déjà : on sert l'ancien cache
            $all = json_decode((string)file_get_contents($file), true) ?: [];
        } else {
            $all = nl_status_compute();
            @file_put_contents($file, json_encode($all), LOCK_EX);
        }
        if ($fh) { if ($locked) { flock($fh, LOCK_UN); } fclose($fh); }
    }

    // Filtrer selon la visibilité de l'appelant
    $vis = nl_visibilities_for($level);
    $out = [];
    foreach ($all as $slug => $s) {
        if (in_array($s['_visibility'] ?? 'private', $vis, true)) {
            unset($s['_visibility']);
            $out[$slug] = $s;
        }
    }
    return $out;
}

function nl_status_compute(): array
{
    $rows = nl_db()->query("SELECT slug, visibility, status_check_url FROM nl_projects
                            WHERE status_check_url IS NOT NULL AND status_check_url <> '' AND status <> 'archived'")->fetchAll();
    $results = [];
    $http = [];
    foreach ($rows as $r) {
        $url = $r['status_check_url'];
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if ($scheme === 'http' || $scheme === 'https') {
            $http[$r['slug']] = $r;
            continue;
        }
        $host = (string)parse_url($url, PHP_URL_HOST);
        $port = (int)(parse_url($url, PHP_URL_PORT) ?: ($scheme === 'mc' ? 25565 : 80));
        $res = $scheme === 'mc' ? nl_check_minecraft($host, $port) : nl_check_tcp($host, $port);
        $res['_visibility'] = $r['visibility'];
        $results[$r['slug']] = $res;
    }

    if ($http) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($http as $slug => $r) {
            $ch = curl_init($r['status_check_url']);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY         => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_USERAGENT      => 'NounoursLauncher-Status/1.0',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$slug] = $ch;
        }
        do {
            $s = curl_multi_exec($mh, $running);
            if ($running) { curl_multi_select($mh, 1.0); }
        } while ($running && $s === CURLM_OK);

        foreach ($handles as $slug => $ch) {
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $ms = (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
            $results[$slug] = [
                'up'          => $code > 0 && $code < 500,
                'code'        => $code,
                'ms'          => $code > 0 ? $ms : null,
                'checked_at'  => gmdate('c'),
                '_visibility' => $http[$slug]['visibility'],
            ];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }
    return $results;
}

function nl_check_tcp(string $host, int $port): array
{
    $t = microtime(true);
    $fp = @fsockopen($host, $port, $errno, $errstr, 3.0);
    $ok = (bool)$fp;
    if ($fp) { fclose($fp); }
    return ['up' => $ok, 'ms' => $ok ? (int)round((microtime(true) - $t) * 1000) : null, 'checked_at' => gmdate('c')];
}

// ---------- Ping Minecraft (protocole Server List Ping, 1.7+) ----------

function nl_varint(int $v): string
{
    $v &= 0xFFFFFFFF;
    $out = '';
    do {
        $b = $v & 0x7F;
        $v >>= 7;
        if ($v !== 0) { $b |= 0x80; }
        $out .= chr($b);
    } while ($v !== 0);
    return $out;
}

function nl_read_varint($fp): ?int
{
    $v = 0;
    for ($i = 0; $i < 5; $i++) {
        $c = fread($fp, 1);
        if ($c === false || $c === '') { return null; }
        $b = ord($c);
        $v |= ($b & 0x7F) << (7 * $i);
        if (!($b & 0x80)) { return $v; }
    }
    return null;
}

function nl_check_minecraft(string $host, int $port): array
{
    $base = ['up' => false, 'ms' => null, 'checked_at' => gmdate('c')];
    $t = microtime(true);
    $fp = @fsockopen($host, $port, $errno, $errstr, 3.0);
    if (!$fp) { return $base; }
    stream_set_timeout($fp, 3);

    $data = nl_varint(0x00) . nl_varint(-1) . nl_varint(strlen($host)) . $host . pack('n', $port) . nl_varint(1);
    fwrite($fp, nl_varint(strlen($data)) . $data); // handshake
    fwrite($fp, "\x01\x00");                         // status request

    $len = nl_read_varint($fp);
    $pid = $len !== null ? nl_read_varint($fp) : null;
    $slen = $pid === 0 ? nl_read_varint($fp) : null;
    $json = '';
    if ($slen !== null && $slen > 0 && $slen < 1_000_000) {
        while (strlen($json) < $slen && !feof($fp)) {
            $chunk = fread($fp, $slen - strlen($json));
            if ($chunk === false || $chunk === '') { break; }
            $json .= $chunk;
        }
    }
    fclose($fp);

    $info = json_decode($json, true);
    if (!is_array($info)) {
        // Le port répond mais pas en Minecraft : on le considère joignable
        return ['up' => true, 'ms' => (int)round((microtime(true) - $t) * 1000), 'checked_at' => gmdate('c')];
    }
    $motd = $info['description'] ?? '';
    if (is_array($motd)) {
        $motd = ($motd['text'] ?? '') . implode('', array_map(fn($e) => is_array($e) ? ($e['text'] ?? '') : (string)$e, $motd['extra'] ?? []));
    }
    return [
        'up'         => true,
        'ms'         => (int)round((microtime(true) - $t) * 1000),
        'checked_at' => gmdate('c'),
        'minecraft'  => [
            'version'     => $info['version']['name'] ?? null,
            'online'      => (int)($info['players']['online'] ?? 0),
            'max'         => (int)($info['players']['max'] ?? 0),
            'motd'        => preg_replace('/§./u', '', (string)$motd),
        ],
    ];
}

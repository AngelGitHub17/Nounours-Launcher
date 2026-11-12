<?php
/**
 * NounoursLauncher — tâche planifiée (facultative)
 * OVH → Hébergement → Tâches planifiées : « www4/launcher/cron.php », toutes les heures (ou plus souvent).
 * Sans elle, la surveillance tourne quand même pendant les visites de l'API (au plus toutes les 15 min).
 * Aussi appelable en HTTP : cron.php?key=<cron_key de config.php>
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/catalog.php';
while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: text/plain; charset=utf-8');

if (PHP_SAPI !== 'cli') {
    $key = (string)(nl_config()['cron_key'] ?? '');
    if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) { http_response_code(403); exit("Interdit\n"); }
}
nl_ensure_schema();
$new = nl_watch_tick(true);
echo $new ? 'Nouvelles versions : ' . implode(', ', $new) . "\n" : "Rien de nouveau\n";

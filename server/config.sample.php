<?php
/**
 * NounoursLauncher — configuration
 * Copier ce fichier en config.php puis remplir les valeurs.
 * config.php ne doit JAMAIS être versionné (secrets).
 */
return [
    // --- Base de données (la même que NounoursAccount) ---
    'db' => [
        'host'    => 'didikaz843.mysql.db',
        'name'    => 'didikaz843',
        'user'    => 'didikaz843',
        'pass'    => 'CHANGE_MOI',
        'charset' => 'utf8mb4',
    ],

    // Auth commune du réseau : le panel admin et les projets "membres" utilisent NounoursAccount
    'shared_auth' => '/home/didikaz/shared/auth.php',

    // URL publique du dossier de l'API (sans / final)
    'base_url' => 'https://nounourscorp.fr/launcher',

    // Slug du projet qui représente le launcher lui-même (pour l'auto-update Tauri)
    'launcher_slug' => 'nounours-launcher',

    // Facultatif : empreintes SHA-256 de jetons de secours (le panel se fait avec NounoursAccount,
    // et le jeton CI se génère dans le panel → ⚙ Réglages). Laisser vide en temps normal.
    'admin_token_hashes' => [],
    'ci_token_hashes'    => [],

    // Origines autorisées à appeler les routes admin depuis un autre site (rarement utile)
    'admin_origins' => [],

    // Durée de cache des statuts (secondes)
    'status_ttl' => 60,

    // Affiche le détail des erreurs dans les réponses JSON
    'debug' => false,
];

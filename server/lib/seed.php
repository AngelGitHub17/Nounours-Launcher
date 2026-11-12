<?php
/**
 * NounoursLauncher — projets ajoutés au premier démarrage
 * (INSERT IGNORE : un projet déjà présent, même modifié, n'est jamais écrasé)
 */
declare(strict_types=1);

function nl_seed(PDO $db): void
{
    // slug, nom, type, description courte, lien, vérification d'état, ordre, statut, couleur
    $seed = [
        ['nounours-launcher', 'NounoursLauncher', 'launcher', "Le launcher de tout l'écosystème NounoursCorp", null, null, 0, 'dev', '#e67e00'],
        ['didikaria', 'DidiKaria', 'site', 'Romans, guides de jeux, logiciels et outils.', 'https://didikaria.fr/', 'https://didikaria.fr/', 10, 'online', '#1e8cff'],
        ['nounourscorp', 'NounoursCorp', 'site', 'Le groupe et ses projets.', 'https://nounourscorp.fr/', 'https://nounourscorp.fr/', 20, 'online', '#e67e00'],
        ['nsdkplus', 'NSDKPlus', 'site', 'Serveur Minecraft roleplay NewSky.', 'https://nsdkplus.fr/', 'https://nsdkplus.fr/', 30, 'online', '#9348e6'],
        ['nounourstv', 'NounoursTV', 'site', 'Chaînes en direct, VOD et cinéma.', 'https://ntvbydidikaria.fr/', 'https://ntvbydidikaria.fr/', 40, 'online', '#e11d48'],
        ['nounoursscreeninfo', 'NounoursScreenInfo', 'site', "Écran d'infos : météo, actualités et messages.", 'https://nounoursscreeninfo.fr/', 'https://nounoursscreeninfo.fr/', 50, 'online', '#0ea56e'],
        ['communaute', 'Communauté', 'site', 'Salons, forums et vocal du réseau.', 'https://didikaria.fr/communaute/', null, 60, 'online', '#1e8cff'],
        ['nounoursservicenow', 'NounoursServiceNow', 'app', 'Commandes en salle, notifications push, gestion des stocks.', 'https://didikaria.fr/command/', null, 100, 'online', '#1e8cff'],
        ['nounourscaisse', 'NounoursCaisse', 'app', 'Caisse sur tablette : ventes, sessions et remboursements.', 'https://didikaria.fr/caisse/tablet/', null, 110, 'online', '#1e8cff'],
        ['railboard', 'Railboard', 'app', 'Panneaux départs/arrivées, annonces, lignes et billets.', 'https://didikaria.fr/travels/', null, 120, 'online', '#1e8cff'],
        ['pandorpacks', 'PandorPacks', 'pack', 'Packs de ressources Minecraft pour NewSky (1.16 → 1.21).', 'https://nsdkplus.fr/?page=pandorpacks', null, 200, 'online', '#9348e6'],
    ];
    $ins = $db->prepare('INSERT IGNORE INTO nl_projects
        (slug, name, type, short_desc, website_url, status_check_url, sort_order, status, accent_color)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($seed as $s) { $ins->execute($s); }
}

# NounoursLauncher — API du catalogue

Le catalogue central de NounoursLauncher : il liste tous les projets NounoursCorp (sites, applis, plugins, services) avec leurs versions et leurs fichiers par plateforme. Le launcher (Tauri, à venir) le lit pour se construire tout seul et pour gérer les mises à jour.

PHP 8.3+ / MySQL. Pensé pour OVH mutualisé : routage par `?r=`, uniquement GET et POST, aucun accès shell nécessaire.

## Installation sur OVH

1. Le dossier est dans `www4/launcher/`, ce qui donne l'adresse `https://nounourscorp.fr/launcher/`.
2. `config.php` contient l'accès MySQL (même base `didikaz843`, toutes les tables sont préfixées `nl_`) et le chemin vers `shared/auth.php`.
3. Ouvrir `https://nounourscorp.fr/launcher/admin/`. Si tu n'es pas connecté, tu passes par la page de connexion NounoursAccount habituelle. Le panel est réservé aux comptes **administrateurs** (rôle 2 ou plus).

Il n'y a rien d'autre à installer : les tables se créent seules à la première visite, grâce au fichier témoin `cache/.schema_v1`.

Les dossiers `cache/` et `uploads/` doivent être accessibles en écriture.

**Jeton CI** (pour GitHub Actions) : dans le panel, ⚙ Réglages, puis « Générer un jeton CI ». Il n'est affiché qu'une seule fois.

## Routes

### Publiques (GET)

| Route | Rôle |
|---|---|
| `?r=catalog&platform=windows-x86_64&channel=stable` | Tous les projets visibles, avec la dernière version par plateforme. Gère l'ETag (réponse 304 si rien n'a changé). |
| `?r=project&slug=xxx` | Fiche détaillée d'un projet, avec l'historique des 20 dernières versions. |
| `?r=update/check&slug=xxx&platform=…&current=1.0.0` | Vérifie si une mise à jour existe pour une appli installée. |
| `?r=launcher/update&target={{target}}&arch={{arch}}&current={{current_version}}` | Auto-update du launcher, au format attendu par Tauri : 204 s'il n'y a rien, sinon le JSON de mise à jour. |
| `?r=status` | État des services : HTTP, `tcp://`, `mc://` (joueurs, version, MOTD). Mis en cache 60 s. |
| `?r=meta` | Listes des plateformes, types et statuts reconnus. |

**Canaux.** `channel=beta` renvoie aussi les versions stables : on obtient toujours la version la plus récente des deux.

**Visibilité.** Les projets `private` ne sont visibles qu'avec un jeton admin. Les projets `members` ne sont visibles que si l'appel porte une connexion NounoursAccount : le cookie de session ou le Bearer `na_bearer` envoyé par le launcher.

### Admin (POST)

Deux façons de s'authentifier :
- **depuis le panel** : la session NounoursAccount (cookie), accompagnée de l'en-tête `X-NL-CSRF` ;
- **depuis le launcher** : `Authorization: Bearer <na_bearer>` d'un compte administrateur.

Routes disponibles :

- `admin/projects`
- `admin/project/save`, `admin/project/delete`
- `admin/release/save`, `admin/release/delete`
- `admin/asset/save`, `admin/asset/delete`
- `admin/upload` (envoi d'icônes et de bannières)

### CI (POST + jeton CI)

`ci/release` crée ou met à jour une version et ses fichiers en un seul appel. Voir `ci/publish-release.yml` : copié dans un dépôt GitHub, ce workflow publie automatiquement chaque release GitHub dans le catalogue. Une release marquée « pre-release » part dans le canal bêta.

## Comptes, verrous et sorties programmées

- **Connexion du launcher** : `auth/start` donne un code, l'utilisateur le valide sur `connect.php` (connecté à NounoursAccount), puis `auth/poll` renvoie un jeton `nlu_…` propre à cet appareil.
- **Verrous par version** (panel → Versions) : « Compte requis » et/ou un prix en pièces. Un achat est définitif pour le compte (`nl_purchases`, journal `na_coin_log`). Les admins passent sans payer.
- **Sortie programmée** : la version est visible grisée avec un compte à rebours (`upcoming` dans le catalogue) jusqu'à l'heure prévue (saisie à l'heure de Paris, stockée en UTC).
- **Liens personnels** : un fichier verrouillé, local ou d'un dépôt privé passe par `api.php?r=asset&id=…&u&e&s` (signé, valable jusqu'au lendemain).

## Nouvelles versions détectées

Panel → fiche projet → « Nouvelles versions & accès » :

- **Surveillance** : `Me demander par mail` (la version reste en attente ; le mail propose « Publier maintenant » ou « Programmer la sortie » via `action.php`, lien signé 30 jours) ou `Publier tout de suite`.
- **Sources** : les releases du dépôt GitHub du projet, et/ou un **dossier du serveur** (`files_dir` + son adresse publique `files_url`, ex. `/home/didikaz/www2/PandorPacks` → `https://nsdkplus.fr/PandorPacks`). Noms reconnus : `NomV2.91a-1.21.zip`, ou un sous-dossier par version.
- **Premier passage** : les versions déjà présentes forment le catalogue de départ (publiées sans mail).
- **Fréquence** : au plus toutes les 15 min pendant les visites de l'API ; pour plus de régularité, tâche planifiée OVH sur `launcher/cron.php` (toutes les heures).
- **Destinataires** : `admin_emails` dans `config.php`, sinon les comptes admin NounoursAccount.

Autres sites du réseau : `lib/public.php` fournit `nl_pub_project()` et `nl_pub_handle_unlock()` (utilisé par nsdkplus.fr/?page=pandorpacks).
`extras/PandorPacks.htaccess` bloque l'accès web direct aux .zip une fois les verrous en place.

## Plateformes et types de fichiers

**Plateformes :** `windows-x86_64`, `windows-aarch64`, `darwin-aarch64`, `darwin-x86_64`, `linux-x86_64`, `linux-aarch64`, `android`, `ios`, `web`, `server`, `any` (fichier commun à tous les systèmes).

**Types de fichiers :** `installer`, `updater`, `portable`, `msi`, `apk`, `dmg`, `appimage`, `deb`, `rpm`, `jar`, `zip`, `resourcepack`, `file`, `store`, `web`.

Le type `updater` est réservé aux artefacts signés de Tauri, c'est-à-dire ceux qui ont une signature minisign.

## Sécurité

- Le panel utilise l'auth commune `shared/auth.php` (rôle admin obligatoire) et une protection CSRF.
- Le jeton CI n'est jamais stocké en clair : seulement son empreinte SHA-256, dans `nl_settings`.
- Les uploads n'acceptent que les images PNG, JPEG, WebP et GIF (pas de SVG), et aucun script ne peut s'exécuter dans `uploads/`.
- Les fichiers `config.php`, `lib/` et `cache/` ne sont pas accessibles depuis le web.
- Si OVH supprime l'en-tête `Authorization`, l'en-tête `X-NL-Token` sert de solution de repli.

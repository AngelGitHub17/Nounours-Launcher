# NounoursLauncher

Le launcher de tout l'écosystème NounoursCorp. Il se construit tout seul à partir du catalogue (`https://nounourscorp.fr/launcher/`) et se met à jour automatiquement.

Développé avec **Tauri 2** : une interface en HTML, CSS et JavaScript (dossier `src/`) et un petit cœur en Rust (dossier `src-tauri/`). Un seul code sert pour Windows, macOS et Linux, puis plus tard pour Android et iOS.

## Télécharger

| | |
|---|---|
| 🌐 **Page officielle** | **https://nounourscorp.fr/launcher/** (détecte ton système) |
| ⬇️ **Lien direct** | https://nounourscorp.fr/launcher/api.php?r=download — donne le bon installeur pour l'appareil qui l'ouvre |
| 🐙 **GitHub** | [Dernière version](../../releases/latest) · [Toutes les versions](../../releases) |

Fichiers par système : **Windows** `…_x64-setup.exe` (ou `.msi`) · **macOS** `…_aarch64.dmg` (Apple Silicon) / `…_x64.dmg` (Intel) · **Linux** `….AppImage`, `.deb`, `.rpm`.

> Première installation : sous Windows, *Informations complémentaires → Exécuter quand même* ; sous macOS, clic droit → *Ouvrir*. Ensuite le launcher se met à jour tout seul.

## Ce que fait le launcher

- **Catalogue**
  - les projets sont rangés par type (Sites, Applications, Plugins…) ;
  - recherche avec Ctrl+F, favoris avec ★, actualisation avec F5 ou toutes les 5 minutes ;
  - les projets inutilisables sur ta plateforme sont masqués automatiquement (par exemple une appli uniquement Linux sur Windows).
- **Fiche projet**
  - description, liens « Ouvrir » et « Code source » ;
  - téléchargement du fichier qui correspond à ta plateforme ;
  - notes des dernières versions.
- **État des services** : un voyant vert ou rouge sur chaque carte, et le nombre de joueurs pour le serveur Minecraft (avec `mc://` dans le panel). Rafraîchi toutes les minutes.
- **Hors ligne** : la dernière copie du catalogue reste affichée.
- **Mise à jour automatique du launcher** : vérification au démarrage puis toutes les 6 heures, avec un bandeau « Installer et redémarrer ». On choisit le canal stable ou bêta dans les Réglages.
- **Une seule instance** : relancer le launcher remet simplement la fenêtre au premier plan.

- **Installer, lancer et mettre à jour tes applis**
  - le bouton change selon l'état : *Installer* → *Lancer* → *Mettre à jour* ;
  - chaque téléchargement est vérifié (SHA-256) avant l'installation, avec une barre de progression ;
  - la vue « Mes applis » liste ce qui est installé, et le bouton *Tout mettre à jour* fait tout d'un coup ;
  - une version marquée « obligatoire » est installée avant le lancement ;
  - les applis portables, les AppImage et les installeurs silencieux se mettent à jour tout seuls (réglable).

## Ajouter une appli au launcher

Dans le panel (`nounourscorp.fr/launcher/admin/`) :
1. Crée le projet, ou ouvre-le s'il existe déjà.
2. Ajoute une version.
3. Ajoute un fichier par plateforme. Tes publications GitHub peuvent aussi le faire automatiquement.

| Type de fichier | Ce que fait le launcher | « À lancer » |
|---|---|---|
| `installer` (.exe NSIS / Inno) | lance l'installeur avec les *arguments* (ex. `/S` pour une installation silencieuse) ; si l'installeur demande les droits admin, la fenêtre de Windows s'affiche | chemin de l'exe installé, ex. `%LOCALAPPDATA%\Programs\NounoursCineManager\NounoursCineManager.exe` |
| `msi` | `msiexec /i … /passive` | idem |
| `zip` / `portable` | extrait l'archive dans le dossier du launcher et remplace l'ancienne version | chemin **dans le zip**, ex. `NounoursCineManager.exe` (si tu le laisses vide, le launcher cherche tout seul) |
| `appimage` | copie le fichier et le rend exécutable | — |
| `dmg` | copie le `.app` dans `~/Applications` | — |
| `deb`, `rpm`, `jar`… | ouvre le fichier avec le programme du système | facultatif |

Il suffit de renseigner les *arguments* et le chemin *à lancer* une seule fois : les versions suivantes, même publiées par GitHub, les reprennent automatiquement.

**Pour tes applis WPF**, le plus simple est un **zip portable** : un `dotnet publish` en self-contained, puis un zip du dossier. Les mises à jour sont alors silencieuses et automatiques.

## Installer l'environnement (Windows)

1. **Node.js LTS** : https://nodejs.org
2. **Rust** : https://rustup.rs, en gardant les choix par défaut.
3. **Visual Studio** avec la charge de travail *Développement Desktop en C++*. Tu l'as sans doute déjà, puisque tu développes en WPF.
4. Dans le dossier du projet, ouvrir un terminal :
   ```
   npm install
   npm run tauri dev
   ```
   La première compilation prend quelques minutes, les suivantes quelques secondes. L'interface se recharge toute seule quand tu modifies `src/`.

Sur Mac, il faut en plus Xcode (`xcode-select --install`). Sur la VM Linux, il faut les paquets `libwebkit2gtk-4.1-dev`, `librsvg2-dev` et `patchelf`.

## Clé de signature des mises à jour (à faire une seule fois)

Le launcher refuse d'installer une mise à jour qui n'est pas signée par toi. Cela empêche quelqu'un d'envoyer un faux programme à tes utilisateurs.

```
npm run tauri signer generate -- -w %USERPROFILE%\.tauri\nounours-launcher.key
```

1. Choisis un mot de passe et note-le.
2. Ouvre le fichier `.key.pub` qui vient d'être créé et copie son contenu dans `src-tauri/tauri.conf.json`, à la place de `REMPLACER_PAR_LA_CLE_PUBLIQUE`.
3. **Ne perds jamais la clé privée** (le fichier `.key`) : sans elle, les launchers déjà installés ne pourront plus se mettre à jour. Garde-en une copie en lieu sûr, et ne la mets jamais dans le dépôt Git.

## Publier une version (automatique, via GitHub)

**Préparation, une seule fois** : dans le dépôt GitHub du launcher, ouvre *Settings → Secrets and variables → Actions*.

| Type | Nom | Valeur |
|---|---|---|
| Secret | `TAURI_SIGNING_PRIVATE_KEY` | le contenu du fichier `.key` |
| Secret | `TAURI_SIGNING_PRIVATE_KEY_PASSWORD` | son mot de passe |
| Secret | `NL_CI_TOKEN` | le jeton généré dans le panel (⚙ Réglages → Générer un jeton CI) |
| Variable | `NL_API_URL` | `https://nounourscorp.fr/launcher/api.php` |

**À chaque version :**

```
npm run version:set 0.2.0
git commit -am "v0.2.0"
git tag -a v0.2.0 -m "- Nouveauté 1
- Correction 2"
git push && git push --tags
```

Ensuite, GitHub fait tout le reste :
1. il construit Windows, macOS (Apple Silicon et Intel) et Linux ;
2. il publie la release, avec le message du tag comme notes de version ;
3. il l'inscrit dans le catalogue.

Les launchers installés proposent la mise à jour dans les 6 heures (ou dès le prochain démarrage). Un tag comme `v0.3.0-beta.1` part dans le canal bêta.

## Compiler à la main

```
set TAURI_SIGNING_PRIVATE_KEY=%USERPROFILE%\.tauri\nounours-launcher.key
set TAURI_SIGNING_PRIVATE_KEY_PASSWORD=ton_mot_de_passe
npm run tauri build
```

Les installeurs sont créés dans `src-tauri/target/release/bundle/`.

## À savoir

- **Windows** : sans certificat de signature de code, SmartScreen affiche « Éditeur inconnu » à la première installation. Il faut cliquer sur *Informations complémentaires*, puis *Exécuter quand même*. Les mises à jour suivantes passent sans avertissement.
- **macOS** : sans compte Apple Developer (signature + notarisation), il faut faire clic droit → *Ouvrir* la première fois. La signature Apple pourra être ajoutée au workflow plus tard.
- L'adresse de l'API se change dans *Réglages → Avancé*, par exemple pour tester sur un serveur local.

## Organisation

```
server/              API du catalogue, panel admin et page de téléchargement (www4/launcher/ sur OVH)
src/                 interface (main.js, api.js, styles.css, md.js)
public/              logo
src-tauri/           cœur Rust, configuration, icônes, droits (capabilities/)
scripts/             set-version.mjs, publish-catalog.sh
.github/workflows/   release.yml (build multiplateforme + catalogue)
```

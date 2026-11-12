# Nouveautés de NounoursLauncher

Pour publier une version : `npm run version:set X.Y.Z`, ajoute une section `## X.Y.Z` en haut de cette liste, puis commit et push sur `main`.

## 0.3.1

- Fini les faux « Hors ligne » : le catalogue n'utilise plus les réponses « 304 » que l'hébergement privait de leurs en-têtes ; nouvel essai automatique au moindre raté, puis toutes les 30 secondes
- Comptes admin NounoursAccount : rubrique « Administration » dans le launcher
  - versions en attente : publier maintenant ou programmer la sortie en un clic
  - « Chercher les nouvelles versions »
  - le panel complet s'ouvre dans une fenêtre du launcher, déjà connecté ; bouton « 🛠 Gérer » sur chaque fiche

## 0.3.0

- Compte NounoursAccount dans le launcher (bouton en bas de la barre latérale) : connexion par code, solde de pièces
- Versions verrouillées : réservées aux comptes, ou à débloquer une fois pour toutes avec des pièces
- Sorties programmées : la version apparaît grisée avec un compte à rebours, puis se débloque toute seule à l'heure prévue
- Chaque version de l'historique a son bouton (installer une ancienne version, débloquer, télécharger)
- Nouveau type « Packs de ressources » : « Ajouter à Minecraft » copie le pack dans le dossier resourcepacks du jeu (et retire l'ancienne version)
- Les fichiers relayés par le serveur (dépôts privés, fichiers verrouillés) gardent leur vrai nom à l'installation
- Première version Android : l'APK est disponible sur la page de téléchargement
- Interface pour téléphone : barre d'onglets en bas, cartes sur deux colonnes, fiches en plein écran, encoche et barre gestuelle respectées
- Sur Android, « Installer » télécharge l'APK de l'appli ; le launcher signale ses propres mises à jour
- Le launcher compile aussi pour iPhone (installation depuis un Mac avec Xcode)
- Thème clair : descriptions mieux contrastées

## 0.2.3

- Le launcher se met à jour tout seul :
  - au démarrage, une nouvelle version est installée tout de suite et le launcher redémarre
  - pendant l'utilisation, il vérifie toutes les 30 minutes (et quand on revient sur sa fenêtre), télécharge en arrière-plan puis redémarre après 15 secondes
  - « Plus tard » reporte l'installation au prochain démarrage ; jamais de redémarrage pendant l'installation d'une appli
- Réglages → Mise à jour du launcher : « Automatique » ou « Me demander », avec l'heure de la dernière vérification
- Hors ligne : nouvel essai automatique 5 minutes plus tard

## 0.2.2

- Le launcher trouve tout seul où une appli s'est installée, pour pouvoir la lancer :
  - Windows : il compare « Programmes et fonctionnalités » avant et après l'installeur (.exe, .msi), puis cherche l'exécutable ; à défaut, le raccourci du menu Démarrer
  - macOS : il attend la fin de l'installeur .pkg et repère l'appli apparue dans Applications
  - Linux : il retrouve le raccourci du menu des applications (.deb, .rpm)
- Si une appli a été déplacée ou installée à part, « Lancer » la recherche par son nom et retient l'emplacement trouvé
- Le champ « À lancer » du panel reste prioritaire s'il est rempli

## 0.2.1

- Notes de version bien plus lisibles : listes imbriquées, liens cliquables, une carte par version, la version installée signalée
- Seules les 3 dernières versions sont dépliées ; « Voir les versions précédentes » pour le reste
- Les notes des applis viennent maintenant du CHANGELOG de leur dépôt GitHub (même privé)
- Mac : les installeurs .pkg sont proposés avant les archives .zip

## 0.2.0

- La molette fonctionne partout : seule la liste des projets défile, la barre latérale reste en place
- Bouton « Revenir en haut », barre latérale réductible (« « ») avec info-bulles
- Nouveaux réglages : thème clair / sombre / système, couleur, affichage en cartes, compact ou liste
- Tri (catalogue, nom, dernières mises à jour), page au démarrage, projets en développement / archivés
- Lancement au démarrage de l'ordinateur (réduit) et taille de fenêtre mémorisée
- Applis publiées dans un dépôt GitHub privé : installation via le serveur, sans accès au code

## 0.1.0

- Première version publique de NounoursLauncher
- Catalogue de tous les projets NounoursCorp, avec recherche et favoris
- État des services en direct (et joueurs du serveur Minecraft)
- Installer, lancer et mettre à jour les applis en un clic
- Mise à jour automatique du launcher (canal stable ou bêta)

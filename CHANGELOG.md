# Nouveautés de NounoursLauncher

Pour publier une version : `npm run version:set X.Y.Z`, ajoute une section `## X.Y.Z` en haut de cette liste, puis commit et push sur `main`.

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

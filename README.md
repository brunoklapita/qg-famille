# 🏠 Le QG de la famille — version auto-hébergée

Menus de la semaine, liste de courses, agenda, coin Noël, congélo & placards, carnet de la maison et sorties en famille. Pas besoin de compte : on entre avec un code.

## Ce qu'il faut

- Un hébergement **PHP 7.4 ou plus récent** sous **Apache** (OVH, o2switch, Hostinger…), sans base de données.
- Un sous-domaine, par exemple `qg.klapita.fr`, de préférence en **HTTPS** (certificat Let's Encrypt gratuit chez la plupart des hébergeurs).

## Installation (5 minutes)

1. **Envoie tout le contenu du dossier** par FTP dans le dossier du sous-domaine, **y compris les fichiers cachés** `.htaccess` et `data/.htaccess`.
2. Vérifie que le dossier `data/` est **accessible en écriture** par PHP (droits `755`, ou `775` selon l'hébergeur).
3. Ouvre **`https://ton-sous-domaine/setup.php`** et choisis :
   - le **code parents** (6 caractères min.) : accès à tout ;
   - le **code enfants** (4 caractères min.) : voir les menus, la maison et le stock ; modifier la liste de courses, l'agenda et les sorties. **Le coin Noël leur est invisible**, et le serveur le leur refuse aussi.
4. **Supprime `setup.php`** du serveur.
5. Ouvre le QG, entre ton code. Chaque appareil reste connecté 6 mois (le 🔒 en haut à droite déconnecte).

> Fais l'étape 3 juste après l'envoi : tant que les codes ne sont pas choisis, n'importe qui connaissant l'adresse pourrait les définir.

## Vérifications de sécurité

- `https://ton-sous-domaine/data/config.json` doit répondre **403 Forbidden**. Sinon, ton hébergeur ignore les `.htaccess` (serveur nginx) : il faut alors bloquer `/data/` dans sa configuration, ou déplacer `data/` hors du dossier web et modifier `DATA_DIR` dans `api.php`.
- Pour forcer le HTTPS, décommente les 3 lignes prévues dans `.htaccess`.
- Les codes sont stockés hachés (bcrypt). Après 10 essais ratés, une IP est bloquée 15 minutes.

## Sur les téléphones

Dans Safari ou Chrome : menu **Partager** ou **⋮**, puis **« Sur l'écran d'accueil »**. Le QG s'ouvre alors comme une appli.

## Bon à savoir

- Les données sont de simples fichiers JSON dans `data/`. **Sauvegarde** : copie ce dossier (sans `sessions/`).
- Synchronisation : chaque appareil vérifie les changements toutes les 15 secondes et à chaque retour sur l'appli.
- **Changer les codes** : supprime `data/config.json`, renvoie `setup.php`, recommence l'étape 3. Tous les appareils devront se reconnecter.
- Le zip d'installation contient déjà les menus de la semaine du 23 novembre (calés sur ton planning Cookidoo). Le dépôt GitHub, lui, ne contient aucune donnée.
- Le bouton ✨ « idée de Claude » n'existe pas dans cette version : il fonctionne seulement dans la version claude.ai.

## 🔄 Mises à jour

Le code de l'appli est sur GitHub : <https://github.com/brunoklapita/qg-famille>.
Quand une nouvelle version est publiée, une notification s'affiche au QG pour les parents. Il suffit d'aller dans **✨ Plus → Mises à jour → Installer**.

La mise à jour télécharge la dernière version depuis GitHub et remplace les fichiers de l'appli. **Elle ne touche jamais à `data/`** (tes données et tes codes) **ni à `setup.php`**.
Il faut que l'hébergement autorise les connexions sortantes et que l'extension PHP `zip` soit active : c'est le cas par défaut chez OVH.

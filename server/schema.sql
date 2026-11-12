-- ============================================================
--  NounoursLauncher — Catalogue (schéma MySQL / MariaDB)
--  Tables préfixées nl_ pour cohabiter dans didikaz843
-- ============================================================

CREATE TABLE IF NOT EXISTS nl_projects (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug             VARCHAR(64)  NOT NULL,
  name             VARCHAR(120) NOT NULL,
  type             ENUM('site','app','plugin','pack','service','bot','tool','launcher') NOT NULL DEFAULT 'app',
  short_desc       VARCHAR(255) NOT NULL DEFAULT '',
  description      TEXT NULL,
  icon_url         VARCHAR(500) NULL,
  banner_url       VARCHAR(500) NULL,
  accent_color     VARCHAR(9)   NULL,                 -- ex. #3aa0ff
  website_url      VARCHAR(500) NULL,                 -- lien "Ouvrir" (sites, web apps)
  repo_url         VARCHAR(500) NULL,
  github_repo      VARCHAR(140) NULL,                 -- owner/repo : import des releases (dépôt privé possible)
  files_dir        VARCHAR(255) NULL,                 -- dossier du serveur à surveiller (ex. /home/didikaz/www2/PandorPacks)
  files_url        VARCHAR(255) NULL,                 -- adresse publique de ce dossier (ex. https://nsdkplus.fr/PandorPacks)
  watch_mode       ENUM('off','ask','publish') NOT NULL DEFAULT 'off', -- nouvelles versions : mail + attente / publication directe
  default_lock_login TINYINT(1) NOT NULL DEFAULT 0,   -- verrous appliqués aux versions détectées automatiquement
  default_lock_coins INT UNSIGNED NOT NULL DEFAULT 0,
  status           ENUM('online','beta','dev','archived') NOT NULL DEFAULT 'dev',
  visibility       ENUM('public','members','private') NOT NULL DEFAULT 'public',
  status_check_url VARCHAR(500) NULL,                 -- https://..., tcp://hote:port ou mc://hote:port
  tags             VARCHAR(255) NOT NULL DEFAULT '',  -- séparés par des virgules
  sort_order       INT NOT NULL DEFAULT 100,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nl_projects_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nl_settings (
  k  VARCHAR(64) NOT NULL,
  v  TEXT NULL,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nl_releases (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id   INT UNSIGNED NOT NULL,
  version      VARCHAR(40)  NOT NULL,                 -- semver : 1.4.0, 2.0.0-beta.1
  label        VARCHAR(40)  NULL,                     -- nom affiché si différent (ex. « V2.91a »)
  channel      ENUM('stable','beta') NOT NULL DEFAULT 'stable',
  notes        TEXT NULL,                             -- changelog (Markdown)
  published    TINYINT(1) NOT NULL DEFAULT 0,
  mandatory    TINYINT(1) NOT NULL DEFAULT 0,         -- mise à jour obligatoire
  pub_date     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  available_at DATETIME NULL,                         -- sortie programmée (UTC) : visible grisée avant
  lock_login   TINYINT(1) NOT NULL DEFAULT 0,         -- compte NounoursAccount obligatoire
  lock_coins   INT UNSIGNED NOT NULL DEFAULT 0,       -- prix en pièces (payé une fois par compte)
  source       VARCHAR(12) NULL,                      -- github, folder, ci, manual
  detected_at  DATETIME NULL,                         -- détectée automatiquement (en attente de décision si non publiée)
  notified     TINYINT(1) NOT NULL DEFAULT 0,         -- mail envoyé à l'admin
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nl_releases (project_id, version),
  KEY ix_nl_releases_project (project_id, published),
  CONSTRAINT fk_nl_releases_project FOREIGN KEY (project_id) REFERENCES nl_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nl_assets (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  release_id   INT UNSIGNED NOT NULL,
  platform     VARCHAR(32)  NOT NULL,                 -- windows-x86_64, darwin-aarch64, linux-x86_64, android, ios, web, server
  kind         VARCHAR(16)  NOT NULL DEFAULT 'installer', -- installer, updater, portable, msi, apk, dmg, appimage, deb, rpm, jar, zip, store, web
  url          VARCHAR(700) NOT NULL,
  size         BIGINT UNSIGNED NULL,
  sha256       CHAR(64) NULL,
  signature    TEXT NULL,                             -- signature minisign (updater Tauri) ou autre
  install_args VARCHAR(255) NULL,                     -- ex. /S pour un installeur NSIS silencieux
  launch_path  VARCHAR(500) NULL,                     -- exécutable à lancer après installation (%LOCALAPPDATA%\..., ou chemin relatif dans un zip)
  PRIMARY KEY (id),
  UNIQUE KEY uq_nl_assets (release_id, platform, kind),
  CONSTRAINT fk_nl_assets_release FOREIGN KEY (release_id) REFERENCES nl_releases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Achats en pièces (une fois par compte et par version)
CREATE TABLE IF NOT EXISTS nl_purchases (
  user_id     INT NOT NULL,
  release_id  INT UNSIGNED NOT NULL,
  coins       INT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, release_id),
  KEY ix_nl_purchases_release (release_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Connexions du launcher (jetons longue durée, seule l'empreinte est stockée)
CREATE TABLE IF NOT EXISTS nl_tokens (
  token_hash  CHAR(64) NOT NULL,
  user_id     INT NOT NULL,
  label       VARCHAR(80) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used   DATETIME NULL,
  PRIMARY KEY (token_hash),
  KEY ix_nl_tokens_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Codes de connexion temporaires (le launcher affiche un code, le site le valide)
CREATE TABLE IF NOT EXISTS nl_auth_codes (
  code        CHAR(8) NOT NULL,
  poll_hash   CHAR(64) NOT NULL,
  user_id     INT NULL,
  label       VARCHAR(80) NULL,
  expires_at  DATETIME NOT NULL,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


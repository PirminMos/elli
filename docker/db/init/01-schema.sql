-- =====================================================================
-- elli – Datenbankschema
-- Wird von MariaDB beim ERSTEN Start automatisch ausgeführt
-- (nur wenn das Daten-Volume noch leer ist).
--
-- Erzeugt aus dem `describe`-Abzug der Produktivdatenbank.
-- Fremdschlüssel sind bewusst als einfache Indizes (KEY) abgebildet,
-- damit die Initialisierung unabhängig von der Einfüge-Reihenfolge klappt.
--
-- >> Wenn du eine bestehende DB mit Daten übernehmen willst: ersetze
-- >> diese Datei durch die Ausgabe von
-- >>   mysqldump -u elli_user -p elli > 01-schema.sql
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `schule` (
  `id`        INT(11)      NOT NULL AUTO_INCREMENT,
  `schuljahr` VARCHAR(20)  NOT NULL,
  `adresse`   LONGTEXT     DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `einstellungen` (
  `schluessel` VARCHAR(50)  NOT NULL,
  `wert`       VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`schluessel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `aktivitaet` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `schuljahr_id` INT(11)      NOT NULL,
  `typ`          VARCHAR(100) DEFAULT NULL,
  `name`         VARCHAR(255) DEFAULT NULL,
  `einsatzort`   VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_aktivitaet_schuljahr` (`schuljahr_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `erstkraft` (
  `id`                 INT(11)      NOT NULL AUTO_INCREMENT,
  `schuljahr_id`       INT(11)      NOT NULL,
  `name`               VARCHAR(255) NOT NULL,
  `titel`              VARCHAR(50)  DEFAULT NULL,
  `kuerzel`            VARCHAR(10)  DEFAULT NULL,
  `farbe`              VARCHAR(7)   DEFAULT NULL,
  `pflichtstunden`     INT(11)      DEFAULT 0,
  `ermaessigung`       INT(11)      DEFAULT 0,
  `upz`                INT(11)      DEFAULT 0,
  `faecher`            VARCHAR(255) DEFAULT NULL,
  `textfarbe`          VARCHAR(7)   DEFAULT '#ffffff',
  `ermaessigung_grund` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erstkraft_schuljahr` (`schuljahr_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `zweitkraft` (
  `id`                 INT(11)      NOT NULL AUTO_INCREMENT,
  `schuljahr_id`       INT(11)      NOT NULL,
  `typ`                VARCHAR(100) DEFAULT NULL,
  `name`               VARCHAR(255) NOT NULL,
  `kuerzel`            VARCHAR(10)  DEFAULT NULL,
  `farbe`              VARCHAR(7)   DEFAULT NULL,
  `textfarbe`          VARCHAR(7)   DEFAULT '#ffffff',
  `ermaessigung`       INT(11)      DEFAULT 0,
  `grund_ermaessigung` VARCHAR(255) DEFAULT NULL,
  `upz`                INT(11)      DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_zweitkraft_schuljahr` (`schuljahr_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `klassen` (
  `id`           INT(11)     NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(50) NOT NULL,
  `schuljahr_id` INT(11)     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_klassen_schuljahr` (`schuljahr_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `klassen_zeitraster` (
  `id`            INT(11) NOT NULL AUTO_INCREMENT,
  `klasse_id`     INT(11) NOT NULL,
  `stunden_index` INT(11) NOT NULL,
  `startzeit`     TIME    NOT NULL,
  `endzeit`       TIME    NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_zeitraster_klasse` (`klasse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `schulfach` (
  `id`                INT(11)      NOT NULL AUTO_INCREMENT,
  `schuljahr_id`      INT(11)      NOT NULL,
  `name`              VARCHAR(255) NOT NULL,
  `benoetigte_raeume` TEXT         DEFAULT NULL,
  `farbe`             VARCHAR(7)   DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_schulfach_schuljahr` (`schuljahr_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `raum` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `schuljahr_id`     INT(11)      NOT NULL,
  `name`             VARCHAR(255) NOT NULL,
  `unterrichtsfach`  VARCHAR(255) DEFAULT NULL,
  `immer_verfuegbar` TINYINT(1)   DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_raum_schuljahr` (`schuljahr_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `raum_verfuegbarkeit` (
  `id`        INT(11)     NOT NULL AUTO_INCREMENT,
  `raum_id`   INT(11)     NOT NULL,
  `tag`       VARCHAR(20) NOT NULL,
  `startzeit` TIME        NOT NULL,
  `endzeit`   TIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_raumverf_raum` (`raum_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `stundentafel` (
  `id`            INT(11)                  NOT NULL AUTO_INCREMENT,
  `klasse_id`     INT(11)                  DEFAULT NULL,
  `fach_id`       INT(11)                  DEFAULT NULL,
  `soll_stunden`  DECIMAL(5,2)             NOT NULL,
  `besetzung_typ` ENUM('einzel','doppel')  DEFAULT 'einzel',
  PRIMARY KEY (`id`),
  KEY `idx_stundentafel_klasse` (`klasse_id`),
  KEY `idx_stundentafel_fach` (`fach_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `lehrer_stundentafel` (
  `id`            INT(11)                  NOT NULL AUTO_INCREMENT,
  `erstkraft_id`  INT(11)                  NOT NULL,
  `fach_id`       INT(11)                  DEFAULT NULL,
  `aktivitaet_id` INT(11)                  DEFAULT NULL,
  `soll_stunden`  DECIMAL(5,2)             NOT NULL,
  `besetzung_typ` ENUM('einzel','doppel')  DEFAULT 'einzel',
  PRIMARY KEY (`id`),
  KEY `idx_lehrertafel_erstkraft` (`erstkraft_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `zweitkraft_stundentafel` (
  `id`            INT(11)                  NOT NULL AUTO_INCREMENT,
  `zweitkraft_id` INT(11)                  NOT NULL,
  `aktivitaet_id` INT(11)                  DEFAULT NULL,
  `einsatzort`    VARCHAR(255)             DEFAULT NULL,
  `soll_stunden`  DECIMAL(5,2)             NOT NULL,
  `besetzung_typ` ENUM('einzel','doppel')  DEFAULT 'einzel',
  PRIMARY KEY (`id`),
  KEY `idx_zktafel_zweitkraft` (`zweitkraft_id`),
  KEY `idx_zktafel_aktivitaet` (`aktivitaet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `termin` (
  `id`                 INT(11)     NOT NULL AUTO_INCREMENT,
  `klassen_id`         INT(11)     DEFAULT NULL,
  `aktivitaet_id`      INT(11)     DEFAULT NULL,
  `schulfach_id`       INT(11)     DEFAULT NULL,
  `tag`                VARCHAR(20) DEFAULT NULL,
  `stunden_id`         INT(11)     DEFAULT NULL,
  `start`              TIME        DEFAULT NULL,
  `ende`               TIME        DEFAULT NULL,
  `is_differenzierung` TINYINT(1)  DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_termin_klassen` (`klassen_id`),
  KEY `idx_termin_aktivitaet` (`aktivitaet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `termin_raeume` (
  `termin_id` INT(11) NOT NULL,
  `raum_id`   INT(11) NOT NULL,
  PRIMARY KEY (`termin_id`, `raum_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `termin_verantwortliche` (
  `termin_id` INT(11)               NOT NULL,
  `kraft_id`  INT(11)               NOT NULL,
  `kraft_typ` ENUM('erst','zweit')  NOT NULL,
  PRIMARY KEY (`termin_id`, `kraft_id`, `kraft_typ`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

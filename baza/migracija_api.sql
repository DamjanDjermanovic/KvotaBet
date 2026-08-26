-- =====================================================================
-- MIGRACIJA — dopuna šeme za pomoćni uvoz ponude sa The Odds API-ja
--
-- Pokreće se SAMO nad bazom koja je već napravljena starijim schema.sql.
-- Ako se baza pravi iznova, sve ovo već stoji u baza/schema.sql i ovaj
-- fajl nije potreban.
--
--     /opt/lampp/bin/mysql -u root kladionica < baza/migracija_api.sql
--
-- Skripta ne dira nijedan postojeći podatak: samo dodaje jednu kolonu i
-- jednu tabelu.
-- =====================================================================

USE kladionica;

-- Naziv tima kod API-ja (engleski). NULL za sve zatečene timove - ručni
-- unos ovu kolonu ne traži.
ALTER TABLE tim
    ADD COLUMN api_naziv VARCHAR(80) NULL AFTER grb,
    ADD UNIQUE KEY uq_tim_api_naziv (liga_id, api_naziv);

-- Mapiranje ključeva liga sa API-ja (soccer_epl) na lige u ovoj bazi
CREATE TABLE IF NOT EXISTS api_liga (
    id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    api_kljuc     VARCHAR(60)  NOT NULL,
    liga_id       SMALLINT UNSIGNED NOT NULL,
    naziv_kod_api VARCHAR(120) NULL,
    aktivna       TINYINT(1)   NOT NULL DEFAULT 1,
    datum_unosa   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_liga_kljuc (api_kljuc),
    KEY ix_api_liga_liga (liga_id),
    CONSTRAINT fk_api_liga_liga FOREIGN KEY (liga_id)
        REFERENCES liga (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

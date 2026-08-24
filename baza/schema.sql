-- =====================================================================
-- FIZIČKI MODEL BAZE PODATAKA
-- Projekat: Veb aplikacija za upravljanje kladionicom i procesom klađenja
-- SUBP: MySQL / MariaDB, InnoDB, utf8mb4
-- =====================================================================

DROP DATABASE IF EXISTS kladionica;
CREATE DATABASE kladionica
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE kladionica;


-- ---------------------------------------------------------------------
-- 1. ULOGA — tipovi korisnika sistema
-- ---------------------------------------------------------------------
CREATE TABLE uloga (
    id      TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    naziv   VARCHAR(30)  NOT NULL,
    opis    VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_uloga_naziv (naziv)
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- 2. KORISNIK — svi nalozi, bez obzira na ulogu
-- ---------------------------------------------------------------------
CREATE TABLE korisnik (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uloga_id            TINYINT UNSIGNED NOT NULL,
    ime                 VARCHAR(50)  NOT NULL,
    prezime             VARCHAR(50)  NOT NULL,
    korisnicko_ime      VARCHAR(30)  NOT NULL,
    email               VARCHAR(120) NOT NULL,
    lozinka_hash        VARCHAR(255) NOT NULL,
    datum_rodjenja      DATE         NOT NULL,
    telefon             VARCHAR(20)  NULL,
    -- Virtuelni novac. Nikad se ne menja bez pratećeg zapisa u tabeli transakcija.
    stanje_racuna       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status              ENUM('aktivan','blokiran','na_cekanju') NOT NULL DEFAULT 'aktivan',
    -- Postaje 1 tek kad operater odobri verifikacioni dokument
    verifikovan         TINYINT(1)   NOT NULL DEFAULT 0,
    token_zapamti_me    VARCHAR(64)  NULL,
    datum_registracije  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    poslednja_prijava   DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_korisnik_email (email),
    UNIQUE KEY uq_korisnik_kime (korisnicko_ime),
    KEY ix_korisnik_uloga (uloga_id),
    CONSTRAINT fk_korisnik_uloga FOREIGN KEY (uloga_id)
        REFERENCES uloga (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_korisnik_stanje CHECK (stanje_racuna >= 0)
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- 3. SPORT
-- ---------------------------------------------------------------------
CREATE TABLE sport (
    id      SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    naziv   VARCHAR(50) NOT NULL,
    ikonica VARCHAR(50) NULL,          -- naziv Bootstrap ikonice
    aktivan TINYINT(1)  NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sport_naziv (naziv)
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- 4. LIGA — takmičenje unutar jednog sporta
-- ---------------------------------------------------------------------
CREATE TABLE liga (
    id       SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sport_id SMALLINT UNSIGNED NOT NULL,
    naziv    VARCHAR(80) NOT NULL,
    drzava   VARCHAR(50) NULL,
    PRIMARY KEY (id),
    KEY ix_liga_sport (sport_id),
    CONSTRAINT fk_liga_sport FOREIGN KEY (sport_id)
        REFERENCES sport (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- 5. TIM
-- ---------------------------------------------------------------------
CREATE TABLE tim (
    id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    liga_id SMALLINT UNSIGNED NOT NULL,
    naziv   VARCHAR(80) NOT NULL,
    grb     VARCHAR(255) NULL,          -- putanja do otpremljene slike
    PRIMARY KEY (id),
    KEY ix_tim_liga (liga_id),
    CONSTRAINT fk_tim_liga FOREIGN KEY (liga_id)
        REFERENCES liga (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- 6. DOGADJAJ — jedna utakmica u ponudi
-- ---------------------------------------------------------------------
CREATE TABLE dogadjaj (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    liga_id          SMALLINT UNSIGNED NOT NULL,
    domacin_id       INT UNSIGNED NOT NULL,
    gost_id          INT UNSIGNED NOT NULL,
    vreme_pocetka    DATETIME NOT NULL,
    -- najavljen: prima uplate | u_toku i zavrsen: ne prima | otkazan: tiketi se storniraju
    status           ENUM('najavljen','u_toku','zavrsen','otkazan') NOT NULL DEFAULT 'najavljen',
    golova_domacin   TINYINT UNSIGNED NULL,
    golova_gost      TINYINT UNSIGNED NULL,
    kreirao_id       INT UNSIGNED NULL,   -- operater koji je uneo događaj
    datum_kreiranja  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_dogadjaj_liga (liga_id),
    KEY ix_dogadjaj_vreme (vreme_pocetka),
    KEY ix_dogadjaj_status (status),
    CONSTRAINT fk_dogadjaj_liga FOREIGN KEY (liga_id)
        REFERENCES liga (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_dogadjaj_domacin FOREIGN KEY (domacin_id)
        REFERENCES tim (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_dogadjaj_gost FOREIGN KEY (gost_id)
        REFERENCES tim (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_dogadjaj_kreirao FOREIGN KEY (kreirao_id)
        REFERENCES korisnik (id) ON UPDATE CASCADE ON DELETE SET NULL,
    -- Tim ne može igrati protiv samog sebe
    CONSTRAINT chk_dogadjaj_timovi CHECK (domacin_id <> gost_id)
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- 7. TIP_IGRE — šifarnik mogućih ishoda (1, X, 2, GG, 3+ ...)
-- ---------------------------------------------------------------------
CREATE TABLE tip_igre (
    id       SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sport_id SMALLINT UNSIGNED NOT NULL,
    oznaka   VARCHAR(10) NOT NULL,      -- ono što igrač vidi na dugmetu
    naziv    VARCHAR(80) NOT NULL,
    opis     VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tip_sport_oznaka (sport_id, oznaka),
    CONSTRAINT fk_tip_sport FOREIGN KEY (sport_id)
        REFERENCES sport (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- 8. KVOTA — veza više-prema-više između događaja i tipa igre,
--    sa sopstvenim atributom (vrednost). U konceptualnom modelu ovo je
--    veza sa atributom; u logičkom postaje samostalna tabela.
-- ---------------------------------------------------------------------
CREATE TABLE kvota (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dogadjaj_id  INT UNSIGNED NOT NULL,
    tip_igre_id  SMALLINT UNSIGNED NOT NULL,
    vrednost     DECIMAL(6,2) NOT NULL,
    aktivna      TINYINT(1) NOT NULL DEFAULT 1,
    izmenjena    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_kvota (dogadjaj_id, tip_igre_id),
    KEY ix_kvota_tip (tip_igre_id),
    CONSTRAINT fk_kvota_dogadjaj FOREIGN KEY (dogadjaj_id)
        REFERENCES dogadjaj (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_kvota_tip FOREIGN KEY (tip_igre_id)
        REFERENCES tip_igre (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_kvota_vrednost CHECK (vrednost >= 1.01)
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- 9. TIKET
-- ---------------------------------------------------------------------
CREATE TABLE tiket (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    broj_tiketa          VARCHAR(20) NOT NULL,     -- čitljiva oznaka za igrača
    korisnik_id          INT UNSIGNED NOT NULL,
    ulog                 DECIMAL(10,2) NOT NULL,
    ukupna_kvota         DECIMAL(10,2) NOT NULL,   -- proizvod kvota svih stavki
    potencijalni_dobitak DECIMAL(12,2) NOT NULL,
    isplaceno            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status               ENUM('aktivan','dobitan','gubitan','storniran') NOT NULL DEFAULT 'aktivan',
    datum_uplate         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    datum_obrade         DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tiket_broj (broj_tiketa),
    KEY ix_tiket_korisnik (korisnik_id),
    KEY ix_tiket_status (status),
    CONSTRAINT fk_tiket_korisnik FOREIGN KEY (korisnik_id)
        REFERENCES korisnik (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_tiket_ulog CHECK (ulog > 0)
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- 10. TIKET_STAVKA — druga veza više-prema-više: tiket <-> kvota.
--     kvota_vrednost je snimak kvote u trenutku uplate. Ako operater
--     kasnije promeni kvotu, već uplaćeni tiketi ostaju netaknuti.
-- ---------------------------------------------------------------------
CREATE TABLE tiket_stavka (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tiket_id       BIGINT UNSIGNED NOT NULL,
    kvota_id       INT UNSIGNED NOT NULL,
    kvota_vrednost DECIMAL(6,2) NOT NULL,
    status         ENUM('ceka','pogodjen','promasen','storniran') NOT NULL DEFAULT 'ceka',
    PRIMARY KEY (id),
    UNIQUE KEY uq_stavka (tiket_id, kvota_id),
    KEY ix_stavka_kvota (kvota_id),
    CONSTRAINT fk_stavka_tiket FOREIGN KEY (tiket_id)
        REFERENCES tiket (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_stavka_kvota FOREIGN KEY (kvota_id)
        REFERENCES kvota (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- 11. TRANSAKCIJA — trag svake promene stanja računa
-- ---------------------------------------------------------------------
CREATE TABLE transakcija (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    korisnik_id   INT UNSIGNED NOT NULL,
    tiket_id      BIGINT UNSIGNED NULL,     -- popunjeno za ulog, dobitak i storno
    tip           ENUM('uplata','isplata','ulog','dobitak','storno') NOT NULL,
    iznos         DECIMAL(12,2) NOT NULL,   -- pozitivan broj; smer određuje tip
    stanje_posle  DECIMAL(12,2) NOT NULL,   -- stanje računa nakon ove transakcije
    opis          VARCHAR(255) NULL,
    datum_vreme   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_transakcija_korisnik (korisnik_id),
    KEY ix_transakcija_datum (datum_vreme),
    CONSTRAINT fk_transakcija_korisnik FOREIGN KEY (korisnik_id)
        REFERENCES korisnik (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_transakcija_tiket FOREIGN KEY (tiket_id)
        REFERENCES tiket (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_transakcija_iznos CHECK (iznos > 0)
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- 12. DOKUMENT — otpremljeni verifikacioni dokumenti igrača
-- ---------------------------------------------------------------------
CREATE TABLE dokument (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    korisnik_id       INT UNSIGNED NOT NULL,
    originalni_naziv  VARCHAR(255) NOT NULL,
    naziv_na_serveru  VARCHAR(100) NOT NULL,   -- nasumično ime, sprečava kolizije i pogađanje
    mime_tip          VARCHAR(100) NOT NULL,
    velicina_bajtova  INT UNSIGNED NOT NULL,
    status            ENUM('na_cekanju','odobren','odbijen') NOT NULL DEFAULT 'na_cekanju',
    napomena          VARCHAR(255) NULL,       -- razlog odbijanja
    obradio_id        INT UNSIGNED NULL,
    datum_slanja      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    datum_obrade      DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dokument_naziv (naziv_na_serveru),
    KEY ix_dokument_korisnik (korisnik_id),
    CONSTRAINT fk_dokument_korisnik FOREIGN KEY (korisnik_id)
        REFERENCES korisnik (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_dokument_obradio FOREIGN KEY (obradio_id)
        REFERENCES korisnik (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- 13. PRISTUP_LOG — evidencija pristupa, osnov za statistiku
--     (paralelno se piše i u tekstualni fajl logovi/pristup.log)
-- ---------------------------------------------------------------------
CREATE TABLE pristup_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    korisnik_id INT UNSIGNED NULL,        -- NULL za neprijavljene posetioce
    ip_adresa   VARCHAR(45) NOT NULL,     -- 45 znakova zbog IPv6
    stranica    VARCHAR(255) NOT NULL,
    metod       VARCHAR(10) NOT NULL,
    user_agent  VARCHAR(255) NULL,
    datum_vreme DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_log_korisnik (korisnik_id),
    KEY ix_log_datum (datum_vreme),
    CONSTRAINT fk_log_korisnik FOREIGN KEY (korisnik_id)
        REFERENCES korisnik (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;


-- =====================================================================
-- POČETNI PODACI — uloge i tipovi igre
-- =====================================================================

INSERT INTO uloga (id, naziv, opis) VALUES
(1, 'igrac',     'Registrovani korisnik koji uplaćuje tikete'),
(2, 'operater',  'Upravlja ponudom, kvotama i rezultatima'),
(3, 'admin',     'Puna kontrola nad sistemom');

INSERT INTO sport (id, naziv, ikonica) VALUES
(1, 'Fudbal',    'bi-dribbble'),
(2, 'Košarka',   'bi-dribbble'),
(3, 'Tenis',     'bi-circle');

INSERT INTO tip_igre (sport_id, oznaka, naziv, opis) VALUES
(1, '1',   'Pobeda domaćina',       'Domaćin pobeđuje u regularnom toku'),
(1, 'X',   'Nerešeno',              'Meč se završava nerešeno'),
(1, '2',   'Pobeda gosta',          'Gost pobeđuje u regularnom toku'),
(1, '1X',  'Domaćin ili nerešeno',  'Dupla šansa'),
(1, '12',  'Bez nerešenog',         'Dupla šansa'),
(1, 'X2',  'Gost ili nerešeno',     'Dupla šansa'),
(1, 'GG',  'Oba tima daju gol',     'Oba tima postižu bar jedan gol'),
(1, '0-2', 'Manje od 3 gola',       'Ukupno 0, 1 ili 2 gola'),
(1, '3+',  'Tri ili više golova',   'Ukupno bar 3 gola'),
(2, '1',   'Pobeda domaćina',       'Uključujući produžetke'),
(2, '2',   'Pobeda gosta',          'Uključujući produžetke'),
(3, '1',   'Pobeda prvog igrača',   NULL),
(3, '2',   'Pobeda drugog igrača',  NULL);

-- =====================================================================
-- TEST PODACI
-- Pokreće se posle schema.sql (koji već unosi uloga, sport i tip_igre).
-- Dodaje lige, timove, buduće najavljene događaje i kvote za fudbal,
-- košarku i tenis - dovoljno da se javna ponuda (index.php) ima šta prikazati.
-- =====================================================================

USE kladionica;


-- ---------------------------------------------------------------------
-- LIGE (sport_id: 1=Fudbal, 2=Košarka, 3=Tenis - iz schema.sql)
-- ---------------------------------------------------------------------
INSERT INTO liga (id, sport_id, naziv, drzava) VALUES
(1, 1, 'Premijer liga',       'Engleska'),
(2, 1, 'La Liga',             'Španija'),
(3, 1, 'Superliga Srbije',    'Srbija'),
(4, 2, 'NBA',                 'SAD'),
(5, 2, 'Evroliga',            'Evropa'),
(6, 3, 'ATP Masters',         'Svet');


-- ---------------------------------------------------------------------
-- TIMOVI (za tenis "tim" predstavlja pojedinačnog igrača)
-- ---------------------------------------------------------------------
INSERT INTO tim (id, liga_id, naziv) VALUES
-- Premijer liga
(1, 1, 'Mančester Siti'),
(2, 1, 'Liverpul'),
(3, 1, 'Arsenal'),
(4, 1, 'Čelsi'),
-- La Liga
(5, 2, 'Real Madrid'),
(6, 2, 'Barselona'),
(7, 2, 'Atletiko Madrid'),
(8, 2, 'Sevilja'),
-- Superliga Srbije
(9,  3, 'Crvena zvezda'),
(10, 3, 'Partizan'),
(11, 3, 'Vojvodina'),
(12, 3, 'TSC'),
-- NBA
(13, 4, 'Los Anđeles Lejkers'),
(14, 4, 'Boston Seltiksi'),
(15, 4, 'Golden Stejt Voriorsi'),
(16, 4, 'Majami Hit'),
-- Evroliga
(17, 5, 'Partizan Košarka'),
(18, 5, 'Crvena zvezda Košarka'),
(19, 5, 'Real Madrid Košarka'),
(20, 5, 'Panatinaikos'),
-- ATP Masters (igrači)
(21, 6, 'Novak Đoković'),
(22, 6, 'Karlos Alkaraz'),
(23, 6, 'Danil Medvedev'),
(24, 6, 'Janik Siner');


-- ---------------------------------------------------------------------
-- DOGAĐAJI - budući test događaji i status 'najavljen', da odmah budu vidljivi u ponudi
-- ---------------------------------------------------------------------
INSERT INTO dogadjaj (id, liga_id, domacin_id, gost_id, vreme_pocetka, status) VALUES
-- Fudbal
(1, 1, 1,  2,  '2026-09-10 20:00:00', 'najavljen'),  -- Mančester Siti - Liverpul
(2, 1, 3,  4,  '2026-09-11 18:30:00', 'najavljen'),  -- Arsenal - Čelsi
(3, 2, 5,  6,  '2026-09-12 21:00:00', 'najavljen'),  -- Real Madrid - Barselona
(4, 2, 7,  8,  '2026-09-13 19:00:00', 'najavljen'),  -- Atletiko Madrid - Sevilja
(5, 3, 9,  10, '2026-09-14 19:00:00', 'najavljen'),  -- Crvena zvezda - Partizan
(6, 3, 11, 12, '2026-09-15 17:00:00', 'najavljen'),  -- Vojvodina - TSC
-- Košarka
(7,  4, 13, 14, '2026-09-16 20:00:00', 'najavljen'), -- Lejkers - Seltiksi
(8,  4, 15, 16, '2026-09-17 20:00:00', 'najavljen'), -- Voriorsi - Hit
(9,  5, 17, 18, '2026-09-18 20:00:00', 'najavljen'), -- Partizan - Crvena zvezda (košarka)
(10, 5, 19, 20, '2026-09-19 20:00:00', 'najavljen'), -- Real Madrid - Panatinaikos
-- Tenis
(11, 6, 21, 22, '2026-09-20 15:00:00', 'najavljen'), -- Đoković - Alkaraz
(12, 6, 23, 24, '2026-09-21 15:00:00', 'najavljen'); -- Medvedev - Siner


-- ---------------------------------------------------------------------
-- KVOTE
-- Fudbal - svih devet tipova igre iz šifarnika (schema.sql), na svakom od
-- šest fudbalskih događaja, da javna ponuda ima šta da prikaže iza dugmeta
-- "+6" (index.php prikazuje prva tri tipa u redu, ostatak iza tog dugmeta).
-- tip_igre id: 1='1', 2='X', 3='2', 4='1X', 5='12', 6='X2', 7='GG', 8='0-2', 9='3+'
-- ---------------------------------------------------------------------
INSERT INTO kvota (dogadjaj_id, tip_igre_id, vrednost) VALUES
(1, 1, 1.75), (1, 2, 3.80), (1, 3, 4.20), (1, 4, 1.20), (1, 5, 1.35), (1, 6, 2.05), (1, 7, 1.75), (1, 8, 2.20), (1, 9, 1.65),
(2, 1, 2.10), (2, 2, 3.40), (2, 3, 3.10), (2, 4, 1.30), (2, 5, 1.50), (2, 6, 1.75), (2, 7, 1.85), (2, 8, 2.05), (2, 9, 1.75),
(3, 1, 2.25), (3, 2, 3.50), (3, 3, 2.90), (3, 4, 1.35), (3, 5, 1.45), (3, 6, 1.65), (3, 7, 1.70), (3, 8, 1.95), (3, 9, 1.85),
(4, 1, 1.95), (4, 2, 3.30), (4, 3, 3.70), (4, 4, 1.25), (4, 5, 1.40), (4, 6, 1.95), (4, 7, 1.80), (4, 8, 2.10), (4, 9, 1.70),
(5, 1, 2.40), (5, 2, 3.10), (5, 3, 2.75), (5, 4, 1.40), (5, 5, 1.45), (5, 6, 1.60), (5, 7, 1.75), (5, 8, 1.90), (5, 9, 1.90),
(6, 1, 1.85), (6, 2, 3.60), (6, 3, 4.00), (6, 4, 1.25), (6, 5, 1.35), (6, 6, 2.10), (6, 7, 1.65), (6, 8, 2.30), (6, 9, 1.60);

-- Košarka - tip_igre id 10='1', 11='2' (nema nerešeno)
INSERT INTO kvota (dogadjaj_id, tip_igre_id, vrednost) VALUES
(7,  10, 1.90), (7,  11, 1.85),
(8,  10, 1.60), (8,  11, 2.25),
(9,  10, 1.75), (9,  11, 2.00),
(10, 10, 2.10), (10, 11, 1.70);

-- Tenis - tip_igre id 12='1', 13='2'
INSERT INTO kvota (dogadjaj_id, tip_igre_id, vrednost) VALUES
(11, 12, 1.55), (11, 13, 2.40),
(12, 12, 1.80), (12, 13, 2.00);


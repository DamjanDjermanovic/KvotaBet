-- =====================================================================
-- TEST PODACI ZA FAZU 8 — obrada rezultata i rešavanje tiketa
--
-- Postavlja šest tiketa u poznato stanje, da se ishodi obrade mogu ručno
-- proveriti. Pokreće se POSLE schema.sql i test_podaci.sql:
--
--     /opt/lampp/bin/mysql -u root kladionica < baza/test_rezultati.sql
--
-- PAŽNJA: skripta briše SVE postojeće tikete i transakcije, da bi početno
-- stanje bilo tačno poznato. Ne pokretati je nad podacima koji se čuvaju.
-- =====================================================================

USE kladionica;

-- ---------------------------------------------------------------------
-- Čišćenje: tiketi, stavke i transakcije se brišu, kvote i događaji ostaju
-- ---------------------------------------------------------------------
DELETE FROM transakcija;
DELETE FROM tiket_stavka;
DELETE FROM tiket;

-- Pošto su obrisane sve transakcije, stanje računa svih korisnika mora pasti
-- na nulu. Inače bi nekome ostalo stanje koje nijedan zapis ne objašnjava, a
-- to je kršenje pravila 8 - i admin/transakcije.php bi ga s pravom prijavio
-- kao neusklađeno. Igraču nad kojim se testira stanje se odmah zatim postavlja
-- na 1000.00, uz odgovarajuće transakcije.
UPDATE korisnik SET stanje_racuna = 0.00;

-- ---------------------------------------------------------------------
-- Igrač nad kojim se testira. Lozinka: Test123
-- ---------------------------------------------------------------------
DELETE FROM korisnik WHERE korisnicko_ime = 'igrac_test';

INSERT INTO korisnik (id, uloga_id, ime, prezime, korisnicko_ime, email, lozinka_hash, datum_rodjenja, stanje_racuna, status, verifikovan)
VALUES (
    900, 1, 'Petar', 'Petrović', 'igrac_test', 'igrac_test@primer.rs',
    '$2y$10$trQP1XNF/a8Ya0YoHXrCEuzyiClLlbCzypt1j0lXNdwnrp7zZAZEq',
    '1990-05-15', 1000.00, 'aktivan', 1
);

-- ---------------------------------------------------------------------
-- Događaji koji će se obrađivati.
-- Vreme se pomera u prošlost da bi se u operaterskom panelu prikazali kao
-- "čeka rezultat", a status se vraća na najavljen da obrada uopšte prođe.
--
--   Događaj 1  Mančester Siti — Liverpul     -> unosi se rezultat 2:0
--   Događaj 3  Real Madrid — Barselona       -> unosi se rezultat 1:1
--   Događaj 4  Atletiko Madrid — Sevilja     -> OTKAZUJE se
-- ---------------------------------------------------------------------
UPDATE dogadjaj
SET status = 'najavljen', golova_domacin = NULL, golova_gost = NULL,
    vreme_pocetka = NOW() - INTERVAL 3 HOUR
WHERE id IN (1, 3, 4);

-- ---------------------------------------------------------------------
-- Kvote za ova tri događaja se brišu i unose iznova, sa poznatim vrednostima.
-- Skripta se tako ne oslanja na to da su kvote iz test_podaci.sql još
-- netaknute (operater ih je mogao izmeniti ili obrisati). Brisanje je
-- bezbedno jer su stavke tiketa maločas obrisane, pa nema više nijedne
-- reference na te kvote.
--
-- Tip igre se traži po oznaci, a ne po pretpostavljenom ID-ju - par
-- (sport_id, oznaka) je UNIQUE u šifarniku. Događaji 1, 3 i 4 su fudbalski.
--
-- ID kvote se namerno ne zadaje - otkad test_podaci.sql upisuje svih devet
-- tipova igre po fudbalskom događaju (ne samo 1/X/2), redni brojevi kvota
-- više nisu 1-12 po malom broju događaja, pa bi fiksni ID ovde upao u tuđ
-- opseg. Stavke tiketa ispod zato i same traže kvota_id po (dogadjaj_id,
-- oznaka), umesto po pretpostavljenom broju.
-- ---------------------------------------------------------------------
DELETE FROM kvota WHERE dogadjaj_id IN (1, 3, 4);

INSERT INTO kvota (dogadjaj_id, tip_igre_id, vrednost, aktivna) VALUES
-- Događaj 1: Mančester Siti — Liverpul
(1, (SELECT id FROM tip_igre WHERE sport_id = 1 AND oznaka = '1'), 1.75, 1),
(1, (SELECT id FROM tip_igre WHERE sport_id = 1 AND oznaka = 'X'), 3.80, 1),
(1, (SELECT id FROM tip_igre WHERE sport_id = 1 AND oznaka = '2'), 4.20, 1),
-- Događaj 3: Real Madrid — Barselona
(3, (SELECT id FROM tip_igre WHERE sport_id = 1 AND oznaka = '1'), 2.25, 1),
(3, (SELECT id FROM tip_igre WHERE sport_id = 1 AND oznaka = 'X'), 3.50, 1),
(3, (SELECT id FROM tip_igre WHERE sport_id = 1 AND oznaka = '2'), 2.90, 1),
-- Događaj 4: Atletiko Madrid — Sevilja
(4, (SELECT id FROM tip_igre WHERE sport_id = 1 AND oznaka = '1'), 1.95, 1),
(4, (SELECT id FROM tip_igre WHERE sport_id = 1 AND oznaka = 'X'), 3.30, 1),
(4, (SELECT id FROM tip_igre WHERE sport_id = 1 AND oznaka = '2'), 3.70, 1);


-- ---------------------------------------------------------------------
-- TIKETI
-- ---------------------------------------------------------------------
INSERT INTO tiket (id, broj_tiketa, korisnik_id, ulog, ukupna_kvota, potencijalni_dobitak, status, datum_uplate) VALUES
-- 1 par, biće pogođen
(901, 'T-TEST-01', 900, 100.00, 1.75, 175.00, 'aktivan', NOW() - INTERVAL 2 DAY),
-- 1 par, biće promašen
(902, 'T-TEST-02', 900, 100.00, 3.80, 380.00, 'aktivan', NOW() - INTERVAL 2 DAY),
-- 2 para na dva različita događaja, oba će biti pogođena
(903, 'T-TEST-03', 900, 100.00, 6.13, 613.00, 'aktivan', NOW() - INTERVAL 2 DAY),
-- 1 par na događaju koji se otkazuje
(904, 'T-TEST-04', 900, 200.00, 1.95, 390.00, 'aktivan', NOW() - INTERVAL 2 DAY),
-- 2 para: jedan pogođen, drugi na otkazanom događaju
(905, 'T-TEST-05', 900, 100.00, 3.41, 341.00, 'aktivan', NOW() - INTERVAL 2 DAY),
-- 2 para: jedan promašen, drugi pogođen
(906, 'T-TEST-06', 900, 100.00, 3.94, 394.00, 'aktivan', NOW() - INTERVAL 2 DAY);

-- Kvota po (dogadjaj_id, oznaka) - vidi napomenu uz INSERT INTO kvota iznad
INSERT INTO tiket_stavka (tiket_id, kvota_id, kvota_vrednost, status) VALUES
(901, (SELECT id FROM kvota WHERE dogadjaj_id = 1 AND tip_igre_id = (SELECT id FROM tip_igre WHERE sport_id = 1 AND oznaka = '1')), 1.75, 'ceka'),
(902, (SELECT id FROM kvota WHERE dogadjaj_id = 1 AND tip_igre_id = (SELECT id FROM tip_igre WHERE sport_id = 1 AND oznaka = 'X')), 3.80, 'ceka'),
(903, (SELECT id FROM kvota WHERE dogadjaj_id = 1 AND tip_igre_id = (SELECT id FROM tip_igre WHERE sport_id = 1 AND oznaka = '1')), 1.75, 'ceka'),
(903, (SELECT id FROM kvota WHERE dogadjaj_id = 3 AND tip_igre_id = (SELECT id FROM tip_igre WHERE sport_id = 1 AND oznaka = 'X')), 3.50, 'ceka'),
(904, (SELECT id FROM kvota WHERE dogadjaj_id = 4 AND tip_igre_id = (SELECT id FROM tip_igre WHERE sport_id = 1 AND oznaka = '1')), 1.95, 'ceka'),
(905, (SELECT id FROM kvota WHERE dogadjaj_id = 1 AND tip_igre_id = (SELECT id FROM tip_igre WHERE sport_id = 1 AND oznaka = '1')), 1.75, 'ceka'),
(905, (SELECT id FROM kvota WHERE dogadjaj_id = 4 AND tip_igre_id = (SELECT id FROM tip_igre WHERE sport_id = 1 AND oznaka = '1')), 1.95, 'ceka'),
(906, (SELECT id FROM kvota WHERE dogadjaj_id = 3 AND tip_igre_id = (SELECT id FROM tip_igre WHERE sport_id = 1 AND oznaka = '1')), 2.25, 'ceka'),
(906, (SELECT id FROM kvota WHERE dogadjaj_id = 1 AND tip_igre_id = (SELECT id FROM tip_igre WHERE sport_id = 1 AND oznaka = '1')), 1.75, 'ceka');

-- ---------------------------------------------------------------------
-- Istorija transakcija koja odgovara gornjim tiketima, da stanje računa ne
-- bi "visilo u vazduhu" (poslovno pravilo 8: svaka promena stanja ima trag).
-- Uplata 1700, pa šest uloga -> preostalo stanje 1000.00
-- ---------------------------------------------------------------------
INSERT INTO transakcija (korisnik_id, tiket_id, tip, iznos, stanje_posle, opis, datum_vreme) VALUES
(900, NULL, 'uplata', 1700.00, 1700.00, 'Uplata virtuelnih sredstava na račun', NOW() - INTERVAL 3 DAY),
(900, 901,  'ulog',    100.00, 1600.00, 'Ulog za tiket T-TEST-01', NOW() - INTERVAL 2 DAY),
(900, 902,  'ulog',    100.00, 1500.00, 'Ulog za tiket T-TEST-02', NOW() - INTERVAL 2 DAY),
(900, 903,  'ulog',    100.00, 1400.00, 'Ulog za tiket T-TEST-03', NOW() - INTERVAL 2 DAY),
(900, 904,  'ulog',    200.00, 1200.00, 'Ulog za tiket T-TEST-04', NOW() - INTERVAL 2 DAY),
(900, 905,  'ulog',    100.00, 1100.00, 'Ulog za tiket T-TEST-05', NOW() - INTERVAL 2 DAY),
(900, 906,  'ulog',    100.00, 1000.00, 'Ulog za tiket T-TEST-06', NOW() - INTERVAL 2 DAY);


-- =====================================================================
-- OČEKIVANI ISHODI
--
-- Redosled obrade u operater/rezultati.php:
--   1) Događaj 1 (Mančester Siti — Liverpul):  rezultat 2 : 0
--   2) Događaj 3 (Real Madrid — Barselona):    rezultat 1 : 1
--   3) Događaj 4 (Atletiko Madrid — Sevilja):  Otkaži
--
-- Posle sva tri koraka očekuje se:
--
--  Tiket       Stavke                          Status      Isplaćeno
--  ---------------------------------------------------------------------
--  T-TEST-01   1 pogođena                      dobitan       175.00
--  T-TEST-02   1 promašena                     gubitan         0.00
--  T-TEST-03   2 pogođene (1.75 x 3.50=6.13)   dobitan       613.00
--  T-TEST-04   1 stornirana                    storniran     200.00  (vraćen ulog)
--  T-TEST-05   1 pogođena + 1 stornirana       dobitan       175.00  (storno = kvota 1.00)
--  T-TEST-06   1 promašena + 1 pogođena        gubitan         0.00
--
--  Ukupno isplaćeno: 175 + 613 + 200 + 175 = 1163.00
--  Stanje računa: 1000.00 + 1163.00 = 2163.00
--
-- Napomena uz T-TEST-05: originalna kvota tiketa je bila 3.41 (1.75 x 1.95),
-- ali pošto je jedan događaj otkazan, ta stavka ulazi kao kvota 1.00, pa je
-- stvarna isplata 100 x 1.75 = 175.00, a ne 341.00. Kolona tiket.ukupna_kvota
-- namerno ostaje 3.41 kao istorijski zapis onoga što je dogovoreno pri uplati.
--
-- Napomena uz T-TEST-06: dovoljna je jedna promašena stavka da tiket bude
-- gubitan, bez obzira što je druga pogođena (poslovno pravilo 7).
--
-- Provera posle obrade:
--
--   SELECT broj_tiketa, status, isplaceno FROM tiket ORDER BY broj_tiketa;
--   SELECT stanje_racuna FROM korisnik WHERE korisnicko_ime = 'igrac_test';
--   SELECT tip, iznos, stanje_posle, opis FROM transakcija
--     WHERE korisnik_id = 900 ORDER BY id;
--
-- Provera idempotentnosti: ponovni pokušaj obrade istog događaja mora biti
-- odbijen porukom "Ovaj događaj je već obrađen", a stanje računa ostati 2163.00.
-- =====================================================================

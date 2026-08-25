<?php
// Konstante korišćene u celom projektu: putanje, vremenska zona, granice
// uloga, trajanje kolačića "zapamti me" i limiti za upload dokumenata.
// Ne sadrži kredencijale baze - oni su isključivo u config/baza.php.

// Osnovna putanja projekta na disku (do foldera kladionica), koristi se
// za sastavljanje putanja do uploada i log fajlova bez obzira odakle se
// skripta pokreće
define('KORENSKA_PUTANJA', dirname(__DIR__));

// Osnovna putanja u URL-u - menja se ako projekat ne stoji direktno u
// htdocs/kladionica
define('KORENSKI_URL', '/kladionica');

// Sve funkcije datuma i vremena (date, strtotime...) računaju po ovoj zoni
date_default_timezone_set('Europe/Belgrade');

// --- Klađenje ---
define('MIN_ULOG', 50.00);
define('MAX_ULOG', 50000.00);

// --- Uplata virtuelnih sredstava na račun ---
define('MIN_UPLATA', 100.00);
define('MAX_UPLATA', 200000.00);

// --- Registracija ---
// Prema pravilima kladionice korisnik mora imati bar ovoliko godina
define('MIN_GODINA_ZA_REGISTRACIJU', 18);

// --- Kolačić "zapamti me" ---
define('TRAJANJE_ZAPAMTI_ME', 60 * 60 * 24 * 30); // 30 dana u sekundama

// --- Upload verifikacionih dokumenata ---
define('PUTANJA_UPLOAD_DOKUMENATA', KORENSKA_PUTANJA . '/uploads/dokumenta/');
define('MAX_VELICINA_UPLOADA', 5 * 1024 * 1024); // 5 MB
define('DOZVOLJENI_MIME_TIPOVI', ['image/jpeg', 'image/png', 'application/pdf']);
define('DOZVOLJENE_EKSTENZIJE', ['jpg', 'jpeg', 'png', 'pdf']);

// --- Tekstualni log fajlovi ---
define('PUTANJA_LOG_PRISTUP', KORENSKA_PUTANJA . '/logovi/pristup.log');
define('PUTANJA_LOG_GRESKE', KORENSKA_PUTANJA . '/logovi/greske.log');

// --- Režim rada ---
// U razvojnom režimu greške se ispisuju na ekran radi lakšeg traženja uzroka.
// Za predaju i svaki stvarni rad mora ostati false: korisniku se nikad ne sme
// prikazati sirova PHP greška, jer odaje putanje na disku, imena tabela i
// strukturu koda. Greške tada idu isključivo u logovi/greske.log.
define('RAZVOJNI_REZIM', false);

// Ova podešavanja se namerno rade u PHP-u, a ne u .htaccess: php_flag radi
// samo uz mod_php, dok ini_set važi u svakom okruženju (mod_php, PHP-FPM, CLI).
ini_set('display_errors', RAZVOJNI_REZIM ? '1' : '0');
ini_set('display_startup_errors', RAZVOJNI_REZIM ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', PUTANJA_LOG_GRESKE);
error_reporting(E_ALL);

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

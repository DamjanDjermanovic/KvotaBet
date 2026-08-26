<?php
// PRIMER podešavanja za The Odds API (https://the-odds-api.com).
//
// Ovaj fajl ide u git i u njemu ključ MORA ostati prazan. Za rad se kopira:
//
//     cp config/api.primer.php config/api.php
//
// pa se u kopiji upiše sopstveni ključ. config/api.php je u .gitignore, pa
// ključ nikad ne završi u repozitorijumu.
//
// Ako config/api.php ne postoji ili je ključ prazan, aplikacija radi normalno -
// samo stranica operater/uvoz.php javlja da uvoz nije podešen i upućuje na
// ručni unos. Nijedna druga stranica ne zavisi od ovog fajla.

require_once __DIR__ . '/konfiguracija.php';

// --- Ključ ---
// Besplatan nalog na the-odds-api.com daje 500 poziva mesečno.
define('ODDS_API_KLJUC', '');

// --- Osnovne konstante ---
// Verzija 4 API-ja; menja se samo ako servis objavi novu.
define('ODDS_API_OSNOVNI_URL', 'https://api.the-odds-api.com/v4');

// Regioni kladionica čije se kvote traže (eu, uk, us, au). Svaki region množi
// cenu poziva, pa se traži samo jedan.
define('ODDS_API_REGIONI', 'eu');

// Tržišta koja se traže: h2h (1/X/2) i totals (manje/više golova).
// Cena poziva = broj regiona x broj tržišta, dakle 1 x 2 = 2 kredita po pozivu.
define('ODDS_API_TRZISTA', 'h2h,totals');

// Linija za tržište totals koja se mapira na tipove 0-2 i 3+.
define('ODDS_API_LINIJA_TOTALS', 2.5);

// Prekid čekanja odgovora u sekundama. Stranica ne sme da visi ako servis
// ne odgovara - posle ovoga se prijavljuje greška i predlaže ručni unos.
define('ODDS_API_TIMEOUT', 8);

// --- Keširanje ---
// Kvota je mala (500 poziva mesečno), pa se svaki odgovor snima na disk i
// ponovo koristi dok je mlađi od ovoga.
define('PUTANJA_KES', KORENSKA_PUTANJA . '/kes/');
define('ODDS_API_TRAJANJE_KESA', 30 * 60);              // 30 minuta za kvote

// Spisak liga (endpoint /sports) se kod ovog servisa ne naplaćuje, ali se
// menja retko, pa se svejedno drži duže.
define('ODDS_API_TRAJANJE_KESA_SPORTOVI', 24 * 60 * 60); // 24 sata

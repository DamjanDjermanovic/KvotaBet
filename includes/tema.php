<?php
// Izbor svetle ili tamne teme.
//
// Tema se pamti u kolačiću, a PHP je čita i upisuje kao atribut na <html>
// PRE nego što pregledač bilo šta iscrta. Bez toga bi se tamna tema videla
// tek posle učitavanja stilova, pa bi svaka strana na trenutak bljesnula belo.
//
// Kolačića može i da nema - tada se ne postavlja nikakav atribut i temu bira
// sistemsko podešavanje, kroz @media (prefers-color-scheme: dark) u style.css.
//
// Ovo je treći slučaj upotrebe kolačića u projektu, uz "zapamti me" na prijavi
// i pamćenje izabrane lige u ponudi.

require_once __DIR__ . '/../config/konfiguracija.php';

define('NAZIV_KOLACICA_TEME', 'tema');
define('TRAJANJE_KOLACICA_TEME', 60 * 60 * 24 * 365); // godinu dana

// Boja za <meta name="theme-color"> - njome pregledač na telefonu boji svoju
// traku iznad stranice. U svetloj temi to je boja navigacije, u tamnoj boja
// same strane, pa se traka u oba slučaja stapa sa vrhom stranice.
define('BOJA_TEME_SVETLA', '#00072d');
define('BOJA_TEME_TAMNA', '#00030f');

// Vraća 'svetla', 'tamna' ili null kad korisnik nije birao (prati se sistem).
// Vrednost iz kolačića je podatak sa klijenta, pa se propuštaju samo dve
// poznate vrednosti - sve ostalo se ponaša kao da kolačića nema.
function izabrana_tema(): ?string
{
    $tema = $_COOKIE[NAZIV_KOLACICA_TEME] ?? '';

    return in_array($tema, ['svetla', 'tamna'], true) ? $tema : null;
}

// Atributi koji idu na <html>. Dva su, jer se boje dele na dva sloja:
//   data-tema     - naši tokeni iz css/style.css
//   data-bs-theme - Bootstrap 5.3 sam prebojava svoje komponente (padajuće
//                   menije, polja, strelicu u <select>), koje mi ne stilizujemo
// Kad kolačića nema, ne ispisuje se ništa i oba sloja rade po sistemskom
// podešavanju - naš kroz @media, a Bootstrap kroz malu skriptu u zaglavlju.
function atributi_teme(): string
{
    $tema = izabrana_tema();

    if ($tema === null) {
        return '';
    }

    return ' data-tema="' . $tema . '" data-bs-theme="' . ($tema === 'tamna' ? 'dark' : 'light') . '"';
}

// Ispisuje <meta name="theme-color">. Kad je tema izabrana, ide jedna oznaka;
// kad nije, idu dve sa media upitom, pa i traku pregledača bira sistem.
function meta_boja_teme(): void
{
    $tema = izabrana_tema();

    if ($tema === 'tamna') {
        echo '<meta name="theme-color" content="' . BOJA_TEME_TAMNA . '">';
        return;
    }

    if ($tema === 'svetla') {
        echo '<meta name="theme-color" content="' . BOJA_TEME_SVETLA . '">';
        return;
    }

    echo '<meta name="theme-color" content="' . BOJA_TEME_SVETLA . '" media="(prefers-color-scheme: light)">' . PHP_EOL
        . '    <meta name="theme-color" content="' . BOJA_TEME_TAMNA . '" media="(prefers-color-scheme: dark)">';
}

// Prihvata izbor i pamti ga u kolačiću. Poziva se iz tema.php.
function zapamti_temu(string $tema): bool
{
    if (!in_array($tema, ['svetla', 'tamna'], true)) {
        return false;
    }

    setcookie(NAZIV_KOLACICA_TEME, $tema, [
        'expires'  => time() + TRAJANJE_KOLACICA_TEME,
        'path'     => KORENSKI_URL . '/',
        'httponly' => false, // tema nije tajna; ovako je vidi i skripta ako zatreba
        'samesite' => 'Lax',
    ]);

    return true;
}

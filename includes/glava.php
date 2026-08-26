<?php
// Zajedničko zaglavlje <head>: naslov, opis, ikonice, boja trake pregledača i
// oznake za deljenje linka (Open Graph).
//
// Postoji zato što četiri stranice imaju sopstveni <html>: zaglavlje.php (sve
// zaštićene i javne strane), prijava.php, registracija.php i greska.php. Bez
// ovog fajla bi se isti spisak oznaka prepisivao četiri puta i razišao se prvom
// izmenom.

// Namerno bez baze i bez sesije: ovaj fajl uključuje i greska.php, do koje se
// stiže i onda kad baza ne radi.
require_once __DIR__ . '/../config/konfiguracija.php';
require_once __DIR__ . '/funkcije.php';
require_once __DIR__ . '/tema.php';

// Kratak opis stranice - koristi se i za <meta name="description"> i za
// prikaz kad se link nalepi u poruku ili na sajt
define('OPIS_APLIKACIJE', 'Simulacija rada kladionice i procesa klađenja: ponuda, kvote, tiketi i rezultati. Fakultetski projekat sa virtuelnim novcem, bez pravih uplata.');

// Puna adresa (sa shemom i domenom) do fajla u projektu. Open Graph ne prihvata
// relativne putanje - servis koji prikazuje pregled linka ne zna sa kog je
// sajta došla. Adresa se sastavlja iz samog zahteva, pa radi i na localhost-u
// i ako projekat jednom pređe na drugi domen.
function puna_adresa(string $putanja): string
{
    $shema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $domacin = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $shema . '://' . $domacin . $putanja;
}

// Ispisuje ceo zajednički deo <head>-a. $naslov je naslov same stranice, bez
// imena aplikacije - ono se dodaje ovde, da svuda stoji u istom obliku.
function ispisi_glavu(string $naslov, ?string $opis = null): void
{
    $opis = $opis ?? OPIS_APLIKACIJE;
    $pun_naslov = $naslov . ' — Kladionica';
    ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= bezbedno($pun_naslov) ?></title>
    <meta name="description" content="<?= bezbedno($opis) ?>">

    <?php // Ikonice: SVG za moderne pregledače (oštar na svakoj veličini),
          // .ico kao rezerva, PNG za prečicu na iOS početnom ekranu ?>
    <link rel="icon" href="<?= KORENSKI_URL ?>/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="<?= KORENSKI_URL ?>/favicon.ico" sizes="32x32">
    <link rel="apple-touch-icon" href="<?= KORENSKI_URL ?>/apple-touch-icon.png">

    <?php meta_boja_teme(); ?>

    <?php // Open Graph - kako link izgleda kad se nalepi u poruku ili na sajt ?>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Kladionica">
    <meta property="og:title" content="<?= bezbedno($pun_naslov) ?>">
    <meta property="og:description" content="<?= bezbedno($opis) ?>">
    <meta property="og:image" content="<?= bezbedno(puna_adresa(KORENSKI_URL . '/og-slika.png')) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:url" content="<?= bezbedno(puna_adresa($_SERVER['REQUEST_URI'] ?? KORENSKI_URL . '/')) ?>">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= KORENSKI_URL ?>/css/style.css" rel="stylesheet">
    <?php

    // Kad tema nije izabrana, naši tokeni prate sistem kroz @media u style.css,
    // ali Bootstrap svoje komponente (padajući meni, polja, strelicu u select-u)
    // boji isključivo po atributu data-bs-theme. Ovih par redova ga postavlja
    // pre iscrtavanja. Bez JavaScripta stranica je i dalje ispravno tamna -
    // samo te Bootstrap-ove komponente ostaju svetle dok korisnik jednom ne
    // dodirne prekidač, čime se kolačić postavi i PHP preuzme posao.
    if (izabrana_tema() === null):
    ?>
    <script>
        (function () {
            try {
                if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.setAttribute('data-bs-theme', 'dark');
                }
            } catch (e) { /* stari pregledač bez matchMedia - tema ostaje svetla */ }
        })();
    </script>
    <?php endif;
}

// Prekidač teme za navigaciju.
//
// U formi stoje DVA dugmeta, a CSS prikazuje ono koje odgovara temi koja je
// trenutno na snazi. Razlog: kad kolačića nema, server ne zna kako je sistem
// podešen, pa ne može da izabere pravo dugme - a CSS to zna kroz media upit.
// Sve radi bez JavaScripta, običnim slanjem forme.
function prekidac_teme(string $csrf_token): void
{
    $povratak = $_SERVER['REQUEST_URI'] ?? (KORENSKI_URL . '/index.php');
    ?>
    <form method="post" action="<?= KORENSKI_URL ?>/tema.php" class="prekidac-teme">
        <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
        <input type="hidden" name="povratak" value="<?= bezbedno($povratak) ?>">

        <button type="submit" name="tema" value="tamna" class="btn-tema samo-u-svetloj"
                aria-label="Uključi tamnu temu" title="Uključi tamnu temu">
            <i class="bi bi-moon-stars-fill" aria-hidden="true"></i>
        </button>
        <button type="submit" name="tema" value="svetla" class="btn-tema samo-u-tamnoj"
                aria-label="Uključi svetlu temu" title="Uključi svetlu temu">
            <i class="bi bi-sun-fill" aria-hidden="true"></i>
        </button>
    </form>
    <?php
}

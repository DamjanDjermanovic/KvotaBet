<?php
// Zaglavlje stranice: HTML <head>, otvara <body> i ispisuje navigaciju koja
// se menja prema ulozi prijavljenog korisnika. Uključuje se na vrhu svake
// javne ili zaštićene stranice (ne i u ajax/ endpointima, koji vraćaju JSON),
// posle includes/autentifikacija.php i eventualne provere zahtevaj_ulogu().

require_once __DIR__ . '/autentifikacija.php';

// Stranica pre uključivanja može postaviti $naslov_stranice; ako to ne uradi,
// koristi se podrazumevani naslov
$naslov_stranice = $naslov_stranice ?? 'Kladionica';

// Evidentira se svaki pristup stranici - i gosta i prijavljenog korisnika -
// u tekstualni log fajl, kao osnova za statistiku pristupa u admin panelu
upisi_pristup($_SERVER['REQUEST_URI'] ?? '', $_SESSION['korisnicko_ime'] ?? null);
?>
<!DOCTYPE html>
<html lang="sr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= bezbedno($naslov_stranice) ?> — Kladionica</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= KORENSKI_URL ?>/css/style.css" rel="stylesheet">
</head>
<body class="d-flex flex-column min-vh-100">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="<?= KORENSKI_URL ?>/index.php">Kladionica</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#glavniMeni">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="glavniMeni">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="<?= KORENSKI_URL ?>/index.php">Ponuda</a></li>

                <?php if (trenutna_uloga() === 'igrac'): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= KORENSKI_URL ?>/igrac/uplata.php">Uplata sredstava</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= KORENSKI_URL ?>/igrac/tiketi.php">Moji tiketi</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= KORENSKI_URL ?>/igrac/profil.php">Profil</a></li>
                <?php elseif (trenutna_uloga() === 'operater'): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= KORENSKI_URL ?>/operater/sportovi.php">Sportovi i lige</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= KORENSKI_URL ?>/operater/dogadjaji.php">Događaji i kvote</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= KORENSKI_URL ?>/operater/dokumenta.php">Dokumenta</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= KORENSKI_URL ?>/operater/tiketi.php">Tiketi</a></li>
                <?php elseif (trenutna_uloga() === 'admin'): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= KORENSKI_URL ?>/admin/korisnici.php">Korisnici</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= KORENSKI_URL ?>/admin/logovi.php">Logovi</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= KORENSKI_URL ?>/admin/statistika.php">Statistika</a></li>
                <?php endif; ?>
            </ul>

            <ul class="navbar-nav">
                <?php if (korisnik_prijavljen()): ?>
                    <li class="nav-item">
                        <span class="navbar-text me-3">
                            <?= bezbedno($_SESSION['korisnicko_ime']) ?> (<?= bezbedno(trenutna_uloga()) ?>)
                        </span>
                    </li>
                    <li class="nav-item"><a class="nav-link" href="<?= KORENSKI_URL ?>/odjava.php">Odjava</a></li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="<?= KORENSKI_URL ?>/prijava.php">Prijava</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= KORENSKI_URL ?>/registracija.php">Registracija</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<main class="flex-grow-1">
<div class="container py-4">

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
// i u tekstualni log fajl i u tabelu pristup_log, kao osnova za statistiku
// pristupa u admin panelu
upisi_pristup($_SERVER['REQUEST_URI'] ?? '', $_SESSION['korisnicko_ime'] ?? null);
evidentiraj_pristup_u_bazi(konektuj_bazu(), $_SESSION['korisnik_id'] ?? null, $_SERVER['REQUEST_URI'] ?? '');

// Stanje računa se prikazuje u navigaciji, pa se ovde i čita. Račun za
// klađenje ima samo igrač - operater i admin ga nemaju, a gost nije prijavljen.
$stanje_u_navigaciji = null;

if (trenutna_uloga() === 'igrac' && isset($_SESSION['korisnik_id'])) {
    try {
        $upit_stanja = konektuj_bazu()->prepare('SELECT stanje_racuna FROM korisnik WHERE id = ?');
        $upit_stanja->execute([(int) $_SESSION['korisnik_id']]);
        $procitano_stanje = $upit_stanja->fetchColumn();

        if ($procitano_stanje !== false) {
            $stanje_u_navigaciji = formatiraj_novac($procitano_stanje);
        }
    } catch (PDOException $e) {
        // Neuspelo čitanje stanja ne sme da obori celu stranicu - navigacija se
        // tada samo ispiše bez iznosa, a uzrok ostane zabeležen u greske.log
        upisi_gresku('Greška pri čitanju stanja računa za navigaciju: ' . $e->getMessage());
    }
}

// Ispisuje jednu stavku glavne navigacije. Stavka koja pokazuje na stranicu
// koja se upravo prikazuje dobija klasu .active (stil je istakne tankom crtom
// ispod) i atribut aria-current, po kome čitači ekrana javljaju gde se
// korisnik nalazi. Poređenje ide preko PHP_SELF, jer se isti naziv fajla
// javlja u više foldera (npr. transakcije.php i kod igrača i kod admina).
function stavka_menija(string $adresa, string $naziv): void
{
    $puna_adresa = KORENSKI_URL . $adresa;
    $aktivna = ($_SERVER['PHP_SELF'] ?? '') === $puna_adresa;

    $klasa = $aktivna ? 'nav-link active' : 'nav-link';
    $aria = $aktivna ? ' aria-current="page"' : '';

    echo '<li class="nav-item">'
        . '<a class="' . $klasa . '"' . $aria . ' href="' . bezbedno($puna_adresa) . '">'
        . bezbedno($naziv)
        . '</a></li>';
}
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

<?php // Prečica za tastaturu: nevidljiva je dok se ne dobije fokus tabulatorom ?>
<a class="preskoci" href="#sadrzaj">Preskoči na sadržaj</a>

<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container">
        <a class="navbar-brand" href="<?= KORENSKI_URL ?>/index.php">
            <i class="bi bi-ticket-perforated" aria-hidden="true"></i>
            Kladionica
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#glavniMeni"
                aria-controls="glavniMeni" aria-expanded="false" aria-label="Prikaži meni">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="glavniMeni">
            <ul class="navbar-nav me-auto">
                <?php stavka_menija('/index.php', 'Ponuda'); ?>

                <?php if (trenutna_uloga() === 'igrac'): ?>
                    <?php stavka_menija('/igrac/uplata.php', 'Uplata sredstava'); ?>
                    <?php stavka_menija('/igrac/tiketi.php', 'Moji tiketi'); ?>
                    <?php stavka_menija('/igrac/transakcije.php', 'Transakcije'); ?>
                    <?php stavka_menija('/igrac/profil.php', 'Profil'); ?>
                <?php elseif (trenutna_uloga() === 'operater'): ?>
                    <?php stavka_menija('/operater/sportovi.php', 'Sportovi'); ?>
                    <?php stavka_menija('/operater/lige.php', 'Lige'); ?>
                    <?php stavka_menija('/operater/timovi.php', 'Timovi'); ?>
                    <?php stavka_menija('/operater/tipovi_igre.php', 'Tipovi igre'); ?>
                    <?php stavka_menija('/operater/dogadjaji.php', 'Događaji i kvote'); ?>
                    <?php stavka_menija('/operater/rezultati.php', 'Rezultati'); ?>
                    <?php stavka_menija('/operater/dokumenta.php', 'Dokumenta'); ?>
                <?php elseif (trenutna_uloga() === 'admin'): ?>
                    <?php stavka_menija('/admin/korisnici.php', 'Korisnici'); ?>
                    <?php stavka_menija('/admin/uloge.php', 'Uloge'); ?>
                    <?php stavka_menija('/admin/tiketi.php', 'Tiketi'); ?>
                    <?php stavka_menija('/admin/transakcije.php', 'Transakcije'); ?>
                    <?php stavka_menija('/admin/dokumenta.php', 'Dokumenti'); ?>
                    <?php stavka_menija('/admin/logovi.php', 'Logovi'); ?>
                    <?php stavka_menija('/admin/statistika.php', 'Statistika'); ?>
                <?php endif; ?>
            </ul>

            <ul class="navbar-nav align-items-lg-center">
                <?php if (korisnik_prijavljen()): ?>
                    <?php if ($stanje_u_navigaciji !== null): ?>
                        <li class="nav-item me-lg-3 my-2 my-lg-0">
                            <span class="nav-stanje" title="Stanje virtuelnog računa">
                                <i class="bi bi-wallet2" aria-hidden="true"></i>
                                <?= bezbedno($stanje_u_navigaciji) ?>
                            </span>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <span class="navbar-text me-lg-2">
                            <?= bezbedno($_SESSION['korisnicko_ime']) ?> (<?= bezbedno(trenutna_uloga()) ?>)
                        </span>
                    </li>
                    <?php stavka_menija('/odjava.php', 'Odjava'); ?>
                <?php else: ?>
                    <?php stavka_menija('/prijava.php', 'Prijava'); ?>
                    <?php stavka_menija('/registracija.php', 'Registracija'); ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<main class="flex-grow-1" id="sadrzaj">
<div class="container py-4">

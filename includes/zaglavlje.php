<?php
// Zaglavlje stranice: HTML <head>, otvara <body> i ispisuje navigaciju koja
// se menja prema ulozi prijavljenog korisnika. Uključuje se na vrhu svake
// javne ili zaštićene stranice (ne i u ajax/ endpointima, koji vraćaju JSON),
// posle includes/autentifikacija.php i eventualne provere zahtevaj_ulogu().

require_once __DIR__ . '/autentifikacija.php';
require_once __DIR__ . '/glava.php';

// Stranica pre uključivanja mora postaviti $naslov_stranice. Podrazumevana
// vrednost postoji samo da naslov nikad ne ostane prazan - svaka stranica
// treba da kaže šta je, jer je taj tekst i naslov kartice u pregledaču i
// tekst koji se vidi kad se link nalepi negde.
$naslov_stranice = $naslov_stranice ?? 'Ponuda';

// Stranica sme postaviti i sopstveni opis; ako ne, koristi se opšti
$opis_stranice = $opis_stranice ?? null;

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

// Da li data adresa odgovara stranici koja se upravo prikazuje. Poređenje ide
// preko PHP_SELF, jer se isti naziv fajla javlja u više foldera (npr.
// transakcije.php i kod igrača i kod admina). Deli je i stavka_menija() i
// grupa_menija(), da se grupa označi aktivnom po istom pravilu kao pojedinačna
// stavka.
function stavka_aktivna(string $adresa): bool
{
    return ($_SERVER['PHP_SELF'] ?? '') === (KORENSKI_URL . $adresa);
}

// Ispisuje jednu stavku glavne navigacije, van grupe. Stavka koja pokazuje na
// stranicu koja se upravo prikazuje dobija klasu .active (stil je istakne
// tankom crtom ispod) i atribut aria-current, po kome čitači ekrana javljaju
// gde se korisnik nalazi.
function stavka_menija(string $adresa, string $naziv): void
{
    $puna_adresa = KORENSKI_URL . $adresa;
    $aktivna = stavka_aktivna($adresa);

    $klasa = $aktivna ? 'nav-link active' : 'nav-link';
    $aria = $aktivna ? ' aria-current="page"' : '';

    echo '<li class="nav-item">'
        . '<a class="' . $klasa . '"' . $aria . ' href="' . bezbedno($puna_adresa) . '">'
        . bezbedno($naziv)
        . '</a></li>';
}

// Ispisuje grupu stavki kao padajući meni. $stavke je niz parova
// [adresa, naziv], istog oblika kakav stavka_menija() prima pojedinačno.
//
// Grupa dobija klasu .active (istu tanku crtu kao pojedinačna stavka) čim je
// BILO KOJA njena stavka na trenutnoj stranici - korisnik tako vidi u kom je
// delu menija, i kad je unutar grupe otvorio treću stavku, a ne prvu.
// aria-current="page" i dalje nosi isključivo ta jedna stvarna stavka: da ga
// dobije i sam naslov grupe, čitač ekrana bi javio da je cela grupa "trenutna
// stranica", što nije tačno - grupa nije stranica, ona samo sadrži jednu.
//
// Otvaranje ide na tri načina, namerno slojevito:
//   1. bez JavaScripta: :hover i :focus-within u style.css otkrivaju meni,
//      jer Bootstrap .dropdown već ima position:relative iz svog CSS-a, pa
//      apsolutno pozicioniran .dropdown-menu ispravno padne ispod bez Poppera;
//   2. sa JavaScriptom, na širem ekranu: klik i tastatura (strelice, Escape,
//      Tab) rade kroz Bootstrap-ov ugrađeni dropdown iz bootstrap.bundle.min.js
//      - ništa od toga ovde nije ručno pisano;
//   3. sa JavaScriptom, u sažetom (mobilnom) meniju: isti Bootstrap mehanizam,
//      ali se meni prikazuje kao ugnežđena lista u toku teksta, ne kao popup
//      preko sadržaja - to obezbeđuje CSS (position: static ispod 992px),
//      uz data-bs-display="static" koje Bootstrap-u kaže da ne pokreće Popper.
function grupa_menija(string $naziv_grupe, array $stavke): void
{
    $aktivna_grupa = false;
    foreach ($stavke as [$adresa, $naziv_stavke]) {
        if (stavka_aktivna($adresa)) {
            $aktivna_grupa = true;
            break;
        }
    }

    // Jedinstven id za aria-labelledby - funkcija se poziva više puta po strani
    static $brojac_grupa = 0;
    $id_toggle = 'meni-grupa-' . (++$brojac_grupa);

    $klasa_toggle = 'nav-link dropdown-toggle' . ($aktivna_grupa ? ' active' : '');

    echo '<li class="nav-item dropdown">';
    echo '<a class="' . $klasa_toggle . '" href="#" id="' . bezbedno($id_toggle) . '" role="button"'
        . ' data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">'
        . bezbedno($naziv_grupe) . '</a>';
    echo '<ul class="dropdown-menu" aria-labelledby="' . bezbedno($id_toggle) . '">';

    foreach ($stavke as [$adresa, $naziv_stavke]) {
        $puna_adresa = KORENSKI_URL . $adresa;
        $aktivna = stavka_aktivna($adresa);
        $klasa = 'dropdown-item' . ($aktivna ? ' active' : '');
        $aria = $aktivna ? ' aria-current="page"' : '';

        echo '<li><a class="' . $klasa . '"' . $aria . ' href="' . bezbedno($puna_adresa) . '">'
            . bezbedno($naziv_stavke) . '</a></li>';
    }

    echo '</ul></li>';
}
?>
<!DOCTYPE html>
<?php // Tema se upisuje na <html> pre bilo kakvog ispisa, da tamna strana ne
      // bljesne belo dok se stilovi učitavaju ?>
<html lang="sr"<?= atributi_teme() ?>>
<head>
    <?php ispisi_glavu($naslov_stranice, $opis_stranice); ?>
</head>
<body class="d-flex flex-column min-vh-100">

<?php // Prečica za tastaturu: nevidljiva je dok se ne dobije fokus tabulatorom ?>
<a class="preskoci" href="#sadrzaj">Preskoči na sadržaj</a>

<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container">
        <a class="navbar-brand" href="<?= KORENSKI_URL ?>/index.php">
            <i class="bi bi-graph-up-arrow" aria-hidden="true"></i>
            Kvota Bet
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#glavniMeni"
                aria-controls="glavniMeni" aria-expanded="false" aria-label="Prikaži meni">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="glavniMeni">
            <ul class="navbar-nav me-auto">
                <?php stavka_menija('/index.php', 'Ponuda'); ?>

                <?php if (trenutna_uloga() === 'igrac'): ?>
                    <?php // Samo četiri stavke - ostaju ravne, grupisanje bi ovde bilo suvišno ?>
                    <?php stavka_menija('/igrac/uplata.php', 'Uplata sredstava'); ?>
                    <?php stavka_menija('/igrac/tiketi.php', 'Moji tiketi'); ?>
                    <?php stavka_menija('/igrac/transakcije.php', 'Transakcije'); ?>
                    <?php stavka_menija('/igrac/profil.php', 'Profil'); ?>
                <?php elseif (trenutna_uloga() === 'operater'): ?>
                    <?php grupa_menija('Utakmice', [
                        ['/operater/dogadjaji.php', 'Događaji i kvote'],
                        ['/operater/rezultati.php', 'Rezultati'],
                        ['/operater/uvoz.php', 'Uvoz sa API-ja'],
                    ]); ?>
                    <?php grupa_menija('Šifarnici', [
                        ['/operater/sportovi.php', 'Sportovi'],
                        ['/operater/lige.php', 'Lige'],
                        ['/operater/timovi.php', 'Timovi'],
                        ['/operater/tipovi_igre.php', 'Tipovi igre'],
                        ['/operater/api_lige.php', 'Mapiranje liga'],
                    ]); ?>
                    <?php stavka_menija('/operater/dokumenta.php', 'Dokumenta'); ?>
                <?php elseif (trenutna_uloga() === 'admin'): ?>
                    <?php grupa_menija('Korisnici', [
                        ['/admin/korisnici.php', 'Korisnici'],
                        ['/admin/uloge.php', 'Uloge'],
                        ['/admin/dokumenta.php', 'Dokumenti'],
                    ]); ?>
                    <?php grupa_menija('Poslovanje', [
                        ['/admin/tiketi.php', 'Tiketi'],
                        ['/admin/transakcije.php', 'Transakcije'],
                    ]); ?>
                    <?php grupa_menija('Nadzor', [
                        ['/admin/statistika.php', 'Statistika'],
                        ['/admin/logovi.php', 'Logovi'],
                    ]); ?>
                <?php endif; ?>
            </ul>

            <ul class="navbar-nav align-items-lg-center">
                <li class="nav-item me-lg-2 my-2 my-lg-0">
                    <?php prekidac_teme(generisi_csrf_token()); ?>
                </li>

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

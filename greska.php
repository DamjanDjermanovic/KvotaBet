<?php
// Stranica sa greškom za kodove 403, 404 i 500.
//
// Namerno je samostalna: ne uključuje zaglavlje.php ni podnozje.php i ne dira
// bazu. Razlog je što se ovde stiže i kad baza ne radi - da je stranica sa
// greškom zavisila od baze, pad baze bi je oborio i korisnik bi umesto poruke
// dobio praznu stranicu ili sirovu grešku.
//
// Kod se određuje iz ?kod= (kad na nju uputi sama aplikacija) ili iz
// REDIRECT_STATUS (kad je pozove Apache preko ErrorDocument iz .htaccess).

require_once __DIR__ . '/config/konfiguracija.php';

$kod = (int) ($_GET['kod'] ?? $_SERVER['REDIRECT_STATUS'] ?? 404);

$poruke = [
    403 => [
        'naslov' => 'Zabranjen pristup',
        'tekst'  => 'Nemate pravo pristupa ovoj stranici. Ako mislite da je ovo greška, prijavite se nalogom koji ima odgovarajuću ulogu.',
        'ikona'  => '&#128274;', // katanac
    ],
    404 => [
        'naslov' => 'Stranica nije pronađena',
        'tekst'  => 'Tražena stranica ne postoji ili je premeštena. Proverite adresu ili se vratite na početnu stranu.',
        'ikona'  => '&#128269;', // lupa
    ],
    500 => [
        'naslov' => 'Greška na serveru',
        'tekst'  => 'Došlo je do neočekivane greške. Greška je zabeležena i biće pregledana. Pokušajte ponovo kasnije.',
        'ikona'  => '&#9888;', // znak upozorenja
    ],
];

// Nepoznat kod se prikazuje kao 404, da se ne bi ispisivalo nešto što nije predviđeno
if (!isset($poruke[$kod])) {
    $kod = 404;
}

$poruka = $poruke[$kod];

// Status se postavlja i ovde, da odgovor nosi tačan HTTP kod i kad se stranici
// pristupi direktno, a ne preko Apache-ovog ErrorDocument mehanizma
if (!headers_sent()) {
    http_response_code($kod);
}
?>
<!DOCTYPE html>
<html lang="sr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $kod ?> — <?= htmlspecialchars($poruka['naslov'], ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= KORENSKI_URL ?>/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6">
            <div class="card shadow-sm my-5 text-center">
                <div class="card-body p-5">
                    <div style="font-size:3rem; line-height:1"><?= $poruka['ikona'] ?></div>
                    <div class="display-4 fw-bold mt-2"><?= $kod ?></div>
                    <h1 class="h4 mt-2"><?= htmlspecialchars($poruka['naslov'], ENT_QUOTES, 'UTF-8') ?></h1>
                    <p class="text-muted mt-3"><?= htmlspecialchars($poruka['tekst'], ENT_QUOTES, 'UTF-8') ?></p>

                    <div class="d-flex gap-2 justify-content-center mt-4">
                        <a href="<?= KORENSKI_URL ?>/index.php" class="btn btn-primary">Početna strana</a>
                        <?php if ($kod === 403): ?>
                            <a href="<?= KORENSKI_URL ?>/prijava.php" class="btn btn-outline-secondary">Prijava</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>

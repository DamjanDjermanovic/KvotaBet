<?php
// Registracija novog korisnika (uvek sa ulogom "igrac"). Server sam
// ponovo proverava sve što je JS već proverio na klijentu - klijentska
// provera je samo udobnost, ova ovde je jedina koja se računa.

require_once __DIR__ . '/includes/autentifikacija.php';
require_once __DIR__ . '/includes/validacija.php';

// Već prijavljen korisnik nema šta da traži na registraciji
if (korisnik_prijavljen()) {
    preusmeri(KORENSKI_URL . '/index.php');
}

$greske = [];
$uneto = [
    'ime' => '', 'prezime' => '', 'korisnicko_ime' => '',
    'email' => '', 'datum_rodjenja' => '', 'telefon' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_ispravan($_POST['csrf_token'] ?? null)) {
        $greske['opsta'] = 'Nevažeća sesija. Osvežite stranicu i pokušajte ponovo.';
    } else {
        $uneto['ime']            = trim($_POST['ime'] ?? '');
        $uneto['prezime']        = trim($_POST['prezime'] ?? '');
        $uneto['korisnicko_ime'] = trim($_POST['korisnicko_ime'] ?? '');
        $uneto['email']          = trim($_POST['email'] ?? '');
        $uneto['datum_rodjenja'] = trim($_POST['datum_rodjenja'] ?? '');
        $uneto['telefon']        = trim($_POST['telefon'] ?? '');
        $lozinka                 = $_POST['lozinka'] ?? '';
        $potvrda_lozinke         = $_POST['potvrda_lozinke'] ?? '';

        if (!validno_ime($uneto['ime'])) {
            $greske['ime'] = 'Ime mora imati 2-50 slova.';
        }
        if (!validno_ime($uneto['prezime'])) {
            $greske['prezime'] = 'Prezime mora imati 2-50 slova.';
        }
        if (!validno_korisnicko_ime($uneto['korisnicko_ime'])) {
            $greske['korisnicko_ime'] = 'Korisničko ime: 3-30 znakova, slova, cifre i donja crta.';
        }
        if (!validan_email($uneto['email'])) {
            $greske['email'] = 'Unesite ispravnu email adresu.';
        }
        if (!validna_lozinka($lozinka)) {
            $greske['lozinka'] = 'Lozinka mora imati bar 6 znakova, jedno slovo i jednu cifru.';
        } elseif ($lozinka !== $potvrda_lozinke) {
            $greske['potvrda_lozinke'] = 'Lozinke se ne poklapaju.';
        }
        if (!validan_datum_rodjenja($uneto['datum_rodjenja'])) {
            $greske['datum_rodjenja'] = 'Unesite ispravan datum rođenja.';
        } elseif (!korisnik_je_punoletan($uneto['datum_rodjenja'])) {
            $greske['datum_rodjenja'] = 'Registracija je dozvoljena samo punoletnim korisnicima.';
        }
        if (!validan_telefon($uneto['telefon'])) {
            $greske['telefon'] = 'Unesite ispravan broj telefona.';
        }

        if (empty($greske)) {
            try {
                $pdo = konektuj_bazu();

                $provera = $pdo->prepare('SELECT id FROM korisnik WHERE email = ? OR korisnicko_ime = ?');
                $provera->execute([$uneto['email'], $uneto['korisnicko_ime']]);

                if ($provera->fetch()) {
                    $greske['opsta'] = 'Nalog sa unetim email-om ili korisničkim imenom već postoji.';
                } else {
                    $upit = $pdo->prepare(
                        'INSERT INTO korisnik
                            (uloga_id, ime, prezime, korisnicko_ime, email, lozinka_hash, datum_rodjenja, telefon)
                         VALUES (1, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $upit->execute([
                        $uneto['ime'],
                        $uneto['prezime'],
                        $uneto['korisnicko_ime'],
                        $uneto['email'],
                        password_hash($lozinka, PASSWORD_DEFAULT),
                        $uneto['datum_rodjenja'],
                        $uneto['telefon'] !== '' ? $uneto['telefon'] : null,
                    ]);

                    $noviId = (int) $pdo->lastInsertId();
                    $novi = $pdo->prepare(
                        'SELECT k.id, k.korisnicko_ime, k.status, u.naziv AS uloga
                         FROM korisnik k JOIN uloga u ON u.id = k.uloga_id WHERE k.id = ?'
                    );
                    $novi->execute([$noviId]);

                    zapocni_sesiju_korisnika($novi->fetch());
                    preusmeri(KORENSKI_URL . '/index.php');
                }
            } catch (PDOException $e) {
                upisi_gresku('Greška pri registraciji: ' . $e->getMessage());
                $greske['opsta'] = 'Trenutno nije moguće izvršiti registraciju. Pokušajte ponovo kasnije.';
            }
        }
    }
}

$csrf_token = generisi_csrf_token();
$max_datum_rodjenja = date('Y-m-d', strtotime('-' . MIN_GODINA_ZA_REGISTRACIJU . ' years'));
?>
<!DOCTYPE html>
<html lang="sr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registracija — Kladionica</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= KORENSKI_URL ?>/css/style.css" rel="stylesheet">
</head>
<body>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-12">
            <?php // .auth-karta sama drži širinu i razmak, pa Bootstrap kolone ovde ne stežu sadržaj ?>
            <div class="card auth-karta">
                <div class="card-body p-4">
                    <div class="text-center mb-4">
                        <span class="eyebrow">Kladionica</span>
                        <h1 class="mb-0">Registracija</h1>
                    </div>

                    <?php if (!empty($greske['opsta'])): ?>
                        <div class="alert alert-danger"><?= bezbedno($greske['opsta']) ?></div>
                    <?php endif; ?>

                    <form method="post" novalidate id="forma-registracija">
                        <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">

                        <div class="row">
                            <div class="col-12 col-sm-6 mb-3">
                                <label for="ime" class="form-label">Ime</label>
                                <input type="text" class="form-control <?= isset($greske['ime']) ? 'is-invalid' : '' ?>"
                                       id="ime" name="ime" required minlength="2" maxlength="50"
                                       value="<?= bezbedno($uneto['ime']) ?>">
                                <div class="invalid-feedback"><?= bezbedno($greske['ime'] ?? 'Unesite ime.') ?></div>
                            </div>
                            <div class="col-12 col-sm-6 mb-3">
                                <label for="prezime" class="form-label">Prezime</label>
                                <input type="text" class="form-control <?= isset($greske['prezime']) ? 'is-invalid' : '' ?>"
                                       id="prezime" name="prezime" required minlength="2" maxlength="50"
                                       value="<?= bezbedno($uneto['prezime']) ?>">
                                <div class="invalid-feedback"><?= bezbedno($greske['prezime'] ?? 'Unesite prezime.') ?></div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="korisnicko_ime" class="form-label">Korisničko ime</label>
                            <input type="text" class="form-control <?= isset($greske['korisnicko_ime']) ? 'is-invalid' : '' ?>"
                                   id="korisnicko_ime" name="korisnicko_ime" required pattern="[A-Za-z0-9_]{3,30}"
                                   value="<?= bezbedno($uneto['korisnicko_ime']) ?>">
                            <div class="invalid-feedback"><?= bezbedno($greske['korisnicko_ime'] ?? 'Unesite korisničko ime.') ?></div>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control <?= isset($greske['email']) ? 'is-invalid' : '' ?>"
                                   id="email" name="email" required maxlength="120"
                                   value="<?= bezbedno($uneto['email']) ?>">
                            <div class="invalid-feedback"><?= bezbedno($greske['email'] ?? 'Unesite ispravan email.') ?></div>
                        </div>

                        <div class="row">
                            <div class="col-12 col-sm-6 mb-3">
                                <label for="lozinka" class="form-label">Lozinka</label>
                                <input type="password" class="form-control <?= isset($greske['lozinka']) ? 'is-invalid' : '' ?>"
                                       id="lozinka" name="lozinka" required minlength="6">
                                <div class="invalid-feedback"><?= bezbedno($greske['lozinka'] ?? 'Bar 6 znakova, slovo i cifra.') ?></div>
                            </div>
                            <div class="col-12 col-sm-6 mb-3">
                                <label for="potvrda_lozinke" class="form-label">Potvrda lozinke</label>
                                <input type="password" class="form-control <?= isset($greske['potvrda_lozinke']) ? 'is-invalid' : '' ?>"
                                       id="potvrda_lozinke" name="potvrda_lozinke" required minlength="6">
                                <div class="invalid-feedback"><?= bezbedno($greske['potvrda_lozinke'] ?? 'Lozinke se moraju poklapati.') ?></div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-12 col-sm-6 mb-3">
                                <label for="datum_rodjenja" class="form-label">Datum rođenja</label>
                                <input type="date" class="form-control <?= isset($greske['datum_rodjenja']) ? 'is-invalid' : '' ?>"
                                       id="datum_rodjenja" name="datum_rodjenja" required max="<?= bezbedno($max_datum_rodjenja) ?>"
                                       value="<?= bezbedno($uneto['datum_rodjenja']) ?>">
                                <div class="invalid-feedback"><?= bezbedno($greske['datum_rodjenja'] ?? 'Morate imati bar ' . MIN_GODINA_ZA_REGISTRACIJU . ' godina.') ?></div>
                            </div>
                            <div class="col-12 col-sm-6 mb-3">
                                <label for="telefon" class="form-label">Telefon <span class="text-muted">(opciono)</span></label>
                                <input type="text" class="form-control <?= isset($greske['telefon']) ? 'is-invalid' : '' ?>"
                                       id="telefon" name="telefon" maxlength="20"
                                       value="<?= bezbedno($uneto['telefon']) ?>">
                                <div class="invalid-feedback"><?= bezbedno($greske['telefon'] ?? 'Unesite ispravan broj telefona.') ?></div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 mt-2">Registruj se</button>
                    </form>

                    <p class="text-center mt-3 mb-0">
                        Već imate nalog? <a href="<?= KORENSKI_URL ?>/prijava.php">Prijavite se</a>
                    </p>
                    <p class="text-center text-muted small mt-3 mb-0">
                        Simulacija sa virtuelnim novcem — registracija je dozvoljena od
                        <?= MIN_GODINA_ZA_REGISTRACIJU ?> godina.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="<?= KORENSKI_URL ?>/js/validacija.js"></script>
</body>
</html>

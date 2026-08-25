<?php
// Prijava korisnika na sistem. Prihvata korisničko ime ili email + lozinku,
// a opciono postavlja kolačić "zapamti me" za automatsku prijavu ubuduće.

require_once __DIR__ . '/includes/autentifikacija.php';

if (korisnik_prijavljen()) {
    preusmeri(KORENSKI_URL . '/index.php');
}

$greska = isset($_GET['blokiran']) ? 'Vaš nalog je u međuvremenu blokiran, pa ste odjavljeni.' : '';
$uneta_prijava = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_ispravan($_POST['csrf_token'] ?? null)) {
        $greska = 'Nevažeća sesija. Osvežite stranicu i pokušajte ponovo.';
    } else {
        $uneta_prijava = trim($_POST['prijava'] ?? '');
        $lozinka = $_POST['lozinka'] ?? '';
        $zapamti_me = isset($_POST['zapamti_me']);

        if ($uneta_prijava === '' || $lozinka === '') {
            $greska = 'Unesite korisničko ime (ili email) i lozinku.';
        } else {
            try {
                $pdo = konektuj_bazu();
                $upit = $pdo->prepare(
                    'SELECT k.id, k.korisnicko_ime, k.lozinka_hash, k.status, u.naziv AS uloga
                     FROM korisnik k JOIN uloga u ON u.id = k.uloga_id
                     WHERE k.email = ? OR k.korisnicko_ime = ?'
                );
                $upit->execute([$uneta_prijava, $uneta_prijava]);
                $korisnik = $upit->fetch();

                if (!$korisnik || !password_verify($lozinka, $korisnik['lozinka_hash'])) {
                    // Namerno ista poruka za nepostojećeg korisnika i pogrešnu lozinku -
                    // sprečava otkrivanje da li nalog postoji (user enumeration)
                    $greska = 'Pogrešno korisničko ime/email ili lozinka.';
                } elseif ($korisnik['status'] === 'blokiran') {
                    $greska = 'Vaš nalog je blokiran. Obratite se administratoru.';
                } else {
                    zapocni_sesiju_korisnika($korisnik);

                    $azuriraj = $pdo->prepare('UPDATE korisnik SET poslednja_prijava = NOW() WHERE id = ?');
                    $azuriraj->execute([$korisnik['id']]);

                    if ($zapamti_me) {
                        postavi_kolacic_zapamti_me((int) $korisnik['id']);
                    }

                    preusmeri(KORENSKI_URL . '/index.php');
                }
            } catch (PDOException $e) {
                upisi_gresku('Greška pri prijavi: ' . $e->getMessage());
                $greska = 'Trenutno nije moguće izvršiti prijavu. Pokušajte ponovo kasnije.';
            }
        }
    }
}

$csrf_token = generisi_csrf_token();
?>
<!DOCTYPE html>
<html lang="sr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Prijava — Kladionica</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= KORENSKI_URL ?>/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-12 col-md-6 col-lg-5">
            <div class="card shadow-sm my-5">
                <div class="card-body p-4">
                    <h1 class="h3 mb-4 text-center">Prijava</h1>

                    <?php if ($greska !== ''): ?>
                        <div class="alert alert-danger"><?= bezbedno($greska) ?></div>
                    <?php endif; ?>

                    <form method="post" novalidate id="forma-prijava">
                        <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">

                        <div class="mb-3">
                            <label for="prijava" class="form-label">Korisničko ime ili email</label>
                            <input type="text" class="form-control" id="prijava" name="prijava" required
                                   value="<?= bezbedno($uneta_prijava) ?>">
                            <div class="invalid-feedback">Unesite korisničko ime ili email.</div>
                        </div>

                        <div class="mb-3">
                            <label for="lozinka" class="form-label">Lozinka</label>
                            <input type="password" class="form-control" id="lozinka" name="lozinka" required>
                            <div class="invalid-feedback">Unesite lozinku.</div>
                        </div>

                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="zapamti_me" name="zapamti_me">
                            <label class="form-check-label" for="zapamti_me">Zapamti me</label>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Prijavi se</button>
                    </form>

                    <p class="text-center mt-3 mb-0">
                        Nemate nalog? <a href="<?= KORENSKI_URL ?>/registracija.php">Registrujte se</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="<?= KORENSKI_URL ?>/js/validacija.js"></script>
</body>
</html>

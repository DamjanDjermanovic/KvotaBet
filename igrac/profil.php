<?php
// Profil igrača: izmena osnovnih podataka, promena lozinke i slanje
// verifikacionog dokumenta. Tri odvojene forme na istoj stranici, svaka sa
// sopstvenom vrednošću polja 'akcija' da bi obrada znala koja je poslata.

require_once __DIR__ . '/../includes/autentifikacija.php';
require_once __DIR__ . '/../includes/validacija.php';
require_once __DIR__ . '/../includes/dokumenti.php';
require_once __DIR__ . '/../includes/funkcije.php';

zahtevaj_ulogu('igrac');

$pdo = konektuj_bazu();
$korisnik_id = (int) $_SESSION['korisnik_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_ispravan($_POST['csrf_token'] ?? null)) {
        postavi_poruku('danger', 'Nevažeća sesija. Osvežite stranicu i pokušajte ponovo.');
        preusmeri(KORENSKI_URL . '/igrac/profil.php');
    }

    $akcija = $_POST['akcija'] ?? '';

    // --- Izmena osnovnih podataka ---
    if ($akcija === 'podaci') {
        $ime = trim($_POST['ime'] ?? '');
        $prezime = trim($_POST['prezime'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefon = trim($_POST['telefon'] ?? '');

        // Isto pravilo kao u admin/korisnici.php: da li su ime i prezime
        // obavezni zavisi od uloge. Ovu stranicu otvara samo igrač (provera je
        // na vrhu fajla), pa su ovde uvek obavezni - odluka se ipak čita iz
        // iste funkcije, da o tome ne bi odlučivala dva mesta u kodu.
        $ime_je_obavezno = ime_obavezno_za_ulogu(trenutna_uloga());

        if (!ime_zadovoljava($ime, $ime_je_obavezno)) {
            postavi_poruku('danger', 'Ime mora imati 2-50 slova.');
        } elseif (!ime_zadovoljava($prezime, $ime_je_obavezno)) {
            postavi_poruku('danger', 'Prezime mora imati 2-50 slova.');
        } elseif (!validan_email($email)) {
            postavi_poruku('danger', 'Unesite ispravnu email adresu.');
        } elseif (!validan_telefon($telefon)) {
            postavi_poruku('danger', 'Unesite ispravan broj telefona.');
        } else {
            try {
                $provera = $pdo->prepare('SELECT id FROM korisnik WHERE email = ? AND id <> ?');
                $provera->execute([$email, $korisnik_id]);

                if ($provera->fetch()) {
                    postavi_poruku('danger', 'Ta email adresa je već zauzeta.');
                } else {
                    $upit = $pdo->prepare(
                        'UPDATE korisnik SET ime = ?, prezime = ?, email = ?, telefon = ? WHERE id = ?'
                    );
                    $upit->execute([$ime, $prezime, $email, $telefon !== '' ? $telefon : null, $korisnik_id]);
                    postavi_poruku('success', 'Podaci profila su sačuvani.');
                }
            } catch (PDOException $e) {
                upisi_gresku('Greška pri izmeni profila: ' . $e->getMessage());
                postavi_poruku('danger', 'Trenutno nije moguće sačuvati izmene.');
            }
        }
    }

    // --- Promena lozinke ---
    if ($akcija === 'lozinka') {
        $trenutna = $_POST['trenutna_lozinka'] ?? '';
        $nova = $_POST['nova_lozinka'] ?? '';
        $potvrda = $_POST['potvrda_nove_lozinke'] ?? '';

        try {
            $upit = $pdo->prepare('SELECT lozinka_hash FROM korisnik WHERE id = ?');
            $upit->execute([$korisnik_id]);
            $hes = $upit->fetchColumn();

            if (!$hes || !password_verify($trenutna, $hes)) {
                postavi_poruku('danger', 'Trenutna lozinka nije ispravna.');
            } elseif (!validna_lozinka($nova)) {
                postavi_poruku('danger', 'Nova lozinka mora imati bar 6 znakova, jedno slovo i jednu cifru.');
            } elseif ($nova !== $potvrda) {
                postavi_poruku('danger', 'Nova lozinka i potvrda se ne poklapaju.');
            } else {
                $azuriraj = $pdo->prepare('UPDATE korisnik SET lozinka_hash = ? WHERE id = ?');
                $azuriraj->execute([password_hash($nova, PASSWORD_DEFAULT), $korisnik_id]);
                postavi_poruku('success', 'Lozinka je izmenjena.');
            }
        } catch (PDOException $e) {
            upisi_gresku('Greška pri promeni lozinke: ' . $e->getMessage());
            postavi_poruku('danger', 'Trenutno nije moguće izmeniti lozinku.');
        }
    }

    // --- Slanje verifikacionog dokumenta ---
    if ($akcija === 'dokument') {
        $rezultat = obradi_upload_dokumenta($_FILES['dokument'] ?? []);

        if (!$rezultat['uspeh']) {
            postavi_poruku('danger', $rezultat['poruka']);
        } else {
            try {
                $upit = $pdo->prepare(
                    'INSERT INTO dokument (korisnik_id, originalni_naziv, naziv_na_serveru, mime_tip, velicina_bajtova)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $upit->execute([
                    $korisnik_id,
                    $rezultat['zapis']['originalni_naziv'],
                    $rezultat['zapis']['naziv_na_serveru'],
                    $rezultat['zapis']['mime_tip'],
                    $rezultat['zapis']['velicina_bajtova'],
                ]);
                postavi_poruku('success', 'Dokument je poslat na proveru operateru.');
            } catch (PDOException $e) {
                upisi_gresku('Greška pri upisu dokumenta u bazu: ' . $e->getMessage());
                postavi_poruku('danger', 'Trenutno nije moguće sačuvati podatke o dokumentu.');
            }
        }
    }

    preusmeri(KORENSKI_URL . '/igrac/profil.php');
}

try {
    $upit = $pdo->prepare('SELECT * FROM korisnik WHERE id = ?');
    $upit->execute([$korisnik_id]);
    $korisnik = $upit->fetch();

    $upitDok = $pdo->prepare('SELECT * FROM dokument WHERE korisnik_id = ? ORDER BY datum_slanja DESC LIMIT 1');
    $upitDok->execute([$korisnik_id]);
    $poslednji_dokument = $upitDok->fetch() ?: null;
} catch (PDOException $e) {
    upisi_gresku('Greška pri učitavanju profila: ' . $e->getMessage());
    $korisnik = null;
    $poslednji_dokument = null;
}

$csrf_token = generisi_csrf_token();
$naslov_stranice = 'Profil';
require_once __DIR__ . '/../includes/zaglavlje.php';
?>

<h1 class="mb-4">Profil</h1>

<?php prikazi_poruke(); ?>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="mb-3">Podaci profila</h2>
                <form method="post" novalidate id="forma-profil-podaci">
                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                    <input type="hidden" name="akcija" value="podaci">

                    <div class="mb-2">
                        <label class="form-label">Korisničko ime</label>
                        <input type="text" class="form-control" value="<?= bezbedno($korisnik['korisnicko_ime']) ?>" disabled>
                    </div>

                    <?php
                    // Igraču su ime i prezime obavezni (vezuju se za verifikacioni
                    // dokument), pa je required uvek postavljen. Uslov se ipak
                    // ispisuje iz iste funkcije kao i provera na serveru.
                    $ime_obavezno_na_formi = ime_obavezno_za_ulogu(trenutna_uloga());
                    ?>
                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <label for="ime" class="form-label">Ime</label>
                            <input type="text" class="form-control" id="ime" name="ime" minlength="2" maxlength="50"
                                   <?= $ime_obavezno_na_formi ? 'required' : '' ?>
                                   value="<?= bezbedno($korisnik['ime']) ?>">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label for="prezime" class="form-label">Prezime</label>
                            <input type="text" class="form-control" id="prezime" name="prezime" minlength="2" maxlength="50"
                                   <?= $ime_obavezno_na_formi ? 'required' : '' ?>
                                   value="<?= bezbedno($korisnik['prezime']) ?>">
                        </div>
                    </div>

                    <div class="mb-2">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required maxlength="120"
                               value="<?= bezbedno($korisnik['email']) ?>">
                    </div>

                    <div class="mb-2">
                        <label for="telefon" class="form-label">Telefon</label>
                        <input type="text" class="form-control" id="telefon" name="telefon" maxlength="20"
                               value="<?= bezbedno($korisnik['telefon'] ?? '') ?>">
                    </div>

                    <button type="submit" class="btn btn-primary mt-2">Sačuvaj podatke</button>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="mb-3">Promena lozinke</h2>
                <form method="post" novalidate id="forma-profil-lozinka">
                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                    <input type="hidden" name="akcija" value="lozinka">

                    <div class="mb-2">
                        <label for="trenutna_lozinka" class="form-label">Trenutna lozinka</label>
                        <input type="password" class="form-control" id="trenutna_lozinka" name="trenutna_lozinka" required>
                    </div>
                    <div class="mb-2">
                        <label for="nova_lozinka" class="form-label">Nova lozinka</label>
                        <input type="password" class="form-control" id="nova_lozinka" name="nova_lozinka" required minlength="6">
                    </div>
                    <div class="mb-2">
                        <label for="potvrda_nove_lozinke" class="form-label">Potvrda nove lozinke</label>
                        <input type="password" class="form-control" id="potvrda_nove_lozinke" name="potvrda_nove_lozinke" required minlength="6">
                        <div class="invalid-feedback" id="greska-potvrda-lozinke"></div>
                    </div>

                    <button type="submit" class="btn btn-primary mt-2">Promeni lozinku</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="mb-3">
                    Verifikacija naloga
                    <?php // Status poslednjeg dokumenta stoji uz naslov, istim rečnikom kao kod operatera ?>
                    <?php if ($korisnik['verifikovan']): ?>
                        <span class="status status-odobren ms-1">verifikovan</span>
                    <?php elseif ($poslednji_dokument): ?>
                        <span class="status status-<?= bezbedno($poslednji_dokument['status']) ?> ms-1"><?= bezbedno($poslednji_dokument['status']) ?></span>
                    <?php endif; ?>
                </h2>

                <?php if ($korisnik['verifikovan']): ?>
                    <div class="alert alert-success mb-0">Vaš nalog je verifikovan.</div>
                <?php elseif ($poslednji_dokument && $poslednji_dokument['status'] === 'na_cekanju'): ?>
                    <div class="alert alert-info">
                        Poslali ste dokument <strong><?= bezbedno($poslednji_dokument['originalni_naziv']) ?></strong>
                        (<?= bezbedno(formatiraj_datum_vreme($poslednji_dokument['datum_slanja'])) ?>).
                        Čeka se provera operatera.
                    </div>
                <?php else: ?>
                    <?php if ($poslednji_dokument && $poslednji_dokument['status'] === 'odbijen'): ?>
                        <div class="alert alert-danger">
                            Prethodni dokument je odbijen<?= $poslednji_dokument['napomena'] ? ': ' . bezbedno($poslednji_dokument['napomena']) : '.' ?>
                            Pošaljite novi.
                        </div>
                    <?php else: ?>
                        <p class="text-muted small">
                            Pošaljite skeniranu ličnu kartu (JPG, PNG ili PDF, do
                            <?= number_format(MAX_VELICINA_UPLOADA / (1024 * 1024), 0) ?> MB) da bismo mogli da verifikujemo vaš nalog.
                        </p>
                    <?php endif; ?>

                    <form method="post" enctype="multipart/form-data" novalidate id="forma-profil-dokument">
                        <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                        <input type="hidden" name="akcija" value="dokument">

                        <div class="mb-2">
                            <label for="dokument" class="form-label">Dokument</label>
                            <input type="file" class="form-control" id="dokument" name="dokument" required
                                   accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf">
                        </div>

                        <button type="submit" class="btn btn-primary mt-2">Pošalji na proveru</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$dodatne_skripte = ['validacija.js', 'lozinka.js'];
require_once __DIR__ . '/../includes/podnozje.php';
?>

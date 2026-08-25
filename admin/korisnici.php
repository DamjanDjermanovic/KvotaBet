<?php
// CRUD nad tabelom korisnik i dodela uloga. Blokiranje naloga je samo izmena
// polja status - isti mehanizam koji prijava.php već proverava.
//
// Admin ne sme da blokira ili obriše sopstveni nalog - to bi ga zaključalo
// van sistema bez ikoga ko bi mogao da ga vrati.

require_once __DIR__ . '/../includes/autentifikacija.php';
require_once __DIR__ . '/../includes/validacija.php';
require_once __DIR__ . '/../includes/funkcije.php';

zahtevaj_ulogu('admin');

$pdo = konektuj_bazu();
$moj_id = (int) $_SESSION['korisnik_id'];
$statusi = ['aktivan', 'blokiran', 'na_cekanju'];

// Uloge se čitaju pre obrade forme, a ne tek pred ispis: obrada mora da zna
// naziv izabrane uloge da bi znala da li su ime i prezime obavezni.
try {
    $uloge = $pdo->query('SELECT id, naziv FROM uloga ORDER BY id')->fetchAll();
} catch (PDOException $e) {
    upisi_gresku('Greška pri učitavanju uloga: ' . $e->getMessage());
    $uloge = [];
}

// Naziv uloge po njenom id-ju, da se u obradi ne pretražuje niz svaki put
$nazivi_uloga = array_column($uloge, 'naziv', 'id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_ispravan($_POST['csrf_token'] ?? null)) {
        postavi_poruku('danger', 'Nevažeća sesija. Osvežite stranicu i pokušajte ponovo.');
        preusmeri(KORENSKI_URL . '/admin/korisnici.php');
    }

    $akcija = $_POST['akcija'] ?? '';
    $id = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : null;

    if ($akcija === 'sacuvaj') {
        $ime = trim($_POST['ime'] ?? '');
        $prezime = trim($_POST['prezime'] ?? '');
        $korisnicko_ime = trim($_POST['korisnicko_ime'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $datum_rodjenja = trim($_POST['datum_rodjenja'] ?? '');
        $uloga_id = ctype_digit((string) ($_POST['uloga_id'] ?? '')) ? (int) $_POST['uloga_id'] : null;
        $status = $_POST['status'] ?? 'aktivan';
        $lozinka = $_POST['lozinka'] ?? '';

        // Ime i prezime su obavezni samo za igrača; operateru i administratoru
        // smeju ostati prazni, ali ako su uneti, moraju biti ispravni.
        $ime_je_obavezno = ime_obavezno_za_ulogu($nazivi_uloga[$uloga_id] ?? null);

        $ime_ispravno = ime_zadovoljava($ime, $ime_je_obavezno)
            && ime_zadovoljava($prezime, $ime_je_obavezno);

        if (!$ime_ispravno) {
            postavi_poruku('danger', $ime_je_obavezno
                ? 'Ime i prezime moraju imati 2-50 slova.'
                : 'Ako unosite ime ili prezime, moraju imati 2-50 slova.');
        } elseif (!validno_korisnicko_ime($korisnicko_ime)) {
            postavi_poruku('danger', 'Korisničko ime: 3-30 znakova, slova, cifre i donja crta.');
        } elseif (!validan_email($email)) {
            postavi_poruku('danger', 'Unesite ispravnu email adresu.');
        } elseif (!validan_datum_rodjenja($datum_rodjenja)) {
            postavi_poruku('danger', 'Unesite ispravan datum rođenja.');
        } elseif ($uloga_id === null) {
            postavi_poruku('danger', 'Izaberite ulogu.');
        } elseif (!in_array($status, $statusi, true)) {
            postavi_poruku('danger', 'Neispravan status.');
        } elseif ($id === null && !validna_lozinka($lozinka)) {
            postavi_poruku('danger', 'Lozinka mora imati bar 6 znakova, jedno slovo i jednu cifru.');
        } elseif ($id !== null && $id === $moj_id && $status !== 'aktivan') {
            postavi_poruku('danger', 'Ne možete sami sebi promeniti status naloga.');
        } elseif ($id !== null && $id === $moj_id && $uloga_id !== (int) ($_POST['trenutna_uloga_id'] ?? $uloga_id)) {
            postavi_poruku('danger', 'Ne možete sami sebi promeniti ulogu.');
        } else {
            try {
                $provera = $pdo->prepare('SELECT id FROM korisnik WHERE (email = ? OR korisnicko_ime = ?) AND id <> ?');
                $provera->execute([$email, $korisnicko_ime, $id ?? 0]);

                if ($provera->fetch()) {
                    postavi_poruku('danger', 'Email ili korisničko ime je već zauzeto.');
                } elseif ($id === null) {
                    $upit = $pdo->prepare(
                        'INSERT INTO korisnik (uloga_id, ime, prezime, korisnicko_ime, email, lozinka_hash, datum_rodjenja, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $upit->execute([
                        $uloga_id, $ime, $prezime, $korisnicko_ime, $email,
                        password_hash($lozinka, PASSWORD_DEFAULT), $datum_rodjenja, $status,
                    ]);
                    postavi_poruku('success', 'Korisnik je dodat.');
                } else {
                    if ($lozinka !== '') {
                        if (!validna_lozinka($lozinka)) {
                            throw new RuntimeException('Nova lozinka mora imati bar 6 znakova, jedno slovo i jednu cifru.');
                        }
                        $upit = $pdo->prepare(
                            'UPDATE korisnik SET ime=?, prezime=?, korisnicko_ime=?, email=?, datum_rodjenja=?, uloga_id=?, status=?, lozinka_hash=? WHERE id=?'
                        );
                        $upit->execute([$ime, $prezime, $korisnicko_ime, $email, $datum_rodjenja, $uloga_id, $status, password_hash($lozinka, PASSWORD_DEFAULT), $id]);
                    } else {
                        $upit = $pdo->prepare(
                            'UPDATE korisnik SET ime=?, prezime=?, korisnicko_ime=?, email=?, datum_rodjenja=?, uloga_id=?, status=? WHERE id=?'
                        );
                        $upit->execute([$ime, $prezime, $korisnicko_ime, $email, $datum_rodjenja, $uloga_id, $status, $id]);
                    }
                    postavi_poruku('success', 'Korisnik je izmenjen.');
                }
            } catch (RuntimeException $e) {
                postavi_poruku('danger', $e->getMessage());
            } catch (PDOException $e) {
                upisi_gresku('Greška pri čuvanju korisnika: ' . $e->getMessage());
                postavi_poruku('danger', 'Trenutno nije moguće sačuvati korisnika.');
            }
        }
    } elseif ($akcija === 'blokiraj' && $id !== null) {
        if ($id === $moj_id) {
            postavi_poruku('danger', 'Ne možete blokirati sopstveni nalog.');
        } else {
            try {
                $upit = $pdo->prepare("UPDATE korisnik SET status = 'blokiran' WHERE id = ?");
                $upit->execute([$id]);
                postavi_poruku('success', 'Nalog je blokiran.');
            } catch (PDOException $e) {
                upisi_gresku('Greška pri blokiranju korisnika: ' . $e->getMessage());
                postavi_poruku('danger', 'Trenutno nije moguće blokirati nalog.');
            }
        }
    } elseif ($akcija === 'odblokiraj' && $id !== null) {
        try {
            $upit = $pdo->prepare("UPDATE korisnik SET status = 'aktivan' WHERE id = ?");
            $upit->execute([$id]);
            postavi_poruku('success', 'Nalog je odblokiran.');
        } catch (PDOException $e) {
            upisi_gresku('Greška pri odblokiranju korisnika: ' . $e->getMessage());
            postavi_poruku('danger', 'Trenutno nije moguće odblokirati nalog.');
        }
    } elseif ($akcija === 'obrisi' && $id !== null) {
        if ($id === $moj_id) {
            postavi_poruku('danger', 'Ne možete obrisati sopstveni nalog.');
        } else {
            try {
                $upit = $pdo->prepare('DELETE FROM korisnik WHERE id = ?');
                $upit->execute([$id]);
                postavi_poruku('success', 'Korisnik je obrisan.');
            } catch (PDOException $e) {
                if ((int) $e->getCode() === 23000) {
                    postavi_poruku('danger', 'Korisnik ima tikete i ne može biti obrisan. Blokirajte nalog umesto brisanja.');
                } else {
                    upisi_gresku('Greška pri brisanju korisnika: ' . $e->getMessage());
                    postavi_poruku('danger', 'Trenutno nije moguće obrisati korisnika.');
                }
            }
        }
    }

    preusmeri(KORENSKI_URL . '/admin/korisnici.php');
}

$korisnik_za_izmenu = null;
$izmeni_id = isset($_GET['izmeni']) && ctype_digit($_GET['izmeni']) ? (int) $_GET['izmeni'] : null;

try {
    if ($izmeni_id !== null) {
        $upit = $pdo->prepare('SELECT * FROM korisnik WHERE id = ?');
        $upit->execute([$izmeni_id]);
        $korisnik_za_izmenu = $upit->fetch() ?: null;
    }

    $korisnici = $pdo->query(
        'SELECT k.*, u.naziv AS uloga_naziv
         FROM korisnik k JOIN uloga u ON u.id = k.uloga_id
         ORDER BY k.datum_registracije DESC'
    )->fetchAll();
} catch (PDOException $e) {
    upisi_gresku('Greška pri učitavanju korisnika: ' . $e->getMessage());
    $korisnici = [];
}

// Napomena: boju statusa naloga više ne bira PHP - nosi je klasa
// .status-<vrednost> iz css/style.css, ista kao za tikete i dokumenta.

$csrf_token = generisi_csrf_token();
$naslov_stranice = 'Korisnici';
require_once __DIR__ . '/../includes/zaglavlje.php';
?>

<h1 class="mb-4">Korisnici</h1>

<?php prikazi_poruke(); ?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="mb-3"><?= $korisnik_za_izmenu ? 'Izmena korisnika' : 'Novi korisnik' ?></h2>
                <?php
                // Admin ovom formom otvara tuđe naloge, pa mu pregledač ne sme
                // nuditi sopstvene upamćene podatke - autocomplete="off" na
                // formi, a na polju za lozinku "new-password", jer pregledači
                // za lozinke po pravilu ne poštuju "off".
                //
                // Da li su ime i prezime obavezni zavisi od izabrane uloge.
                // Prvi ispis ide po zatečenoj ulozi, a js/korisnici.js to menja
                // pri promeni izbora; server proverava isto pravilo ponovo.
                $uloga_na_formi = $nazivi_uloga[$korisnik_za_izmenu['uloga_id'] ?? 0] ?? reset($nazivi_uloga);
                $ime_obavezno_na_formi = ime_obavezno_za_ulogu($uloga_na_formi ?: null);
                ?>
                <form method="post" id="forma-korisnik" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                    <input type="hidden" name="akcija" value="sacuvaj">
                    <?php if ($korisnik_za_izmenu): ?>
                        <input type="hidden" name="id" value="<?= (int) $korisnik_za_izmenu['id'] ?>">
                        <input type="hidden" name="trenutna_uloga_id" value="<?= (int) $korisnik_za_izmenu['uloga_id'] ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-6 mb-2">
                            <label for="ime" class="form-label">
                                Ime <span class="text-muted oznaka-neobavezno" <?= $ime_obavezno_na_formi ? 'hidden' : '' ?>>(opciono)</span>
                            </label>
                            <input type="text" class="form-control" id="ime" name="ime" minlength="2" maxlength="50"
                                   autocomplete="off" <?= $ime_obavezno_na_formi ? 'required' : '' ?>
                                   value="<?= bezbedno($korisnik_za_izmenu['ime'] ?? '') ?>">
                        </div>
                        <div class="col-6 mb-2">
                            <label for="prezime" class="form-label">
                                Prezime <span class="text-muted oznaka-neobavezno" <?= $ime_obavezno_na_formi ? 'hidden' : '' ?>>(opciono)</span>
                            </label>
                            <input type="text" class="form-control" id="prezime" name="prezime" minlength="2" maxlength="50"
                                   autocomplete="off" <?= $ime_obavezno_na_formi ? 'required' : '' ?>
                                   value="<?= bezbedno($korisnik_za_izmenu['prezime'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="mb-2">
                        <label for="korisnicko_ime" class="form-label">Korisničko ime</label>
                        <input type="text" class="form-control" id="korisnicko_ime" name="korisnicko_ime" required pattern="[A-Za-z0-9_]{3,30}"
                               value="<?= bezbedno($korisnik_za_izmenu['korisnicko_ime'] ?? '') ?>">
                    </div>

                    <div class="mb-2">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required maxlength="120"
                               value="<?= bezbedno($korisnik_za_izmenu['email'] ?? '') ?>">
                    </div>

                    <div class="mb-2">
                        <label for="datum_rodjenja" class="form-label">Datum rođenja</label>
                        <input type="date" class="form-control" id="datum_rodjenja" name="datum_rodjenja" required
                               value="<?= bezbedno($korisnik_za_izmenu['datum_rodjenja'] ?? '') ?>">
                    </div>

                    <div class="row">
                        <div class="col-6 mb-2">
                            <label for="uloga_id" class="form-label">Uloga</label>
                            <select class="form-select" id="uloga_id" name="uloga_id" required
                                <?= ($korisnik_za_izmenu && (int) $korisnik_za_izmenu['id'] === $moj_id) ? 'disabled' : '' ?>>
                                <?php foreach ($uloge as $uloga): ?>
                                    <?php // data-trazi-ime govori skripti da li ta uloga traži ime i prezime ?>
                                    <option value="<?= (int) $uloga['id'] ?>"
                                        data-trazi-ime="<?= ime_obavezno_za_ulogu($uloga['naziv']) ? '1' : '0' ?>"
                                        <?= isset($korisnik_za_izmenu['uloga_id']) && (int) $korisnik_za_izmenu['uloga_id'] === (int) $uloga['id'] ? 'selected' : '' ?>>
                                        <?= bezbedno($uloga['naziv']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 mb-2">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status"
                                <?= ($korisnik_za_izmenu && (int) $korisnik_za_izmenu['id'] === $moj_id) ? 'disabled' : '' ?>>
                                <?php foreach ($statusi as $status): ?>
                                    <option value="<?= $status ?>" <?= ($korisnik_za_izmenu['status'] ?? 'aktivan') === $status ? 'selected' : '' ?>><?= bezbedno($status) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="lozinka" class="form-label">
                            <?= $korisnik_za_izmenu ? 'Nova lozinka' : 'Lozinka' ?>
                            <?php if ($korisnik_za_izmenu): ?><span class="text-muted">(ostavite prazno da je ne menjate)</span><?php endif; ?>
                        </label>
                        <input type="password" class="form-control" id="lozinka" name="lozinka" minlength="6"
                               pattern="(?=.*[A-Za-z])(?=.*[0-9]).{6,}" title="Bar 6 znakova, jedno slovo i jedna cifra"
                               autocomplete="new-password"
                               <?= $korisnik_za_izmenu ? '' : 'required' ?>>
                    </div>

                    <button type="submit" class="btn btn-primary w-100"><?= $korisnik_za_izmenu ? 'Sačuvaj izmene' : 'Dodaj korisnika' ?></button>
                    <?php if ($korisnik_za_izmenu): ?>
                        <a href="<?= KORENSKI_URL ?>/admin/korisnici.php" class="btn btn-outline-secondary w-100 mt-2">Otkaži izmenu</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th>Korisnik</th>
                        <th>Uloga</th>
                        <th>Status</th>
                        <th>Registrovan</th>
                        <th class="text-end">Akcije</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($korisnici as $korisnik): ?>
                        <tr>
                            <td>
                                <?php // Operater i admin ne moraju imati ime - tada u prvom redu stoji korisničko ime ?>
                                <div><?= bezbedno(ime_za_prikaz($korisnik['ime'], $korisnik['prezime'], $korisnik['korisnicko_ime'])) ?></div>
                                <div class="text-muted small"><?= bezbedno($korisnik['korisnicko_ime']) ?> · <?= bezbedno($korisnik['email']) ?></div>
                                <?php // Oznaka sopstvenog naloga stoji uz ime, a ne među dugmadima, jer govori ko je korisnik, a ne šta se sa njim može ?>
                                <?php if ((int) $korisnik['id'] === $moj_id): ?>
                                    <span class="status status-ja mt-1">to ste vi</span>
                                <?php endif; ?>
                            </td>
                            <td><?= bezbedno($korisnik['uloga_naziv']) ?></td>
                            <td><span class="status status-<?= bezbedno($korisnik['status']) ?>"><?= bezbedno($korisnik['status']) ?></span></td>
                            <td><?= bezbedno(formatiraj_datum($korisnik['datum_registracije'])) ?></td>
                            <td class="text-end">
                                <a href="<?= KORENSKI_URL ?>/admin/korisnici.php?izmeni=<?= (int) $korisnik['id'] ?>" class="btn btn-sm btn-outline-primary">Izmeni</a>

                                <?php if ((int) $korisnik['id'] !== $moj_id): ?>
                                    <?php if ($korisnik['status'] === 'blokiran'): ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                                            <input type="hidden" name="akcija" value="odblokiraj">
                                            <input type="hidden" name="id" value="<?= (int) $korisnik['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success">Odblokiraj</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Blokirati ovaj nalog?');">
                                            <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                                            <input type="hidden" name="akcija" value="blokiraj">
                                            <input type="hidden" name="id" value="<?= (int) $korisnik['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-warning">Blokiraj</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Trajno obrisati ovog korisnika?');">
                                        <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                                        <input type="hidden" name="akcija" value="obrisi">
                                        <input type="hidden" name="id" value="<?= (int) $korisnik['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Obriši</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($korisnici)): ?>
                        <tr>
                            <td colspan="5">
                                <div class="prazno">
                                    <i class="bi bi-people" aria-hidden="true"></i>
                                    <div class="naslov-praznog">Nema nijednog korisnika</div>
                                    <p class="mb-0">Novi nalog se dodaje formom levo.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$dodatne_skripte = ['korisnici.js', 'lozinka.js'];
require_once __DIR__ . '/../includes/podnozje.php';
?>

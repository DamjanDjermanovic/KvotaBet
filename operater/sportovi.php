<?php
// CRUD nad tabelom sport. Brisanje sporta u bazi kaskadno briše i njegove
// lige, timove, događaje, kvote i tipove igre (FK ON DELETE CASCADE) - zato
// se pred brisanjem korisniku eksplicitno skreće pažnja na to.

require_once __DIR__ . '/../includes/autentifikacija.php';
require_once __DIR__ . '/../includes/funkcije.php';

zahtevaj_ulogu('operater', 'admin');

$pdo = konektuj_bazu();

// Spisak ikonica koje operater može izabrati. Slobodan unos je zamenjen
// padajućim menijem jer je pre toga bilo moguće upisati bilo šta - naziv
// ikonice koja ne postoji davao je prazno mesto u ponudi, a nije bilo načina
// da se to primeti pre nego što stranica ode korisnicima.
//
// Bootstrap Icons nema ikonice za pojedinačne sportove, pa su ovde birane
// one koje se mogu pročitati kao vrsta takmičenja (isto zapažanje stoji i u
// komentaru uz test podatke u baza/schema.sql). Uz svaku stoji opis, jer
// naziv klase sam po sebi ne govori šta se vidi na ekranu.
//
// Ovaj niz je ujedno i spisak dozvoljenih vrednosti pri proveri na serveru:
// padajući meni je samo udobnost, a poslati podatak se ne sme uzeti zdravo
// za gotovo - korisnik ga može promeniti pre slanja.
$dozvoljene_ikonice = [
    'bi-trophy-fill'           => 'Trofej — opšte takmičenje',
    'bi-award-fill'            => 'Medalja — takmičenje sa plasmanom',
    'bi-people-fill'           => 'Ekipa — ekipni sportovi',
    'bi-person-fill'           => 'Pojedinac — pojedinačni sportovi',
    'bi-bullseye'              => 'Meta — pikado, streljaštvo',
    'bi-bicycle'               => 'Bicikl — biciklizam',
    'bi-car-front-fill'        => 'Automobil — auto-moto sport',
    'bi-stopwatch-fill'        => 'Štoperica — atletika, trke',
    'bi-water'                 => 'Voda — plivanje, vaterpolo',
    'bi-snow'                  => 'Pahulja — zimski sportovi',
    'bi-flag-fill'             => 'Zastavica — golf, trke',
    'bi-lightning-charge-fill' => 'Munja — brzi sportovi',
    'bi-shield-fill'           => 'Grb — klupska takmičenja',
    'bi-globe2'                => 'Globus — međunarodna takmičenja',
    'bi-controller'            => 'Gejmpad — e-sport',
    'bi-dice-5-fill'           => 'Kocka — igre na sreću',
    'bi-suit-spade-fill'       => 'Karte — kartaške igre',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_ispravan($_POST['csrf_token'] ?? null)) {
        postavi_poruku('danger', 'Nevažeća sesija. Osvežite stranicu i pokušajte ponovo.');
        preusmeri(KORENSKI_URL . '/operater/sportovi.php');
    }

    $akcija = $_POST['akcija'] ?? '';

    if ($akcija === 'sacuvaj') {
        $id = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : null;
        $naziv = trim($_POST['naziv'] ?? '');
        $ikonica = trim($_POST['ikonica'] ?? '');
        $aktivan = isset($_POST['aktivan']) ? 1 : 0;

        // Ikonica je opciona, ali ako je poslata, mora biti sa spiska. Padajući
        // meni je samo udobnost - poslata vrednost se svejedno proverava ovde.
        $ikonica_dozvoljena = $ikonica === '' || isset($dozvoljene_ikonice[$ikonica]);

        // Izuzetak: vrednost koja već stoji u bazi za taj sport. Mogla je biti
        // upisana dok je polje bilo slobodan unos, i forma je nudi kao zatečenu
        // stavku - izmena naziva sporta ne sme da padne zbog nje.
        if (!$ikonica_dozvoljena && $id !== null) {
            try {
                $provera_ikonice = $pdo->prepare('SELECT ikonica FROM sport WHERE id = ?');
                $provera_ikonice->execute([$id]);
                $ikonica_dozvoljena = $provera_ikonice->fetchColumn() === $ikonica;
            } catch (PDOException $e) {
                upisi_gresku('Greška pri proveri zatečene ikonice sporta: ' . $e->getMessage());
            }
        }

        if (mb_strlen($naziv) < 2 || mb_strlen($naziv) > 50) {
            postavi_poruku('danger', 'Naziv sporta mora imati 2-50 znakova.');
        } elseif (!$ikonica_dozvoljena) {
            postavi_poruku('danger', 'Izaberite ikonicu sa ponuđenog spiska.');
        } else {
            try {
                if ($id === null) {
                    $upit = $pdo->prepare('INSERT INTO sport (naziv, ikonica, aktivan) VALUES (?, ?, ?)');
                    $upit->execute([$naziv, $ikonica !== '' ? $ikonica : null, $aktivan]);
                    postavi_poruku('success', 'Sport je dodat.');
                } else {
                    $upit = $pdo->prepare('UPDATE sport SET naziv = ?, ikonica = ?, aktivan = ? WHERE id = ?');
                    $upit->execute([$naziv, $ikonica !== '' ? $ikonica : null, $aktivan, $id]);
                    postavi_poruku('success', 'Sport je izmenjen.');
                }
            } catch (PDOException $e) {
                if ((int) $e->getCode() === 23000) {
                    postavi_poruku('danger', 'Sport sa tim nazivom već postoji.');
                } else {
                    upisi_gresku('Greška pri čuvanju sporta: ' . $e->getMessage());
                    postavi_poruku('danger', 'Trenutno nije moguće sačuvati sport.');
                }
            }
        }
    } elseif ($akcija === 'obrisi' && ctype_digit((string) ($_POST['id'] ?? ''))) {
        try {
            $upit = $pdo->prepare('DELETE FROM sport WHERE id = ?');
            $upit->execute([(int) $_POST['id']]);
            postavi_poruku('success', 'Sport je obrisan, zajedno sa svim ligama, timovima i događajima koji su mu pripadali.');
        } catch (PDOException $e) {
            upisi_gresku('Greška pri brisanju sporta: ' . $e->getMessage());
            postavi_poruku('danger', 'Trenutno nije moguće obrisati sport.');
        }
    }

    preusmeri(KORENSKI_URL . '/operater/sportovi.php');
}

$sport_za_izmenu = null;
$izmeni_id = isset($_GET['izmeni']) && ctype_digit($_GET['izmeni']) ? (int) $_GET['izmeni'] : null;

try {
    if ($izmeni_id !== null) {
        $upit = $pdo->prepare('SELECT * FROM sport WHERE id = ?');
        $upit->execute([$izmeni_id]);
        $sport_za_izmenu = $upit->fetch() ?: null;
    }

    $sportovi = $pdo->query('SELECT * FROM sport ORDER BY naziv')->fetchAll();
} catch (PDOException $e) {
    upisi_gresku('Greška pri učitavanju sportova: ' . $e->getMessage());
    $sportovi = [];
}

$csrf_token = generisi_csrf_token();
$naslov_stranice = 'Sportovi';
require_once __DIR__ . '/../includes/zaglavlje.php';
?>

<h1 class="mb-4">Sportovi</h1>

<?php prikazi_poruke(); ?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="mb-3"><?= $sport_za_izmenu ? 'Izmena sporta' : 'Novi sport' ?></h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                    <input type="hidden" name="akcija" value="sacuvaj">
                    <?php if ($sport_za_izmenu): ?>
                        <input type="hidden" name="id" value="<?= (int) $sport_za_izmenu['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-2">
                        <label for="naziv" class="form-label">Naziv</label>
                        <input type="text" class="form-control" id="naziv" name="naziv" required minlength="2" maxlength="50"
                               value="<?= bezbedno($sport_za_izmenu['naziv'] ?? '') ?>">
                    </div>

                    <?php
                    // Ikonica sporta koji se menja. Ako je u bazi ostala neka
                    // vrednost koja nije na spisku (upisana dok je polje bilo
                    // slobodan unos), dodaje se kao posebna stavka, da izmena
                    // naziva sporta ne bi usput nečujno promenila i ikonicu.
                    $izabrana_ikonica = $sport_za_izmenu['ikonica'] ?? '';
                    $ikonica_van_spiska = $izabrana_ikonica !== '' && !isset($dozvoljene_ikonice[$izabrana_ikonica]);
                    ?>
                    <div class="mb-2">
                        <label for="ikonica" class="form-label">Bootstrap ikonica <span class="text-muted">(opciono)</span></label>
                        <div class="input-group">
                            <?php // Pregled izabrane ikonice; JS ga menja pri svakom izboru ?>
                            <span class="input-group-text pregled-ikonice" id="pregled-ikonice">
                                <i class="bi <?= bezbedno($izabrana_ikonica !== '' ? $izabrana_ikonica : 'bi-dash') ?>" aria-hidden="true"></i>
                            </span>
                            <select class="form-select" id="ikonica" name="ikonica" data-pregled="pregled-ikonice">
                                <option value="">— bez ikonice —</option>
                                <?php if ($ikonica_van_spiska): ?>
                                    <option value="<?= bezbedno($izabrana_ikonica) ?>" selected>
                                        <?= bezbedno($izabrana_ikonica) ?> (postojeća, van spiska)
                                    </option>
                                <?php endif; ?>
                                <?php foreach ($dozvoljene_ikonice as $klasa => $opis): ?>
                                    <option value="<?= bezbedno($klasa) ?>" <?= $izabrana_ikonica === $klasa ? 'selected' : '' ?>>
                                        <?= bezbedno($opis) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="aktivan" name="aktivan"
                               <?= ($sport_za_izmenu === null || $sport_za_izmenu['aktivan']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="aktivan">Aktivan (prikazuje se u ponudi)</label>
                    </div>

                    <button type="submit" class="btn btn-primary w-100"><?= $sport_za_izmenu ? 'Sačuvaj izmene' : 'Dodaj sport' ?></button>
                    <?php if ($sport_za_izmenu): ?>
                        <a href="<?= KORENSKI_URL ?>/operater/sportovi.php" class="btn btn-outline-secondary w-100 mt-2">Otkaži izmenu</a>
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
                        <th>Naziv</th>
                        <th>Ikonica</th>
                        <th>Aktivan</th>
                        <th class="text-end">Akcije</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sportovi as $sport): ?>
                        <tr>
                            <td><?= bezbedno($sport['naziv']) ?></td>
                            <?php // Uz naziv klase ide i sama ikonica, da operater odmah vidi šta je uneo ?>
                            <td>
                                <?php if (!empty($sport['ikonica'])): ?>
                                    <i class="bi <?= bezbedno($sport['ikonica']) ?>" aria-hidden="true"></i>
                                <?php endif; ?>
                                <code><?= bezbedno($sport['ikonica'] ?? '') ?></code>
                            </td>
                            <td><?= $sport['aktivan'] ? '<span class="status status-aktivan">da</span>' : '<span class="status status-storniran">ne</span>' ?></td>
                            <td class="text-end">
                                <a href="<?= KORENSKI_URL ?>/operater/lige.php?sport_id=<?= (int) $sport['id'] ?>" class="btn btn-sm btn-outline-secondary">Lige</a>
                                <a href="<?= KORENSKI_URL ?>/operater/sportovi.php?izmeni=<?= (int) $sport['id'] ?>" class="btn btn-sm btn-outline-primary">Izmeni</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Brisanje sporta trajno briše i sve njegove lige, timove, događaje i kvote. Nastaviti?');">
                                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                                    <input type="hidden" name="akcija" value="obrisi">
                                    <input type="hidden" name="id" value="<?= (int) $sport['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Obriši</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($sportovi)): ?>
                        <tr>
                            <td colspan="4">
                                <div class="prazno">
                                    <i class="bi bi-trophy-fill" aria-hidden="true"></i>
                                    <div class="naslov-praznog">Nema unetih sportova</div>
                                    <p class="mb-0">Sport je prvi korak — bez njega nema ni liga, ni timova, ni ponude.</p>
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
$dodatne_skripte = ['operater.js'];
require_once __DIR__ . '/../includes/podnozje.php';
?>

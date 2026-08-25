<?php
// CRUD nad tabelom dogadjaj. Liga događaja se ne bira posebno - izvodi se iz
// lige zajedničke oba izabrana tima, čime je nemoguće napraviti utakmicu
// između timova iz dve različite lige.
//
// Statusi "zavrsen" i "otkazan" se NE mogu postaviti odavde. Njih postavlja
// isključivo operater/rezultati.php, koji uz promenu statusa u istoj transakciji
// baze oceni stavke i isplati dobitke. Kad bi se status menjao i ovde, događaj
// bi mogao da završi kao obrađen a da tiketi zauvek ostanu "aktivan".
// Iz istog razloga se već obrađen događaj uopšte ne može menjati.

require_once __DIR__ . '/../includes/autentifikacija.php';
require_once __DIR__ . '/../includes/funkcije.php';

zahtevaj_ulogu('operater', 'admin');

$pdo = konektuj_bazu();
$dozvoljeni_statusi = ['najavljen', 'u_toku'];
$obradjeni_statusi = ['zavrsen', 'otkazan'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_ispravan($_POST['csrf_token'] ?? null)) {
        postavi_poruku('danger', 'Nevažeća sesija. Osvežite stranicu i pokušajte ponovo.');
        preusmeri(KORENSKI_URL . '/operater/dogadjaji.php');
    }

    $akcija = $_POST['akcija'] ?? '';

    if ($akcija === 'sacuvaj') {
        $id = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : null;
        $domacin_id = ctype_digit((string) ($_POST['domacin_id'] ?? '')) ? (int) $_POST['domacin_id'] : null;
        $gost_id = ctype_digit((string) ($_POST['gost_id'] ?? '')) ? (int) $_POST['gost_id'] : null;
        $vreme_pocetka = trim($_POST['vreme_pocetka'] ?? '');
        $status = $_POST['status'] ?? 'najavljen';

        if ($domacin_id === null || $gost_id === null) {
            postavi_poruku('danger', 'Izaberite oba tima.');
        } elseif ($domacin_id === $gost_id) {
            postavi_poruku('danger', 'Domaćin i gost ne mogu biti isti tim.');
        } elseif ($vreme_pocetka === '' || strtotime($vreme_pocetka) === false) {
            postavi_poruku('danger', 'Unesite ispravno vreme početka.');
        } elseif (!in_array($status, $dozvoljeni_statusi, true)) {
            postavi_poruku('danger', 'Status se ovde može postaviti samo na "najavljen" ili "u_toku". Za zaključivanje i otkazivanje koristite stranicu Rezultati.');
        } else {
            try {
                // Već obrađen događaj se ne dira: tiketi su rešeni po njegovom
                // rezultatu, pa bi izmena timova, vremena ili statusa učinila
                // te tikete neobjašnjivim
                if ($id !== null) {
                    $provera = $pdo->prepare('SELECT status FROM dogadjaj WHERE id = ?');
                    $provera->execute([$id]);
                    $postojeci_status = (string) $provera->fetchColumn();

                    if (in_array($postojeci_status, $obradjeni_statusi, true)) {
                        throw new RuntimeException('Događaj je već obrađen i više se ne može menjati.');
                    }
                }

                // Liga se izvodi iz timova, ne unosi se ručno - sprečava
                // nedoslednost tipa "domaćin iz fudbala, gost iz košarke"
                $upitTimova = $pdo->prepare('SELECT id, liga_id FROM tim WHERE id IN (?, ?)');
                $upitTimova->execute([$domacin_id, $gost_id]);
                $timovi = $upitTimova->fetchAll();

                if (count($timovi) !== 2 || $timovi[0]['liga_id'] !== $timovi[1]['liga_id']) {
                    postavi_poruku('danger', 'Oba tima moraju pripadati istoj ligi.');
                } else {
                    $liga_id = (int) $timovi[0]['liga_id'];
                    $vreme_za_bazu = date('Y-m-d H:i:s', strtotime($vreme_pocetka));

                    if ($id === null) {
                        $upit = $pdo->prepare(
                            'INSERT INTO dogadjaj (liga_id, domacin_id, gost_id, vreme_pocetka, status, kreirao_id)
                             VALUES (?, ?, ?, ?, ?, ?)'
                        );
                        $upit->execute([$liga_id, $domacin_id, $gost_id, $vreme_za_bazu, $status, (int) $_SESSION['korisnik_id']]);
                        postavi_poruku('success', 'Događaj je dodat.');
                    } else {
                        $upit = $pdo->prepare(
                            'UPDATE dogadjaj SET liga_id = ?, domacin_id = ?, gost_id = ?, vreme_pocetka = ?, status = ? WHERE id = ?'
                        );
                        $upit->execute([$liga_id, $domacin_id, $gost_id, $vreme_za_bazu, $status, $id]);
                        postavi_poruku('success', 'Događaj je izmenjen.');
                    }
                }
            } catch (PDOException $e) {
                // PDOException se hvata pre RuntimeException jer je njegov naslednik
                upisi_gresku('Greška pri čuvanju događaja: ' . $e->getMessage());
                postavi_poruku('danger', 'Trenutno nije moguće sačuvati događaj.');
            } catch (RuntimeException $e) {
                postavi_poruku('danger', $e->getMessage());
            }
        }
    } elseif ($akcija === 'obrisi' && ctype_digit((string) ($_POST['id'] ?? ''))) {
        try {
            $upit = $pdo->prepare('DELETE FROM dogadjaj WHERE id = ?');
            $upit->execute([(int) $_POST['id']]);
            postavi_poruku('success', 'Događaj je obrisan.');
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                postavi_poruku('danger', 'Događaj se ne može obrisati jer su na njegove kvote već uplaćeni tiketi. Umesto brisanja, promenite status na "otkazan".');
            } else {
                upisi_gresku('Greška pri brisanju događaja: ' . $e->getMessage());
                postavi_poruku('danger', 'Trenutno nije moguće obrisati događaj.');
            }
        }
    }

    preusmeri(KORENSKI_URL . '/operater/dogadjaji.php');
}

$dogadjaj_za_izmenu = null;
$izmeni_id = isset($_GET['izmeni']) && ctype_digit($_GET['izmeni']) ? (int) $_GET['izmeni'] : null;

try {
    $timovi = $pdo->query(
        'SELECT t.id, t.naziv, t.liga_id, l.naziv AS liga_naziv, s.naziv AS sport_naziv
         FROM tim t JOIN liga l ON l.id = t.liga_id JOIN sport s ON s.id = l.sport_id
         ORDER BY s.naziv, l.naziv, t.naziv'
    )->fetchAll();

    if ($izmeni_id !== null) {
        $upit = $pdo->prepare('SELECT * FROM dogadjaj WHERE id = ?');
        $upit->execute([$izmeni_id]);
        $dogadjaj_za_izmenu = $upit->fetch() ?: null;
    }

    $dogadjaji = $pdo->query(
        "SELECT d.*, l.naziv AS liga_naziv, s.naziv AS sport_naziv,
                td.naziv AS domacin_naziv, tg.naziv AS gost_naziv,
                (SELECT COUNT(*) FROM kvota k WHERE k.dogadjaj_id = d.id) AS broj_kvota
         FROM dogadjaj d
         JOIN liga l ON l.id = d.liga_id
         JOIN sport s ON s.id = l.sport_id
         JOIN tim td ON td.id = d.domacin_id
         JOIN tim tg ON tg.id = d.gost_id
         ORDER BY d.vreme_pocetka DESC"
    )->fetchAll();
} catch (PDOException $e) {
    upisi_gresku('Greška pri učitavanju događaja: ' . $e->getMessage());
    $timovi = [];
    $dogadjaji = [];
}

// Boja bedža statusa događaja u tabeli
function boja_statusa_dogadjaja(string $status): string
{
    return match ($status) {
        'najavljen' => 'primary',
        'u_toku' => 'warning',
        'zavrsen' => 'success',
        'otkazan' => 'secondary',
        default => 'secondary',
    };
}

$csrf_token = generisi_csrf_token();
$naslov_stranice = 'Događaji';
require_once __DIR__ . '/../includes/zaglavlje.php';
?>

<h1 class="h3 mb-4">Događaji</h1>

<?php prikazi_poruke(); ?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3"><?= $dogadjaj_za_izmenu ? 'Izmena događaja' : 'Novi događaj' ?></h2>
                <form method="post" id="forma-dogadjaj">
                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                    <input type="hidden" name="akcija" value="sacuvaj">
                    <?php if ($dogadjaj_za_izmenu): ?>
                        <input type="hidden" name="id" value="<?= (int) $dogadjaj_za_izmenu['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-2">
                        <label for="domacin_id" class="form-label">Domaćin</label>
                        <select class="form-select" id="domacin_id" name="domacin_id" required>
                            <option value="">Izaberite tim</option>
                            <?php foreach ($timovi as $tim): ?>
                                <option value="<?= (int) $tim['id'] ?>" data-liga="<?= (int) $tim['liga_id'] ?>"
                                    <?= $dogadjaj_za_izmenu && (int) $dogadjaj_za_izmenu['domacin_id'] === (int) $tim['id'] ? 'selected' : '' ?>>
                                    <?= bezbedno($tim['sport_naziv']) ?> / <?= bezbedno($tim['liga_naziv']) ?> — <?= bezbedno($tim['naziv']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label for="gost_id" class="form-label">Gost</label>
                        <select class="form-select" id="gost_id" name="gost_id" required>
                            <option value="">Izaberite tim</option>
                            <?php foreach ($timovi as $tim): ?>
                                <option value="<?= (int) $tim['id'] ?>" data-liga="<?= (int) $tim['liga_id'] ?>"
                                    <?= $dogadjaj_za_izmenu && (int) $dogadjaj_za_izmenu['gost_id'] === (int) $tim['id'] ? 'selected' : '' ?>>
                                    <?= bezbedno($tim['sport_naziv']) ?> / <?= bezbedno($tim['liga_naziv']) ?> — <?= bezbedno($tim['naziv']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Oba tima moraju biti iz iste lige.</div>
                    </div>

                    <div class="mb-2">
                        <label for="vreme_pocetka" class="form-label">Vreme početka</label>
                        <input type="datetime-local" class="form-control" id="vreme_pocetka" name="vreme_pocetka" required
                               value="<?= $dogadjaj_za_izmenu ? bezbedno(date('Y-m-d\TH:i', strtotime($dogadjaj_za_izmenu['vreme_pocetka']))) : '' ?>">
                    </div>

                    <div class="mb-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <?php foreach ($dozvoljeni_statusi as $status): ?>
                                <option value="<?= $status ?>" <?= ($dogadjaj_za_izmenu['status'] ?? 'najavljen') === $status ? 'selected' : '' ?>>
                                    <?= bezbedno($status) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            Zaključivanje i otkazivanje se rade na stranici
                            <a href="<?= KORENSKI_URL ?>/operater/rezultati.php">Rezultati</a>, jer uz status
                            treba obraditi i tikete.
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100"><?= $dogadjaj_za_izmenu ? 'Sačuvaj izmene' : 'Dodaj događaj' ?></button>
                    <?php if ($dogadjaj_za_izmenu): ?>
                        <a href="<?= KORENSKI_URL ?>/operater/dogadjaji.php" class="btn btn-outline-secondary w-100 mt-2">Otkaži izmenu</a>
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
                        <th>Događaj</th>
                        <th>Liga</th>
                        <th>Vreme</th>
                        <th>Status</th>
                        <th>Kvote</th>
                        <th class="text-end">Akcije</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dogadjaji as $dogadjaj): ?>
                        <?php $obradjen = in_array($dogadjaj['status'], $obradjeni_statusi, true); ?>
                        <tr>
                            <td><?= bezbedno($dogadjaj['domacin_naziv']) ?> — <?= bezbedno($dogadjaj['gost_naziv']) ?></td>
                            <td><?= bezbedno($dogadjaj['sport_naziv']) ?> / <?= bezbedno($dogadjaj['liga_naziv']) ?></td>
                            <td><?= bezbedno(formatiraj_datum_vreme($dogadjaj['vreme_pocetka'])) ?></td>
                            <td>
                                <span class="badge text-bg-<?= boja_statusa_dogadjaja($dogadjaj['status']) ?>"><?= bezbedno($dogadjaj['status']) ?></span>
                                <?php if ($dogadjaj['status'] === 'zavrsen' && $dogadjaj['golova_domacin'] !== null): ?>
                                    <div class="small mt-1"><?= (int) $dogadjaj['golova_domacin'] ?> : <?= (int) $dogadjaj['golova_gost'] ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) $dogadjaj['broj_kvota'] ?></td>
                            <td class="text-end">
                                <a href="<?= KORENSKI_URL ?>/operater/kvote.php?dogadjaj_id=<?= (int) $dogadjaj['id'] ?>" class="btn btn-sm btn-outline-secondary">Kvote</a>

                                <?php if ($obradjen): ?>
                                    <?php // Obrađen događaj se ne menja i ne briše - tiketi su rešeni po njemu ?>
                                    <span class="badge text-bg-light text-dark">obrađen</span>
                                <?php else: ?>
                                    <a href="<?= KORENSKI_URL ?>/operater/dogadjaji.php?izmeni=<?= (int) $dogadjaj['id'] ?>" class="btn btn-sm btn-outline-primary">Izmeni</a>
                                    <a href="<?= KORENSKI_URL ?>/operater/rezultati.php" class="btn btn-sm btn-outline-success">Rezultat</a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Obrisati ovaj događaj?');">
                                        <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                                        <input type="hidden" name="akcija" value="obrisi">
                                        <input type="hidden" name="id" value="<?= (int) $dogadjaj['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Obriši</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($dogadjaji)): ?>
                        <tr><td colspan="6" class="text-muted">Nema unetih događaja.</td></tr>
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

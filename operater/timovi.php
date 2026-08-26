<?php
// CRUD nad tabelom tim. Za tenis "tim" predstavlja pojedinačnog igrača -
// tabela je ista, samo se drugačije čita u ponudi.

require_once __DIR__ . '/../includes/autentifikacija.php';
require_once __DIR__ . '/../includes/funkcije.php';

zahtevaj_ulogu('operater', 'admin');

$pdo = konektuj_bazu();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_ispravan($_POST['csrf_token'] ?? null)) {
        postavi_poruku('danger', 'Nevažeća sesija. Osvežite stranicu i pokušajte ponovo.');
        preusmeri(KORENSKI_URL . '/operater/timovi.php');
    }

    $akcija = $_POST['akcija'] ?? '';

    if ($akcija === 'sacuvaj') {
        $id = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : null;
        $liga_id = ctype_digit((string) ($_POST['liga_id'] ?? '')) ? (int) $_POST['liga_id'] : null;
        $naziv = trim($_POST['naziv'] ?? '');

        // Naziv istog tima kod The Odds API-ja. Polje je opciono i tiče se
        // isključivo pomoćnog uvoza (operater/uvoz.php) - prazna vrednost se
        // upisuje kao NULL, da UNIQUE (liga_id, api_naziv) ne bi smetao svim
        // timovima koji se nikad ne uvoze.
        $api_naziv = trim($_POST['api_naziv'] ?? '');
        $api_naziv = $api_naziv !== '' ? $api_naziv : null;

        if ($liga_id === null) {
            postavi_poruku('danger', 'Izaberite ligu.');
        } elseif (mb_strlen($naziv) < 2 || mb_strlen($naziv) > 80) {
            postavi_poruku('danger', 'Naziv tima mora imati 2-80 znakova.');
        } elseif ($api_naziv !== null && mb_strlen($api_naziv) > 80) {
            postavi_poruku('danger', 'Naziv kod servisa ne sme biti duži od 80 znakova.');
        } else {
            try {
                if ($id === null) {
                    $upit = $pdo->prepare('INSERT INTO tim (liga_id, naziv, api_naziv) VALUES (?, ?, ?)');
                    $upit->execute([$liga_id, $naziv, $api_naziv]);
                    postavi_poruku('success', 'Tim je dodat.');
                } else {
                    $upit = $pdo->prepare('UPDATE tim SET liga_id = ?, naziv = ?, api_naziv = ? WHERE id = ?');
                    $upit->execute([$liga_id, $naziv, $api_naziv, $id]);
                    postavi_poruku('success', 'Tim je izmenjen.');
                }
            } catch (PDOException $e) {
                if ((int) $e->getCode() === 23000) {
                    postavi_poruku('danger', 'Taj naziv kod servisa je već upisan drugom timu iste lige.');
                } else {
                    upisi_gresku('Greška pri čuvanju tima: ' . $e->getMessage());
                    postavi_poruku('danger', 'Trenutno nije moguće sačuvati tim.');
                }
            }
        }
    } elseif ($akcija === 'obrisi' && ctype_digit((string) ($_POST['id'] ?? ''))) {
        try {
            $upit = $pdo->prepare('DELETE FROM tim WHERE id = ?');
            $upit->execute([(int) $_POST['id']]);
            postavi_poruku('success', 'Tim je obrisan.');
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                postavi_poruku('danger', 'Tim se ne može obrisati jer već ima zakazane događaje. Obrišite prvo te događaje.');
            } else {
                upisi_gresku('Greška pri brisanju tima: ' . $e->getMessage());
                postavi_poruku('danger', 'Trenutno nije moguće obrisati tim.');
            }
        }
    }

    preusmeri(KORENSKI_URL . '/operater/timovi.php');
}

$tim_za_izmenu = null;
$izmeni_id = isset($_GET['izmeni']) && ctype_digit($_GET['izmeni']) ? (int) $_GET['izmeni'] : null;
$filter_liga_id = isset($_GET['liga_id']) && ctype_digit($_GET['liga_id']) ? (int) $_GET['liga_id'] : null;

try {
    $lige = $pdo->query(
        'SELECT l.id, l.naziv, s.naziv AS sport_naziv
         FROM liga l JOIN sport s ON s.id = l.sport_id
         ORDER BY s.naziv, l.naziv'
    )->fetchAll();

    if ($izmeni_id !== null) {
        $upit = $pdo->prepare('SELECT * FROM tim WHERE id = ?');
        $upit->execute([$izmeni_id]);
        $tim_za_izmenu = $upit->fetch() ?: null;
    }

    $sqlTimovi = 'SELECT t.*, l.naziv AS liga_naziv, s.naziv AS sport_naziv
                  FROM tim t JOIN liga l ON l.id = t.liga_id JOIN sport s ON s.id = l.sport_id';
    $parametri = [];
    if ($filter_liga_id !== null) {
        $sqlTimovi .= ' WHERE t.liga_id = ?';
        $parametri[] = $filter_liga_id;
    }
    $sqlTimovi .= ' ORDER BY s.naziv, l.naziv, t.naziv';

    $upitTimovi = $pdo->prepare($sqlTimovi);
    $upitTimovi->execute($parametri);
    $timovi = $upitTimovi->fetchAll();
} catch (PDOException $e) {
    upisi_gresku('Greška pri učitavanju timova: ' . $e->getMessage());
    $lige = [];
    $timovi = [];
}

$csrf_token = generisi_csrf_token();
$naslov_stranice = 'Timovi';
require_once __DIR__ . '/../includes/zaglavlje.php';
?>

<h1 class="mb-4">Timovi</h1>

<?php prikazi_poruke(); ?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="mb-3"><?= $tim_za_izmenu ? 'Izmena tima' : 'Novi tim' ?></h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                    <input type="hidden" name="akcija" value="sacuvaj">
                    <?php if ($tim_za_izmenu): ?>
                        <input type="hidden" name="id" value="<?= (int) $tim_za_izmenu['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-2">
                        <label for="liga_id" class="form-label">Liga</label>
                        <select class="form-select" id="liga_id" name="liga_id" required>
                            <option value="">Izaberite ligu</option>
                            <?php foreach ($lige as $liga): ?>
                                <?php
                                $izabrana = $tim_za_izmenu
                                    ? (int) $tim_za_izmenu['liga_id'] === (int) $liga['id']
                                    : $filter_liga_id === (int) $liga['id'];
                                ?>
                                <option value="<?= (int) $liga['id'] ?>" <?= $izabrana ? 'selected' : '' ?>>
                                    <?= bezbedno($liga['sport_naziv']) ?> — <?= bezbedno($liga['naziv']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label for="naziv" class="form-label">Naziv <span class="text-muted">(tim ili igrač)</span></label>
                        <input type="text" class="form-control" id="naziv" name="naziv" required minlength="2" maxlength="80"
                               value="<?= bezbedno($tim_za_izmenu['naziv'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label for="api_naziv" class="form-label">
                            Naziv kod servisa <span class="text-muted">(opciono)</span>
                        </label>
                        <input type="text" class="form-control" id="api_naziv" name="api_naziv" maxlength="80"
                               placeholder="npr. Red Star Belgrade"
                               value="<?= bezbedno($tim_za_izmenu['api_naziv'] ?? '') ?>">
                        <div class="form-text">
                            Popunjava se samo za timove koji učestvuju u
                            <a href="<?= KORENSKI_URL ?>/operater/uvoz.php">uvozu sa API-ja</a>; obično ga
                            uvoz sam upiše. Prazno polje briše vezu.
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100"><?= $tim_za_izmenu ? 'Sačuvaj izmene' : 'Dodaj tim' ?></button>
                    <?php if ($tim_za_izmenu): ?>
                        <a href="<?= KORENSKI_URL ?>/operater/timovi.php" class="btn btn-outline-secondary w-100 mt-2">Otkaži izmenu</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <?php if ($filter_liga_id !== null): ?>
            <a href="<?= KORENSKI_URL ?>/operater/timovi.php" class="btn btn-sm btn-outline-secondary mb-2">Prikaži sve timove</a>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th>Liga</th>
                        <th>Naziv</th>
                        <th>Kod servisa</th>
                        <th class="text-end">Akcije</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($timovi as $tim): ?>
                        <tr>
                            <td><?= bezbedno($tim['sport_naziv']) ?> — <?= bezbedno($tim['liga_naziv']) ?></td>
                            <td><?= bezbedno($tim['naziv']) ?></td>
                            <td class="small text-muted"><?= bezbedno($tim['api_naziv'] ?? '—') ?></td>
                            <td class="text-end">
                                <a href="<?= KORENSKI_URL ?>/operater/timovi.php?izmeni=<?= (int) $tim['id'] ?>" class="btn btn-sm btn-outline-primary">Izmeni</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Obrisati ovaj tim?');">
                                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                                    <input type="hidden" name="akcija" value="obrisi">
                                    <input type="hidden" name="id" value="<?= (int) $tim['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Obriši</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($timovi)): ?>
                        <tr>
                            <td colspan="4">
                                <div class="prazno">
                                    <i class="bi bi-people" aria-hidden="true"></i>
                                    <div class="naslov-praznog">Nema unetih timova</div>
                                    <p class="mb-0">Da bi se napravio događaj, ligi trebaju bar dva tima.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/podnozje.php'; ?>

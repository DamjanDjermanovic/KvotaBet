<?php
// CRUD nad tabelom liga. Brisanje lige kaskadno briše i njene timove i
// događaje (FK ON DELETE CASCADE).

require_once __DIR__ . '/../includes/autentifikacija.php';
require_once __DIR__ . '/../includes/funkcije.php';

zahtevaj_ulogu('operater', 'admin');

$pdo = konektuj_bazu();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_ispravan($_POST['csrf_token'] ?? null)) {
        postavi_poruku('danger', 'Nevažeća sesija. Osvežite stranicu i pokušajte ponovo.');
        preusmeri(KORENSKI_URL . '/operater/lige.php');
    }

    $akcija = $_POST['akcija'] ?? '';

    if ($akcija === 'sacuvaj') {
        $id = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : null;
        $sport_id = ctype_digit((string) ($_POST['sport_id'] ?? '')) ? (int) $_POST['sport_id'] : null;
        $naziv = trim($_POST['naziv'] ?? '');
        $drzava = trim($_POST['drzava'] ?? '');

        if ($sport_id === null) {
            postavi_poruku('danger', 'Izaberite sport.');
        } elseif (mb_strlen($naziv) < 2 || mb_strlen($naziv) > 80) {
            postavi_poruku('danger', 'Naziv lige mora imati 2-80 znakova.');
        } else {
            try {
                if ($id === null) {
                    $upit = $pdo->prepare('INSERT INTO liga (sport_id, naziv, drzava) VALUES (?, ?, ?)');
                    $upit->execute([$sport_id, $naziv, $drzava !== '' ? $drzava : null]);
                    postavi_poruku('success', 'Liga je dodata.');
                } else {
                    $upit = $pdo->prepare('UPDATE liga SET sport_id = ?, naziv = ?, drzava = ? WHERE id = ?');
                    $upit->execute([$sport_id, $naziv, $drzava !== '' ? $drzava : null, $id]);
                    postavi_poruku('success', 'Liga je izmenjena.');
                }
            } catch (PDOException $e) {
                upisi_gresku('Greška pri čuvanju lige: ' . $e->getMessage());
                postavi_poruku('danger', 'Trenutno nije moguće sačuvati ligu.');
            }
        }
    } elseif ($akcija === 'obrisi' && ctype_digit((string) ($_POST['id'] ?? ''))) {
        try {
            $upit = $pdo->prepare('DELETE FROM liga WHERE id = ?');
            $upit->execute([(int) $_POST['id']]);
            postavi_poruku('success', 'Liga je obrisana, zajedno sa svim timovima i događajima koji su joj pripadali.');
        } catch (PDOException $e) {
            upisi_gresku('Greška pri brisanju lige: ' . $e->getMessage());
            postavi_poruku('danger', 'Trenutno nije moguće obrisati ligu.');
        }
    }

    preusmeri(KORENSKI_URL . '/operater/lige.php');
}

$liga_za_izmenu = null;
$izmeni_id = isset($_GET['izmeni']) && ctype_digit($_GET['izmeni']) ? (int) $_GET['izmeni'] : null;
$filter_sport_id = isset($_GET['sport_id']) && ctype_digit($_GET['sport_id']) ? (int) $_GET['sport_id'] : null;

try {
    $sportovi = $pdo->query('SELECT id, naziv FROM sport ORDER BY naziv')->fetchAll();

    if ($izmeni_id !== null) {
        $upit = $pdo->prepare('SELECT * FROM liga WHERE id = ?');
        $upit->execute([$izmeni_id]);
        $liga_za_izmenu = $upit->fetch() ?: null;
    }

    $sqlLige = 'SELECT l.*, s.naziv AS sport_naziv FROM liga l JOIN sport s ON s.id = l.sport_id';
    $parametri = [];
    if ($filter_sport_id !== null) {
        $sqlLige .= ' WHERE l.sport_id = ?';
        $parametri[] = $filter_sport_id;
    }
    $sqlLige .= ' ORDER BY s.naziv, l.naziv';

    $upitLige = $pdo->prepare($sqlLige);
    $upitLige->execute($parametri);
    $lige = $upitLige->fetchAll();
} catch (PDOException $e) {
    upisi_gresku('Greška pri učitavanju liga: ' . $e->getMessage());
    $sportovi = [];
    $lige = [];
}

$csrf_token = generisi_csrf_token();
$naslov_stranice = 'Lige';
require_once __DIR__ . '/../includes/zaglavlje.php';
?>

<h1 class="mb-4">Lige</h1>

<?php prikazi_poruke(); ?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="mb-3"><?= $liga_za_izmenu ? 'Izmena lige' : 'Nova liga' ?></h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                    <input type="hidden" name="akcija" value="sacuvaj">
                    <?php if ($liga_za_izmenu): ?>
                        <input type="hidden" name="id" value="<?= (int) $liga_za_izmenu['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-2">
                        <label for="sport_id" class="form-label">Sport</label>
                        <select class="form-select" id="sport_id" name="sport_id" required>
                            <option value="">Izaberite sport</option>
                            <?php foreach ($sportovi as $sport): ?>
                                <?php
                                $izabran = $liga_za_izmenu
                                    ? (int) $liga_za_izmenu['sport_id'] === (int) $sport['id']
                                    : $filter_sport_id === (int) $sport['id'];
                                ?>
                                <option value="<?= (int) $sport['id'] ?>" <?= $izabran ? 'selected' : '' ?>><?= bezbedno($sport['naziv']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label for="naziv" class="form-label">Naziv</label>
                        <input type="text" class="form-control" id="naziv" name="naziv" required minlength="2" maxlength="80"
                               value="<?= bezbedno($liga_za_izmenu['naziv'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label for="drzava" class="form-label">Država <span class="text-muted">(opciono)</span></label>
                        <input type="text" class="form-control" id="drzava" name="drzava" maxlength="50"
                               value="<?= bezbedno($liga_za_izmenu['drzava'] ?? '') ?>">
                    </div>

                    <button type="submit" class="btn btn-primary w-100"><?= $liga_za_izmenu ? 'Sačuvaj izmene' : 'Dodaj ligu' ?></button>
                    <?php if ($liga_za_izmenu): ?>
                        <a href="<?= KORENSKI_URL ?>/operater/lige.php" class="btn btn-outline-secondary w-100 mt-2">Otkaži izmenu</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <?php if ($filter_sport_id !== null): ?>
            <a href="<?= KORENSKI_URL ?>/operater/lige.php" class="btn btn-sm btn-outline-secondary mb-2">Prikaži sve lige</a>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th>Sport</th>
                        <th>Naziv</th>
                        <th>Država</th>
                        <th class="text-end">Akcije</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lige as $liga): ?>
                        <tr>
                            <td><?= bezbedno($liga['sport_naziv']) ?></td>
                            <td><?= bezbedno($liga['naziv']) ?></td>
                            <td><?= bezbedno($liga['drzava'] ?? '—') ?></td>
                            <td class="text-end">
                                <a href="<?= KORENSKI_URL ?>/operater/timovi.php?liga_id=<?= (int) $liga['id'] ?>" class="btn btn-sm btn-outline-secondary">Timovi</a>
                                <a href="<?= KORENSKI_URL ?>/operater/lige.php?izmeni=<?= (int) $liga['id'] ?>" class="btn btn-sm btn-outline-primary">Izmeni</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Brisanje lige trajno briše i sve njene timove i događaje. Nastaviti?');">
                                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                                    <input type="hidden" name="akcija" value="obrisi">
                                    <input type="hidden" name="id" value="<?= (int) $liga['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Obriši</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($lige)): ?>
                        <tr>
                            <td colspan="4">
                                <div class="prazno">
                                    <i class="bi bi-trophy" aria-hidden="true"></i>
                                    <div class="naslov-praznog">Nema unetih liga</div>
                                    <p class="mb-0">Ligu dodajte formom levo, pa joj zatim unesite timove.</p>
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

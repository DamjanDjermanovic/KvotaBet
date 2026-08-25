<?php
// CRUD nad tabelom sport. Brisanje sporta u bazi kaskadno briše i njegove
// lige, timove, događaje, kvote i tipove igre (FK ON DELETE CASCADE) - zato
// se pred brisanjem korisniku eksplicitno skreće pažnja na to.

require_once __DIR__ . '/../includes/autentifikacija.php';
require_once __DIR__ . '/../includes/funkcije.php';

zahtevaj_ulogu('operater', 'admin');

$pdo = konektuj_bazu();

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

        if (mb_strlen($naziv) < 2 || mb_strlen($naziv) > 50) {
            postavi_poruku('danger', 'Naziv sporta mora imati 2-50 znakova.');
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

<h1 class="h3 mb-4">Sportovi</h1>

<?php prikazi_poruke(); ?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3"><?= $sport_za_izmenu ? 'Izmena sporta' : 'Novi sport' ?></h2>
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

                    <div class="mb-2">
                        <label for="ikonica" class="form-label">Bootstrap ikonica <span class="text-muted">(opciono)</span></label>
                        <input type="text" class="form-control" id="ikonica" name="ikonica" maxlength="50" placeholder="bi-dribbble"
                               value="<?= bezbedno($sport_za_izmenu['ikonica'] ?? '') ?>">
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
                            <td><code><?= bezbedno($sport['ikonica'] ?? '') ?></code></td>
                            <td><?= $sport['aktivan'] ? '<span class="badge text-bg-success">da</span>' : '<span class="badge text-bg-secondary">ne</span>' ?></td>
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
                        <tr><td colspan="4" class="text-muted">Nema unetih sportova.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/podnozje.php'; ?>

<?php
// CRUD nad tabelom uloga. Ima samo tri reda (igrac, operater, admin) na
// koje se oslanja cela aplikacija (zahtevaj_ulogu() poredi baš ove nazive),
// pa se brisanje uloge koja je u upotrebi oslanja na FK ograničenje baze.

require_once __DIR__ . '/../includes/autentifikacija.php';
require_once __DIR__ . '/../includes/funkcije.php';

zahtevaj_ulogu('admin');

$pdo = konektuj_bazu();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_ispravan($_POST['csrf_token'] ?? null)) {
        postavi_poruku('danger', 'Nevažeća sesija. Osvežite stranicu i pokušajte ponovo.');
        preusmeri(KORENSKI_URL . '/admin/uloge.php');
    }

    $akcija = $_POST['akcija'] ?? '';

    if ($akcija === 'sacuvaj') {
        $id = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : null;
        $naziv = trim($_POST['naziv'] ?? '');
        $opis = trim($_POST['opis'] ?? '');

        if (mb_strlen($naziv) < 2 || mb_strlen($naziv) > 30) {
            postavi_poruku('danger', 'Naziv uloge mora imati 2-30 znakova.');
        } else {
            try {
                if ($id === null) {
                    $upit = $pdo->prepare('INSERT INTO uloga (naziv, opis) VALUES (?, ?)');
                    $upit->execute([$naziv, $opis !== '' ? $opis : null]);
                    postavi_poruku('success', 'Uloga je dodata.');
                } else {
                    $upit = $pdo->prepare('UPDATE uloga SET naziv = ?, opis = ? WHERE id = ?');
                    $upit->execute([$naziv, $opis !== '' ? $opis : null, $id]);
                    postavi_poruku('success', 'Uloga je izmenjena. Ako ste promenili naziv, proverite da li se poklapa sa onim što zahtevaj_ulogu() očekuje u kodu.');
                }
            } catch (PDOException $e) {
                if ((int) $e->getCode() === 23000) {
                    postavi_poruku('danger', 'Uloga sa tim nazivom već postoji.');
                } else {
                    upisi_gresku('Greška pri čuvanju uloge: ' . $e->getMessage());
                    postavi_poruku('danger', 'Trenutno nije moguće sačuvati ulogu.');
                }
            }
        }
    } elseif ($akcija === 'obrisi' && ctype_digit((string) ($_POST['id'] ?? ''))) {
        try {
            $upit = $pdo->prepare('DELETE FROM uloga WHERE id = ?');
            $upit->execute([(int) $_POST['id']]);
            postavi_poruku('success', 'Uloga je obrisana.');
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                postavi_poruku('danger', 'Uloga se ne može obrisati jer je dodeljena postojećim korisnicima.');
            } else {
                upisi_gresku('Greška pri brisanju uloge: ' . $e->getMessage());
                postavi_poruku('danger', 'Trenutno nije moguće obrisati ulogu.');
            }
        }
    }

    preusmeri(KORENSKI_URL . '/admin/uloge.php');
}

$uloga_za_izmenu = null;
$izmeni_id = isset($_GET['izmeni']) && ctype_digit($_GET['izmeni']) ? (int) $_GET['izmeni'] : null;

try {
    if ($izmeni_id !== null) {
        $upit = $pdo->prepare('SELECT * FROM uloga WHERE id = ?');
        $upit->execute([$izmeni_id]);
        $uloga_za_izmenu = $upit->fetch() ?: null;
    }

    $uloge = $pdo->query(
        'SELECT u.*, (SELECT COUNT(*) FROM korisnik k WHERE k.uloga_id = u.id) AS broj_korisnika
         FROM uloga u ORDER BY u.id'
    )->fetchAll();
} catch (PDOException $e) {
    upisi_gresku('Greška pri učitavanju uloga: ' . $e->getMessage());
    $uloge = [];
}

$csrf_token = generisi_csrf_token();
$naslov_stranice = 'Uloge';
require_once __DIR__ . '/../includes/zaglavlje.php';
?>

<h1 class="mb-4">Uloge</h1>

<?php prikazi_poruke(); ?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="mb-3"><?= $uloga_za_izmenu ? 'Izmena uloge' : 'Nova uloga' ?></h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                    <input type="hidden" name="akcija" value="sacuvaj">
                    <?php if ($uloga_za_izmenu): ?>
                        <input type="hidden" name="id" value="<?= (int) $uloga_za_izmenu['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-2">
                        <label for="naziv" class="form-label">Naziv</label>
                        <input type="text" class="form-control" id="naziv" name="naziv" required minlength="2" maxlength="30"
                               value="<?= bezbedno($uloga_za_izmenu['naziv'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label for="opis" class="form-label">Opis <span class="text-muted">(opciono)</span></label>
                        <input type="text" class="form-control" id="opis" name="opis" maxlength="255"
                               value="<?= bezbedno($uloga_za_izmenu['opis'] ?? '') ?>">
                    </div>

                    <button type="submit" class="btn btn-primary w-100"><?= $uloga_za_izmenu ? 'Sačuvaj izmene' : 'Dodaj ulogu' ?></button>
                    <?php if ($uloga_za_izmenu): ?>
                        <a href="<?= KORENSKI_URL ?>/admin/uloge.php" class="btn btn-outline-secondary w-100 mt-2">Otkaži izmenu</a>
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
                        <th>Opis</th>
                        <th>Korisnika</th>
                        <th class="text-end">Akcije</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($uloge as $uloga): ?>
                        <tr>
                            <td><?= bezbedno($uloga['naziv']) ?></td>
                            <td><?= bezbedno($uloga['opis'] ?? '—') ?></td>
                            <td><?= (int) $uloga['broj_korisnika'] ?></td>
                            <td class="text-end">
                                <a href="<?= KORENSKI_URL ?>/admin/uloge.php?izmeni=<?= (int) $uloga['id'] ?>" class="btn btn-sm btn-outline-primary">Izmeni</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Obrisati ovu ulogu?');">
                                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                                    <input type="hidden" name="akcija" value="obrisi">
                                    <input type="hidden" name="id" value="<?= (int) $uloga['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" <?= $uloga['broj_korisnika'] > 0 ? 'disabled title="Ima korisnika sa ovom ulogom"' : '' ?>>Obriši</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/podnozje.php'; ?>

<?php
// CRUD nad tabelom kvota, uvek u kontekstu jednog događaja (?dogadjaj_id=).
// Ponuđeni tipovi igre su ograničeni na sport tog događaja i na one koji već
// nisu iskorišćeni (UNIQUE (dogadjaj_id, tip_igre_id) u bazi).

require_once __DIR__ . '/../includes/autentifikacija.php';
require_once __DIR__ . '/../includes/funkcije.php';

zahtevaj_ulogu('operater', 'admin');

$pdo = konektuj_bazu();

$dogadjaj_id = isset($_GET['dogadjaj_id']) && ctype_digit($_GET['dogadjaj_id']) ? (int) $_GET['dogadjaj_id'] : null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dogadjaj_id = isset($_POST['dogadjaj_id']) && ctype_digit($_POST['dogadjaj_id']) ? (int) $_POST['dogadjaj_id'] : $dogadjaj_id;
}

if ($dogadjaj_id === null) {
    preusmeri(KORENSKI_URL . '/operater/dogadjaji.php');
}

try {
    $upitDogadjaj = $pdo->prepare(
        'SELECT d.*, l.naziv AS liga_naziv, s.id AS sport_id, s.naziv AS sport_naziv,
                td.naziv AS domacin_naziv, tg.naziv AS gost_naziv
         FROM dogadjaj d
         JOIN liga l ON l.id = d.liga_id
         JOIN sport s ON s.id = l.sport_id
         JOIN tim td ON td.id = d.domacin_id
         JOIN tim tg ON tg.id = d.gost_id
         WHERE d.id = ?'
    );
    $upitDogadjaj->execute([$dogadjaj_id]);
    $dogadjaj = $upitDogadjaj->fetch();
} catch (PDOException $e) {
    upisi_gresku('Greška pri učitavanju događaja: ' . $e->getMessage());
    $dogadjaj = false;
}

if (!$dogadjaj) {
    preusmeri(KORENSKI_URL . '/operater/dogadjaji.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_ispravan($_POST['csrf_token'] ?? null)) {
        postavi_poruku('danger', 'Nevažeća sesija. Osvežite stranicu i pokušajte ponovo.');
        preusmeri(KORENSKI_URL . '/operater/kvote.php?dogadjaj_id=' . $dogadjaj_id);
    }

    $akcija = $_POST['akcija'] ?? '';

    if ($akcija === 'sacuvaj') {
        $id = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : null;
        $tip_igre_id = ctype_digit((string) ($_POST['tip_igre_id'] ?? '')) ? (int) $_POST['tip_igre_id'] : null;
        $vrednost_unos = str_replace(',', '.', trim($_POST['vrednost'] ?? ''));
        $aktivna = isset($_POST['aktivna']) ? 1 : 0;

        if ($id === null && $tip_igre_id === null) {
            postavi_poruku('danger', 'Izaberite tip igre.');
        } elseif (!is_numeric($vrednost_unos) || (float) $vrednost_unos < 1.01) {
            // 1.01 je donja granica i u bazi (CHECK chk_kvota_vrednost)
            postavi_poruku('danger', 'Kvota mora biti broj veći ili jednak 1.01.');
        } else {
            $vrednost = number_format((float) $vrednost_unos, 2, '.', '');

            try {
                if ($id === null) {
                    $upit = $pdo->prepare('INSERT INTO kvota (dogadjaj_id, tip_igre_id, vrednost, aktivna) VALUES (?, ?, ?, ?)');
                    $upit->execute([$dogadjaj_id, $tip_igre_id, $vrednost, $aktivna]);
                    postavi_poruku('success', 'Kvota je dodata.');
                } else {
                    // Poslovno pravilo 5: menja se samo vrednost i aktivnost kvote,
                    // već uplaćeni tiketi čuvaju svoj snimak u tiket_stavka.kvota_vrednost
                    $upit = $pdo->prepare('UPDATE kvota SET vrednost = ?, aktivna = ? WHERE id = ? AND dogadjaj_id = ?');
                    $upit->execute([$vrednost, $aktivna, $id, $dogadjaj_id]);
                    postavi_poruku('success', 'Kvota je izmenjena. Već uplaćeni tiketi zadržavaju staru vrednost.');
                }
            } catch (PDOException $e) {
                if ((int) $e->getCode() === 23000) {
                    postavi_poruku('danger', 'Ovaj tip igre već ima kvotu na ovom događaju.');
                } else {
                    upisi_gresku('Greška pri čuvanju kvote: ' . $e->getMessage());
                    postavi_poruku('danger', 'Trenutno nije moguće sačuvati kvotu.');
                }
            }
        }
    } elseif ($akcija === 'obrisi' && ctype_digit((string) ($_POST['id'] ?? ''))) {
        try {
            $upit = $pdo->prepare('DELETE FROM kvota WHERE id = ? AND dogadjaj_id = ?');
            $upit->execute([(int) $_POST['id'], $dogadjaj_id]);
            postavi_poruku('success', 'Kvota je obrisana.');
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                postavi_poruku('danger', 'Kvota se ne može obrisati jer je već upotrebljena na tiketu. Deaktivirajte je umesto brisanja.');
            } else {
                upisi_gresku('Greška pri brisanju kvote: ' . $e->getMessage());
                postavi_poruku('danger', 'Trenutno nije moguće obrisati kvotu.');
            }
        }
    }

    preusmeri(KORENSKI_URL . '/operater/kvote.php?dogadjaj_id=' . $dogadjaj_id);
}

try {
    $upitTipovi = $pdo->prepare('SELECT id, oznaka, naziv FROM tip_igre WHERE sport_id = ? ORDER BY id');
    $upitTipovi->execute([(int) $dogadjaj['sport_id']]);
    $svi_tipovi = $upitTipovi->fetchAll();

    $upitKvote = $pdo->prepare(
        'SELECT k.*, ti.oznaka, ti.naziv AS tip_naziv
         FROM kvota k JOIN tip_igre ti ON ti.id = k.tip_igre_id
         WHERE k.dogadjaj_id = ? ORDER BY ti.id'
    );
    $upitKvote->execute([$dogadjaj_id]);
    $kvote = $upitKvote->fetchAll();
} catch (PDOException $e) {
    upisi_gresku('Greška pri učitavanju kvota: ' . $e->getMessage());
    $svi_tipovi = [];
    $kvote = [];
}

$iskorisceni_tipovi = array_column($kvote, 'tip_igre_id');
$slobodni_tipovi = array_filter($svi_tipovi, function ($tip) use ($iskorisceni_tipovi) {
    return !in_array((int) $tip['id'], array_map('intval', $iskorisceni_tipovi), true);
});

$csrf_token = generisi_csrf_token();
$naslov_stranice = 'Kvote';
require_once __DIR__ . '/../includes/zaglavlje.php';
?>

<h1 class="h3 mb-1">Kvote</h1>
<p class="text-muted mb-4">
    <?= bezbedno($dogadjaj['domacin_naziv']) ?> — <?= bezbedno($dogadjaj['gost_naziv']) ?>
    <span class="mx-1">·</span> <?= bezbedno($dogadjaj['sport_naziv']) ?> / <?= bezbedno($dogadjaj['liga_naziv']) ?>
    <span class="mx-1">·</span> <?= bezbedno(formatiraj_datum_vreme($dogadjaj['vreme_pocetka'])) ?>
</p>

<a href="<?= KORENSKI_URL ?>/operater/dogadjaji.php" class="btn btn-sm btn-outline-secondary mb-4">&laquo; Nazad na događaje</a>

<?php prikazi_poruke(); ?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">Nova kvota</h2>
                <?php if (empty($slobodni_tipovi)): ?>
                    <p class="text-muted small mb-0">Svi tipovi igre za ovaj sport već imaju kvotu na ovom događaju.</p>
                <?php else: ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                        <input type="hidden" name="akcija" value="sacuvaj">
                        <input type="hidden" name="dogadjaj_id" value="<?= $dogadjaj_id ?>">

                        <div class="mb-2">
                            <label for="tip_igre_id" class="form-label">Tip igre</label>
                            <select class="form-select" id="tip_igre_id" name="tip_igre_id" required>
                                <option value="">Izaberite</option>
                                <?php foreach ($slobodni_tipovi as $tip): ?>
                                    <option value="<?= (int) $tip['id'] ?>"><?= bezbedno($tip['oznaka']) ?> — <?= bezbedno($tip['naziv']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-2">
                            <label for="vrednost" class="form-label">Vrednost kvote</label>
                            <input type="number" class="form-control" id="vrednost" name="vrednost" required step="0.01" min="1.01" value="1.50">
                        </div>

                        <div class="form-check mb-3">
                            <input type="checkbox" class="form-check-input" id="aktivna" name="aktivna" checked>
                            <label class="form-check-label" for="aktivna">Aktivna (prikazuje se u ponudi)</label>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Dodaj kvotu</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th>Tip igre</th>
                        <th>Vrednost i aktivnost</th>
                        <th class="text-end">Akcije</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kvote as $kvota): ?>
                        <tr>
                            <td><?= bezbedno($kvota['oznaka']) ?> — <?= bezbedno($kvota['tip_naziv']) ?></td>
                            <td>
                                <form method="post" class="d-flex align-items-center gap-2">
                                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                                    <input type="hidden" name="akcija" value="sacuvaj">
                                    <input type="hidden" name="dogadjaj_id" value="<?= $dogadjaj_id ?>">
                                    <input type="hidden" name="id" value="<?= (int) $kvota['id'] ?>">
                                    <input type="number" class="form-control form-control-sm" style="width:6.5rem" name="vrednost"
                                           step="0.01" min="1.01" value="<?= number_format((float) $kvota['vrednost'], 2, '.', '') ?>" required>
                                    <div class="form-check form-switch mb-0">
                                        <input type="checkbox" class="form-check-input" name="aktivna" <?= $kvota['aktivna'] ? 'checked' : '' ?>>
                                        <label class="form-check-label small">aktivna</label>
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-outline-primary">Sačuvaj</button>
                                </form>
                            </td>
                            <td class="text-end">
                                <form method="post" onsubmit="return confirm('Obrisati ovu kvotu?');">
                                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                                    <input type="hidden" name="akcija" value="obrisi">
                                    <input type="hidden" name="dogadjaj_id" value="<?= $dogadjaj_id ?>">
                                    <input type="hidden" name="id" value="<?= (int) $kvota['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Obriši</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($kvote)): ?>
                        <tr><td colspan="3" class="text-muted">Nema unetih kvota za ovaj događaj.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/podnozje.php'; ?>

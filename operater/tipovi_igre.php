<?php
// CRUD nad šifarnikom tip_igre.
//
// Oznaka nije samo natpis na dugmetu: po njoj funkcija tip_igre_pogodjen()
// odlučuje da li je stavka tiketa pogođena. Zato se pri unosu i izmeni
// proverava da li je oznaka među onima koje sistem ume da oceni, i ako nije,
// operater dobija jasno upozorenje.

require_once __DIR__ . '/../includes/autentifikacija.php';
require_once __DIR__ . '/../includes/funkcije.php';
require_once __DIR__ . '/../includes/rezultati.php';

zahtevaj_ulogu('operater', 'admin');

$pdo = konektuj_bazu();
$poznate_oznake = poznate_oznake_tipova();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_ispravan($_POST['csrf_token'] ?? null)) {
        postavi_poruku('danger', 'Nevažeća sesija. Osvežite stranicu i pokušajte ponovo.');
        preusmeri(KORENSKI_URL . '/operater/tipovi_igre.php');
    }

    $akcija = $_POST['akcija'] ?? '';

    if ($akcija === 'sacuvaj') {
        $id = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : null;
        $sport_id = ctype_digit((string) ($_POST['sport_id'] ?? '')) ? (int) $_POST['sport_id'] : null;
        $oznaka = trim($_POST['oznaka'] ?? '');
        $naziv = trim($_POST['naziv'] ?? '');
        $opis = trim($_POST['opis'] ?? '');

        if ($sport_id === null) {
            postavi_poruku('danger', 'Izaberite sport.');
        } elseif ($oznaka === '' || mb_strlen($oznaka) > 10) {
            postavi_poruku('danger', 'Oznaka mora imati 1-10 znakova.');
        } elseif (mb_strlen($naziv) < 2 || mb_strlen($naziv) > 80) {
            postavi_poruku('danger', 'Naziv mora imati 2-80 znakova.');
        } else {
            try {
                if ($id === null) {
                    $upit = $pdo->prepare('INSERT INTO tip_igre (sport_id, oznaka, naziv, opis) VALUES (?, ?, ?, ?)');
                    $upit->execute([$sport_id, $oznaka, $naziv, $opis !== '' ? $opis : null]);
                    postavi_poruku('success', 'Tip igre je dodat.');
                } else {
                    // Provera da li se menja baš oznaka - to je izmena koja
                    // utiče na ocenjivanje, pa se posebno beleži i najavljuje
                    $stari = $pdo->prepare('SELECT oznaka FROM tip_igre WHERE id = ?');
                    $stari->execute([$id]);
                    $stara_oznaka = (string) $stari->fetchColumn();

                    $upit = $pdo->prepare('UPDATE tip_igre SET sport_id = ?, oznaka = ?, naziv = ?, opis = ? WHERE id = ?');
                    $upit->execute([$sport_id, $oznaka, $naziv, $opis !== '' ? $opis : null, $id]);

                    if ($stara_oznaka !== $oznaka) {
                        upisi_trag(
                            $_SESSION['korisnicko_ime'],
                            "Izmenjena oznaka tipa igre id=$id: \"$stara_oznaka\" -> \"$oznaka\""
                        );
                        postavi_poruku(
                            'warning',
                            'Oznaka je promenjena iz "' . $stara_oznaka . '" u "' . $oznaka . '". '
                            . 'Već uplaćeni tiketi sa ovim tipom igre biće ubuduće ocenjivani po novoj oznaci.'
                        );
                    } else {
                        postavi_poruku('success', 'Tip igre je izmenjen.');
                    }
                }

                if (!in_array($oznaka, $poznate_oznake, true)) {
                    postavi_poruku(
                        'warning',
                        'Oznaku "' . $oznaka . '" automatska obrada rezultata ne prepoznaje. '
                        . 'Dok se ne dopuni funkcija tip_igre_pogodjen() u includes/rezultati.php, '
                        . 'događaji sa ovom kvotom neće moći da budu zaključeni.'
                    );
                }
            } catch (PDOException $e) {
                if ((int) $e->getCode() === 23000) {
                    postavi_poruku('danger', 'Taj sport već ima tip igre sa istom oznakom.');
                } else {
                    upisi_gresku('Greška pri čuvanju tipa igre: ' . $e->getMessage());
                    postavi_poruku('danger', 'Trenutno nije moguće sačuvati tip igre.');
                }
            }
        }
    } elseif ($akcija === 'obrisi' && ctype_digit((string) ($_POST['id'] ?? ''))) {
        $id = (int) $_POST['id'];

        try {
            $podaci = $pdo->prepare('SELECT oznaka, naziv FROM tip_igre WHERE id = ?');
            $podaci->execute([$id]);
            $tip = $podaci->fetch();

            $upit = $pdo->prepare('DELETE FROM tip_igre WHERE id = ?');
            $upit->execute([$id]);

            upisi_trag(
                $_SESSION['korisnicko_ime'],
                'Obrisan tip igre id=' . $id . ' (' . ($tip['oznaka'] ?? '?') . ' — ' . ($tip['naziv'] ?? '?') . ')'
            );
            postavi_poruku('success', 'Tip igre je obrisan.');
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                postavi_poruku('danger', 'Tip igre se ne može obrisati jer za njega postoje kvote. Obrišite prvo te kvote.');
            } else {
                upisi_gresku('Greška pri brisanju tipa igre: ' . $e->getMessage());
                postavi_poruku('danger', 'Trenutno nije moguće obrisati tip igre.');
            }
        }
    }

    preusmeri(KORENSKI_URL . '/operater/tipovi_igre.php');
}

$tip_za_izmenu = null;
$izmeni_id = isset($_GET['izmeni']) && ctype_digit($_GET['izmeni']) ? (int) $_GET['izmeni'] : null;

try {
    $sportovi = $pdo->query('SELECT id, naziv FROM sport ORDER BY naziv')->fetchAll();

    if ($izmeni_id !== null) {
        $upit = $pdo->prepare('SELECT * FROM tip_igre WHERE id = ?');
        $upit->execute([$izmeni_id]);
        $tip_za_izmenu = $upit->fetch() ?: null;
    }

    $tipovi = $pdo->query(
        'SELECT ti.*, s.naziv AS sport_naziv,
                (SELECT COUNT(*) FROM kvota k WHERE k.tip_igre_id = ti.id) AS broj_kvota
         FROM tip_igre ti JOIN sport s ON s.id = ti.sport_id
         ORDER BY s.naziv, ti.id'
    )->fetchAll();
} catch (PDOException $e) {
    upisi_gresku('Greška pri učitavanju tipova igre: ' . $e->getMessage());
    $sportovi = [];
    $tipovi = [];
}

$csrf_token = generisi_csrf_token();
$naslov_stranice = 'Tipovi igre';
require_once __DIR__ . '/../includes/zaglavlje.php';
?>

<h1 class="mb-4">Tipovi igre</h1>

<?php prikazi_poruke(); ?>

<div class="alert alert-warning">
    <strong>Oznaka nije samo natpis.</strong> Po njoj automatska obrada rezultata odlučuje da li je
    stavka tiketa pogođena. Sistem trenutno ume da oceni:
    <?php foreach ($poznate_oznake as $oznaka): ?><code class="me-1"><?= bezbedno($oznaka) ?></code><?php endforeach; ?>.
    Za bilo koju drugu oznaku događaj se neće moći zaključiti dok se ne dopuni funkcija
    <code>tip_igre_pogodjen()</code> u <code>includes/rezultati.php</code>.
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="mb-3"><?= $tip_za_izmenu ? 'Izmena tipa igre' : 'Novi tip igre' ?></h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                    <input type="hidden" name="akcija" value="sacuvaj">
                    <?php if ($tip_za_izmenu): ?>
                        <input type="hidden" name="id" value="<?= (int) $tip_za_izmenu['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-2">
                        <label for="sport_id" class="form-label">Sport</label>
                        <select class="form-select" id="sport_id" name="sport_id" required>
                            <option value="">Izaberite sport</option>
                            <?php foreach ($sportovi as $sport): ?>
                                <option value="<?= (int) $sport['id'] ?>"
                                    <?= isset($tip_za_izmenu['sport_id']) && (int) $tip_za_izmenu['sport_id'] === (int) $sport['id'] ? 'selected' : '' ?>>
                                    <?= bezbedno($sport['naziv']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label for="oznaka" class="form-label">Oznaka</label>
                        <input type="text" class="form-control" id="oznaka" name="oznaka" required maxlength="10"
                               list="poznate-oznake" value="<?= bezbedno($tip_za_izmenu['oznaka'] ?? '') ?>">
                        <datalist id="poznate-oznake">
                            <?php foreach ($poznate_oznake as $oznaka): ?>
                                <option value="<?= bezbedno($oznaka) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="mb-2">
                        <label for="naziv" class="form-label">Naziv</label>
                        <input type="text" class="form-control" id="naziv" name="naziv" required minlength="2" maxlength="80"
                               value="<?= bezbedno($tip_za_izmenu['naziv'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label for="opis" class="form-label">Opis <span class="text-muted">(opciono)</span></label>
                        <input type="text" class="form-control" id="opis" name="opis" maxlength="255"
                               value="<?= bezbedno($tip_za_izmenu['opis'] ?? '') ?>">
                    </div>

                    <button type="submit" class="btn btn-primary w-100"><?= $tip_za_izmenu ? 'Sačuvaj izmene' : 'Dodaj tip igre' ?></button>
                    <?php if ($tip_za_izmenu): ?>
                        <a href="<?= KORENSKI_URL ?>/operater/tipovi_igre.php" class="btn btn-outline-secondary w-100 mt-2">Otkaži izmenu</a>
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
                        <th>Sport</th>
                        <th>Oznaka</th>
                        <th>Naziv</th>
                        <th>Kvota</th>
                        <th class="text-end">Akcije</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tipovi as $tip): ?>
                        <?php $poznat = in_array($tip['oznaka'], $poznate_oznake, true); ?>
                        <tr>
                            <td><?= bezbedno($tip['sport_naziv']) ?></td>
                            <td>
                                <code><?= bezbedno($tip['oznaka']) ?></code>
                                <?php if (!$poznat): ?>
                                    <span class="status status-ceka" title="Automatska obrada rezultata ne prepoznaje ovu oznaku">?</span>
                                <?php endif; ?>
                            </td>
                            <td><?= bezbedno($tip['naziv']) ?></td>
                            <td><?= (int) $tip['broj_kvota'] ?></td>
                            <td class="text-end">
                                <a href="<?= KORENSKI_URL ?>/operater/tipovi_igre.php?izmeni=<?= (int) $tip['id'] ?>" class="btn btn-sm btn-outline-primary">Izmeni</a>
                                <form method="post" class="d-inline"
                                      onsubmit="return confirm('Obrisati tip igre <?= bezbedno($tip['oznaka']) ?>? Ovo je trajno.');">
                                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                                    <input type="hidden" name="akcija" value="obrisi">
                                    <input type="hidden" name="id" value="<?= (int) $tip['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" <?= $tip['broj_kvota'] > 0 ? 'disabled title="Postoje kvote sa ovim tipom igre"' : '' ?>>Obriši</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($tipovi)): ?>
                        <tr>
                            <td colspan="5">
                                <div class="prazno">
                                    <i class="bi bi-list-ol" aria-hidden="true"></i>
                                    <div class="naslov-praznog">Nema unetih tipova igre</div>
                                    <p class="mb-0">Bez tipa igre (1, X, 2…) ne mogu se uneti kvote za događaje.</p>
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

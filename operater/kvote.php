<?php
// CRUD nad tabelom kvota, uvek u kontekstu jednog događaja (?dogadjaj_id=).
// Ponuđeni tipovi igre su ograničeni na sport tog događaja i na one koji već
// nisu iskorišćeni (UNIQUE (dogadjaj_id, tip_igre_id) u bazi).

require_once __DIR__ . '/../includes/autentifikacija.php';
require_once __DIR__ . '/../includes/funkcije.php';

zahtevaj_ulogu('operater', 'admin');

// Predlog vrednosti kvote po oznaci tipa igre, za dugme "Dodaj sve tipove".
// Ovo je samo početna tačka za operatera - ništa se ne upisuje u bazu dok on
// sam ne potvrdi (ili izmeni) vrednost i pritisne "Sačuvaj predložene kvote".
function predlozena_kvota_za_oznaku(string $oznaka): float
{
    return match ($oznaka) {
        '1'   => 1.90,
        'X'   => 3.40,
        '2'   => 3.60,
        '1X'  => 1.30,
        '12'  => 1.40,
        'X2'  => 1.75,
        'GG'  => 1.80,
        '0-2' => 2.05,
        '3+'  => 1.75,
        default => 1.50,
    };
}

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
    } elseif ($akcija === 'dodaj_sve') {
        // Dugme "Dodaj sve tipove" - masovno dodavanje kvota za sve tipove igre
        // ovog sporta koji na ovom događaju još nemaju kvotu. Predlog vrednosti
        // je operater već mogao izmeniti u pregledaču pre slanja forme; ovde se
        // vrednosti proveravaju ponovo, kao i svaka druga kvota.
        $poslati_id_niz = $_POST['tip_igre_id'] ?? [];
        $poslate_vrednosti = $_POST['vrednost'] ?? [];

        if (!is_array($poslati_id_niz) || empty($poslati_id_niz)) {
            postavi_poruku('danger', 'Izaberite bar jedan tip igre za dodavanje.');
        } else {
            try {
                // Spisak tipova ovog sporta koji na ovom događaju još nemaju kvotu
                // se učitava ponovo, ovde - poslati id-jevi su podaci sa klijenta i
                // ne uzimaju se zdravo za gotovo (mogli su u međuvremenu biti
                // iskorišćeni u drugom prozoru, ili jednostavno ne pripadati ovom
                // sportu).
                $upitSlobodni = $pdo->prepare(
                    'SELECT ti.id FROM tip_igre ti
                     WHERE ti.sport_id = (SELECT l.sport_id FROM dogadjaj d JOIN liga l ON l.id = d.liga_id WHERE d.id = ?)
                       AND ti.id NOT IN (SELECT tip_igre_id FROM kvota WHERE dogadjaj_id = ?)'
                );
                $upitSlobodni->execute([$dogadjaj_id, $dogadjaj_id]);
                $dozvoljeni_id = array_map('intval', $upitSlobodni->fetchAll(PDO::FETCH_COLUMN));

                $za_upis = [];
                $neispravna_vrednost = false;

                foreach ($poslati_id_niz as $poslati_id) {
                    $poslati_id = ctype_digit((string) $poslati_id) ? (int) $poslati_id : null;

                    // Tuđ ili već iskorišćen tip igre se tiho preskače - forma ga
                    // uopšte nije ni smela ponuditi, pa to nije greška operatera
                    if ($poslati_id === null || !in_array($poslati_id, $dozvoljeni_id, true)) {
                        continue;
                    }

                    $unos = str_replace(',', '.', trim((string) ($poslate_vrednosti[$poslati_id] ?? '')));

                    if (!is_numeric($unos) || (float) $unos < 1.01) {
                        $neispravna_vrednost = true;
                        break;
                    }

                    $za_upis[$poslati_id] = number_format((float) $unos, 2, '.', '');
                }

                if ($neispravna_vrednost) {
                    postavi_poruku('danger', 'Sve unete kvote moraju biti brojevi veći ili jednaki 1.01.');
                } elseif (empty($za_upis)) {
                    postavi_poruku('danger', 'Nijedan izabrani tip igre više nije slobodan - stranica je verovatno bila otvorena u drugom prozoru.');
                } else {
                    $upitUmetni = $pdo->prepare(
                        'INSERT INTO kvota (dogadjaj_id, tip_igre_id, vrednost, aktivna) VALUES (?, ?, ?, 1)'
                    );

                    $pdo->beginTransaction();
                    foreach ($za_upis as $tip_igre_id => $vrednost) {
                        $upitUmetni->execute([$dogadjaj_id, $tip_igre_id, $vrednost]);
                    }
                    $pdo->commit();

                    postavi_poruku('success', 'Dodato kvota: ' . count($za_upis) . '.');
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                upisi_gresku('Greška pri masovnom dodavanju kvota: ' . $e->getMessage());
                postavi_poruku('danger', 'Trenutno nije moguće dodati kvote.');
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
$naslov_stranice = 'Kvote: ' . $dogadjaj['domacin_naziv'] . ' — ' . $dogadjaj['gost_naziv'];
require_once __DIR__ . '/../includes/zaglavlje.php';
?>

<div class="zaglavlje-strane">
    <h1>Kvote</h1>
    <p class="podnaslov">
        <?= bezbedno($dogadjaj['domacin_naziv']) ?> — <?= bezbedno($dogadjaj['gost_naziv']) ?>
        <span class="mx-1">·</span> <?= bezbedno($dogadjaj['sport_naziv']) ?> / <?= bezbedno($dogadjaj['liga_naziv']) ?>
        <span class="mx-1">·</span> <?= bezbedno(formatiraj_datum_vreme($dogadjaj['vreme_pocetka'])) ?>
    </p>
    <a href="<?= KORENSKI_URL ?>/operater/dogadjaji.php" class="btn btn-sm btn-outline-secondary nazad">&laquo; Nazad na događaje</a>
</div>

<?php prikazi_poruke(); ?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="mb-3">Nova kvota</h2>
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
                        <tr>
                            <td colspan="3">
                                <div class="prazno">
                                    <i class="bi bi-percent" aria-hidden="true"></i>
                                    <div class="naslov-praznog">Nema unetih kvota za ovaj događaj</div>
                                    <p class="mb-0">Bez bar jedne kvote događaj se ne prikazuje u javnoj ponudi.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (!empty($slobodni_tipovi)): ?>
    <?php
    // Dugme "Dodaj sve tipove": umesto da operater dodaje devet kvota fudbalskog
    // događaja jednu po jednu kroz formu levo, ovde odjednom dobija predlog za
    // sve tipove igre koji ovom događaju još nedostaju. Klik na dugme ništa ne
    // šalje serveru - samo otkriva formu ispod, već popunjenu predloženim
    // vrednostima. Ništa se ne upisuje u bazu dok operater ne pregleda (ili
    // izmeni) te vrednosti i sam ne pritisne "Sačuvaj predložene kvote".
    ?>
    <div class="card shadow-sm mt-4">
        <div class="card-body">
            <h2 class="mb-2">Nedostajući tipovi igre</h2>
            <p class="text-muted small mb-3">
                Ovom događaju nedostaje <?= count($slobodni_tipovi) ?>
                <?= count($slobodni_tipovi) === 1 ? 'tip igre' : 'tipova igre' ?> za sport
                „<?= bezbedno($dogadjaj['sport_naziv']) ?>“.
            </p>

            <button type="button" class="btn btn-outline-primary mb-3" id="dugme-dodaj-sve-tipove"
                    data-cilj="predlog-svih-tipova" aria-expanded="false" aria-controls="predlog-svih-tipova">
                Dodaj sve tipove
            </button>

            <form method="post" class="d-none" id="predlog-svih-tipova">
                <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                <input type="hidden" name="akcija" value="dodaj_sve">
                <input type="hidden" name="dogadjaj_id" value="<?= $dogadjaj_id ?>">

                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width:2.5rem"></th>
                                <th>Tip igre</th>
                                <th style="width:9rem">Predložena vrednost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($slobodni_tipovi as $tip): ?>
                                <?php $id_polja = 'ukljuci-' . (int) $tip['id']; ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" class="form-check-input" name="tip_igre_id[]"
                                               value="<?= (int) $tip['id'] ?>" id="<?= bezbedno($id_polja) ?>" checked>
                                    </td>
                                    <td>
                                        <label for="<?= bezbedno($id_polja) ?>" class="mb-0">
                                            <?= bezbedno($tip['oznaka']) ?> — <?= bezbedno($tip['naziv']) ?>
                                        </label>
                                    </td>
                                    <td>
                                        <input type="number" class="form-control form-control-sm" step="0.01" min="1.01"
                                               name="vrednost[<?= (int) $tip['id'] ?>]"
                                               value="<?= number_format(predlozena_kvota_za_oznaku($tip['oznaka']), 2, '.', '') ?>" required>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <button type="submit" class="btn btn-primary">Sačuvaj predložene kvote</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php
$dodatne_skripte = ['operater.js'];
require_once __DIR__ . '/../includes/podnozje.php';
?>

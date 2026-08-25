<?php
// CRUD nad tabelom transakcija, uz stalnu brigu o pravilu 8: stanje računa
// mora u svakom trenutku biti jednako zbiru svih transakcija tog igrača.
//
// Zato:
//  - unos korektivne transakcije menja i stanje računa, u istoj transakciji baze;
//  - brisanje se dozvoljava samo za transakcije koje nisu vezane za tiket, i
//    posle njega se ceo niz preračunava (uključujući kolonu stanje_posle), pa
//    evidencija ostaje neprekidna;
//  - transakcija vezana za tiket se ne briše pojedinačno - brisao bi se trag o
//    novcu, a tiket bi ostao, pa je jedini ispravan put brisanje celog tiketa.

require_once __DIR__ . '/../includes/autentifikacija.php';
require_once __DIR__ . '/../includes/funkcije.php';
require_once __DIR__ . '/../includes/racun.php';

zahtevaj_ulogu('admin');

$pdo = konektuj_bazu();
$tipovi_za_korekciju = ['uplata', 'isplata'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_ispravan($_POST['csrf_token'] ?? null)) {
        postavi_poruku('danger', 'Nevažeća sesija. Osvežite stranicu i pokušajte ponovo.');
        preusmeri(KORENSKI_URL . '/admin/transakcije.php');
    }

    $akcija = $_POST['akcija'] ?? '';

    try {
        if ($akcija === 'korekcija') {
            $korisnik_id = ctype_digit((string) ($_POST['korisnik_id'] ?? '')) ? (int) $_POST['korisnik_id'] : null;
            $tip = $_POST['tip'] ?? '';
            $iznos_unos = str_replace(',', '.', trim($_POST['iznos'] ?? ''));
            $opis = trim($_POST['opis'] ?? '');

            if ($korisnik_id === null) {
                throw new RuntimeException('Izaberite korisnika.');
            }
            if (!in_array($tip, $tipovi_za_korekciju, true)) {
                throw new RuntimeException('Korektivna transakcija može biti samo uplata ili isplata.');
            }
            if (!is_numeric($iznos_unos) || round((float) $iznos_unos, 2) <= 0) {
                throw new RuntimeException('Iznos mora biti pozitivan broj.');
            }
            if ($opis === '') {
                throw new RuntimeException('Unesite razlog korekcije - bez njega zapis nema vrednost kao trag.');
            }

            $iznos = round((float) $iznos_unos, 2);

            $pdo->beginTransaction();

            $zakljucaj = $pdo->prepare('SELECT korisnicko_ime, stanje_racuna FROM korisnik WHERE id = ? FOR UPDATE');
            $zakljucaj->execute([$korisnik_id]);
            $korisnik = $zakljucaj->fetch();

            if (!$korisnik) {
                throw new RuntimeException('Korisnik nije pronađen.');
            }

            $stanje = round((float) $korisnik['stanje_racuna'], 2);
            $novo_stanje = transakcija_je_priliv($tip) ? $stanje + $iznos : $stanje - $iznos;
            $novo_stanje = round($novo_stanje, 2);

            if ($novo_stanje < 0) {
                throw new RuntimeException(
                    'Isplata je veća od stanja na računu (' . formatiraj_novac($stanje) . '), pa bi račun otišao u minus.'
                );
            }

            $upis = $pdo->prepare(
                'INSERT INTO transakcija (korisnik_id, tiket_id, tip, iznos, stanje_posle, opis)
                 VALUES (?, NULL, ?, ?, ?, ?)'
            );
            $upis->execute([$korisnik_id, $tip, za_bazu($iznos), za_bazu($novo_stanje), 'Korekcija: ' . mb_substr($opis, 0, 240)]);

            $postavi = $pdo->prepare('UPDATE korisnik SET stanje_racuna = ? WHERE id = ?');
            $postavi->execute([za_bazu($novo_stanje), $korisnik_id]);

            $pdo->commit();

            upisi_trag(
                $_SESSION['korisnicko_ime'],
                'Korektivna transakcija za ' . $korisnik['korisnicko_ime'] . ': ' . $tip . ' '
                . za_bazu($iznos) . ', stanje ' . za_bazu($stanje) . ' -> ' . za_bazu($novo_stanje)
                . '. Razlog: ' . $opis
            );

            postavi_poruku(
                'success',
                'Korekcija je upisana. Stanje računa korisnika ' . $korisnik['korisnicko_ime']
                . ' je sada ' . formatiraj_novac($novo_stanje) . '.'
            );
        } elseif ($akcija === 'izmeni_opis') {
            // Menja se samo opis. Iznos i tip se namerno ne mogu menjati: oni
            // određuju stanje računa, pa bi njihova izmena raskinula vezu
            // između evidencije i stvarnog stanja. Za ispravku iznosa postoji
            // brisanje pa nova korektivna transakcija.
            $id = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : null;
            $opis = trim($_POST['opis'] ?? '');

            if ($id === null) {
                throw new RuntimeException('Neispravna transakcija.');
            }
            if ($opis === '') {
                throw new RuntimeException('Opis ne sme biti prazan.');
            }

            $podaci = $pdo->prepare('SELECT opis FROM transakcija WHERE id = ?');
            $podaci->execute([$id]);
            $stari_opis = $podaci->fetchColumn();

            if ($stari_opis === false) {
                throw new RuntimeException('Transakcija nije pronađena.');
            }

            $upit = $pdo->prepare('UPDATE transakcija SET opis = ? WHERE id = ?');
            $upit->execute([mb_substr($opis, 0, 255), $id]);

            upisi_trag(
                $_SESSION['korisnicko_ime'],
                'Izmenjen opis transakcije id=' . $id . ': "' . $stari_opis . '" -> "' . $opis . '"'
            );
            postavi_poruku('success', 'Opis transakcije je izmenjen.');
        } elseif ($akcija === 'obrisi') {
            $id = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : null;

            if ($id === null) {
                throw new RuntimeException('Neispravna transakcija.');
            }

            $pdo->beginTransaction();

            $podaci = $pdo->prepare(
                'SELECT t.*, k.korisnicko_ime FROM transakcija t
                 JOIN korisnik k ON k.id = t.korisnik_id
                 WHERE t.id = ? FOR UPDATE'
            );
            $podaci->execute([$id]);
            $transakcija = $podaci->fetch();

            if (!$transakcija) {
                throw new RuntimeException('Transakcija nije pronađena.');
            }

            // Odbijanje uz objašnjenje: transakcija vezana za tiket je deo
            // priče o tom tiketu i ne sme da nestane sama
            if ($transakcija['tiket_id'] !== null) {
                throw new RuntimeException(
                    'Ova transakcija je vezana za tiket, pa se ne može obrisati zasebno - '
                    . 'ostao bi tiket bez traga o novcu. Ako je namera da nestane, obrišite ceo tiket '
                    . 'na stranici Tiketi.'
                );
            }

            $korisnik_id = (int) $transakcija['korisnik_id'];

            $zakljucaj = $pdo->prepare('SELECT id FROM korisnik WHERE id = ? FOR UPDATE');
            $zakljucaj->execute([$korisnik_id]);

            $upit = $pdo->prepare('DELETE FROM transakcija WHERE id = ?');
            $upit->execute([$id]);

            // Ceo niz se preračunava: kolone stanje_posle u preostalim redovima
            // i konačno stanje računa. Ako bi negde otišlo u minus, funkcija
            // baca izuzetak i brisanje se poništava.
            $novo_stanje = preracunaj_stanje_racuna($pdo, $korisnik_id);

            $pdo->commit();

            upisi_trag(
                $_SESSION['korisnicko_ime'],
                'Obrisana transakcija id=' . $id . ' (' . $transakcija['tip'] . ' '
                . $transakcija['iznos'] . ') korisnika ' . $transakcija['korisnicko_ime']
                . '. Novo stanje: ' . $novo_stanje
            );

            postavi_poruku(
                'success',
                'Transakcija je obrisana, a niz je preračunat. Novo stanje računa korisnika '
                . $transakcija['korisnicko_ime'] . ' je ' . formatiraj_novac($novo_stanje) . '.'
            );
        } elseif ($akcija === 'uskladi') {
            $korisnik_id = ctype_digit((string) ($_POST['korisnik_id'] ?? '')) ? (int) $_POST['korisnik_id'] : null;

            if ($korisnik_id === null) {
                throw new RuntimeException('Neispravan korisnik.');
            }

            $pdo->beginTransaction();

            $zakljucaj = $pdo->prepare('SELECT korisnicko_ime, stanje_racuna FROM korisnik WHERE id = ? FOR UPDATE');
            $zakljucaj->execute([$korisnik_id]);
            $korisnik = $zakljucaj->fetch();

            if (!$korisnik) {
                throw new RuntimeException('Korisnik nije pronađen.');
            }

            $staro = $korisnik['stanje_racuna'];
            $novo_stanje = preracunaj_stanje_racuna($pdo, $korisnik_id);

            $pdo->commit();

            upisi_trag(
                $_SESSION['korisnicko_ime'],
                'Usklađeno stanje računa korisnika ' . $korisnik['korisnicko_ime']
                . ': ' . $staro . ' -> ' . $novo_stanje
            );

            postavi_poruku(
                'success',
                'Stanje računa korisnika ' . $korisnik['korisnicko_ime'] . ' je usklađeno sa istorijom transakcija: '
                . formatiraj_novac($staro) . ' -> ' . formatiraj_novac($novo_stanje) . '.'
            );
        }
    } catch (PDOException $e) {
        // PDOException se hvata pre RuntimeException jer je njegov naslednik
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        upisi_gresku('Greška pri radu sa transakcijama: ' . $e->getMessage());
        postavi_poruku('danger', 'Trenutno nije moguće izvršiti tu radnju nad transakcijama.');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        postavi_poruku('danger', $e->getMessage());
    }

    preusmeri(KORENSKI_URL . '/admin/transakcije.php');
}

$filter_korisnik = isset($_GET['korisnik_id']) && ctype_digit($_GET['korisnik_id']) ? (int) $_GET['korisnik_id'] : null;

try {
    $korisnici = $pdo->query(
        "SELECT k.id, k.korisnicko_ime, k.stanje_racuna
         FROM korisnik k JOIN uloga u ON u.id = k.uloga_id
         ORDER BY k.korisnicko_ime"
    )->fetchAll();

    $sql = 'SELECT t.*, k.korisnicko_ime, tk.broj_tiketa
            FROM transakcija t
            JOIN korisnik k ON k.id = t.korisnik_id
            LEFT JOIN tiket tk ON tk.id = t.tiket_id';
    $parametri = [];

    if ($filter_korisnik !== null) {
        $sql .= ' WHERE t.korisnik_id = ?';
        $parametri[] = $filter_korisnik;
    }

    $sql .= ' ORDER BY t.datum_vreme DESC, t.id DESC LIMIT 200';

    $upit = $pdo->prepare($sql);
    $upit->execute($parametri);
    $transakcije = $upit->fetchAll();

    $nesaglasni = nesaglasni_racuni($pdo);
} catch (PDOException $e) {
    upisi_gresku('Greška pri učitavanju transakcija: ' . $e->getMessage());
    $korisnici = [];
    $transakcije = [];
    $nesaglasni = [];
}

$csrf_token = generisi_csrf_token();
$naslov_stranice = 'Transakcije';
require_once __DIR__ . '/../includes/zaglavlje.php';
?>

<h1 class="h3 mb-4">Transakcije</h1>

<?php prikazi_poruke(); ?>

<?php if (!empty($nesaglasni)): ?>
    <div class="alert alert-danger">
        <strong>Neusklađeno stanje računa!</strong>
        Kod sledećih korisnika stanje ne odgovara zbiru transakcija. To ne bi smelo da se desi u
        normalnom radu - proverite log grešaka.
        <ul class="mb-2 mt-2">
            <?php foreach ($nesaglasni as $red): ?>
                <li>
                    <?= bezbedno($red['korisnicko_ime']) ?>:
                    stanje <?= bezbedno(formatiraj_novac($red['stanje_racuna'])) ?>,
                    zbir transakcija <?= bezbedno(formatiraj_novac($red['zbir_transakcija'])) ?>
                    <form method="post" class="d-inline"
                          onsubmit="return confirm('Uskladiti stanje računa sa istorijom transakcija? Kolone stanje_posle biće preračunate.');">
                        <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                        <input type="hidden" name="akcija" value="uskladi">
                        <input type="hidden" name="korisnik_id" value="<?= (int) $red['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger ms-2">Uskladi</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php else: ?>
    <div class="alert alert-success small">
        Stanje računa svih korisnika odgovara zbiru njihovih transakcija.
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">Korektivna transakcija</h2>
                <p class="text-muted small">
                    Ručni ispravak stanja računa. Upisuje se kao redovna transakcija i odmah menja
                    stanje, pa evidencija ostaje usklađena.
                </p>
                <form method="post" onsubmit="return confirm('Upisati korektivnu transakciju? Stanje računa će se odmah promeniti.');">
                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                    <input type="hidden" name="akcija" value="korekcija">

                    <div class="mb-2">
                        <label for="korisnik_id" class="form-label">Korisnik</label>
                        <select class="form-select" id="korisnik_id" name="korisnik_id" required>
                            <option value="">Izaberite korisnika</option>
                            <?php foreach ($korisnici as $korisnik): ?>
                                <option value="<?= (int) $korisnik['id'] ?>">
                                    <?= bezbedno($korisnik['korisnicko_ime']) ?> (<?= bezbedno(formatiraj_novac($korisnik['stanje_racuna'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label for="tip" class="form-label">Tip</label>
                        <select class="form-select" id="tip" name="tip" required>
                            <option value="uplata">uplata (uvećava stanje)</option>
                            <option value="isplata">isplata (umanjuje stanje)</option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label for="iznos" class="form-label">Iznos</label>
                        <input type="number" class="form-control" id="iznos" name="iznos" required step="0.01" min="0.01">
                    </div>

                    <div class="mb-3">
                        <label for="opis" class="form-label">Razlog korekcije</label>
                        <input type="text" class="form-control" id="opis" name="opis" required maxlength="240"
                               placeholder="npr. ispravka posle reklamacije">
                    </div>

                    <button type="submit" class="btn btn-warning w-100">Upiši korekciju</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <form method="get" class="mb-3 d-flex gap-2 align-items-end">
            <div>
                <label for="filter_korisnik" class="form-label">Korisnik</label>
                <select class="form-select" id="filter_korisnik" name="korisnik_id" onchange="this.form.submit()">
                    <option value="">Svi korisnici</option>
                    <?php foreach ($korisnici as $korisnik): ?>
                        <option value="<?= (int) $korisnik['id'] ?>" <?= $filter_korisnik === (int) $korisnik['id'] ? 'selected' : '' ?>>
                            <?= bezbedno($korisnik['korisnicko_ime']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($filter_korisnik !== null): ?>
                <a href="<?= KORENSKI_URL ?>/admin/transakcije.php" class="btn btn-outline-secondary">Poništi</a>
            <?php endif; ?>
        </form>

        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Korisnik</th>
                        <th>Tip</th>
                        <th class="text-end">Iznos</th>
                        <th class="text-end">Stanje posle</th>
                        <th>Opis</th>
                        <th class="text-end">Akcija</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transakcije as $transakcija): ?>
                        <tr>
                            <td class="small"><?= bezbedno(formatiraj_datum_vreme($transakcija['datum_vreme'])) ?></td>
                            <td class="small"><?= bezbedno($transakcija['korisnicko_ime']) ?></td>
                            <td><span class="badge text-bg-secondary text-uppercase"><?= bezbedno($transakcija['tip']) ?></span></td>
                            <td class="text-end <?= transakcija_je_priliv($transakcija['tip']) ? 'text-success' : 'text-danger' ?>">
                                <?= transakcija_je_priliv($transakcija['tip']) ? '+' : '−' ?><?= bezbedno(formatiraj_novac($transakcija['iznos'])) ?>
                            </td>
                            <td class="text-end"><?= bezbedno(formatiraj_novac($transakcija['stanje_posle'])) ?></td>
                            <td class="small">
                                <form method="post" class="d-flex gap-1">
                                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                                    <input type="hidden" name="akcija" value="izmeni_opis">
                                    <input type="hidden" name="id" value="<?= (int) $transakcija['id'] ?>">
                                    <input type="text" class="form-control form-control-sm py-0" name="opis" maxlength="255"
                                           value="<?= bezbedno($transakcija['opis']) ?>" required>
                                    <button type="submit" class="btn btn-sm btn-outline-primary py-0" title="Sačuvaj opis">&check;</button>
                                </form>
                                <?php if ($transakcija['broj_tiketa']): ?>
                                    <span class="badge text-bg-light text-dark mt-1"><?= bezbedno($transakcija['broj_tiketa']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <form method="post"
                                      onsubmit="return confirm('Obrisati ovu transakciju? Niz transakcija i stanje računa biće preračunati.');">
                                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                                    <input type="hidden" name="akcija" value="obrisi">
                                    <input type="hidden" name="id" value="<?= (int) $transakcija['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                        <?= $transakcija['tiket_id'] !== null ? 'disabled title="Vezana za tiket - obrišite tiket umesto ovoga"' : '' ?>>Obriši</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($transakcije)): ?>
                        <tr><td colspan="7" class="text-muted">Nema transakcija za prikaz.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/podnozje.php'; ?>

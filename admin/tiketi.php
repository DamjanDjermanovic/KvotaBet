<?php
// CRUD nad tabelama tiket i tiket_stavka.
//
// O usklađenosti stanja računa (poslovno pravilo 8):
//
// Brisanje tiketa NE dira novac. Strani ključ transakcija.tiket_id je
// ON DELETE SET NULL, pa zapisi o ulogu i dobitku ostaju u evidenciji, samo
// gube vezu sa obrisanim tiketom. Stanje računa se time ne menja, pa ne može
// ni da se raziđe sa zbirom transakcija. Novac se nikad ne briše zajedno sa
// tiketom - to bi bio jedini način da evidencija postane neusklađena.
//
// Brisanje pojedinačne stavke se dozvoljava samo dok je tiket aktivan.
// Kod već obrađenog tiketa ishod i isplata su obračunati baš po tim stavkama,
// pa bi naknadno uklanjanje jedne od njih učinilo isplatu neobjašnjivom.

require_once __DIR__ . '/../includes/autentifikacija.php';
require_once __DIR__ . '/../includes/funkcije.php';
require_once __DIR__ . '/../includes/racun.php';

zahtevaj_ulogu('admin');

$pdo = konektuj_bazu();
$statusi_tiketa = ['aktivan', 'dobitan', 'gubitan', 'storniran'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_ispravan($_POST['csrf_token'] ?? null)) {
        postavi_poruku('danger', 'Nevažeća sesija. Osvežite stranicu i pokušajte ponovo.');
        preusmeri(KORENSKI_URL . '/admin/tiketi.php');
    }

    $akcija = $_POST['akcija'] ?? '';
    $id = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : null;

    try {
        if ($akcija === 'status' && $id !== null) {
            $novi_status = $_POST['status'] ?? '';

            if (!in_array($novi_status, $statusi_tiketa, true)) {
                throw new RuntimeException('Neispravan status tiketa.');
            }

            $podaci = $pdo->prepare('SELECT broj_tiketa, status FROM tiket WHERE id = ?');
            $podaci->execute([$id]);
            $tiket = $podaci->fetch();

            if (!$tiket) {
                throw new RuntimeException('Tiket nije pronađen.');
            }

            $upit = $pdo->prepare('UPDATE tiket SET status = ? WHERE id = ?');
            $upit->execute([$novi_status, $id]);

            upisi_trag(
                $_SESSION['korisnicko_ime'],
                'Ručno promenjen status tiketa ' . $tiket['broj_tiketa']
                . ': "' . $tiket['status'] . '" -> "' . $novi_status . '"'
            );

            postavi_poruku(
                'warning',
                'Status tiketa ' . $tiket['broj_tiketa'] . ' je promenjen u "' . $novi_status . '". '
                . 'Novac time nije pomeren - ako treba i isplata, unesite je na stranici Transakcije.'
            );
        } elseif ($akcija === 'obrisi_tiket' && $id !== null) {
            $pdo->beginTransaction();

            $podaci = $pdo->prepare('SELECT broj_tiketa, korisnik_id, status FROM tiket WHERE id = ? FOR UPDATE');
            $podaci->execute([$id]);
            $tiket = $podaci->fetch();

            if (!$tiket) {
                throw new RuntimeException('Tiket nije pronađen.');
            }

            $broj_stavki = $pdo->prepare('SELECT COUNT(*) FROM tiket_stavka WHERE tiket_id = ?');
            $broj_stavki->execute([$id]);
            $obrisano_stavki = (int) $broj_stavki->fetchColumn();

            // Stavke odlaze kaskadno (ON DELETE CASCADE), transakcije ostaju
            // sa tiket_id = NULL (ON DELETE SET NULL)
            $upit = $pdo->prepare('DELETE FROM tiket WHERE id = ?');
            $upit->execute([$id]);

            // Kontrola: posle brisanja stanje mora i dalje da odgovara zbiru
            // transakcija. Ako ne odgovara, ceo posao se poništava.
            $korisnik_id = (int) $tiket['korisnik_id'];
            $zakljucaj = $pdo->prepare('SELECT stanje_racuna FROM korisnik WHERE id = ? FOR UPDATE');
            $zakljucaj->execute([$korisnik_id]);
            $stanje = round((float) $zakljucaj->fetchColumn(), 2);
            $ocekivano = ocekivano_stanje_racuna($pdo, $korisnik_id);

            if (abs($stanje - $ocekivano) > 0.001) {
                throw new RuntimeException(
                    'Brisanje je odbijeno: posle njega stanje računa (' . formatiraj_novac($stanje)
                    . ') ne bi odgovaralo zbiru transakcija (' . formatiraj_novac($ocekivano) . ').'
                );
            }

            $pdo->commit();

            upisi_trag(
                $_SESSION['korisnicko_ime'],
                'Obrisan tiket ' . $tiket['broj_tiketa'] . ' (status ' . $tiket['status'] . ', '
                . $obrisano_stavki . ' stavki). Transakcije zadržane radi usklađenosti stanja.'
            );

            postavi_poruku(
                'success',
                'Tiket ' . $tiket['broj_tiketa'] . ' je obrisan zajedno sa ' . $obrisano_stavki . ' stavki. '
                . 'Transakcije ulaganja i isplate ostaju u evidenciji, da stanje računa ostane usklađeno.'
            );
        } elseif ($akcija === 'status_stavke' && $id !== null) {
            $novi_status = $_POST['status'] ?? '';
            $statusi_stavke = ['ceka', 'pogodjen', 'promasen', 'storniran'];

            if (!in_array($novi_status, $statusi_stavke, true)) {
                throw new RuntimeException('Neispravan status stavke.');
            }

            $podaci = $pdo->prepare(
                'SELECT ts.status, t.broj_tiketa, t.status AS status_tiketa
                 FROM tiket_stavka ts JOIN tiket t ON t.id = ts.tiket_id
                 WHERE ts.id = ?'
            );
            $podaci->execute([$id]);
            $stavka = $podaci->fetch();

            if (!$stavka) {
                throw new RuntimeException('Stavka nije pronađena.');
            }

            // Isti razlog kao kod brisanja stavke: kod obrađenog tiketa je po
            // ovim stavkama već obračunata isplata
            if ($stavka['status_tiketa'] !== 'aktivan') {
                throw new RuntimeException(
                    'Stavka pripada već obrađenom tiketu (' . $stavka['broj_tiketa'] . '). '
                    . 'Po njoj je obračunat ishod, pa se status ne može menjati.'
                );
            }

            $upit = $pdo->prepare('UPDATE tiket_stavka SET status = ? WHERE id = ?');
            $upit->execute([$novi_status, $id]);

            upisi_trag(
                $_SESSION['korisnicko_ime'],
                'Ručno promenjen status stavke id=' . $id . ' tiketa ' . $stavka['broj_tiketa']
                . ': "' . $stavka['status'] . '" -> "' . $novi_status . '"'
            );

            postavi_poruku(
                'warning',
                'Status stavke je promenjen. Tiket time nije preračunat - ako treba i ishod tiketa, '
                . 'promenite i njegov status.'
            );
        } elseif ($akcija === 'obrisi_stavku' && $id !== null) {
            $pdo->beginTransaction();

            $podaci = $pdo->prepare(
                'SELECT ts.id, ts.tiket_id, ts.kvota_vrednost, t.broj_tiketa, t.status, t.ulog
                 FROM tiket_stavka ts JOIN tiket t ON t.id = ts.tiket_id
                 WHERE ts.id = ? FOR UPDATE'
            );
            $podaci->execute([$id]);
            $stavka = $podaci->fetch();

            if (!$stavka) {
                throw new RuntimeException('Stavka nije pronađena.');
            }

            if ($stavka['status'] !== 'aktivan') {
                throw new RuntimeException(
                    'Stavka pripada već obrađenom tiketu (' . $stavka['broj_tiketa'] . ', status '
                    . $stavka['status'] . '). Po njoj je obračunat ishod i eventualna isplata, '
                    . 'pa se ne može ukloniti. Obrišite ceo tiket ako je to namera.'
                );
            }

            $prebroj = $pdo->prepare('SELECT COUNT(*) FROM tiket_stavka WHERE tiket_id = ?');
            $prebroj->execute([(int) $stavka['tiket_id']]);

            if ((int) $prebroj->fetchColumn() <= 1) {
                throw new RuntimeException(
                    'Ovo je poslednja stavka tiketa, a tiket mora imati bar jednu (poslovno pravilo 1). '
                    . 'Obrišite ceo tiket.'
                );
            }

            $upit = $pdo->prepare('DELETE FROM tiket_stavka WHERE id = ?');
            $upit->execute([$id]);

            // Ukupna kvota i potencijalni dobitak se preračunavaju iz preostalih
            // stavki, da tiket ne bi ostao sa brojkama koje više ne stoje
            $preostale = $pdo->prepare('SELECT kvota_vrednost FROM tiket_stavka WHERE tiket_id = ?');
            $preostale->execute([(int) $stavka['tiket_id']]);

            $ukupna_kvota = 1.0;
            foreach ($preostale->fetchAll() as $red) {
                $ukupna_kvota *= (float) $red['kvota_vrednost'];
            }
            $ukupna_kvota = round($ukupna_kvota, 2);
            $dobitak = round((float) $stavka['ulog'] * $ukupna_kvota, 2);

            $azuriraj = $pdo->prepare('UPDATE tiket SET ukupna_kvota = ?, potencijalni_dobitak = ? WHERE id = ?');
            $azuriraj->execute([za_bazu($ukupna_kvota), za_bazu($dobitak), (int) $stavka['tiket_id']]);

            $pdo->commit();

            upisi_trag(
                $_SESSION['korisnicko_ime'],
                'Obrisana stavka id=' . $id . ' sa tiketa ' . $stavka['broj_tiketa']
                . '; nova ukupna kvota ' . $ukupna_kvota
            );

            postavi_poruku(
                'success',
                'Stavka je obrisana. Nova ukupna kvota tiketa ' . $stavka['broj_tiketa'] . ' je '
                . number_format($ukupna_kvota, 2) . ', potencijalni dobitak ' . formatiraj_novac($dobitak) . '.'
            );
        }
    } catch (PDOException $e) {
        // PDOException se hvata pre RuntimeException jer je njegov naslednik
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        upisi_gresku('Greška pri radu sa tiketima: ' . $e->getMessage());
        postavi_poruku('danger', 'Trenutno nije moguće izvršiti tu radnju nad tiketom.');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        postavi_poruku('danger', $e->getMessage());
    }

    preusmeri(KORENSKI_URL . '/admin/tiketi.php');
}

$filter_status = $_GET['status'] ?? 'svi';
if (!in_array($filter_status, array_merge($statusi_tiketa, ['svi']), true)) {
    $filter_status = 'svi';
}

try {
    $sql = 'SELECT t.*, k.korisnicko_ime
            FROM tiket t JOIN korisnik k ON k.id = t.korisnik_id';
    $parametri = [];

    if ($filter_status !== 'svi') {
        $sql .= ' WHERE t.status = ?';
        $parametri[] = $filter_status;
    }

    $sql .= ' ORDER BY t.datum_uplate DESC LIMIT 200';

    $upit = $pdo->prepare($sql);
    $upit->execute($parametri);
    $tiketi = $upit->fetchAll();

    $upitStavke = $pdo->prepare(
        'SELECT ts.id, ts.tiket_id, ts.kvota_vrednost, ts.status,
                ti.oznaka, td.naziv AS domacin, tg.naziv AS gost
         FROM tiket_stavka ts
         JOIN kvota kv ON kv.id = ts.kvota_id
         JOIN tip_igre ti ON ti.id = kv.tip_igre_id
         JOIN dogadjaj d ON d.id = kv.dogadjaj_id
         JOIN tim td ON td.id = d.domacin_id
         JOIN tim tg ON tg.id = d.gost_id
         WHERE ts.tiket_id = ? ORDER BY ts.id'
    );

    foreach ($tiketi as &$tiket) {
        $upitStavke->execute([$tiket['id']]);
        $tiket['stavke'] = $upitStavke->fetchAll();
    }
    unset($tiket);
} catch (PDOException $e) {
    upisi_gresku('Greška pri učitavanju tiketa: ' . $e->getMessage());
    $tiketi = [];
}

// Napomena: boju statusa više ne bira PHP - nosi je klasa .status-<vrednost>
// iz css/style.css, ista na svim stranicama koje prikazuju status.

$csrf_token = generisi_csrf_token();
$naslov_stranice = 'Svi tiketi';
require_once __DIR__ . '/../includes/zaglavlje.php';
?>

<h1 class="mb-4">Svi tiketi</h1>

<?php prikazi_poruke(); ?>

<div class="alert alert-secondary small">
    Brisanje tiketa ne pomera novac: zapisi o ulogu i dobitku ostaju u tabeli transakcija (gube samo
    vezu sa tiketom), pa stanje računa ostaje usklađeno sa evidencijom. Ako novac treba vratiti ili
    skinuti, to se radi zasebno na stranici <a href="<?= KORENSKI_URL ?>/admin/transakcije.php">Transakcije</a>.
</div>

<ul class="nav nav-pills mb-4">
    <?php foreach (['svi' => 'Svi', 'aktivan' => 'Aktivni', 'dobitan' => 'Dobitni', 'gubitan' => 'Gubitni', 'storniran' => 'Stornirani'] as $vrednost => $naziv): ?>
        <li class="nav-item">
            <a class="nav-link <?= $filter_status === $vrednost ? 'active' : '' ?>"
               href="<?= KORENSKI_URL ?>/admin/tiketi.php?status=<?= $vrednost ?>"><?= bezbedno($naziv) ?></a>
        </li>
    <?php endforeach; ?>
</ul>

<?php if (empty($tiketi)): ?>
    <div class="prazno">
        <i class="bi bi-receipt-cutoff" aria-hidden="true"></i>
        <div class="naslov-praznog">Nema tiketa za prikaz</div>
        <p class="mb-3">Za izabrani status nema nijednog tiketa.</p>
        <a href="<?= KORENSKI_URL ?>/admin/tiketi.php?status=svi" class="btn btn-primary btn-sm">Prikaži sve tikete</a>
    </div>
<?php else: ?>
    <?php foreach ($tiketi as $tiket): ?>
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div>
                        <div class="fw-semibold">
                            <?= bezbedno($tiket['broj_tiketa']) ?>
                            <span class="status status-<?= bezbedno($tiket['status']) ?> ms-1"><?= bezbedno($tiket['status']) ?></span>
                        </div>
                        <div class="text-muted small">
                            <?= bezbedno($tiket['korisnicko_ime']) ?> ·
                            <?= bezbedno(formatiraj_datum_vreme($tiket['datum_uplate'])) ?>
                        </div>
                        <div class="small mt-1">
                            Ulog <span class="iznos"><?= bezbedno(formatiraj_novac($tiket['ulog'])) ?></span> ·
                            kvota <span class="broj"><?= number_format((float) $tiket['ukupna_kvota'], 2) ?></span> ·
                            potencijalno <span class="iznos"><?= bezbedno(formatiraj_novac($tiket['potencijalni_dobitak'])) ?></span>
                            <?php if ((float) $tiket['isplaceno'] > 0): ?>
                                · <span class="iznos-plus">isplaćeno +<?= bezbedno(formatiraj_novac($tiket['isplaceno'])) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 align-items-start">
                        <form method="post" class="d-flex gap-1">
                            <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                            <input type="hidden" name="akcija" value="status">
                            <input type="hidden" name="id" value="<?= (int) $tiket['id'] ?>">
                            <select name="status" class="form-select form-select-sm" style="width:8rem">
                                <?php foreach ($statusi_tiketa as $status): ?>
                                    <option value="<?= $status ?>" <?= $tiket['status'] === $status ? 'selected' : '' ?>><?= bezbedno($status) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-outline-primary"
                                    onclick="return confirm('Promeniti status tiketa? Novac se time ne pomera.');">Sačuvaj</button>
                        </form>

                        <form method="post"
                              onsubmit="return confirm('Trajno obrisati tiket <?= bezbedno($tiket['broj_tiketa']) ?> i sve njegove stavke? Transakcije ostaju u evidenciji.');">
                            <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                            <input type="hidden" name="akcija" value="obrisi_tiket">
                            <input type="hidden" name="id" value="<?= (int) $tiket['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Obriši tiket</button>
                        </form>
                    </div>
                </div>

                <button class="btn btn-sm btn-outline-secondary mt-3" type="button"
                        data-bs-toggle="collapse" data-bs-target="#stavke-<?= (int) $tiket['id'] ?>">
                    Stavke (<?= count($tiket['stavke']) ?>)
                </button>

                <div class="collapse mt-2" id="stavke-<?= (int) $tiket['id'] ?>">
                    <ul class="list-group list-group-flush">
                        <?php foreach ($tiket['stavke'] as $stavka): ?>
                            <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <div class="small">
                                    <?= bezbedno($stavka['domacin']) ?> — <?= bezbedno($stavka['gost']) ?>
                                    <span class="kvota-vrednost fw-semibold ms-1"><?= bezbedno($stavka['oznaka']) ?> <?= number_format((float) $stavka['kvota_vrednost'], 2) ?></span>
                                </div>
                                <div class="d-flex gap-1 align-items-center">
                                    <form method="post" class="d-flex gap-1"
                                          onsubmit="return confirm('Promeniti status ove stavke? Ishod tiketa se time ne preračunava.');">
                                        <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                                        <input type="hidden" name="akcija" value="status_stavke">
                                        <input type="hidden" name="id" value="<?= (int) $stavka['id'] ?>">
                                        <select name="status" class="form-select form-select-sm py-0" style="width:7.5rem"
                                            <?= $tiket['status'] !== 'aktivan' ? 'disabled' : '' ?>>
                                            <?php foreach (['ceka', 'pogodjen', 'promasen', 'storniran'] as $status_stavke): ?>
                                                <option value="<?= $status_stavke ?>" <?= $stavka['status'] === $status_stavke ? 'selected' : '' ?>><?= $status_stavke ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-outline-primary py-0"
                                            <?= $tiket['status'] !== 'aktivan' ? 'disabled title="Tiket je već obrađen"' : '' ?>>&check;</button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Obrisati ovu stavku sa tiketa?');">
                                        <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                                        <input type="hidden" name="akcija" value="obrisi_stavku">
                                        <input type="hidden" name="id" value="<?= (int) $stavka['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger py-0"
                                            <?= $tiket['status'] !== 'aktivan' ? 'disabled title="Tiket je već obrađen"' : '' ?>>&times;</button>
                                    </form>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/podnozje.php'; ?>

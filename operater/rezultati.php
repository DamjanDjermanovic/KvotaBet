<?php
// Unos rezultata i zaključivanje događaja. Sav težak posao radi
// obradi_dogadjaj() iz includes/rezultati.php - ova stranica samo prikuplja
// unos, proverava prava i CSRF, i prikazuje izveštaj o obradi.
//
// Posle obrade se uvek preusmerava (POST-redirect-GET), pa osvežavanje
// stranice ne šalje formu ponovo. Čak i da je pošalje, obrada je idempotentna
// i drugi pokušaj biva odbijen.

require_once __DIR__ . '/../includes/autentifikacija.php';
require_once __DIR__ . '/../includes/funkcije.php';
require_once __DIR__ . '/../includes/rezultati.php';

zahtevaj_ulogu('operater', 'admin');

$pdo = konektuj_bazu();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_ispravan($_POST['csrf_token'] ?? null)) {
        postavi_poruku('danger', 'Nevažeća sesija. Osvežite stranicu i pokušajte ponovo.');
        preusmeri(KORENSKI_URL . '/operater/rezultati.php');
    }

    $akcija = $_POST['akcija'] ?? '';
    $dogadjaj_id = ctype_digit((string) ($_POST['dogadjaj_id'] ?? '')) ? (int) $_POST['dogadjaj_id'] : null;

    if ($dogadjaj_id === null) {
        postavi_poruku('danger', 'Neispravan događaj.');
        preusmeri(KORENSKI_URL . '/operater/rezultati.php');
    }

    try {
        if ($akcija === 'rezultat') {
            $domacin_unos = trim($_POST['golova_domacin'] ?? '');
            $gost_unos = trim($_POST['golova_gost'] ?? '');

            if (!ctype_digit($domacin_unos) || !ctype_digit($gost_unos)) {
                throw new RuntimeException('Rezultat mora biti ceo broj, nula ili više, za oba tima.');
            }

            $izvestaj = obradi_dogadjaj($pdo, $dogadjaj_id, 'zavrsen', (int) $domacin_unos, (int) $gost_unos);

            $poruka = 'Rezultat je upisan i tiketi su obrađeni. '
                . 'Stavki: ' . $izvestaj['stavki']
                . ' (pogođenih ' . $izvestaj['pogodjenih'] . ', promašenih ' . $izvestaj['promasenih'] . '). '
                . 'Tiketa — dobitnih: ' . $izvestaj['dobitnih']
                . ', gubitnih: ' . $izvestaj['gubitnih']
                . ', još aktivnih: ' . $izvestaj['jos_aktivnih'] . '. '
                . 'Ukupno isplaćeno: ' . formatiraj_novac($izvestaj['ukupno_isplaceno']) . '.';

            postavi_poruku('success', $poruka);
        } elseif ($akcija === 'otkazi') {
            $izvestaj = obradi_dogadjaj($pdo, $dogadjaj_id, 'otkazan', null, null);

            $poruka = 'Događaj je otkazan. '
                . 'Storniranih stavki: ' . $izvestaj['storniranih_stavki'] . '. '
                . 'Tiketa — storniranih: ' . $izvestaj['storniranih']
                . ', dobitnih: ' . $izvestaj['dobitnih']
                . ', gubitnih: ' . $izvestaj['gubitnih']
                . ', još aktivnih: ' . $izvestaj['jos_aktivnih'] . '. '
                . 'Ukupno vraćeno igračima: ' . formatiraj_novac($izvestaj['ukupno_isplaceno']) . '.';

            postavi_poruku('success', $poruka);
        } else {
            postavi_poruku('danger', 'Nepoznata akcija.');
        }
    } catch (PDOException $e) {
        // PDOException se hvata pre RuntimeException jer je njegov naslednik
        upisi_gresku('Greška pri obradi rezultata događaja ' . $dogadjaj_id . ': ' . $e->getMessage());
        postavi_poruku('danger', 'Trenutno nije moguće obraditi rezultat. Pokušajte ponovo kasnije.');
    } catch (RuntimeException $e) {
        // Prekršeno poslovno pravilo - poruka je unapred napisana i bezbedna za prikaz
        postavi_poruku('danger', $e->getMessage());
    }

    preusmeri(KORENSKI_URL . '/operater/rezultati.php');
}

try {
    // Događaji koji čekaju obradu - najstariji prvi, jer njima rezultat
    // najviše "kasni"
    $za_obradu = $pdo->query(
        "SELECT d.*, l.naziv AS liga_naziv, s.naziv AS sport_naziv,
                td.naziv AS domacin_naziv, tg.naziv AS gost_naziv,
                (SELECT COUNT(*) FROM tiket_stavka ts
                 JOIN kvota k ON k.id = ts.kvota_id
                 WHERE k.dogadjaj_id = d.id AND ts.status = 'ceka') AS stavki_na_cekanju
         FROM dogadjaj d
         JOIN liga l ON l.id = d.liga_id
         JOIN sport s ON s.id = l.sport_id
         JOIN tim td ON td.id = d.domacin_id
         JOIN tim tg ON tg.id = d.gost_id
         WHERE d.status IN ('najavljen', 'u_toku')
         ORDER BY d.vreme_pocetka ASC"
    )->fetchAll();

    $obradjeni = $pdo->query(
        "SELECT d.*, l.naziv AS liga_naziv, s.naziv AS sport_naziv,
                td.naziv AS domacin_naziv, tg.naziv AS gost_naziv,
                (SELECT COUNT(*) FROM tiket_stavka ts
                 JOIN kvota k ON k.id = ts.kvota_id
                 WHERE k.dogadjaj_id = d.id) AS ukupno_stavki
         FROM dogadjaj d
         JOIN liga l ON l.id = d.liga_id
         JOIN sport s ON s.id = l.sport_id
         JOIN tim td ON td.id = d.domacin_id
         JOIN tim tg ON tg.id = d.gost_id
         WHERE d.status IN ('zavrsen', 'otkazan')
         ORDER BY d.vreme_pocetka DESC
         LIMIT 25"
    )->fetchAll();
} catch (PDOException $e) {
    upisi_gresku('Greška pri učitavanju događaja za obradu: ' . $e->getMessage());
    $za_obradu = [];
    $obradjeni = [];
}

$csrf_token = generisi_csrf_token();
$naslov_stranice = 'Rezultati';
require_once __DIR__ . '/../includes/zaglavlje.php';
?>

<h1 class="h3 mb-4">Rezultati i obrada tiketa</h1>

<?php prikazi_poruke(); ?>

<div class="alert alert-secondary small">
    Upis rezultata je konačan: čim se događaj zaključi ili otkaže, tiketi se obrađuju
    i dobici isplaćuju, pa se isti događaj više ne može obraditi ponovo.
</div>

<h2 class="h5 mb-3">Za obradu</h2>

<?php if (empty($za_obradu)): ?>
    <div class="alert alert-info">Nema događaja koji čekaju obradu.</div>
<?php else: ?>
    <div class="table-responsive mb-5">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Događaj</th>
                    <th>Vreme</th>
                    <th>Stavki na čekanju</th>
                    <th style="width:20rem">Rezultat i obrada</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($za_obradu as $dogadjaj): ?>
                    <?php $vec_pocelo = strtotime($dogadjaj['vreme_pocetka']) <= time(); ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= bezbedno($dogadjaj['domacin_naziv']) ?> — <?= bezbedno($dogadjaj['gost_naziv']) ?></div>
                            <div class="text-muted small"><?= bezbedno($dogadjaj['sport_naziv']) ?> / <?= bezbedno($dogadjaj['liga_naziv']) ?></div>
                        </td>
                        <td>
                            <?= bezbedno(formatiraj_datum_vreme($dogadjaj['vreme_pocetka'])) ?>
                            <?php if ($vec_pocelo): ?>
                                <span class="badge text-bg-warning d-block mt-1">čeka rezultat</span>
                            <?php else: ?>
                                <span class="badge text-bg-light text-dark d-block mt-1">još nije počeo</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $dogadjaj['stavki_na_cekanju'] ?></td>
                        <td>
                            <form method="post" class="d-flex align-items-center gap-2 flex-wrap">
                                <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                                <input type="hidden" name="dogadjaj_id" value="<?= (int) $dogadjaj['id'] ?>">

                                <input type="number" class="form-control form-control-sm" style="width:4.5rem"
                                       name="golova_domacin" min="0" max="255" step="1" required placeholder="dom">
                                <span>:</span>
                                <input type="number" class="form-control form-control-sm" style="width:4.5rem"
                                       name="golova_gost" min="0" max="255" step="1" required placeholder="gost">

                                <button type="submit" name="akcija" value="rezultat" class="btn btn-sm btn-success"
                                        onclick="return confirm('Zaključiti događaj sa unetim rezultatom? Tiketi će odmah biti obrađeni i dobici isplaćeni.');">
                                    Zaključi
                                </button>

                                <?php // formnovalidate je nužan: otkazivanje ne traži rezultat, pa polja smeju ostati prazna ?>
                                <button type="submit" name="akcija" value="otkazi" class="btn btn-sm btn-outline-danger" formnovalidate
                                        onclick="return confirm('Otkazati događaj? Sve stavke se storniraju, a tiketima koji zavise samo od njega vraća se ulog.');">
                                    Otkaži
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<h2 class="h5 mb-3">Obrađeni događaji</h2>

<?php if (empty($obradjeni)): ?>
    <div class="alert alert-info">Nijedan događaj još nije obrađen.</div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead>
                <tr>
                    <th>Događaj</th>
                    <th>Vreme</th>
                    <th>Rezultat</th>
                    <th>Status</th>
                    <th>Stavki</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($obradjeni as $dogadjaj): ?>
                    <tr>
                        <td>
                            <div><?= bezbedno($dogadjaj['domacin_naziv']) ?> — <?= bezbedno($dogadjaj['gost_naziv']) ?></div>
                            <div class="text-muted small"><?= bezbedno($dogadjaj['sport_naziv']) ?> / <?= bezbedno($dogadjaj['liga_naziv']) ?></div>
                        </td>
                        <td><?= bezbedno(formatiraj_datum_vreme($dogadjaj['vreme_pocetka'])) ?></td>
                        <td>
                            <?php if ($dogadjaj['status'] === 'zavrsen'): ?>
                                <strong><?= (int) $dogadjaj['golova_domacin'] ?> : <?= (int) $dogadjaj['golova_gost'] ?></strong>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge text-bg-<?= $dogadjaj['status'] === 'zavrsen' ? 'success' : 'secondary' ?>">
                                <?= bezbedno($dogadjaj['status']) ?>
                            </span>
                        </td>
                        <td><?= (int) $dogadjaj['ukupno_stavki'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/podnozje.php'; ?>

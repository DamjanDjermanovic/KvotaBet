<?php
// Istorija tiketa igrača, sa detaljima stavki po tiketu (Bootstrap collapse,
// bez AJAX-a - sve stavke se učitaju odjednom jer ih po igraču ima malo).

require_once __DIR__ . '/../includes/autentifikacija.php';

zahtevaj_ulogu('igrac');

$pdo = konektuj_bazu();
$korisnik_id = (int) $_SESSION['korisnik_id'];
$tiketi = [];

try {
    $upit = $pdo->prepare(
        'SELECT id, broj_tiketa, ulog, ukupna_kvota, potencijalni_dobitak, isplaceno, status, datum_uplate, datum_obrade
         FROM tiket WHERE korisnik_id = ? ORDER BY datum_uplate DESC'
    );
    $upit->execute([$korisnik_id]);
    $tiketi = $upit->fetchAll();

    $upitStavke = $pdo->prepare(
        "SELECT ts.tiket_id, ts.kvota_vrednost, ts.status AS stavka_status,
                ti.oznaka, td.naziv AS domacin, tg.naziv AS gost,
                d.vreme_pocetka, l.naziv AS liga_naziv
         FROM tiket_stavka ts
         JOIN kvota k    ON k.id = ts.kvota_id
         JOIN tip_igre ti ON ti.id = k.tip_igre_id
         JOIN dogadjaj d ON d.id = k.dogadjaj_id
         JOIN tim td     ON td.id = d.domacin_id
         JOIN tim tg     ON tg.id = d.gost_id
         JOIN liga l     ON l.id = d.liga_id
         WHERE ts.tiket_id = ?
         ORDER BY ts.id"
    );

    foreach ($tiketi as &$tiket) {
        $upitStavke->execute([$tiket['id']]);
        $tiket['stavke'] = $upitStavke->fetchAll();
    }
    unset($tiket);
} catch (PDOException $e) {
    upisi_gresku('Greška pri učitavanju istorije tiketa: ' . $e->getMessage());
}

// Boja bedža statusa tiketa/stavke - ista paleta za oba nivoa
function boja_statusa(string $status): string
{
    return match ($status) {
        'dobitan', 'pogodjen' => 'success',
        'gubitan', 'promasen' => 'danger',
        'storniran' => 'secondary',
        'ceka' => 'secondary',
        default => 'primary', // aktivan
    };
}

$naslov_stranice = 'Moji tiketi';
require_once __DIR__ . '/../includes/zaglavlje.php';
?>

<h1 class="h3 mb-4">Moji tiketi</h1>

<?php if (empty($tiketi)): ?>
    <div class="alert alert-info">Još uvek niste uplatili nijedan tiket.</div>
<?php else: ?>
    <?php foreach ($tiketi as $tiket): ?>
        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <strong><?= bezbedno($tiket['broj_tiketa']) ?></strong>
                    <span class="text-muted small ms-2"><?= bezbedno(formatiraj_datum_vreme($tiket['datum_uplate'])) ?></span>
                </div>
                <span class="badge text-bg-<?= boja_statusa($tiket['status']) ?> text-uppercase"><?= bezbedno($tiket['status']) ?></span>
            </div>
            <div class="card-body">
                <div class="row text-center mb-3">
                    <div class="col-4">
                        <div class="text-muted small">Ulog</div>
                        <div class="fw-semibold"><?= bezbedno(formatiraj_novac($tiket['ulog'])) ?></div>
                    </div>
                    <div class="col-4">
                        <div class="text-muted small">Ukupna kvota</div>
                        <div class="fw-semibold"><?= number_format((float) $tiket['ukupna_kvota'], 2) ?></div>
                    </div>
                    <div class="col-4">
                        <div class="text-muted small">Potencijalni dobitak</div>
                        <div class="fw-semibold"><?= bezbedno(formatiraj_novac($tiket['potencijalni_dobitak'])) ?></div>
                    </div>
                </div>

                <?php if ((float) $tiket['isplaceno'] > 0): ?>
                    <p class="text-success small mb-3">Isplaćeno: <?= bezbedno(formatiraj_novac($tiket['isplaceno'])) ?></p>
                <?php endif; ?>

                <button class="btn btn-outline-secondary btn-sm" type="button"
                        data-bs-toggle="collapse" data-bs-target="#stavke-<?= (int) $tiket['id'] ?>">
                    Prikaži <?= count($tiket['stavke']) ?> <?= count($tiket['stavke']) === 1 ? 'par' : 'para' ?>
                </button>

                <div class="collapse mt-3" id="stavke-<?= (int) $tiket['id'] ?>">
                    <ul class="list-group list-group-flush">
                        <?php foreach ($tiket['stavke'] as $stavka): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="small fw-semibold"><?= bezbedno($stavka['domacin']) ?> — <?= bezbedno($stavka['gost']) ?></div>
                                    <div class="text-muted" style="font-size:.75rem">
                                        <?= bezbedno($stavka['liga_naziv']) ?> · <?= bezbedno(formatiraj_datum_vreme($stavka['vreme_pocetka'])) ?>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge text-bg-primary"><?= bezbedno($stavka['oznaka']) ?> <?= number_format((float) $stavka['kvota_vrednost'], 2) ?></span>
                                    <span class="badge text-bg-<?= boja_statusa($stavka['stavka_status']) ?>"><?= bezbedno($stavka['stavka_status']) ?></span>
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

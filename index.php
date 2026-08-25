<?php
// Početna strana - javna ponuda: sportovi, lige, događaji i kvote, sa
// filtriranjem po sportu i ligi. Dostupna svima, gostima i prijavljenima.

require_once __DIR__ . '/includes/autentifikacija.php';

$pdo = konektuj_bazu();

$sport_id = isset($_GET['sport_id']) && ctype_digit($_GET['sport_id']) ? (int) $_GET['sport_id'] : null;
$liga_id  = isset($_GET['liga_id']) && ctype_digit($_GET['liga_id']) ? (int) $_GET['liga_id'] : null;

$sportovi = [];
$lige = [];
$dogadjaji = [];

try {
    $sportovi = $pdo->query('SELECT id, naziv, ikonica FROM sport WHERE aktivan = 1 ORDER BY naziv')->fetchAll();

    // Lista liga u padajućem meniju prati izabrani sport - kad korisnik
    // promeni sport, meni lige se odmah suzi samo na njegove lige
    if ($sport_id !== null) {
        $upitLige = $pdo->prepare('SELECT id, naziv FROM liga WHERE sport_id = ? ORDER BY naziv');
        $upitLige->execute([$sport_id]);
    } else {
        $upitLige = $pdo->query('SELECT id, naziv FROM liga ORDER BY naziv');
    }
    $lige = $upitLige->fetchAll();

    // Ako izabrana liga ne pripada trenutno izabranom sportu (npr. korisnik
    // ručno izmenio parametre u URL-u), filter po ligi se zanemaruje
    if ($liga_id !== null && !in_array($liga_id, array_column($lige, 'id'), true)) {
        $liga_id = null;
    }

    $sqlDogadjaji = "SELECT d.id, d.vreme_pocetka,
                             s.id AS sport_id, s.naziv AS sport_naziv, s.ikonica,
                             l.id AS liga_id, l.naziv AS liga_naziv,
                             td.naziv AS domacin_naziv, tg.naziv AS gost_naziv
                      FROM dogadjaj d
                      JOIN liga l ON l.id = d.liga_id
                      JOIN sport s ON s.id = l.sport_id
                      JOIN tim td ON td.id = d.domacin_id
                      JOIN tim tg ON tg.id = d.gost_id
                      WHERE d.status = 'najavljen' AND d.vreme_pocetka > NOW()";
    $parametri = [];

    if ($sport_id !== null) {
        $sqlDogadjaji .= ' AND s.id = ?';
        $parametri[] = $sport_id;
    }
    if ($liga_id !== null) {
        $sqlDogadjaji .= ' AND l.id = ?';
        $parametri[] = $liga_id;
    }

    $sqlDogadjaji .= ' ORDER BY s.naziv, l.naziv, d.vreme_pocetka';

    $upitDogadjaji = $pdo->prepare($sqlDogadjaji);
    $upitDogadjaji->execute($parametri);
    $dogadjaji = $upitDogadjaji->fetchAll();

    // Kvote se učitavaju posebno za svaki događaj - broj događaja u ponudi
    // je mali za potrebe ovog projekta pa dodatni upiti po događaju ne
    // predstavljaju problem za performanse
    $upitKvote = $pdo->prepare(
        'SELECT k.id, k.vrednost, ti.oznaka
         FROM kvota k JOIN tip_igre ti ON ti.id = k.tip_igre_id
         WHERE k.dogadjaj_id = ? AND k.aktivna = 1
         ORDER BY ti.id'
    );

    foreach ($dogadjaji as &$dogadjaj) {
        $upitKvote->execute([$dogadjaj['id']]);
        $dogadjaj['kvote'] = $upitKvote->fetchAll();
    }
    unset($dogadjaj);
} catch (PDOException $e) {
    upisi_gresku('Greška pri učitavanju ponude: ' . $e->getMessage());
}

// Token se prosleđuje panelu tiketa da bi ga JS slao uz svaki AJAX zahtev
$csrf_token = generisi_csrf_token();

$naslov_stranice = 'Ponuda';
require_once __DIR__ . '/includes/zaglavlje.php';
?>

<h1 class="h3 mb-4">Ponuda događaja</h1>

<div class="row g-4">
<div class="col-lg-8">

<form method="get" class="row g-2 align-items-end mb-4" id="forma-filter">
    <div class="col-auto">
        <label for="sport_id" class="form-label">Sport</label>
        <select class="form-select" id="sport_id" name="sport_id" onchange="this.form.submit()">
            <option value="">Svi sportovi</option>
            <?php foreach ($sportovi as $sport): ?>
                <option value="<?= (int) $sport['id'] ?>" <?= $sport_id === (int) $sport['id'] ? 'selected' : '' ?>>
                    <?= bezbedno($sport['naziv']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <label for="liga_id" class="form-label">Liga</label>
        <select class="form-select" id="liga_id" name="liga_id" onchange="this.form.submit()">
            <option value="">Sve lige</option>
            <?php foreach ($lige as $liga): ?>
                <option value="<?= (int) $liga['id'] ?>" <?= $liga_id === (int) $liga['id'] ? 'selected' : '' ?>>
                    <?= bezbedno($liga['naziv']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($sport_id !== null || $liga_id !== null): ?>
        <div class="col-auto">
            <a href="<?= KORENSKI_URL ?>/index.php" class="btn btn-outline-secondary">Poništi filter</a>
        </div>
    <?php endif; ?>
    <noscript>
        <div class="col-auto"><button type="submit" class="btn btn-primary">Filtriraj</button></div>
    </noscript>
</form>

<?php if (empty($dogadjaji)): ?>
    <div class="alert alert-info">Trenutno nema najavljenih događaja za izabrani filter.</div>
<?php else: ?>
    <?php
    $tekuci_sport = null;
    $tekuca_liga = null;
    ?>
    <?php foreach ($dogadjaji as $dogadjaj): ?>
        <?php if ($tekuci_sport !== (int) $dogadjaj['sport_id']): ?>
            <?php $tekuci_sport = (int) $dogadjaj['sport_id']; $tekuca_liga = null; ?>
            <h2 class="h4 mt-4">
                <?php if (!empty($dogadjaj['ikonica'])): ?><i class="bi <?= bezbedno($dogadjaj['ikonica']) ?>"></i><?php endif; ?>
                <?= bezbedno($dogadjaj['sport_naziv']) ?>
            </h2>
        <?php endif; ?>

        <?php if ($tekuca_liga !== (int) $dogadjaj['liga_id']): ?>
            <?php $tekuca_liga = (int) $dogadjaj['liga_id']; ?>
            <h3 class="h6 text-muted mt-3"><?= bezbedno($dogadjaj['liga_naziv']) ?></h3>
        <?php endif; ?>

        <div class="card mb-2">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <div class="fw-semibold"><?= bezbedno($dogadjaj['domacin_naziv']) ?> — <?= bezbedno($dogadjaj['gost_naziv']) ?></div>
                    <div class="text-muted small"><?= bezbedno(formatiraj_datum_vreme($dogadjaj['vreme_pocetka'])) ?></div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($dogadjaj['kvote'] as $kvota): ?>
                        <button type="button" class="btn btn-outline-primary btn-sm dugme-kvota"
                                data-kvota-id="<?= (int) $kvota['id'] ?>" aria-pressed="false">
                            <?= bezbedno($kvota['oznaka']) ?> <strong><?= number_format((float) $kvota['vrednost'], 2) ?></strong>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

</div><!-- kraj kolone sa ponudom -->

<div class="col-lg-4">
    <?php // Panel puni js/tiket.js preko AJAX-a, pa se ovde ispisuje samo kostur ?>
    <div class="card shadow-sm tiket-panel" id="tiket-panel"
         data-koren="<?= KORENSKI_URL ?>" data-csrf="<?= bezbedno($csrf_token) ?>">

        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Tiket</span>
            <span class="badge text-bg-secondary" id="tiket-broj-stavki">0</span>
        </div>

        <div id="tiket-poruka"></div>

        <ul class="list-group list-group-flush" id="tiket-lista"></ul>

        <div class="card-body text-muted small" id="tiket-prazan">
            Kliknite na kvotu u ponudi da dodate par na tiket.
        </div>

        <div class="card-body border-top">
            <div class="d-flex justify-content-between">
                <span>Ukupna kvota:</span><strong id="tiket-ukupna-kvota">—</strong>
            </div>
            <div class="d-flex justify-content-between">
                <span>Potencijalni dobitak:</span><strong id="tiket-dobitak">—</strong>
            </div>
            <button type="button" class="btn btn-outline-secondary btn-sm w-100 mt-3 d-none" id="dugme-ocisti">
                Isprazni tiket
            </button>
        </div>

        <?php if (trenutna_uloga() === 'igrac'): ?>
            <div class="card-body border-top">
                <form id="forma-tiket" novalidate>
                    <div class="small text-muted mb-2">Stanje računa: <span id="tiket-stanje">—</span></div>

                    <label for="tiket-ulog" class="form-label">Ulog</label>
                    <input type="number" class="form-control" id="tiket-ulog" name="ulog" required
                           step="0.01" min="<?= MIN_ULOG ?>" max="<?= MAX_ULOG ?>" value="<?= MIN_ULOG ?>">
                    <div class="small text-danger mt-1" id="tiket-ulog-greska"></div>

                    <button type="submit" class="btn btn-success w-100 mt-3" id="dugme-uplati" disabled>
                        Uplati tiket
                    </button>
                </form>
            </div>
        <?php elseif (korisnik_prijavljen()): ?>
            <div class="card-body border-top small text-muted">
                Uplata tiketa je dostupna samo igračima.
            </div>
        <?php else: ?>
            <div class="card-body border-top">
                <p class="small text-muted mb-2">Prijavite se da biste uplatili tiket.</p>
                <a href="<?= KORENSKI_URL ?>/prijava.php" class="btn btn-primary btn-sm w-100">Prijava</a>
            </div>
        <?php endif; ?>
    </div>
</div>
</div><!-- kraj reda -->

<?php
$dodatne_skripte = ['tiket.js'];
require_once __DIR__ . '/includes/podnozje.php';
?>

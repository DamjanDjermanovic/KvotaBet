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
    $sportovi = $pdo->query('SELECT id, naziv FROM sport WHERE aktivan = 1 ORDER BY naziv')->fetchAll();

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
                             s.id AS sport_id, s.naziv AS sport_naziv,
                             l.id AS liga_id, l.naziv AS liga_naziv, l.drzava AS liga_drzava,
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

<h1 class="mb-4">Ponuda događaja</h1>

<div class="row g-4">
<div class="col-lg-8">

<form method="get" class="row g-2 align-items-end mb-4" id="forma-filter">
    <div class="col-auto">
        <label for="sport_id" class="form-label">Sport</label>
        <select class="form-select filter-izbor" id="sport_id" name="sport_id" onchange="this.form.submit()">
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
        <select class="form-select filter-izbor" id="liga_id" name="liga_id" onchange="this.form.submit()">
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
    <div class="prazno">
        <i class="bi bi-calendar-x" aria-hidden="true"></i>
        <div class="naslov-praznog">Nema najavljenih događaja</div>
        <p class="mb-3">Za izabrani filter trenutno nema ničega u ponudi.</p>
        <a href="<?= KORENSKI_URL ?>/index.php" class="btn btn-primary btn-sm">Prikaži celu ponudu</a>
    </div>
<?php else: ?>
    <?php
    // Događaji stižu sortirani po sportu, ligi i vremenu, pa se zaglavlja
    // ispisuju "u prolazu". Redovi jedne lige moraju stajati u sopstvenom
    // omotaču: stil računa gornju ivicu i zaobljenje reda po tome da li je
    // prvi ili poslednji u svom bloku.
    $tekuci_sport = null;
    $tekuca_liga = null;
    $blok_otvoren = false;
    ?>
    <?php foreach ($dogadjaji as $dogadjaj): ?>
        <?php if ($tekuci_sport !== (int) $dogadjaj['sport_id']): ?>
            <?php if ($blok_otvoren): ?></div><?php $blok_otvoren = false; endif; ?>
            <?php $tekuci_sport = (int) $dogadjaj['sport_id']; $tekuca_liga = null; ?>
            <?php // Ikonica sporta se ovde namerno ne ispisuje ?>
            <h2 class="mt-4 mb-2"><?= bezbedno($dogadjaj['sport_naziv']) ?></h2>
        <?php endif; ?>

        <?php if ($tekuca_liga !== (int) $dogadjaj['liga_id']): ?>
            <?php if ($blok_otvoren): ?></div><?php $blok_otvoren = false; endif; ?>
            <?php $tekuca_liga = (int) $dogadjaj['liga_id']; ?>
            <div class="liga-zaglavlje mt-3">
                <i class="bi bi-trophy" aria-hidden="true"></i>
                <?= bezbedno($dogadjaj['liga_naziv']) ?>
                <?php if (!empty($dogadjaj['liga_drzava'])): ?>
                    <span class="drzava"><?= bezbedno($dogadjaj['liga_drzava']) ?></span>
                <?php endif; ?>
            </div>
            <div class="mb-3"><?php $blok_otvoren = true; ?>
        <?php endif; ?>

            <div class="dogadjaj-red">
                <div class="dogadjaj-vreme">
                    <span class="dan"><?= bezbedno(formatiraj_datum($dogadjaj['vreme_pocetka'])) ?></span>
                    <?= bezbedno(date('H:i', strtotime($dogadjaj['vreme_pocetka']))) ?>
                </div>

                <div class="dogadjaj-timovi">
                    <span class="tim"><?= bezbedno($dogadjaj['domacin_naziv']) ?></span>
                    <span class="protiv">protiv</span>
                    <span class="tim"><?= bezbedno($dogadjaj['gost_naziv']) ?></span>
                </div>

                <?php
                // Prva tri tipa igre (obično 1/X/2) stoje odmah u redu; ostatak je
                // iza dugmeta "+N", da red ne postane predugačak kod sportova sa
                // devet tipova igre (fudbal). Kvote već stižu sortirane po id-ju
                // tipa igre (index.php upit za $dogadjaj['kvote'] ide ORDER BY
                // ti.id), pa su prva tri baš 1/X/2.
                $vidljive_kvote = array_slice($dogadjaj['kvote'], 0, 3);
                $skrivene_kvote = array_slice($dogadjaj['kvote'], 3);
                $id_prosirenja = 'prosireno-' . (int) $dogadjaj['id'];
                ?>
                <div class="kvote-grupa">
                    <?php foreach ($vidljive_kvote as $kvota): ?>
                        <?php // aria-pressed prati .izabrana, da i čitač ekrana zna šta je na tiketu ?>
                        <button type="button" class="kvota dugme-kvota"
                                data-kvota-id="<?= (int) $kvota['id'] ?>" aria-pressed="false">
                            <span class="oznaka"><?= bezbedno($kvota['oznaka']) ?></span>
                            <span class="kvota-vrednost"><?= number_format((float) $kvota['vrednost'], 2) ?></span>
                        </button>
                    <?php endforeach; ?>

                    <?php if (!empty($skrivene_kvote)): ?>
                        <?php // Bez AJAX-a: dugme samo otkriva panel ispod reda, koji je već ispisan u HTML-u ?>
                        <button type="button" class="kvota dugme-prosiri-kvote"
                                data-cilj="<?= bezbedno($id_prosirenja) ?>"
                                data-oznaka-zatvoreno="+<?= count($skrivene_kvote) ?>"
                                data-oznaka-otvoreno="manje"
                                aria-expanded="false" aria-controls="<?= bezbedno($id_prosirenja) ?>">
                            <span class="kvota-vrednost">+<?= count($skrivene_kvote) ?></span>
                        </button>
                    <?php endif; ?>
                </div>

                <?php if (!empty($skrivene_kvote)): ?>
                    <div class="kvote-dodatne d-none" id="<?= bezbedno($id_prosirenja) ?>">
                        <?php foreach ($skrivene_kvote as $kvota): ?>
                            <button type="button" class="kvota dugme-kvota"
                                    data-kvota-id="<?= (int) $kvota['id'] ?>" aria-pressed="false">
                                <span class="oznaka"><?= bezbedno($kvota['oznaka']) ?></span>
                                <span class="kvota-vrednost"><?= number_format((float) $kvota['vrednost'], 2) ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
    <?php endforeach; ?>
    <?php if ($blok_otvoren): ?></div><?php endif; ?>
<?php endif; ?>

</div><!-- kraj kolone sa ponudom -->

<div class="col-lg-4">
    <?php // Panel puni js/tiket.js preko AJAX-a, pa se ovde ispisuje samo kostur ?>
    <div class="tiket-panel" id="tiket-panel"
         data-koren="<?= KORENSKI_URL ?>" data-csrf="<?= bezbedno($csrf_token) ?>">

        <div class="tiket-glava">
            <i class="bi bi-receipt" aria-hidden="true"></i>
            Tiket
            <span class="broj-parova" id="tiket-broj-stavki">0</span>
        </div>

        <?php // Nazubljena ivica listića - čist ukras, zato skrivena od čitača ekrana ?>
        <div class="tiket-zub" aria-hidden="true"></div>

        <div id="tiket-poruka"></div>

        <div id="tiket-lista"></div>

        <div class="tiket-prazan" id="tiket-prazan">
            <i class="bi bi-hand-index-thumb" aria-hidden="true"></i>
            Kliknite na kvotu u ponudi da dodate par na tiket.
        </div>

        <div class="tiket-zbir">
            <div class="red">
                <span>Ukupna kvota</span>
                <span class="vrednost" id="tiket-ukupna-kvota">—</span>
            </div>
            <div class="red dobitak-red">
                <span>Potencijalni dobitak</span>
                <span class="vrednost" id="tiket-dobitak">—</span>
            </div>

            <?php if (trenutna_uloga() === 'igrac'): ?>
                <form id="forma-tiket" class="mt-3" novalidate>
                    <div class="red">
                        <span>Stanje računa</span>
                        <span class="vrednost" id="tiket-stanje">—</span>
                    </div>

                    <label for="tiket-ulog" class="form-label mt-2">Ulog</label>
                    <input type="number" class="form-control" id="tiket-ulog" name="ulog" required
                           step="0.01" min="<?= MIN_ULOG ?>" max="<?= MAX_ULOG ?>" value="<?= MIN_ULOG ?>">
                    <div class="invalid-feedback d-block" id="tiket-ulog-greska"></div>

                    <button type="submit" class="btn btn-uplati mt-3" id="dugme-uplati" disabled>
                        Uplati tiket
                    </button>
                </form>
            <?php elseif (korisnik_prijavljen()): ?>
                <p class="small text-muted mb-0 mt-3">Uplata tiketa je dostupna samo igračima.</p>
            <?php else: ?>
                <p class="small text-muted mb-2 mt-3">Prijavite se da biste uplatili tiket.</p>
                <a href="<?= KORENSKI_URL ?>/prijava.php" class="btn btn-uplati">Prijava</a>
            <?php endif; ?>

            <button type="button" class="btn btn-outline-primary btn-sm w-100 mt-2 d-none" id="dugme-ocisti">
                Isprazni tiket
            </button>
        </div>
    </div>
</div>
</div><!-- kraj reda -->

<?php
$dodatne_skripte = ['tiket.js', 'ponuda.js'];
require_once __DIR__ . '/includes/podnozje.php';
?>

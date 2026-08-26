<?php
// Uvoz utakmica i kvota sa The Odds API-ja — POMOĆNI alat za operatera.
//
// Rad u dva koraka, jer se ništa ne sme upisati bez potvrde:
//   1. operater bira ligu i klikne "Preuzmi" — odgovor servisa (ili keš) se
//      prikazuje kao TABELA PREGLEDA, bez ijednog upisa u bazu;
//   2. čekira šta hoće i klikne "Sačuvaj u bazu" — tek tada se, u jednoj
//      transakciji, upisuju izabrani događaji i njihove kvote.
//
// Sve odluke o tome šta se upisuje donosi server iz keširanog odgovora
// (includes/uvoz.php); iz forme stižu samo identifikatori čekiranih utakmica.
//
// Ručni unos (operater/dogadjaji.php i operater/kvote.php) ovom stranicom nije
// ni dodirnut i radi bez interneta, bez ključa i bez ijednog reda u api_liga.

require_once __DIR__ . '/../includes/autentifikacija.php';
require_once __DIR__ . '/../includes/funkcije.php';
require_once __DIR__ . '/../includes/uvoz.php';

zahtevaj_ulogu('operater', 'admin');

$pdo = konektuj_bazu();

// Traži mapiranje po id-ju u već učitanom spisku, da se isti red ne bi
// dovlačio drugim upitom
function nadji_mapiranje(array $mapiranja, ?int $id): ?array
{
    foreach ($mapiranja as $mapiranje) {
        if ((int) $mapiranje['mapiranje_id'] === $id) {
            return $mapiranje;
        }
    }

    return null;
}

$mapiranja = ucitaj_mapirane_lige($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_ispravan($_POST['csrf_token'] ?? null)) {
        postavi_poruku('danger', 'Nevažeća sesija. Osvežite stranicu i pokušajte ponovo.');
        preusmeri(KORENSKI_URL . '/operater/uvoz.php');
    }

    $akcija = $_POST['akcija'] ?? '';
    $mapiranje_id = ctype_digit((string) ($_POST['mapiranje_id'] ?? '')) ? (int) $_POST['mapiranje_id'] : null;
    $mapiranje = nadji_mapiranje($mapiranja, $mapiranje_id);

    // Svaka akcija radi nad tačno jednom mapiranom ligom; bez nje se ne radi ništa
    if ($mapiranje === null) {
        postavi_poruku('danger', 'Izaberite ligu koja je mapirana na ključ sa servisa.');
        preusmeri(KORENSKI_URL . '/operater/uvoz.php');
    }

    $povratak = KORENSKI_URL . '/operater/uvoz.php?mapiranje_id=' . $mapiranje_id;

    if ($akcija === 'preuzmi') {
        // Jedino mesto na stranici koje sme da pozove servis. Ako je keš mlađi
        // od 30 minuta, odds_api_kvote() ga sam iskoristi i poziv se ne šalje -
        // osim kad je pritisnuto "Osveži sa servera" ($osvezi).
        $osvezi = !empty($_POST['osvezi']);
        $odgovor = odds_api_kvote($mapiranje['api_kljuc'], $osvezi);

        if (!$odgovor['uspeh']) {
            postavi_poruku('danger', $odgovor['poruka']);
        } elseif ($odgovor['poruka'] !== '') {
            // Uspeh uz poruku znači da je poziv pao, a prikazan je stari keš
            postavi_poruku('warning', $odgovor['poruka']);
        } elseif ($odgovor['iz_kesa']) {
            postavi_poruku('info', 'Prikazan je sačuvani odgovor od '
                . formatiraj_datum_vreme(date('Y-m-d H:i:s', (int) $odgovor['vreme_kesa']))
                . ' — poziv ka servisu nije poslat. Za nove podatke koristite „Osveži sa servera“.');
        } else {
            postavi_poruku('success', 'Preuzeto sa servisa: ' . count($odgovor['podaci']) . ' utakmica.');
        }

        preusmeri($povratak);
    } elseif ($akcija === 'povezi_tim') {
        // Rešavanje jednog nepoznatog tima iz pregleda: nov tim ili veza sa
        // postojećim. Odvojeno od "Sačuvaj u bazu" jer je to odluka o rečniku
        // timova, a ne o ponudi.
        $api_naziv = trim($_POST['api_naziv'] ?? '');
        $tim_id = ctype_digit((string) ($_POST['tim_id'] ?? '')) ? (int) $_POST['tim_id'] : null;

        $ishod = povezi_api_tim($pdo, (int) $mapiranje['id'], $api_naziv, $tim_id);
        postavi_poruku($ishod['uspeh'] ? 'success' : 'danger', $ishod['poruka']);

        preusmeri($povratak);
    } elseif ($akcija === 'sacuvaj') {
        // Drugi korak: upis. Odgovor servisa se ponovo čita SA DISKA - ni
        // vremena, ni timovi, ni kvote se ne uzimaju iz forme.
        $utakmice = odds_api_kvote_iz_kesa($mapiranje['api_kljuc']);

        if ($utakmice === null) {
            postavi_poruku('danger', 'Preuzeti podaci više nisu dostupni. Ponovo pritisnite „Preuzmi“ pa izaberite utakmice.');
            preusmeri($povratak);
        }

        $izabrani = $_POST['utakmica'] ?? [];

        if (!is_array($izabrani)) {
            $izabrani = [];
        }

        $ishod = upisi_uvezene_utakmice($pdo, $mapiranje, $utakmice, $izabrani, (int) $_SESSION['korisnik_id']);

        if ($ishod['uspeh'] && $ishod['uneto'] > 0) {
            postavi_poruku('success', 'Uneto događaja: ' . $ishod['uneto'] . ', kvota: ' . $ishod['kvota'] . '.');

            // Trag o grupnom upisu — isti razlog kao kod destruktivnih akcija:
            // po tabeli dogadjaj se posle ne vidi da su redovi stigli sa API-ja
            upisi_trag(
                $_SESSION['korisnicko_ime'] ?? '?',
                'uvezao sa API-ja (' . $mapiranje['api_kljuc'] . '): ' . $ishod['uneto'] . ' dogadjaja, ' . $ishod['kvota'] . ' kvota'
            );
        } elseif ($ishod['uspeh']) {
            postavi_poruku('warning', 'Nijedan događaj nije unet.');
        }

        if ($ishod['preskoceno'] > 0) {
            postavi_poruku('info', 'Preskočeno kao duplikat: ' . $ishod['preskoceno'] . '.');
        }

        foreach ($ishod['poruke'] as $poruka) {
            postavi_poruku($ishod['uspeh'] ? 'info' : 'danger', $poruka);
        }

        preusmeri($povratak);
    }

    preusmeri($povratak);
}

// --- Prikaz (GET) ---
// Stranica pri otvaranju NIKAD ne poziva servis: čita se samo keš. Poziv
// pokreće isključivo dugme "Preuzmi" / "Osveži sa servera".

$mapiranje_id = isset($_GET['mapiranje_id']) && ctype_digit($_GET['mapiranje_id']) ? (int) $_GET['mapiranje_id'] : null;
$izabrano_mapiranje = nadji_mapiranje($mapiranja, $mapiranje_id);

$kes = null;
$pregled = null;
$timovi_lige = [];
$oznake_sporta = [];
$oznake_bez_para = [];

if ($izabrano_mapiranje !== null) {
    $kes = procitaj_kes('kvote_' . $izabrano_mapiranje['api_kljuc']);

    if ($kes !== null) {
        $pregled = pripremi_pregled_uvoza($pdo, $kes['podaci'], $izabrano_mapiranje);
        $timovi_lige = timovi_lige($pdo, (int) $izabrano_mapiranje['id']);

        try {
            $upit = $pdo->prepare('SELECT oznaka FROM tip_igre WHERE sport_id = ?');
            $upit->execute([(int) $izabrano_mapiranje['sport_id']]);
            $sve_oznake = $upit->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            upisi_gresku('Greška pri učitavanju tipova igre za uvoz: ' . $e->getMessage());
            $sve_oznake = [];
        }

        // Kolone tabele pregleda su presek onoga što API daje i onoga što
        // sport uopšte ima u šifarniku - košarka nema "X", pa nema ni kolonu
        foreach (ODDS_API_OZNAKE as $oznaka) {
            if (in_array($oznaka, $sve_oznake, true)) {
                $oznake_sporta[] = $oznaka;
            } else {
                $oznake_bez_para[] = $oznaka;
            }
        }
    }
}

$csrf_token = generisi_csrf_token();
$naslov_stranice = 'Uvoz ponude';
require_once __DIR__ . '/../includes/zaglavlje.php';
?>

<div class="zaglavlje-strane">
    <h1>Uvoz ponude sa servisa</h1>
    <p class="podnaslov">
        Pomoćni alat: preuzima utakmice i kvote sa <span class="text-nowrap">the-odds-api.com</span> i
        nudi ih na pregled. U bazu ne ulazi ništa dok ne potvrdite izbor. Ručni unos kroz
        <a href="<?= KORENSKI_URL ?>/operater/dogadjaji.php">Događaje</a> radi nezavisno od ovoga.
    </p>
    <a href="<?= KORENSKI_URL ?>/operater/api_lige.php" class="btn btn-sm btn-outline-secondary nazad">
        <i class="bi bi-diagram-3" aria-hidden="true"></i> Mapiranje liga
    </a>
</div>

<?php prikazi_poruke(); ?>

<?php if (!odds_api_podesen()): ?>
    <div class="alert alert-warning">
        <strong>Uvoz nije podešen.</strong>
        Ključ za servis se upisuje u <code>config/api.php</code> (šablon je
        <code>config/api.primer.php</code>). Dok ključa nema, ponuda se unosi ručno —
        sve ostale stranice rade normalno.
    </div>
<?php endif; ?>

<?php if (empty($mapiranja)): ?>
    <div class="prazno">
        <i class="bi bi-cloud-arrow-down" aria-hidden="true"></i>
        <div class="naslov-praznog">Nijedna liga nije mapirana na servis</div>
        <p class="mb-2">
            Servis lige označava ključevima tipa <code>soccer_epl</code>. Da bi uvoz znao
            gde da upiše utakmice, ključ prvo treba povezati sa ligom iz baze.
        </p>
        <a href="<?= KORENSKI_URL ?>/operater/api_lige.php" class="btn btn-primary btn-sm">Otvori mapiranje liga</a>
    </div>
<?php else: ?>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h2 class="mb-3">1. Izbor lige</h2>
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                <input type="hidden" name="akcija" value="preuzmi">

                <div class="col-sm-6 col-lg-5">
                    <label for="mapiranje_id" class="form-label">Liga</label>
                    <select class="form-select filter-izbor" id="mapiranje_id" name="mapiranje_id" required>
                        <option value="">Izaberite ligu</option>
                        <?php foreach ($mapiranja as $mapiranje): ?>
                            <option value="<?= (int) $mapiranje['mapiranje_id'] ?>"
                                <?= $mapiranje_id === (int) $mapiranje['mapiranje_id'] ? 'selected' : '' ?>>
                                <?= bezbedno($mapiranje['sport_naziv']) ?> — <?= bezbedno($mapiranje['naziv']) ?>
                                (<?= bezbedno($mapiranje['api_kljuc']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php // data-ucitavanje: poziv ka servisu sme da traje do
                      // ODDS_API_TIMEOUT sekundi, pa dugme za to vreme nosi
                      // vrtešku (js/ucitavanje.js) ?>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary" data-ucitavanje="Preuzimanje...">
                        <i class="bi bi-cloud-arrow-down" aria-hidden="true"></i> Preuzmi
                    </button>
                </div>

                <div class="col-auto">
                    <?php // Isto dugme, samo sa zaobilaženjem keša - zato name/value, a ne zasebna forma ?>
                    <button type="submit" class="btn btn-outline-secondary" name="osvezi" value="1"
                            data-ucitavanje="Osvežavanje...">
                        <i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Osveži sa servera
                    </button>
                </div>

                <div class="col-12">
                    <div class="form-text">
                        „Preuzmi“ koristi sačuvani odgovor ako nije stariji od
                        <?= (int) round(ODDS_API_TRAJANJE_KESA / 60) ?> minuta i tada ne troši kvotu.
                        „Osveži sa servera“ uvek šalje nov poziv.
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ($izabrano_mapiranje !== null && $kes === null): ?>
        <div class="prazno">
            <i class="bi bi-inbox" aria-hidden="true"></i>
            <div class="naslov-praznog">Za ovu ligu još ništa nije preuzeto</div>
            <p class="mb-0">Pritisnite „Preuzmi“ iznad. Ako servis ne odgovori, ponudu unesite ručno.</p>
        </div>
    <?php endif; ?>

    <?php if ($pregled !== null): ?>
        <?php
        $starost_kesa = time() - (int) $kes['vreme'];
        $kes_svez = $starost_kesa < ODDS_API_TRAJANJE_KESA;
        $spremnih = 0;
        foreach ($pregled['redovi'] as $red) {
            if ($red['cekirano']) {
                $spremnih++;
            }
        }
        ?>

        <?php // Stanje alata: odakle su podaci, koliko je kredita ostalo i šta je spremno ?>
        <div class="uvoz-info mb-4">
            <div class="stavka <?= $kes_svez ? '' : 'istekao' ?>">
                <span class="oznaka">Podaci preuzeti</span>
                <span class="vrednost"><?= bezbedno(formatiraj_datum_vreme(date('Y-m-d H:i:s', (int) $kes['vreme']))) ?></span>
                <span class="dodatak">
                    <?= $kes_svez
                        ? 'pre ' . (int) floor($starost_kesa / 60) . ' min — keš je svež'
                        : 'keš je istekao, „Preuzmi“ će poslati nov poziv' ?>
                </span>
            </div>
            <div class="stavka">
                <span class="oznaka">Preostalo poziva</span>
                <span class="vrednost"><?= $kes['preostalo'] !== null ? (int) $kes['preostalo'] : '—' ?></span>
                <span class="dodatak">
                    <?= $kes['iskorisceno'] !== null
                        ? 'iskorišćeno ' . (int) $kes['iskorisceno'] . ' ovog meseca'
                        : 'servis nije prijavio stanje' ?>
                </span>
            </div>
            <div class="stavka">
                <span class="oznaka">Spremno za upis</span>
                <span class="vrednost"><?= $spremnih ?> / <?= count($pregled['redovi']) ?></span>
                <span class="dodatak">čekirano od ukupno preuzetih utakmica</span>
            </div>
        </div>

        <?php if (!empty($pregled['nepoznati_timovi'])): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <h2 class="mb-2">Nepoznati timovi</h2>
                    <p class="text-muted small mb-3">
                        Servis daje nazive na engleskom. Timove koji nisu prepoznati napravite ovde
                        jednim klikom, ili ih povežite sa timom koji već postoji u ligi
                        „<?= bezbedno($izabrano_mapiranje['naziv']) ?>“. Utakmice sa nepoznatim timom
                        se ne mogu upisati dok se to ne reši.
                    </p>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Naziv kod servisa</th>
                                    <th>Utakmica</th>
                                    <th style="width:22rem">Šta uraditi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pregled['nepoznati_timovi'] as $api_naziv => $broj): ?>
                                    <tr>
                                        <td><?= bezbedno($api_naziv) ?></td>
                                        <td><?= (int) $broj ?></td>
                                        <td>
                                            <form method="post" class="d-flex gap-2">
                                                <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                                                <input type="hidden" name="akcija" value="povezi_tim">
                                                <input type="hidden" name="mapiranje_id" value="<?= (int) $izabrano_mapiranje['mapiranje_id'] ?>">
                                                <input type="hidden" name="api_naziv" value="<?= bezbedno($api_naziv) ?>">
                                                <select class="form-select form-select-sm" name="tim_id">
                                                    <option value="">napravi novi tim</option>
                                                    <?php foreach ($timovi_lige as $tim): ?>
                                                        <option value="<?= (int) $tim['id'] ?>">
                                                            poveži sa: <?= bezbedno($tim['naziv']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">Poveži</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="mb-2">2. Pregled pre upisa</h2>
                <p class="text-muted small mb-3">
                    Kvota je prosek svih kladionica koje su je ponudile, zaokružen na dve decimale.
                    Tržište <code>h2h</code> daje 1/X/2, a <code>totals</code> na liniji
                    <?= bezbedno(number_format(ODDS_API_LINIJA_TOTALS, 1, ',', '')) ?> daje 0-2 i 3+.
                    Ostale tipove (GG, dupla šansa) besplatni nivo servisa ne daje — dodaju se
                    dugmetom „Dodaj sve tipove“ na stranici sa kvotama.
                    <?php if (!empty($oznake_bez_para)): ?>
                        <br>Sport „<?= bezbedno($izabrano_mapiranje['sport_naziv']) ?>“ nema tipove
                        <?= bezbedno(implode(', ', $oznake_bez_para)) ?> u šifarniku, pa se te kvote ne uvoze.
                    <?php endif; ?>
                </p>

                <?php if (empty($pregled['redovi'])): ?>
                    <div class="prazno">
                        <i class="bi bi-calendar-x" aria-hidden="true"></i>
                        <div class="naslov-praznog">Servis za ovu ligu trenutno ne nudi nijednu utakmicu</div>
                        <p class="mb-0">Van sezone je to očekivano. Događaje možete uneti ručno.</p>
                    </div>
                <?php else: ?>
                    <form method="post" id="forma-uvoz">
                        <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                        <input type="hidden" name="akcija" value="sacuvaj">
                        <input type="hidden" name="mapiranje_id" value="<?= (int) $izabrano_mapiranje['mapiranje_id'] ?>">

                        <div class="table-responsive mb-3">
                            <table class="table table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width:2.5rem">
                                            <input type="checkbox" class="form-check-input" id="uvoz-sve"
                                                   aria-label="Označi sve utakmice koje se mogu uneti">
                                        </th>
                                        <th>Vreme</th>
                                        <th>Utakmica</th>
                                        <?php foreach ($oznake_sporta as $oznaka): ?>
                                            <th class="text-center"><?= bezbedno($oznaka) ?></th>
                                        <?php endforeach; ?>
                                        <th>Stanje</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pregled['redovi'] as $indeks => $red): ?>
                                        <?php $id_polja = 'utakmica-' . $indeks; ?>
                                        <tr>
                                            <td>
                                                <?php if ($red['moze']): ?>
                                                    <input type="checkbox" class="form-check-input" name="utakmica[]"
                                                           id="<?= bezbedno($id_polja) ?>"
                                                           value="<?= bezbedno($red['api_id']) ?>"
                                                        <?= $red['cekirano'] ? 'checked' : '' ?>>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-nowrap">
                                                <?= $red['vreme'] !== null ? bezbedno(formatiraj_datum_vreme($red['vreme'])) : '—' ?>
                                            </td>
                                            <td>
                                                <label for="<?= bezbedno($id_polja) ?>" class="mb-0">
                                                    <?= bezbedno($red['api_domacin']) ?> — <?= bezbedno($red['api_gost']) ?>
                                                </label>
                                                <?php if ($red['domacin_naziv'] !== null && $red['gost_naziv'] !== null): ?>
                                                    <div class="small text-muted">
                                                        u bazi: <?= bezbedno($red['domacin_naziv']) ?> — <?= bezbedno($red['gost_naziv']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <?php foreach ($oznake_sporta as $oznaka): ?>
                                                <td class="text-center broj">
                                                    <?= isset($red['kvote'][$oznaka]) ? bezbedno(number_format($red['kvote'][$oznaka], 2, ',', '')) : '—' ?>
                                                </td>
                                            <?php endforeach; ?>
                                            <td>
                                                <span class="status status-uvoz-<?= bezbedno($red['status']) ?>">
                                                    <?= bezbedno(opis_statusa_uvoza($red['status'])) ?>
                                                </span>
                                                <?php if ($red['napomena'] !== ''): ?>
                                                    <div class="small text-muted"><?= bezbedno($red['napomena']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <button type="submit" class="btn btn-primary" data-ucitavanje="Upisivanje...">
                            <i class="bi bi-database-add" aria-hidden="true"></i> Sačuvaj u bazu
                        </button>
                        <span class="form-text ms-2">
                            Upisuju se samo čekirani redovi, i to u jednoj transakciji baze.
                        </span>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php
$dodatne_skripte = ['operater.js'];
require_once __DIR__ . '/../includes/podnozje.php';
?>

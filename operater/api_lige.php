<?php
// CRUD nad tabelom api_liga - mapiranje ključeva liga sa The Odds API-ja
// (npr. 'soccer_epl') na lige u ovoj bazi.
//
// Bez ovog mapiranja uvoz ne zna u koju ligu da upiše preuzete utakmice, pa
// je ovo prvi korak pre stranice operater/uvoz.php. Sama stranica ne poziva
// API pri otvaranju: spisak liga se čita iz keša, a poziv se šalje samo kad
// operater pritisne dugme za preuzimanje spiska.

require_once __DIR__ . '/../includes/autentifikacija.php';
require_once __DIR__ . '/../includes/funkcije.php';
require_once __DIR__ . '/../includes/odds_api.php';

zahtevaj_ulogu('operater', 'admin');

$pdo = konektuj_bazu();

// Ključevi kod ovog servisa su oblika soccer_epl, basketball_nba - mala slova,
// cifre i donja crta. Provera je i odbrana od unosa koji bi kasnije završio
// u putanji poziva.
function ispravan_api_kljuc(string $kljuc): bool
{
    return (bool) preg_match('/^[a-z0-9_]{3,60}$/', $kljuc);
}

// Čitljiv naziv koji servis prijavljuje za dati ključ, ako je spisak liga
// već preuzet. Traži se ISKLJUČIVO u kešu - upis forme ne sme da zavisi od
// toga da li mreža radi.
function naziv_lige_kod_apija(string $kljuc): ?string
{
    foreach (odds_api_sportovi_iz_kesa() ?? [] as $sport) {
        if (($sport['key'] ?? '') === $kljuc) {
            $naziv = trim((string) ($sport['title'] ?? ''));
            $opis  = trim((string) ($sport['description'] ?? ''));

            if ($opis !== '' && $opis !== $naziv) {
                $naziv = $naziv === '' ? $opis : $naziv . ' (' . $opis . ')';
            }

            return $naziv !== '' ? mb_substr($naziv, 0, 120) : null;
        }
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_ispravan($_POST['csrf_token'] ?? null)) {
        postavi_poruku('danger', 'Nevažeća sesija. Osvežite stranicu i pokušajte ponovo.');
        preusmeri(KORENSKI_URL . '/operater/api_lige.php');
    }

    $akcija = $_POST['akcija'] ?? '';

    if ($akcija === 'sacuvaj') {
        $id = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : null;
        $api_kljuc = strtolower(trim($_POST['api_kljuc'] ?? ''));
        $liga_id = ctype_digit((string) ($_POST['liga_id'] ?? '')) ? (int) $_POST['liga_id'] : null;
        $aktivna = isset($_POST['aktivna']) ? 1 : 0;

        if (!ispravan_api_kljuc($api_kljuc)) {
            postavi_poruku('danger', 'Ključ lige sme sadržati samo mala slova, cifre i donju crtu (npr. soccer_epl), dužine 3-60 znakova.');
        } elseif ($liga_id === null) {
            postavi_poruku('danger', 'Izaberite ligu iz baze na koju se ključ mapira.');
        } else {
            try {
                $naziv_kod_api = naziv_lige_kod_apija($api_kljuc);

                if ($id === null) {
                    $upit = $pdo->prepare('INSERT INTO api_liga (api_kljuc, liga_id, naziv_kod_api, aktivna) VALUES (?, ?, ?, ?)');
                    $upit->execute([$api_kljuc, $liga_id, $naziv_kod_api, $aktivna]);
                    postavi_poruku('success', 'Mapiranje je dodato.');
                } else {
                    $upit = $pdo->prepare('UPDATE api_liga SET api_kljuc = ?, liga_id = ?, naziv_kod_api = ?, aktivna = ? WHERE id = ?');
                    $upit->execute([$api_kljuc, $liga_id, $naziv_kod_api, $aktivna, $id]);
                    postavi_poruku('success', 'Mapiranje je izmenjeno.');
                }
            } catch (PDOException $e) {
                if ((int) $e->getCode() === 23000) {
                    postavi_poruku('danger', 'Ključ „' . $api_kljuc . '“ je već mapiran na neku ligu. Jedan ključ vodi u tačno jednu ligu.');
                } else {
                    upisi_gresku('Greška pri čuvanju mapiranja lige: ' . $e->getMessage());
                    postavi_poruku('danger', 'Trenutno nije moguće sačuvati mapiranje.');
                }
            }
        }
    } elseif ($akcija === 'obrisi' && ctype_digit((string) ($_POST['id'] ?? ''))) {
        try {
            $upit = $pdo->prepare('DELETE FROM api_liga WHERE id = ?');
            $upit->execute([(int) $_POST['id']]);

            // Brisanje mapiranja ne dira nijedan već uvezen događaj, ali je i
            // dalje destruktivna akcija, pa ostavlja trag van baze
            upisi_trag($_SESSION['korisnicko_ime'] ?? '?', 'obrisao mapiranje api_liga id=' . (int) $_POST['id']);
            postavi_poruku('success', 'Mapiranje je obrisano. Već uvezeni događaji ostaju netaknuti.');
        } catch (PDOException $e) {
            upisi_gresku('Greška pri brisanju mapiranja lige: ' . $e->getMessage());
            postavi_poruku('danger', 'Trenutno nije moguće obrisati mapiranje.');
        }
    } elseif ($akcija === 'preuzmi_spisak') {
        // Jedini poziv API-ja sa ove stranice, i to na izričit klik. Endpoint
        // /sports se kod ovog servisa ne naplaćuje, ali svejedno traži mrežu.
        $spisak = odds_api_sportovi(true);

        if ($spisak['uspeh']) {
            postavi_poruku('success', 'Spisak liga je preuzet: ' . count($spisak['podaci']) . ' liga.');
        } else {
            postavi_poruku('warning', $spisak['poruka'] . ' Ključ lige možete uneti i ručno.');
        }
    }

    preusmeri(KORENSKI_URL . '/operater/api_lige.php');
}

$mapiranje_za_izmenu = null;
$izmeni_id = isset($_GET['izmeni']) && ctype_digit($_GET['izmeni']) ? (int) $_GET['izmeni'] : null;

try {
    $lige = $pdo->query(
        'SELECT l.id, l.naziv, l.drzava, s.naziv AS sport_naziv
         FROM liga l JOIN sport s ON s.id = l.sport_id
         ORDER BY s.naziv, l.naziv'
    )->fetchAll();

    if ($izmeni_id !== null) {
        $upit = $pdo->prepare('SELECT * FROM api_liga WHERE id = ?');
        $upit->execute([$izmeni_id]);
        $mapiranje_za_izmenu = $upit->fetch() ?: null;
    }

    $mapiranja = $pdo->query(
        'SELECT al.*, l.naziv AS liga_naziv, s.naziv AS sport_naziv
         FROM api_liga al
         JOIN liga l ON l.id = al.liga_id
         JOIN sport s ON s.id = l.sport_id
         ORDER BY s.naziv, l.naziv'
    )->fetchAll();
} catch (PDOException $e) {
    upisi_gresku('Greška pri učitavanju mapiranja liga: ' . $e->getMessage());
    $lige = [];
    $mapiranja = [];
}

// Spisak liga sa servisa služi kao pomoć pri unosu (datalist uz polje).
// Čita se samo iz keša - stranica se ne sme oslanjati na mrežu.
$sportovi_sa_apija = odds_api_sportovi_iz_kesa();
$kes_sportova = procitaj_kes('sportovi');

$csrf_token = generisi_csrf_token();
$naslov_stranice = 'Mapiranje liga';
require_once __DIR__ . '/../includes/zaglavlje.php';
?>

<h1 class="mb-1">Mapiranje liga sa API-ja</h1>
<p class="text-muted mb-3">
    Servis lige označava ključevima tipa <code>soccer_epl</code>. Ovde se svaki takav ključ
    povezuje sa ligom iz naše baze, da bi uvoz znao gde da upiše preuzete utakmice.
</p>

<a href="<?= KORENSKI_URL ?>/operater/uvoz.php" class="btn btn-sm btn-outline-secondary mb-4">&laquo; Nazad na uvoz</a>

<?php prikazi_poruke(); ?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="mb-3"><?= $mapiranje_za_izmenu ? 'Izmena mapiranja' : 'Novo mapiranje' ?></h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                    <input type="hidden" name="akcija" value="sacuvaj">
                    <?php if ($mapiranje_za_izmenu): ?>
                        <input type="hidden" name="id" value="<?= (int) $mapiranje_za_izmenu['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-2">
                        <label for="api_kljuc" class="form-label">Ključ lige kod servisa</label>
                        <input type="text" class="form-control" id="api_kljuc" name="api_kljuc" required
                               maxlength="60" pattern="[a-z0-9_]{3,60}" placeholder="soccer_epl"
                               list="spisak-api-liga"
                               value="<?= bezbedno($mapiranje_za_izmenu['api_kljuc'] ?? '') ?>">
                        <?php // Datalist je samo pomoć pri kucanju: ako spisak nije preuzet, polje ostaje običan unos ?>
                        <datalist id="spisak-api-liga">
                            <?php foreach ($sportovi_sa_apija ?? [] as $sport): ?>
                                <option value="<?= bezbedno($sport['key'] ?? '') ?>">
                                    <?= bezbedno(trim(($sport['group'] ?? '') . ' — ' . ($sport['title'] ?? ''))) ?>
                                </option>
                            <?php endforeach; ?>
                        </datalist>
                        <div class="form-text">Mala slova, cifre i donja crta.</div>
                    </div>

                    <div class="mb-2">
                        <label for="liga_id" class="form-label">Liga u našoj bazi</label>
                        <select class="form-select" id="liga_id" name="liga_id" required>
                            <option value="">Izaberite ligu</option>
                            <?php foreach ($lige as $liga): ?>
                                <option value="<?= (int) $liga['id'] ?>"
                                    <?= $mapiranje_za_izmenu && (int) $mapiranje_za_izmenu['liga_id'] === (int) $liga['id'] ? 'selected' : '' ?>>
                                    <?= bezbedno($liga['sport_naziv']) ?> — <?= bezbedno($liga['naziv']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="aktivna" name="aktivna"
                            <?= ($mapiranje_za_izmenu === null || $mapiranje_za_izmenu['aktivna']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="aktivna">Aktivno (nudi se na stranici za uvoz)</label>
                    </div>

                    <button type="submit" class="btn btn-primary w-100"><?= $mapiranje_za_izmenu ? 'Sačuvaj izmene' : 'Dodaj mapiranje' ?></button>
                    <?php if ($mapiranje_za_izmenu): ?>
                        <a href="<?= KORENSKI_URL ?>/operater/api_lige.php" class="btn btn-outline-secondary w-100 mt-2">Otkaži izmenu</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="card shadow-sm mt-3">
            <div class="card-body">
                <h2 class="mb-2">Spisak liga sa servisa</h2>
                <?php if ($sportovi_sa_apija === null): ?>
                    <p class="text-muted small">
                        Spisak još nije preuzet. Bez njega se ključ unosi ručno — uvoz radi jednako.
                    </p>
                <?php else: ?>
                    <p class="text-muted small">
                        Preuzeto <?= count($sportovi_sa_apija) ?> liga,
                        <?= bezbedno(formatiraj_datum_vreme(date('Y-m-d H:i:s', (int) $kes_sportova['vreme']))) ?>.
                        Spisak se nudi u polju iznad dok kucate.
                    </p>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                    <input type="hidden" name="akcija" value="preuzmi_spisak">
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-cloud-arrow-down" aria-hidden="true"></i>
                        <?= $sportovi_sa_apija === null ? 'Preuzmi spisak liga' : 'Osveži spisak liga' ?>
                    </button>
                </form>
                <div class="form-text mt-2">Ovaj poziv se ne naplaćuje iz mesečne kvote.</div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th>Ključ kod servisa</th>
                        <th>Liga u bazi</th>
                        <th>Naziv kod servisa</th>
                        <th>Stanje</th>
                        <th class="text-end">Akcije</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mapiranja as $mapiranje): ?>
                        <tr>
                            <td><code><?= bezbedno($mapiranje['api_kljuc']) ?></code></td>
                            <td><?= bezbedno($mapiranje['sport_naziv']) ?> — <?= bezbedno($mapiranje['liga_naziv']) ?></td>
                            <td class="small text-muted"><?= bezbedno($mapiranje['naziv_kod_api'] ?? '—') ?></td>
                            <td>
                                <span class="status status-<?= $mapiranje['aktivna'] ? 'aktivan' : 'blokiran' ?>">
                                    <?= $mapiranje['aktivna'] ? 'aktivno' : 'isključeno' ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="<?= KORENSKI_URL ?>/operater/uvoz.php?mapiranje_id=<?= (int) $mapiranje['id'] ?>" class="btn btn-sm btn-outline-secondary">Uvoz</a>
                                <a href="<?= KORENSKI_URL ?>/operater/api_lige.php?izmeni=<?= (int) $mapiranje['id'] ?>" class="btn btn-sm btn-outline-primary">Izmeni</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Obrisati ovo mapiranje? Već uvezeni događaji ostaju.');">
                                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                                    <input type="hidden" name="akcija" value="obrisi">
                                    <input type="hidden" name="id" value="<?= (int) $mapiranje['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Obriši</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($mapiranja)): ?>
                        <tr>
                            <td colspan="5">
                                <div class="prazno">
                                    <i class="bi bi-diagram-3" aria-hidden="true"></i>
                                    <div class="naslov-praznog">Nema nijednog mapiranja</div>
                                    <p class="mb-0">Dok liga nije mapirana, uvoz je za nju nedostupan — ručni unos radi kao i do sada.</p>
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

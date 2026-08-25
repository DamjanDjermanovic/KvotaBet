<?php
// Čitanje tekstualnih log fajlova (logovi/pristup.log i logovi/greske.log)
// preko procitaj_log() iz includes/log.php, i održavanje tabele pristup_log
// u bazi: pregled, brisanje pojedinačnog zapisa i čišćenje starijih od N dana.
//
// Tabela pristup_log jedina je tabela u bazi čije brisanje ne dira ništa
// drugo - zapisi su čista evidencija, bez stranih ključeva ka njima. Zato je
// ovde brisanje bezbedno, ali se ipak beleži u greske.log kao trag, jer se
// time menja osnov za statistiku pristupa.

require_once __DIR__ . '/../includes/autentifikacija.php';
require_once __DIR__ . '/../includes/funkcije.php';

zahtevaj_ulogu('admin');

$pdo = konektuj_bazu();
$dozvoljeni_dani = [7, 30, 90, 365];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_ispravan($_POST['csrf_token'] ?? null)) {
        postavi_poruku('danger', 'Nevažeća sesija. Osvežite stranicu i pokušajte ponovo.');
        preusmeri(KORENSKI_URL . '/admin/logovi.php');
    }

    $akcija = $_POST['akcija'] ?? '';

    try {
        if ($akcija === 'izmeni_zapis') {
            // Evidencija pristupa je po prirodi "samo dopisivanje", pa je jedina
            // smislena izmena ispravka ili anonimizacija zapisa (npr. uklanjanje
            // IP adrese na zahtev korisnika). Zato se menjaju samo ip_adresa i
            // stranica, a svaka takva izmena ostavlja trag u logu.
            $id = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : null;
            $ip = trim($_POST['ip_adresa'] ?? '');
            $stranica = trim($_POST['stranica'] ?? '');

            if ($id === null) {
                throw new RuntimeException('Neispravan zapis.');
            }
            if ($ip === '' || mb_strlen($ip) > 45) {
                throw new RuntimeException('IP adresa mora imati 1-45 znakova.');
            }
            if ($stranica === '' || mb_strlen($stranica) > 255) {
                throw new RuntimeException('Stranica mora imati 1-255 znakova.');
            }

            $podaci = $pdo->prepare('SELECT ip_adresa, stranica FROM pristup_log WHERE id = ?');
            $podaci->execute([$id]);
            $stari = $podaci->fetch();

            if (!$stari) {
                throw new RuntimeException('Zapis nije pronađen.');
            }

            $upit = $pdo->prepare('UPDATE pristup_log SET ip_adresa = ?, stranica = ? WHERE id = ?');
            $upit->execute([$ip, $stranica, $id]);

            upisi_trag(
                $_SESSION['korisnicko_ime'],
                'Izmenjen zapis pristupa id=' . $id . ': ip "' . $stari['ip_adresa'] . '" -> "' . $ip
                . '", stranica "' . $stari['stranica'] . '" -> "' . $stranica . '"'
            );
            postavi_poruku('success', 'Zapis pristupa je izmenjen.');
        } elseif ($akcija === 'obrisi_zapis') {
            $id = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : null;

            if ($id === null) {
                throw new RuntimeException('Neispravan zapis.');
            }

            $upit = $pdo->prepare('DELETE FROM pristup_log WHERE id = ?');
            $upit->execute([$id]);

            upisi_trag($_SESSION['korisnicko_ime'], 'Obrisan zapis pristupa id=' . $id);
            postavi_poruku('success', 'Zapis je obrisan.');
        } elseif ($akcija === 'obrisi_starije') {
            $dana = ctype_digit((string) ($_POST['dana'] ?? '')) ? (int) $_POST['dana'] : null;

            if ($dana === null || !in_array($dana, $dozvoljeni_dani, true)) {
                throw new RuntimeException('Izaberite ponuđeni broj dana.');
            }

            // Broj dana se ne ubacuje u SQL kao tekst nego ide kao parametar
            $upit = $pdo->prepare('DELETE FROM pristup_log WHERE datum_vreme < NOW() - INTERVAL ? DAY');
            $upit->execute([$dana]);
            $obrisano = $upit->rowCount();

            upisi_trag(
                $_SESSION['korisnicko_ime'],
                'Obrisano ' . $obrisano . ' zapisa pristupa starijih od ' . $dana . ' dana'
            );
            postavi_poruku('success', 'Obrisano zapisa: ' . $obrisano . ' (stariji od ' . $dana . ' dana).');
        }
    } catch (PDOException $e) {
        upisi_gresku('Greška pri brisanju zapisa pristupa: ' . $e->getMessage());
        postavi_poruku('danger', 'Trenutno nije moguće obrisati zapise.');
    } catch (RuntimeException $e) {
        postavi_poruku('danger', $e->getMessage());
    }

    preusmeri(KORENSKI_URL . '/admin/logovi.php');
}

// --- Tekstualni logovi ---
$dozvoljeni_brojevi = [50, 100, 200, 500];
$broj_linija = isset($_GET['broj']) && in_array((int) $_GET['broj'], $dozvoljeni_brojevi, true) ? (int) $_GET['broj'] : 100;
$koji_log = ($_GET['log'] ?? 'pristup') === 'greske' ? 'greske' : 'pristup';

$putanja = $koji_log === 'greske' ? PUTANJA_LOG_GRESKE : PUTANJA_LOG_PRISTUP;
$linije = array_reverse(procitaj_log($putanja, $broj_linija));

// --- Tabela pristup_log ---
try {
    $ukupno_zapisa = (int) $pdo->query('SELECT COUNT(*) FROM pristup_log')->fetchColumn();

    $zapisi = $pdo->query(
        "SELECT pl.*, COALESCE(k.korisnicko_ime, 'gost') AS korisnik
         FROM pristup_log pl
         LEFT JOIN korisnik k ON k.id = pl.korisnik_id
         ORDER BY pl.datum_vreme DESC, pl.id DESC
         LIMIT 100"
    )->fetchAll();
} catch (PDOException $e) {
    upisi_gresku('Greška pri učitavanju zapisa pristupa: ' . $e->getMessage());
    $ukupno_zapisa = 0;
    $zapisi = [];
}

$csrf_token = generisi_csrf_token();
$naslov_stranice = 'Logovi';
require_once __DIR__ . '/../includes/zaglavlje.php';
?>

<h1 class="h3 mb-4">Logovi</h1>

<?php prikazi_poruke(); ?>

<h2 class="h5 mb-3">Tekstualni log fajlovi</h2>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <ul class="nav nav-pills">
        <li class="nav-item">
            <a class="nav-link <?= $koji_log === 'pristup' ? 'active' : '' ?>"
               href="<?= KORENSKI_URL ?>/admin/logovi.php?log=pristup&broj=<?= $broj_linija ?>">logovi/pristup.log</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $koji_log === 'greske' ? 'active' : '' ?>"
               href="<?= KORENSKI_URL ?>/admin/logovi.php?log=greske&broj=<?= $broj_linija ?>">logovi/greske.log</a>
        </li>
    </ul>

    <div class="btn-group btn-group-sm" role="group">
        <?php foreach ($dozvoljeni_brojevi as $broj): ?>
            <a href="<?= KORENSKI_URL ?>/admin/logovi.php?log=<?= $koji_log ?>&broj=<?= $broj ?>"
               class="btn btn-outline-secondary <?= $broj_linija === $broj ? 'active' : '' ?>">poslednjih <?= $broj ?></a>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($koji_log === 'greske'): ?>
    <p class="text-muted small">
        Redovi označeni sa <code>TRAG</code> nisu greške nego zabeležene destruktivne akcije
        (brisanje zapisa, ručna izmena stanja računa).
    </p>
<?php endif; ?>

<?php if (empty($linije)): ?>
    <div class="alert alert-info">Log fajl je prazan ili ne postoji.</div>
<?php else: ?>
    <div class="card mb-5">
        <div class="card-body p-0">
            <pre class="p-3 mb-0 small" style="max-height:50vh; overflow-y:auto; white-space:pre-wrap; word-break:break-word;"><?php
                foreach ($linije as $red) {
                    echo bezbedno($red) . "\n";
                }
            ?></pre>
        </div>
    </div>
    <p class="text-muted small mt-2">Prikazano <?= count($linije) ?> od maksimalno <?= $broj_linija ?> traženih redova, najnoviji prvi.</p>
<?php endif; ?>

<hr class="my-5">

<h2 class="h5 mb-3">Evidencija pristupa u bazi (tabela pristup_log)</h2>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <p class="text-muted small mb-0">
        Ukupno zapisa: <strong><?= number_format($ukupno_zapisa, 0, ',', '.') ?></strong>.
        Prikazano poslednjih <?= count($zapisi) ?>.
    </p>

    <form method="post" class="d-flex gap-2 align-items-center"
          onsubmit="return confirm('Trajno obrisati zapise pristupa starije od izabranog broja dana? Statistika pristupa će se time promeniti.');">
        <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
        <input type="hidden" name="akcija" value="obrisi_starije">
        <label for="dana" class="form-label mb-0 small">Obriši starije od</label>
        <select class="form-select form-select-sm" id="dana" name="dana" style="width:auto">
            <?php foreach ($dozvoljeni_dani as $dana): ?>
                <option value="<?= $dana ?>"><?= $dana ?> dana</option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-outline-danger">Očisti</button>
    </form>
</div>

<?php if (empty($zapisi)): ?>
    <div class="alert alert-info">Nema zapisa u tabeli pristup_log.</div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-striped table-sm align-middle">
            <thead>
                <tr>
                    <th>Vreme</th>
                    <th>Korisnik</th>
                    <th>IP</th>
                    <th>Metod</th>
                    <th>Stranica</th>
                    <th class="text-end">Akcija</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($zapisi as $zapis): ?>
                    <tr>
                        <td class="small text-nowrap"><?= bezbedno(formatiraj_datum_vreme($zapis['datum_vreme'])) ?></td>
                        <td class="small"><?= bezbedno($zapis['korisnik']) ?></td>
                        <td colspan="3">
                            <?php // Izmena služi za ispravku ili anonimizaciju zapisa ?>
                            <form method="post" class="d-flex gap-1 align-items-center">
                                <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                                <input type="hidden" name="akcija" value="izmeni_zapis">
                                <input type="hidden" name="id" value="<?= (int) $zapis['id'] ?>">
                                <input type="text" class="form-control form-control-sm py-0" name="ip_adresa" maxlength="45"
                                       style="width:9rem" value="<?= bezbedno($zapis['ip_adresa']) ?>" required>
                                <span class="small text-muted"><?= bezbedno($zapis['metod']) ?></span>
                                <input type="text" class="form-control form-control-sm py-0" name="stranica" maxlength="255"
                                       value="<?= bezbedno($zapis['stranica']) ?>" required>
                                <button type="submit" class="btn btn-sm btn-outline-primary py-0" title="Sačuvaj izmenu">&check;</button>
                            </form>
                        </td>
                        <td class="text-end">
                            <form method="post" onsubmit="return confirm('Obrisati ovaj zapis pristupa?');">
                                <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                                <input type="hidden" name="akcija" value="obrisi_zapis">
                                <input type="hidden" name="id" value="<?= (int) $zapis['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger py-0">&times;</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/podnozje.php'; ?>

<?php
// Čitanje tekstualnih log fajlova (logovi/pristup.log i logovi/greske.log)
// preko procitaj_log() iz includes/log.php. Admin bira koliko poslednjih
// redova želi da vidi; noviji redovi se prikazuju na vrhu.

require_once __DIR__ . '/../includes/autentifikacija.php';

zahtevaj_ulogu('admin');

$dozvoljeni_brojevi = [50, 100, 200, 500];
$broj_linija = isset($_GET['broj']) && in_array((int) $_GET['broj'], $dozvoljeni_brojevi, true) ? (int) $_GET['broj'] : 100;
$koji_log = ($_GET['log'] ?? 'pristup') === 'greske' ? 'greske' : 'pristup';

$putanja = $koji_log === 'greske' ? PUTANJA_LOG_GRESKE : PUTANJA_LOG_PRISTUP;
$linije = array_reverse(procitaj_log($putanja, $broj_linija));

$naslov_stranice = 'Logovi';
require_once __DIR__ . '/../includes/zaglavlje.php';
?>

<h1 class="h3 mb-4">Log fajlovi</h1>

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

<?php if (empty($linije)): ?>
    <div class="alert alert-info">Log fajl je prazan ili ne postoji.</div>
<?php else: ?>
    <div class="card">
        <div class="card-body p-0">
            <pre class="p-3 mb-0 small" style="max-height:70vh; overflow-y:auto; white-space:pre-wrap; word-break:break-word;"><?php
                foreach ($linije as $red) {
                    echo bezbedno($red) . "\n";
                }
            ?></pre>
        </div>
    </div>
    <p class="text-muted small mt-2">Prikazano <?= count($linije) ?> od maksimalno <?= $broj_linija ?> traženih redova, najnoviji prvi.</p>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/podnozje.php'; ?>

<?php
// Statistika pristupa sistemu za administratora - admin vidi sve, za razliku
// od operatera (statistika ponude/tiketa) i igrača (samo sopstvena istorija
// prijava). Podaci dolaze iz tabele pristup_log, koja se puni iz zaglavlje.php.

require_once __DIR__ . '/../includes/autentifikacija.php';

zahtevaj_ulogu('admin');

$pdo = konektuj_bazu();

try {
    $ukupno = (int) $pdo->query('SELECT COUNT(*) FROM pristup_log')->fetchColumn();

    $danas = (int) $pdo->query('SELECT COUNT(*) FROM pristup_log WHERE DATE(datum_vreme) = CURDATE()')->fetchColumn();

    $poslednjih_7_dana = (int) $pdo->query(
        'SELECT COUNT(*) FROM pristup_log WHERE datum_vreme >= NOW() - INTERVAL 7 DAY'
    )->fetchColumn();

    $jedinstvene_ip = (int) $pdo->query(
        'SELECT COUNT(DISTINCT ip_adresa) FROM pristup_log WHERE datum_vreme >= NOW() - INTERVAL 7 DAY'
    )->fetchColumn();

    $po_danu = $pdo->query(
        "SELECT DATE(datum_vreme) AS dan, COUNT(*) AS broj
         FROM pristup_log
         WHERE datum_vreme >= NOW() - INTERVAL 7 DAY
         GROUP BY DATE(datum_vreme)
         ORDER BY dan"
    )->fetchAll();
    $najvise_u_danu = max(array_column($po_danu, 'broj') ?: [1]);

    $po_ulozi = $pdo->query(
        "SELECT COALESCE(u.naziv, 'gost') AS uloga, COUNT(*) AS broj
         FROM pristup_log pl
         LEFT JOIN korisnik k ON k.id = pl.korisnik_id
         LEFT JOIN uloga u ON u.id = k.uloga_id
         GROUP BY uloga
         ORDER BY broj DESC"
    )->fetchAll();

    $najposecenije_stranice = $pdo->query(
        'SELECT stranica, COUNT(*) AS broj
         FROM pristup_log
         GROUP BY stranica
         ORDER BY broj DESC
         LIMIT 10'
    )->fetchAll();

    $najaktivniji_korisnici = $pdo->query(
        "SELECT k.ime, k.prezime, k.korisnicko_ime, u.naziv AS uloga, COUNT(*) AS broj
         FROM pristup_log pl
         JOIN korisnik k ON k.id = pl.korisnik_id
         JOIN uloga u ON u.id = k.uloga_id
         GROUP BY k.id
         ORDER BY broj DESC
         LIMIT 10"
    )->fetchAll();
} catch (PDOException $e) {
    upisi_gresku('Greška pri učitavanju statistike pristupa: ' . $e->getMessage());
    $ukupno = $danas = $poslednjih_7_dana = $jedinstvene_ip = 0;
    $po_danu = $po_ulozi = $najposecenije_stranice = $najaktivniji_korisnici = [];
    $najvise_u_danu = 1;
}

$naslov_stranice = 'Statistika pristupa';
require_once __DIR__ . '/../includes/zaglavlje.php';
?>

<h1 class="mb-4">Statistika pristupa</h1>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-karta">
            <div class="broj"><?= number_format($ukupno, 0, ',', '.') ?></div>
            <div class="opis">Ukupno pristupa</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-karta">
            <div class="broj"><?= number_format($danas, 0, ',', '.') ?></div>
            <div class="opis">Danas</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-karta">
            <div class="broj"><?= number_format($poslednjih_7_dana, 0, ',', '.') ?></div>
            <div class="opis">Poslednjih 7 dana</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-karta">
            <div class="broj"><?= number_format($jedinstvene_ip, 0, ',', '.') ?></div>
            <div class="opis">Jedinstvenih IP (7 dana)</div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="mb-3">Pristupi po danu (poslednjih 7 dana)</h2>
                <?php if (empty($po_danu)): ?>
                    <div class="prazno">
                        <i class="bi bi-bar-chart" aria-hidden="true"></i>
                        <div class="naslov-praznog">Nema zabeleženih pristupa</div>
                        <p class="mb-0">Statistika se puni sama, pri svakom otvaranju bilo koje stranice.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($po_danu as $red): ?>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <div class="text-muted small broj" style="width:6rem"><?= bezbedno(formatiraj_datum($red['dan'])) ?></div>
                            <div class="flex-grow-1 bg-light rounded" style="height:1.25rem">
                                <div class="bg-primary rounded" style="height:1.25rem; width:<?= max(4, round($red['broj'] / $najvise_u_danu * 100)) ?>%"></div>
                            </div>
                            <div class="small text-end broj" style="width:2.5rem"><?= (int) $red['broj'] ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="mb-3">Pristupi po ulozi</h2>
                <table class="table table-sm mb-0">
                    <?php foreach ($po_ulozi as $red): ?>
                        <tr>
                            <td class="text-capitalize"><?= bezbedno($red['uloga']) ?></td>
                            <td class="broj text-end"><?= number_format($red['broj'], 0, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($po_ulozi)): ?>
                        <tr><td colspan="2"><div class="prazno"><i class="bi bi-inbox" aria-hidden="true"></i>Nema podataka za prikaz.</div></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="mb-3">Najposećenije stranice</h2>
                <table class="table table-sm mb-0">
                    <?php foreach ($najposecenije_stranice as $red): ?>
                        <tr>
                            <td class="text-truncate" style="max-width:16rem"><code class="small"><?= bezbedno($red['stranica']) ?></code></td>
                            <td class="broj text-end"><?= number_format($red['broj'], 0, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($najposecenije_stranice)): ?>
                        <tr><td colspan="2"><div class="prazno"><i class="bi bi-inbox" aria-hidden="true"></i>Nema podataka za prikaz.</div></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="mb-3">Najaktivniji prijavljeni korisnici</h2>
                <table class="table table-sm mb-0">
                    <?php foreach ($najaktivniji_korisnici as $red): ?>
                        <tr>
                            <td>
                                <?= bezbedno($red['ime']) ?> <?= bezbedno($red['prezime']) ?>
                                <span class="text-muted">(<?= bezbedno($red['korisnicko_ime']) ?>, <?= bezbedno($red['uloga']) ?>)</span>
                            </td>
                            <td class="broj text-end"><?= number_format($red['broj'], 0, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($najaktivniji_korisnici)): ?>
                        <tr><td colspan="2"><div class="prazno"><i class="bi bi-inbox" aria-hidden="true"></i>Nema podataka za prikaz.</div></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/podnozje.php'; ?>

<?php
// Statistika pristupa sistemu za administratora - admin vidi sve, za razliku
// od operatera (statistika ponude/tiketa) i igrača (samo sopstvena istorija
// prijava). Podaci dolaze iz tabele pristup_log, koja se puni iz zaglavlje.php.

require_once __DIR__ . '/../includes/autentifikacija.php';

zahtevaj_ulogu('admin');

$pdo = konektuj_bazu();

// Koliko dana pokriva linijski grafikon. Prvi dan je 13 dana unazad, jer se
// i današnji dan računa u tih 14.
$dana_u_grafikonu = 14;
$prvi_dan_grafikona = (new DateTimeImmutable('today'))->modify('-' . ($dana_u_grafikonu - 1) . ' days');

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

    // Poseban upit za linijski grafikon: on pokriva 14 dana, dok lista ispod
    // njega ostaje na 7, pa se $po_danu ne dira. Početni dan ide kao parametar
    // pripremljenog upita, ne kao ulepljen tekst u SQL.
    $upit_grafikona = $pdo->prepare(
        "SELECT DATE(datum_vreme) AS dan, COUNT(*) AS broj
         FROM pristup_log
         WHERE datum_vreme >= ?
         GROUP BY DATE(datum_vreme)"
    );
    $upit_grafikona->execute([$prvi_dan_grafikona->format('Y-m-d 00:00:00')]);
    $pristupi_po_danu = [];

    foreach ($upit_grafikona->fetchAll() as $red) {
        $pristupi_po_danu[$red['dan']] = (int) $red['broj'];
    }
} catch (PDOException $e) {
    upisi_gresku('Greška pri učitavanju statistike pristupa: ' . $e->getMessage());
    $ukupno = $danas = $poslednjih_7_dana = $jedinstvene_ip = 0;
    $po_danu = $po_ulozi = $najposecenije_stranice = $najaktivniji_korisnici = [];
    $najvise_u_danu = 1;
    $pristupi_po_danu = [];
}

// Upit vraća samo dane u kojima je bilo pristupa. Da bi se pad na nulu na
// grafikonu video kao pad, a ne kao rupa u liniji, prazni dani se ovde
// dopunjuju nulama - linija mora imati tačku za svaki od 14 dana.
$grafikon_dani = [];
$grafikon_vrednosti = [];

for ($i = 0; $i < $dana_u_grafikonu; $i++) {
    $dan = $prvi_dan_grafikona->modify('+' . $i . ' days');

    $grafikon_dani[] = $dan->format('d.m.');
    $grafikon_vrednosti[] = $pristupi_po_danu[$dan->format('Y-m-d')] ?? 0;
}

// Boje grafikona ne stoje ovde kao heksadecimalne vrednosti nego kao nazivi
// promenljivih iz css/style.css - JS ih čita iz stila, pa paleta i dalje ima
// samo jedno mesto istine.
//
// Uloge nisu ravnopravne kategorije nego stepenice ovlašćenja
// (gost -> igrac -> operater -> admin), pa nose jednu boju u četiri tona:
// što više prava, to tamniji ton. Redosled je vezan za ulogu, a ne za broj
// pristupa, da isti nalog ne bi menjao boju kad se podaci promene.
//
// Nazivi su --grafikon-N, a ne tonovi iz palete: --grafikon-N prati temu, pa
// se ceo niz u tamnoj temi pomera u svetliji deo skale. Ranije je ovde stajao
// --mornar-900, koji na tamnoj kartici praktično nestane.
$boje_uloga = [
    'gost'     => '--grafikon-1',
    'igrac'    => '--grafikon-2',
    'operater' => '--grafikon-3',
    'admin'    => '--grafikon-4',
];

// Uloge se mogu dodavati kroz admin/uloge.php, pa grafikon mora podneti i
// ulogu koja nije u paleti. Sve takve ulaze u jednu sivu krišku "ostale
// uloge" - izmišljanje novih boja bi dalo tonove koje čitalac ne razlikuje.
$grafikon_uloge = [];
$ostale_uloge = 0;

foreach ($boje_uloga as $naziv_uloge => $css_promenljiva) {
    foreach ($po_ulozi as $red) {
        if ($red['uloga'] === $naziv_uloge) {
            $grafikon_uloge[] = [
                'uloga' => $naziv_uloge,
                'broj'  => (int) $red['broj'],
                'boja'  => $css_promenljiva,
            ];
        }
    }
}

foreach ($po_ulozi as $red) {
    if (!isset($boje_uloga[$red['uloga']])) {
        $ostale_uloge += (int) $red['broj'];
    }
}

if ($ostale_uloge > 0) {
    $grafikon_uloge[] = ['uloga' => 'ostale uloge', 'broj' => $ostale_uloge, 'boja' => '--prigusen'];
}

$ukupno_po_ulogama = array_sum(array_column($grafikon_uloge, 'broj'));

// js/statistika.js uključuje podnožje posle Chart.js oznake ispod, pa je
// biblioteka do tada već učitana
$dodatne_skripte = ['statistika.js'];

$naslov_stranice = 'Statistika pristupa';
require_once __DIR__ . '/../includes/zaglavlje.php';
?>

<h1 class="mb-4">Statistika pristupa</h1>

<?php
// Grafikoni stoje iznad brojki i tabela: prvo se vidi oblik kretanja, pa tek
// onda tačne vrednosti ispod. Podaci se ne ispisuju u JS kod nego u data-
// atribute platna, pa skripta ostaje u zasebnom fajlu i nema ubačenog koda.
?>
<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="mb-1">Pristupi po danu</h2>
                <p class="text-muted small mb-3">Poslednjih <?= (int) $dana_u_grafikonu ?> dana, zaključno sa današnjim.</p>

                <?php if (array_sum($grafikon_vrednosti) === 0): ?>
                    <div class="prazno">
                        <i class="bi bi-graph-up" aria-hidden="true"></i>
                        <div class="naslov-praznog">Nema zabeleženih pristupa</div>
                        <p class="mb-0">Grafikon se puni sam, pri svakom otvaranju bilo koje stranice.</p>
                    </div>
                <?php else: ?>
                    <div class="grafikon-okvir">
                        <canvas id="grafikon-po-danu"
                                data-dani="<?= bezbedno(json_encode($grafikon_dani, JSON_UNESCAPED_UNICODE)) ?>"
                                data-vrednosti="<?= bezbedno(json_encode($grafikon_vrednosti)) ?>"></canvas>
                    </div>

                    <?php
                    // Platno je slika i čitač ekrana iz njega ne može pročitati ništa, a
                    // ovih 14 brojeva nema ni u jednoj tabeli na stranici (donja lista
                    // pokriva 7 dana). Zato ista serija ide i kao tabela, sakrivena za
                    // oko ali dostupna čitaču ekrana.
                    ?>
                    <table class="visually-hidden">
                        <caption>Broj pristupa po danu, poslednjih <?= (int) $dana_u_grafikonu ?> dana</caption>
                        <thead>
                            <tr><th scope="col">Dan</th><th scope="col">Pristupa</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grafikon_dani as $redni_broj => $dan): ?>
                                <tr>
                                    <td><?= bezbedno($dan) ?></td>
                                    <td><?= (int) $grafikon_vrednosti[$redni_broj] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="mb-1">Raspodela po ulogama</h2>
                <p class="text-muted small mb-3">Udeo svake uloge u ukupnom broju pristupa.</p>

                <?php if (empty($grafikon_uloge)): ?>
                    <div class="prazno">
                        <i class="bi bi-pie-chart" aria-hidden="true"></i>
                        <div class="naslov-praznog">Nema podataka za prikaz</div>
                        <p class="mb-0">Raspodela se računa iz zabeleženih pristupa.</p>
                    </div>
                <?php else: ?>
                    <div class="grafikon-okvir grafikon-krug">
                        <canvas id="grafikon-po-ulogama"
                                data-oznake="<?= bezbedno(json_encode(array_column($grafikon_uloge, 'uloga'), JSON_UNESCAPED_UNICODE)) ?>"
                                data-vrednosti="<?= bezbedno(json_encode(array_column($grafikon_uloge, 'broj'))) ?>"
                                data-boje="<?= bezbedno(json_encode(array_column($grafikon_uloge, 'boja'))) ?>"></canvas>
                    </div>

                    <?php
                    // Sopstvena legenda umesto ugrađene: uz boju stoje i naziv, broj i
                    // udeo, pa se kriška može pročitati i bez razlikovanja tonova - i
                    // ujedno zamenjuje tabelu za ovaj grafikon.
                    ?>
                    <ul class="legenda-grafikona list-unstyled mb-0">
                        <?php foreach ($grafikon_uloge as $stavka): ?>
                            <li>
                                <span class="tacka" style="background: var(<?= bezbedno($stavka['boja']) ?>)" aria-hidden="true"></span>
                                <span class="naziv text-capitalize"><?= bezbedno($stavka['uloga']) ?></span>
                                <span class="broj"><?= number_format($stavka['broj'], 0, ',', '.') ?></span>
                                <span class="udeo broj"><?= $ukupno_po_ulogama > 0 ? round($stavka['broj'] / $ukupno_po_ulogama * 100) : 0 ?>%</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

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
                <div class="table-responsive">
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
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="mb-3">Najposećenije stranice</h2>
                <div class="table-responsive">
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
        </div>

        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="mb-3">Najaktivniji prijavljeni korisnici</h2>
                <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <?php foreach ($najaktivniji_korisnici as $red): ?>
                        <tr>
                            <td>
                                <?php // Na ovom spisku su i operateri i admini, koji ime ne moraju imati upisano ?>
                                <?= bezbedno(ime_za_prikaz($red['ime'], $red['prezime'], $red['korisnicko_ime'])) ?>
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
</div>

<?php
// Chart.js se povezuje sa CDN-a, kao i Bootstrap. Oznaka stoji ovde, a ne u
// zaglavlju, da se biblioteka učitava samo na stranici koja je zaista koristi.
// Mora doći pre podnožja, jer podnožje uključuje js/statistika.js.
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

<?php require_once __DIR__ . '/../includes/podnozje.php'; ?>

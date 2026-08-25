<?php
// Obrada verifikacionih dokumenata igrača: pregled poslatih dokumenata i
// njihovo odobravanje ili odbijanje. Sam fajl se ne prikazuje direktno (folder
// je zaključan preko .htaccess) već preko operater/dokument_preuzmi.php.

require_once __DIR__ . '/../includes/autentifikacija.php';
require_once __DIR__ . '/../includes/funkcije.php';

zahtevaj_ulogu('operater', 'admin');

$pdo = konektuj_bazu();
$obradio_id = (int) $_SESSION['korisnik_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_ispravan($_POST['csrf_token'] ?? null)) {
        postavi_poruku('danger', 'Nevažeća sesija. Osvežite stranicu i pokušajte ponovo.');
        preusmeri(KORENSKI_URL . '/operater/dokumenta.php');
    }

    $akcija = $_POST['akcija'] ?? '';
    $id = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : null;

    if ($id !== null && in_array($akcija, ['odobri', 'odbij'], true)) {
        try {
            $pdo->beginTransaction();

            $upit = $pdo->prepare('SELECT * FROM dokument WHERE id = ? FOR UPDATE');
            $upit->execute([$id]);
            $dokument = $upit->fetch();

            if (!$dokument || $dokument['status'] !== 'na_cekanju') {
                $pdo->rollBack();
                postavi_poruku('danger', 'Ovaj dokument je već obrađen ili ne postoji.');
            } elseif ($akcija === 'odobri') {
                $azurirajDok = $pdo->prepare(
                    "UPDATE dokument SET status = 'odobren', obradio_id = ?, datum_obrade = NOW() WHERE id = ?"
                );
                $azurirajDok->execute([$obradio_id, $id]);

                $azurirajKorisnika = $pdo->prepare('UPDATE korisnik SET verifikovan = 1 WHERE id = ?');
                $azurirajKorisnika->execute([$dokument['korisnik_id']]);

                $pdo->commit();
                postavi_poruku('success', 'Dokument je odobren, nalog je verifikovan.');
            } else {
                $napomena = trim($_POST['napomena'] ?? '');

                if ($napomena === '') {
                    $pdo->rollBack();
                    postavi_poruku('danger', 'Unesite razlog odbijanja.');
                } else {
                    $azurirajDok = $pdo->prepare(
                        "UPDATE dokument SET status = 'odbijen', napomena = ?, obradio_id = ?, datum_obrade = NOW() WHERE id = ?"
                    );
                    $azurirajDok->execute([mb_substr($napomena, 0, 255), $obradio_id, $id]);

                    $pdo->commit();
                    postavi_poruku('success', 'Dokument je odbijen.');
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            upisi_gresku('Greška pri obradi dokumenta: ' . $e->getMessage());
            postavi_poruku('danger', 'Trenutno nije moguće obraditi dokument.');
        }
    }

    preusmeri(KORENSKI_URL . '/operater/dokumenta.php');
}

$prikaz_status = $_GET['status'] ?? 'na_cekanju';
if (!in_array($prikaz_status, ['na_cekanju', 'odobren', 'odbijen', 'svi'], true)) {
    $prikaz_status = 'na_cekanju';
}

try {
    $sql = "SELECT dk.*, k.ime, k.prezime, k.korisnicko_ime, k.email
            FROM dokument dk JOIN korisnik k ON k.id = dk.korisnik_id";
    $parametri = [];

    if ($prikaz_status !== 'svi') {
        $sql .= ' WHERE dk.status = ?';
        $parametri[] = $prikaz_status;
    }

    $sql .= ' ORDER BY dk.datum_slanja DESC';

    $upit = $pdo->prepare($sql);
    $upit->execute($parametri);
    $dokumenti = $upit->fetchAll();
} catch (PDOException $e) {
    upisi_gresku('Greška pri učitavanju dokumenata: ' . $e->getMessage());
    $dokumenti = [];
}

// Napomena: boju statusa dokumenta više ne bira PHP - nosi je klasa
// .status-<vrednost> iz css/style.css, ista kao za tikete i naloge.

$csrf_token = generisi_csrf_token();
$naslov_stranice = 'Verifikacioni dokumenti';
require_once __DIR__ . '/../includes/zaglavlje.php';
?>

<h1 class="mb-4">Verifikacioni dokumenti</h1>

<?php prikazi_poruke(); ?>

<ul class="nav nav-pills mb-4">
    <?php foreach (['na_cekanju' => 'Na čekanju', 'odobren' => 'Odobreni', 'odbijen' => 'Odbijeni', 'svi' => 'Svi'] as $vrednost => $naziv): ?>
        <li class="nav-item">
            <a class="nav-link <?= $prikaz_status === $vrednost ? 'active' : '' ?>"
               href="<?= KORENSKI_URL ?>/operater/dokumenta.php?status=<?= $vrednost ?>"><?= bezbedno($naziv) ?></a>
        </li>
    <?php endforeach; ?>
</ul>

<?php if (empty($dokumenti)): ?>
    <div class="prazno">
        <i class="bi bi-file-earmark-check" aria-hidden="true"></i>
        <div class="naslov-praznog">Nema dokumenata za prikaz</div>
        <p class="mb-3">Za izabrani status nijedan dokument nije poslat.</p>
        <a href="<?= KORENSKI_URL ?>/operater/dokumenta.php?status=svi" class="btn btn-primary btn-sm">Prikaži sve dokumente</a>
    </div>
<?php else: ?>
    <?php foreach ($dokumenti as $dok): ?>
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                    <div>
                        <div class="fw-semibold">
                            <?= bezbedno($dok['ime']) ?> <?= bezbedno($dok['prezime']) ?>
                            <span class="text-muted">(<?= bezbedno($dok['korisnicko_ime']) ?>)</span>
                        </div>
                        <div class="text-muted small"><?= bezbedno($dok['email']) ?></div>
                        <div class="small mt-1">
                            <a href="<?= KORENSKI_URL ?>/operater/dokument_preuzmi.php?id=<?= (int) $dok['id'] ?>" target="_blank" rel="noopener">
                                <?= bezbedno($dok['originalni_naziv']) ?>
                            </a>
                            <span class="text-muted">(<?= number_format($dok['velicina_bajtova'] / 1024, 0) ?> KB, poslato <?= bezbedno(formatiraj_datum_vreme($dok['datum_slanja'])) ?>)</span>
                        </div>
                        <?php if ($dok['napomena']): ?>
                            <div class="small text-danger mt-1">Napomena: <?= bezbedno($dok['napomena']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="text-end">
                        <span class="status status-<?= bezbedno($dok['status']) ?> mb-2"><?= bezbedno($dok['status']) ?></span>

                        <?php if ($dok['status'] === 'na_cekanju'): ?>
                            <div class="d-flex gap-2 justify-content-end">
                                <form method="post" onsubmit="return confirm('Odobriti ovaj dokument i verifikovati nalog?');">
                                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                                    <input type="hidden" name="akcija" value="odobri">
                                    <input type="hidden" name="id" value="<?= (int) $dok['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-success">Odobri</button>
                                </form>
                                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#odbij-<?= (int) $dok['id'] ?>">
                                    Odbij
                                </button>
                            </div>

                            <div class="collapse mt-2" id="odbij-<?= (int) $dok['id'] ?>">
                                <form method="post" class="d-flex gap-2">
                                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                                    <input type="hidden" name="akcija" value="odbij">
                                    <input type="hidden" name="id" value="<?= (int) $dok['id'] ?>">
                                    <input type="text" class="form-control form-control-sm" name="napomena" placeholder="Razlog odbijanja" required maxlength="255">
                                    <button type="submit" class="btn btn-sm btn-danger text-nowrap">Potvrdi odbijanje</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/podnozje.php'; ?>

<?php
// Administratorski pregled verifikacionih dokumenata, sa brisanjem koje uklanja
// i zapis iz baze i sam fajl sa diska.
//
// Odobravanje i odbijanje se i dalje rade u operater/dokumenta.php - ovde je
// samo ono što operater ne sme: trajno brisanje.
//
// Redosled je namerno "prvo baza, pa fajl": ako upis u bazu ne uspe, fajl
// ostaje i zapis o njemu je i dalje tačan. Obrnuto bi ostavilo zapis koji
// pokazuje na fajl koga više nema.

require_once __DIR__ . '/../includes/autentifikacija.php';
require_once __DIR__ . '/../includes/funkcije.php';

zahtevaj_ulogu('admin');

$pdo = konektuj_bazu();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_ispravan($_POST['csrf_token'] ?? null)) {
        postavi_poruku('danger', 'Nevažeća sesija. Osvežite stranicu i pokušajte ponovo.');
        preusmeri(KORENSKI_URL . '/admin/dokumenta.php');
    }

    $id = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : null;

    if (($_POST['akcija'] ?? '') === 'obrisi' && $id !== null) {
        try {
            $podaci = $pdo->prepare(
                'SELECT dk.naziv_na_serveru, dk.originalni_naziv, dk.status, k.korisnicko_ime
                 FROM dokument dk JOIN korisnik k ON k.id = dk.korisnik_id
                 WHERE dk.id = ?'
            );
            $podaci->execute([$id]);
            $dokument = $podaci->fetch();

            if (!$dokument) {
                postavi_poruku('danger', 'Dokument nije pronađen.');
            } else {
                $upit = $pdo->prepare('DELETE FROM dokument WHERE id = ?');
                $upit->execute([$id]);

                // basename sprečava izlazak iz foldera i u slučaju da je u bazi
                // nekim propustom završila putanja umesto samog imena fajla
                $putanja = PUTANJA_UPLOAD_DOKUMENATA . basename($dokument['naziv_na_serveru']);
                $fajl_obrisan = false;

                if (is_file($putanja)) {
                    $fajl_obrisan = @unlink($putanja);

                    if (!$fajl_obrisan) {
                        upisi_gresku('Zapis o dokumentu je obrisan, ali fajl nije mogao biti uklonjen: ' . $putanja);
                    }
                }

                upisi_trag(
                    $_SESSION['korisnicko_ime'],
                    'Obrisan dokument id=' . $id . ' (' . $dokument['originalni_naziv'] . ', status '
                    . $dokument['status'] . ') korisnika ' . $dokument['korisnicko_ime']
                    . '; fajl sa diska ' . ($fajl_obrisan ? 'obrisan' : 'NIJE obrisan')
                );

                if ($fajl_obrisan) {
                    postavi_poruku('success', 'Dokument je obrisan iz baze i fajl je uklonjen sa diska.');
                } else {
                    postavi_poruku(
                        'warning',
                        'Zapis o dokumentu je obrisan, ali fajl sa diska nije mogao biti uklonjen. '
                        . 'Greška je zabeležena u logu - fajl treba ukloniti ručno.'
                    );
                }
            }
        } catch (PDOException $e) {
            upisi_gresku('Greška pri brisanju dokumenta: ' . $e->getMessage());
            postavi_poruku('danger', 'Trenutno nije moguće obrisati dokument.');
        }
    }

    preusmeri(KORENSKI_URL . '/admin/dokumenta.php');
}

try {
    $dokumenti = $pdo->query(
        'SELECT dk.*, k.korisnicko_ime, k.ime, k.prezime, k.verifikovan
         FROM dokument dk JOIN korisnik k ON k.id = dk.korisnik_id
         ORDER BY dk.datum_slanja DESC'
    )->fetchAll();
} catch (PDOException $e) {
    upisi_gresku('Greška pri učitavanju dokumenata: ' . $e->getMessage());
    $dokumenti = [];
}

$csrf_token = generisi_csrf_token();
$naslov_stranice = 'Dokumenti';
require_once __DIR__ . '/../includes/zaglavlje.php';
?>

<h1 class="mb-4">Verifikacioni dokumenti</h1>

<?php prikazi_poruke(); ?>

<div class="alert alert-secondary small">
    Odobravanje i odbijanje dokumenata je na stranici
    <a href="<?= KORENSKI_URL ?>/operater/dokumenta.php">Dokumenta</a> u operaterskom delu.
    Ovde je samo trajno brisanje, koje uklanja i fajl sa diska.
    Brisanje odobrenog dokumenta ne ukida verifikaciju naloga - to se menja u
    <a href="<?= KORENSKI_URL ?>/admin/korisnici.php">Korisnicima</a>.
</div>

<div class="table-responsive">
    <table class="table table-striped align-middle">
        <thead>
            <tr>
                <th>Korisnik</th>
                <th>Fajl</th>
                <th>Veličina</th>
                <th>Status</th>
                <th>Poslato</th>
                <th class="text-end">Akcija</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($dokumenti as $dokument): ?>
                <tr>
                    <td>
                        <div><?= bezbedno($dokument['ime']) ?> <?= bezbedno($dokument['prezime']) ?></div>
                        <div class="text-muted small">
                            <?= bezbedno($dokument['korisnicko_ime']) ?>
                            <?php if ($dokument['verifikovan']): ?>
                                <span class="status status-odobren">verifikovan</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <a href="<?= KORENSKI_URL ?>/operater/dokument_preuzmi.php?id=<?= (int) $dokument['id'] ?>"
                           target="_blank" rel="noopener"><?= bezbedno($dokument['originalni_naziv']) ?></a>
                        <div class="text-muted" style="font-size:.75rem"><?= bezbedno($dokument['mime_tip']) ?></div>
                    </td>
                    <td class="small"><?= number_format($dokument['velicina_bajtova'] / 1024, 0) ?> KB</td>
                    <td>
                        <span class="status status-<?= bezbedno($dokument['status']) ?>"><?= bezbedno($dokument['status']) ?></span>
                    </td>
                    <td class="small"><?= bezbedno(formatiraj_datum_vreme($dokument['datum_slanja'])) ?></td>
                    <td class="text-end">
                        <form method="post"
                              onsubmit="return confirm('Trajno obrisati dokument <?= bezbedno($dokument['originalni_naziv']) ?> i ukloniti fajl sa diska? Ovo se ne može poništiti.');">
                            <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">
                            <input type="hidden" name="akcija" value="obrisi">
                            <input type="hidden" name="id" value="<?= (int) $dokument['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Obriši</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($dokumenti)): ?>
                <tr>
                    <td colspan="6">
                        <div class="prazno">
                            <i class="bi bi-file-earmark-x" aria-hidden="true"></i>
                            <div class="naslov-praznog">Nema poslatih dokumenata</div>
                            <p class="mb-0">Igrači šalju verifikacione dokumente sa svoje stranice Profil.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/podnozje.php'; ?>

<?php
// Uplata virtuelnih sredstava na račun igrača. Simulacija - nema pravih
// platnih kartica niti spoljnih servisa, samo se stanje računa uvećava i
// upisuje se prateća transakcija (poslovno pravilo 8).

require_once __DIR__ . '/../includes/autentifikacija.php';
require_once __DIR__ . '/../includes/funkcije.php';

zahtevaj_ulogu('igrac');

$pdo = konektuj_bazu();
$korisnik_id = (int) $_SESSION['korisnik_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_ispravan($_POST['csrf_token'] ?? null)) {
        postavi_poruku('danger', 'Nevažeća sesija. Osvežite stranicu i pokušajte ponovo.');
    } else {
        $unos = str_replace(',', '.', trim($_POST['iznos'] ?? ''));

        if ($unos === '' || !is_numeric($unos)) {
            postavi_poruku('danger', 'Unesite ispravan iznos.');
        } else {
            $iznos = round((float) $unos, 2);

            if ($iznos < MIN_UPLATA) {
                postavi_poruku('danger', 'Minimalna uplata je ' . formatiraj_novac(MIN_UPLATA) . '.');
            } elseif ($iznos > MAX_UPLATA) {
                postavi_poruku('danger', 'Maksimalna jednokratna uplata je ' . formatiraj_novac(MAX_UPLATA) . '.');
            } else {
                try {
                    $pdo->beginTransaction();

                    // FOR UPDATE zaključava red igrača dok traje transakcija,
                    // da dva istovremena zahteva ne bi jedan drugom pregazili stanje
                    $racun = $pdo->prepare('SELECT stanje_racuna FROM korisnik WHERE id = ? FOR UPDATE');
                    $racun->execute([$korisnik_id]);
                    $racun->fetch();

                    $azuriraj = $pdo->prepare('UPDATE korisnik SET stanje_racuna = stanje_racuna + ? WHERE id = ?');
                    $azuriraj->execute([number_format($iznos, 2, '.', ''), $korisnik_id]);

                    $novo = $pdo->prepare('SELECT stanje_racuna FROM korisnik WHERE id = ?');
                    $novo->execute([$korisnik_id]);
                    $novo_stanje = $novo->fetchColumn();

                    // Poslovno pravilo 8: svaka promena stanja računa ostavlja trag u transakciji
                    $transakcija = $pdo->prepare(
                        'INSERT INTO transakcija (korisnik_id, tip, iznos, stanje_posle, opis)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    $transakcija->execute([
                        $korisnik_id,
                        'uplata',
                        number_format($iznos, 2, '.', ''),
                        $novo_stanje,
                        'Uplata virtuelnih sredstava na račun',
                    ]);

                    $pdo->commit();
                    postavi_poruku('success', 'Uplata je uspešna. Novo stanje računa: ' . formatiraj_novac($novo_stanje) . '.');
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    upisi_gresku('Greška pri uplati sredstava: ' . $e->getMessage());
                    postavi_poruku('danger', 'Trenutno nije moguće izvršiti uplatu. Pokušajte ponovo kasnije.');
                }
            }
        }
    }

    preusmeri(KORENSKI_URL . '/igrac/uplata.php');
}

try {
    $upit = $pdo->prepare('SELECT stanje_racuna FROM korisnik WHERE id = ?');
    $upit->execute([$korisnik_id]);
    $stanje_racuna = $upit->fetchColumn();
} catch (PDOException $e) {
    upisi_gresku('Greška pri čitanju stanja računa: ' . $e->getMessage());
    $stanje_racuna = null;
}

$csrf_token = generisi_csrf_token();
$naslov_stranice = 'Uplata sredstava';
require_once __DIR__ . '/../includes/zaglavlje.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-md-6">
        <h1 class="mb-4">Uplata sredstava</h1>

        <?php prikazi_poruke(); ?>

        <div class="card shadow-sm">
            <div class="card-body">
                <p class="mb-3">
                    <span class="eyebrow">Trenutno stanje računa</span>
                    <strong class="stanje-iznos fs-4"><?= $stanje_racuna !== null ? bezbedno(formatiraj_novac($stanje_racuna)) : '—' ?></strong>
                </p>

                <form method="post" id="forma-uplata">
                    <input type="hidden" name="csrf_token" value="<?= bezbedno($csrf_token) ?>">

                    <label for="iznos" class="form-label">Iznos uplate</label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="iznos" name="iznos" required
                               step="0.01" min="<?= MIN_UPLATA ?>" max="<?= MAX_UPLATA ?>" value="<?= MIN_UPLATA ?>">
                        <span class="input-group-text">din</span>
                    </div>
                    <div class="form-text">
                        Iznos između <?= bezbedno(formatiraj_novac(MIN_UPLATA)) ?> i <?= bezbedno(formatiraj_novac(MAX_UPLATA)) ?>.
                        Ovo je simulacija - novac je virtuelan.
                    </div>

                    <button type="submit" class="btn btn-success w-100 mt-3">Uplati</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/podnozje.php'; ?>

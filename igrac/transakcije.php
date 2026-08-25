<?php
// Istorija transakcija igrača - svaka promena stanja računa (uplata, ulog,
// dobitak, isplata, storno) ostavlja ovde svoj trag (poslovno pravilo 8).

require_once __DIR__ . '/../includes/autentifikacija.php';

zahtevaj_ulogu('igrac');

$pdo = konektuj_bazu();
$korisnik_id = (int) $_SESSION['korisnik_id'];
$transakcije = [];

try {
    $upit = $pdo->prepare(
        'SELECT t.tip, t.iznos, t.stanje_posle, t.opis, t.datum_vreme, tk.broj_tiketa
         FROM transakcija t
         LEFT JOIN tiket tk ON tk.id = t.tiket_id
         WHERE t.korisnik_id = ?
         ORDER BY t.datum_vreme DESC'
    );
    $upit->execute([$korisnik_id]);
    $transakcije = $upit->fetchAll();
} catch (PDOException $e) {
    upisi_gresku('Greška pri učitavanju istorije transakcija: ' . $e->getMessage());
}

// Predznak i boja u prikazu - "prilivi" su zeleni, "odlivi" crveni.
// Sama provera je u includes/funkcije.php (transakcija_je_priliv), da bi
// prikaz i preračunavanje stanja računa koristili isto pravilo.

$naslov_stranice = 'Transakcije';
require_once __DIR__ . '/../includes/zaglavlje.php';
?>

<h1 class="mb-4">Istorija transakcija</h1>

<?php if (empty($transakcije)): ?>
    <div class="prazno">
        <i class="bi bi-cash-stack" aria-hidden="true"></i>
        <div class="naslov-praznog">Nema još nijedne transakcije</div>
        <p class="mb-3">Svaka promena stanja računa pojaviće se ovde.</p>
        <a href="<?= KORENSKI_URL ?>/igrac/uplata.php" class="btn btn-primary btn-sm">Uplati sredstva</a>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead>
                <tr>
                    <th>Datum i vreme</th>
                    <th>Tip</th>
                    <th>Opis</th>
                    <th class="text-end">Iznos</th>
                    <th class="text-end">Stanje posle</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transakcije as $t): ?>
                    <tr>
                        <td><?= bezbedno(formatiraj_datum_vreme($t['datum_vreme'])) ?></td>
                        <td><span class="status status-<?= bezbedno($t['tip']) ?>"><?= bezbedno($t['tip']) ?></span></td>
                        <td>
                            <?= bezbedno($t['opis']) ?>
                            <?php if ($t['broj_tiketa']): ?>
                                <span class="text-muted small">(<?= bezbedno($t['broj_tiketa']) ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td class="iznos">
                            <?php // Smer transakcije nosi i znak i boja - .iznos-plus je zelen, .iznos-minus crven ?>
                            <span class="<?= transakcija_je_priliv($t['tip']) ? 'iznos-plus' : 'iznos-minus' ?>">
                                <?= transakcija_je_priliv($t['tip']) ? '+' : '−' ?><?= bezbedno(formatiraj_novac($t['iznos'])) ?>
                            </span>
                        </td>
                        <td class="iznos"><?= bezbedno(formatiraj_novac($t['stanje_posle'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/podnozje.php'; ?>

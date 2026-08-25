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

<h1 class="h3 mb-4">Istorija transakcija</h1>

<?php if (empty($transakcije)): ?>
    <div class="alert alert-info">Nema još nijedne transakcije na vašem računu.</div>
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
                        <td><span class="badge text-bg-secondary text-uppercase"><?= bezbedno($t['tip']) ?></span></td>
                        <td>
                            <?= bezbedno($t['opis']) ?>
                            <?php if ($t['broj_tiketa']): ?>
                                <span class="text-muted small">(<?= bezbedno($t['broj_tiketa']) ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end <?= transakcija_je_priliv($t['tip']) ? 'text-success' : 'text-danger' ?>">
                            <?= transakcija_je_priliv($t['tip']) ? '+' : '−' ?><?= bezbedno(formatiraj_novac($t['iznos'])) ?>
                        </td>
                        <td class="text-end"><?= bezbedno(formatiraj_novac($t['stanje_posle'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/podnozje.php'; ?>

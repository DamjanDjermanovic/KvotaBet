<?php
// Usklađivanje stanja računa sa istorijom transakcija.
//
// Pravilo 8 kaže da svaka promena stanja mora imati zapis u tabeli transakcija.
// Iz toga sledi provera koja se ovde koristi: ako se sve transakcije igrača
// odigraju redom počev od nule, mora se dobiti tačno ono što piše u
// korisnik.stanje_racuna. Brisanje ili ručni unos transakcije sme se pustiti
// samo ako ta jednakost i posle njega važi.

require_once __DIR__ . '/../config/baza.php';
require_once __DIR__ . '/funkcije.php';
require_once __DIR__ . '/log.php';

// Sabira sve transakcije igrača redom i vraća stanje koje bi račun morao imati.
// Ne menja ništa u bazi - služi za proveru.
function ocekivano_stanje_racuna(PDO $pdo, int $korisnik_id): float
{
    $upit = $pdo->prepare('SELECT tip, iznos FROM transakcija WHERE korisnik_id = ? ORDER BY datum_vreme, id');
    $upit->execute([$korisnik_id]);

    $stanje = 0.0;
    foreach ($upit->fetchAll() as $transakcija) {
        $iznos = (float) $transakcija['iznos'];
        $stanje += transakcija_je_priliv($transakcija['tip']) ? $iznos : -$iznos;
        $stanje = round($stanje, 2);
    }

    return $stanje;
}

// Ponovo računa ceo niz transakcija igrača: popravlja kolonu stanje_posle u
// svakom redu i upisuje konačan iznos u korisnik.stanje_racuna. Time posle
// brisanja jednog zapisa niz opet postaje neprekidan.
//
// Očekuje otvorenu transakciju baze i zaključan red korisnika (FOR UPDATE).
// Ako bi u nekom trenutku stanje otišlo ispod nule, ceo posao se odbija -
// takav niz transakcija ne bi mogao ni da nastane u stvarnom radu aplikacije,
// a kolona stanje_racuna ionako ima CHECK (stanje_racuna >= 0).
function preracunaj_stanje_racuna(PDO $pdo, int $korisnik_id): string
{
    $upit = $pdo->prepare('SELECT id, tip, iznos FROM transakcija WHERE korisnik_id = ? ORDER BY datum_vreme, id');
    $upit->execute([$korisnik_id]);
    $transakcije = $upit->fetchAll();

    $azuriraj = $pdo->prepare('UPDATE transakcija SET stanje_posle = ? WHERE id = ?');
    $stanje = 0.0;

    foreach ($transakcije as $transakcija) {
        $iznos = (float) $transakcija['iznos'];
        $stanje += transakcija_je_priliv($transakcija['tip']) ? $iznos : -$iznos;
        $stanje = round($stanje, 2);

        if ($stanje < 0) {
            throw new RuntimeException(
                'Posle ove izmene stanje računa bi u jednom trenutku bilo negativno, '
                . 'pa izmena nije izvršena.'
            );
        }

        $azuriraj->execute([za_bazu($stanje), (int) $transakcija['id']]);
    }

    $postavi = $pdo->prepare('UPDATE korisnik SET stanje_racuna = ? WHERE id = ?');
    $postavi->execute([za_bazu($stanje), $korisnik_id]);

    return za_bazu($stanje);
}

// Vraća spisak igrača kod kojih se stanje računa ne poklapa sa zbirom
// transakcija. U ispravnom radu aplikacije lista je uvek prazna; koristi se
// u admin panelu kao kontrola da ništa nije "iscurelo" mimo evidencije.
function nesaglasni_racuni(PDO $pdo): array
{
    $upit = $pdo->query(
        "SELECT k.id, k.korisnicko_ime, k.stanje_racuna,
                COALESCE(SUM(CASE WHEN t.tip IN ('uplata','dobitak','storno') THEN t.iznos
                                  ELSE -t.iznos END), 0) AS zbir_transakcija
         FROM korisnik k
         LEFT JOIN transakcija t ON t.korisnik_id = k.id
         GROUP BY k.id
         HAVING ABS(k.stanje_racuna - zbir_transakcija) > 0.001
         ORDER BY k.korisnicko_ime"
    );

    return $upit->fetchAll();
}

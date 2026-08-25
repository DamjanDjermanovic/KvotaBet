<?php
// AJAX: živi proračun ukupne kvote i potencijalnog dobitka. Poziva se dok
// igrač kuca ulog, pa uz iznose vraća i poruku o grešci ako ulog izlazi iz
// dozvoljenih granica ili premašuje stanje računa (poslovno pravilo 4).
//
// Proračun namerno radi server, a ne JavaScript: klijentu se ne sme verovati
// da tačno izračuna ono na osnovu čega se skida novac sa računa.

require_once __DIR__ . '/zajednicko.php';
require_once __DIR__ . '/../includes/tiket.php';

zahtevaj_post();
zahtevaj_csrf();

try {
    $pdo = konektuj_bazu();

    json_odgovor(array_merge(
        stanje_tiketa($pdo, $_POST['ulog'] ?? null),
        ['uspeh' => true]
    ));
} catch (PDOException $e) {
    upisi_gresku('Greška pri proračunu tiketa: ' . $e->getMessage());
    json_odgovor(['uspeh' => false, 'poruka' => 'Trenutno nije moguće izračunati tiket.'], 500);
}

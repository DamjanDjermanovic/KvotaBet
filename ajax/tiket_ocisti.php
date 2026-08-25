<?php
// AJAX: uklanja sve stavke sa tiketa u izradi.

require_once __DIR__ . '/zajednicko.php';
require_once __DIR__ . '/../includes/tiket.php';

zahtevaj_post();
zahtevaj_csrf();

try {
    $pdo = konektuj_bazu();

    tiket_isprazni();

    json_odgovor(array_merge(
        stanje_tiketa($pdo, $_POST['ulog'] ?? null),
        ['uspeh' => true, 'poruka' => 'Tiket je ispražnjen.']
    ));
} catch (PDOException $e) {
    upisi_gresku('Greška pri pražnjenju tiketa: ' . $e->getMessage());
    json_odgovor(['uspeh' => false, 'poruka' => 'Trenutno nije moguće isprazniti tiket.'], 500);
}

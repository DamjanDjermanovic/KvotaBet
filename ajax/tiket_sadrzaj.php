<?php
// AJAX: vraća trenutno stanje tiketa iz sesije. Poziva se pri učitavanju
// stranice, da bi se panel popunio i da bi se u ponudi označile kvote koje
// su već na tiketu (sesija preživljava osvežavanje stranice).

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
    upisi_gresku('Greška pri čitanju tiketa: ' . $e->getMessage());
    json_odgovor(['uspeh' => false, 'poruka' => 'Trenutno nije moguće učitati tiket.'], 500);
}

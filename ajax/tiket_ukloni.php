<?php
// AJAX: uklanja jednu kvotu (par) sa tiketa u izradi.

require_once __DIR__ . '/zajednicko.php';
require_once __DIR__ . '/../includes/tiket.php';

zahtevaj_post();
zahtevaj_csrf();

$ulog_unos = $_POST['ulog'] ?? null;
$kvota_unos = $_POST['kvota_id'] ?? '';

try {
    $pdo = konektuj_bazu();

    if (!ctype_digit((string) $kvota_unos)) {
        json_odgovor(array_merge(
            stanje_tiketa($pdo, $ulog_unos),
            ['uspeh' => false, 'poruka' => 'Neispravna kvota.']
        ), 400);
    }

    $kvota_id = (int) $kvota_unos;

    // Zadržavaju se sve kvote osim tražene; ako tražene nije ni bilo na tiketu,
    // spisak ostaje isti i odgovor je i dalje uspešan jer je krajnje stanje
    // ono koje je igrač i tražio
    $preostale = array_filter(tiket_kvote(), function (int $id) use ($kvota_id) {
        return $id !== $kvota_id;
    });

    tiket_postavi($preostale);

    json_odgovor(array_merge(
        stanje_tiketa($pdo, $ulog_unos),
        ['uspeh' => true]
    ));
} catch (PDOException $e) {
    upisi_gresku('Greška pri uklanjanju para sa tiketa: ' . $e->getMessage());
    json_odgovor(['uspeh' => false, 'poruka' => 'Trenutno nije moguće ukloniti par sa tiketa.'], 500);
}

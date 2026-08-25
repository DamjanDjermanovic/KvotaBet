<?php
// AJAX: dodaje jednu kvotu (par) na tiket u izradi.
//
// Poštuju se poslovna pravila 1 i 2: stavka je uvek jedna kvota jednog
// događaja, a sa istog događaja na tiketu sme biti najviše jedna kvota.

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

    // Kvota mora biti aktivna, a njen događaj još uvek najavljen i pre početka -
    // na događaj koji je počeo ili je zaključen ne sme se primiti nova uplata
    $upit = $pdo->prepare(
        "SELECT k.id, k.dogadjaj_id
         FROM kvota k
         JOIN dogadjaj d ON d.id = k.dogadjaj_id
         WHERE k.id = ?
           AND k.aktivna = 1
           AND d.status = 'najavljen'
           AND d.vreme_pocetka > NOW()"
    );
    $upit->execute([$kvota_id]);
    $kvota = $upit->fetch();

    if (!$kvota) {
        json_odgovor(array_merge(
            stanje_tiketa($pdo, $ulog_unos),
            ['uspeh' => false, 'poruka' => 'Ovaj par više nije u ponudi.']
        ), 400);
    }

    // Postojeće stavke se učitavaju pre provere, čime se usput iz sesije
    // izbacuje sve što je u međuvremenu ispalo iz ponude
    $stavke = ucitaj_stavke_tiketa($pdo);

    foreach ($stavke as $stavka) {
        if ((int) $stavka['kvota_id'] === $kvota_id) {
            json_odgovor(array_merge(
                stanje_tiketa($pdo, $ulog_unos),
                ['uspeh' => false, 'poruka' => 'Ovaj par je već na tiketu.']
            ), 400);
        }

        // Poslovno pravilo 2
        if ((int) $stavka['dogadjaj_id'] === (int) $kvota['dogadjaj_id']) {
            json_odgovor(array_merge(
                stanje_tiketa($pdo, $ulog_unos),
                ['uspeh' => false, 'poruka' => 'Sa jednog događaja može biti samo jedan par na tiketu.']
            ), 400);
        }
    }

    $kvote = tiket_kvote();
    $kvote[] = $kvota_id;
    tiket_postavi($kvote);

    json_odgovor(array_merge(
        stanje_tiketa($pdo, $ulog_unos),
        ['uspeh' => true]
    ));
} catch (PDOException $e) {
    upisi_gresku('Greška pri dodavanju para na tiket: ' . $e->getMessage());
    json_odgovor(['uspeh' => false, 'poruka' => 'Trenutno nije moguće dodati par na tiket.'], 500);
}

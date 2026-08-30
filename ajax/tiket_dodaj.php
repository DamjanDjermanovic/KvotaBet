<?php
// AJAX: dodaje jednu kvotu (par) na tiket u izradi.
//
// Poštuju se poslovna pravila 1 i 2: stavka je uvek jedna kvota jednog
// događaja, a sa istog događaja na tiketu sme biti najviše jedna kvota. Klik
// na drugu kvotu istog događaja tu staru kvotu ZAMENJUJE novom (stara se
// skida, nova dodaje) - pravilo 2 time nije oslabljeno, samo se do njega
// stiže zamenom umesto odbijanjem klika.

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

    // Ista kvota koja je već na tiketu - ovo se u redovnom radu ne dešava,
    // jer klik na već izabrano dugme u ponudi ide na tiket_ukloni.php, ne
    // ovde (js/tiket.js bira fajl po klasi .izabrana). Do ovoga bi moglo doći
    // samo ako je stanje na klijentu zastarelo (npr. druga otvorena kartica).
    // Ponašanje ostaje kao i pre uvođenja zamene: odbija se, ne "zamenjuje
    // sama sobom".
    foreach ($stavke as $stavka) {
        if ((int) $stavka['kvota_id'] === $kvota_id) {
            json_odgovor(array_merge(
                stanje_tiketa($pdo, $ulog_unos),
                ['uspeh' => false, 'poruka' => 'Ovaj par je već na tiketu.']
            ), 400);
        }
    }

    // Poslovno pravilo 2: sa jednog događaja sme biti samo jedna kvota na
    // tiketu. Umesto da se klik na drugu kvotu istog događaja odbije, ta
    // stara stavka se ovde izbacuje iz nove liste (zamenjuje), a njen
    // kvota_id se pamti da bi odgovor mogao da javi šta se desilo.
    $zamenjena_kvota_id = null;
    $nova_lista = [];

    foreach ($stavke as $stavka) {
        if ((int) $stavka['dogadjaj_id'] === (int) $kvota['dogadjaj_id']) {
            $zamenjena_kvota_id = (int) $stavka['kvota_id'];
            continue;
        }

        $nova_lista[] = (int) $stavka['kvota_id'];
    }

    $nova_lista[] = $kvota_id;
    tiket_postavi($nova_lista);

    $dodatno = ['uspeh' => true, 'zamenjena_kvota_id' => $zamenjena_kvota_id];

    if ($zamenjena_kvota_id !== null) {
        $dodatno['poruka'] = 'Prethodni par sa ovog događaja je zamenjen novim.';
    }

    json_odgovor(array_merge(stanje_tiketa($pdo, $ulog_unos), $dodatno));
} catch (PDOException $e) {
    upisi_gresku('Greška pri dodavanju para na tiket: ' . $e->getMessage());
    json_odgovor(['uspeh' => false, 'poruka' => 'Trenutno nije moguće dodati par na tiket.'], 500);
}

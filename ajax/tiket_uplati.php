<?php
// AJAX: uplata tiketa.
//
// Ovo je jedino mesto u fazi 5 koje menja bazu, pa se ceo posao radi u
// transakciji baze (poslovno pravilo 9): ili prođu svi koraci - zapis tiketa,
// stavke sa snimljenim kvotama, skidanje uloga i zapis u tabelu transakcija -
// ili se ne desi nijedan.

require_once __DIR__ . '/zajednicko.php';
require_once __DIR__ . '/../includes/tiket.php';

zahtevaj_post();
zahtevaj_csrf();
zahtevaj_ulogu_json('igrac');

$pdo = konektuj_bazu();
$korisnik_id = (int) $_SESSION['korisnik_id'];
$ulog_unos = $_POST['ulog'] ?? null;

try {
    $pdo->beginTransaction();

    // Red korisnika se zaključava (FOR UPDATE) dok traje transakcija. Bez toga
    // bi dva istovremena zahteva mogla oba da prođu proveru stanja i zajedno
    // skinu više nego što na računu zaista ima.
    $upit = $pdo->prepare('SELECT stanje_racuna, status FROM korisnik WHERE id = ? FOR UPDATE');
    $upit->execute([$korisnik_id]);
    $korisnik = $upit->fetch();

    if (!$korisnik) {
        throw new RuntimeException('Nalog nije pronađen.');
    }

    if ($korisnik['status'] !== 'aktivan') {
        throw new RuntimeException('Vaš nalog nije aktivan, pa uplata tiketa nije moguća.');
    }

    // Stavke se iznova čitaju iz baze umesto da se veruje onome što je klijent
    // poslao. Ako je nešto u međuvremenu ispalo iz ponude, biće izbačeno iz
    // sesije, pa se broj stavki neće poklopiti sa onim što je igrač video.
    $broj_pre = count(tiket_kvote());
    $stavke = ucitaj_stavke_tiketa($pdo);

    // Poslovno pravilo 1: tiket ima bar jednu stavku
    if (empty($stavke)) {
        throw new RuntimeException('Tiket je prazan. Dodajte bar jedan par.');
    }

    if (count($stavke) !== $broj_pre) {
        throw new RuntimeException('Ponuda se u međuvremenu promenila. Proverite tiket pa pokušajte ponovo.');
    }

    // Poslovno pravilo 4
    $provera = proveri_ulog($ulog_unos, $korisnik['stanje_racuna']);

    if ($provera['greska'] !== null) {
        throw new RuntimeException($provera['greska']);
    }

    if ($provera['ulog'] === null) {
        throw new RuntimeException('Unesite ulog.');
    }

    $ulog = $provera['ulog'];

    // Poslovno pravilo 3
    $ukupna_kvota = ukupna_kvota_tiketa($stavke);
    $dobitak = potencijalni_dobitak($ulog, $ukupna_kvota);

    if ($ukupna_kvota > MAX_UKUPNA_KVOTA || $dobitak > MAX_POTENCIJALNI_DOBITAK) {
        throw new RuntimeException('Ukupna kvota tiketa je previsoka. Uklonite neki par sa tiketa.');
    }

    $broj_tiketa = jedinstveni_broj_tiketa($pdo);

    $upis_tiketa = $pdo->prepare(
        'INSERT INTO tiket (broj_tiketa, korisnik_id, ulog, ukupna_kvota, potencijalni_dobitak)
         VALUES (?, ?, ?, ?, ?)'
    );
    $upis_tiketa->execute([
        $broj_tiketa,
        $korisnik_id,
        za_bazu($ulog),
        za_bazu($ukupna_kvota),
        za_bazu($dobitak),
    ]);

    $tiket_id = (int) $pdo->lastInsertId();

    // Poslovno pravilo 5: vrednost kvote se prepisuje u stavku kao snimak u
    // trenutku uplate. Kad operater kasnije promeni kvotu, ovaj tiket ostaje
    // netaknut jer se obračun radi po kvota_vrednost, a ne po tekućoj kvoti.
    $upis_stavke = $pdo->prepare(
        'INSERT INTO tiket_stavka (tiket_id, kvota_id, kvota_vrednost) VALUES (?, ?, ?)'
    );
    foreach ($stavke as $stavka) {
        $upis_stavke->execute([
            $tiket_id,
            (int) $stavka['kvota_id'],
            za_bazu((float) $stavka['vrednost']),
        ]);
    }

    // Oduzimanje računa sama baza nad DECIMAL kolonom, a ne PHP nad float
    // vrednošću - tako nema greške zaokruživanja u stanju računa
    $skidanje = $pdo->prepare('UPDATE korisnik SET stanje_racuna = stanje_racuna - ? WHERE id = ?');
    $skidanje->execute([za_bazu($ulog), $korisnik_id]);

    $novo = $pdo->prepare('SELECT stanje_racuna FROM korisnik WHERE id = ?');
    $novo->execute([$korisnik_id]);
    $novo_stanje = (string) $novo->fetchColumn();

    // Poslovno pravilo 8: nijedna promena stanja računa ne sme proći bez traga
    $upis_transakcije = $pdo->prepare(
        'INSERT INTO transakcija (korisnik_id, tiket_id, tip, iznos, stanje_posle, opis)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $upis_transakcije->execute([
        $korisnik_id,
        $tiket_id,
        'ulog',
        za_bazu($ulog),
        $novo_stanje,
        'Ulog za tiket ' . $broj_tiketa,
    ]);

    $pdo->commit();

    // Tiket je uplaćen, pa korpa u sesiji više nema smisla
    tiket_isprazni();

    json_odgovor(array_merge(stanje_tiketa($pdo, null), [
        'uspeh'       => true,
        'broj_tiketa' => $broj_tiketa,
        'poruka'      => 'Tiket ' . $broj_tiketa . ' je uspešno uplaćen. '
                         . 'Novo stanje računa: ' . formatiraj_novac($novo_stanje) . '.',
    ]));
} catch (PDOException $e) {
    // PDOException se hvata pre RuntimeException zato što je njegov naslednik -
    // da je redosled obrnut, greške baze bi završile u pogrešnom bloku i
    // korisniku bi se prikazala sirova poruka izuzetka
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    upisi_gresku('Greška pri uplati tiketa: ' . $e->getMessage());
    json_odgovor(['uspeh' => false, 'poruka' => 'Trenutno nije moguće uplatiti tiket. Pokušajte ponovo kasnije.'], 500);
} catch (RuntimeException $e) {
    // Prekršeno poslovno pravilo - poruka je unapred napisana na srpskom i
    // bezbedna za prikaz igraču
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_odgovor(array_merge(
        stanje_tiketa($pdo, $ulog_unos),
        ['uspeh' => false, 'poruka' => $e->getMessage()]
    ), 400);
}

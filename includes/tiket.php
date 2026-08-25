<?php
// Tiket u izradi ("korpa") koji se čuva u sesiji, i proračuni nad njim.
//
// U sesiji se pamte isključivo ID-jevi kvota, nikad njihove vrednosti. Kvote
// se pri svakom prikazu iznova čitaju iz baze, pa igrač uvek vidi aktuelnu
// kvotu, a snimak vrednosti se pravi tek u trenutku uplate (poslovno pravilo 5).

require_once __DIR__ . '/autentifikacija.php';

// Ključ pod kojim tiket u izradi stoji u sesiji
define('KLJUC_SESIJE_TIKET', 'tiket_u_izradi');

// Gornje granice koje kolone u bazi mogu da prime: tiket.ukupna_kvota je
// DECIMAL(10,2), a tiket.potencijalni_dobitak DECIMAL(12,2). Bez ove provere
// tiket sa previše parova oborio bi upit umesto da igrač dobije jasnu poruku.
define('MAX_UKUPNA_KVOTA', 99999999.99);
define('MAX_POTENCIJALNI_DOBITAK', 9999999999.99);

// Vraća ID-jeve kvota koje su trenutno na tiketu u izradi, redosledom kojim
// ih je igrač dodavao
function tiket_kvote(): array
{
    $kvote = $_SESSION[KLJUC_SESIJE_TIKET] ?? [];

    return is_array($kvote) ? array_map('intval', $kvote) : [];
}

// Upisuje novi spisak kvota u sesiju (array_values da ključevi ostanu 0,1,2...)
function tiket_postavi(array $kvote): void
{
    $_SESSION[KLJUC_SESIJE_TIKET] = array_values(array_map('intval', $kvote));
}

// Uklanja ceo tiket iz sesije - poziva se posle uspešne uplate i na zahtev igrača
function tiket_isprazni(): void
{
    unset($_SESSION[KLJUC_SESIJE_TIKET]);
}

// Učitava pune podatke o stavkama tiketa iz baze. Uzimaju se samo kvote koje
// su i dalje u ponudi: aktivna kvota, događaj u statusu "najavljen" i vreme
// početka još nije prošlo (poslovno pravilo 6). Sve što je u međuvremenu
// ispalo iz ponude izbacuje se iz sesije, da igrač ne bi uplatio par koji
// više ne postoji.
function ucitaj_stavke_tiketa(PDO $pdo): array
{
    $kvote = tiket_kvote();

    if (empty($kvote)) {
        return [];
    }

    $mesta = implode(',', array_fill(0, count($kvote), '?'));

    $upit = $pdo->prepare(
        "SELECT k.id AS kvota_id, k.vrednost, k.dogadjaj_id,
                ti.oznaka,
                d.vreme_pocetka,
                td.naziv AS domacin, tg.naziv AS gost,
                l.naziv AS liga_naziv
         FROM kvota k
         JOIN tip_igre ti ON ti.id = k.tip_igre_id
         JOIN dogadjaj d  ON d.id = k.dogadjaj_id
         JOIN tim td      ON td.id = d.domacin_id
         JOIN tim tg      ON tg.id = d.gost_id
         JOIN liga l      ON l.id = d.liga_id
         WHERE k.id IN ($mesta)
           AND k.aktivna = 1
           AND d.status = 'najavljen'
           AND d.vreme_pocetka > NOW()"
    );
    $upit->execute($kvote);

    // Rezultat se preslaguje po ID-ju kvote jer SQL ne garantuje da će redovi
    // stići redosledom iz IN liste, a igraču stavke treba prikazati onako
    // kako ih je dodavao
    $po_id = [];
    foreach ($upit->fetchAll() as $red) {
        $po_id[(int) $red['kvota_id']] = $red;
    }

    $stavke = [];
    $preostale = [];
    foreach ($kvote as $kvota_id) {
        if (isset($po_id[$kvota_id])) {
            $stavke[] = $po_id[$kvota_id];
            $preostale[] = $kvota_id;
        }
    }

    if (count($preostale) !== count($kvote)) {
        tiket_postavi($preostale);
    }

    return $stavke;
}

// Poslovno pravilo 3: ukupna kvota tiketa je proizvod kvota svih stavki.
// Zaokružuje se na dve decimale odmah, jer se ista vrednost i prikazuje igraču
// i upisuje u kolonu DECIMAL(10,2) - tako se prikaz i baza uvek poklapaju.
// Prazan tiket nema kvotu, pa se vraća 0.
function ukupna_kvota_tiketa(array $stavke): float
{
    if (empty($stavke)) {
        return 0.0;
    }

    $proizvod = 1.0;
    foreach ($stavke as $stavka) {
        $proizvod *= (float) $stavka['vrednost'];
    }

    return round($proizvod, 2);
}

// Poslovno pravilo 3: potencijalni dobitak = ulog × ukupna kvota
function potencijalni_dobitak(float $ulog, float $ukupna_kvota): float
{
    return round($ulog * $ukupna_kvota, 2);
}

// Poslovno pravilo 4: ulog mora biti između MIN_ULOG i MAX_ULOG i ne veći od
// stanja računa. Vraća niz ['ulog' => float|null, 'greska' => string|null].
// Prazan unos nije greška (igrač još nije ni počeo da kuca), ali ni ispravan
// ulog - zato je 'ulog' tada null pa uplata ostaje onemogućena.
// $stanje_racuna je null za posetioca koji nije igrač; tada se stanje ne proverava.
function proveri_ulog(?string $unos, ?string $stanje_racuna): array
{
    $unos = trim((string) $unos);

    if ($unos === '') {
        return ['ulog' => null, 'greska' => null];
    }

    // Kod nas se decimalni zarez kuca češće od tačke, pa se prihvataju oba
    $unos = str_replace(',', '.', $unos);

    if (!is_numeric($unos)) {
        return ['ulog' => null, 'greska' => 'Ulog mora biti broj.'];
    }

    $ulog = round((float) $unos, 2);

    if ($ulog < MIN_ULOG) {
        return ['ulog' => $ulog, 'greska' => 'Minimalni ulog je ' . formatiraj_novac(MIN_ULOG) . '.'];
    }

    if ($ulog > MAX_ULOG) {
        return ['ulog' => $ulog, 'greska' => 'Maksimalni ulog je ' . formatiraj_novac(MAX_ULOG) . '.'];
    }

    if ($stanje_racuna !== null && $ulog > round((float) $stanje_racuna, 2)) {
        return ['ulog' => $ulog, 'greska' => 'Nemate dovoljno sredstava na računu.'];
    }

    return ['ulog' => $ulog, 'greska' => null];
}

// Sastavlja kompletno stanje tiketa u obliku koji se šalje klijentu kao JSON.
// Svi AJAX endpointi vraćaju baš ovaj oblik, pa JS ima samo jednu funkciju za
// iscrtavanje panela. Sve iznose računa server - klijent ih samo ispisuje.
function stanje_tiketa(PDO $pdo, ?string $ulog_unos = null): array
{
    $broj_pre = count(tiket_kvote());
    $stavke = ucitaj_stavke_tiketa($pdo);
    $uklonjeno = $broj_pre - count($stavke);

    $ukupna_kvota = ukupna_kvota_tiketa($stavke);

    // Stanje računa se čita samo za igrača - operater i admin nemaju račun za
    // klađenje, a gost uopšte nije prijavljen
    $stanje_racuna = null;
    if (trenutna_uloga() === 'igrac' && isset($_SESSION['korisnik_id'])) {
        $upit = $pdo->prepare('SELECT stanje_racuna FROM korisnik WHERE id = ?');
        $upit->execute([(int) $_SESSION['korisnik_id']]);
        $procitano = $upit->fetchColumn();
        $stanje_racuna = $procitano !== false ? (string) $procitano : null;
    }

    $provera = proveri_ulog($ulog_unos, $stanje_racuna);
    $dobitak = potencijalni_dobitak($provera['ulog'] ?? 0.0, $ukupna_kvota);

    // Uplata je moguća samo igraču, samo sa bar jednom stavkom (pravilo 1) i
    // samo sa ispravnim ulogom (pravilo 4)
    $moze_uplata = trenutna_uloga() === 'igrac'
        && !empty($stavke)
        && $provera['ulog'] !== null
        && $provera['greska'] === null;

    $stavke_prikaz = [];
    foreach ($stavke as $stavka) {
        $stavke_prikaz[] = [
            'kvota_id' => (int) $stavka['kvota_id'],
            'oznaka'   => $stavka['oznaka'],
            'vrednost' => number_format((float) $stavka['vrednost'], 2),
            'domacin'  => $stavka['domacin'],
            'gost'     => $stavka['gost'],
            'liga'     => $stavka['liga_naziv'],
            'vreme'    => formatiraj_datum_vreme($stavka['vreme_pocetka']),
        ];
    }

    return [
        'stavke'               => $stavke_prikaz,
        'broj_stavki'          => count($stavke_prikaz),
        'ukupna_kvota'         => number_format($ukupna_kvota, 2),
        'potencijalni_dobitak' => formatiraj_novac($dobitak),
        'ulog_greska'          => $provera['greska'],
        'moze_uplata'          => $moze_uplata,
        'stanje_racuna'        => $stanje_racuna !== null ? formatiraj_novac($stanje_racuna) : null,
        'uklonjeno'            => $uklonjeno,
    ];
}

// Generiše broj tiketa i proverava da već ne postoji u bazi. Kolona
// broj_tiketa je UNIQUE, pa bi ponavljanje oborilo upis; šansa je zanemarljiva
// ali se ipak pokušava nekoliko puta pre nego što se odustane.
function jedinstveni_broj_tiketa(PDO $pdo): string
{
    $upit = $pdo->prepare('SELECT id FROM tiket WHERE broj_tiketa = ?');

    for ($pokusaj = 0; $pokusaj < 5; $pokusaj++) {
        $broj = generisi_broj_tiketa();
        $upit->execute([$broj]);

        if (!$upit->fetch()) {
            return $broj;
        }
    }

    throw new RuntimeException('Nije moguće generisati broj tiketa. Pokušajte ponovo.');
}

// Napomena: za_bazu() je premeštena u includes/funkcije.php, jer je od faze 8
// treba i modul za obradu rezultata, koji ne sme da zavisi od sesije.

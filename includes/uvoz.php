<?php
// Jezgro pomoćnog uvoza ponude sa The Odds API-ja: pretvaranje odgovora
// servisa u oblik naše šeme, prepoznavanje duplikata, povezivanje timova i
// upis izabranih utakmica u bazu.
//
// Stranica operater/uvoz.php sadrži samo prikaz i obradu forme; sve odluke
// o tome šta se sme upisati donose se ovde, na serveru, iz podataka
// pročitanih sa diska - nikad iz onoga što je forma poslala.

require_once __DIR__ . '/../config/konfiguracija.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/funkcije.php';
require_once __DIR__ . '/odds_api.php';


// ---------------------------------------------------------------------
// ČITANJE IZ BAZE
// ---------------------------------------------------------------------

// Lige koje imaju mapiranje na ključ sa API-ja. Uvoz se nudi samo za njih,
// jer bez mapiranja ne bi bilo poznato u koju ligu upisati preuzete utakmice.
function ucitaj_mapirane_lige(PDO $pdo, bool $samo_aktivne = true): array
{
    $sql = 'SELECT al.id AS mapiranje_id, al.api_kljuc, al.naziv_kod_api, al.aktivna,
                   l.id, l.naziv, l.drzava, s.id AS sport_id, s.naziv AS sport_naziv
            FROM api_liga al
            JOIN liga l ON l.id = al.liga_id
            JOIN sport s ON s.id = l.sport_id';

    if ($samo_aktivne) {
        $sql .= ' WHERE al.aktivna = 1';
    }

    $sql .= ' ORDER BY s.naziv, l.naziv';

    try {
        return $pdo->query($sql)->fetchAll();
    } catch (PDOException $e) {
        upisi_gresku('Greška pri učitavanju mapiranja liga: ' . $e->getMessage());
        return [];
    }
}

// Svi timovi jedne lige, sa oba naziva - našim i onim kod API-ja.
function timovi_lige(PDO $pdo, int $liga_id): array
{
    try {
        $upit = $pdo->prepare('SELECT id, naziv, api_naziv FROM tim WHERE liga_id = ? ORDER BY naziv');
        $upit->execute([$liga_id]);

        return $upit->fetchAll();
    } catch (PDOException $e) {
        upisi_gresku('Greška pri učitavanju timova lige: ' . $e->getMessage());
        return [];
    }
}


// ---------------------------------------------------------------------
// POVEZIVANJE TIMOVA
// ---------------------------------------------------------------------

// Traži tim iz baze koji odgovara nazivu sa API-ja. Redosled pokušaja ide od
// najpouzdanijeg ka najslabijem, da odluka operatera uvek pobedi pogađanje:
//   1. tačno onaj naziv koji je operater sam povezao (tim.api_naziv)
//   2. isti povezan naziv, do razlike u velikim slovima i interpunkciji
//   3. naš naziv tima se poklapa sa nazivom kod API-ja
// Ako nijedno ne prođe, tim je nepoznat i operater ga rešava iz pregleda.
function pronadji_tim_po_api_nazivu(array $timovi, string $api_naziv): ?array
{
    $trazeni = normalizuj_naziv_tima($api_naziv);

    if ($trazeni === '') {
        return null;
    }

    foreach ($timovi as $tim) {
        if (($tim['api_naziv'] ?? null) !== null && $tim['api_naziv'] === $api_naziv) {
            return $tim;
        }
    }

    foreach ($timovi as $tim) {
        if (($tim['api_naziv'] ?? null) !== null && normalizuj_naziv_tima($tim['api_naziv']) === $trazeni) {
            return $tim;
        }
    }

    foreach ($timovi as $tim) {
        if (normalizuj_naziv_tima($tim['naziv']) === $trazeni) {
            return $tim;
        }
    }

    return null;
}

// Rešava jedan nepoznat tim iz pregleda: ili pravi novi tim u ligi, ili
// upisuje naziv sa API-ja postojećem timu ($tim_id). Ovo je jedini upis u
// bazu koji uvoz radi van "Sačuvaj u bazu", i pokreće ga isključivo poseban
// klik operatera - rečnik timova je odluka odvojena od same ponude.
function povezi_api_tim(PDO $pdo, int $liga_id, string $api_naziv, ?int $tim_id): array
{
    $api_naziv = trim($api_naziv);

    if ($api_naziv === '' || mb_strlen($api_naziv) > 80) {
        return ['uspeh' => false, 'poruka' => 'Naziv tima sa servisa nije upotrebljiv (prazan ili duži od 80 znakova).'];
    }

    try {
        if ($tim_id === null) {
            // Novi tim dobija naziv kakav je kod servisa; operater ga posle
            // sme preimenovati kroz operater/timovi.php, a veza ostaje jer je
            // nosi kolona api_naziv, a ne sam naziv
            $upit = $pdo->prepare('INSERT INTO tim (liga_id, naziv, api_naziv) VALUES (?, ?, ?)');
            $upit->execute([$liga_id, $api_naziv, $api_naziv]);

            return ['uspeh' => true, 'poruka' => 'Napravljen je tim „' . $api_naziv . '“.'];
        }

        // Postojeći tim mora biti iz iste lige - inače bi uvoz napravio
        // događaj sa timovima iz dve lige, što operater/dogadjaji.php
        // izričito zabranjuje pri ručnom unosu
        $provera = $pdo->prepare('SELECT naziv FROM tim WHERE id = ? AND liga_id = ?');
        $provera->execute([$tim_id, $liga_id]);
        $naziv = $provera->fetchColumn();

        if ($naziv === false) {
            return ['uspeh' => false, 'poruka' => 'Izabrani tim ne pripada ovoj ligi.'];
        }

        $upit = $pdo->prepare('UPDATE tim SET api_naziv = ? WHERE id = ?');
        $upit->execute([$api_naziv, $tim_id]);

        return ['uspeh' => true, 'poruka' => '„' . $api_naziv . '“ je povezan sa timom „' . $naziv . '“.'];
    } catch (PDOException $e) {
        if ((int) $e->getCode() === 23000) {
            return ['uspeh' => false, 'poruka' => 'Taj naziv sa servisa je već povezan sa nekim timom ove lige.'];
        }

        upisi_gresku('Greška pri povezivanju tima sa nazivom iz API-ja: ' . $e->getMessage());

        return ['uspeh' => false, 'poruka' => 'Trenutno nije moguće povezati tim.'];
    }
}


// ---------------------------------------------------------------------
// PREGLED PRE UPISA
// ---------------------------------------------------------------------

// Pretvara sirov odgovor API-ja u redove tabele pregleda. Ništa ne upisuje.
//
// Ista funkcija se poziva dvaput: jednom da bi operater video šta dobija, i
// drugi put pri upisu - tada nad istim (keširanim) odgovorom, ali unutar
// transakcije i sa $zakljucaj = true. Tako se upisuje ono što je server sam
// izračunao, a ne ono što je forma poslala.
//
// $zakljucaj zaključava postojeće događaje te lige (SELECT ... FOR UPDATE),
// pa dva istovremena uvoza iste lige ne mogu oba proći proveru duplikata.
function pripremi_pregled_uvoza(PDO $pdo, array $utakmice, array $liga, bool $zakljucaj = false): array
{
    $liga_id = (int) $liga['id'];
    $timovi = timovi_lige($pdo, $liga_id);

    // Postojeći događaji ove lige - osnov za prepoznavanje duplikata
    $sql = 'SELECT domacin_id, gost_id, vreme_pocetka FROM dogadjaj WHERE liga_id = ?';
    if ($zakljucaj) {
        $sql .= ' FOR UPDATE';
    }

    $upit = $pdo->prepare($sql);
    $upit->execute([$liga_id]);

    // Duplikat je, po dogovoru, ista četvorka (liga, domaćin, gost, vreme).
    // Uz to se pamti i par po danu, da bi se javio slučaj kad servis pomeri
    // satnicu iste utakmice - to nije duplikat po pravilu, ali jeste vredno
    // upozorenja.
    $postojeci = [];
    $postojeci_dan = [];

    foreach ($upit->fetchAll() as $dogadjaj) {
        $par = (int) $dogadjaj['domacin_id'] . '|' . (int) $dogadjaj['gost_id'];

        $postojeci[$par . '|' . $dogadjaj['vreme_pocetka']] = true;
        $postojeci_dan[$par . '|' . substr($dogadjaj['vreme_pocetka'], 0, 10)] = formatiraj_datum_vreme($dogadjaj['vreme_pocetka']);
    }

    $sada = date('Y-m-d H:i:s');
    $redovi = [];
    $nepoznati_timovi = [];

    foreach ($utakmice as $utakmica) {
        if (!is_array($utakmica)) {
            continue;
        }

        $api_id      = trim((string) ($utakmica['id'] ?? ''));
        $api_domacin = trim((string) ($utakmica['home_team'] ?? ''));
        $api_gost    = trim((string) ($utakmica['away_team'] ?? ''));
        $vreme       = vreme_iz_apija($utakmica['commence_time'] ?? null);

        $red = [
            'api_id'        => $api_id,
            'api_domacin'   => $api_domacin,
            'api_gost'      => $api_gost,
            'vreme'         => $vreme,
            'domacin_id'    => null,
            'gost_id'       => null,
            'domacin_naziv' => null,
            'gost_naziv'    => null,
            'kvote'         => [],
            'status'        => 'neispravan',
            'napomena'      => '',
            'moze'          => false,
            'cekirano'      => false,
        ];

        if ($api_id === '' || $api_domacin === '' || $api_gost === '' || $vreme === null) {
            $red['napomena'] = 'Zapis sa servisa je nepotpun i preskače se.';
            $redovi[] = $red;
            continue;
        }

        $tim_domacin = pronadji_tim_po_api_nazivu($timovi, $api_domacin);
        $tim_gost    = pronadji_tim_po_api_nazivu($timovi, $api_gost);

        if ($tim_domacin !== null) {
            $red['domacin_id'] = (int) $tim_domacin['id'];
            $red['domacin_naziv'] = $tim_domacin['naziv'];
        }
        if ($tim_gost !== null) {
            $red['gost_id'] = (int) $tim_gost['id'];
            $red['gost_naziv'] = $tim_gost['naziv'];
        }

        $red['kvote'] = izvuci_kvote_utakmice($utakmica);

        if ($vreme <= $sada) {
            // Poslovno pravilo 6: kladi se samo na događaj koji nije počeo.
            // Utakmica iz zastarelog keša tako ne uđe u ponudu kao mrtav red.
            $red['status'] = 'proslo';
            $red['napomena'] = 'Vreme početka je već prošlo.';
        } elseif ($tim_domacin === null || $tim_gost === null) {
            $red['status'] = 'nepoznat_tim';
            $red['napomena'] = 'Tim još nije povezan sa timom iz baze.';

            // Broji se u koliko utakmica se nepoznat tim javlja, da bi
            // operater video koliko dobija jednim povezivanjem
            if ($tim_domacin === null) {
                $nepoznati_timovi[$api_domacin] = ($nepoznati_timovi[$api_domacin] ?? 0) + 1;
            }
            if ($tim_gost === null) {
                $nepoznati_timovi[$api_gost] = ($nepoznati_timovi[$api_gost] ?? 0) + 1;
            }
        } elseif (empty($red['kvote'])) {
            $red['status'] = 'bez_kvota';
            $red['napomena'] = 'Servis za ovu utakmicu nije dao nijednu upotrebljivu kvotu.';
        } else {
            $par = $red['domacin_id'] . '|' . $red['gost_id'];

            if (isset($postojeci[$par . '|' . $vreme])) {
                $red['status'] = 'duplikat';
                $red['napomena'] = 'Isti događaj već postoji u bazi.';
            } elseif (isset($postojeci_dan[$par . '|' . substr($vreme, 0, 10)])) {
                // Ne odbija se, ali se ne čekira samo od sebe - odluku donosi
                // operater, jer servis ume da pomeri satnicu već unetog meča
                $red['status'] = 'moguc_duplikat';
                $red['napomena'] = 'Isti timovi već imaju događaj tog dana ('
                    . $postojeci_dan[$par . '|' . substr($vreme, 0, 10)] . '), ali u drugo vreme.';
                $red['moze'] = true;
            } else {
                $red['status'] = 'spreman';
                $red['moze'] = true;
                $red['cekirano'] = true;
            }
        }

        $redovi[] = $red;
    }

    // Servis vraća utakmice hronološki, ali se na to ne oslanjamo
    usort($redovi, function ($a, $b) {
        return strcmp((string) $a['vreme'], (string) $b['vreme']);
    });

    ksort($nepoznati_timovi);

    return [
        'redovi'           => $redovi,
        'nepoznati_timovi' => $nepoznati_timovi,
    ];
}

// Kratak opis statusa reda pregleda, za natpis na oznaci u tabeli
function opis_statusa_uvoza(string $status): string
{
    return match ($status) {
        'spreman'        => 'spreman',
        'duplikat'       => 'već postoji',
        'moguc_duplikat' => 'moguć duplikat',
        'nepoznat_tim'   => 'nepoznat tim',
        'bez_kvota'      => 'bez kvota',
        'proslo'         => 'prošlo vreme',
        default          => 'nečitljiv zapis',
    };
}


// ---------------------------------------------------------------------
// UPIS U BAZU
// ---------------------------------------------------------------------

// Upisuje izabrane utakmice i njihove kvote u jednoj transakciji baze.
//
// $utakmice je sirov odgovor servisa PROČITAN SA DISKA (iz keša), a ne iz
// forme; iz forme stižu samo identifikatori utakmica koje je operater
// čekirao. Sve ostalo - vreme, timovi, vrednosti kvota - server ponovo
// izračuna sam.
//
// Duplikat se preskače uz obaveštenje, umesto da obori ceo upis. Svaka druga
// greška poništava sve: ili uđu sve izabrane utakmice, ili nijedna.
function upisi_uvezene_utakmice(PDO $pdo, array $liga, array $utakmice, array $izabrani_api_id, int $operater_id): array
{
    $ishod = [
        'uspeh'      => false,
        'uneto'      => 0,
        'kvota'      => 0,
        'preskoceno' => 0,
        'odbijeno'   => 0,
        'poruke'     => [],
    ];

    if (empty($izabrani_api_id)) {
        $ishod['poruke'][] = 'Nijedna utakmica nije izabrana za upis.';
        return $ishod;
    }

    try {
        $pdo->beginTransaction();

        // Tipovi igre ovog sporta, po oznaci. Sport koji nema npr. oznaku "X"
        // (košarka) jednostavno neće dobiti tu kvotu - mapiranje je time
        // generičko po sportu, bez pretpostavke da je reč o fudbalu.
        $upitTipovi = $pdo->prepare('SELECT id, oznaka FROM tip_igre WHERE sport_id = ?');
        $upitTipovi->execute([(int) $liga['sport_id']]);

        // PAŽNJA: PHP numeričke stringove pretvara u cele brojeve kad su ključ
        // niza, pa oznaka '1' ovde postaje ključ 1. Isto važi i za ključeve
        // koje vraća izvuci_kvote_utakmice(), pa se dve strane poklapaju - ali
        // se na to ne oslanjamo prećutno: obe se izričito svode na string.
        $tipovi_po_oznaci = [];
        foreach ($upitTipovi->fetchAll() as $tip) {
            $tipovi_po_oznaci[(string) $tip['oznaka']] = (int) $tip['id'];
        }

        // Pregled se pravi iznova, unutar transakcije i sa zaključavanjem
        $pregled = pripremi_pregled_uvoza($pdo, $utakmice, $liga, true);

        $po_api_id = [];
        foreach ($pregled['redovi'] as $red) {
            if ($red['api_id'] !== '') {
                $po_api_id[$red['api_id']] = $red;
            }
        }

        $umetniDogadjaj = $pdo->prepare(
            'INSERT INTO dogadjaj (liga_id, domacin_id, gost_id, vreme_pocetka, status, kreirao_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $umetniKvotu = $pdo->prepare(
            'INSERT INTO kvota (dogadjaj_id, tip_igre_id, vrednost, aktivna) VALUES (?, ?, ?, 1)'
        );

        foreach ($izabrani_api_id as $api_id) {
            $red = $po_api_id[(string) $api_id] ?? null;

            // Poslat identifikator koji uopšte nije u keširanom odgovoru -
            // forma ga nije ni mogla ponuditi, pa se tiho preskače
            if ($red === null) {
                $ishod['odbijeno']++;
                continue;
            }

            $par_za_poruku = $red['api_domacin'] . ' — ' . $red['api_gost'];

            if ($red['status'] === 'duplikat') {
                $ishod['preskoceno']++;
                $ishod['poruke'][] = 'Preskočeno (već postoji): ' . $par_za_poruku;
                continue;
            }

            if (!$red['moze']) {
                $ishod['odbijeno']++;
                $ishod['poruke'][] = 'Nije uneto (' . opis_statusa_uvoza($red['status']) . '): ' . $par_za_poruku;
                continue;
            }

            $umetniDogadjaj->execute([
                (int) $liga['id'],
                $red['domacin_id'],
                $red['gost_id'],
                $red['vreme'],
                'najavljen',
                $operater_id,
            ]);

            $dogadjaj_id = (int) $pdo->lastInsertId();
            $ishod['uneto']++;

            foreach ($red['kvote'] as $oznaka => $vrednost) {
                $oznaka = (string) $oznaka;

                // Sport koji nema ovaj tip igre u šifarniku (košarka nema "X")
                // jednostavno ne dobija tu kvotu
                if (!isset($tipovi_po_oznaci[$oznaka])) {
                    continue;
                }

                $umetniKvotu->execute([$dogadjaj_id, $tipovi_po_oznaci[$oznaka], za_bazu($vrednost)]);
                $ishod['kvota']++;
            }
        }

        $pdo->commit();
        $ishod['uspeh'] = true;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        upisi_gresku('Greška pri uvozu utakmica sa API-ja: ' . $e->getMessage());

        $ishod['uneto'] = 0;
        $ishod['kvota'] = 0;
        $ishod['poruke'][] = 'Upis nije uspeo - nijedna utakmica nije sačuvana. Pokušajte ponovo ili unesite ponudu ručno.';
    }

    return $ishod;
}

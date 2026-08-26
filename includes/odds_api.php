<?php
// Komunikacija sa The Odds API-jem (the-odds-api.com) i keširanje odgovora.
//
// Ceo fajl je POMOĆNI alat: služi samo stranici operater/uvoz.php i CRUD-u
// nad tabelom api_liga. Nijedna druga stranica ga ne uključuje, pa aplikacija
// radi u potpunosti i bez interneta, bez ključa i bez ovog fajla.
//
// Pravilo koje se ovde nigde ne krši: NIJEDNA funkcija ne baca izuzetak i
// nijedna ne ispisuje ništa. Svaka vraća niz sa poljem 'uspeh' i porukom na
// srpskom koju stranica sme da prikaže; tehnički detalj (kod greške, odgovor
// servisa) ide isključivo u logovi/greske.log.

require_once __DIR__ . '/../config/konfiguracija.php';
require_once __DIR__ . '/log.php';

// Ključ i podešavanja stoje u config/api.php, koji je u .gitignore. Fajl ne
// mora postojati - tada uvoz javlja da nije podešen, a ostatak aplikacije
// radi neizmenjeno.
if (is_file(__DIR__ . '/../config/api.php')) {
    require_once __DIR__ . '/../config/api.php';
}

// Popuna za slučaj da config/api.php ne postoji ili je zaostao iza
// config/api.primer.php pa mu neka konstanta nedostaje. Jedino ključ nema
// upotrebljivu podrazumevanu vrednost - bez njega se poziv i ne pokušava.
defined('ODDS_API_KLJUC')                  || define('ODDS_API_KLJUC', '');
defined('ODDS_API_OSNOVNI_URL')            || define('ODDS_API_OSNOVNI_URL', 'https://api.the-odds-api.com/v4');
defined('ODDS_API_REGIONI')                || define('ODDS_API_REGIONI', 'eu');
defined('ODDS_API_TRZISTA')                || define('ODDS_API_TRZISTA', 'h2h,totals');
defined('ODDS_API_LINIJA_TOTALS')          || define('ODDS_API_LINIJA_TOTALS', 2.5);
defined('ODDS_API_TIMEOUT')                || define('ODDS_API_TIMEOUT', 8);
defined('PUTANJA_KES')                     || define('PUTANJA_KES', KORENSKA_PUTANJA . '/kes/');
defined('ODDS_API_TRAJANJE_KESA')          || define('ODDS_API_TRAJANJE_KESA', 30 * 60);
defined('ODDS_API_TRAJANJE_KESA_SPORTOVI') || define('ODDS_API_TRAJANJE_KESA_SPORTOVI', 24 * 60 * 60);

// Oznake tipova igre koje se mogu izvesti iz onoga što API daje u besplatnom
// nivou. GG i dupla šansa (1X, 12, X2) nisu među njima - njih operater dodaje
// ručno, dugmetom "Dodaj sve tipove" na stranici sa kvotama.
define('ODDS_API_OZNAKE', ['1', 'X', '2', '0-2', '3+']);


// ---------------------------------------------------------------------
// PODEŠENOST
// ---------------------------------------------------------------------

// Da li je uvoz uopšte podešen (postoji ključ). Stranica ovo pita pre nego
// što ponudi dugme "Preuzmi", da ne bi slala poziv za koji se unapred zna
// da će pasti.
function odds_api_podesen(): bool
{
    return trim(ODDS_API_KLJUC) !== '';
}


// ---------------------------------------------------------------------
// KEŠ NA DISKU
// ---------------------------------------------------------------------

// Puna putanja do fajla keša za dati naziv. Naziv se sastavlja od ključa lige
// koji stiže iz URL-a, pa se propušta samo [a-z0-9_] - time je nemoguće da
// se kroz njega izađe iz foldera kes/ ("../" ne preživi ovo čišćenje).
function putanja_kesa(string $naziv): string
{
    $bezbedan_naziv = preg_replace('/[^a-z0-9_]/', '', strtolower($naziv));

    return PUTANJA_KES . $bezbedan_naziv . '.json';
}

// Čita keširani odgovor sa diska. Vraća ceo omot (vreme snimanja, stanje
// kredita, podaci) ili null ako fajla nema ili je neupotrebljiv. Starost se
// ovde NE proverava - o njoj odlučuje pozivalac, jer se zastareo keš i dalje
// koristi kao rezerva kad API ne odgovori.
function procitaj_kes(string $naziv): ?array
{
    $putanja = putanja_kesa($naziv);

    if (!is_file($putanja)) {
        return null;
    }

    $sadrzaj = @file_get_contents($putanja);

    if ($sadrzaj === false) {
        upisi_gresku('The Odds API: keš fajl se ne može pročitati: ' . $putanja);
        return null;
    }

    $omot = json_decode($sadrzaj, true);

    // Oštećen ili ručno izmenjen fajl se ponaša isto kao da ga nema
    if (!is_array($omot) || !isset($omot['vreme'], $omot['podaci']) || !is_array($omot['podaci'])) {
        upisi_gresku('The Odds API: keš fajl je oštećen: ' . $putanja);
        return null;
    }

    // Stanje kredita se dopunjuje ako ga u fajlu nema (star ili ručno napravljen
    // keš). Tako pozivalac uvek sme da pročita ova polja, umesto da svako mesto
    // prikaza posebno pazi da li postoje.
    $omot['preostalo'] = $omot['preostalo'] ?? null;
    $omot['iskorisceno'] = $omot['iskorisceno'] ?? null;

    return $omot;
}

// Snima odgovor u kes/ zajedno sa vremenskom oznakom i stanjem kredita.
// Neuspeh upisa ne obara preuzimanje - podaci su već u ruci, samo se sledeći
// put neće moći ponovo iskoristiti, pa se greška samo beleži.
function upisi_kes(string $naziv, array $omot): void
{
    if (!is_dir(PUTANJA_KES)) {
        if (!@mkdir(PUTANJA_KES, 0775, true) && !is_dir(PUTANJA_KES)) {
            upisi_gresku('The Odds API: folder za keš se ne može napraviti: ' . PUTANJA_KES);
            return;
        }

        // Folder u projektu već ima svoj .htaccess, ali ako je obrisan pa ga
        // ovaj poziv napravi iznova, zabrana pristupa mora otići sa njim -
        // inače bi keširani odgovori odjednom bili dostupni kroz pregledač
        @file_put_contents(PUTANJA_KES . '.htaccess', "Require all denied\n");
    }

    $json = json_encode($omot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        upisi_gresku('The Odds API: odgovor se ne može pretvoriti u JSON za keširanje.');
        return;
    }

    // LOCK_EX da dva istovremena preuzimanja ne izmešaju sadržaj fajla
    if (@file_put_contents(putanja_kesa($naziv), $json, LOCK_EX) === false) {
        upisi_gresku('The Odds API: keš fajl se ne može upisati: ' . putanja_kesa($naziv));
    }
}


// ---------------------------------------------------------------------
// POZIV API-JA
// ---------------------------------------------------------------------

// Jedan HTTP poziv ka API-ju preko cURL-a. Vraća:
//   uspeh, podaci (dekodiran JSON), poruka (za korisnika),
//   preostalo / iskorisceno (broj kredita iz zaglavlja odgovora).
// Nikad ne baca izuzetak i nikad ne prikazuje sirov odgovor servisa.
function odds_api_zahtev(string $putanja, array $parametri): array
{
    $rezultat = [
        'uspeh'       => false,
        'podaci'      => [],
        'poruka'      => '',
        'preostalo'   => null,
        'iskorisceno' => null,
    ];

    if (!function_exists('curl_init')) {
        upisi_gresku('The Odds API: cURL ekstenzija nije dostupna na serveru.');
        $rezultat['poruka'] = 'Na serveru nije uključena cURL ekstenzija, pa preuzimanje ponude nije moguće. Ponudu unesite ručno.';
        return $rezultat;
    }

    if (!odds_api_podesen()) {
        $rezultat['poruka'] = 'Ključ za The Odds API nije podešen, pa preuzimanje nije moguće. Ponudu unesite ručno.';
        return $rezultat;
    }

    $parametri['apiKey'] = ODDS_API_KLJUC;
    $url = rtrim(ODDS_API_OSNOVNI_URL, '/') . $putanja . '?' . http_build_query($parametri);

    // Zaglavlja odgovora se skupljaju usput; iz njih se čita koliko je kredita
    // ostalo (x-requests-remaining), što je jedini način da operater to sazna.
    $zaglavlja = [];

    $veza = curl_init();
    curl_setopt_array($veza, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        // Ukupno čekanje ograničeno na ODDS_API_TIMEOUT (8s po dogovoru) -
        // stranica ne sme da visi ako servis ne odgovara
        CURLOPT_TIMEOUT        => ODDS_API_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => min(5, ODDS_API_TIMEOUT),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'Kladionica (fakultetski projekat)',
        CURLOPT_HEADERFUNCTION => function ($veza, string $red) use (&$zaglavlja) {
            $delovi = explode(':', $red, 2);

            if (count($delovi) === 2) {
                $zaglavlja[strtolower(trim($delovi[0]))] = trim($delovi[1]);
            }

            // cURL zahteva da povratna vrednost bude broj pročitanih bajtova
            return strlen($red);
        },
    ]);

    $telo = curl_exec($veza);
    $http_kod = (int) curl_getinfo($veza, CURLINFO_RESPONSE_CODE);
    $greska_broj = curl_errno($veza);
    $greska_tekst = curl_error($veza);
    curl_close($veza);

    // Stanje kredita stiže i uz neuspešan odgovor, pa se čita pre provera
    if (isset($zaglavlja['x-requests-remaining']) && is_numeric($zaglavlja['x-requests-remaining'])) {
        $rezultat['preostalo'] = (int) $zaglavlja['x-requests-remaining'];
    }
    if (isset($zaglavlja['x-requests-used']) && is_numeric($zaglavlja['x-requests-used'])) {
        $rezultat['iskorisceno'] = (int) $zaglavlja['x-requests-used'];
    }

    // U log nikad ne ide pun URL, jer u njemu stoji ključ
    $za_log = 'The Odds API [' . $putanja . ']: ';

    if ($telo === false) {
        upisi_gresku($za_log . 'cURL greška ' . $greska_broj . ' - ' . $greska_tekst);

        $rezultat['poruka'] = match ($greska_broj) {
            CURLE_OPERATION_TIMEDOUT   => 'Servis sa kvotama nije odgovorio u roku od ' . ODDS_API_TIMEOUT . ' sekundi.',
            CURLE_COULDNT_RESOLVE_HOST => 'Servis sa kvotama nije dostupan - izgleda da nema internet veze.',
            CURLE_COULDNT_CONNECT      => 'Nije moguće povezati se sa servisom za kvote.',
            default                    => 'Preuzimanje sa servisa za kvote nije uspelo.',
        };
        $rezultat['poruka'] .= ' Ponudu možete uneti ručno.';

        return $rezultat;
    }

    if ($http_kod !== 200) {
        upisi_gresku($za_log . 'HTTP ' . $http_kod . ' - ' . mb_substr((string) $telo, 0, 300));

        $rezultat['poruka'] = match ($http_kod) {
            401     => 'Servis je odbio ključ. Proverite ODDS_API_KLJUC u config/api.php.',
            422     => 'Servis je odbio zahtev kao neispravan (verovatno pogrešan ključ lige).',
            429     => 'Potrošena je mesečna kvota poziva ka servisu.',
            default => 'Servis sa kvotama je vratio grešku (HTTP ' . $http_kod . ').',
        };
        $rezultat['poruka'] .= ' Ponudu možete uneti ručno.';

        return $rezultat;
    }

    $podaci = json_decode((string) $telo, true);

    if (!is_array($podaci)) {
        upisi_gresku($za_log . 'odgovor nije ispravan JSON: ' . mb_substr((string) $telo, 0, 300));
        $rezultat['poruka'] = 'Servis je vratio odgovor koji se ne može pročitati. Ponudu možete uneti ručno.';
        return $rezultat;
    }

    $rezultat['uspeh'] = true;
    $rezultat['podaci'] = $podaci;

    return $rezultat;
}

// Poziv sa keširanjem - jedina funkcija koju stranice zaista koriste.
// Redosled odlučivanja:
//   1. keš mlađi od $trajanje  -> koristi se fajl, poziv se uopšte ne šalje
//   2. inače                   -> šalje se poziv i odgovor se snima u keš
//   3. ako poziv padne, a keš postoji (makar zastareo) -> koristi se keš uz
//      upozorenje, jer je stara ponuda korisnija od prazne stranice
function odds_api_uzmi(string $naziv_kesa, string $putanja, array $parametri, int $trajanje, bool $osvezi = false): array
{
    $rezultat = [
        'uspeh'       => false,
        'podaci'      => [],
        'poruka'      => '',
        'iz_kesa'     => false,
        'zastareo'    => false,
        'vreme_kesa'  => null,
        'preostalo'   => null,
        'iskorisceno' => null,
    ];

    $kes = procitaj_kes($naziv_kesa);

    // Mesečna kvota je 500 poziva, pa svež keš uvek ima prednost. Dugme
    // "Osveži sa servera" postavlja $osvezi i preskače ovu granu.
    if (!$osvezi && $kes !== null && (time() - (int) $kes['vreme']) < $trajanje) {
        $rezultat['uspeh']       = true;
        $rezultat['podaci']      = $kes['podaci'];
        $rezultat['iz_kesa']     = true;
        $rezultat['vreme_kesa']  = (int) $kes['vreme'];
        $rezultat['preostalo']   = $kes['preostalo'] ?? null;
        $rezultat['iskorisceno'] = $kes['iskorisceno'] ?? null;

        return $rezultat;
    }

    $api = odds_api_zahtev($putanja, $parametri);

    if ($api['uspeh']) {
        $omot = [
            'vreme'       => time(),
            'putanja'     => $putanja,
            'preostalo'   => $api['preostalo'],
            'iskorisceno' => $api['iskorisceno'],
            'podaci'      => $api['podaci'],
        ];
        upisi_kes($naziv_kesa, $omot);

        $rezultat['uspeh']       = true;
        $rezultat['podaci']      = $api['podaci'];
        $rezultat['vreme_kesa']  = $omot['vreme'];
        $rezultat['preostalo']   = $api['preostalo'];
        $rezultat['iskorisceno'] = $api['iskorisceno'];

        return $rezultat;
    }

    // Poziv nije uspeo. Ako na disku postoji ma i star odgovor, bolje je
    // prikazati njega uz jasno upozorenje nego ostaviti operatera bez ičega.
    if ($kes !== null) {
        $rezultat['uspeh']       = true;
        $rezultat['podaci']      = $kes['podaci'];
        $rezultat['iz_kesa']     = true;
        $rezultat['zastareo']    = true;
        $rezultat['vreme_kesa']  = (int) $kes['vreme'];
        $rezultat['preostalo']   = $kes['preostalo'] ?? null;
        $rezultat['iskorisceno'] = $kes['iskorisceno'] ?? null;
        $rezultat['poruka']      = $api['poruka'] . ' Prikazan je poslednji sačuvani odgovor, koji može biti zastareo.';

        return $rezultat;
    }

    $rezultat['poruka'] = $api['poruka'];

    return $rezultat;
}

// Spisak liga koje servis nudi (ključ tipa soccer_epl + čitljiv naziv).
// Ovaj endpoint se kod The Odds API-ja ne naplaćuje, ali se spisak menja
// retko, pa se svejedno kešira na 24 sata.
function odds_api_sportovi(bool $osvezi = false): array
{
    return odds_api_uzmi(
        'sportovi',
        '/sports',
        [],
        ODDS_API_TRAJANJE_KESA_SPORTOVI,
        $osvezi
    );
}

// Utakmice i kvote za jednu ligu. Ovo je jedini poziv koji troši kredite:
// cena je (broj regiona x broj tržišta), dakle 1 x 2 = 2 kredita.
function odds_api_kvote(string $sport_kljuc, bool $osvezi = false): array
{
    return odds_api_uzmi(
        'kvote_' . $sport_kljuc,
        '/sports/' . rawurlencode($sport_kljuc) . '/odds',
        [
            'regions'    => ODDS_API_REGIONI,
            'markets'    => ODDS_API_TRZISTA,
            'oddsFormat' => 'decimal',
            'dateFormat' => 'iso',
        ],
        ODDS_API_TRAJANJE_KESA,
        $osvezi
    );
}

// Spisak liga iz keša, bez ijednog poziva ka servisu. Stranice ga koriste pri
// običnom otvaranju: spisak je udobnost (padajuća pomoć pri unosu ključa), pa
// ne sme da natera stranicu da čeka mrežu. Poziv se šalje samo kad operater
// izričito pritisne dugme za preuzimanje spiska.
function odds_api_sportovi_iz_kesa(): ?array
{
    $kes = procitaj_kes('sportovi');

    return $kes === null ? null : $kes['podaci'];
}

// Čita SAMO keš, bez ijednog poziva ka servisu. Koristi se u drugom koraku
// uvoza (upis u bazu): operater je već video pregled, pa se isti odgovor
// ponovo čita sa diska umesto da se veruje vrednostima poslatim iz forme -
// i bez trošenja još jednog kredita. Starost se namerno ne gleda, jer je
// ovo isti onaj odgovor koji je operater upravo pregledao.
function odds_api_kvote_iz_kesa(string $sport_kljuc): ?array
{
    $kes = procitaj_kes('kvote_' . $sport_kljuc);

    return $kes === null ? null : $kes['podaci'];
}


// ---------------------------------------------------------------------
// PRETVARANJE ODGOVORA U OBLIK NAŠE ŠEME
// ---------------------------------------------------------------------

// Vreme početka iz API-ja (ISO 8601 u UTC, npr. 2026-09-01T18:30:00Z) u
// oblik za DATETIME kolonu, prebačeno u lokalnu zonu - cela aplikacija
// računa po Europe/Belgrade (vidi config/konfiguracija.php).
function vreme_iz_apija(?string $iso): ?string
{
    if (empty($iso)) {
        return null;
    }

    try {
        // Druga zona je samo rezerva: ako oznaka zone nedostaje, tumači se kao UTC
        $vreme = new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        upisi_gresku('The Odds API: nečitljivo vreme početka "' . $iso . '"');
        return null;
    }

    return $vreme->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
}

// Prosečna kvota jedne oznake preko svih kladionica koje su je ponudile,
// zaokružena na dve decimale. Prosek se uzima namerno: pojedinačna kladionica
// može imati ispad, a ovo je ionako samo predlog koji operater posle sme da
// izmeni kroz operater/kvote.php.
function prosecna_kvota(array $cene): ?float
{
    if (empty($cene)) {
        return null;
    }

    $prosek = round(array_sum($cene) / count($cene), 2);

    // 1.01 je donja granica i u bazi (CHECK chk_kvota_vrednost). Ovako nizak
    // prosek se u praksi ne javlja, ali upis koji bi pao na ograničenju baze
    // srušio bi celu transakciju uvoza.
    return $prosek >= 1.01 ? $prosek : null;
}

// Iz jedne utakmice iz odgovora izvlači kvote po oznakama naših tipova igre.
// Mapiranje:
//   h2h    -> ime domaćina = "1", "Draw" = "X", ime gosta = "2"
//   totals -> na liniji 2.5: "Under" = "0-2", "Over" = "3+"
// Ostale linije totals-a (1.5, 3.5...) se preskaču, jer im u šifarniku
// tip_igre nema para.
function izvuci_kvote_utakmice(array $utakmica): array
{
    $domacin = (string) ($utakmica['home_team'] ?? '');
    $gost    = (string) ($utakmica['away_team'] ?? '');

    // oznaka => niz cena, po jedna od svake kladionice
    $cene = [];

    foreach ($utakmica['bookmakers'] ?? [] as $kladionica) {
        foreach ($kladionica['markets'] ?? [] as $trziste) {
            $kljuc_trzista = (string) ($trziste['key'] ?? '');

            foreach ($trziste['outcomes'] ?? [] as $ishod) {
                $cena = isset($ishod['price']) ? (float) $ishod['price'] : 0.0;

                if ($cena <= 0) {
                    continue;
                }

                $ime = (string) ($ishod['name'] ?? '');
                $oznaka = null;

                if ($kljuc_trzista === 'h2h') {
                    if ($ime === $domacin) {
                        $oznaka = '1';
                    } elseif ($ime === $gost) {
                        $oznaka = '2';
                    } elseif (strcasecmp($ime, 'Draw') === 0) {
                        $oznaka = 'X';
                    }
                } elseif ($kljuc_trzista === 'totals') {
                    $linija = isset($ishod['point']) ? (float) $ishod['point'] : null;

                    // Poređenje decimalnih brojeva ide preko razlike, ne preko ==
                    if ($linija !== null && abs($linija - ODDS_API_LINIJA_TOTALS) < 0.001) {
                        if (strcasecmp($ime, 'Under') === 0) {
                            $oznaka = '0-2';
                        } elseif (strcasecmp($ime, 'Over') === 0) {
                            $oznaka = '3+';
                        }
                    }
                }

                if ($oznaka !== null) {
                    $cene[$oznaka][] = $cena;
                }
            }
        }
    }

    $kvote = [];

    // Redosled je iz ODDS_API_OZNAKE, da tabela pregleda uvek ima iste kolone
    foreach (ODDS_API_OZNAKE as $oznaka) {
        $prosek = prosecna_kvota($cene[$oznaka] ?? []);

        if ($prosek !== null) {
            $kvote[$oznaka] = $prosek;
        }
    }

    return $kvote;
}

// Naziv tima sveden na oblik pogodan za poređenje: bez velikih slova, bez
// naših kvačica i bez znakova interpunkcije. Time se "F.C. Köln" i "FC Koln"
// prepoznaju kao isti tim. Sve dalje od toga (npr. "Mančester Junajted" prema
// "Manchester United") operater povezuje sam, kroz kolonu tim.api_naziv.
function normalizuj_naziv_tima(string $naziv): string
{
    $naziv = mb_strtolower(trim($naziv), 'UTF-8');

    $zamene = [
        'č' => 'c', 'ć' => 'c', 'ž' => 'z', 'š' => 's', 'đ' => 'dj',
        'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', 'é' => 'e',
        'è' => 'e', 'á' => 'a', 'à' => 'a', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
    ];
    $naziv = strtr($naziv, $zamene);

    // Ostaju samo slova i cifre - razmaci, tačke i crtice ne razlikuju timove
    return preg_replace('/[^a-z0-9]/', '', $naziv) ?? '';
}

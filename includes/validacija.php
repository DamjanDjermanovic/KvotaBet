<?php
// Funkcije za proveru ispravnosti korisničkog unosa na serveru. Ovo je
// jedina provera koja se računa - klijentska (JS) provera postoji samo
// radi udobnosti i nikad se ne sme smatrati dovoljnom.

require_once __DIR__ . '/../config/konfiguracija.php';

// Proverava ime ili prezime: samo slova (uključujući ćirilicu/dijakritike),
// razmak i crtica, dužine 2-50 znakova
function validno_ime(?string $vrednost): bool
{
    $vrednost = trim($vrednost ?? '');

    return mb_strlen($vrednost) >= 2
        && mb_strlen($vrednost) <= 50
        && preg_match('/^[\p{L} \-]+$/u', $vrednost) === 1;
}

// Da li uloga mora imati upisano ime i prezime.
//
// Ime i prezime igrača su deo njegovog naloga za klađenje - vezuju se za
// verifikacioni dokument i moraju se poklopiti sa njim, pa se traže. Operater
// i administrator su radni nalozi; njih se u sistemu prepoznaje po korisničkom
// imenu, pa im lično ime nije obavezno.
function ime_obavezno_za_ulogu(?string $uloga): bool
{
    return $uloga === 'igrac';
}

// Ista provera kao validno_ime, ali prihvata i prazno polje. Koristi se za
// uloge kojima ime nije obavezno: ako je nešto uneto, mora biti ispravno.
function validno_ime_ili_prazno(?string $vrednost): bool
{
    return trim($vrednost ?? '') === '' || validno_ime($vrednost);
}

// Objedinjuje dve prethodne provere u jednu, da mesta koja proveravaju ime ne
// bi svaki put ponavljala isti uslov: kad je ime obavezno mora biti ispravno,
// a kad nije, sme ostati i prazno.
function ime_zadovoljava(?string $vrednost, bool $obavezno): bool
{
    return $obavezno ? validno_ime($vrednost) : validno_ime_ili_prazno($vrednost);
}

// Proverava korisničko ime: slova, cifre i donja crta, dužine 3-30 znakova
function validno_korisnicko_ime(?string $vrednost): bool
{
    $vrednost = trim($vrednost ?? '');

    return preg_match('/^[A-Za-z0-9_]{3,30}$/', $vrednost) === 1;
}

// Proverava format email adrese. filter_var je polazna tačka, ali nije dovoljan:
// propušta adresu sa navodnicima u lokalnom delu ("a..b"@example.com), ne zna
// ništa o širini kolone u bazi, a njegovo ponašanje se menjalo kroz verzije
// PHP-a. Zato se dopunske provere pišu ovde, izričito, da ne zavise od toga
// koja verzija PHP-a stoji ispod.
//
// Namerno bez sopstvenog regularnog izraza za celu adresu: takvi izrazi po
// pravilu odbiju i sasvim ispravne adrese (znak +, poddomeni, nove vrhunske
// domene), a to je gora greška od propuštanja neke retke neispravne.
function validan_email(?string $vrednost): bool
{
    $vrednost = trim($vrednost ?? '');

    if ($vrednost === '' || filter_var($vrednost, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }

    // Dužina: 120 je širina kolone email u tabeli korisnik, pa duža adresa ne
    // bi ni mogla da se upiše cela. Lokalni deo (pre @) po standardu ne sme
    // preko 64 znaka.
    $mesto_majmuna = strrpos($vrednost, '@');
    $lokalni_deo = substr($vrednost, 0, $mesto_majmuna);
    $domen = substr($vrednost, $mesto_majmuna + 1);

    if (mb_strlen($vrednost) > 120 || strlen($lokalni_deo) > 64) {
        return false;
    }

    // Dve tačke jedna do druge nisu dozvoljene ni u jednom delu adrese
    if (str_contains($vrednost, '..')) {
        return false;
    }

    // Domen mora imati bar jednu tačku: "pera@localhost" je tehnički ispravna
    // adresa, ali ne i adresa na koju se u ovoj aplikaciji može javiti korisnik
    if (!str_contains($domen, '.')) {
        return false;
    }

    return true;
}

// Zahteva lozinku od bar 6 znakova, sa bar jednim slovom i jednom cifrom -
// dovoljno strogo za potrebe simulacije, bez preterivanja sa pravilima
function validna_lozinka(?string $vrednost): bool
{
    $vrednost = $vrednost ?? '';

    return mb_strlen($vrednost) >= 6
        && preg_match('/[A-Za-z]/', $vrednost) === 1
        && preg_match('/[0-9]/', $vrednost) === 1;
}

// Proverava da je vrednost ispravan datum u formatu GGGG-MM-DD i da je u prošlosti
function validan_datum_rodjenja(?string $vrednost): bool
{
    if (empty($vrednost)) {
        return false;
    }

    $datum = DateTime::createFromFormat('Y-m-d', $vrednost);

    return $datum !== false
        && $datum->format('Y-m-d') === $vrednost
        && $datum < new DateTime();
}

// Poslovno pravilo: registracija je dozvoljena samo korisnicima koji imaju
// bar MIN_GODINA_ZA_REGISTRACIJU godina na dan registracije
function korisnik_je_punoletan(string $datum_rodjenja): bool
{
    $rodjen = new DateTime($datum_rodjenja);
    $godine = $rodjen->diff(new DateTime())->y;

    return $godine >= MIN_GODINA_ZA_REGISTRACIJU;
}

// Telefon je opciono polje - prazna vrednost je ispravna, a ako je uneta
// mora sadržati samo cifre, razmake, plus i crtu
function validan_telefon(?string $vrednost): bool
{
    $vrednost = trim($vrednost ?? '');

    if ($vrednost === '') {
        return true;
    }

    return preg_match('/^[0-9+\- ]{6,20}$/', $vrednost) === 1;
}

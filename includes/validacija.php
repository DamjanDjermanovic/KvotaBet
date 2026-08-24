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

// Proverava korisničko ime: slova, cifre i donja crta, dužine 3-30 znakova
function validno_korisnicko_ime(?string $vrednost): bool
{
    $vrednost = trim($vrednost ?? '');

    return preg_match('/^[A-Za-z0-9_]{3,30}$/', $vrednost) === 1;
}

// Proverava format email adrese preko ugrađenog PHP filtera
function validan_email(?string $vrednost): bool
{
    $vrednost = trim($vrednost ?? '');

    return $vrednost !== ''
        && mb_strlen($vrednost) <= 120
        && filter_var($vrednost, FILTER_VALIDATE_EMAIL) !== false;
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

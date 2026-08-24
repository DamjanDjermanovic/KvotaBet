<?php
// Upis i čitanje tekstualnih log fajlova: logovi/pristup.log i logovi/greske.log.
// Ovo je nezavisan trag pored tabele pristup_log u bazi - radi i kad baza
// otkaže, jer greška konekcije mora negde da se zabeleži.

require_once __DIR__ . '/../config/konfiguracija.php';

// Upisuje jedan red u logovi/pristup.log. Poziva se pri svakom pristupu
// stranici, iz zaglavlje.php, da bi admin kasnije video statistiku pristupa.
function upisi_pristup(string $stranica, ?string $korisnicko_ime): void
{
    $vreme = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $metod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $ko = $korisnicko_ime ?? 'gost';

    $red = "[$vreme] $ip | $ko | $metod $stranica" . PHP_EOL;

    // LOCK_EX sprečava da dva istovremena zahteva izmešaju redove u fajlu
    file_put_contents(PUTANJA_LOG_PRISTUP, $red, FILE_APPEND | LOCK_EX);
}

// Upisuje jedan red u logovi/greske.log. Poziva se iz catch blokova umesto
// da se korisniku prikaže sirova poruka izuzetka.
function upisi_gresku(string $poruka): void
{
    $vreme = date('Y-m-d H:i:s');
    $red = "[$vreme] $poruka" . PHP_EOL;

    file_put_contents(PUTANJA_LOG_GRESKE, $red, FILE_APPEND | LOCK_EX);
}

// Čita poslednjih $broj_linija iz log fajla na datoj putanji i vraća ih kao
// niz stringova (najstarija prva, najnovija poslednja). Koristi se u admin
// panelu za prikaz sadržaja logova bez otvaranja fajla van aplikacije.
function procitaj_log(string $putanja, int $broj_linija = 100): array
{
    if (!file_exists($putanja)) {
        return [];
    }

    $sve_linije = file($putanja, FILE_IGNORE_NEW_LINES);

    if ($sve_linije === false) {
        return [];
    }

    return array_slice($sve_linije, -$broj_linija);
}

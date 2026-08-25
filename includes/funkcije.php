<?php
// Pomoćne funkcije opšte namene: bezbedan ispis, preusmeravanje, formatiranje
// novca i datuma, CSRF zaštita i generisanje oznaka. Funkcije za proveru
// ispravnosti korisničkog unosa idu u includes/validacija.php, ne ovde.

require_once __DIR__ . '/../config/konfiguracija.php';

// Bezbedan ispis teksta u HTML - štiti od XSS napada. Svaki podatak koji je
// makar posredno unet od strane korisnika mora proći kroz ovu funkciju pre
// nego što se ispiše na stranici.
function bezbedno(?string $tekst): string
{
    return htmlspecialchars($tekst ?? '', ENT_QUOTES, 'UTF-8');
}

// Preusmerava korisnika na drugu stranicu (unutar aplikacije ili apsolutnu
// putanju) i odmah prekida dalje izvršavanje skripte
function preusmeri(string $putanja): void
{
    header('Location: ' . $putanja);
    exit;
}

// Formatira novčani iznos za prikaz sa dve decimale i oznakom valute.
// Prima i string jer PDO vraća DECIMAL kolone kao string, ne kao float.
function formatiraj_novac(int|float|string $iznos): string
{
    return number_format((float) $iznos, 2, ',', '.') . ' din';
}

// Formatira datum (bez vremena) u srpski format dan.mesec.godina.
function formatiraj_datum(string $datum_vreme): string
{
    return date('d.m.Y.', strtotime($datum_vreme));
}

// Formatira datum i vreme u srpski format dan.mesec.godina. sati:minuti.
function formatiraj_datum_vreme(string $datum_vreme): string
{
    return date('d.m.Y. H:i', strtotime($datum_vreme));
}

// Sastavlja ime i prezime za prikaz. Operater i administrator ih ne moraju
// imati upisane (vidi ime_obavezno_za_ulogu u includes/validacija.php), pa se
// tada koristi korisničko ime - da red u tabeli ne ostane prazan i da se nalog
// i dalje može prepoznati.
function ime_za_prikaz(?string $ime, ?string $prezime, ?string $korisnicko_ime): string
{
    $puno_ime = trim(trim($ime ?? '') . ' ' . trim($prezime ?? ''));

    return $puno_ime !== '' ? $puno_ime : trim($korisnicko_ime ?? '');
}

// Generiše CSRF token (ako već ne postoji u sesiji) i vraća njegovu vrednost
// da bi se upisala u skriveno polje forme. Zahteva da je sesija već pokrenuta.
function generisi_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

// Proverava da li token poslat iz forme odgovara tokenu sačuvanom u sesiji.
// hash_equals se koristi umesto == da bi se izbeglo poređenje osetljivo na
// vreme izvršavanja (timing napad).
function csrf_token_ispravan(?string $token_iz_forme): bool
{
    if (empty($_SESSION['csrf_token']) || empty($token_iz_forme)) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token_iz_forme);
}

// Generiše jedinstvenu, čitljivu oznaku tiketa u obliku T-GODINA-nasumičniDeo,
// koja se korisniku prikazuje umesto internog ID-ja iz baze.
function generisi_broj_tiketa(): string
{
    return 'T-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

// Da li tip transakcije uvećava stanje računa (priliv) ili ga umanjuje (odliv).
// Jedno mesto istine za znak transakcije - koriste ga i prikaz istorije i
// preračunavanje stanja računa, pa ne smeju da se raziđu.
function transakcija_je_priliv(string $tip): bool
{
    return in_array($tip, ['uplata', 'dobitak', 'storno'], true);
}

// Priprema novčani iznos ili kvotu za upis u DECIMAL kolonu: uvek dve decimale,
// tačka kao separator i bez razdvajanja hiljada. Sprečava da PHP veliki float
// pretvori u zapis sa eksponentom, koji baza ne bi ispravno pročitala.
function za_bazu(float $iznos): string
{
    return number_format($iznos, 2, '.', '');
}

// Upamti poruku za ispis posle sledećeg preusmeravanja (tzv. "flash" poruka).
// Koristi se posle CRUD akcija: stranica obradi POST, preusmeri na samu sebe
// (da osvežavanje ne ponovi slanje forme), a poruka o uspehu/grešci preživi
// to preusmeravanje kroz sesiju.
function postavi_poruku(string $vrsta, string $tekst): void
{
    $_SESSION['poruke'][] = ['vrsta' => $vrsta, 'tekst' => $tekst];
}

// Ispisuje sve upamćene poruke kao Bootstrap obaveštenja i prazni ih iz
// sesije, da se ne bi ponovo prikazale pri sledećem učitavanju stranice.
function prikazi_poruke(): void
{
    foreach ($_SESSION['poruke'] ?? [] as $poruka) {
        echo '<div class="alert alert-' . bezbedno($poruka['vrsta']) . '">' . bezbedno($poruka['tekst']) . '</div>';
    }

    unset($_SESSION['poruke']);
}

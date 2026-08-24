<?php
// Autentifikacija: pokretanje sesije, prijava, odjava, kolačić "zapamti me"
// i provera prava pristupa po ulozi. Uključuje se na vrhu svake stranice
// koja zna za prijavljenog korisnika ili koja zahteva određenu ulogu.

require_once __DIR__ . '/../config/konfiguracija.php';
require_once __DIR__ . '/../config/baza.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/funkcije.php';

// Naziv kolačića za funkciju "zapamti me"
define('NAZIV_KOLACICA_ZAPAMTI_ME', 'zapamti_me');

// Podešavanja kolačića sesije moraju se postaviti pre session_start() -
// httponly sprečava čitanje kolačića iz JS-a, samesite ublažava CSRF
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => KORENSKI_URL . '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Upisuje podatke o prijavljenom korisniku u sesiju i regeneriše ID sesije -
// sprečava napad fiksacijom sesije (session fixation) posle uspešne prijave
function zapocni_sesiju_korisnika(array $korisnik): void
{
    session_regenerate_id(true);
    $_SESSION['korisnik_id']    = (int) $korisnik['id'];
    $_SESSION['korisnicko_ime'] = $korisnik['korisnicko_ime'];
    $_SESSION['uloga']          = $korisnik['uloga'];
}

// true ako je neko prijavljen u trenutnoj sesiji
function korisnik_prijavljen(): bool
{
    return isset($_SESSION['korisnik_id']);
}

// Vraća ulogu prijavljenog korisnika ('igrac', 'operater', 'admin') ili
// null ako niko nije prijavljen
function trenutna_uloga(): ?string
{
    return $_SESSION['uloga'] ?? null;
}

// Prekida izvršavanje stranice i preusmerava korisnika ako nije prijavljen
// ili mu uloga nije među dozvoljenima. Poziva se na vrhu svake zaštićene
// stranice, pre bilo kakvog ispisa sadržaja.
function zahtevaj_ulogu(string ...$dozvoljene_uloge): void
{
    if (!korisnik_prijavljen()) {
        preusmeri(KORENSKI_URL . '/prijava.php');
    }

    if (!in_array(trenutna_uloga(), $dozvoljene_uloge, true)) {
        preusmeri(KORENSKI_URL . '/greska.php?kod=403');
    }
}

// Generiše novi token za "zapamti me", u bazu upisuje samo njegov heš (SHA-256),
// a sirovu vrednost šalje korisniku kroz kolačić - tako baza nikad ne čuva
// vrednost koja bi, ako procuri, sama po sebi omogućila prijavu
function postavi_kolacic_zapamti_me(int $korisnik_id): void
{
    $token = bin2hex(random_bytes(32));
    $hes = hash('sha256', $token);

    try {
        $pdo = konektuj_bazu();
        $upit = $pdo->prepare('UPDATE korisnik SET token_zapamti_me = ? WHERE id = ?');
        $upit->execute([$hes, $korisnik_id]);

        setcookie(NAZIV_KOLACICA_ZAPAMTI_ME, $korisnik_id . ':' . $token, [
            'expires'  => time() + TRAJANJE_ZAPAMTI_ME,
            'path'     => KORENSKI_URL . '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } catch (PDOException $e) {
        upisi_gresku('Greška pri postavljanju kolačića zapamti me: ' . $e->getMessage());
    }
}

// Briše kolačić "zapamti me" kod klijenta, a ako je poznat korisnik i
// njegov heš tokena u bazi - poziva se pri odjavi i kad je kolačić neispravan
function obrisi_kolacic_zapamti_me(?int $korisnik_id = null): void
{
    if ($korisnik_id !== null) {
        try {
            $pdo = konektuj_bazu();
            $upit = $pdo->prepare('UPDATE korisnik SET token_zapamti_me = NULL WHERE id = ?');
            $upit->execute([$korisnik_id]);
        } catch (PDOException $e) {
            upisi_gresku('Greška pri brisanju kolačića zapamti me: ' . $e->getMessage());
        }
    }

    setcookie(NAZIV_KOLACICA_ZAPAMTI_ME, '', [
        'expires'  => time() - 3600,
        'path'     => KORENSKI_URL . '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[NAZIV_KOLACICA_ZAPAMTI_ME]);
}

// Uništava sesiju i kolačić "zapamti me" trenutno prijavljenog korisnika
function odjavi_korisnika(): void
{
    $korisnik_id = $_SESSION['korisnik_id'] ?? null;

    $_SESSION = [];
    session_destroy();

    obrisi_kolacic_zapamti_me($korisnik_id);
}

// Ako niko nije prijavljen u sesiji, ali postoji ispravan kolačić
// "zapamti me", automatski prijavljuje korisnika. Poziva se jednom, odmah
// po učitavanju ovog fajla, pre nego što stranica proveri prava pristupa.
function primeni_zapamti_me(): void
{
    if (korisnik_prijavljen() || empty($_COOKIE[NAZIV_KOLACICA_ZAPAMTI_ME])) {
        return;
    }

    $delovi = explode(':', $_COOKIE[NAZIV_KOLACICA_ZAPAMTI_ME], 2);

    if (count($delovi) !== 2 || !ctype_digit($delovi[0])) {
        obrisi_kolacic_zapamti_me();
        return;
    }

    [$korisnik_id, $token] = $delovi;

    try {
        $pdo = konektuj_bazu();
        $upit = $pdo->prepare(
            'SELECT k.id, k.korisnicko_ime, k.status, k.token_zapamti_me, u.naziv AS uloga
             FROM korisnik k JOIN uloga u ON u.id = k.uloga_id
             WHERE k.id = ?'
        );
        $upit->execute([(int) $korisnik_id]);
        $korisnik = $upit->fetch();

        if (
            $korisnik
            && $korisnik['status'] === 'aktivan'
            && !empty($korisnik['token_zapamti_me'])
            && hash_equals($korisnik['token_zapamti_me'], hash('sha256', $token))
        ) {
            zapocni_sesiju_korisnika($korisnik);
        } else {
            obrisi_kolacic_zapamti_me();
        }
    } catch (PDOException $e) {
        upisi_gresku('Greška pri proveri kolačića zapamti me: ' . $e->getMessage());
    }
}

primeni_zapamti_me();

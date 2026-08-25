<?php
// Zajednička osnova za sve AJAX endpointe. Postavlja JSON zaglavlje i nudi
// funkcije za odgovor i za provere koje su drugačije nego kod običnih stranica:
// AJAX zahtev ne sme da bude preusmeren na stranicu za prijavu, jer fetch()
// očekuje JSON, a ne HTML.

require_once __DIR__ . '/../includes/autentifikacija.php';

header('Content-Type: application/json; charset=utf-8');

// Ispisuje odgovor kao JSON i prekida izvršavanje. JSON_UNESCAPED_UNICODE je
// obavezan da bi se naša slova prenela kao slova, a ne kao \u oznake.
function json_odgovor(array $podaci, int $http_kod = 200): void
{
    http_response_code($http_kod);
    echo json_encode($podaci, JSON_UNESCAPED_UNICODE);
    exit;
}

// AJAX verzija funkcije zahtevaj_ulogu(): umesto preusmeravanja vraća JSON
// grešku sa odgovarajućim HTTP kodom (401 nije prijavljen, 403 nema pravo)
function zahtevaj_ulogu_json(string ...$dozvoljene_uloge): void
{
    if (!korisnik_prijavljen()) {
        json_odgovor(['uspeh' => false, 'poruka' => 'Morate biti prijavljeni.'], 401);
    }

    if (!in_array(trenutna_uloga(), $dozvoljene_uloge, true)) {
        json_odgovor(['uspeh' => false, 'poruka' => 'Nemate pravo na ovu radnju.'], 403);
    }
}

// Endpointi koji menjaju stanje smeju se pozivati samo POST metodom
function zahtevaj_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_odgovor(['uspeh' => false, 'poruka' => 'Neispravan način poziva.'], 405);
    }
}

// Provera CSRF tokena je obavezna i za AJAX zahteve, isto kao za obične forme
function zahtevaj_csrf(): void
{
    if (!csrf_token_ispravan($_POST['csrf_token'] ?? null)) {
        json_odgovor(['uspeh' => false, 'poruka' => 'Nevažeća sesija. Osvežite stranicu i pokušajte ponovo.'], 403);
    }
}

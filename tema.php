<?php
// Prima izbor teme sa prekidača u navigaciji, pamti ga u kolačiću i vraća
// korisnika na stranicu sa koje je došao.
//
// Namerno obična forma, bez AJAX-a: prekidač tako radi i kad je JavaScript
// isključen. Cena je jedno osvežavanje strane, koje je ovde i korisno - tema
// se primenjuje na serveru, pa nema treptaja.

require_once __DIR__ . '/includes/autentifikacija.php';
require_once __DIR__ . '/includes/funkcije.php';
require_once __DIR__ . '/includes/tema.php';

// Vraća putanju na koju se sme preusmeriti. Podatak stiže iz forme, pa se ne
// uzima zdravo za gotovo: mora počinjati korenskom putanjom aplikacije, čime
// otpada i preusmeravanje na tuđi sajt (i "//tudji-sajt" i "http://..." padaju
// na ovoj proveri).
function bezbedan_povratak(?string $putanja): string
{
    $podrazumevano = KORENSKI_URL . '/index.php';

    if ($putanja === null || $putanja === '') {
        return $podrazumevano;
    }

    if (strpbrk($putanja, "\r\n") !== false) {
        return $podrazumevano;
    }

    return str_starts_with($putanja, KORENSKI_URL . '/') ? $putanja : $podrazumevano;
}

$povratak = bezbedan_povratak($_POST['povratak'] ?? null);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_token_ispravan($_POST['csrf_token'] ?? null)) {
    preusmeri($povratak);
}

zapamti_temu($_POST['tema'] ?? '');

preusmeri($povratak);

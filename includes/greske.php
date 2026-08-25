<?php
// Poslednja odbrana od sirove PHP greške na ekranu.
//
// Pojedinačni upiti su i dalje u try/catch blokovima - ovo hvata ono što
// promakne: neuhvaćen izuzetak, grešku u kodu, fatalnu grešku. Sve takvo se
// upisuje u logovi/greske.log, a korisniku se prikaže greska.php.
//
// AJAX endpointi pre uključivanja ovog fajla definišu AJAX_ZAHTEV, da bi im
// odgovor i u slučaju greške ostao JSON, a ne HTML stranica koju fetch() ne
// ume da pročita.

require_once __DIR__ . '/../config/konfiguracija.php';
require_once __DIR__ . '/log.php';

// Prekida obradu i vodi korisnika na stranicu sa greškom (ili vraća JSON,
// ako je zahtev došao preko AJAX-a)
function prikazi_gresku_i_prekini(int $kod): void
{
    if (defined('AJAX_ZAHTEV') && AJAX_ZAHTEV) {
        if (!headers_sent()) {
            http_response_code($kod);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode(
            ['uspeh' => false, 'poruka' => 'Došlo je do greške na serveru. Pokušajte ponovo kasnije.'],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    // Ako ispis stranice još nije počeo, korisnik se preusmerava na greska.php
    if (!headers_sent()) {
        header('Location: ' . KORENSKI_URL . '/greska.php?kod=' . $kod);
        exit;
    }

    // Greška je nastala usred ispisa, pa preusmeravanje više nije moguće -
    // ostaje samo da se poruka doda na kraj već ispisanog dela stranice
    echo '<div class="alert alert-danger m-3">Došlo je do greške. '
        . 'Deo stranice možda nije prikazan. Pokušajte ponovo kasnije.</div>';
    exit;
}

// Neuhvaćen izuzetak bilo koje vrste
set_exception_handler(function (Throwable $izuzetak): void {
    upisi_gresku(
        'Neuhvaćen izuzetak ' . get_class($izuzetak) . ': ' . $izuzetak->getMessage()
        . ' u ' . $izuzetak->getFile() . ':' . $izuzetak->getLine()
    );

    prikazi_gresku_i_prekini(500);
});

// Upozorenja i obaveštenja: beleže se, ali ne prekidaju rad stranice
set_error_handler(function (int $nivo, string $poruka, string $fajl = '', int $linija = 0): bool {
    // Greške ugašene operatorom @ ili isključene kroz error_reporting se preskaču
    if (!(error_reporting() & $nivo)) {
        return false;
    }

    upisi_gresku("PHP greška (nivo $nivo): $poruka u $fajl:$linija");

    // true znači "obrađeno je" - PHP tada ništa ne ispisuje na ekran
    return true;
});

// Fatalne greške ne prolaze kroz set_error_handler, pa se hvataju na izlazu
register_shutdown_function(function (): void {
    $greska = error_get_last();

    if ($greska === null) {
        return;
    }

    $fatalni = [E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING];

    if (in_array($greska['type'], $fatalni, true)) {
        upisi_gresku(
            'Fatalna greška: ' . $greska['message'] . ' u ' . $greska['file'] . ':' . $greska['line']
        );

        prikazi_gresku_i_prekini(500);
    }
});

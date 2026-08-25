<?php
// Upload i obrada verifikacionih dokumenata igrača (lična karta kao JPG/PNG/PDF).
// Fajl koristi i igrac/profil.php (slanje dokumenta) i operater/dokumenta.php
// (pregled i odobravanje) - provere pri prijemu fajla su iste bez obzira ko
// ga posle gleda.

require_once __DIR__ . '/../config/konfiguracija.php';
require_once __DIR__ . '/log.php';

// Prima jedan element iz $_FILES, proverava ga prema svim pravilima za
// upload (veličina, ekstenzija, stvarni MIME tip preko finfo) i, ako je
// ispravan, premešta ga pod nasumičnim imenom u folder van dosega direktnog
// pristupa. Vraća ['uspeh' => bool, 'poruka' => string, 'zapis' => array|null].
function obradi_upload_dokumenta(array $fajl): array
{
    if (!isset($fajl['error']) || is_array($fajl['error'])) {
        return ['uspeh' => false, 'poruka' => 'Neispravan zahtev za slanje fajla.', 'zapis' => null];
    }

    if ($fajl['error'] === UPLOAD_ERR_NO_FILE) {
        return ['uspeh' => false, 'poruka' => 'Izaberite fajl za slanje.', 'zapis' => null];
    }

    if ($fajl['error'] !== UPLOAD_ERR_OK) {
        return ['uspeh' => false, 'poruka' => 'Slanje fajla nije uspelo. Pokušajte ponovo.', 'zapis' => null];
    }

    // is_uploaded_file sprečava da se kao "upload" prihvati proizvoljna
    // putanja na serveru koju bi neko podmetnuo kroz $_FILES
    if (!is_uploaded_file($fajl['tmp_name'])) {
        return ['uspeh' => false, 'poruka' => 'Neispravan zahtev za slanje fajla.', 'zapis' => null];
    }

    if ($fajl['size'] > MAX_VELICINA_UPLOADA) {
        $mb = number_format(MAX_VELICINA_UPLOADA / (1024 * 1024), 0);
        return ['uspeh' => false, 'poruka' => "Fajl je prevelik. Maksimalna veličina je {$mb} MB.", 'zapis' => null];
    }

    $ekstenzija = strtolower(pathinfo($fajl['name'], PATHINFO_EXTENSION));

    if (!in_array($ekstenzija, DOZVOLJENE_EKSTENZIJE, true)) {
        return ['uspeh' => false, 'poruka' => 'Dozvoljeni formati su JPG, PNG i PDF.', 'zapis' => null];
    }

    // Ekstenzija se lako laže preimenovanjem fajla - stvarni tip se proverava
    // po sadržaju preko fileinfo (finfo), ne po imenu koje je poslao klijent
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_tip = finfo_file($finfo, $fajl['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime_tip, DOZVOLJENI_MIME_TIPOVI, true)) {
        return ['uspeh' => false, 'poruka' => 'Sadržaj fajla ne odgovara dozvoljenim formatima (JPG, PNG, PDF).', 'zapis' => null];
    }

    if (!is_dir(PUTANJA_UPLOAD_DOKUMENATA) || !is_writable(PUTANJA_UPLOAD_DOKUMENATA)) {
        upisi_gresku('Folder za upload dokumenata ne postoji ili nije upisiv: ' . PUTANJA_UPLOAD_DOKUMENATA);
        return ['uspeh' => false, 'poruka' => 'Trenutno nije moguće primiti fajl. Pokušajte kasnije.', 'zapis' => null];
    }

    // Nasumično ime sprečava koliziju sa postojećim fajlovima i onemogućava
    // pogađanje putanje tuđeg dokumenta
    $novo_ime = bin2hex(random_bytes(16)) . '.' . $ekstenzija;
    $puna_putanja = PUTANJA_UPLOAD_DOKUMENATA . $novo_ime;

    if (!move_uploaded_file($fajl['tmp_name'], $puna_putanja)) {
        upisi_gresku('Neuspešno premeštanje otpremljenog dokumenta u ' . $puna_putanja);
        return ['uspeh' => false, 'poruka' => 'Trenutno nije moguće sačuvati fajl. Pokušajte kasnije.', 'zapis' => null];
    }

    return [
        'uspeh'  => true,
        'poruka' => 'Dokument je poslat na proveru.',
        'zapis'  => [
            'originalni_naziv' => mb_substr($fajl['name'], 0, 255),
            'naziv_na_serveru' => $novo_ime,
            'mime_tip'         => $mime_tip,
            'velicina_bajtova' => (int) $fajl['size'],
        ],
    ];
}

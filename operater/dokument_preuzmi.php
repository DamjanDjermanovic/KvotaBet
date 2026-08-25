<?php
// Bezbedno posluživanje otpremljenog dokumenta. Fajlovi stoje u
// uploads/dokumenta/, koji je preko .htaccess zaključan za direktan pristup -
// ovo je jedino mesto kroz koje operater ili admin mogu da ih pogledaju,
// posle provere uloge.

require_once __DIR__ . '/../includes/autentifikacija.php';

zahtevaj_ulogu('operater', 'admin');

$pdo = konektuj_bazu();
$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int) $_GET['id'] : null;

if ($id === null) {
    preusmeri(KORENSKI_URL . '/greska.php?kod=404');
}

try {
    $upit = $pdo->prepare('SELECT naziv_na_serveru, originalni_naziv, mime_tip FROM dokument WHERE id = ?');
    $upit->execute([$id]);
    $dokument = $upit->fetch();
} catch (PDOException $e) {
    upisi_gresku('Greška pri čitanju dokumenta iz baze: ' . $e->getMessage());
    $dokument = false;
}

if (!$dokument) {
    preusmeri(KORENSKI_URL . '/greska.php?kod=404');
}

// basename() sprečava izlazak iz foldera i ako bi naziv_na_serveru ikad
// sadržao putanju - u praksi je uvek nasumično generisano ime bez putanje
$puna_putanja = PUTANJA_UPLOAD_DOKUMENATA . basename($dokument['naziv_na_serveru']);

if (!is_file($puna_putanja)) {
    upisi_gresku('Dokument nedostaje na disku: ' . $puna_putanja);
    preusmeri(KORENSKI_URL . '/greska.php?kod=404');
}

header('Content-Type: ' . $dokument['mime_tip']);
header('Content-Length: ' . filesize($puna_putanja));
header('Content-Disposition: inline; filename="' . rawurlencode($dokument['originalni_naziv']) . '"');
header('X-Content-Type-Options: nosniff');

readfile($puna_putanja);

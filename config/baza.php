<?php
// Konekcija ka bazi podataka preko PDO. Ovo je jedino mesto u projektu gde
// se čuvaju kredencijali za pristup bazi.

require_once __DIR__ . '/konfiguracija.php';
require_once __DIR__ . '/../includes/log.php';

// Podrazumevani XAMPP kredencijali - izmeniti ovde ako je lokalna baza
// drugačije podešena
define('BAZA_HOST', 'localhost');
define('BAZA_NAZIV', 'kladionica');
define('BAZA_KORISNIK', 'root');
define('BAZA_LOZINKA', '');
define('BAZA_KARAKTER_SET', 'utf8mb4');

// Otvara (ili vraća već otvorenu) PDO konekciju ka bazi. Konekcija je
// singleton - jedna po zahtevu - da se ne bi otvarala nova na svaki poziv.
function konektuj_bazu(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . BAZA_HOST . ';dbname=' . BAZA_NAZIV . ';charset=' . BAZA_KARAKTER_SET;

    $opcije = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, BAZA_KORISNIK, BAZA_LOZINKA, $opcije);
    } catch (PDOException $e) {
        // Ne sme se prikazati sirova poruka izuzetka (može otkriti detalje o bazi)
        upisi_gresku('Neuspešno povezivanje na bazu: ' . $e->getMessage());
        die('Trenutno nije moguće pristupiti sistemu. Pokušajte ponovo kasnije.');
    }

    return $pdo;
}

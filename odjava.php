<?php
// Odjava trenutno prijavljenog korisnika: briše sesiju i kolačić "zapamti me".

require_once __DIR__ . '/includes/autentifikacija.php';

odjavi_korisnika();
preusmeri(KORENSKI_URL . '/prijava.php');

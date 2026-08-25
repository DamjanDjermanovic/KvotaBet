// Forma za korisnika u admin panelu: ime i prezime su obavezni samo za igrača.
//
// Bez ovoga bi pregledač i dalje tražio popunjeno ime i za operatera i za
// administratora (atribut required stoji u HTML-u), pa se forma ne bi mogla
// poslati. Ovo je samo udobnost - isto pravilo admin/korisnici.php proverava
// ponovo na serveru, jer se poslatim podacima ne veruje.

(function () {
    'use strict';

    var forma = document.getElementById('forma-korisnik');

    if (!forma) {
        return;
    }

    var poljeUloge = document.getElementById('uloga_id');
    var poljeImena = document.getElementById('ime');
    var poljePrezimena = document.getElementById('prezime');

    if (!poljeUloge || !poljeImena || !poljePrezimena) {
        return;
    }

    // Da li izabrana uloga traži ime i prezime - podatak stiže sa servera,
    // kao data-trazi-ime na svakoj stavci menija, da spisak uloga postoji
    // samo na jednom mestu (u bazi)
    function ulogaTraziIme() {
        var izabrana = poljeUloge.options[poljeUloge.selectedIndex];

        return !izabrana || izabrana.dataset.traziIme === '1';
    }

    function osveziObaveznost() {
        var trazi = ulogaTraziIme();

        [poljeImena, poljePrezimena].forEach(function (polje) {
            polje.required = trazi;

            // Oznaka "(opciono)" uz naslov polja se pali i gasi zajedno sa
            // obaveznošću, da forma ne traži jedno a piše drugo
            var oznaka = polje.parentNode.querySelector('.oznaka-neobavezno');

            if (oznaka) {
                oznaka.hidden = trazi;
            }
        });
    }

    poljeUloge.addEventListener('change', osveziObaveznost);

    // Prvi prolaz je ispisao PHP; ovde se stanje samo poravnava, za slučaj da
    // je pregledač zapamtio drugi izbor pri povratku na stranicu
    osveziObaveznost();
})();

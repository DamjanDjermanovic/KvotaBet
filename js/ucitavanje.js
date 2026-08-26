// Stanje učitavanja na dugmadima koja pokreću nešto što traje.
//
// Dva slučaja u projektu:
//   1. AJAX - uplata tiketa (js/tiket.js poziva pokreniUcitavanje sam);
//   2. obična forma koja dugo putuje - preuzimanje ponude sa spoljnog servisa
//      (operater/uvoz.php), gde poziv sme da traje do 8 sekundi.
//
// U oba slučaja dugme se onemogući i dobije vrtešku, da se ne klikne dvaput.
// Zaštita od dvostruke uplate NIJE ovde - nju drži server (zaključavanje reda
// i provera stanja računa u ajax/tiket_uplati.php). Ovo je samo da korisnik
// vidi da se nešto dešava.

(function () {
    'use strict';

    // Prebacuje dugme u stanje "radi se". Vraća funkciju koja ga vraća u
    // prethodno stanje - pozivaocu je tako svejedno šta je na dugmetu pisalo.
    function pokreniUcitavanje(dugme, tekst) {
        if (!dugme || dugme.dataset.ucitavanjeAktivno === '1') {
            return function () {};
        }

        var staroHtml = dugme.innerHTML;
        var staroOnemoguceno = dugme.disabled;

        dugme.dataset.ucitavanjeAktivno = '1';
        dugme.disabled = true;
        // aria-busy javlja čitaču ekrana da se čeka; sam natpis se menja u
        // tekst radnje, jer "Učitavanje..." bez konteksta ne govori ništa
        dugme.setAttribute('aria-busy', 'true');
        dugme.innerHTML = '<span class="vrteska" aria-hidden="true"></span>' +
            (tekst ? '<span>' + tekst + '</span>' : '');

        return function vrati() {
            delete dugme.dataset.ucitavanjeAktivno;
            dugme.disabled = staroOnemoguceno;
            dugme.removeAttribute('aria-busy');
            dugme.innerHTML = staroHtml;
        };
    }

    // Dostupno ostalim skriptama (js/tiket.js)
    window.pokreniUcitavanje = pokreniUcitavanje;

    // Obične forme: dugme sa data-ucitavanje="tekst" prelazi u stanje
    // učitavanja kad se forma pošalje. Pošto posle toga sledi nova stranica,
    // stanje se ne vraća - osim ako se korisnik vrati dugmetom "nazad", pa se
    // zato sluša i pageshow.
    document.addEventListener('submit', function (dogadjaj) {
        var forma = dogadjaj.target;

        if (!forma || typeof forma.matches !== 'function') {
            return;
        }

        // Dugme kojim je forma poslata; ako ga nema, uzima se prvo u formi
        var dugme = (dogadjaj.submitter && dogadjaj.submitter.dataset.ucitavanje !== undefined)
            ? dogadjaj.submitter
            : null;

        if (!dugme) {
            return;
        }

        // Forma sa potvrdom (confirm) koja je odbijena uopšte ne stiže dovde,
        // jer onsubmit vraća false i događaj se ne nastavlja.
        //
        // Promena se odlaže za sledeći prolaz petlje događaja, a ne radi
        // odmah: onemogućeno dugme pregledač ne broji kao pošiljaoca forme,
        // pa bi se izgubio njegov name=value. Na stranici uvoza baš o tome
        // zavisi razlika između "Preuzmi" i "Osveži sa servera" (osvezi=1).
        setTimeout(function () {
            pokreniUcitavanje(dugme, dugme.dataset.ucitavanje);
        }, 0);
    });

    // Povratak na stranicu iz keša pregledača ("nazad") vraća HTML kakav je
    // bio pri odlasku - sa zamrznutim dugmetom. Osvežavanje ga oslobađa.
    window.addEventListener('pageshow', function (dogadjaj) {
        if (!dogadjaj.persisted) {
            return;
        }

        var zamrznuta = document.querySelectorAll('[data-ucitavanje-aktivno="1"]');

        for (var i = 0; i < zamrznuta.length; i++) {
            zamrznuta[i].disabled = false;
            zamrznuta[i].removeAttribute('aria-busy');
            delete zamrznuta[i].dataset.ucitavanjeAktivno;
        }
    });
})();

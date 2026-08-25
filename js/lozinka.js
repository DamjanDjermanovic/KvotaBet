// Dugme "oko" pored svakog polja za lozinku - prebacuje polje iz type="password"
// u type="text" i nazad, da korisnik može da proveri šta je otkucao.
//
// Dugme pravi JS, ne PHP: ako je JavaScript isključen, dugmeta nema, umesto da
// stoji na formi i ne radi ništa. Zbog toga se ova skripta uključuje na svakoj
// stranici sa lozinkom (registracija, prijava, profil, admin/korisnici) i sama
// pronalazi sva takva polja.

(function () {
    'use strict';

    var polja = document.querySelectorAll('input[type="password"]');

    Array.prototype.forEach.call(polja, function (polje) {
        // Bootstrap dugme uz polje traži omotač .input-group, pa se polje
        // premešta u novonapravljeni omotač na svoje mesto u formi
        var grupa = document.createElement('div');
        grupa.className = 'input-group';
        polje.parentNode.insertBefore(grupa, polje);
        grupa.appendChild(polje);

        var dugme = document.createElement('button');

        // Bez type="button" dugme unutar forme podrazumevano šalje formu
        dugme.type = 'button';
        dugme.className = 'btn btn-outline-secondary prikazi-lozinku';
        dugme.innerHTML = '<i class="bi bi-eye" aria-hidden="true"></i>';
        grupa.appendChild(dugme);

        // Poruka o grešci (.invalid-feedback) je do sada bila odmah iza polja.
        // Sada je iza omotača, gde je Bootstrap ne prikazuje, pa se premešta
        // unutar grupe - uz klasu .has-validation, koja je za to i predviđena.
        var poruka = grupa.nextElementSibling;

        if (poruka && poruka.classList.contains('invalid-feedback')) {
            grupa.classList.add('has-validation');
            grupa.appendChild(poruka);
        }

        // Postavlja izgled dugmeta i poruku za čitače ekrana prema tome da li
        // je lozinka trenutno vidljiva
        function osveziDugme(vidljiva) {
            dugme.querySelector('i').className = vidljiva ? 'bi bi-eye-slash' : 'bi bi-eye';
            dugme.setAttribute('aria-label', vidljiva ? 'Sakrij lozinku' : 'Prikaži lozinku');
            dugme.setAttribute('title', vidljiva ? 'Sakrij lozinku' : 'Prikaži lozinku');
            dugme.setAttribute('aria-pressed', vidljiva ? 'true' : 'false');
        }

        osveziDugme(false);

        dugme.addEventListener('click', function () {
            var vidljiva = polje.type === 'text';

            polje.type = vidljiva ? 'password' : 'text';
            osveziDugme(!vidljiva);

            // Fokus se vraća u polje da kucanje može odmah da se nastavi
            polje.focus();
        });

        // Pri slanju forme lozinka se vraća u skriveno stanje, da ne ostane
        // ispisana na ekranu ako se stranica ponovo prikaže sa greškom
        if (polje.form) {
            polje.form.addEventListener('submit', function () {
                polje.type = 'password';
                osveziDugme(false);
            });
        }
    });
})();

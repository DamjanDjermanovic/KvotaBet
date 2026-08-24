// Klijentska validacija formi za registraciju i prijavu. Služi samo za
// udobnost korisnika (odmah vidi grešku, ne čeka povratak sa servera) -
// jedina provera koja se zaista računa je ona na serveru.

(function () {
    'use strict';

    // Postavlja Bootstrapov izgled validacije nad zadatom formom i sprečava
    // slanje dok forma nije ispravna prema HTML5 pravilima (required, pattern...)
    function omoguciBootstrapValidaciju(forma, dodatnaProvera) {
        forma.addEventListener('submit', function (dogadjaj) {
            var ispravna = forma.checkValidity();

            if (dodatnaProvera && !dodatnaProvera()) {
                ispravna = false;
            }

            if (!ispravna) {
                dogadjaj.preventDefault();
                dogadjaj.stopPropagation();
            }

            forma.classList.add('was-validated');
        });
    }

    var formaRegistracija = document.getElementById('forma-registracija');

    if (formaRegistracija) {
        var poljeLozinka = document.getElementById('lozinka');
        var poljePotvrda = document.getElementById('potvrda_lozinke');
        var poljeDatumRodjenja = document.getElementById('datum_rodjenja');

        // Lozinka mora imati bar jedno slovo i jednu cifru - HTML pattern za
        // password polje se namerno ne koristi jer bi otkrio dozvoljene znakove
        // u izvornom kodu; provera ide kroz JS
        function lozinkaJeIspravna() {
            var vrednost = poljeLozinka.value;
            return vrednost.length >= 6 && /[A-Za-z]/.test(vrednost) && /[0-9]/.test(vrednost);
        }

        function potvrdaSePoklapa() {
            return poljePotvrda.value === poljeLozinka.value;
        }

        // Punoletstvo se proverava i ovde da korisnik ne mora da šalje formu
        // da bi saznao da je premlad - prava provera je i dalje na serveru
        function korisnikJePunoletan() {
            if (!poljeDatumRodjenja.value) {
                return false;
            }

            var danas = new Date();
            var rodjen = new Date(poljeDatumRodjenja.value);
            var godine = danas.getFullYear() - rodjen.getFullYear();
            var meseciRazlika = danas.getMonth() - rodjen.getMonth();

            if (meseciRazlika < 0 || (meseciRazlika === 0 && danas.getDate() < rodjen.getDate())) {
                godine--;
            }

            return godine >= 18;
        }

        function azurirajGresku(polje, poruka, jeIspravno) {
            var kontejnerGreske = polje.parentElement.querySelector('.invalid-feedback');

            polje.setCustomValidity(jeIspravno ? '' : poruka);

            if (!jeIspravno && kontejnerGreske) {
                kontejnerGreske.textContent = poruka;
            }
        }

        omoguciBootstrapValidaciju(formaRegistracija, function () {
            var ispravno = true;

            if (!lozinkaJeIspravna()) {
                azurirajGresku(poljeLozinka, 'Lozinka mora imati bar 6 znakova, jedno slovo i jednu cifru.', false);
                ispravno = false;
            } else {
                azurirajGresku(poljeLozinka, '', true);
            }

            if (!potvrdaSePoklapa()) {
                azurirajGresku(poljePotvrda, 'Lozinke se ne poklapaju.', false);
                ispravno = false;
            } else {
                azurirajGresku(poljePotvrda, '', true);
            }

            if (!korisnikJePunoletan()) {
                azurirajGresku(poljeDatumRodjenja, 'Registracija je dozvoljena samo punoletnim korisnicima.', false);
                ispravno = false;
            } else {
                azurirajGresku(poljeDatumRodjenja, '', true);
            }

            return ispravno;
        });
    }

    var formaPrijava = document.getElementById('forma-prijava');

    if (formaPrijava) {
        omoguciBootstrapValidaciju(formaPrijava, null);
    }
})();

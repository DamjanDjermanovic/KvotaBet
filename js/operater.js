// Operaterske stranice: pomoćni JS koji ne menja podatke, samo olakšava unos.
// Bez AJAX-a - samo filtrira opcije koje su već stigle sa servera.

(function () {
    'use strict';

    // Na formi za događaj: kad se izabere domaćin, u listi gosta se prikazuju
    // samo timovi iz iste lige (i obrnuto) - server ionako ponovo proverava
    // da oba tima pripadaju istoj ligi, ovo je samo udobnost pri unosu.
    var formaDogadjaj = document.getElementById('forma-dogadjaj');

    if (formaDogadjaj) {
        var poljeDomacin = document.getElementById('domacin_id');
        var poljeGost = document.getElementById('gost_id');

        function filtrirajPoLigi(izvor, cilj) {
            var izabranaOpcija = izvor.options[izvor.selectedIndex];
            var ligaId = izabranaOpcija ? izabranaOpcija.dataset.liga : '';

            Array.prototype.forEach.call(cilj.options, function (opcija) {
                if (!opcija.value) {
                    opcija.hidden = false;
                    return;
                }
                opcija.hidden = ligaId !== '' && opcija.dataset.liga !== ligaId;
            });

            if (ligaId !== '' && cilj.options[cilj.selectedIndex] && cilj.options[cilj.selectedIndex].hidden) {
                cilj.value = '';
            }
        }

        poljeDomacin.addEventListener('change', function () {
            filtrirajPoLigi(poljeDomacin, poljeGost);
        });
        poljeGost.addEventListener('change', function () {
            filtrirajPoLigi(poljeGost, poljeDomacin);
        });

        // Ako je forma otvorena u režimu izmene sa već izabranim timovima,
        // liste se odmah filtriraju prema postojećem izboru
        if (poljeDomacin.value) {
            filtrirajPoLigi(poljeDomacin, poljeGost);
        }
    }

    // Na formi za sport: pregled izabrane ikonice. U padajućem meniju stoji
    // opis ("Meta — pikado, streljaštvo"), a ne izgled, pa se izabrana ikonica
    // odmah prikazuje pored menija. Prvi prikaz ispisuje PHP, ovde se samo
    // menja pri svakom novom izboru.
    var poljeIkonice = document.getElementById('ikonica');

    if (poljeIkonice && poljeIkonice.dataset.pregled) {
        var pregled = document.getElementById(poljeIkonice.dataset.pregled);

        if (pregled) {
            poljeIkonice.addEventListener('change', function () {
                // Crtica se prikazuje kad ikonica nije izabrana, da mesto za
                // pregled ne ostane prazno i ne "skače" pri izboru
                pregled.querySelector('i').className = 'bi ' + (poljeIkonice.value || 'bi-dash');
            });
        }
    }

    // Na formi za kvote: dugme "Dodaj sve tipove" ne šalje ništa serveru - samo
    // otkriva formu ispod, već popunjenu predloženim vrednostima za sve tipove
    // igre koji događaju nedostaju. Ništa se ne upisuje dok operater sam ne
    // pritisne "Sačuvaj predložene kvote" te (skrivene, ali postojeće) forme.
    var dugmeDodajSve = document.getElementById('dugme-dodaj-sve-tipove');

    if (dugmeDodajSve) {
        var predlogForma = document.getElementById(dugmeDodajSve.dataset.cilj);

        if (predlogForma) {
            dugmeDodajSve.addEventListener('click', function () {
                var otvoren = !predlogForma.classList.toggle('d-none');

                dugmeDodajSve.setAttribute('aria-expanded', otvoren ? 'true' : 'false');

                if (otvoren) {
                    predlogForma.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            });
        }
    }

    // Na pregledu uvoza (operater/uvoz.php): kućica u zaglavlju tabele
    // označava ili poništava sve redove odjednom. Redovi koji se ne mogu
    // uneti (duplikat, nepoznat tim, prošlo vreme) uopšte nemaju kućicu, pa
    // ih ovo ni ne dodiruje. Ništa se ne šalje serveru - upis i dalje traži
    // klik na "Sačuvaj u bazu".
    var formaUvoz = document.getElementById('forma-uvoz');
    var kucicaSve = document.getElementById('uvoz-sve');

    if (formaUvoz && kucicaSve) {
        var kucice = formaUvoz.querySelectorAll('input[name="utakmica[]"]');

        // Zaglavlje odmah pokazuje zatečeno stanje: server je već čekirao ono
        // što je spremno, pa kućica ne sme da krene od praznog
        function osveziZaglavlje() {
            var oznaceno = 0;

            Array.prototype.forEach.call(kucice, function (kucica) {
                if (kucica.checked) {
                    oznaceno++;
                }
            });

            kucicaSve.checked = oznaceno === kucice.length && kucice.length > 0;
            // Delimičan izbor se prikazuje trećim stanjem, a ne praznom kućicom
            kucicaSve.indeterminate = oznaceno > 0 && oznaceno < kucice.length;
        }

        kucicaSve.addEventListener('change', function () {
            Array.prototype.forEach.call(kucice, function (kucica) {
                kucica.checked = kucicaSve.checked;
            });
            kucicaSve.indeterminate = false;
        });

        Array.prototype.forEach.call(kucice, function (kucica) {
            kucica.addEventListener('change', osveziZaglavlje);
        });

        osveziZaglavlje();
    }
})();

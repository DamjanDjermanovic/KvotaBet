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
})();

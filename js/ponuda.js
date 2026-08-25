// Javna ponuda (index.php): dugme "+N" pored kvota otkriva ostale tipove igre
// ispod reda. Bez AJAX-a - sve kvote su već u HTML-u, ovde se samo pokazuje
// ili sakriva panel koji ih sadrži.

(function () {
    'use strict';

    document.addEventListener('click', function (dogadjaj) {
        var dugme = dogadjaj.target.closest('.dugme-prosiri-kvote');

        if (!dugme) {
            return;
        }

        var panel = document.getElementById(dugme.dataset.cilj);

        if (!panel) {
            return;
        }

        // classList.toggle('d-none') vraća true kad je klasa posle prekidanja
        // OSTALA na elementu, dakle kad je panel sakriven
        var sakriven = panel.classList.toggle('d-none');

        dugme.setAttribute('aria-expanded', sakriven ? 'false' : 'true');

        var oznaka = dugme.querySelector('.kvota-vrednost');

        if (oznaka) {
            oznaka.textContent = sakriven ? dugme.dataset.oznakaZatvoreno : dugme.dataset.oznakaOtvoreno;
        }
    });
})();

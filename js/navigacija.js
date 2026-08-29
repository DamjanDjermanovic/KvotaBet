// Grupni meniji u glavnoj navigaciji (Utakmice, Šifarnici...) imaju dva
// nezavisna sloja otvaranja - CSS :focus-within (tastatura, radi i bez
// JavaScripta) i Bootstrap-ov dropdown (klik, tastatura, sa JavaScriptom).
// Ovaj fajl radi tačno jednu stvar: čim potvrdi da je Bootstrap dostupan,
// isključuje CSS sloj tako što na svaku grupu doda klasu .js-aktivan - od
// tog trenutka je Bootstrap-ova .show klasa JEDINI izvor istine za to da li
// je meni vizuelno otvoren (vidi style.css, blok "GRUPE U GLAVNOJ
// NAVIGACIJI", gde ta klasa isključuje :focus-within selektor preko
// :not(.js-aktivan)).
//
// ZAŠTO OVO POSTOJI (uzrok utvrđen merenjem, ne pretpostavljen):
//
// Klik na već otvoren meni ga nije zatvarao - vizuelno je ostajao otvoren,
// iako je Bootstrap PRAVILNO menjao svoje stanje (.show nestaje, aria-
// expanded postaje "false" - provereno direktno u DOM-u, privremenim
// dijagnostičkim alatom, van ove datoteke). Uzrok: Bootstrap pri OTVARANJU
// sam pozove toggle.focus() (deo njegove pristupačnosti), a taj fokus ne ode
// sam od sebe kad se meni kasnije zatvori. Dok god toggle ima fokus,
// :focus-within iz style.css je i dalje tačan i sam po sebi drži meni
// vizuelno otvorenim - potpuno nezavisno od toga šta Bootstrap kaže.
//
// Isti obrazac se javlja i posle Escape-a: Bootstrap NAMERNO vraća fokus na
// toggle (ARIA konvencija za meni-dugme), pa bi :focus-within opet ostao
// tačan i posle ispravnog zatvaranja. Zato skidanje fokusa NIJE opšte
// rešenje (probano prvo, za klik - popravilo je klik, ali bi za Escape
// pokvarilo baš onu pristupačnost koju Escape namerno obezbeđuje). Jedino
// opšte rešenje je da :focus-within sloj prestane da se pita ČIM Bootstrap
// preuzme odgovornost - fokus na toggle-u tada više ničim ne utiče na
// vizuelno stanje, ono dolazi isključivo iz .show, koju Bootstrap ispravno
// vodi za SVAKI način zatvaranja (klik, Escape, klik van menija).
//
// POSLEDICA PO TASTATURU BEZ JAVASCRIPTA: nema je - ovaj fajl se u tom
// slučaju uopšte ne izvrši (typeof bootstrap === 'undefined'), .js-aktivan
// se nikad ne doda, i :focus-within u style.css ostaje jedini i potpuno
// nepromenjen mehanizam, tačno kao pre ovog rešenja.
//
// POSLEDICA PO TASTATURU SA JAVASCRIPTOM: samo Tab-dolazak na toggle više
// sam po sebi ne otvara meni (taj posao sada isključivo radi Bootstrap).
// Meni je i dalje potpuno dostižan i otvoriv tastaturom - Enter, Space ili
// strelica dole, odmah pošto Tab dovede fokus na toggle, otvaraju ga preko
// Bootstrap-a, kao i do sada. Ovo nije gubitak nego usklađivanje sa namerom
// sloja 1 iz style.css, koji je od početka bio zamišljen kao rezerva za
// slučaj DA JS-a nema, ne kao dodatni način otvaranja POREĐ Bootstrap-a.

(function () {
    'use strict';

    if (typeof bootstrap === 'undefined' || !bootstrap.Dropdown) {
        return;
    }

    var grupe = document.querySelectorAll('.navbar .nav-item.dropdown');

    grupe.forEach(function (grupa) {
        grupa.classList.add('js-aktivan');
    });

    // "Samo jedan otvoren": Bootstrap sam zatvara prethodno otvoren meni pri
    // KLIKU na sledeći (ugrađeno ponašanje, ovde nije ponovo pisano) i sam
    // koordiniše i Enter/Space/strelice otvaranje isto tako - jednom kad je
    // .js-aktivan prisutan, JEDINI način da se bilo koji od ovih menija
    // otvori jeste kroz Bootstrap-ov show(), a Bootstrap-ova sopstvena
    // koordinacija između njegovih instanci već sprečava da dve budu otvorene
    // istovremeno. Ipak, listener ispod je zadržan kao jeftina odbrambena
    // mera (poziva isključivo Bootstrap-ov Dropdown API, nikad ne dira klase
    // ručno) za slučaj da se to ikad promeni.
    function zatvoriOstale(trenutna) {
        grupe.forEach(function (grupa) {
            if (grupa === trenutna) {
                return;
            }

            var toggle = grupa.querySelector('.dropdown-toggle');

            if (toggle && toggle.classList.contains('show')) {
                bootstrap.Dropdown.getOrCreateInstance(toggle).hide();
            }
        });
    }

    grupe.forEach(function (grupa) {
        grupa.addEventListener('focusin', function () { zatvoriOstale(grupa); });
    });
})();

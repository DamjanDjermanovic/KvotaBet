// Tiket u izradi: dodavanje i uklanjanje parova preko AJAX-a, živi proračun
// ukupne kvote i potencijalnog dobitka i uplata tiketa.
//
// Sve iznose računa server - ovaj kod ih samo ispisuje. Razlog je što se na
// osnovu tih iznosa skida novac sa računa, pa proračun ne sme da zavisi od
// koda koji se izvršava u pregledaču i koji korisnik može da izmeni.

(function () {
    'use strict';

    var panel = document.getElementById('tiket-panel');

    // Panel postoji samo na stranici sa ponudom; na ostalim stranicama skripta
    // nema šta da radi
    if (!panel) {
        return;
    }

    var koren = panel.dataset.koren;
    var csrfToken = panel.dataset.csrf;

    var lista = document.getElementById('tiket-lista');
    var prazanTekst = document.getElementById('tiket-prazan');
    var brojStavki = document.getElementById('tiket-broj-stavki');
    var ukupnaKvota = document.getElementById('tiket-ukupna-kvota');
    var dobitak = document.getElementById('tiket-dobitak');
    var poruka = document.getElementById('tiket-poruka');
    var dugmeOcisti = document.getElementById('dugme-ocisti');

    // Ova polja postoje samo kad je prijavljen igrač - gost i operater vide
    // tiket, ali nemaju formu za uplatu
    var forma = document.getElementById('forma-tiket');
    var poljeUlog = document.getElementById('tiket-ulog');
    var greskaUloga = document.getElementById('tiket-ulog-greska');
    var stanjeRacuna = document.getElementById('tiket-stanje');
    var dugmeUplati = document.getElementById('dugme-uplati');

    var tajmerProracuna = null;

    // Trenutno uneti ulog se šalje uz svaki zahtev da bi server u odgovoru
    // odmah vratio i preračunat potencijalni dobitak
    function trenutniUlog() {
        return poljeUlog ? poljeUlog.value : '';
    }

    // Šalje POST zahtev ka AJAX endpointu i vraća isparsiran JSON odgovor.
    // Greške mreže se pretvaraju u isti oblik odgovora kao i greške servera,
    // da pozivalac ne mora posebno da ih obrađuje.
    function posalji(fajl, podaci) {
        var telo = new FormData();
        telo.append('csrf_token', csrfToken);

        Object.keys(podaci || {}).forEach(function (kljuc) {
            telo.append(kljuc, podaci[kljuc]);
        });

        return fetch(koren + '/ajax/' + fajl, { method: 'POST', body: telo })
            .then(function (odgovor) {
                return odgovor.json();
            })
            .catch(function () {
                return { uspeh: false, poruka: 'Greška u komunikaciji sa serverom.' };
            });
    }

    // Ispisuje poruku iznad spiska stavki. Prazan tekst uklanja poruku.
    function prikaziPoruku(tekst, vrsta) {
        poruka.innerHTML = '';

        if (!tekst) {
            return;
        }

        var okvir = document.createElement('div');
        okvir.className = 'alert alert-' + (vrsta || 'danger') + ' m-3 py-2 small';
        okvir.textContent = tekst;
        poruka.appendChild(okvir);
    }

    // Pravi jedan red spiska stavki. Tekst se upisuje preko textContent, a ne
    // preko innerHTML - nazivi timova dolaze iz baze i ne smeju se ubaciti u
    // stranicu kao HTML.
    function napraviStavku(stavka) {
        var red = document.createElement('li');
        red.className = 'list-group-item d-flex justify-content-between align-items-start gap-2';

        var opis = document.createElement('div');

        var par = document.createElement('div');
        par.className = 'small fw-semibold';
        par.textContent = stavka.domacin + ' — ' + stavka.gost;

        var detalji = document.createElement('div');
        detalji.className = 'text-muted';
        detalji.style.fontSize = '0.75rem';
        detalji.textContent = stavka.liga + ' · ' + stavka.vreme;

        opis.appendChild(par);
        opis.appendChild(detalji);

        var desno = document.createElement('div');
        desno.className = 'd-flex align-items-center gap-2';

        var kvota = document.createElement('span');
        kvota.className = 'badge text-bg-primary';
        kvota.textContent = stavka.oznaka + ' ' + stavka.vrednost;

        var dugme = document.createElement('button');
        dugme.type = 'button';
        dugme.className = 'btn-close';
        dugme.setAttribute('aria-label', 'Ukloni par sa tiketa');
        dugme.addEventListener('click', function () {
            posalji('tiket_ukloni.php', {
                kvota_id: stavka.kvota_id,
                ulog: trenutniUlog()
            }).then(osveziPanel);
        });

        desno.appendChild(kvota);
        desno.appendChild(dugme);

        red.appendChild(opis);
        red.appendChild(desno);

        return red;
    }

    // Označava u ponudi ona dugmad čije su kvote na tiketu, da igrač vidi
    // šta je već izabrao i posle osvežavanja stranice
    function oznaciKvoteUPonudi(stavke) {
        var izabrane = stavke.map(function (stavka) {
            return String(stavka.kvota_id);
        });

        document.querySelectorAll('.dugme-kvota').forEach(function (dugme) {
            var jeIzabrana = izabrane.indexOf(dugme.dataset.kvotaId) !== -1;
            dugme.classList.toggle('active', jeIzabrana);
            dugme.setAttribute('aria-pressed', jeIzabrana ? 'true' : 'false');
        });
    }

    // Iscrtava ceo panel na osnovu stanja koje je vratio server
    function prikaziTiket(podaci) {
        lista.innerHTML = '';
        podaci.stavke.forEach(function (stavka) {
            lista.appendChild(napraviStavku(stavka));
        });

        var imaStavki = podaci.broj_stavki > 0;

        prazanTekst.classList.toggle('d-none', imaStavki);
        dugmeOcisti.classList.toggle('d-none', !imaStavki);
        brojStavki.textContent = podaci.broj_stavki;
        ukupnaKvota.textContent = imaStavki ? podaci.ukupna_kvota : '—';
        dobitak.textContent = imaStavki ? podaci.potencijalni_dobitak : '—';

        if (stanjeRacuna) {
            stanjeRacuna.textContent = podaci.stanje_racuna || '—';
        }
        if (greskaUloga) {
            greskaUloga.textContent = podaci.ulog_greska || '';
        }
        if (dugmeUplati) {
            dugmeUplati.disabled = !podaci.moze_uplata;
        }

        oznaciKvoteUPonudi(podaci.stavke);
    }

    // Jedinstvena obrada svakog odgovora sa servera: prvo se osveži panel, pa
    // se prikaže poruka ako je ima
    function osveziPanel(odgovor) {
        if (Array.isArray(odgovor.stavke)) {
            prikaziTiket(odgovor);
        }

        if (odgovor.poruka) {
            prikaziPoruku(odgovor.poruka, odgovor.uspeh ? 'success' : 'danger');
        } else if (odgovor.uklonjeno > 0) {
            prikaziPoruku(
                'Uklonjeno parova sa tiketa jer više nisu u ponudi: ' + odgovor.uklonjeno,
                'warning'
            );
        } else {
            prikaziPoruku('');
        }
    }

    // Klik na kvotu u ponudi. Ako je kvota već na tiketu, isti klik je skida -
    // tako jedno dugme služi i za dodavanje i za uklanjanje.
    document.addEventListener('click', function (dogadjaj) {
        var dugme = dogadjaj.target.closest('.dugme-kvota');

        if (!dugme) {
            return;
        }

        var fajl = dugme.classList.contains('active') ? 'tiket_ukloni.php' : 'tiket_dodaj.php';

        dugme.disabled = true;
        posalji(fajl, {
            kvota_id: dugme.dataset.kvotaId,
            ulog: trenutniUlog()
        }).then(function (odgovor) {
            dugme.disabled = false;
            osveziPanel(odgovor);
        });
    });

    dugmeOcisti.addEventListener('click', function () {
        posalji('tiket_ocisti.php', { ulog: trenutniUlog() }).then(osveziPanel);
    });

    // Živi proračun dok igrač kuca ulog. Zahtev se odlaže 300 ms posle
    // poslednjeg pritiska tastera, da se ne šalje jedan zahtev po slovu.
    if (poljeUlog) {
        poljeUlog.addEventListener('input', function () {
            clearTimeout(tajmerProracuna);

            tajmerProracuna = setTimeout(function () {
                posalji('tiket_proracun.php', { ulog: poljeUlog.value }).then(osveziPanel);
            }, 300);
        });
    }

    // Uplata tiketa. Klijentska provera forme je samo udobnost - server ponovo
    // proverava ulog, stavke i stanje računa pre nego što bilo šta upiše.
    if (forma) {
        forma.addEventListener('submit', function (dogadjaj) {
            dogadjaj.preventDefault();

            if (!forma.checkValidity()) {
                forma.classList.add('was-validated');
                return;
            }

            dugmeUplati.disabled = true;
            posalji('tiket_uplati.php', { ulog: poljeUlog.value }).then(osveziPanel);
        });
    }

    // Pri učitavanju stranice tiket se povlači iz sesije - preživeo je i
    // osvežavanje stranice i prelazak na drugu ligu u ponudi
    posalji('tiket_sadrzaj.php', { ulog: trenutniUlog() }).then(osveziPanel);
})();

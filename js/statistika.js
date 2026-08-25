// Grafikoni na admin/statistika.php, preko Chart.js sa CDN-a.
//
// Skripta ne zna ništa o bazi - sve podatke čita iz data- atributa platna,
// koje je ispisao PHP. Boje se ne pišu ovde nego se čitaju iz css/style.css
// preko naziva promenljivih, da paleta i dalje ima samo jedno mesto istine.

(function () {
    'use strict';

    var poDanu = document.getElementById('grafikon-po-danu');
    var poUlogama = document.getElementById('grafikon-po-ulogama');

    if (!poDanu && !poUlogama) {
        return;
    }

    // Ako CDN nije dostupan (npr. odbrana projekta bez interneta), grafikoni se
    // preskaču. Brojke i tabele ispod njih su i dalje tu, pa stranica ostaje
    // upotrebljiva - zato se ovde samo ostavlja poruka, a ne prazno platno.
    if (typeof Chart === 'undefined') {
        [poDanu, poUlogama].forEach(function (platno) {
            if (!platno) {
                return;
            }
            var poruka = document.createElement('p');
            poruka.className = 'text-muted small mb-0';
            poruka.textContent = 'Grafikon nije prikazan jer biblioteka Chart.js nije učitana. Podaci su u tabelama ispod.';
            platno.parentNode.replaceChild(poruka, platno);
        });
        return;
    }

    var stilovi = getComputedStyle(document.documentElement);

    // Vraća vrednost boje iz palete u css/style.css po nazivu promenljive
    function boja(naziv) {
        return stilovi.getPropertyValue(naziv).trim();
    }

    // Ista boja sa providnošću. Osmocifreni zapis (#rrggbbaa) je najkraći način
    // da se iz heks vrednosti dobije providna varijanta bez ručnog razlaganja
    // na kanale; '1a' je otprilike 10 posto.
    function providna(heks, alfa) {
        return heks + alfa;
    }

    var bojaLinije = boja('--plava-500');
    var bojaMreze = boja('--ivica');
    var bojaTeksta = boja('--prigusen');
    var bojaPodloge = boja('--karta');
    var bojaNaslova = boja('--mornar-800');

    // Zajedničke postavke: tanke linije, mreža koja se ne nameće i isti font
    // kao ostatak stranice
    Chart.defaults.font.family = "'Barlow', system-ui, sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.color = bojaTeksta;

    // Čita niz iz data- atributa. Ako atribut nedostaje ili nije ispravan JSON,
    // vraća prazan niz umesto da obori celu skriptu.
    function procitajNiz(element, naziv) {
        try {
            var vrednost = JSON.parse(element.dataset[naziv] || '[]');
            return Array.isArray(vrednost) ? vrednost : [];
        } catch (e) {
            return [];
        }
    }

    // -----------------------------------------------------------------------
    // Linijski grafikon: pristupi po danu
    // -----------------------------------------------------------------------
    if (poDanu) {
        var dani = procitajNiz(poDanu, 'dani');
        var vrednosti = procitajNiz(poDanu, 'vrednosti');

        // Dodatak koji ispisuje vrednost iznad poslednje tačke i iznad najviše.
        // Namerno se ne ispisuje broj iznad svake tačke - četrnaest brojeva
        // preko linije se ne čita, a osa i oblak sa podacima ionako nose ostalo.
        var oznakeTacaka = {
            id: 'oznakeTacaka',
            afterDatasetsDraw: function (grafikon) {
                var tacke = grafikon.getDatasetMeta(0).data;

                if (!tacke.length) {
                    return;
                }

                var najvisi = vrednosti.indexOf(Math.max.apply(null, vrednosti));
                var zaIspis = [tacke.length - 1];

                // Najviša tačka se označava samo ako nije već poslednja
                if (najvisi !== tacke.length - 1) {
                    zaIspis.push(najvisi);
                }

                var crtac = grafikon.ctx;
                crtac.save();
                crtac.font = "600 12px 'Barlow', system-ui, sans-serif";
                crtac.fillStyle = bojaNaslova;
                crtac.textAlign = 'center';
                crtac.textBaseline = 'bottom';

                zaIspis.forEach(function (redniBroj) {
                    var tacka = tacke[redniBroj];

                    if (tacka) {
                        crtac.fillText(String(vrednosti[redniBroj]), tacka.x, tacka.y - 8);
                    }
                });

                crtac.restore();
            }
        };

        new Chart(poDanu, {
            type: 'line',
            data: {
                labels: dani,
                datasets: [{
                    label: 'Pristupa',
                    data: vrednosti,
                    borderColor: bojaLinije,
                    borderWidth: 2,
                    // Blaga providna popuna ispod linije - naglašava količinu,
                    // a ne guši samu liniju
                    backgroundColor: providna(bojaLinije, '1a'),
                    fill: true,
                    tension: 0.3,
                    pointBackgroundColor: bojaLinije,
                    // Beli prsten oko tačke, da se tačke ne stope tamo gde su blizu
                    pointBorderColor: bojaPodloge,
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                // Oblak sa podacima se pojavljuje na ceo dan, ne samo kad se
                // pokazivač pogodi u tačku od 8 piksela
                interaction: { mode: 'index', intersect: false },
                layout: { padding: { top: 18 } },
                plugins: {
                    // Jedna serija ne treba legendu - naslov kartice već kaže šta se crta
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: boja('--mornar-900'),
                        padding: 10,
                        displayColors: false,
                        callbacks: {
                            label: function (stavka) {
                                return stavka.parsed.y + ' pristupa';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        // Uspravne linije mreže ovde ništa ne pomažu - dan se čita sa ose
                        grid: { display: false },
                        border: { color: bojaMreze },
                        ticks: { color: bojaTeksta, maxRotation: 0, autoSkipPadding: 12 }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: bojaMreze, drawTicks: false },
                        border: { display: false },
                        ticks: {
                            color: bojaTeksta,
                            padding: 8,
                            // Broj pristupa je ceo broj, pa se decimale na osi izbacuju
                            precision: 0
                        }
                    }
                }
            },
            plugins: [oznakeTacaka]
        });
    }

    // -----------------------------------------------------------------------
    // Kružni grafikon: raspodela pristupa po ulogama
    // -----------------------------------------------------------------------
    if (poUlogama) {
        var oznake = procitajNiz(poUlogama, 'oznake');
        var brojevi = procitajNiz(poUlogama, 'vrednosti');

        // PHP šalje nazive promenljivih iz palete, ne gotove boje
        var bojeKriski = procitajNiz(poUlogama, 'boje').map(boja);
        var ukupno = brojevi.reduce(function (zbir, broj) {
            return zbir + broj;
        }, 0);

        new Chart(poUlogama, {
            type: 'doughnut',
            data: {
                labels: oznake,
                datasets: [{
                    data: brojevi,
                    backgroundColor: bojeKriski,
                    // Razmak u boji podloge razdvaja susedne kriške; obris u
                    // nekoj trećoj boji bi dodao mastilo koje nije podatak
                    borderColor: bojaPodloge,
                    borderWidth: 2,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '58%',
                plugins: {
                    // Legenda je ispisana u HTML-u, zajedno sa brojem i udelom
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: boja('--mornar-900'),
                        padding: 10,
                        callbacks: {
                            label: function (stavka) {
                                var udeo = ukupno > 0 ? Math.round(stavka.parsed / ukupno * 100) : 0;
                                return ' ' + stavka.label + ': ' + stavka.parsed + ' (' + udeo + '%)';
                            }
                        }
                    }
                }
            }
        });
    }
})();

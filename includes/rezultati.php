<?php
// Obrada rezultata događaja i automatsko rešavanje tiketa.
// Ovde se sprovode poslovna pravila 7 (ocenjivanje stavki i statusa tiketa),
// 8 (svaka promena stanja računa ostavlja zapis u tabeli transakcija) i
// 9 (sve u jednoj transakciji baze).
//
// Modul namerno ne uključuje autentifikaciju - ne dira sesiju, pa se može
// pozvati i iz skripte za testiranje, a proveru prava radi stranica koja ga zove.

require_once __DIR__ . '/../config/baza.php';
require_once __DIR__ . '/funkcije.php';
require_once __DIR__ . '/log.php';

// Ocenjuje da li je tip igre pogođen prema unetom rezultatu.
//
// Vraća true (pogođen), false (promašen) ili null ako sistem ne zna da oceni
// tu oznaku. Null je bitan: ako operater kroz šifarnik doda novi tip igre, a
// ovde se ne dopiše pravilo, obrada se prekida umesto da se stavka tiho
// proglasi promašenom - inače bi tiketi bili nepravedno oboreni.
//
// Za košarku i tenis nerešen ishod ne postoji (igra se do pobednika), pa se
// oznake '1' i '2' porede isto kao u fudbalu.
function tip_igre_pogodjen(string $oznaka, int $domacin, int $gost): ?bool
{
    return match ($oznaka) {
        '1'   => $domacin > $gost,
        'X'   => $domacin === $gost,
        '2'   => $gost > $domacin,
        '1X'  => $domacin >= $gost,
        '12'  => $domacin !== $gost,
        'X2'  => $gost >= $domacin,
        'GG'  => $domacin > 0 && $gost > 0,
        '0-2' => ($domacin + $gost) <= 2,
        '3+'  => ($domacin + $gost) >= 3,
        default => null,
    };
}

// Spisak oznaka koje tip_igre_pogodjen() ume da oceni. Koristi ga operaterski
// panel da upozori kad se unese oznaka za koju automatska obrada rezultata ne
// bi znala šta da radi.
function poznate_oznake_tipova(): array
{
    return ['1', 'X', '2', '1X', '12', 'X2', 'GG', '0-2', '3+'];
}

// Uvećava stanje računa i obavezno upisuje prateći zapis u tabelu transakcija
// (poslovno pravilo 8). Očekuje da je transakcija baze već otvorena i da je
// pozivalac zaključao šta treba - sama ne otvara ni ne zatvara transakciju.
// Vraća novo stanje računa kao string, onako kako ga je baza izračunala.
function isplati_na_racun(PDO $pdo, int $korisnik_id, float $iznos, string $tip, int $tiket_id, string $opis): string
{
    // Kolona transakcija.iznos ima CHECK (iznos > 0), pa nulti "dobitak"
    // nikad ne sme da stigne dovde
    if ($iznos <= 0) {
        throw new RuntimeException('Pokušaj isplate iznosa koji nije pozitivan.');
    }

    // Red korisnika se zaključava da se dve istovremene izmene stanja ne bi
    // preklopile. Isti redosled zaključavanja (prvo događaj, pa korisnik)
    // koristi i uplata tiketa, čime se izbegava međusobno blokiranje.
    $zakljucaj = $pdo->prepare('SELECT stanje_racuna FROM korisnik WHERE id = ? FOR UPDATE');
    $zakljucaj->execute([$korisnik_id]);

    // Sabiranje računa sama baza nad DECIMAL kolonom, ne PHP nad float vrednošću
    $uvecaj = $pdo->prepare('UPDATE korisnik SET stanje_racuna = stanje_racuna + ? WHERE id = ?');
    $uvecaj->execute([za_bazu($iznos), $korisnik_id]);

    $novo = $pdo->prepare('SELECT stanje_racuna FROM korisnik WHERE id = ?');
    $novo->execute([$korisnik_id]);
    $novo_stanje = (string) $novo->fetchColumn();

    $upis = $pdo->prepare(
        'INSERT INTO transakcija (korisnik_id, tiket_id, tip, iznos, stanje_posle, opis)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $upis->execute([$korisnik_id, $tiket_id, $tip, za_bazu($iznos), $novo_stanje, $opis]);

    return $novo_stanje;
}

// Preračunava status jednog tiketa na osnovu statusa svih njegovih stavki
// (poslovno pravilo 7) i, ako je tiket rešen u korist igrača, isplaćuje mu novac.
//
// Očekuje otvorenu transakciju baze. Vraća ishod kao string:
// 'dobitan', 'gubitan', 'storniran', 'aktivan' (još čeka druge događaje) ili
// 'preskocen' (tiket je već bio rešen ranije).
function resi_tiket(PDO $pdo, int $tiket_id): array
{
    // Zaključavanje tiketa je ključno za idempotentnost: dva istovremena
    // zahteva ne mogu oba da vide tiket kao "aktivan" i oba da isplate dobitak
    $upit = $pdo->prepare('SELECT id, korisnik_id, broj_tiketa, ulog, status FROM tiket WHERE id = ? FOR UPDATE');
    $upit->execute([$tiket_id]);
    $tiket = $upit->fetch();

    // Samo aktivan tiket se rešava. Tiket koji je već dobitan, gubitan ili
    // storniran je konačan i više se ne dira - to je druga brana protiv
    // dvostruke isplate.
    if (!$tiket || $tiket['status'] !== 'aktivan') {
        return ['ishod' => 'preskocen', 'isplaceno' => 0.0];
    }

    $upitStavke = $pdo->prepare('SELECT status, kvota_vrednost FROM tiket_stavka WHERE tiket_id = ?');
    $upitStavke->execute([$tiket_id]);
    $stavke = $upitStavke->fetchAll();

    $ima_promasenih = false;
    $ima_na_cekanju = false;
    $ima_pogodjenih = false;
    $efektivna_kvota = 1.0;

    foreach ($stavke as $stavka) {
        switch ($stavka['status']) {
            case 'promasen':
                $ima_promasenih = true;
                break;
            case 'ceka':
                $ima_na_cekanju = true;
                break;
            case 'pogodjen':
                $ima_pogodjenih = true;
                $efektivna_kvota *= (float) $stavka['kvota_vrednost'];
                break;
            case 'storniran':
                // Stavka sa otkazanog događaja ulazi u proračun kao kvota 1.00,
                // dakle neutralno - ne obara tiket, ali ni ne uvećava dobitak
                break;
        }
    }

    $zavrsi = $pdo->prepare('UPDATE tiket SET status = ?, isplaceno = ?, datum_obrade = NOW() WHERE id = ?');

    // Poslovno pravilo 7: jedna promašena stavka obara ceo tiket, bez obzira
    // na to što ostale stavke možda još čekaju svoje događaje
    if ($ima_promasenih) {
        $zavrsi->execute(['gubitan', za_bazu(0), $tiket_id]);
        return ['ishod' => 'gubitan', 'isplaceno' => 0.0];
    }

    // Nema promašenih, ali neke stavke još čekaju druge događaje - tiket ostaje
    // aktivan i biće ponovo razmotren kad se obradi sledeći događaj
    if ($ima_na_cekanju) {
        return ['ishod' => 'aktivan', 'isplaceno' => 0.0];
    }

    // Sve stavke su stornirane (svi događaji otkazani) - igraču se vraća ulog
    if (!$ima_pogodjenih) {
        $ulog = round((float) $tiket['ulog'], 2);
        isplati_na_racun(
            $pdo,
            (int) $tiket['korisnik_id'],
            $ulog,
            'storno',
            $tiket_id,
            'Storniran tiket ' . $tiket['broj_tiketa'] . ' (otkazan događaj)'
        );
        $zavrsi->execute(['storniran', za_bazu($ulog), $tiket_id]);

        return ['ishod' => 'storniran', 'isplaceno' => $ulog];
    }

    // Poslovno pravilo 7: sve stavke pogođene -> tiket je dobitan i dobitak
    // se uplaćuje na račun. Kvota se zaokružuje na dve decimale pre množenja,
    // isto kao pri uplati tiketa, da se prikazani i isplaćeni iznos poklope.
    $efektivna_kvota = round($efektivna_kvota, 2);
    $dobitak = round((float) $tiket['ulog'] * $efektivna_kvota, 2);

    isplati_na_racun(
        $pdo,
        (int) $tiket['korisnik_id'],
        $dobitak,
        'dobitak',
        $tiket_id,
        'Dobitak po tiketu ' . $tiket['broj_tiketa']
    );
    $zavrsi->execute(['dobitan', za_bazu($dobitak), $tiket_id]);

    return ['ishod' => 'dobitan', 'isplaceno' => $dobitak];
}

// Obrađuje jedan događaj: upisuje rezultat (ili ga otkazuje), ocenjuje sve
// stavke tiketa vezane za njega i preračunava status tih tiketa.
//
// Ceo posao je u jednoj transakciji baze (poslovno pravilo 9) - ili prođe sve
// ili ništa. Obrada je idempotentna: već obrađen događaj se odbija, a već
// rešeni tiketi se preskaču, pa dvostruko slanje forme ne može da isplati
// dobitak dva puta.
//
// $novi_status je 'zavrsen' (uz rezultat) ili 'otkazan' (bez rezultata).
// Vraća izveštaj o obradi. Poslovne greške baca kao RuntimeException.
function obradi_dogadjaj(PDO $pdo, int $dogadjaj_id, string $novi_status, ?int $golova_domacin, ?int $golova_gost): array
{
    if (!in_array($novi_status, ['zavrsen', 'otkazan'], true)) {
        throw new RuntimeException('Neispravna vrsta obrade događaja.');
    }

    $pdo->beginTransaction();

    try {
        // --- 1. Zaključavanje događaja ---
        // Ovo je prva i najvažnija brana idempotentnosti. Dva istovremena
        // zahteva se ovde serijalizuju: drugi čeka da prvi završi, pa tek onda
        // čita status - i vidi da je događaj već obrađen.
        $upit = $pdo->prepare('SELECT id, status FROM dogadjaj WHERE id = ? FOR UPDATE');
        $upit->execute([$dogadjaj_id]);
        $dogadjaj = $upit->fetch();

        if (!$dogadjaj) {
            throw new RuntimeException('Događaj nije pronađen.');
        }

        if (in_array($dogadjaj['status'], ['zavrsen', 'otkazan'], true)) {
            throw new RuntimeException(
                'Ovaj događaj je već obrađen. Ponovna obrada nije moguća jer bi dobici bili isplaćeni dva puta.'
            );
        }

        // --- 2. Provera rezultata ---
        if ($novi_status === 'zavrsen') {
            if ($golova_domacin === null || $golova_gost === null) {
                throw new RuntimeException('Unesite rezultat za oba tima.');
            }
            // Kolone golova su TINYINT UNSIGNED (0-255)
            if ($golova_domacin < 0 || $golova_gost < 0 || $golova_domacin > 255 || $golova_gost > 255) {
                throw new RuntimeException('Rezultat mora biti broj između 0 i 255.');
            }
        } else {
            $golova_domacin = null;
            $golova_gost = null;
        }

        // --- 3. Stavke tiketa koje čekaju ovaj događaj ---
        // Prvo se pokupe kvote događaja, pa se stavke zaključavaju bez JOIN-a,
        // da zaključavanje ne bi zahvatilo i redove šifarnika (tip_igre)
        $upitKvota = $pdo->prepare('SELECT id, tip_igre_id FROM kvota WHERE dogadjaj_id = ?');
        $upitKvota->execute([$dogadjaj_id]);
        $kvote = $upitKvota->fetchAll();

        $stavke = [];

        if (!empty($kvote)) {
            $oznake_po_kvoti = [];
            $upitOznaka = $pdo->prepare('SELECT oznaka FROM tip_igre WHERE id = ?');

            foreach ($kvote as $kvota) {
                $upitOznaka->execute([(int) $kvota['tip_igre_id']]);
                $oznake_po_kvoti[(int) $kvota['id']] = (string) $upitOznaka->fetchColumn();
            }

            $mesta = implode(',', array_fill(0, count($kvote), '?'));
            $upitStavke = $pdo->prepare(
                "SELECT id, tiket_id, kvota_id FROM tiket_stavka
                 WHERE kvota_id IN ($mesta) AND status = 'ceka'
                 FOR UPDATE"
            );
            $upitStavke->execute(array_column($kvote, 'id'));

            foreach ($upitStavke->fetchAll() as $stavka) {
                $stavka['oznaka'] = $oznake_po_kvoti[(int) $stavka['kvota_id']] ?? '';
                $stavke[] = $stavka;
            }
        }

        // --- 4. Provera da sistem ume da oceni sve tipove igre ---
        // Namerno pre bilo kakvog upisa: ako makar jedan tip igre nije poznat,
        // ništa se ne menja i operater dobija jasnu poruku
        if ($novi_status === 'zavrsen') {
            foreach ($stavke as $stavka) {
                if (tip_igre_pogodjen($stavka['oznaka'], $golova_domacin, $golova_gost) === null) {
                    throw new RuntimeException(
                        'Sistem ne zna da oceni tip igre "' . $stavka['oznaka'] . '", pa je obrada prekinuta. '
                        . 'Dopunite funkciju tip_igre_pogodjen() u includes/rezultati.php.'
                    );
                }
            }
        }

        // --- 5. Ocenjivanje stavki ---
        $azuriraj = $pdo->prepare('UPDATE tiket_stavka SET status = ? WHERE id = ?');
        $pogodjenih = 0;
        $promasenih = 0;
        $storniranih_stavki = 0;
        $tiketi_za_proveru = [];

        foreach ($stavke as $stavka) {
            if ($novi_status === 'otkazan') {
                $status_stavke = 'storniran';
                $storniranih_stavki++;
            } elseif (tip_igre_pogodjen($stavka['oznaka'], $golova_domacin, $golova_gost)) {
                $status_stavke = 'pogodjen';
                $pogodjenih++;
            } else {
                $status_stavke = 'promasen';
                $promasenih++;
            }

            $azuriraj->execute([$status_stavke, (int) $stavka['id']]);
            $tiketi_za_proveru[(int) $stavka['tiket_id']] = true;
        }

        // --- 6. Preračunavanje statusa pogođenih tiketa ---
        $izvestaj = [
            'stavki'             => count($stavke),
            'pogodjenih'         => $pogodjenih,
            'promasenih'         => $promasenih,
            'storniranih_stavki' => $storniranih_stavki,
            'dobitnih'           => 0,
            'gubitnih'           => 0,
            'storniranih'        => 0,
            'jos_aktivnih'       => 0,
            'ukupno_isplaceno'   => 0.0,
        ];

        foreach (array_keys($tiketi_za_proveru) as $tiket_id) {
            $rezultat = resi_tiket($pdo, $tiket_id);

            switch ($rezultat['ishod']) {
                case 'dobitan':    $izvestaj['dobitnih']++; break;
                case 'gubitan':    $izvestaj['gubitnih']++; break;
                case 'storniran':  $izvestaj['storniranih']++; break;
                case 'aktivan':    $izvestaj['jos_aktivnih']++; break;
            }

            $izvestaj['ukupno_isplaceno'] += $rezultat['isplaceno'];
        }

        // --- 7. Upis rezultata i statusa događaja ---
        $zavrsi = $pdo->prepare(
            'UPDATE dogadjaj SET status = ?, golova_domacin = ?, golova_gost = ? WHERE id = ?'
        );
        $zavrsi->execute([$novi_status, $golova_domacin, $golova_gost, $dogadjaj_id]);

        $pdo->commit();

        return $izvestaj;
    } catch (Throwable $e) {
        // Bilo kakav problem poništava ceo posao - ni jedan tiket ne sme da
        // ostane napola obrađen, ni jedna isplata bez svog zapisa u transakciji
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

# Dnevnik izrade

Kratak zapis posle svake završene faze iz CLAUDE.md (redosled izrade).

---

## Faza 6 — Igračev panel (2026-08-25)

Dodato:
- `igrac/uplata.php` — uplata virtuelnih sredstava (MIN_UPLATA/MAX_UPLATA u
  `config/konfiguracija.php`), red korisnika se zaključava (`FOR UPDATE`) dok
  traje transakcija, svaka uplata upisuje zapis u `transakcija` (pravilo 8).
- `igrac/tiketi.php` — istorija tiketa sa detaljima stavki po tiketu
  (Bootstrap collapse, bez AJAX-a).
- `igrac/transakcije.php` — istorija svih transakcija računa.
- `igrac/profil.php` — tri odvojene forme (podaci, lozinka, dokument) na
  istoj stranici, razlikuju se preko skrivenog polja `akcija`.
- `includes/dokumenti.php` — `obradi_upload_dokumenta()`: provera veličine,
  ekstenzije i **stvarnog** MIME tipa preko `finfo` (ne verovati imenu fajla
  ni `Content-Type` koji šalje klijent), nasumično ime fajla.
- `uploads/dokumenta/.htaccess` — `Require all denied` + gašenje PHP engine-a;
  dokumenti se mogu preuzeti isključivo kroz zaštićen PHP skript (dolazi u
  fazi 7, `operater/dokument_preuzmi.php`). Potvrđeno da `AllowOverride All`
  važi za `htdocs` u XAMPP konfiguraciji, pa `.htaccess` stvarno radi.
- `includes/log.php` — `evidentiraj_pristup_u_bazi()`: pored tekstualnog
  loga, svaki pristup se sada upisuje i u tabelu `pristup_log` (poziva se iz
  `zaglavlje.php`), da bi admin statistika u fazi 9 imala šta da upituje.
- `includes/funkcije.php` — `postavi_poruku()` / `prikazi_poruke()`: flash
  poruke kroz sesiju, koristi se u svim CRUD stranicama umesto ručnog
  prosleđivanja grešaka kroz GET parametre.

Testirano preko pravog HTTP-a (PHP dev server + MariaDB): granice uploga
(min/max), izmena profila i provera jedinstvenosti email-a, promena lozinke
(pogrešna trenutna lozinka odbijena), upload dokumenta — validan JPG prihvaćen,
tekstualni fajl preimenovan u `.pdf` i binarni fajl preimenovan u `.jpg`
ispravno odbijeni jer `finfo` čita stvarni sadržaj, ne ekstenziju.

---

## Faza 7 — Operaterski panel (2026-08-25)

Dodato:
- `operater/sportovi.php`, `lige.php`, `timovi.php`, `dogadjaji.php`, `kvote.php`
  — CRUD nad tabelama `sport`, `liga`, `tim`, `dogadjaj`, `kvota`. Svaka
  stranica: forma za dodavanje/izmenu (jedna forma, `id` u skrivenom polju
  razlikuje izmenu od unosa) + tabela sa "Izmeni"/"Obriši", flash poruke kroz
  `postavi_poruku()`/`prikazi_poruke()`.
- `dogadjaji.php` ne traži ligu kao poseban padajući meni - izvodi se iz
  zajedničke lige oba izabrana tima (server odbija ako domaćin i gost nisu iz
  iste lige). `js/operater.js` filtrira padajuće menije timova po ligi kao
  pomoć pri unosu (bez AJAX-a), server ionako ponovo proverava.
- `operater/kvote.php` — kvote uvek u kontekstu jednog događaja
  (`?dogadjaj_id=`), ponuđeni tipovi igre ograničeni na sport tog događaja i
  na one koji već nemaju kvotu. Izmena kvote ne dira `tiket_stavka.kvota_vrednost`
  već uplaćenih tiketa (pravilo 5).
- `operater/dokumenta.php` + `operater/dokument_preuzmi.php` — pregled i
  odobravanje/odbijanje verifikacionih dokumenata; odobravanje postavlja
  `korisnik.verifikovan = 1` u istoj transakciji baze. Fajl se poslužuje
  isključivo kroz `dokument_preuzmi.php` (uploads/ je zaključan), koji prvo
  proverava ulogu.
- Sve operaterske stranice dozvoljavaju i `admin` ulogu (`zahtevaj_ulogu('operater','admin')`),
  u skladu sa hijerarhijom prava iz CLAUDE.md.

Otkriveno i ispravljeno tokom pisanja: prvobitna tabela u `operater/kvote.php`
imala je jedan `<form>` razvučen preko tri `<td>` ćelije, što je nevažeći HTML
(forma ne sme da premošćuje granice ćelija tabele) - ispravljeno spajanjem
polja za vrednost/aktivnost u jednu ćeliju sa sopstvenom, potpuno ugnježdenom
formom.

Testirano preko pravog HTTP-a sa nalogom operatera: uloga igrača ne može da
pristupi operaterskim stranicama (redirekcija); događaj sa timovima iz dve
različite lige je odbijen; isti tim kao domaćin i gost je odbijen; dupla
kvota za isti tip igre na istom događaju je odbijena; brisanje kvote koja je
već upotrebljena na plaćenom tiketu je odbijeno FK ograničenjem baze i lepo
prevedeno u poruku; direktan pokušaj ponovne obrade već odobrenog dokumenta
(zaobilazeći UI) je odbijen na serveru; igrač ne može da preuzme tuđi
verifikacioni dokument.

---

## Faza 8 — Obrada rezultata i rešavanje tiketa (2026-08-25)

Dodato:
- `includes/rezultati.php` — jezgro faze:
  - `tip_igre_pogodjen()` ocenjuje oznake `1, X, 2, 1X, 12, X2, GG, 0-2, 3+`.
    Za nepoznatu oznaku vraća `null`, a ne `false` — obrada se tada prekida
    umesto da se stavka tiho proglasi promašenom i nepravedno obori tikete.
  - `resi_tiket()` preračunava status tiketa po pravilu 7.
  - `isplati_na_racun()` uvećava stanje i obavezno upisuje zapis u
    `transakcija` (pravilo 8).
  - `obradi_dogadjaj()` radi sve u jednoj transakciji baze (pravilo 9).
- `operater/rezultati.php` — unos rezultata i otkazivanje događaja, sa
  spiskom onoga što čeka obradu i onoga što je obrađeno.
- `baza/test_rezultati.sql` — šest tiketa u poznatom stanju sa tabelom
  očekivanih ishoda u komentaru. **Skripta briše sve postojeće tikete i
  transakcije** da bi početno stanje bilo tačno poznato.

### Idempotentnost — tri odvojene brane
1. `SELECT ... FROM dogadjaj WHERE id = ? FOR UPDATE` na početku transakcije.
   Istovremeni zahtevi se serijalizuju; drugi po redu pročita status tek kad
   prvi commit-uje i vidi da je događaj već obrađen.
2. Već obrađen događaj (`zavrsen`/`otkazan`) se odbija sa jasnom porukom.
3. `resi_tiket()` zaključava tiket i preskače sve što nije `aktivan`, pa
   tiket koji je jednom rešen ne može biti isplaćen drugi put.

Uz to, stranica posle obrade uvek preusmerava (POST-redirect-GET), pa
osvežavanje ne šalje formu ponovo.

### Odluke koje vredi zapamtiti
- **Stornirana stavka ulazi u proračun kao kvota 1.00**, a ne kao automatski
  povraćaj celog tiketa. Tiket sa jednim pogođenim i jednim storniranim parom
  je dobitan po umanjenoj kvoti (vidi T-TEST-05: originalno 3.41, stvarno
  isplaćeno po 1.75). Ulog se u celosti vraća samo kad su **sve** stavke
  stornirane. Da je otkazan događaj automatski vraćao ulog, tiket koji je već
  promašio na drugom događaju dobio bi novac nazad.
- `tiket.ukupna_kvota` ostaje istorijski zapis dogovorenog pri uplati;
  stvarno isplaćeni iznos ide u `tiket.isplaceno`.
- Ispravka pogrešno unetog rezultata **nije** podržana. Vraćanje već
  isplaćenih dobitaka je zaseban problem i namerno nije rešavan ovde.

### Ispravljeno u ranijim fazama
`operater/dogadjaji.php` je iz faze 7 dozvoljavao ručno postavljanje statusa
na `zavrsen` ili `otkazan`. Time je događaj mogao da završi kao obrađen a da
tiketi zauvek ostanu `aktivan` — i da ih `rezultati.php` posle odbije jer je
događaj „već obrađen". Sada su tamo dozvoljeni samo `najavljen` i `u_toku`, a
već obrađen događaj se uopšte ne može menjati ni brisati.

U `ajax/tiket_uplati.php` uvedeno je zaključavanje događaja **pre** korisnika,
istim redosledom koji koristi i obrada rezultata. Time se zatvara trka u kojoj
bi se stavka za događaj koji se upravo zaključuje provukla na tiket, a
istovremeno se izbegava i međusobno blokiranje dve transakcije.

### Testirano
Ceo scenario iz `test_rezultati.sql` je odigran preko pravog HTTP-a i ishodi
se poklapaju sa dokumentovanim: 3 dobitna, 2 gubitna, 1 storniran tiket,
ukupno isplaćeno 1163.00, stanje računa 1000.00 → 2163.00, svaka isplata sa
svojim zapisom u `transakcija` i ispravnim nizom `stanje_posle`.

Posebno provereno:
- **5 istovremenih zahteva** za obradu istog događaja → tačno **jedna**
  isplata, stanje 1175.00 umesto 1875.00 koliko bi bilo bez zaključavanja.
- Ponovni unos rezultata i pokušaj otkazivanja već završenog događaja →
  odbijeni, stanje nepromenjeno.
- Nepoznat tip igre (`HT1`) na stavci → obrada prekinuta i **potpuno**
  poništena: događaj ostao `najavljen`, bez golova, sve stavke i dalje `ceka`,
  stanje računa netaknuto.
- Zaobilaženje preko `dogadjaji.php` (postavljanje `zavrsen`, izmena obrađenog
  događaja) → odbijeno na serveru.
- Igračev panel prikazuje rešene tikete, statuse stavki i isplate.

---

## Faza 9 — Admin panel (2026-08-25)

Dodato:
- `admin/korisnici.php` — CRUD nad tabelom `korisnik`: kreiranje (bilo koje
  uloge), izmena podataka i uloge, opciona promena lozinke, brisanje.
  Blokiranje/odblokiranje je brz prekidač statusa direktno iz liste, bez
  otvaranja forme za izmenu. Admin ne može da blokira ni obriše sopstveni
  nalog (provera `$id === $moj_id`).
- `admin/uloge.php` — CRUD nad tabelom `uloga`.
- `admin/logovi.php` — čitanje `logovi/pristup.log` i `logovi/greske.log`
  preko `procitaj_log()`, izbor broja redova, noviji redovi na vrhu.
- `admin/statistika.php` — statistika pristupa iz tabele `pristup_log`
  (ukupno, danas, poslednjih 7 dana, jedinstvene IP adrese, raspodela po
  danu/ulozi/stranici, najaktivniji korisnici).

Otkriveno i ispravljeno tokom testiranja: blokiranje naloga je menjalo samo
`korisnik.status` u bazi, ali `zahtevaj_ulogu()` čita ulogu i prijavu iz
**sesije**, ne iz baze - korisnik koji je već bio prijavljen u trenutku
blokiranja je zadržavao punu funkcionalnost do isteka sesije. Provereno
direktno (postojeća sesija je posle blokiranja i dalje vraćala 200 na
`igrac/profil.php`). Ispravljeno dodavanjem `proveri_da_li_je_nalog_i_dalje_aktivan()`
u `includes/autentifikacija.php`, koja se poziva na svakom zahtevu posle
`primeni_zapamti_me()`: ako nalog prijavljenog korisnika više nije `aktivan`,
sesija se odmah uništava i korisnik se vraća na `prijava.php?blokiran=1` sa
objašnjenjem. Ponovo testirano - ista sesija je posle ove izmene odmah
dobila 302 na sledeći zahtev.

Ostalo testirano preko pravog HTTP-a: uloga `igrac` ne može na admin
stranice; blokiran korisnik ne može da se prijavi; brisanje korisnika koji
ima tikete je odbijeno FK ograničenjem (`ON DELETE RESTRICT` na
`tiket.korisnik_id`) i prevedeno u razumljivu poruku; brisanje uloge `igrac`
(u upotrebi) odbijeno, brisanje prazne test-uloge prošlo; `admin/statistika.php`
i `admin/logovi.php` prikazuju stvarne podatke nastale tokom sopstvenog
testiranja prethodnih faza.

---

## Dopuna CRUD-a do svih 13 tabela (2026-08-25)

Zahtev 6 iz CLAUDE.md traži kreiranje, čitanje, izmenu i brisanje **kroz
interfejs** nad svakom tabelom. Nedostajalo je za `tip_igre`, `tiket`,
`tiket_stavka`, `transakcija`, `dokument` i `pristup_log`.

Dodato:
- `operater/tipovi_igre.php` — CRUD nad `tip_igre`. Oznaka nije samo natpis:
  po njoj `tip_igre_pogodjen()` ocenjuje stavke, pa stranica prikazuje spisak
  oznaka koje sistem ume da oceni i upozorava kad se unese nepoznata ili kad
  se postojeća promeni.
- `admin/tiketi.php` — pregled svih tiketa, izmena statusa tiketa i statusa
  pojedinačne stavke, brisanje tiketa (stavke odlaze kaskadno) i brisanje
  pojedinačne stavke.
- `admin/transakcije.php` — pregled, ručni unos korektivne transakcije,
  izmena opisa, brisanje, plus stalna kontrola usklađenosti svih računa.
- `admin/dokumenta.php` — brisanje dokumenta koje uklanja i fajl sa diska.
- `admin/logovi.php` — dopunjen radom nad tabelom `pristup_log`: pregled,
  izmena (ispravka/anonimizacija zapisa), brisanje pojedinačnog zapisa i
  čišćenje svih starijih od N dana.
- `includes/racun.php` — `ocekivano_stanje_racuna()`, `preracunaj_stanje_racuna()`
  i `nesaglasni_racuni()`.
- `includes/log.php` — `upisi_trag()`: svaka destruktivna akcija upisuje red
  `TRAG | ip | ko | šta` u `logovi/greske.log`. Obrisani red iz baze nema ko
  da posvedoči o sebi, pa trag mora ostati van nje.

### Kako je čuvana usklađenost stanja računa
Polazna provera: ako se sve transakcije igrača odigraju redom počev od nule,
mora se dobiti tačno ono što piše u `korisnik.stanje_racuna`. Odatle:

- **Brisanje tiketa ne dira novac.** `transakcija.tiket_id` je
  `ON DELETE SET NULL`, pa zapisi o ulogu i dobitku ostaju u evidenciji i samo
  gube vezu sa tiketom. Stanje se ne menja, pa ne može ni da se raziđe.
  Posle brisanja se svejedno proverava jednakost i, ako ne bi važila, ceo
  posao se poništava.
- **Brisanje transakcije vezane za tiket se odbija uz objašnjenje** — ostao bi
  tiket bez traga o novcu. Ispravan put je brisanje celog tiketa.
- **Brisanje samostalne transakcije** preračunava ceo niz (i kolonu
  `stanje_posle` u preostalim redovima) pa upisuje novo stanje. Ako bi u nekom
  trenutku stanje otišlo ispod nule, brisanje se odbija.
- **Brisanje stavke** je dozvoljeno samo dok je tiket `aktivan`; kod obrađenog
  tiketa je po tim stavkama već obračunata isplata. Poslednja stavka se ne
  briše (pravilo 1), a posle brisanja se ukupna kvota i potencijalni dobitak
  preračunavaju iz preostalih stavki.
- **Iznos i tip transakcije se ne mogu menjati** — oni određuju stanje računa.
  Menja se samo opis; za ispravku iznosa postoji brisanje pa nova korekcija.

### Šta je usput otkriveno
Nova kontrola usklađenosti odmah je prijavila stvarnu grešku u
`baza/test_rezultati.sql` iz faze 8: skripta briše **sve** transakcije, ali je
stanje računa postavljala samo fixture igraču, pa je `testigrac` ostajao sa
1400.00 koje nijedan zapis ne objašnjava. Ispravljeno dodavanjem
`UPDATE korisnik SET stanje_racuna = 0.00` posle brisanja transakcija.

### Testirano
Preko pravog Apache-a: odbijeno brisanje transakcije vezane za tiket, brisanje
poslednje stavke i brisanje stavke sa obrađenog tiketa; korektivna transakcija
1000 → 1250 i njeno brisanje nazad na 1000 uz neprekinut niz `stanje_posle`;
brisanje tiketa uz nepromenjeno stanje i sačuvanu transakciju sa
`tiket_id = NULL`; brisanje dokumenta uklonilo i fajl sa diska; čišćenje
`pristup_log` po broju dana i pojedinačno; upozorenja pri unosu i izmeni
nepoznate oznake tipa igre. Svih devet destruktivnih akcija je zabeleženo kao
`TRAG` u `greske.log`.

---

## Faza 10 — Obrada grešaka (2026-08-25)

Dodato:
- `greska.php` — stranica za kodove 403, 404 i 500. Namerno je samostalna: ne
  uključuje `zaglavlje.php` ni bazu, jer se na nju stiže i kad baza ne radi.
  Kod čita iz `?kod=` (kad je uputi aplikacija) ili iz `REDIRECT_STATUS` (kad
  je pozove Apache).
- `.htaccess` u korenu — `ErrorDocument` za 403/404/500, `Options -Indexes` i
  zabrana direktnog pristupa `.sql`, `.log` i `.md` fajlovima.
- `includes/greske.php` — `set_exception_handler`, `set_error_handler` i
  `register_shutdown_function`. Sve što promakne pojedinačnim `try/catch`
  blokovima završi ovde: detalji idu u `greske.log`, korisnik dobija
  `greska.php`. AJAX endpointi definišu `AJAX_ZAHTEV` pre uključivanja, pa im
  i greška vrati JSON, a ne HTML koji `fetch()` ne ume da pročita.
- `config/konfiguracija.php` — `RAZVOJNI_REZIM` (podrazumevano `false`) i
  gašenje `display_errors` preko `ini_set()`. Namerno u PHP-u a ne u
  `.htaccess`: `php_flag` radi samo uz mod_php i oborio bi server tamo gde tog
  modula nema, dok `ini_set` radi u svakom okruženju.

`ErrorDocument` je proveren pod **pravim Apache-om** (privremena instanca na
zasebnom portu), pošto ugrađeni PHP server ignoriše `.htaccess`:
nepostojeća stranica daje našu 404 stranicu sa HTTP kodom 404, a `.sql`/`.log`/`.md`
i `uploads/dokumenta/` vraćaju 403 sa našom 403 stranicom.

Provereno i da ništa ne curi: stranica koja baca neuhvaćen izuzetak sa tajnim
tekstom u poruci, i stranica sa fatalnom greškom — obe daju 302 na
`greska.php?kod=500`, a u odgovoru nema ni traga od poruke, imena klase, putanje
na disku ni `SQLSTATE`. Pun tekst je uredno završio u `greske.log`. AJAX verzija
vraća `500` sa `{"uspeh":false,"poruka":"Došlo je do greške na serveru..."}`.
Sve stranice sve četiri uloge prošle su kroz proveru na `Fatal error`, `Warning`,
`Notice`, `Uncaught`, `SQLSTATE` i putanje `/opt/lampp` — nijedna ih ne prikazuje.

Time je zatvorena i praznina zabeležena posle faze 9: `zahtevaj_ulogu()` i
`operater/dokument_preuzmi.php` sada zaista vode na stranicu sa porukom
„Zabranjen pristup" umesto na nepostojeću adresu.

---

## Usklađivanje HTML-a sa novim `css/style.css` (2026-08-25)

`css/style.css` je zamenjen novim fajlom sa sopstvenim vizuelnim identitetom
(četiri plava tona, Barlow / Barlow Condensed, semantičke boje samo za ishode).
Menjano je **isključivo označavanje** — nijedno poslovno pravilo, upit ni
provera prava nisu dirani.

### Zaglavlje i podnožje
- `includes/zaglavlje.php` — `.navbar-brand` sa ikonicom, link `.preskoci` na
  vrhu `<body>` (vidljiv tek pri tabulaciji, vodi na `<main id="sadrzaj">`),
  stanje računa u `.nav-stanje`.
- Nova pomoćna funkcija `stavka_menija()` ispisuje stavku navigacije i sama
  postavlja `.active` + `aria-current="page"` na tekućoj stranici. Poređenje
  ide preko `PHP_SELF`, a ne preko naziva fajla, jer se isti naziv javlja u
  više foldera (`transakcije.php` i kod igrača i kod admina).
- Stanje računa se za `.nav-stanje` čita jednim `SELECT`-om u `zaglavlje.php`,
  u `try/catch` — ako čitanje padne, navigacija se ispiše bez iznosa umesto da
  cela stranica pukne.
- `includes/podnozje.php` — `.podnozje` sa `.napomena` da je reč o simulaciji
  sa virtuelnim novcem (stoji na svakoj strani, da nijedan ekran ne ostavi
  utisak pravog uplatnog mesta).

### Ponuda (`index.php`)
- `.liga-zaglavlje` sa `.drzava`, redovi kao `.dogadjaj-red` sa
  `.dogadjaj-vreme` (`.dan` + sat) i `.dogadjaj-timovi` (`.tim` / `.protiv`).
- Kvote su sada `.kvota` sa `.oznaka` i `.kvota-vrednost` unutra, u
  `.kvote-grupa`.
- Redovi jedne lige idu u sopstveni omotač, jer stil računa gornju ivicu i
  zaobljenje reda preko `:first-of-type` / `:last-child` — bez omotača bi
  zaobljenje palo na poslednji red cele strane, a ne na poslednji red lige.
- U upit je dodata kolona `l.drzava`, samo da bi `.drzava` imala šta da
  ispiše; ostatak upita je netaknut.
- Panel tiketa: `.tiket-panel` (bez `.card`) sa `.tiket-glava`, `.broj-parova`,
  `.tiket-zub`, `.tiket-zbir` (`.red` / `.vrednost` / `.dobitak-red`),
  `.tiket-prazan` i `.btn-uplati`.

### Tiket (`js/tiket.js`)
- Izabrana kvota nosi `.izabrana` umesto Bootstrap `.active` (i klik na već
  izabranu kvotu je i dalje skida sa tiketa).
- Stavke se grade kao `.tiket-stavka` sa `.par` / `.mec` / `.tip` i
  `.tiket-ukloni`; `<ul class="list-group">` više nije potreban.
- Novo: `osveziVrednostiKvota()` — server uz svaku stavku vraća **aktuelnu**
  kvotu, pa se dugme u ponudi ispravi i nakratko zatreperi klasom
  `.promenjena` ako je operater u međuvremenu promenio kvotu. Klasa se skida
  na `animationend`, da bi treperenje moglo da se ponovi.

### Statusi
Svi statusi idu kroz `<span class="status status-{$status}">`. Time je
otpalo pet funkcija koje su postojale samo da bi vratile naziv Bootstrap
boje — `boja_statusa()`, `boja_statusa_tiketa()`, `boja_statusa_naloga()`,
`boja_statusa_dogadjaja()` i `boja_statusa_dokumenta()`. Boju sada drži jedno
mesto (`css/style.css`), pa se prikaz tiketa, stavke, naloga i dokumenta više
ne mogu razići.

### Ostalo
- Iznosi u istorijama idu kroz `.iznos-plus` / `.iznos-minus` (`igrac/` i
  `admin/transakcije.php`, isplaćeni dobitak na tiketima).
- `admin/statistika.php` — četiri `.stat-karta` sa `.broj` i `.opis`.
- `admin/logovi.php` — `.log-ispis` (jedino mesto sa monospace fontom).
- `prijava.php` i `registracija.php` — `.auth-karta`; obe stranice sada
  uključuju i Bootstrap Icons, jer ne prolaze kroz `zaglavlje.php`. Polja
  registracije su prebačena na `col-sm-6`, pošto `.auth-karta` drži karticu
  na 26rem pa dvokolonski red na `md` više nema mesta.
- Sve prazne liste idu kroz `.prazno` sa ikonicom, naslovom i pozivom na
  akciju (npr. „Još nemate nijedan tiket → Otvori ponudu"), umesto ranijeg
  `alert alert-info`.
- Sa naslova su skinute Bootstrap klase `h3`/`h5`, koje su svojom većom
  specifičnošću gasile tipografsku skalu iz novog `style.css`.

### Testirano
Preko pravog Apache-a, sve 4 uloge (gost, igrač, operater, admin) i svih 21
stranica: HTTP 200, nijedan `Fatal error`, `Warning`, `Notice` ni `Deprecated`
u odgovoru. Provereno i da su blokovi liga u ponudi ispravno ugnežđeni (svaki
`<div>` zatvoren) i da se `.nav-stanje`, `.nav-link.active`, `.stat-karta`,
`.log-ispis`, `.auth-karta` i `.prazno` zaista pojavljuju u ispisu.

### ~~Nedovršeno — statusi bez pravila u CSS-u~~ (REŠENO u ispravkama prikaza)

_Zabeleženo ovde, rešeno u odeljku „Četiri ispravke u prikazu" — ostavljeno
radi istorije._

`css/style.css` ima pravila za `aktivan`, `blokiran`, `dobitan`, `gubitan`,
`storniran`, `ceka`, `pogodjen`, `promasen`, `odobren`, `odbijen` i
`na_cekanju`. Nema ih za dva rečnika iz šeme:

| Kolona | Vrednosti bez pravila |
|---|---|
| `dogadjaj.status` | `najavljen`, `u_toku`, `zavrsen`, `otkazan` |
| `transakcija.tip` | `uplata`, `isplata`, `ulog`, `dobitak`, `storno` |

Te oznake se ispisuju sa osnovnom klasom `.status` — dobiju oblik, verzalno
kondenzovano pismo i razmak, ali ostaju bez pozadine. Za `transakcija.tip` to
je i ispravno (tip nije ishod, a smer već nose `.iznos-plus` / `.iznos-minus`),
ali za status događaja bi trebalo dopisati četiri pravila u `style.css`.

---

## ~~Napomena — greska.php ne postoji~~ (REŠENO u fazi 10)

_Zabeleženo posle faze 9, rešeno u fazi 10 — ostavljeno radi istorije._

`zahtevaj_ulogu()` (od faze 3) i `operater/dokument_preuzmi.php` (faza 7)
preusmeravali su na `greska.php?kod=403` / `kod=404` kad pristup nije dozvoljen,
a ta stranica tada još nije postojala, pa je pokušaj pristupa vodio ka
nepostojećoj adresi. Provera prava je i tada radila ispravno (pristup je bio
blokiran), nedostajala je samo stranica na kraju preusmeravanja. Napravljena je
u fazi 10 i provereno je da oba slučaja sada prikazuju „Zabranjen pristup".

---

## Četiri ispravke u prikazu (2026-08-25)

Sitna doterivanja izgleda, bez ijedne izmene poslovne logike, proračuna ili
upita koji nešto menjaju u bazi.

### 1. Filter ponude — meniji različite širine (`index.php`, `css/style.css`)

Pregledač padajućem meniju širinu računa po najdužoj stavci, pa je meni sporta
(„Svi sportovi", „Fudbal", „Tenis") ispadao osetno uži od menija lige i sekao
duže nazive. Oba `select`-a su dobila klasu `.filter-izbor` sa
`min-width: 13rem`, pa su sada iste širine i red filtera je poravnat. Granica
je donja, ne fiksna širina: duži naziv lige i dalje sme da raširi svoj meni.

### 2. Poruka o ulogu na praznom tiketu (`js/tiket.js`)

Panel je i na praznom tiketu ispisivao „Minimalni ulog je…" ili „Nemate
dovoljno sredstava na računu" čim bi se nešto ukucalo u polje uloga — provera
koja nema smisla dok nema nijedne stavke. `prikaziTiket()` sada ispisuje
`ulog_greska` samo kad je `broj_stavki > 0`.

Namerno je ispravljen **klijent, a ne server**: `stanje_tiketa()` i dalje vraća
`ulog_greska` i uz prazan tiket, jer je to tačan opis unosa i jer serverska
provera uloga ostaje netaknuta. Uplata je i pre i posle ove izmene onemogućena
kroz `moze_uplata`, koje traži bar jednu stavku (pravilo 1) — promenjeno je
samo kada se poruka *prikazuje*.

### 3. Ikonice sportova (`baza/schema.sql`, `index.php`, `operater/sportovi.php`)

Zatečeno stanje: fudbal i košarka su delili `bi-dribbble`, a tenis je imao
`bi-circle`. `bi-dribbble` uopšte nije lopta — to je logotip sajta Dribbble
(zajednica dizajnera), pa je stajao pogrešno na sportskoj ponudi.

Ponuđena su dva rešenja; izabrano je **uklanjanje ikonice iz prikaza sporta**,
uz `bi-trophy-fill` kao vrednost u bazi. Razlog protiv toga da svi sportovi
samo dobiju `bi-trophy-fill` u prikazu: ikonica koja je ista za sve vrednosti
onoga što označava ne nosi nikakvu informaciju, a naslov sporta je ionako veliki
`<h2>` sa punim nazivom. Uz to, `.liga-zaglavlje` odmah ispod već nosi
`bi-trophy` — pehar iznad pehara bi oslabio jedino mesto gde ta ikonica nešto
znači. Bootstrap Icons nema zasebne ikonice za pojedinačne sportove, pa
smisleno razlikovanje ionako nije bilo moguće.

Kolona `sport.ikonica` i njen unos u operaterskom CRUD-u **ostaju** — briše se
samo ispis u ponudi. Da polje ne bi uređivalo nešto nevidljivo, tabela u
`operater/sportovi.php` sada uz naziv klase prikazuje i samu ikonicu, pa
operater odmah vidi šta je uneo. `placeholder` i ikonica praznog spiska na toj
stranici prebačeni su sa `bi-dribbble` na `bi-trophy-fill`.

Postojeći redovi u bazi su usklađeni sa `schema.sql`:
`UPDATE sport SET ikonica = 'bi-trophy-fill';`

### 4. Statusi događaja u CSS-u (`css/style.css`, `operater/rezultati.php`)

Dopisana su četiri pravila koja su nedostajala (vidi odeljak „Nedovršeno"
iznad): `.status-najavljen`, `.status-u_toku`, `.status-zavrsen` i
`.status-otkazan`.

Zelena i crvena se namerno ne koriste — po dogovoru iz `style.css` one znače
isključivo dobitak i gubitak, a događaj sam po sebi nije ni jedno ni drugo.
Razlikuju se plavom (u ponudi, prima se tiket), žutom (počeo, čeka se rezultat)
i neutralnom (obrađen). `otkazan` uz neutralnu boju nosi i isprekidanu ivicu,
pa se od `zavrsen` razlikuje i bez oslanjanja na boju.

Provereno gde se status događaja ispisuje — postoje tačno dva mesta i oba su
već koristila `<span class="status status-{$status}">`, pa nije trebalo ništa
menjati:

| Mesto | Šta ispisuje |
|---|---|
| `operater/dogadjaji.php:248` | status u spisku svih događaja |
| `operater/rezultati.php:235` | status u spisku obrađenih događaja |

Usput ispravljeno: u spisku događaja za obradu (`operater/rezultati.php`)
oznake „čeka rezultat" i „još nije počeo" opisuju stanje događaja, a pozajmljivale
su boje iz rečnika tiketa (`.status-ceka`, `.status-storniran`). Prebačene su na
`.status-u_toku` i `.status-najavljen`, pa je rečnik boja za događaje sada
jedinstven.

Vrednosti `transakcija.tip` i dalje ostaju bez sopstvenih pravila — kako je i
zabeleženo ranije, tip transakcije nije ishod, a smer novca već nose
`.iznos-plus` / `.iznos-minus`.

### Testirano

`php -l` nad izmenjenim PHP fajlovima i `curl` nad `index.php` preko pravog
Apache-a: HTTP 200, bez `Fatal error`/`Warning`/`Notice`/`Deprecated`, oba
`select`-a nose `.filter-izbor`, naslovi sportova se ispisuju bez ikonice
(„Fudbal", „Košarka", „Tenis"). Operaterske stranice nisu otvarane kroz
pregledač jer u bazi trenutno ne postoji nalog operatera — provera za njih je
urađena poređenjem vrednosti iz `ENUM('najavljen','u_toku','zavrsen','otkazan')`
sa imenima klasa u `style.css`.

---

## Grafikoni u statistici i sedam sitnih izmena (2026-08-25)

### 1. Chart.js u `admin/statistika.php`

Dodata su dva grafikona **iznad** postojećih brojki i tabela; nijedan
postojeći upit, broj ni tabela nije menjan.

- **Linijski** — pristupi po danu za poslednjih 14 dana. Ide preko sopstvenog
  upita (pripremljen, sa početnim danom kao parametrom), jer lista ispod
  njega pokriva 7 dana i ostaje takva. Upit vraća samo dane u kojima je bilo
  pristupa, pa se prazni dani dopunjuju nulama u PHP-u — bez toga se pad na
  nulu ne bi video kao pad, nego kao rupa u liniji.
- **Kružni** — raspodela pristupa po ulogama, sa sopstvenom legendom u HTML-u
  (naziv, broj i udeo), pa se kriška može pročitati i kad se tonovi ne
  razlikuju.

Chart.js se povezuje sa CDN-a, kao Bootstrap. Oznaka `<script>` stoji u samoj
`statistika.php`, a ne u zaglavlju, da se biblioteka učitava samo tamo gde se
koristi. Ako CDN nije dostupan (odbrana bez interneta), `js/statistika.js`
ispisuje kratku poruku umesto praznog platna — brojke i tabele su i dalje tu.

**Boje.** PHP šalje *nazive promenljivih* iz `css/style.css`
(`--plava-500`, `--mornar-700`…), a JS ih čita iz stila. Nijedna heks
vrednost se ne ponavlja u JS-u, pa paleta i dalje ima jedno mesto istine.

Uloge nisu ravnopravne kategorije nego stepenice ovlašćenja
(gost → igrac → operater → admin), pa nose jednu boju u četiri tona — što više
prava, tamniji ton. Redosled je vezan za ulogu, a ne za broj pristupa, da isti
nalog ne menja boju kad se podaci promene. Uloge koje admin naknadno doda kroz
`admin/uloge.php` skupljaju se u jednu sivu krišku „ostale uloge"; izmišljanje
novih tonova plave dalo bi boje koje se ne razlikuju.

Prvi izbor tonova (`--plava-400`, `--plava-500`, `--mornar-700`,
`--mornar-800`) je **odbačen** — izmereno je da razlika između `--mornar-700`
i `--mornar-800` iznosi svega ΔE 5.9, pa su te dve kriške bile praktično
nerazlučive. Zamenom `--mornar-800` sa `--mornar-900` razmak je porastao na
prihvatljiv, uz zadržan smer svetlo → tamno.

Za linijski grafikon (jedna serija) legenda nije potrebna — naslov kartice
kaže šta se crta; ispisuje se samo vrednost iznad poslednje i najviše tačke,
jer četrnaest brojeva preko linije niko ne čita.

Uz linijski grafikon ide i ista serija kao tabela sa klasom
`.visually-hidden`: platno je slika i čitač ekrana iz njega ne vidi ništa, a
tih 14 brojeva ne postoji ni u jednoj vidljivoj tabeli na stranici.

### 2. `.kvota.izabrana:hover` (`css/style.css`)

Pravilo **nije postojalo** u fajlu — `git status` je pokazao `css/style.css`
kao neizmenjen u odnosu na poslednji commit. Greška na koju je ukazano je
ipak stvarna i pravilo je sada dopisano.

Uzrok: `.kvota:hover` i `.kvota.izabrana` imaju **istu težinu** (po dve
klase). `.kvota.izabrana` stoji niže u fajlu i pobeđuje za boju teksta
(bela), ali `background` postavlja samo `.kvota:hover` — i to na svetlo
`#f0f7fc`. Prelaskom miša preko već izabrane kvote broj je postajao beo na
skoro belom: odnos kontrasta **1.08:1**, nečitljivo (van prelaska je 5.70:1).

Izabrana kvota sada pri prelasku potamni na `--mornar-700` (13.92:1) umesto
da posvetli, pa i dalje javlja da je dugme živo.

### 3. Prikaz lozinke (`js/lozinka.js`, 4 stranice)

Dugme sa ikonicom oka (`bi-eye` / `bi-eye-slash`) pored svakog polja za
lozinku: registracija, prijava, `igrac/profil.php`, `admin/korisnici.php`
(ukupno sedam polja).

Dugme pravi JS, a ne PHP — ako je JavaScript isključen, dugmeta nema, umesto
da stoji na formi i ne radi ništa. Skripta sama pronalazi sva polja
`input[type="password"]`, pa nijedna forma nije morala da se menja.

Dva detalja: polje se pri slanju forme vraća u skriveno stanje (da lozinka ne
ostane ispisana ako se stranica vrati sa greškom), a poruka `.invalid-feedback`
se premešta unutar novog `.input-group` uz klasu `.has-validation` — inače je
Bootstrap ne bi prikazao.

### 4. `validan_email()` (`includes/validacija.php`)

`filter_var` je zadržan kao polazna tačka i dopunjen proverama dužine
(ukupno 120 = širina kolone, lokalni deo 64 po standardu), zabranom uzastopnih
tačaka i zahtevom da domen ima bar jednu tačku.

Provereno šta `filter_var` u PHP 8.2 zaista propušta: `pera@localhost`,
`a..b@example.com` i `.pera@example.com` već odbija sam, ali **propušta**
`"a..b"@example.com` — navodnici u lokalnom delu zaobilaze zabranu uzastopnih
tačaka. To je stvarna rupa koju nove provere zatvaraju. Ostale su namerno
zadržane iako se preklapaju sa `filter_var`: ponašanje tog filtera se menjalo
kroz verzije PHP-a, a ovako pravilo stoji izričito u kodu.

Sopstveni regularni izraz za celu adresu **nije** korišćen — takvi izrazi po
pravilu odbiju i ispravne adrese (znak `+`, poddomeni, nove vrhunske domene),
što je gora greška od propuštanja neke retke neispravne. Provereno na 20
adresa: sedam ispravnih prolazi, trinaest neispravnih pada.

### 5. Ikonica sporta iz spiska (`operater/sportovi.php`, `js/operater.js`)

Slobodan unos je zamenjen padajućim menijem sa 17 ikonica, uz opis pored svake
(„Meta — pikado, streljaštvo"), jer naziv klase sam po sebi ne govori šta se
vidi. Pored menija stoji pregled izabrane ikonice: prvi prikaz ispisuje PHP,
`js/operater.js` ga menja pri svakom izboru.

Isti niz je i spisak dozvoljenih vrednosti pri **proveri na serveru** —
padajući meni je samo udobnost, a poslata vrednost se ne uzima zdravo za
gotovo. Provereno slanjem `ikonica=bi-nepostojeca-ikonica` mimo forme:
odbijeno.

Izuzetak: vrednost koja već stoji u bazi za taj sport prolazi i kad nije na
spisku (mogla je biti upisana dok je polje bilo slobodan unos). Forma je nudi
kao „postojeća, van spiska", pa izmena naziva sporta ne pada zbog zatečene
ikonice, niti je nečujno briše.

### 6. Ime i prezime obavezni samo za igrača

Ime i prezime igrača vezuju se za verifikacioni dokument i moraju se poklopiti
sa njim, pa se traže. Operater i administrator su radni nalozi — prepoznaju se
po korisničkom imenu.

Pravilo stoji na **jednom mestu**, u `ime_obavezno_za_ulogu()`
(`includes/validacija.php`), i odatle ga čitaju i provera na serveru i atribut
`required` u HTML-u, i u `admin/korisnici.php` i u `igrac/profil.php`. Uz njega
idu `validno_ime_ili_prazno()` i `ime_zadovoljava()`.

Kad su ime i prezime prazni, u prikazu se koristi korisničko ime —
`ime_za_prikaz()` u `includes/funkcije.php`. Primenjeno na dva mesta gde se
operater i admin zaista pojavljuju: spisak korisnika i „najaktivniji korisnici"
u statistici. Spiskovi dokumenata su izostavljeni jer dokumenta šalju samo
igrači, kojima je ime obavezno.

`js/korisnici.js` pali i gasi `required` pri promeni uloge — bez toga pregledač
ne bi dao da se forma pošalje za operatera sa praznim imenom. Koje uloge traže
ime stiže sa servera, kao `data-trazi-ime` na stavkama menija, da spisak uloga
i dalje postoji samo u bazi.

Uloge se sada čitaju **pre** obrade forme, a ne tek pred ispis, jer obrada mora
znati naziv izabrane uloge.

### 7. Napomena iznad forme za registraciju

Iznad forme je dodato istaknuto obaveštenje da je usluga namenjena punoletnim
licima i da je reč o simulaciji sa virtuelnim novcem. Provera 18+ je i dalje
tamo gde je i bila (i na klijentu i na serveru), ali je to provera koju
korisnik vidi tek kad pogreši.

**Uklonjena je** ranija sitna napomena ispod forme („Simulacija sa virtuelnim
novcem — registracija je dozvoljena od 18 godina."), jer je govorila isto to —
ista dva podatka dvaput na jednoj kratkoj stranici. Registracija i prijava
imaju sopstveni HTML, bez zajedničkog podnožja, pa napomena iz `podnozje.php`
na njih ionako ne stiže.

### 8. Sitnice u `admin/korisnici.php`

`autocomplete="off"` na formi, jer admin njome otvara **tuđe** naloge i ne
treba mu ponuda sopstvenih upamćenih podataka. Na polju za lozinku stoji
`autocomplete="new-password"`, jer pregledači za lozinke po pravilu ne poštuju
`off`.

Oznaka „to ste vi" premeštena je iz kolone sa akcijama ispod korisničkog imena
— govori ko je korisnik, a ne šta se sa njim može. Dobila je klasu
`.status-ja` (plava iz osnovne palete): nije ni ishod ni stanje, pa ne uzima
ni zelenu ni crvenu.

### Testirano

`php -l` nad svim izmenjenim PHP fajlovima, pa PHP dev server + MariaDB, kroz
privremeni admin nalog (obrisan po završetku, zajedno sa svim probnim
sportovima i korisnicima — u bazi su ostala samo zatečena 4 naloga):

| Provera | Ishod |
|---|---|
| `admin/statistika.php`, `admin/korisnici.php`, `operater/sportovi.php` | HTTP 200 |
| Podaci grafikona | svih 14 dana u nizu, uključujući dane bez pristupa; četiri uloge sa tonovima iz palete |
| Grafikoni iscrtani | oba, snimkom ekrana iz pregledača (Chart.js sa CDN-a) |
| Operater bez imena i prezimena | sačuvan |
| Igrač bez imena i prezimena | odbijen |
| Operater sa imenom „X" | odbijen (ako se unosi, mora biti ispravno) |
| Prikaz operatera bez imena | ispisano korisničko ime |
| `required` na formi | postoji za igrača, nema ga za operatera |
| Ikonica van spiska, poslata mimo forme | odbijena |
| Ikonica sa spiska | sačuvana |
| Zatečena ikonica van spiska pri izmeni | prihvaćena |
| `validan_email()` | 7 ispravnih prolazi, 13 neispravnih pada |

Kontrast je meren, a ne procenjivan: 1.08:1 pre ispravke `.kvota.izabrana:hover`,
13.92:1 posle. Isto i za razmak tonova u kružnom grafikonu (ΔE u OKLab
prostoru), zbog čega je jedan ton i zamenjen.

---

## Svih devet tipova igre za fudbal (2026-08-25)

Ponuda je do sada prikazivala samo 1/X/2 iako `tip_igre` u `schema.sql` ima
devet fudbalskih oznaka (1, X, 2, 1X, 12, X2, GG, 0-2, 3+) — razlog je bio prost:
`test_podaci.sql` je unosio kvotu samo za prve tri, pa ostalih šest nikad nije
imalo šta da se prikaže.

### 1. Dugme „Dodaj sve tipove" (`operater/kvote.php`)

Dodavanje devet kvota jednu po jednu kroz postojeću formu je bilo dovoljno
sporo da se u praksi preskoči. Novo dugme rešava to za ceo događaj odjednom,
u dva koraka koja odgovaraju frazi iz zahteva — „napravi kvote sa predloženim
vrednostima koje operater može da izmeni **pre** čuvanja":

1. Klik na „Dodaj sve tipove" **ne šalje ništa serveru** — samo otkriva formu
   koja je već u HTML-u, sakrivena (`d-none`), sa po jednim redom za svaki
   tip igre koji događaju nedostaje. Svaki red ima checkbox (uključen po
   default-u), naziv tipa i polje sa predloženom vrednošću. Otkrivanje radi
   `js/operater.js` (dodato uz postojeću logiku filtriranja timova i pregleda
   ikonice na istoj stranici).
2. Operater po želji menja vrednosti ili isključuje pojedine redove, pa
   pritiska „Sačuvaj predložene kvote" — tek to šalje POST (`akcija=dodaj_sve`)
   koji upisuje izabrane kvote u jednoj transakciji baze.

Predložene vrednosti dolaze iz `predlozena_kvota_za_oznaku()` — mapa oznaka
u tipičnu kvotu (npr. `'1X' => 1.30`), samo početna tačka za operatera, ne
poslovno pravilo.

**Server ne veruje formi.** Poslati `tip_igre_id` se preseca sa ponovo
učitanim spiskom tipova koji zaista nedostaju tom događaju (odbrana od toga
da je stranica u međuvremenu otvorena u drugom prozoru, ili da je neko poslao
tip igre iz drugog sporta) — nepripadajući id se tiho preskače. Ako je bilo
koja poslata vrednost manja od 1.01, **ništa se ne upisuje** — provera ide pre
prve `INSERT` naredbe, cela grupa je „sve ili ništa" (dodatno umotana u
`beginTransaction`/`commit`/`rollBack`, iako pravilo 9 iz uputstva govori o
tiketima, a ne o kvotama — ovde je primenjeno kao dobra praksa za bilo koji
upis od više redova odjednom).

Testirano preko pravog HTTP-a, kroz privremeni operaterski nalog i događaj
bez ijedne kvote: svih 9 se upiše na klik; ponovni pokušaj sa već iskorišćenim
i nepostojećim `tip_igre_id` je odbijen porukom bez upisivanja ičega; slanje
jedne neispravne vrednosti (0.50) uz jednu ispravnu odbija **obe** — ni
ispravna nije upisana. Nalog, događaj, tim i liga su posle testa obrisani.

### 2. `baza/test_podaci.sql` — devet kvota po fudbalskom događaju

Prošireno sa 3 na 9 kvota za svih šest fudbalskih događaja (`dogadjaj_id`
1-6), po šifarniku iz `schema.sql` (`tip_igre.id` 1-9 = '1','X','2','1X','12',
'X2','GG','0-2','3+'). Vrednosti su ručno birane tako da dupla šansa (1X, 12,
X2) uvek bude niža od pojedinačnih kvota koje pokriva — jedini razlog je da
ponuda ne izgleda apsurdno u pregledu, ne postoji pravilo u CLAUDE.md koje bi
to zahtevalo.

**Otkriveno usput — postojeći `test_rezultati.sql` je pukao.** Taj fajl
(fixture za fazu 8, dokumentovan sopstvenim komentarom sa očekivanim
ishodima) je pretpostavljao da `test_podaci.sql` unosi tačno 3 kvote po
fudbalskom događaju, pa je i sam upisivao kvote sa **fiksnim ID-jevima**
(1,2,3 za događaj 1; 7,8,9 za događaj 3; 10,11,12 za događaj 4) i te iste
brojeve koristio kao `tiket_stavka.kvota_id`. Sa devet kvota po događaju ta
numeracija više ne važi (događaj 2 sada zauzima baš id-jeve 10-18), pa je
učitavanje `test_rezultati.sql` posle novog `test_podaci.sql` padalo na
`Duplicate entry '10' for key 'PRIMARY'`.

Ispravljeno u `test_rezultati.sql`: `INSERT INTO kvota` više ne zadaje `id`
(baza sama dodeljuje sledeći slobodan), a `tiket_stavka.kvota_id` se sada
traži preko `(SELECT id FROM kvota WHERE dogadjaj_id = ? AND tip_igre_id =
(SELECT id FROM tip_igre WHERE sport_id = 1 AND oznaka = ?))` — isti princip
koji je fajl već koristio za `tip_igre_id` u komentaru „traži se po oznaci,
ne po pretpostavljenom ID-ju", sada dosledno primenjen i na `kvota_id`.
Fajl više nikad neće pući zbog toga koliko kvota `test_podaci.sql` upiše.

Testirano: potpuno svež `DROP DATABASE` → `schema.sql` → `test_podaci.sql` →
`test_rezultati.sql`, sve tri bez greške. Zatim odigran tačan scenario iz
komentara „OČEKIVANI ISHODI" (događaj 1 → 2:0, događaj 3 → 1:1, događaj 4 →
otkazan) — svih šest tiketa završilo tačno u dokumentovanom statusu i sa
dokumentovanim isplaćenim iznosom (T-TEST-01 dobitan/175, T-TEST-02
gubitan/0, T-TEST-03 dobitan/613, T-TEST-04 storniran/200, T-TEST-05
dobitan/175, T-TEST-06 gubitan/0), stanje računa tačno 2163.00, a ponovni
pokušaj obrade događaja 1 odbijen porukom „već obrađen".

### 3. Prva tri tipa u redu, ostatak iza „+N" (`index.php`)

Kvote iz upita već stižu sortirane po `tip_igre.id` (`ORDER BY ti.id`), pa su
prve tri uvek 1/X/2 — `array_slice($dogadjaj['kvote'], 0, 3)` i `array_slice(
..., 3)` razdvajaju vidljivo od skrivenog bez novog upita.

Skriveni deo je **već u HTML-u** pri učitavanju stranice, u `<div
class="kvote-dodatne d-none">` — dugme „+N" ga samo otkriva, bez AJAX-a
(`js/ponuda.js`, čist `classList.toggle('d-none')`). Panel je dete istog
`.dogadjaj-red` flex kontejnera kao i `.kvote-grupa`, sa `flex: 1 0 100%` —
pošto ne staje pored ostatka reda na istoj liniji, flexbox ga sam gurne u
novi red ispod (`.dogadjaj-red` je zato dobio `flex-wrap: wrap`, ranije je
imao samo `nowrap` na širim ekranima). Ovaj pristup je izabran umesto
zasebnog omotača oko celog događaja da se ne bi diralo postojeće `:first-
of-type`/`:last-child` pravilo koje računa zaobljene ivice bloka lige.

Dugme menja natpis između „+N" i „manje" i `aria-expanded` pri svakom kliku;
kvote unutar otvorenog panela su obično dugmad `.dugme-kvota`, pa ih
`js/tiket.js` (koji već sluša klik na `document`, ne na pojedinačne kvote)
tretira identično onima u vidljivom delu — dodavanje na tiket, isticanje
`.izabrana` i treperenje pri promeni vrednosti rade bez ijedne izmene u
`tiket.js`.

Testirano preko headless pregledača (Chrome, snimak ekrana pre i posle
simuliranog klika): red za Real Madrid — Barselona pokazuje 1/X/2 i dugme
„+6"; posle klika dugme postaje „manje", a ispod reda se pojavljuje isprekidanom
linijom odvojen red sa preostalih 6 kvota (1X 1.35, 12 1.45, X2 1.65, GG 1.70,
0-2 1.95, 3+ 1.85).

### 4. `tip_igre_pogodjen()` i obrada rezultata za svih devet oznaka

Provereno, **bez izmene koda** — funkcija u `includes/rezultati.php` je već
imala grane za svih devet oznaka (`match` izraz), i `obradi_dogadjaj()` je već
generička po broju/vrsti kvota na događaju (ne postoji nigde pretpostavka da
ih ima tačno 3). `operater/rezultati.php` takođe ne pravi tu pretpostavku —
samo prosleđuje rezultat golova, ne zna niti mu treba da zna koji tipovi igre
postoje.

Napravljena su dva privremena testa (nisu deo aplikacije, obrisani po
završetku):

- **Čista logika** — 6 kombinacija rezultata (2:0, 1:1, 0:3, 2:2, 0:0, 5:4) x
  9 oznaka = 54 provere, plus provera da nepoznata oznaka vraća `null` (a ne
  pogrešnu ocenu — na tome počiva odbrana iz pravila 7: obrada se prekida
  umesto da tiho proglasi stavku promašenom). Očekivanja za svaku proveru su
  ručno izvedena iz značenja oznake (npr. „GG" = oba tima daju gol), ne
  prepisana iz same funkcije — nezavisna provera, ne provera funkcije protiv
  same sebe.
- **Pun lanac kroz bazu** — privremeni događaj sa svih 9 tipova igre,
  privremeni igrač, po jedan jednonožni tiket za svaku oznaku (da se svaki
  ishod ocenjuje nezavisno, bez preplitanja pravila 7 sa više stavki na istom
  tiketu). Rezultat 2:0 provučen kroz pravi `obradi_dogadjaj()`: sve 4 tiketa
  koje su trebale da budu pogođene (1, 1X, 12, 0-2) su `dobitan`, ostalih 5
  `gubitan`, stanje računa uvećano tačno za zbir dobitaka (4 x 20.00 = 80.00),
  upisane tačno 4 transakcije tipa `dobitak`.

Oba testa: SVE OK. Sav test-podaci (događaj, liga, timovi, kvote, tiketi,
korisnik, transakcije) obrisan na kraju istog skripta.

---

## Uvoz ponude sa The Odds API-ja (2026-08-26)

Operater do sada svaku utakmicu i svaku kvotu kuca ručno. Dodat je pomoćni alat
koji ih preuzima sa `the-odds-api.com`. **Ručni unos nije ni dodirnut** i mora
raditi bez interneta, bez ključa i sa praznom tabelom mapiranja — to je i
zapisano u `CLAUDE.md` kao pravilo, uz zabranu da bilo koja druga stranica
uključuje `includes/odds_api.php`.

### Šta je dodato

- `config/api.primer.php` — šablon sa **praznim** ključem, ide u git.
  `config/api.php` je kopija sa pravim ključem i stoji u `.gitignore`.
- `includes/odds_api.php` — poziv servisa preko cURL-a (timeout 8s) i keširanje.
- `includes/uvoz.php` — pretvaranje odgovora u našu šemu, prepoznavanje
  duplikata, povezivanje timova, upis u bazu.
- `operater/uvoz.php` — dvokoračna stranica (pregled, pa upis).
- `operater/api_lige.php` — CRUD nad novom tabelom `api_liga`.
- `baza/migracija_api.sql` — dopuna za bazu koja već postoji; u `schema.sql` je
  isto to već ugrađeno (sada 14 tabela).

### Dva koraka, jer se ništa ne sme upisati bez potvrde

Klik na „Preuzmi“ **ne upisuje ništa** — samo prikaže tabelu pregleda sa
utakmicama, kvotama i stanjem svakog reda. Tek „Sačuvaj u bazu“ upisuje ono što
je operater ostavio čekirano.

**Server ne veruje formi.** Iz forme stižu samo identifikatori čekiranih
utakmica; vremena, timove i kvote server ponovo izračuna iz odgovora
**pročitanog sa diska**. Isti `pripremi_pregled_uvoza()` radi i pregled i upis,
pa se ono što operater vidi i ono što se upisuje ne mogu razići.

Zbog toga u pregledu ni ne postoji polje za izmenu kvote — vrednost bi tada
morala da stigne sa klijenta. Kvota se posle upisa menja tamo gde se i inače
menja, u `operater/kvote.php`.

### Keširanje — kvota je 500 poziva mesečno

Svaki odgovor se snima u `kes/` kao JSON sa vremenskom oznakom i stanjem
kredita. Ako je fajl mlađi od 30 minuta, „Preuzmi“ ga koristi i **poziv se
uopšte ne šalje**; „Osveži sa servera“ uvek šalje nov. Traka iznad tabele
prikazuje kad je keš napravljen i koliko je poziva ostalo (iz zaglavlja
`x-requests-remaining`).

Sam upis u bazu **nikad** ne poziva servis: čita isti keš, bez obzira na
starost. To je odgovor koji je operater upravo pregledao, pa bi nov poziv i
trošio kredit i mogao da mu ispod ruke promeni podatke.

Dodato preko traženog: **ako poziv padne, a na disku postoji makar zastareo
keš, prikazuje se on** uz jasno upozorenje. Stara ponuda je korisnija od prazne
stranice, a operater vidi šta gleda.

Cena poziva je (broj regiona × broj tržišta) = 1 × 2 = **2 kredita**. Spisak
liga (`/sports`) se ne naplaćuje, ali se svejedno kešira na 24 sata i čita samo
sa diska pri otvaranju stranice — stranica se ne sme oslanjati na mrežu da bi
se iscrtala.

### Mapiranje na postojeću šemu

**Lige** — nova tabela `api_liga` (`api_kljuc` → `liga_id`), sa punim CRUD-om,
jer zahtev 6 traži CRUD nad svakom tabelom. Ključ je `UNIQUE`: jedan ključ vodi
u tačno jednu ligu, obrnuto ne mora (ista liga sme imati više ključeva).

**Timovi** — ovde **nije** napravljena druga tabela, nego kolona
`tim.api_naziv`. Razlog: veza je 1:1 sa timom i prirodno je njegov atribut, a
tabela `tim` već ima CRUD, pa se polje odmah uređuje i briše kroz
`operater/timovi.php` — bez još jedne stranice koja postoji samo da bi zahtev 6
bio zadovoljen. Ograničenje je `UNIQUE (liga_id, api_naziv)`; MySQL dozvoljava
više `NULL` vrednosti u `UNIQUE` ključu, pa svi timovi koji se nikad ne uvoze
mirno stoje sa praznom kolonom (provereno: 7 timova sa `NULL` u istoj ligi
prolazi, dva ista neprazna naziva ne prolaze).

Tim se traži redom, od najpouzdanijeg ka najslabijem:

1. tačno onaj naziv koji je operater sam povezao (`tim.api_naziv`),
2. isti povezan naziv do razlike u velikim slovima i interpunkciji,
3. naš naziv se poklapa sa nazivom kod servisa.

Odluka operatera time uvek pobeđuje pogađanje. Normalizacija skida kvačice i
interpunkciju („F.C. Köln“ = „FC Koln“), ali ne pokušava prevod — „Manchester
United“ i „Mančester Junajted“ povezuje operater, jednim klikom iz pregleda.

Povezivanje tima je namerno **odvojeno** od „Sačuvaj u bazu“: to je odluka o
rečniku timova, a ne o ponudi, i tiče se svih budućih uvoza te lige.

**Tržišta** — `h2h` daje 1/X/2 (ime domaćina, `Draw`, ime gosta), `totals` na
liniji 2.5 daje `Under` → `0-2` i `Over` → `3+`. Druge linije (1.5, 3.5) se
preskaču jer im u `tip_igre` nema para. Mapiranje ide **po oznaci unutar
sporta**, ne po pretpostavci da je reč o fudbalu: košarka nema oznaku „X“, pa
prosto ne dobija tu kvotu, i tabela pregleda za nju nema ni tu kolonu. GG i
dupla šansa se u besplatnom nivou ne dobijaju — dopunjuju se postojećim
dugmetom „Dodaj sve tipove“.

**Kvota** je prosek svih kladionica koje su je ponudile, zaokružen na dve
decimale. Prosek ispod 1.01 se odbacuje, jer bi pao na `CHECK` ograničenju baze
i srušio celu transakciju uvoza.

### Duplikati i stanja redova

Duplikat je, po dogovoru, ista četvorka **(liga, domaćin, gost, vreme
početka)** — takav red se preskače uz obaveštenje i nema ni kućicu za čekiranje.

Uz to je dodato stanje **„moguć duplikat“**: isti timovi već imaju događaj tog
dana, ali u drugo vreme. Po pravilu to nije duplikat (servis ume da pomeri
satnicu već unete utakmice), pa se red ne odbija — ali se ni ne čekira sam od
sebe. Odluku donosi operater.

Sedam mogućih stanja reda i šta znače:

| Stanje | Kućica | Zašto |
|---|---|---|
| `spreman` | čekirana | ući će u bazu |
| `moguć duplikat` | prazna | isti par isti dan, drugo vreme |
| `već postoji` | nema je | ista četvorka je već u bazi |
| `nepoznat tim` | nema je | tim još nije povezan |
| `bez kvota` | nema je | servis nije dao nijednu upotrebljivu kvotu |
| `prošlo vreme` | nema je | pravilo 6 — na započet događaj se ne kladi |
| `nečitljiv zapis` | nema je | odgovor servisa je nepotpun |

Boje u `style.css` prate isti dogovor kao i drugde: zelena i crvena su rezervi-
sane za dobitak i gubitak, pa stanja uvoza koriste plavu (ući će), žutu (traži
odluku) i neutralnu (ne ulazi, isprekidana ivica kad nešto nedostaje).

### Otpornost

Nijedna funkcija u `odds_api.php` ne baca izuzetak i nijedna ne ispisuje ništa —
svaka vraća niz sa `uspeh` i porukom na srpskom koju stranica sme da prikaže.
Tehnički detalj (HTTP kod, odgovor servisa, cURL greška) ide isključivo u
`logovi/greske.log`, i to **bez ključa**: u log odlazi samo putanja poziva, a
nikad pun URL, u kom ključ stoji kao parametar.

Upis izabranih utakmica ide u **jednoj transakciji** — ili uđu sve, ili nijedna.
Duplikat je jedini izuzetak: on se preskače, a ne obara upis. Postojeći događaji
te lige se pritom zaključavaju (`SELECT ... FOR UPDATE`), istim postupkom kojim
faza 8 zaključava događaj pri obradi rezultata, pa dva istovremena uvoza iste
lige ne mogu oba proći kroz proveru duplikata.

### Otkriveno usput

**1. PHP tiho pretvara numeričke stringove u cele brojeve kad su ključ niza.**
Oznaka `'1'` u nizu kvota postaje ključ `1`. Slučajno je radilo, jer se ista
pretvorba dešava i pri čitanju i pri upisu, pa su se dve strane poklapale — ali
na prećutno pravilo se ne oslanja: obe strane se sada izričito svode na string.
Otkriveno tako što je test poredio `array_keys()` strogim `===`.

**2. Apache na ovoj mašini radi kao `daemon` i nije mogao da piše u `kes/`.**
Folder je bio `damjan:damjan`, dok `logovi/` i `uploads/` odavno stoje sa grupom
`daemon`. Posledica nije bila pad — preuzimanje je radilo, samo se keš nije
snimao, pa bi **svako otvaranje trošilo kredit**. Greška je uredno završila u
`greske.log`. Rešenje je jedna komanda, dopisana u `POKRETANJE.md` (na Windows
XAMPP-u problem ne postoji). Uz to: ako se `kes/` obriše, `upisi_kes()` ga pravi
iznova i sa njim upisuje i `.htaccess`, da keširani odgovori ne bi ostali
dostupni kroz pregledač.

**3. Ceo `config/` folder je bio dostupan kroz pregledač.** Dok PHP radi,
otvaranje `config/baza.php` ne ispiše ništa, pa se to nije videlo. Ali fajl sa
ključem i fajl sa lozinkom baze ne smeju da zavise od toga da li PHP radi —
dodat je `config/.htaccess` sa `Require all denied`. `require_once` je pristup
fajl sistemu i to ga ne dodiruje, što je i provereno: sve stranice i dalje rade,
a `config/api.php`, `config/baza.php` i `config/konfiguracija.php` kroz
pregledač daju 403.

### Odluke koje vredi zapamtiti

- U meni operatera je dodata samo stavka „Uvoz sa API-ja“; `api_lige.php` stoji
  jedan klik dalje, sa same stranice za uvoz. Sa devet stavki bi se glavni meni
  prelomio u dva reda, a mapiranje liga se dira jednom, pri podešavanju.
- Uvoz upisuje `TRAG` u `greske.log` (koliko događaja i kvota, sa kog ključa) —
  po tabeli `dogadjaj` se posle ne vidi da su redovi stigli sa servisa.
- Brisanje mapiranja **ne dira** već uvezene događaje; oni su od trenutka upisa
  obični redovi kao i svi ručno uneti.

### Testirano

Napravljen je **privremeni test server koji glumi The Odds API** (nije deo
aplikacije, obrisan po završetku). Time je ceo lanac — cURL, zaglavlja, keš,
pregled, upis — provučen kroz pravi HTTP, bez ključa i bez trošenja ijednog
poziva iz mesečne kvote. Sedam pripremljenih utakmica pokriva svih sedam stanja
reda iz tabele iznad.

**42 provere nad `includes/odds_api.php`, sve prolaze**, uključujući: prosek
kvota (1.80 + 1.90 → 1.85), zaokruživanje na dve decimale, odbacivanje proseka
ispod 1.01, prevođenje vremena iz UTC u lokalno i leti i zimi, čišćenje naziva
keš fajla (`../../etc/passwd` → `etcpasswd.json`), čitanje
`x-requests-remaining`, i da svež keš znači **nula** novih poziva (brojano po
zapisima test servera).

Otpornost, svaka pojedinačno:

| Slučaj | Ishod |
|---|---|
| HTTP 429 (potrošena kvota) | prazan niz + poruka o mesečnoj kvoti |
| HTTP 401 (loš ključ) | poruka upućuje na `config/api.php`; odgovor servisa se ne prikazuje |
| odgovor koji nije JSON | prazan niz + poruka, detalj u `greske.log` |
| servis ćuti 12s | prekinuto na **8.0s**, poruka pominje 8 sekundi |
| servis ugašen, keš postoji | prikazan zastareo keš uz upozorenje |
| servis ugašen, keša nema | jasna poruka i predlog ručnog unosa |
| ključ nije podešen | stranica to kaže i upućuje na ručni unos, ostatak aplikacije radi |
| ključ u logu | ne pojavljuje se nijednom |

Ceo tok kroz pravi Apache, sa privremenim operaterskim nalogom:

- Preuzimanje → 7 utakmica, traka pokazuje vreme keša i **468** preostalih
  poziva, „0 / 7 spremno“ jer timovi još nisu povezani.
- „Arsenal“ je **prepoznat sam** (isti naziv u bazi); ostala tri tima ponuđena
  za povezivanje. Jedan napravljen kao nov tim, dva povezana sa postojećim.
- Posle povezivanja: „2 / 7 spremno“, a redovi su stali tačno na svoja stanja —
  duplikat, moguć duplikat, dva spremna, bez kvota, prošlo vreme, nečitljiv.
- **Slanje mimo forme** (duplikat + prošlo vreme + bez kvota + nečitljiv +
  izmišljen identifikator): 12 događaja pre, **12 posle** — ništa nije upisano,
  svaki red objašnjen svojom porukom.
- Upis dve spremne utakmice: 2 događaja i 10 kvota, vrednosti tačno jednake
  proseku (1.85 / 3.80 / 4.10 / 2.12 / 1.72), vreme prevedeno iz UTC u lokalno,
  status `najavljen`, `kreirao_id` operatera.
- Isti upis drugi put: **0 upisano**, obe prepoznate kao duplikat.
- **Poništavanje transakcije**: privremeno dodato ograničenje baze koje odbija
  jednu kvotu **druge** utakmice. Rezultat: prva utakmica, koja je već bila
  upisana, poništena zajedno sa njom — 12 događaja pre, 12 posle, 0 kvota.
  Korisnik je dobio razumljivu poruku, `SQLSTATE` je ostao u `greske.log`.
- Uvezen događaj se pojavljuje u javnoj ponudi sa svojih 5 kvota (1/X/2 vidljive,
  0-2 i 3+ iza dugmeta), a dugme „Dodaj sve tipove“ na njemu nudi tačno ona
  četiri tipa koja servis ne daje (1X, 12, X2, GG). Posle njihovog dodavanja
  događaj ima svih 9.
- Ručni unos događaja i dalje radi neizmenjeno.
- `kes/` i `config/` kroz pregledač vraćaju **403**.
- Sve nove i izmenjene stranice: HTTP 200, bez ijednog `Fatal error`, `Warning`,
  `Notice`, `Deprecated`, `SQLSTATE` ili putanje `/opt/lampp` u odgovoru.

Baza je proverena u oba smera: potpuno svež `schema.sql` (14 tabela) i
`migracija_api.sql` nad zatečenom bazom od 13 tabela. Sve na privremenoj bazi
`kladionica_proba`, koja je obrisana.

Po završetku obrisano: privremeni operaterski nalog, uvezeni i ručno uneti
probni događaji, novonapravljeni tim, sva mapiranja, sve `api_naziv` vrednosti i
keš fajlovi. U bazi su ostala zatečena 3 naloga, 12 događaja i 24 tima.

---

## Ikonice, tamna tema i doterivanje izgleda (2026-08-26)

### 1. Ikonice i oznake za deljenje

- `favicon.svg` — glif `bi-ticket-perforated` prepisan iz Bootstrap Icons
  1.11.3 (isti crtež koji nosi `.navbar-brand`), obojen u `--plava-500`, na
  providnoj pozadini.
- `favicon.ico` (32x32, uz 16x16 unutra) i `apple-touch-icon.png` (180x180) —
  isti crtež, beo, na punoj `--mornar-900` pozadini, jer iOS providnost ne
  prikazuje nego je zacrni.
- `og-slika.png` (1200x630) — ime, opis i ikonica na identitetskoj traci.
  Namerno **nije** ista slika kao apple-touch-icon: kvadrat od 180px u
  pregledu linka izgleda kao greška, a ovaj format je ono što servisi zaista
  prikazuju.
- `includes/glava.php` — zajednički `<head>` (naslov, opis, ikonice,
  `theme-color`, Open Graph). Postoji zato što četiri stranice imaju sopstveni
  `<html>`: `zaglavlje.php`, `prijava.php`, `registracija.php` i `greska.php`.

`theme-color` prati temu: kad je izabrana, ide jedna oznaka; kad nije, idu dve
sa `media` upitom, pa i traku pregledača bira sistem.

**Kako su rasterske ikonice napravljene, kad na mašini nema ni ImageMagick-a ni
rsvg-a:** SVG je iscrtan u headless Chrome-u na 512x512, pa je Pillow smanjio
sliku na 180px i 32px. Isti postupak je dao i `og-slika.png`, koja zato koristi
prave fontove projekta (Barlow Condensed) i boje iz palete.

**Otkriveno usput:** prvi `favicon.svg` se uopšte nije prikazivao. Uzrok je
komentar u kom je pisalo `--plava-500`: **dve crtice zaredom su u XML komentaru
zabranjene**, pa je ceo fajl bio neispravan XML. Pregledač je ćutke prikazivao
praznu ikonicu; otkriveno tek kad je fajl pušten kroz XML parser. Naziv
promenljive sada u komentaru stoji bez crtica, uz objašnjenje zašto.

### 2. Tamna tema

#### Zašto je bilo potrebno dirati pravila, a ne samo vrednosti

Zamisao je bila da se promene samo vrednosti promenljivih. To nije bilo moguće
jer je **isti ton služio u dve suprotne uloge**: `--mornar-800` je bio i boja
naslova (tamno slovo na belom) i pozadina zaglavlja tabele (belo slovo na
tamnom). U tamnoj temi naslov mora da posvetli, a zaglavlje tabele da ostane
tamno — jedna promenljiva to ne može oboje. Isto važi za `--plava-500`
(link **i** pozadina dugmeta) i `--mornar-700`.

Zato je `css/style.css` podeljen na dva sloja:

| Sloj | Šta sadrži | Menja se sa temom |
|---|---|---|
| **PALETA** | `--mornar-950`, `--plava-300`… — tonovi marke | ne |
| **ULOGE** | `--naslov`, `--akcenat`, `--povrsina-mornar`… | da |

Pravila sada imenuju ulogu, ne ton. Paleta je dopunjena sa četiri tona:
`--crna-plava` i `--mornar-950` (dublji od `--mornar-900`, za podlogu tamne
teme) i `--plava-300` / `--plava-200` (akcenat kao **slovo** na tamnoj podlozi —
`--plava-500` tamo daje svega 3.4:1).

Vrednosti tamne teme stoje u `--t-*`, na jednom mestu. Dva bloka
(`[data-tema="tamna"]` i `@media (prefers-color-scheme: dark)`) ih samo
dodeljuju ulogama, jer CSS nema način da jedan blok veže za oba slučaja.

#### Kolačić i odsustvo treptaja

Izbor se pamti u kolačiću `tema` (godinu dana, `SameSite=Lax`,
`includes/tema.php`). PHP ga čita u `zaglavlje.php` i upisuje `data-tema` na
`<html>` **pre** nego što pregledač bilo šta iscrta — bez toga bi tamna strana
na trenutak bljesnula belo. Ovo je **treći** dokumentovani slučaj upotrebe
kolačića, uz „zapamti me“ i pamćenje izabrane lige (dopisano u `CLAUDE.md`).

Bez kolačića se ne ispisuje ništa i temu bira `prefers-color-scheme`.

#### Prekidač koji radi bez JavaScripta

U formi stoje **dva** dugmeta — mesec („uključi tamnu“) i sunce („uključi
svetlu“) — a CSS prikazuje ono koje odgovara temi na snazi. Razlog je baš
slučaj bez kolačića: server tada ne zna kako je sistem podešen, pa ne može da
izabere pravo dugme, dok CSS to zna kroz isti media upit kojim bira i boje.
Klik je obična forma ka `tema.php`, koja pamti kolačić i vraća korisnika nazad.

`tema.php` ne veruje ničemu iz forme: traži CSRF token, prima samo `svetla` i
`tamna`, a putanju povratka propušta samo ako počinje korenskom putanjom
aplikacije (time otpada i `//tudji-sajt` i `http://…`).

#### Bootstrap kao drugi sloj boja

Uz `data-tema` se postavlja i `data-bs-theme`, pa Bootstrap sam prebojava svoje
komponente (padajuće menije, polja, strelicu u `<select>`). Kad kolačića nema,
to radi četiri reda skripte u `<head>`-u — ali **i bez JavaScripta stranica je
ispravno tamna**, jer naši tokeni idu kroz `@media`; svetle ostaju samo
Bootstrap-ove komponente, dok korisnik jednom ne dodirne prekidač.

#### Grafikoni

`js/statistika.js` je i ranije čitao boje iz CSS-a, pa se prebojava sam pri
osvežavanju strane. Dve stvari su ipak morale da se promene, jer su bile vezane
za **paletu** umesto za ulogu:

- natpis iznad poslednje tačke crtan je bojom `--mornar-800` — u tamnoj temi
  tamnoplavo po tamnoj kartici, nevidljivo. Sada koristi `--naslov`.
- kriške po ulogama išle su `--plava-400 → --mornar-900`; poslednja se na
  tamnoj kartici stapala sa podlogom. Uvedeni su `--grafikon-1..4`, koji u
  tamnoj temi pomeraju ceo niz u svetliji deo skale, uz zadržan smer
  „više prava = tamniji ton“.

### 3. Doterivanje izgleda

- **Tabele.** Tri tabele u `admin/statistika.php` bile su jedine bez
  `.table-responsive`. Sve ostale su ga već imale.
- **Kolona akcija u `operater/dogadjaji.php`** imala je četiri kontrole i
  lomila se u tri reda. Sada je vidljiva samo najčešća radnja („Kvote“), a
  ostale (Izmeni / Unesi rezultat / Obriši) stoje u meniju sa tri tačke.
  Brisanje je i dalje forma sa CSRF tokenom, samo premeštena u meni.
- **Stanje učitavanja** (`js/ucitavanje.js`): vrteška i onemogućeno dugme na
  uplati tiketa (AJAX) i na preuzimanju sa API-ja (obična forma, poziv sme da
  traje do 8 sekundi).
- **Zaglavlje stranice.** Novije strane slagale su razmak Bootstrap klasama —
  negde `mb-3`, negde `mb-4`, uz dvostruki procep kad iza podnaslova dođe i
  dugme. Uvedena je klasa `.zaglavlje-strane` sa ukupno 1.5rem ispod, koliko
  daje `mb-4` na ostalim stranama.
- **Naslovi stranica.** Svaka strana već je imala svoj, ali su se dva
  ponavljala (`Transakcije` i kod igrača i kod admina). Sada su svi jedinstveni
  („Moje transakcije“ / „Sve transakcije“), a `operater/kvote.php` nosi i imena
  timova, jer operater otvara više kvota odjednom.

### Kontrast — izmereno, ne procenjeno

Napravljen je privremeni alat koji u pregledaču prolazi kroz **svaki vidljiv
tekst**, čita stvarnu izračunatu boju slova i stvarnu podlogu ispod njih
(slažući poluprovidne slojeve), pa računa WCAG odnos. Meri se ono što se
iscrta, ne vrednosti tokena.

Rezultat: **4674 provere na 25 stranica x 2 teme, sve ≥ 4.5:1** (odnosno ≥ 3:1
za krupan tekst). Alat je usput našao **šest stvarnih grešaka**, od kojih pet
starijih od tamne teme:

| Gde | Bilo | Sada |
|---|---|---|
| `.podnozje .napomena` (na svakoj strani) | 3.44:1 | **6.14:1** |
| `.btn-outline-warning` („Blokiraj“) | 1.63:1 | **5.98:1** |
| `.btn-uplati:disabled` („Uplati tiket“) | 1.60:1 | **10.57:1** |
| `--dobitak` na svojoj podlozi | 3.88:1 | **5.03:1** |
| `--ceka` na svojoj podlozi | 3.43:1 | **5.43:1** |
| link u `.alert-secondary` | 3.50:1 | **5.21:1** |

Uz to, u tamnoj temi: `.text-muted` i `.form-text` (Bootstrap-ovo `#6c757d`)
davali su 3.82:1, a `btn-outline-danger`/`success`/`secondary` po ~3.9:1 — sve
preuzimaju naše tokene i sada su 7.5–10:1.

**Najveće iznenađenje:** linkovi nikad nisu ni bili u boji marke. Bootstrap 5.3
ih boji preko `--bs-link-color-**rgb**`, a ne preko `--bs-link-color`, pa je
postavka iz prve verzije `style.css` bila bez ikakvog dejstva — svi linkovi su
sve vreme bili Bootstrap-plavi `#0d6efd`, koji na pozadini strane daje 4.12:1.
Otkriveno tek merenjem iscrtane strane; da su se merili tokeni, ne bi se videlo.

Mereni su i tonovi krišaka kružnog grafikona (OKLab ΔE, isti postupak kao
ranije): u tamnoj temi susedni tonovi se razlikuju za ΔE 9.5–13.3, a svaki ima
bar 4.5:1 prema kartici. Prvi predlog (`#bcdcfa`) je odbačen na ΔE 7.3.

### Prelivanje na uskim ekranima — takođe izmereno

Drugi privremeni alat učitava svaku stranicu u okviru širine 375px i prijavljuje
svaki element koji izlazi iz te širine (preskačući one unutar kutije sa
sopstvenim vodoravnim klizačem, kakva je `.table-responsive`).

Našao je dva stvarna prelivanja:

- `admin/logovi.php` — grupa od četiri dugmeta „poslednjih 50/100/200/500“ bila
  je široka 416px. Reč „poslednjih“ se sada kaže jednom, ispred grupe; čitačima
  ekrana pun tekst i dalje stiže kroz `aria-label`.
- `admin/statistika.php` — **grešku sam sam napravio** dok sam umotavao tabele
  u `.table-responsive`: zamena je pogodila i zatvarajući tag skrivene tabele
  (one koja služi kao tekstualna zamena za grafikon), pa je ostao višak
  `</div>` koji je prerano zatvarao `.container`. Zbog toga su svi redovi ispod
  izlazili iz okvira. Alat je to prijavio kao prelivanje od 8 i 12 piksela, što
  je tačno negativna margina `.row`-a — trag po kom se i našlo.

Posle ispravki: **25 stranica x 2 teme, nijedno prelivanje.**

### Ostalo testirano

- **Prekidač bez JavaScripta** — proveren `curl`-om, koji JS uopšte ne izvršava:
  klik na mesec upisuje kolačić i vraća `data-tema="tamna"`, klik na sunce
  vraća `svetla`. Kolačić traje 365 dana.
- **Odbrana `tema.php`** — bez CSRF tokena se ništa ne menja; `povratak` na
  `https://zlonamerni.example` i na `//zlonamerni.example` završava na početnoj;
  `GET` ne menja ništa; nepoznata vrednost (`neonska`) se ignoriše.
- **Vrteška ne guta `name=value`.** Onemogućeno dugme pregledač ne broji kao
  pošiljaoca forme, pa bi se izgubila razlika između „Preuzmi“ i „Osveži sa
  servera“ (`osvezi=1`). Zato se stanje menja tek u sledećem prolazu petlje
  događaja. Provereno klikovima u pregledaču uz test server: „Preuzmi“ bez keša
  → poziv, „Osveži“ nad svežim kešom → **ipak poziv**, „Preuzmi“ nad svežim
  kešom → bez poziva. Tačno dva poziva, kako i treba.
- **Sve 25 stranica, obe teme:** HTTP 200, nijedan `Fatal error`, `Warning`,
  `Notice`, `Deprecated`, `SQLSTATE` ni putanja `/opt/lampp` u odgovoru.
- `favicon.ico` je stizao **bez `Content-Type`** zaglavlja — ovaj Apache nema
  MIME oznaku za `.ico`. Dopisan `AddType image/x-icon .ico` u `.htaccess`;
  ostale zabrane (`.sql`, `.log`, `.md`, `kes/`, `config/`) i dalje vraćaju 403.
- Snimcima ekrana provereni panel tiketa sa stavkama, meni sa tri tačke,
  grafikoni i traka uvoza — u obe teme.

Meni operatera je usput dobio osmu stavku i počeo da se prelama usred naziva
(„DOGAĐAJI / I KVOTE“); naziv sada ostaje u jednom komadu, a do 1400px se steže
razmak između stavki.

Privremeni alati (merač kontrasta, merač prelivanja, klikač, test server API-ja)
nisu deo aplikacije i obrisani su, zajedno sa tri probna naloga i njihovim
podacima. U bazi su ostala zatečena 3 naloga, 12 događaja, 24 tima i 66 kvota.

---

## Grupisanje navigacije u padajuće menije (2026-08-26)

Meni operatera (8 stavki) i admina (7 stavki) se na srednjim širinama lomio.
Prošli krug je to zaobišao stezanjem razmaka do 1400px - ovaj krug rešava
uzrok: stavke su grupisane, pa oba menija sad imaju po 4 vrha.

- **Operater:** Ponuda | Utakmice ▾ | Šifarnici ▾ | Dokumenta
- **Admin:** Ponuda | Korisnici ▾ | Poslovanje ▾ | Nadzor ▾
- **Igrač:** bez izmena - četiri stavke, grupisanje bi tu bilo suvišno.

„Mapiranje liga" (`operater/api_lige.php`) je pritom prvi put ušlo u glavni
meni - ranije je bilo dostupno samo jednim klikom sa stranice za uvoz.

### `grupa_menija()` - deli logiku sa `stavka_menija()`, ne duplira je

Provera „da li je ovo trenutna stranica" je izvučena u `stavka_aktivna()`, koju
sada koriste obe funkcije. Grupa dobija `.active` (istu tanku crtu ispod kao
pojedinačna stavka) čim je **bilo koja** njena stavka trenutna - ali
`aria-current="page"` i dalje nosi **isključivo** ta jedna stvarna stavka, ne i
naslov grupe. Razlog: grupa nije stranica, ona samo sadrži jednu, i čitač
ekrana ne sme da pomisli suprotno. Provereno u izvornom HTML-u
(`operater/lige.php`, operater): tačno dva `active` na strani - na toggle-u
„Šifarnici" i na stavci „Lige", uz `aria-current="page"` samo na drugoj.

### Otvaranje u tri sloja, umesto pisanja nove JS komponente

1. **Bez JavaScripta** - `:hover` i `:focus-within` u `style.css`. Radi zato
   što Bootstrap-ova klasa `.dropdown` već postavlja `position: relative` na
   roditelja (`<li>`), pa apsolutno pozicioniran `.dropdown-menu` ispravno
   padne ispod bez ijedne linije JS-a ili Poppera. Specifičnost `.navbar
   .dropdown:hover > .dropdown-menu` (0,3,0) je veća od Bootstrap-ovog
   `.dropdown-menu` (0,1,0), pa `!important` nije trebalo.
2. **Sa JavaScriptom, na širem ekranu (≥992px)** - Bootstrap-ov ugrađeni
   dropdown iz `bootstrap.bundle.min.js`, koji je već učitan na svakoj strani.
   Klik, strelice kroz stavke, Escape koje vraća fokus na toggle - ništa od
   toga nije ručno pisano, samo ispravno postavljen `data-bs-toggle="dropdown"`
   i `aria-expanded`.
3. **Sa JavaScriptom, u sažetom (mobilnom) meniju (<992px)** - isti Bootstrap
   mehanizam, ali se meni prikazuje kao **ugnežđena lista** u toku teksta
   (`position: static`), ne kao popup preko sadržaja. `data-bs-display="static"`
   na toggle-u govori Bootstrap-u da ne pokreće Popper, čije bi inline stilove
   inače trebalo nadjačavati.

### Otkriveno usput - isti obrazac greške kao prošli put

**`.dropdown-item.active` je bio Bootstrap-ovo plavo `#0d6efd`, ne naše.**
Isti obrazac kao `--bs-link-color-rgb` iz prošlog kruga: `.dropdown-menu` je
već imao `--bs-dropdown-link-hover-bg` postavljen na naš token, ali ne i
`--bs-dropdown-link-active-bg` - pa je stavka koja predstavlja trenutnu
stranicu u meniju (npr. „Lige" dok je operater na `operater/lige.php`) bila
obojena tuđom bojom umesto markom sajta. Otkriveno snimkom ekrana, ne merenjem
koda - kontrast sam po sebi nije bio loš (belo na `#0d6efd` prolazi), samo je
boja bila pogrešna. Ispravljeno preko istog `--povrsina-akcenat` tokena koji
već nosi `.btn-primary` (5.70:1, izmereno u prošlom krugu, ne zavisi od teme).

### Testirano

**Tastatura**, sintetičkim `KeyboardEvent`-ima na `document.activeElement`
unutar prave stranice (ne na pogođenom selektoru - prva verzija testa je
slala Escape na prvi `.dropdown-item` u **celom** dokumentu, koji pripada
prvoj grupi, a ne trenutno fokusiranoj; ispravljeno da uzima stvarno
fokusiran element):
- fokus na toggle → `ArrowDown` → `aria-expanded="true"`, `.dropdown-menu`
  dobija `.show`, fokus prelazi na prvu stavku;
- drugi `ArrowDown` → fokus prelazi na drugu stavku;
- `Escape` sa fokusa unutar menija → `aria-expanded="false"`, `.show` nestaje,
  fokus se vraća na toggle.

**Bez JavaScripta - dokazano, ne pretpostavljeno.** Stranica je preuzeta preko
`curl`-a, iz nje su fizički uklonjena sva tri `<script>` taga (ne samo
onemogućen JS zastavicom), na toggle je dodat čist HTML atribut `autofocus`
(ne zahteva JS), i takva stranica je otvorena u Chrome-u sa
`--disable-javascript`. Snimak ekrana pokazuje otvoren meni „Utakmice" -
`:focus-within` ga je otkrio bez ijedne linije skripte na strani. Isti
mehanizam (isti CSS selektor, samo drugi pseudo-klasa okidač) stoji iza
`:hover` verzije za miš.

**Vizuelno, snimcima ekrana, u obe teme:**
- meni otvoren na 1350px (desktop popup) - svetla i tamna se slažu, samo se
  podloga menija menja (`var(--karta)`), tekst i akcenat ostaju čitljivi;
- meni otvoren na 375px posle klika na hamburger (ugnežđena lista) - stavke
  su uvučene, sa tananom levom trakom, bez senke i bez zaobljenih ivica -
  vizuelno deo iste tamne trake kao ostatak sažetog menija, ne odvojena kartica;
- tačno na granici: 992px daje pun desktop popup (hamburger već sakriven),
  991px daje ugnežđenu listu (hamburger vidljiv) - lom je oštar, bez
  međustanja.

**Prelivanje, automatski, na 375 / 768 / 992 / 1200px, u obe teme, i sa
zatvorenim i sa otvorenim menijem** (za mobilne širine: klik na hamburger pa
na prvu grupu, pre merenja) - `index.php`, `operater/lige.php`,
`operater/dogadjaji.php`, `operater/uvoz.php`, `admin/korisnici.php`:
**nijedno prelivanje, nijedna kombinacija.**

**Ostalo:** 26 kombinacija stranica × tema (sve tri uloge) - HTTP 200, bez
`Fatal error`, `Warning`, `Notice`, `Deprecated`, `SQLSTATE` ni putanje
`/opt/lampp`. Igračev meni proveren da je ostao potpuno nepromenjen (pet ravnih
stavki, bez grupa). PHP sintaksa čista na svih 54 fajla.

Privremeni alati (merač prelivanja, klikač sa tastaturom, test bez JS-a) nisu
deo aplikacije i obrisani su, zajedno sa probnim nalozima. U bazi su ostala
zatečena 3 naloga, 12 događaja, 24 tima.

---

## Animacija padajućih menija i "samo jedan otvoren" (2026-08-26)

### 1. Animacija - na samom `.dropdown-menu`, ne na okidaču

Otvaranje ~140ms, zatvaranje ~100ms, `opacity` + `translateY(-4px → 0)`,
`ease-out`. Zahtev je bio da prelaz radi identično bez obzira ko ga pokrenuo -
:hover, :focus-within ili Bootstrap-ova klasa `.show` - pa prelaz stoji na
`.dropdown-menu` kao takvom:

```css
.navbar .dropdown-menu {
    display: block;
    opacity: 0;
    transform: translateY(-4px);
    pointer-events: none;
    transition: opacity .1s ease-out, transform .1s ease-out;
}

.navbar .dropdown:hover > .dropdown-menu,
.navbar .dropdown:focus-within > .dropdown-menu,
.navbar .dropdown-menu.show {
    opacity: 1;
    transform: translateY(0);
    pointer-events: auto;
    transition: opacity .14s ease-out, transform .14s ease-out;
}
```

Trik za dve različite brzine bez posebne klase: CSS pravilo koje je AKTIVNO u
trenutku promene diktira trajanje prelaza. Zatvoreno stanje nosi `.1s`
(koristi se pri zatvaranju, jer se tada baš ono ponovo primenjuje), otvoreno
`.14s` (koristi se pri otvaranju) - standardan obrazac za "brže napolje nego
unutra", bez ijedne dodatne klase ili linije JS-a.

**Zašto ovo zahteva `display: block` stalno, a ne `display: none` kad je
zatvoreno:** `display` se ne može animirati - prelaz sa `none` na `block` je
trenutan skok, bez koraka. Zato je `.dropdown-menu` sad UVEK u iscrtavanju
(≥992px), sakriven isključivo preko `opacity: 0` i onemogućen preko
`pointer-events: none` (bez ovog drugog, nevidljiv meni bi i dalje gutao
klikove namenjene sadržaju ispod njega - tačno upozorenje iz zahteva).
`pointer-events` namerno NIJE u `transition` listi: menja se trenutno, ne
tokom prelaza, jer otvoren meni mora biti klikabilan čim počne da se pojavljuje,
ne tek kad se prelaz do kraja odigra.

**Mobilni (<992px) meni namerno ostaje bez animacije.** Tamo je meni deo toka
teksta (`position: static`), ne plutajući popup - `translateY` tu nema smisla
(zahtev to i zabranjuje), a da bi se animirao makar `opacity`, zatvoreno stanje
bi moralo trajno da rezerviše svoju visinu u toku teksta (isti razlog zbog kog
je ≥992px sad stalno u iscrtavanju), što bi ostavilo prazan razmak gde stoji
zatvorena lista - vidljiv nedostatak. Ispravno rešenje bi bilo i sažimanje
visine (`max-height` prelaz), što zahtev izričito ne traži ("dovoljna je
promena opacity, ili nikakva") - izabrano je "nikakva", najjednostavnije i bez
rizika. Mobilni meni je i dalje Bootstrap-ov običan `display: none` / `.show`.

**Smanjeno kretanje.** Postojeće univerzalno pravilo
(`*, *::before, *::after { transition-duration: .01ms !important; }`) hvata i
ova dva nova prelaza bez izmene, jer `!important` na univerzalnom selektoru
pobeđuje bilo koju specifičnost. Provereno snimkom ekrana uz
`prefers-reduced-motion: reduce` - meni se pojavljuje bez ijednog vidljivog
koraka.

### 2. Samo jedan otvoren - `js/navigacija.js`, minimalan, oslanja se na Bootstrap API

Bootstrap već zatvara prethodni meni pri KLIKU na sledeći - to je ugrađeno i
nije ponovo pisano. Praznina je bila u CSS-only slojevima: `:hover` i
`:focus-within` ništa ne znaju o tome da je neki drugi meni već otvoren preko
Bootstrap-a (`.show`), pa bi prelazak miša (ili Tab) preko druge grupe otvorio
i nju - dva menija odjednom.

`js/navigacija.js` (učitava se na svakoj strani, uz `js/ucitavanje.js`) sluša
`mouseenter` i `focusin` na svakoj grupi. Kad pokazivač ili fokus UĐE u jednu,
funkcija prođe kroz SVE OSTALE i, za onu koja ima `.show`, pozove
`bootstrap.Dropdown.getOrCreateInstance(toggle).hide()` - zvaničan API, ne
ručno skidanje klase, pa Bootstrap ostaje u toku (aria-expanded, sopstveni
`hidden.bs.dropdown` događaj, sve se ažurira ispravno).

Ako `bootstrap` global ne postoji (nema JS-a), skripta se sama gasi na prvoj
liniji - ništa se ne pokreće, CSS sloj ostaje kakav jeste.

### Otkriveno pri testiranju - preostalo ograničenje, dokumentovano kako je i traženo

**Dva CSS-only sloja mogu ostati istovremeno otvorena BEZ JavaScripta**, i to
u tačno jednom scenariju: miš pokazuje na jednu grupu (:hover) dok je tastaturni
fokus istovremeno u drugoj (:focus-within) - dva nezavisna ulazna uređaja, dve
nezavisne trenutne pozicije, oba legitimna. `navigacija.js` ovo ne rešava po
dizajnu: gasi samo ono što je Bootstrap-ova `.show` evidencija, a ne bi imalo
smisla da hover prisilno oduzima tastaturni fokus samo zato što je meni ispod
pokazivača - to bi bio gori bag od dva otvorena menija.

Zahtev je ovo unapred predvideo i dozvolio ("prihvatljivo da :hover ponašanje
ostane kakvo jeste") uz traženu proveru da izgleda podnošljivo. Kako izgleda
(oba menija forsirano otvorena, kao proksi za ovaj scenario - genuine :hover ne
može da se sintetički pokrene u headless Chrome-u van CDP-a, objašnjeno ispod):
susedne grupe ("Utakmice" i "Šifarnici") stoje jedna pored druge bez preklapanja
teksta u glavnom telu, samo se DESNA ivica LEVOG menija delimično sakrije ispod
VIŠE postavljenog (kasnijeg u DOM-u) desnog menija - u konkretnom slučaju,
poslednja stavka "Uvoz sa API-ja" gubi deo teksta. Nijedan meni ne postaje
negativan ili nečitljiv - podnošljivo, ali ne savršeno.

### Testirano

**Sva tri sloja, pojedinačno, čitanjem `getComputedStyle` na `.dropdown-menu`
(1350px):**
- zatvoreno (bez ijednog okidača): `opacity=0`, `transform=matrix(1,0,0,1,0,-4)`
  (= `translateY(-4px)`), `pointer-events=none`, `transition-duration=0.1s`;
- `:focus-within` (`.focus()` na toggle - pravi fokus, za razliku od hovera
  legitimno se pokreće programski): rule se odmah prebacuje na
  `pointer-events=auto`, `transition-duration=0.14s`;
- Bootstrap `.show` (`.click()` na toggle): isto `pointer-events=auto`,
  `transition-duration=0.14s`, uz `show-klasa=true`;
- da se opacity/transform STVARNO ispisuju do kraja (1/translateY(0)), a ne
  samo da je pravilo tehnički pogodilo, provereno snimkom ekrana posle
  fokusiranja - meni pun, nepomeren, čitljiv.

**`:hover` specifično nije sintetički testiran** - `element.dispatchEvent(new
MouseEvent('mouseenter', ...))` menja DOM stanje (događaji koje sluša
`js/navigacija.js` I DALJE rade ispravno na tu simulaciju), ali ne menja
STVARNO ":hover" stanje pregledača, koje prati pravi pokazivač na nivou
rendering engine-a, ne DOM događaje - poznato ograničenje headless testiranja
bez CDP/WebSocket pristupa (nije dostupan u ovom okruženju: nema `pip`,
`websocket-client` ni `Xvfb`/`xdotool` za pravi miš). Ovo NIJE rupa u dokazu:
`:hover` i `:focus-within` su zarezom razdvojeni selektori koji dele ISTO telo
pravila (`{ opacity: 1; transform: translateY(0); ... }` stoji tačno jednom u
izvornom kodu) - nema načina da CSS pravilo primeni različite vrednosti zavisno
od toga koji ga je od tri selektora pogodio. Dokazano za `:focus-within`,
dokazano i za `:hover`, jer je isto pravilo.

**"Samo jedan otvoren", oba sudarna scenarija, sintetičkim `mouseenter` i
`focusin` (koji - za razliku od `:hover` - JESU pravi DOM događaji i pokreću
`js/navigacija.js` tačno kao što bi ih pokrenuo pravi ulaz):**
- klik na "Utakmice" (`show-klasa=true`) → `mouseenter` na "Šifarnici" →
  "Utakmice" prelazi na `show-klasa=false`, `pointer-events=none`;
- klik na "Utakmice" → `focusin` na "Šifarnici" (`.focus()`) → isti ishod;
- snimkom ekrana posle klik+fokus sekvence: vidljiv isključivo "Šifarnici",
  "Utakmice" zatvoreno - potvrđeno vizuelno, ne samo kroz DOM stanje.

**Prelivanje na 375 / 991 / 992 / 1350px, obe teme, meni zatvoren / otvoren
klikom / otvoren fokusom**, na dve NAJRIZIČNIJE grupe (najbliže desnoj ivici
navigacije - "Šifarnici" kod operatera, "Nadzor" kod admina, jer `.dropdown-menu`
je od sada UVEK u iscrtavanju na ≥992px, pa i zatvoren teorijski može da
prelije ako mu geometrija padne van ekrana): **nijedno prelivanje, nijedna
kombinacija.** Vizuelno potvrđeno i na 992px (najuža desktop širina) da meni
"Nadzor" staje udobno, sa oko 350px slobodnog prostora do desne ivice.

**Ostalo:** mobilni ugnežđeni prikaz (375px) vizuelno nepromenjen u obe teme
(namerno - animacija tamo nije ni dirana). Sve tri uloge, obe teme - HTTP 200,
bez `Fatal error`/`Warning`/`Notice`/`Deprecated`/`SQLSTATE`. PHP sintaksa
čista na svih 54 fajla.

Privremeni alati (merač animiranih vrednosti, merač prelivanja) nisu deo
aplikacije i obrisani su, zajedno sa probnim nalozima. U bazi su ostala
zatečena 3 naloga, 12 događaja, 24 tima.

---

## Uklonjeno :hover otvaranje padajućih menija (2026-08-26)

### Zašto uklonjeno, a ne samo popravljeno

`:hover` je bio jedan od tri sloja otvaranja iz prethodnog kruga. Problem nije
bio u tome kako radi na računaru - tamo je bio ispravan - nego na dodirnim
ekranima. Tamo ne postoji pravi pokazivač koji "uđe" i "izađe": prvi dodir na
telefonu prevodi pregledač u `:hover` stanje (jer nema boljeg signala odakle bi
inače dolazio), i to stanje **ostaje zalepljeno** dok korisnik ne dodirne nešto
drugo što samo po sebi menja fokus. Meni otvoren dodirom bi tako ostajao
otvoren i posle dodira negde drugde na strani - korisnik ne bi imao način da ga
zatvori osim slučajnim dodirom van njega, što nije očigledno ponašanje. Zato je
`:hover` izbačen u potpunosti, a ne samo dorađen - nema bezbedne varijante koja
zadržava prelazak miša kao okidač, a da ne pravi ovaj problem na dodiru.

Otvaranje sada ide isključivo namernom radnjom:
- **klik** - Bootstrap-ov ugrađeni dropdown (`bootstrap.bundle.min.js`), radi
  identično na mišu i na dodiru;
- **tastatura** - `:focus-within`, i dalje bez ijedne linije JavaScripta.

### Izmene

- `css/style.css` - iz selektora liste u oba pravila (otvaranje menija i
  isticanje toggle-a) izbačen `.navbar .dropdown:hover > ...`. Ostalo
  (`:focus-within`, `.show`, animacija) je nedirnuto - isto telo pravila,
  samo kraća lista selektora koji ga pokreću. Hover na SAMOM toggle-u (bez
  otvaranja menija) i dalje radi, ali stiže kroz već postojeće opšte pravilo
  `.navbar .nav-link:hover` (toggle nosi i klasu `.nav-link`) - ništa novo tu
  nije trebalo pisati.
- `js/navigacija.js` - uklonjen `mouseenter` slušalac. `focusin` ostaje, jer
  `:focus-within` i dalje može otvoriti drugi meni dok je neki već otvoren
  klikom, pa "samo jedan otvoren" i dalje mora da radi za tu kombinaciju.

### Otpada ranije dokumentovano ograničenje

Prošli krug je opisao (i prihvatio, po izričitom dopuštenju iz tog zahteva)
scenario u kom dva menija ostanu otvorena bez JavaScripta: miš na jednoj grupi
(`:hover`) dok je tastaturni fokus istovremeno u drugoj (`:focus-within`) - dva
nezavisna ulazna uređaja, dve nezavisne trenutne pozicije. Uklanjanjem `:hover`
kao okidača taj scenario **više ne postoji**: jedini CSS-only način da se meni
otvori je `:focus-within`, a fokus u datom trenutku može biti samo na jednom
mestu na strani. Sudar dva CSS-only sloja je time nestao zajedno sa jednim od
ta dva sloja - ne popravljen, uklonjen zajedno sa uzrokom.

### Otkriveno pri testiranju - artefakt alata za proveru, ne greška sajta

Test iz prethodnog kruga (`getComputedStyle` posle `.focus()`, čitano preko
`--dump-dom` uz `--virtual-time-budget`) je za `:focus-within` sad davao
`opacity=0` čak i posle više uzastopnih čitanja - dok je `pointer-events`
ispravno prelazio na `auto`. Posumnjalo se na pravi bug (dve vrednosti iz ISTOG
pravila, jedna se primeni a druga ne), pa je provereno izolovanom, minimalnom
stranicom van aplikacije:

- ista pravila, `.focus()`, `getComputedStyle` odmah zatim, pod
  `--virtual-time-budget` → `opacity` zaglavljen na `0`;
- ista stranica, ali **bez** `transition` na `.dropdown-menu` → `opacity`
  ispravno `1`;
- ista stranica **bez** `--virtual-time-budget` (stvarno vreme) → snimak
  ekrana pokazuje potpuno neproviran meni (piksel `(255,255,255)`, čista bela).

Zaključak: `--virtual-time-budget` u headless Chrome-u zamrzava CSS
**tranzicije** (koje zavise od internog časovnika animacije) na njihovu
početnu vrednost, dok trenutne promene poput `pointer-events` (van
`transition` liste) prolaze normalno. `:focus-within` selektor je sve vreme
ispravno pogađao pravilo - alat za proveru je lagao o rezultatu, ne CSS. Sav
dalji vizuelni dokaz u ovom krugu rađen je snimcima ekrana **bez**
`--virtual-time-budget` (obična navigacija + `.focus()`/`.click()` u
`onload`), gde se stvarno vreme dâ tranziciji da završi pre snimka.

### Testirano

**Logika (dump-dom, virtual-time - pouzdano za sve osim opacity):**
- sintetički `mouseenter` na toggle → **bez efekta**: `opacity=0`,
  `pointer-events=none`, `show-klasa=false` pre i posle, identično - ni CSS ni
  JS više ne slušaju taj događaj;
- klik → `pointer-events=auto`, `show-klasa=true` (Bootstrap i dalje radi);
- `.focus()` → `pointer-events=auto` (`:focus-within` i dalje pogađa pravilo);
- klik na "Utakmice" pa `.focus()` na "Šifarnici" → "Utakmice" se vraća na
  `pointer-events=none`, `show-klasa=false` (`focusin` u `navigacija.js` i
  dalje zatvara sestrinske grupe).

**Vizuelno, snimcima ekrana BEZ `--virtual-time-budget` (stvarno vreme za
tranziciju), obe teme, na pravim stranicama (`operater/lige.php`,
`admin/korisnici.php`):**
- klik otvara meni, potpuno neproziran;
- `.focus()` (tastatura) otvara meni, potpuno neproziran - isti prelaz kao
  klik, kako i treba, jer stoji na istom `.dropdown-menu` pravilu;
- svetla i tamna tema jasno različite (bela/tamna podloga menija), oba
  potpuno čitljiva;
- klik na "Utakmice" pa fokus na "Šifarnici" → vidljiv isključivo "Šifarnici",
  "Utakmice" zatvoreno - "samo jedan otvoren" i dalje važi;
- mobilni ugnežđeni prikaz (375px, klik na hamburger pa na grupu) vizuelno
  nepromenjen - mehanizam ispod 992px nije ni dirnut.

**Smanjeno kretanje** - direktno merenje `transition-duration` na izolovanoj
stranici sa istim CSS-om: bez zastavice `0.1s, 0.1s`, sa
`--force-prefers-reduced-motion` `1e-05s` (`.01ms`) - postojeće univerzalno
pravilo na dnu `style.css` i dalje gasi ovu (i svaku drugu) tranziciju.

**Prelivanje**, ponovljeno na 375 / 991 / 992 / 1350px, obe teme, klik i
fokus, na obe najrizičnije grupe ("Šifarnici" kod operatera, "Nadzor" kod
admina): **nijedno prelivanje, nijedna kombinacija** - geometrija (horizontalno
prelivanje) nije zavisila od `--virtual-time-budget` artefakta, jer
`translateY` menja samo Y osu.

**Ostalo:** sve stranice obe uloge (admin, operater), obe teme - HTTP 200, bez
`Fatal error`/`Warning`/`Notice`/`Deprecated`/`SQLSTATE`. Brojanje `{`/`}` u
`style.css` balansirano, nijedan preostali pomen `:hover` uz `.dropdown` u
`style.css` ni `js/navigacija.js`.

Privremeni alati (test računatih CSS vrednosti, merač prelivanja, izolovane
probne stranice) nisu deo aplikacije i obrisani su, zajedno sa probnim
nalozima. U bazi su ostala zatečena 3 naloga, 12 događaja, 24 tima.

---

## Klik na otvoren padajući meni ga nije zatvarao (2026-08-26)

### Utvrđivanje uzroka - pre bilo kakve izmene

Sumnja iz zahteva (Bootstrap skine `.show`, ali fokus ostaje na toggle-u pa
`:focus-within` i dalje drži meni otvorenim) proverena je privremenim
dijagnostičkim alatom koji čita **stvarno DOM i CSS stanje** posle svakog
klika - klasu, `aria-expanded`, `document.activeElement`, da li roditelj
pogađa `:focus-within`, i računatu `pointer-events`. Rezultat posle drugog
klika na isti toggle:

| Polje | Vrednost | Značenje |
|---|---|---|
| `toggle.classList` | `nav-link dropdown-toggle` (bez `.show`) | Bootstrap ispravno zatvorio |
| `aria-expanded` | `false` | Bootstrap ispravno zatvorio |
| `activeElement === toggle` | `true` | **fokus nije otišao nikuda** |
| `li.matches(':focus-within')` | `true` | **CSS uslov i dalje tačan** |
| `pointer-events` | `auto` | **meni i dalje vizuelno/interaktivno otvoren** |

Sumnja je potvrđena tačno: Bootstrap radi svoj deo posla besprekorno (klasa,
`aria-expanded` - to nikad nije bio problem), ali fokus koji je sam postavio
pri otvaranju ne ide nikuda kad se meni zatvori istim toggle-om, pa
`:focus-within` iz `style.css` (sloj dodat za tastaturu bez JavaScripta)
samostalno i dalje drži meni vizuelno otvorenim, potpuno nezavisno od
Bootstrap-ove odluke.

### Prvi pokušaj rešenja - odbačen, širi test ga je oborio

Prva ideja: u `js/navigacija.js` dodati `click` listener na toggle koji, ako
je `.show` **odsutna** kad se listener izvrši (provereno da se izvršava POSLE
Bootstrap-ovog sopstvenog rukovaoca - `.show` u tom trenutku već odražava
stanje POSLE klika), pozove `toggle.blur()`. Ovo je popravilo klik: drugi
klik je ispravno zatvarao meni i vizuelno, i u DOM-u (`activeElement` prelazi
na `BODY`, `pointer-events: none`).

**Ali pre nego što je ova izmena upisana kao konačna, testirano je i
Escape** - i pokazao je **identičan bag**: Bootstrap posle Escape-a
NAMERNO vraća fokus na toggle (ARIA konvencija za dugme koje otvara meni -
korisnik mora znati gde je posle zatvaranja, ovo je već bilo testirano i
očekivano ponašanje iz ranijeg kruga). `:focus-within` je posle Escape-a
ostajao tačan iz istog razloga kao kod klika, pa je meni ostajao vizuelno
otvoren i posle ispravnog Escape zatvaranja. `toggle.blur()` bi ovde
POPRAVIO vizuelni bag, ali bi **pokvario samu pristupačnost koju Escape
namerno obezbeđuje** - eksplicitan zahtev je da Escape i dalje radi, a
"raditi" ovde uključuje i vraćanje fokusa, već ustanovljeno u ranijem krugu.
Blur-na-klik rešenje je zato odbačeno u celosti, ne samo dopunjeno.

### Pravi uzrok i konačno rešenje

Zajednički uzrok oba slučaja: **CSS nema memoriju uzroka**, samo trenutno
stanje. `:focus-within` ne može da razlikuje "fokus je stigao ovde da bi
zatražio otvaranje" (sveža Tab navigacija) od "fokus je ostao ovde posle
zatvaranja" (Bootstrap ga namerno tu drži ili ga jednostavno nije pomerio) -
oba izgledaju identično kao "trenutno stanje: fokusiran". Nijedno rešenje
zasnovano na diranju fokusa (blur ovde, blur tamo, blur svuda-osim-kad-ne-bi-
trebalo) ne može biti opšte, jer bi svaki takav pokušaj morao unapred da zna
KOJI je od dva slučaja u pitanju - što je tačno ono što CSS ne zna.

Rešenje zato ne dira fokus nikako. Umesto toga, `js/navigacija.js` čim
potvrdi da je Bootstrap dostupan, doda klasu `.js-aktivan` na svaku grupu
(`<li class="dropdown">`). U `style.css`, oba pravila koja koriste
`:focus-within` sad glase `:not(.js-aktivan):focus-within` - čim je ta klasa
prisutna, `:focus-within` sloj se **u potpunosti isključuje** za tu grupu, i
Bootstrap-ova `.show` postaje **jedini** izvor istine za vizuelno stanje.
`.show` je već tačna za svaki način zatvaranja (klik, Escape, klik van menija,
programsko `hide()` iz "samo jedan otvoren" niže u istoj datoteci) jer je
Bootstrap sam vodi kroz svoj Dropdown API - problem nije bio u tome ŠTA
Bootstrap radi, nego u tome što je jedan **drugi**, nezavisni CSS mehanizam
imao pravo veta koje Bootstrap-ovo stanje nije moglo da poništi.

Ovo se uklapa u već postojeću, dokumentovanu nameru iz prethodnog kruga:
komentar u `style.css` je od početka opisivao `:focus-within` kao sloj
"**bez JS-a**: ... (tastatura)" - zamišljen kao rezerva za slučaj da
JavaScript-a nema, ne kao dodatni, trajno aktivan način otvaranja POREĐ
Bootstrap-a dok JS radi. Bag je bio u tome što je taj sloj ostajao aktivan i
posle učitavanja JS-a, i mogao je da "pobedi" Bootstrap-ovu odluku o
zatvaranju. `.js-aktivan` tu nameru čini doslovnom u kodu, ne samo u
komentaru.

### Posledica po tastaturu SA JavaScript-om (namerna, dokumentovana promena)

Pre ove izmene, goli Tab-dolazak na toggle je i sam po sebi otvarao meni
(preko `:focus-within`), čak i kad je JS aktivan. Posle ove izmene, to više
nije slučaj - Tab-dolazak sam ne otvara ništa, dok JS radi. Meni je i dalje
potpuno dostižan i otvoriv tastaturom: **Enter, Space ili strelica dole**,
odmah pošto Tab dovede fokus na toggle, otvaraju ga preko Bootstrap-a, kao i
uvek (provereno - vidi "Testirano"). Ovo je namerna promena, ne previd:
"landing otvara" i "landing pa jedan pritisak otvara" su oba priznati,
uobičajeni obrasci za pristupačne padajuće menije (drugi je, ako nešto,
konvencionalniji - isti obrazac koji Bootstrap sam koristi svuda drugde u
ovoj aplikaciji, npr. za akcije nad događajem u `operater/dogadjaji.php`).

### Posledica po tastaturu BEZ JavaScript-a

Nikakva. Kad JavaScript-a nema (ili Bootstrap ne uspe da se učita),
`js/navigacija.js` se uopšte ne izvrši (rani izlazak iz IIFE-a na
`typeof bootstrap === 'undefined'`, nepromenjen iz prethodnog kruga),
`.js-aktivan` se nikad ne doda, i `:focus-within` ostaje **jedini i potpuno
nepromenjen** mehanizam - identičan onome pre ove izmene. Ponovo provereno
istim postupkom kao u prethodnom krugu (stranica preuzeta, svi `<script>`
tagovi fizički uklonjeni, `autofocus` dodat na toggle kao čist HTML atribut,
otvorena u Chrome-u sa `--disable-javascript`): meni se i dalje otvara bez
ijedne linije JavaScript-a.

### Testirano

**DOM/CSS stanje kroz ceo ciklus klika** (privremeni alat, `dump-dom` uz
`--virtual-time-budget` - pouzdano za sva polja OSIM `opacity`, isti
poznati artefakt iz prethodnog kruga; `pointer-events` NIJE animiran i
pouzdano pokazuje stvarno stanje):
- 1. klik: `.show` prisutna, `pointer-events: auto` - otvoreno;
- 2. klik: `.show` odsutna, `activeElement === toggle` i dalje `true`,
  `:focus-within` i dalje `true`, ali **`pointer-events: none`** - zatvoreno
  I DALJE i pored zaostalog fokusa, jer `.js-aktivan` isključuje taj sloj;
- 3. klik: `.show` ponovo prisutna, `pointer-events: auto` - ponovo otvoreno.
  Toggle radi kao pravi prekidač, otvara-zatvara-otvara.

**Escape, posebno** (isti alat): posle Escape na otvorenom meniju,
`activeElement === toggle` ostaje `true` (fokus se VRATIO, ARIA konvencija
netaknuta) I `pointer-events: none` (meni ispravno zatvoren) - **oba uslova
zadovoljena istovremeno**, što je bilo nemoguće sa blur-zasnovanim pristupom.

**Vizuelno, snimcima ekrana BEZ `--virtual-time-budget`** (stvarno vreme, isti
razlog kao u prethodnom krugu), na pravim stranicama:
- `operater/lige.php`, grupa "Utakmice": klik→otvoreno, klik→**potpuno
  zatvoreno** (nijedan trag menija na snimku), klik→ponovo otvoreno - obe
  teme, identičan ishod;
- `operater/lige.php`, grupa "Šifarnici" posle klika na "Utakmice": vidljiv
  isključivo "Šifarnici" - "samo jedan otvoren" i dalje važi, potvrđeno
  vizuelno;
- `admin/korisnici.php`, grupa "Nadzor": klik-klik → potpuno zatvoreno,
  isti ishod kao kod operatera;
- `375px`, mobilni ugnežđeni prikaz: klik na hamburger pa klik-klik na grupu →
  zatvoreno, bez regresije (mobilni sloj nikad nije koristio `:focus-within`
  za vizuelno stanje, pa ni nije mogao imati ovaj bag, ali ponovo provereno
  da ništa nije slučajno pokvareno);
- fokus (tastatura) sam, bez klika, na 1350px: meni **ostaje zatvoren** -
  potvrđena namerna promena;
- fokus pa strelica dole: meni se otvara, fokus vidljivo prelazi na prvu
  stavku ("Događaji i kvote" sa prstenom fokusa) - Bootstrap-ovo tastaturno
  upravljanje unutar otvorenog menija nedirnuto.

**Bez JavaScript-a**, ponovljeno (fizičko uklanjanje `<script>` tagova +
`--disable-javascript` + `autofocus`): meni se otvara identično kao pre ove
izmene - `:focus-within` sloj potpuno nepromenjen kad JS-a nema.

**Ostalo:** sve stranice obe uloge, obe teme - HTTP 200, bez `Fatal error`/
`Warning`/`Notice`/`Deprecated`/`SQLSTATE`. PHP sintaksa čista na svih 54
fajla, `{`/`}` u `style.css` balansirano.

Privremeni dijagnostički alati (četiri odvojene probne stranice - stanje kroz
klik, redosled događaja, proba rešenja, Escape) nisu deo aplikacije i
obrisani su, zajedno sa probnim nalozima. U bazi su ostala zatečena 3 naloga,
12 događaja, 24 tima.

---

## Klik na drugu kvotu istog događaja sada zamenjuje, ne odbija (2026-08-30)

Poslovno pravilo 2 (najviše jedna kvota po događaju na tiketu) ostaje na
snazi - menja se samo put do njega. Ranije je klik na drugu kvotu istog
događaja bio odbijen porukom „Sa jednog događaja može biti samo jedan par na
tiketu.". Sada tu staru kvotu **zamenjuje** novom.

### `ajax/tiket_dodaj.php`

Zamena se radi isključivo na serveru; klijent i dalje šalje samo `kvota_id`,
ništa u zahtevu nije promenjeno. Petlja koja je ranije odbijala klik na drugom
pogotku (isti `dogadjaj_id`) sad umesto toga tu stavku izbacuje iz nove liste
pre nego što doda novu, i pamti `kvota_id` izbačene stavke.

Slučaj kad je poslata kvota **ista** ona koja je već na tiketu ostaje
netaknut - i dalje se odbija porukom „Ovaj par je već na tiketu.", ne
„zamenjuje sama sobom". Ovo se u redovnom radu ionako ne dešava: klik na već
izabrano dugme u ponudi `js/tiket.js` šalje na `tiket_ukloni.php`, ne ovde
(bira fajl po klasi `.izabrana`) - do ovog puta bi se stiglo samo sa
zastarelim stanjem na klijentu (npr. druga otvorena kartica).

Odgovor sad nosi `zamenjena_kvota_id` (id izbačene kvote, ili `null` kad
zamene nije bilo) i, samo kad je do zamene došlo, kratku `poruka` za
korisnika: „Prethodni par sa ovog događaja je zamenjen novim.".

### JS nije trebalo menjati

Provereno pre pisanja bilo čega u `js/tiket.js`: `oznaciKvoteUPonudi()` već
posle **svakog** AJAX odgovora prođe kroz **sve** `.dugme-kvota` na strani i
klasu `.izabrana` postavi prema tome da li se dugme nalazi u vraćenom spisku
`stavke` - ne dodaje je samo na kliknuto dugme. Kad server vrati novu listu
stavki (staru kvotu zamenjenu novom), ovaj mehanizam sam skine `.izabrana` sa
stare i doda je na novu, bez ijedne izmene u JS-u. Isto važi i za poruku:
`osveziPanel()` već ispisuje `odgovor.poruka` kao `alert-success` kad je
`uspeh: true` - dovoljno je bilo da server pošalje nenultu `poruka` samo u
slučaju zamene.

Ovo pokriva i kvote iza dugmeta „+N": `.kvote-dodatne` je u DOM-u od učitavanja
strane (samo sakriven sa `d-none`, ne dodat naknadno preko JS-a), pa ga
`document.querySelectorAll('.dugme-kvota')` u `oznaciKvoteUPonudi()` ionako
zahvata bez obzira na vidljivost.

### `ajax/tiket_uplati.php` - nezavisna provera pravila 2

Do sada uplata nije nigde EKSPLICITNO proveravala pravilo 2 - oslanjala se na
to da sesija nikad neće sadržati dve kvote istog događaja, jer je jedini put
kojim se sesija punila (`tiket_dodaj.php`) to sprečavao odbijanjem. Ta
pretpostavka je posle ove izmene i dalje tačna (zamena umesto odbijanja i
dalje garantuje najviše jednu kvotu po događaju), ali zahtev je bio izričit:
uplata mora poštovati pravilo 2 **nezavisno** od toga kako je tiket sastavljen.
Dodata je eksplicitna provera - petlja preko `dogadjaj_id` svih stavki, uz
odbijanje ako se neki ponovi - odmah posle provere da se ponuda nije u
međuvremenu promenila, pre bilo kakvog upisa u bazu.

### Testirano

Preko pravog HTTP-a, probnim igračkim nalogom:
- dodavanje kvote na prazan tiket - bez poruke, kao i pre;
- dodavanje **druge** kvote sa istog događaja (`dogadjaj_id=5`, "1" pa "2") -
  odgovor sadrži `zamenjena_kvota_id` stare kvote i poruku o zameni, `stavke`
  sadrži samo novu;
- slanje **iste** kvote koja je već na tiketu direktno na `tiket_dodaj.php`
  (zaobilazeći JS routing) - i dalje odbijeno porukom „Ovaj par je već na
  tiketu.", tiket nepromenjen;
- dodavanje kvote sa **drugog** događaja dok je nešto već na tiketu - prosto
  dodato, bez zamene, postojeća stavka netaknuta;
- zamena kvotom iz skrivenog „+N" panela (`GG`, `dogadjaj_id` isti kao
  vidljiva "1"/"X"/"2") - zamena radi identično kao za vidljive kvote.

Kroz pravi pregledač (headless Chrome, bez izmene `js/tiket.js`): klik na "1",
pa klik na "GG" iz otvorenog „+N" panela - `.izabrana` se ispravno premešta
(`"1"` gubi klasu i `aria-pressed` postaje `"false"`, `"GG"` dobija oboje),
poruka se ispisuje sa klasom `alert-success` i tačnim tekstom, tiket i dalje
ima tačno jednu stavku.

**Nezavisna provera u uplati**, direktno testirana: kvote `[37, 39]` (obe sa
`dogadjaj_id=5`) silom ubačene u sesiju mimo `tiket_dodaj.php` (privremeni
dijagnostički skript, van aplikacije) - `tiket_sadrzaj.php` verno prikazuje
oba (čitanje ne proverava pravilo 2, samo upis), a `tiket_uplati.php` odbija
uplatu porukom o dva para sa istog događaja, **bez upisa reda u tabelu
`tiket`** (0 pre, 0 posle pokušaja). Normalna uplata posle toga i dalje radi
neometano (1 tiket upisan, račun umanjen za ulog).

Privremeni dijagnostički skript (silovanje sesije mimo `tiket_dodaj.php`) i
probna HTML stranica za headless test nisu deo aplikacije i obrisani su,
zajedno sa probnim igračkim nalogom i njegovim tiketima/transakcijama. U bazi
su ostala zatečena 3 naloga, 12 događaja, 24 tima.

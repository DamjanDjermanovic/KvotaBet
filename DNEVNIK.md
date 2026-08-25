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

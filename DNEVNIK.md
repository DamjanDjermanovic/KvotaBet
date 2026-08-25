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

## ~~Napomena — greska.php ne postoji~~ (REŠENO u fazi 10)

_Zabeleženo posle faze 9, rešeno u fazi 10 — ostavljeno radi istorije._

`zahtevaj_ulogu()` (od faze 3) i `operater/dokument_preuzmi.php` (faza 7)
preusmeravali su na `greska.php?kod=403` / `kod=404` kad pristup nije dozvoljen,
a ta stranica tada još nije postojala, pa je pokušaj pristupa vodio ka
nepostojećoj adresi. Provera prava je i tada radila ispravno (pristup je bio
blokiran), nedostajala je samo stranica na kraju preusmeravanja. Napravljena je
u fazi 10 i provereno je da oba slučaja sada prikazuju „Zabranjen pristup".

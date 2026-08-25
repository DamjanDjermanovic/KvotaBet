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

## Napomena — greska.php ne postoji

`zahtevaj_ulogu()` (od faze 3) i `operater/dokument_preuzmi.php` (faza 7)
preusmeravaju na `greska.php?kod=403` / `kod=404` kad pristup nije dozvoljen,
ali ta stranica još nije napravljena - to je deo faze 10 (obrada grešaka),
koja nije bila deo ovog kruga izrade. Do tada takav pokušaj pristupa vodi ka
nepostojećoj stranici umesto ka lepoj poruci. Provera prava sama po sebi radi
ispravno (pristup je blokiran), nedostaje samo prijateljska stranica na kraju
preusmeravanja.

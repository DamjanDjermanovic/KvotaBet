# Projekat: Veb aplikacija za upravljanje kladionicom

Fakultetski projekat. Cilj je aplikacija koja simulira rad kladionice i proces klađenja.
**Sve je simulacija sa virtuelnim novcem** — nema pravih uplata, platnih kartica, niti
integracije sa spoljnim servisima.

---

## KRITIČNA OGRANIČENJA — nikad ih ne kršiti

Ovo su pravila iz zvaničnog uputstva predmeta. Kršenje bilo kog znači pad projekta.

1. **Zabranjen je bilo koji PHP framework.** Nema Laravela, Symfonyja, CodeIgnitera,
   Slima. Nema Composera i nema `vendor/` foldera. Nema autoload-a preko biblioteka.
   Sve se piše ručno, običnim `require_once`.
2. **Kod mora biti ručno pisan i komentarisan na srpskom.** Svaka funkcija dobija kratak
   komentar iznad — šta radi, šta prima, šta vraća. Ključni blokovi unutar funkcija
   takođe. Komentari objašnjavaju *zašto*, ne prepričavaju sintaksu.
3. **Baza se koristi isključivo preko PDO** sa pripremljenim upitima (`prepare` +
   `execute` sa parametrima). Nikad konkatenacija promenljivih u SQL string.
4. **Puni CRUD nad svakom tabelom.** Za svaku tabelu mora postojati kreiranje, čitanje,
   izmena i brisanje kroz interfejs — makar iz admin panela.
5. **Validacija mora postojati i na klijentu i na serveru.** Klijentska (JS) je zbog
   udobnosti, serverska je jedina koja se računa. Nikad ne verovati podacima sa klijenta.
6. **Frontend:** HTML5, CSS3 i Bootstrap 5. Bootstrap se povezuje preko CDN-a.
   JavaScript je čist, vanilla — bez jQueryja i bez React-a.
7. **AJAX se radi preko `fetch()`** ka fajlovima u `/ajax/` koji vraćaju JSON.

---

## Obavezni elementi (checklist iz uputstva)

Ovih 11 stavki se ocenjuje. Prati status ovde i ažuriraj kad se nešto završi.

- [ ] 1. Baza modelovana prema poslovnim procesima
- [ ] 2. Korisnički interfejs za sve tipove korisnika (HTML/CSS/Bootstrap)
- [ ] 3. Ručno pisan PHP za sve funkcionalnosti
- [ ] 4. Komentari u kodu
- [ ] 5. Kolačići, sesije i AJAX
- [ ] 6. Kompletan CRUD nad **svim** tabelama preko PDO
- [ ] 7. Provera podataka na klijentu i serveru
- [ ] 8. Obrada grešaka
- [ ] 9. Upload fajlova uz provere i ograničenja
- [ ] 10. Upis u tekstualni fajl i čitanje sadržaja tekstualnog fajla
- [ ] 11. Statistika pristupa sistemu, zavisno od tipa korisnika

### Gde su "lako zaboravljene" stavke rešene

| Stavka | Rešenje u ovom projektu |
|---|---|
| Kolačići | "Zapamti me" na prijavi + čuvanje izabrane lige u ponudi |
| Sesije | Prijava, uloga korisnika, tiket u izradi (korpa) |
| AJAX | Dodavanje para na tiket, živi proračun kvote i dobitka, provera zauzetosti emaila pri registraciji, pretraga ponude |
| Upload | Verifikacioni dokument igrača (lična karta kao JPG/PNG/PDF) |
| Tekstualni fajl | `logovi/pristup.log` i `logovi/greske.log` — upis pri svakom pristupu, čitanje i prikaz u admin panelu |
| Statistika | Admin vidi sve; operater vidi statistiku ponude i tiketa; igrač vidi samo svoju istoriju prijava |

---

## Tipovi korisnika i njihova prava

| Uloga | Može |
|---|---|
| **Neprijavljeni (gost)** | Pregled ponude i kvota, pretraga i filtriranje, registracija, prijava |
| **Igrač** | Sve gore + uplata na virtuelni račun, sastavljanje i uplata tiketa, istorija tiketa i transakcija, izmena profila, slanje verifikacionog dokumenta |
| **Operater** | Unos i izmena sportova, liga, timova, događaja i kvota; zaključivanje događaja i unos rezultata; obrada verifikacionih dokumenata; pregled tiketa |
| **Administrator** | Sve gore + upravljanje korisnicima i ulogama, blokiranje naloga, pregled log fajlova, kompletna statistika pristupa, brisanje bilo kog zapisa |

Provera prava ide **na vrhu svake stranice**, pre bilo kakvog ispisa, kroz
`zahtevaj_ulogu()` iz `includes/autentifikacija.php`.

---

## Struktura projekta

```
kladionica/
├── config/
│   ├── baza.php              PDO konekcija (jedino mesto sa kredencijalima)
│   ├── konfiguracija.php     konstante: putanje, limiti uploada, min/max ulog
│   ├── api.php               ključ za The Odds API — NIJE u gitu
│   └── api.primer.php        šablon za api.php, sa praznim ključem
├── includes/
│   ├── autentifikacija.php   prijava, odjava, provera uloge, "zapamti me"
│   ├── validacija.php        funkcije za proveru unosa na serveru
│   ├── funkcije.php          pomoćne funkcije (formatiranje, redirekcija, escape)
│   ├── log.php               upis i čitanje tekstualnih log fajlova
│   ├── odds_api.php          poziv spoljnog servisa sa kvotama + keširanje
│   ├── uvoz.php              pretvaranje odgovora servisa u našu šemu, upis
│   ├── zaglavlje.php         HTML head, navigacija (menja se prema ulozi)
│   └── podnozje.php          footer, zatvaranje tagova, JS skripte
├── ajax/                     endpointi koji vraćaju JSON
├── igrac/                    stranice dostupne igraču
├── operater/                 stranice dostupne operateru
├── admin/                    stranice dostupne administratoru
├── css/style.css             sopstveni stilovi (Bootstrap ide preko CDN-a)
├── js/                       vanilla JS: validacija, AJAX, tiket
├── uploads/dokumenta/        otpremljeni verifikacioni dokumenti
├── kes/                      keširani odgovori spoljnog servisa (nisu u gitu)
├── logovi/                   pristup.log, greske.log
├── baza/                     schema.sql, test_podaci.sql, migracija_api.sql
├── dokumentacija/            materijal za Word dokumentaciju
└── index.php                 početna strana sa ponudom
```

**Uvoz sa spoljnog servisa je pomoćni alat, ne oslonac.** Ručni unos ponude mora
raditi bez interneta, bez ključa i sa praznom tabelom `api_liga`. Nijedna stranica
osim `operater/uvoz.php` i `operater/api_lige.php` ne sme uključivati
`includes/odds_api.php`.

---

## Konvencije koda

- Nazivi tabela, kolona, promenljivih i funkcija su **na srpskom, latinicom, bez kvačica**:
  `korisnik`, `stanje_racuna`, `proveri_email()`. Ključne reči PHP-a ostaju engleske.
- Funkcije `snake_case`, konstante `VELIKIM_SLOVIMA`.
- Svaki fajl počinje `<?php` bez zatvarajućeg `?>` ako je čisti PHP.
- Svaki izlaz u HTML prolazi kroz `bezbedno()` (wrapper oko `htmlspecialchars`) — zaštita od XSS.
- Svaka forma nosi CSRF token; provera je obavezna pre obrade.
- Novac je uvek `DECIMAL(10,2)` u bazi i nikad `float` u proračunima gde je moguće.

## Obrada grešaka

- PDO je u `ERRMODE_EXCEPTION` režimu.
- Svaki upit ide u `try/catch`. U `catch`: greška se upisuje u `logovi/greske.log`
  preko `upisi_gresku()`, a korisniku se prikazuje **razumljiva poruka na srpskom**,
  nikad sirovi tekst izuzetka.
- Postoje `404.php` i `greska.php` stranice.
- U produkcionom režimu `display_errors` je isključen.

## Bezbednost

- Lozinke isključivo preko `password_hash()` / `password_verify()`. Nikad MD5 ili SHA1.
- Pripremljeni upiti svuda.
- Upload: provera MIME tipa preko `finfo`, provera ekstenzije, ograničenje veličine (5 MB),
  generisanje novog nasumičnog imena fajla, folder van dosega direktnog izvršavanja PHP-a.
- Registracija proverava da je korisnik stariji od 18 godina — i na klijentu i na serveru.
- Sesija se regeneriše (`session_regenerate_id`) posle uspešne prijave.

---

## Poslovna pravila kladionice

Ovo je logika koju aplikacija mora poštovati:

1. Tiket se sastoji od jedne ili više stavki; svaka stavka je jedna kvota jednog događaja.
2. **Na jednom tiketu ne mogu biti dve kvote iz istog događaja.**
3. Ukupna kvota tiketa = proizvod kvota svih stavki. Potencijalni dobitak = ulog × ukupna kvota.
4. Ulog mora biti između `MIN_ULOG` i `MAX_ULOG` iz konfiguracije, i ne veći od stanja računa.
5. **Vrednost kvote se pamti u trenutku uplate tiketa** (`tiket_stavka.kvota_vrednost`).
   Kasnija izmena kvote od strane operatera ne sme promeniti već uplaćen tiket.
6. Klađenje na događaj je moguće samo dok je status `najavljen` i dok nije prošlo vreme početka.
7. Kad operater unese rezultat, sistem prolazi kroz sve aktivne stavke tog događaja,
   označava ih kao pogođene ili promašene, pa preračunava status tiketa:
   - jedna promašena stavka → tiket je `gubitan`
   - sve stavke pogođene → tiket je `dobitan`, dobitak se uplaćuje na račun kroz transakciju
8. Svaka promena stanja računa **mora** proizvesti zapis u tabeli `transakcija`.
   Stanje računa nikad se ne menja "u prolazu" bez traga.
9. Uplata tiketa i skidanje uloga rade se u **transakciji baze** (`beginTransaction` / `commit` / `rollBack`).

---

## Redosled izrade

Radi fazu po fazu. Ne prelazi na sledeću dok prethodna ne radi i ne bude testirana.

1. Baza — pokretanje `schema.sql` i `test_podaci.sql`
2. Konfiguracija, PDO konekcija, pomoćne funkcije, logovanje
3. Registracija, prijava, odjava, sesije, kolačić "zapamti me", zaštita stranica
4. Javna ponuda: prikaz sportova, liga, događaja i kvota + filtriranje
5. Tiket: AJAX dodavanje stavki, proračun kvote, uplata sa transakcijom baze
6. Igračev panel: uplata sredstava, istorija tiketa i transakcija, profil, upload dokumenta
7. Operaterski panel: CRUD nad sportovima, ligama, timovima, događajima i kvotama
8. Obrada rezultata i automatsko rešavanje tiketa
9. Admin panel: korisnici, uloge, čitanje logova, statistika pristupa
10. Obrada grešaka, doterivanje izgleda, testiranje svih 11 stavki iz checkliste
11. Materijal za dokumentaciju

---

## Okruženje

XAMPP ili Laragon, PHP 8.1+, MySQL/MariaDB, Apache.
Projekat stoji u `htdocs/kladionica/`, otvara se na `http://localhost/kladionica/`.
Baza: `kladionica`, korisnik `root`, prazna lozinka (menja se u `config/baza.php`).

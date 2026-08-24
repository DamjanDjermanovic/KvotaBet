# Pokretanje projekta

## 1. Okruženje

Instaliraj **XAMPP** (ili Laragon). Potreban je PHP 8.1 ili noviji.
Prekopiraj ceo folder `kladionica` u `C:\xampp\htdocs\`.
Pokreni Apache i MySQL iz XAMPP kontrolne table.

## 2. Baza

Otvori `http://localhost/phpmyadmin`, idi na karticu **SQL** i izvrši sadržaj fajla
`baza/schema.sql`. Skripta sama briše i pravi bazu `kladionica`, tako da se može
pokrenuti više puta bez problema.

Provera: u listi sa leve strane treba da se pojavi 13 tabela.

## 3. Claude Code

U terminalu:

```
cd C:\xampp\htdocs\kladionica
claude
```

Claude Code automatski učitava `CLAUDE.md` iz korenskog foldera, pa ne treba ništa
posebno da mu objašnjavaš o pravilima projekta.

### Prva komanda

```
Pročitaj CLAUDE.md i baza/schema.sql. Zatim uradi fazu 2 iz redosleda izrade:
config/baza.php, config/konfiguracija.php, includes/funkcije.php i includes/log.php.
Ništa više od toga.
```

## 4. Kako raditi sa Claude Code na ovom projektu

**Jedna faza po jednom razgovoru.** Ako mu daš "napravi celu aplikaciju", dobićeš
1500 linija koje ne razumeš i koje nećeš umeti da odbraniš. Faze iz `CLAUDE.md`
su namerno male.

**Posle svake faze uradi tri stvari:**

1. Otvori stranicu u browseru i proveri da radi.
2. Pročitaj kod koji je napisan. Ako ne razumeš neku liniju, pitaj:
   *"Objasni mi liniju 34 u includes/autentifikacija.php, na srpskom."*
3. Odštikliraj stavku u checklisti u `CLAUDE.md`.

**Korisne komande tokom rada:**

```
Objasni mi šta radi ova funkcija, kao da spremam usmenu odbranu.
```

```
Proveri da li sam negde koristio konkatenaciju u SQL upitu umesto pripremljenog upita.
```

```
Prođi kroz checklistu u CLAUDE.md i reci mi koje stavke još nisu pokrivene u kodu.
```

```
Pripremi mi izvod koda za registraciju korisnika, sa komentarima,
u obliku koji mogu da nalepim u Word dokumentaciju.
```

## 5. Odbrana projekta

Sve što Claude Code napiše treba da umeš da objasniš. Pre odbrane obavezno prođi:

- kako radi `password_hash` i zašto ne MD5
- šta je pripremljeni upit i od čega štiti
- razlika između sesije i kolačića, i gde si koji koristio
- šta se dešava korak po korak kad igrač klikne "Uplati tiket"
- zašto se kvota pamti u `tiket_stavka.kvota_vrednost`
- razlika između konceptualnog, logičkog i fizičkog modela na primeru tabele `kvota`

## 6. Predaja

Dokumentacija se predaje kao PDF, naziv fajla:
`ime_prezime_brojIndeksa.pdf` — bez kvačica i razmaka, minimum 15 strana A4.
Kod u dokumentaciji ide u textbox, **nikako kao slika**.

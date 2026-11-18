# KvotaBet

A sportsbook simulation web app: players build and place betting slips with virtual money, operators manage the offer and enter results, and administrators manage users and review access statistics. It started as a course project at VIŠER (New Computer Technologies) and is now my thesis. There is no real money and no payment integration.

> Code, database names and comments are in Serbian, as the course requires.

## Highlights

- **Odds import from The Odds API.** Operators map API leagues to local leagues, then import events and odds. Responses are cached on disk (30 minutes for odds, 24 hours for the sports list), and manual entry still works with no network or API key.
- **Safe ticket payment.** Payment runs in one database transaction. It locks the events on the slip (sorted by id) and then the player's row; result processing locks in the same order, so the two can never deadlock each other. Odds are stored on each ticket line at payment time, so later odds changes never alter a placed ticket.
- **Idempotent settlement.** Entering a result locks the event and checks its status, and every affected ticket is locked and settled only while it is still active, so submitting the same result twice cannot pay out twice. Lines on cancelled events count as odds 1.00, and a ticket whose events were all cancelled refunds the stake.
- **Audit trail.** Every balance change writes a row to the `transakcija` table with the balance after the change. Money is stored as `DECIMAL(10,2)`, and the database does the arithmetic.
- **Security.** PDO prepared statements throughout, `password_hash` (bcrypt), CSRF tokens on forms, a new session ID after login, and uploads checked by their real MIME type (`finfo`), extension and size, stored under random names and served only through a protected script.
- **Roles.** Guest, player, operator and administrator, checked at the top of every page.
- **Front end.** Vanilla JavaScript with `fetch()` for the live slip (total odds and payout update as picks are added), Bootstrap 5, and a light and dark theme.

About 11,000 lines of PHP and JavaScript across 14 database tables.

## Tech stack

PHP 8.1+ with no framework and no Composer (a course requirement) · MySQL/MariaDB through PDO · vanilla JavaScript · Bootstrap 5 (CDN) · Apache (XAMPP or Laragon)

## Getting started

1. Copy the project into your web root as `kladionica` (for example `C:\xampp\htdocs\kladionica`) and start Apache and MySQL.
2. In phpMyAdmin, run `baza/schema.sql`. It creates the `kladionica` database with all 14 tables. For sample data, run `baza/test_podaci.sql` as well.
3. Database credentials are in `config/baza.php` (default: user `root` with an empty password).
4. Optional odds import: copy `config/api.primer.php` to `config/api.php` and add a free key from the-odds-api.com. `config/api.php` is ignored by git.
5. Open `http://localhost/kladionica/`.

On Linux, the web server user needs write access to `logovi/`, `uploads/dokumenta/` and `kes/`.

## Project structure

| Folder | Contents |
| --- | --- |
| `ajax/` | JSON endpoints for the betting slip |
| `igrac/` | Player pages: deposits, tickets, transactions, profile and ID upload |
| `operater/` | Operator pages: sports, leagues, teams, events, odds, results and the API import |
| `admin/` | Admin pages: users, roles, tickets, transactions, documents, logs and statistics |
| `includes/` | Shared PHP: authentication, validation, settlement, the odds API client and import |
| `baza/` | Schema, sample data and migrations |
| `js/`, `css/` | Front-end scripts and styles |

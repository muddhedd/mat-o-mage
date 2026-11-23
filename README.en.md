# Mat-å-Mage

*[Svensk version](README.md)*

Log what you do and how it feels with a single tap, and find the connections between them. The tool was built for food and stomach ("what happens after I drink chocolate milk?"), but it works for any actions and consequences: exercise and sleep, medication and headaches, coffee and mood. The name is Swedish wordplay meaning roughly "Food and Tummy".

It is written in PHP and MySQL/MariaDB, built for mobile, with no framework and no build step.

> The user interface is in **Swedish**. This README explains everything in English.

## Features

**Logging**
- Your own buttons for **actions** (e.g. Apple, Yoghurt, Oboj chocolate milk) and **consequences** (e.g. Gassy, Cramps). One tap logs.
- **Log after the fact**: "Nu" (now), −30 min, −1 h, −2 h or any time.
- **Intensity** for consequences (mild, strong, severe) and a **comment** right after the tap, both optional.
- **Undo** a mis-tap with a single tap.
- **The log** below the buttons. Tap a row to change its time, intensity or comment, or to delete it.
- **Works offline**: entries are stored on the phone and sent when the connection is back.
- **Home screen icon**: you can add the page to your home screen and open it like an app.

**Buttons**
- Add, rename, pick a colour and reorder.
- Buttons with history are **archived** instead of deleted, so the analysis keeps the data. They can be restored.
- **Rename the two categories**: the default is "Mat" (food) and "Mage" (stomach), but they could be "Exercise" and "Sleep", for example.

**Analysis**
- Pick an **action** to see what happened in the **following 72 hours**, in 12-hour intervals.
- Pick a **consequence** to see which actions occurred in the **72 hours before**.
- Each cell shows *"7/9, normalt 3,1"*: it happened after 7 of 9 occasions, while chance alone would give about 3.
- ▲ and ▲▲ mark results that are **probably not chance** (binomial test, below 5 % and 1 % risk). ▼ marks things that happen clearly *less* often than usual.
- **Hide** buttons you're not interested in. The choice is saved.
- Filter by **date** and **intensity**, and choose the **window** (24 h–7 days) and **interval** (6, 12 or 24 h).

**Export**
- **"Exportera för AI"**: one file (.md) with instructions at the top and the data in CSV format below. Upload it to an AI assistant and ask it to analyse the log.
- **"Exportera CSV"**: plain data for Excel, Numbers or Google Sheets, semicolon-separated (Swedish Excel locale) with UTF-8 BOM.

## How correlations are calculated

1. **Occasions, not entries.** Entries of the same button within 3 hours (configurable) count as one occasion, so two cups of coffee in one morning aren't counted twice.
2. **Hits.** For each occasion and time interval, the tool checks whether the other button was logged at least once in that interval.
3. **Baseline with daily rhythm.** For each occasion, the tool checks the same time window, at the same time of day, up to 45 days before and after. The share of those days where the other button appears in the window is the baseline, and the sum over all occasions gives the expected count. That way breakfast doesn't become a "cause" just because symptoms are usually logged during the day.
4. **Significance.** A binomial test gives the probability of getting at least that many hits by chance. Below 5 % gives ▲ and below 1 % gives ▲▲. At least 3 occasions are required.
5. **Only what could have happened counts.** Intervals that lie in the future, or before the first entry, are not counted.

> With many cells, the odd ▲ will appear by pure chance. ▲▲, and correlations that show up in several columns, are more reliable. Things that often occur together (e.g. a cheese sandwich and coffee at breakfast) can share a correlation. Few occasions give uncertain results. The tool finds correlations, not causes, and is no substitute for medical care.

## Requirements

- PHP 7.4 or later (8.x recommended) with `mysqli` (mysqlnd) and `mbstring`
- MySQL 5.7+ or MariaDB 10.3+ (InnoDB)
- HTTPS is recommended, and most phones require it for the home screen icon.

## Installation

1. **Create a database** and run `sql/schema.sql`, for example in phpMyAdmin under the SQL tab.
2. **Sample data (optional):** run `sql/exempeldata.sql`. It contains a 60-day log with two built-in correlations: *Oboj → Gasig* (chocolate milk → gassy) after 12–24 h, and *Glass → Lös i magen* (ice cream → loose stomach) within a few hours. Everything else is random, so you can see how the analysis separates real correlations from noise. The data goes on user ID 1.
3. **Copy** `config.example.php` to `config.php` and fill in the database details and `LOGIN_USER`.
4. **Upload** the folder to your web server.
5. **Open** `matamage.php`. The first time, a setup guide appears:
   1. Choose a password, and a hash is created for it.
   2. Paste it into `config.php` as `LOGIN_PASSWORD_HASH` and reload the page.
   3. Log in.
6. **On your phone:** choose "Share → Add to Home Screen" (iPhone) or "Install app" (Android).

`config.php` is listed in `.gitignore`, so your credentials never end up in git.

## Login modes

| Mode | Description |
|---|---|
| `login` | Built-in login with one username and password. The default. |
| `demo` | No login. Each visitor gets their own sandbox with sample data, deleted after `DEMO_TTL_HOURS` hours. Requires `sql/demo.sql`. |
| `ll2` | Shares the session with an existing login system (`ll2.php`). Used in the author's own installation, and you can ignore it. |

## Files

| File | Contents |
|---|---|
| `matamage.php` | The whole tool: logging, buttons, analysis, export and app manifest |
| `mam-ikon-*.png` | Home screen icons |
| `common.php` | Shared hub: database, login, CSRF protection, menu and demo mode |
| `config.example.php` | Settings template |
| `demo.php` | Copies sample data into a visitor's sandbox (demo mode only) |
| `minsida.css` | Styling |
| `sql/` | `schema.sql`, `exempeldata.sql` (sample data) and `demo.sql` |

## License

MIT – see [LICENSE](LICENSE).

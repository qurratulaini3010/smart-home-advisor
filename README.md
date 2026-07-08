# Smart Home Advisor

A PHP web application that helps property buyers find homes that match their financial situation, lifestyle, and smart-home preferences. Buyers fill out a short assessment (budget, income, household size, occupation, smart-home priorities), and the system scores every property in the catalogue against that profile, explaining each score with plain-language rule-based badges.

[Go to System n rules fire explain](README2(1).md)

for details on rule fire and system explaination
---

## 1. System Overview

### What it does

A logged-in user completes an **assessment** (income, commitments, budget, household size, occupation, and which smart-home features they care about). The system then:

1. **Scores every property** against that assessment on five weighted dimensions — affordability, security, smart-home readiness, environment/sustainability, and family suitability — producing a single match percentage per property.
2. **Explains the score** using a separate rules engine that fires plain-language badges ("Within your budget," "High flood risk — factor in insurance," "Hospital nearby — important for retirees") so the user understands *why* a property scored the way it did, not just the number.
3. Lets the user **browse, filter, and shortlist** properties in a directory view, and lets staff/admin manage the property catalogue.

### Architecture at a glance

The app is plain PHP (no framework) following a light MVC-style split:

| Layer | Location | Responsibility |
|---|---|---|
| Entry point | `public/index.php` | Single front controller. Routes via `?page=` query string (no URL rewriting required), renders all pages inline. |
| API endpoints | `public/api/*.php` | JSON endpoints called by the frontend JS (`properties.php`, `property-directory.php`, `rules.php`). |
| Core services | `app/core/` | `Auth.php` (session-based login), `Database.php` (PDO/MySQL singleton connection), `Csrf.php` (CSRF token handling). |
| Business logic | `app/models/` | `RecommendationEngine.php` (the weighted scoring algorithm), `PropertyRepository.php` / `PropertyDirectoryRepository.php` (property queries/filtering). |
| Frontend | `public/assets/` | Vanilla JS (no build step) + Bootstrap 5 for styling, loaded via CDN. |
| Database | `database/` | Schema, migrations, and one-off import/seed scripts (run manually, not by the web app). |

### The two engines that drive recommendations

This system has **two separate but related decision-making components**, both keyed off the same user assessment and property data:

- **`RecommendationEngine::score()`** (`app/models/RecommendationEngine.php`) — produces the numeric match percentage (0–100) per property, using fixed weights across five dimensions (affordability 30%, security 20%, smart-home 20%, environment 15%, family 15%).
- **`buildRules()` / `evaluateRules()`** (`public/api/rules.php`) — a ~108-rule forward-chaining rules engine ("CLIPS-style") that fires explanatory badges (positive / info / warning) per property, grouped into 8 domains (Affordability, Safety, Family, Smart Home, Occupation, etc.).

Both pull occupation classification from a single shared, mutually-exclusive classifier (`RecommendationEngine::classifyOccupation()`) so a user's job title (e.g. "Retired Civil Servant") is always categorized the same way in both the score and the badges.

### Known orphaned code (not wired into the live app)

Two groups of files exist in the codebase but are not currently reachable from any page, button, or script — they were either superseded or never finished. They're harmless to leave in place, but worth knowing about if you're exploring the codebase:

- **Insights feature**: `database/schema_insights.sql`, `database/import_excel.php`, `public/api/insights.php` — a self-contained analytics table/API with no frontend consumer.
- **Property components prototype**: `public/components/customer-property-cards.html`, `public/components/staff-property-table.html`, `public/assets/js/property-components.js` — an earlier draft of the property directory UI, superseded by `public/partials/property_directory_tabs.php` + `public/assets/js/property-advisor.js`, which is what the live app actually uses.

---

## 2. Setup on a New Laptop

### Requirements

- **PHP 8.1 or newer** (the code uses the `never` return type, which requires 8.1+), with the **`pdo_mysql`** extension enabled.
  - On Debian/Ubuntu, installing `php-cli` alone does **not** include this — you also need `php-mysql` (`sudo apt install php-cli php-mysql`), confirmed by testing: a fresh `php-cli`-only install fails the import script with `could not find driver`.
- **MySQL** (or MariaDB) 5.7+/10.3+
- A local web server — either PHP's built-in server (good enough for development, no extra install needed) or Apache/Nginx with PHP-FPM
- No `npm`/`composer` install step — there is no `package.json` or `composer.json`. All frontend libraries (Bootstrap 5.3.3, Font Awesome, Chart.js, Google Fonts) load from public CDNs, so **an internet connection is required for the styling/UI to render correctly**, even though all backend logic runs locally.

### Step 1 — Get the code

Copy or extract the project folder. The app's own config (`app/config.php`) builds its base URL assuming the folder is named exactly:

```
smart-home-advisor-system
```

with `public/` as the web root inside it. If you use a different folder name, you'll need to update `APP_URL` in `app/config.php` (see Step 4) to match.

### Step 2 — Create the database

Open a MySQL client (MySQL Workbench, phpMyAdmin, Adminer, or the `mysql` CLI) and create the database and base schema:

```bash
mysql -u root -p < database/schema.sql
```

This creates the `smart_home_advisor` database and its 7 core tables (`users`, `properties`, `assessment_criteria`, `assessments`, `recommendations`, `favorites`, `smart_home_features`).

> **Note:** `database/properties_table.sql` is a leftover alternate setup script that creates its own competing version of the database/`properties` table. **Don't run it** — use `schema.sql` as the source of truth, followed by the migrations below, which is what the live application's queries actually expect.

### Step 3 — Run the required migration

The base schema already includes `monthly_commitment` and `net_income` on the `assessments` table, but it doesn't yet have all the extra property columns the recommendation/rules engines and the sample property dataset depend on (flood risk, crime risk, safety score, distances to school/hospital/transport, rental yield, etc.). Run this one migration after `schema.sql`:

```bash
mysql -u root -p smart_home_advisor < database/migrate_properties_dataset.sql
```

This adds roughly 20 columns to `properties` — without it, the next step (importing the CSV) will fail, since the CSV has columns the base table doesn't have yet.

> **Note on `database/migrate_add_commitment.sql`:** this file is **not needed on a fresh install** — it exists only to upgrade an older database created before `monthly_commitment`/`net_income` were merged into `schema.sql` directly. Running it on a brand-new database will fail with `Duplicate column name 'monthly_commitment'`, since `schema.sql` already created it. Skip it unless you're upgrading an existing, older copy of this database.

### Step 4 — Configure database credentials

Open `app/config.php` and confirm or update these values to match your local MySQL setup:

```php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'smart_home_advisor');
define('DB_USER', 'root');
define('DB_PASS', '');   // set this if your local MySQL root user has a password
```

There is no `.env` file in this project — all configuration lives directly in this one PHP file. If your folder name isn't `smart-home-advisor-system`, also update the path segment in `APP_URL`:

```php
define('APP_URL', $protocol . '://' . $host . '/smart-home-advisor-system/public');
```

### Step 5 — Load the sample property data

The repo includes a real property dataset as a CSV. Load it with the import script:

```bash
php database/import_properties.php
```

This reads `database/property_data.csv` and inserts each row into the `properties` table, mapping columns like `Township`, `Median_Price`, `Safety_Score`, `Flood_Risk`, etc.

### Step 6 — Create the admin account

```bash
php database/seed_admin.php
```

This creates a default admin login if one doesn't already exist:

```
Email:    admin@smarthome.local
Password: Admin@123
```

**Change this password after first login** if this will be anything other than a local dev environment.

### Step 7 — Start the server

For local development, PHP's built-in server is the quickest option (no Apache/Nginx setup needed) — run this from inside the `public/` folder:

```bash
cd public
php -S localhost:8000
```

Then open `http://localhost:8000` in a browser.

If you'd rather use Apache: point your virtual host's document root at the `public/` folder. The included `public/.htaccess` only sets `DirectoryIndex index.php` — there's no URL rewriting to configure, since the app routes everything through a `?page=` query string.

### Step 8 — Verify it works

1. Visit the homepage — you should see the landing page with Bootstrap styling loaded (if styling looks broken/unstyled, check your internet connection, since CSS/JS load from CDNs).
2. Log in with the admin credentials from Step 6.
3. Go to **Assessment**, fill it out, and submit — you should see scored property recommendations with explanatory badges.
4. Go to **Directory** and confirm properties from the CSV import are listed and filterable.

---

## 3. Running the Logic Tests

Two standalone PHP test scripts cover the core scoring and rules logic (no database required — they test the pure functions in isolation):

```bash
php tests/test_recommendation_engine.php
php tests/test_rules_engine.php
```

Place both files in a `tests/` folder at the project root (alongside `app/` and `public/`) and they'll auto-locate the source files; otherwise pass explicit paths:

```bash
php tests/test_recommendation_engine.php app/models/RecommendationEngine.php
php tests/test_rules_engine.php public/api/rules.php app/models/RecommendationEngine.php
```

Each script prints a `PASS`/`FAIL` line per check and exits with code `0` if everything passes, `1` if anything fails — safe to wire into a CI step later.

---

## 4. Troubleshooting

| Symptom | Likely cause |
|---|---|
| Blank page / 500 error on load | Check PHP error log; confirm `pdo_mysql` extension is enabled (`php -m \| grep pdo_mysql`) |
| "SQLSTATE[HY000] Connection refused" | MySQL isn't running, or `DB_HOST`/`DB_USER`/`DB_PASS` in `app/config.php` don't match your local setup |
| "SQLSTATE[HY000] [1698] Access denied for user 'root'@'localhost'" even though the password is correct | Common with fresh MySQL/MariaDB installs: the `root` user often only has a grant for connections via the local socket (`localhost`), not over TCP — but `app/config.php` connects via `DB_HOST = '127.0.0.1'`, which MySQL treats as a different login. Fix by granting root TCP access: `mysql -u root -e "CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED VIA mysql_native_password USING PASSWORD(''); GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1'; FLUSH PRIVILEGES;"` (adjust the password if you've set one) |
| Page loads but has no styling | No internet access — Bootstrap/Font Awesome/fonts load from CDNs, not bundled locally |
| Directory page shows no properties | `database/import_properties.php` wasn't run, or was run before `migrate_properties_dataset.sql` (columns wouldn't exist yet, so the import script fails with a SQL error) |
| Can't log in as admin | `database/seed_admin.php` wasn't run, or was run before `schema.sql` created the `users` table |
| Recommendation scores look wrong / missing rule badges | Confirm `migrate_properties_dataset.sql` ran successfully — most scoring/rule logic depends on the columns it adds |
| `database/migrate_add_commitment.sql` fails with "Duplicate column name 'monthly_commitment'" | Expected on a fresh install — this migration is only for upgrading old databases. Skip it; `schema.sql` already includes these columns |

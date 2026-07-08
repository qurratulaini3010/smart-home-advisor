# Smart Home Advisor — Inputs, Actions & Data Flow Reference

This is a companion to `README.md`. That file explains *how to set the project up*; this file explains *what every input field in the app actually does*: which form or script it belongs to, what endpoint/action it fires, what server-side code handles it, what gets read from or written to the database, and what the user sees as a result.

It's written so you (or anyone maintaining this codebase) can look at any `<input>`, button, or slider in the UI and trace it end-to-end without reading the whole codebase.

**This revision closes every "collected but has no effect" gap that a previous audit had flagged.** Where a section below describes a fix made in this pass, it's marked **FIXED (this pass)** so you can tell it apart from earlier fixes. See §14 for the full list.

---

## 1. How this app is wired (read this first)

- **No framework, one front controller.** Every normal page request goes through `public/index.php`. The page shown is chosen by a query string: `index.php?page=login`, `index.php?page=dashboard`, etc. There is no `.htaccess` URL rewriting.
- **Two kinds of input handling in `index.php`:**
  1. **Persistent form POSTs** — a `<form method="post">` with a hidden `<input name="action" value="...">`. These are read starting at the block `if ($dbError === null && $_SERVER['REQUEST_METHOD'] === 'POST')`. Each `if ($action === '...')` block is one handler. These always end in either `redirect()` (send the browser to a new `?page=`) or a flash message.
  2. **AJAX JSON endpoints** — either a special case inside `index.php` itself (`?action=assessment_preview`) or a standalone file under `public/api/*.php`. These return `json_encode([...])` and never redirect; the calling JavaScript decides what to do with the response.
- **CSRF**: every persistent form includes `<?= Csrf::field() ?>`, a hidden `csrf_token` input. `Csrf::verify()` is called before any `action` is processed; a mismatched/missing token stops the request. AJAX POSTs (e.g. favoriting from the directory) send `csrf_token` as a regular POST field instead of a hidden form input.
- **Auth gate**: `Auth::requireLogin()` / `Auth::requireAdmin()` guard pages and actions. If you're not logged in, you're redirected to `login`; if you're not an admin on an admin-only action, you get a 403.
- **Table legend used below:**

  | Column | Meaning |
  |---|---|
  | Field | The `name=""` attribute of the input, or the JS variable/param name for AJAX |
  | Fires on | What triggers the request (submit, click, debounced input) |
  | Goes to | The exact action/endpoint that receives it |
  | Server logic | What the PHP does with the value |
  | Output | What the user/browser gets back |

---

## 2. Page map (every `?page=` value)

| `page` value | Auth required | What it renders | Defined at |
|---|---|---|---|
| `landing` (default if logged out) | No | Marketing/home page | `index.php` ~740 |
| `login` | No | Login form | ~791 |
| `register` | No | Registration form | ~791 |
| `forgot` | No | Static "ask admin" notice | ~791 |
| `dashboard` (default if logged in) | Yes | User stats + favorites | ~854 |
| `assessment` | Yes | 4-step assessment wizard | ~900 |
| `results` | Yes | Ranked property matches for one assessment | ~1080 |
| `property_directory` | Yes | Searchable property grid (delegates to `public/partials/property_directory_tabs.php`) | ~1184 |
| `property` | Yes | Full property detail page | ~1192 |
| `mortgage` | Yes | Standalone mortgage calculator | ~1388 |
| `profile` | Yes | Edit name/phone/DOB/occupation | ~1406 |
| `financial_profile` | Yes | Income + commitments manager | ~1453 |
| `admin_dashboard` | Admin | Admin stat tiles | ~1617 |
| `admin_users` | Admin | User list + role changer | ~1632 |
| `admin_properties` | Admin | Add/edit/delete properties | ~1642 |
| `admin_reports` | Admin | Assessment history table + CSV export | ~1721 |
| `export_reports` | Admin | Not a page — streams a CSV file | ~630 |
| `logout` | — | No UI — clears session, redirects to `landing` | ~623 |

---

## 3. Authentication

### 3.1 Login — `page=login`

| Field | Type | Notes |
|---|---|---|
| `email` | email, required | |
| `password` | password, required | |
| `action` | hidden | always `login` |
| `csrf_token` | hidden | from `Csrf::field()` |

**Fires on:** form submit → `POST index.php` (no query string needed; `action` comes from the POST body).
**Server logic (`index.php` `if ($action === 'login')`):** calls `Auth::login($email, $password)`, which looks up the user by email and checks `password_verify()`.
**Output:**
- Wrong credentials → flash "Invalid email or password." (danger) → redirect back to `login`.
- Correct, role = admin → redirect to `admin_dashboard`.
- Correct, role = user, has ≥1 prior assessment → redirect to `dashboard`.
- Correct, role = user, no assessments yet → redirect to `assessment` (so a new user is dropped straight into the wizard).

### 3.2 Register — `page=register`

| Field | Type | Notes |
|---|---|---|
| `full_name` | text, required | |
| `phone` | text, optional | |
| `occupation` | select, optional | grouped dropdown (Government, Professional, Self-Employed, Other) |
| `date_of_birth` | date, optional | `max` attribute blocks picking a date less than 18 years ago in supporting browsers; server re-validates |
| `email` | email, required | |
| `password` | password, required, min 8 chars | |
| `action` | hidden | `register` |

> **Note:** this field only renders on the register form — it used to also render (incorrectly) on the login form. See §14's "Date of Birth leaking onto the Login form" entry.

**Fires on:** form submit → `POST index.php`, handler `if ($action === 'register')`.
**Server logic:**
1. Validates name/email format/password length — fails → flash danger, redirect `register`.
2. If `date_of_birth` was filled in: parses it with `strtotime()`; rejects it (flash + redirect `register`) if unparsable **or** if it computes to under 18 years old (`$dobTs >= strtotime('-18 years')`).
3. `INSERT INTO users (full_name, email, password, phone, occupation, date_of_birth, role)` — password is hashed with `password_hash()`, role is hard-coded to `'user'` (you cannot self-register as admin).

**Output:**
- Success → flash "Account created. You can sign in now." → redirect `login`.
- Email already taken → the `INSERT` throws (unique constraint on `users.email`) → flash "That email is already registered." → redirect `register`.

### 3.3 Logout — link, no form

**Fires on:** clicking "Logout" (`href="index.php?page=logout"`).
**Server logic:** `Auth::logout()` wipes `$_SESSION`, expires the session cookie, destroys the session.
**Output:** flash "You have signed out." → redirect `landing`.

---

## 4. Profile — `page=profile`, action=`profile`

| Field | Type | Notes |
|---|---|---|
| `full_name` | text, required | |
| `phone` | text | |
| `date_of_birth` | date | same 18+ validation as registration |
| `occupation` | select | same grouped list as registration |

**Fires on:** form submit → `POST index.php`, handler `if ($action === 'profile')`.
**Server logic:** re-validates DOB the same way as registration, then `UPDATE users SET full_name, phone, occupation, date_of_birth WHERE id = <current user>`.
**Output:** flash "Profile updated." → redirect `profile` (page reloads showing the saved values, plus a computed "Age: N years old" line under the DOB field if one is on file).

---

## 5. Financial Profile — `page=financial_profile`

This page has two independent forms plus a per-row delete button.

### 5.1 Set gross income — action=`financial_profile_save`

| Field | Type |
|---|---|
| `gross_monthly_income` | number, required, min 1 |

**Server logic:** rejects ≤ 0 → flash danger, redirect back. Otherwise `UPDATE users SET gross_monthly_income = ?`.
**Output:** flash "Income saved." → redirect `financial_profile`, where the page now shows Gross / Total Commitments / Net Income tiles.

### 5.2 Add a commitment — action=`commitment_add`

| Field | Type | Notes |
|---|---|---|
| `label` | text, required, max 150 chars | free-text description, e.g. "Myvi car loan" |
| `category` | select, required | one of `car_loan`, `study_loan`, `personal_loan`, `credit_card`, `existing_mortgage`, `other` — server re-checks this against the same whitelist |
| `amount` | number, required, min 1 | monthly RM amount |

**Server logic:** validates label non-empty, category in whitelist, amount > 0 → `INSERT INTO user_commitments (user_id, label, category, amount)`.
**Output:** flash "Commitment added." → redirect `financial_profile`; the new row appears in the commitments table and the Total/Net tiles recalculate.

### 5.3 Delete a commitment — action=`commitment_delete`

| Field | Type |
|---|---|
| `commitment_id` | hidden int, one per row's own form |

**Server logic:** looks the row up **scoped to `user_id = current user`** before deleting — so you can't delete someone else's commitment by guessing an ID. Not found → flash danger. Found → `DELETE`.
**Output:** flash "Commitment removed." (warning style) → redirect `financial_profile`.

> **Why income/commitments live here and not on the assessment form:** `assessment_store` (below) pulls `gross_monthly_income` and the sum of `user_commitments` straight from the database — it does **not** read them from any assessment form field. If `gross_monthly_income` is 0, the assessment page blocks you with a banner telling you to fill this out first. **FIXED (this pass):** the live preview (§6, Step 4) used to ignore this entirely and always score against a net income of 0 — it now pulls from the database the same way.

---

## 6. Assessment Wizard — `page=assessment`

This is the most involved flow in the app: one visible 4-step client-side wizard, one hidden "real" submission form, and one live-preview AJAX call. As of this pass, **every field collected by the wizard now affects either the live preview, the final saved assessment, or both** — this section used to list several fields that were collected and silently discarded; that list is now empty (see §14 for what changed and why).

### How the two forms relate

- The **visible wizard** (`#advisorWizardAssessment`) is not itself submitted. Its inputs only exist to be read by JavaScript.
- A **hidden form** (`#assessmentFinalForm`, `action=assessment_store`) is what actually gets POSTed. `app.js`'s `copyAssessmentToFinalForm()` copies values from the visible wizard into this hidden form's fields right before submitting.

### Step 1 — Budget

| Field | Type | Fires on | Goes to |
|---|---|---|---|
| `budget` (visible) | number, required, min 1 | `input` event | copied live into the hidden form's `budget` field by an inline `<script>` block, so the hidden form always has the current value even before you click Next |

### Step 2 — Your Ideal Home

| Field | Type | Used by final submit? | Used by live preview? |
|---|---|---|---|
| `preferred_location` | text | ✅ yes | ✅ yes |
| `property_type` | select | ✅ yes | ✅ yes |
| `tenure_preference` | select (Any / Freehold / Leasehold) | ✅ yes | ✅ yes |
| `bedrooms` (minimum wanted) | number | ✅ yes | ✅ yes |
| `low_flood_risk` | checkbox | ✅ yes | ✅ yes |
| `near_school` | checkbox | ✅ yes | ✅ yes |

**FIXED (this pass):** `tenure_preference`, `bedrooms`, `low_flood_risk`, and `near_school` carry `data-assessment-field` and were already being collected by `collectAssessmentData()` and sent in the live-preview AJAX payload — but neither `assessment_preview` (in `index.php`) nor `copyAssessmentToFinalForm()` (in `app.js`) read them, so filling them in changed nothing. They are now:
- Read server-side by both `assessment_preview` and `assessment_store`.
- Forwarded into the hidden final form by `copyAssessmentToFinalForm()`.
- Persisted on the `assessments` row (`tenure_preference`, `min_bedrooms`, `low_flood_risk`, `near_school` columns — see the `database/migrate_assessment_preferences.sql` migration).
- Applied as soft scoring bonuses/penalties in `RecommendationEngine::score()` (not hard filters — a near-miss property is still shown, just ranked lower). See §13 for the exact point values.
- Shown back to you on the Results page as a "Home Preferences Used for Ranking" chip row (see §7), so the effect isn't just internal.

### Step 3 — What Matters Most (sliders)

| Field | Range | Mapped to (via `slidersToAssessmentFields()` in `app.js`) |
|---|---|---|
| `smart_priority_slider` | 0–100, default 50 | if ≥ 50 → `smart_lighting=1`, `smart_appliances=1`, `smart_energy=1`; else `0` |
| `security_priority_slider` | 0–100, default 50 | if ≥ 50 → `smart_security=1`; else `0` |
| `sustainability_priority_slider` | 0–100, default 50 | competes for `comfort_priority` — see below. **FIXED (this pass)**, previously collected but never read. |
| `family_priority_slider` | 0–100, default 70 | competes for `comfort_priority` — see below |
| `quiet_priority_slider` | 0–100, default 50 | competes for `comfort_priority` — see below |

**How `comfort_priority` is now chosen:** the three sliders above (`family`, `sustainability`, `quiet`) are compared, and whichever has the **highest value** wins — as long as it's ≥ 60 — mapping to `"Family growth"`, `"Energy efficiency"`, or `"Acoustic comfort"` respectively. If none clears 60, it defaults to `"Energy efficiency"`. `comfort_priority` maps to a single +5 bonus on either the family or environment score (see §13). Previously, `sustainability_priority_slider` had no path to influence this at all — `"Energy efficiency"` only ever won as the fallback default, regardless of what the user actually set that slider to.

Moving any slider re-renders its numeric readout instantly (pure DOM update) and, if you're on Step 4, restarts a 250ms debounce timer that re-fetches the live preview.

### Step 4 — Your Matches Preview (live AJAX, no page reload)

**Fires on:** arriving at step 4, or any input change while on step 4 (debounced 250ms).
**Goes to:** `POST index.php?action=assessment_preview` (handled inside `index.php`, *not* a separate file — look for the `assessment_preview` block near the top, before the persistent-form section, ~line 158).
**Sends:** `budget`, `preferred_location`, `property_type`, `tenure_preference`, `bedrooms`, `low_flood_risk`, `near_school`, `comfort_priority`, and the four `smart_*` flags derived from the sliders.

> **FIXED (this pass) — income during preview:** the visible wizard has no income/commitment fields (those live on the Financial Profile page), so a posted `monthly_income`/`monthly_commitment` would always have been `0`. Previously the server used those posted (always-empty) values directly, so the preview's affordability tilt silently ran on a net income of 0 regardless of what the user had actually set up in their Financial Profile — meaning **preview rankings could disagree with the final saved results**. The preview endpoint now fetches `gross_monthly_income` and sums `user_commitments` from the database for the logged-in user, exactly like `assessment_store` does, so preview and final scoring use the same numbers.

**Server logic:** rebuilds the same weighted-scoring call (`RecommendationEngine::score()`) used for real results, against properties priced at or under `budget × 1.20`, filtered by location if given. Keeps matches with `match_percentage > 40`, sorts, returns the **top 3**.
**Output (JSON):** `{ success: true, data: [{ id, title, match_percentage }, ...] }` — rendered as small cards. No database write happens here; nothing is saved.

### Final submit — action=`assessment_store`

**Fires on:** clicking the wizard button on Step 4 (its label changes to "Save & Get Full Results"). JS copies wizard values into the hidden form's fields, then calls `finalForm.submit()` — a normal full-page POST, not AJAX.

| Field sent | Where it came from |
|---|---|
| `budget` | Step 1 |
| `preferred_location`, `property_type`, `tenure_preference`, `bedrooms`, `low_flood_risk`, `near_school` | Step 2 |
| `smart_lighting`, `smart_security`, `smart_appliances`, `smart_energy` | derived from Step 3 sliders |
| `comfort_priority` | derived from Step 3 sliders |

**Server logic (`index.php`, `if ($action === 'assessment_store')`, ~line 428):**
1. Loads `occupation`, `gross_monthly_income`, `date_of_birth` from `users` for the current user — **not** from the form.
2. Blocks with a flash + redirect to `financial_profile` if income isn't set up, or if total commitments ≥ income (net income would be ≤ 0).
3. Derives `age` from `date_of_birth` if present (nothing else on this page collects age).
4. Requires `budget > 0`.
5. Reads `tenure_preference`, `bedrooms`, `low_flood_risk`, `near_school` from the form.
6. `INSERT INTO assessments (...)` with all of the above, including the four Step 2 preference columns.
7. Calls `save_recommendations($assessmentId, $assessment)`, which:
   - Pulls every property priced at or under `budget × 1.20` (optionally filtered by `preferred_location` against `township`/`area`/`state`).
   - Loads admin-configurable weights from `assessment_criteria` (falls back to the hard-coded 30/20/20/15/15 split in `RecommendationEngine::WEIGHTS` if none are set), normalizes them to sum to 1.
   - Scores every candidate property with `RecommendationEngine::score()`, now including the Step 2 preference bonuses (see §13 for the formula).
   - Keeps only `match_percentage > 40`, sorts descending, keeps the **top 10**, and `INSERT`s them into `recommendations` with a `rank_position`.

**Output:** redirect to `results&id=<new assessment id>`.

---

## 7. Results — `page=results&id=N`

No inputs — read-only. Loads the assessment (must belong to the logged-in user, or you're bounced to `dashboard`), joins `recommendations` → `properties`, and displays them ranked, each with a "View Details" link to `page=property`. The only interactive element is a **Print PDF Report** button, which just calls the browser's native `window.print()` — no server round-trip.

**FIXED (this pass) — new panel:** a "Home Preferences Used for Ranking" chip row now appears (when any of tenure/bedrooms/flood/school preferences were set) so the effect of those Step 2 inputs is visible here, not just baked invisibly into the match percentages.

---

## 8. Property Directory — `page=property_directory`

This page renders `public/partials/property_directory_tabs.php` and loads `public/assets/js/property-advisor.js`. Everything here is AJAX against `public/api/property-directory.php`; the page itself never reloads.

### 8.1 Search form (`#propertySearchForm`) — this is what's actually live on the page

| Field | Type |
|---|---|
| `township_area` | text |
| `property_type` | select |
| `min_price` / `max_price` | number |
| `bedrooms` | number |
| `min_smart_score` | number 0–100 |
| `min_sustainability_score` | number 0–100 |

**Fires on:** page load (runs once immediately with all fields empty) and on any `input` event, debounced 250ms.
**Goes to:** `GET public/api/property-directory.php?action=search&...`
**Server logic:** `PropertyDirectoryRepository::search()` builds a filtered SQL query from whichever fields are non-empty.
**Output:** JSON `{ success, count, data: [...] }`, rendered as property cards into `#propertyDirectoryGrid`; `#directoryCount` shows the count.

**View Details button** on each card (`data-view-property="<id>"`): fires `GET api/property-directory.php?action=details&id=<id>` plus (in a second, parallel request) `GET api/rules.php?property_id=<id>&budget=<your last assessment's budget>`. Both responses are merged into a Bootstrap modal — full spec sheet plus the same rule badges used on the property detail page. No page navigation happens.

### 8.2 "Advisor" wizard and favoriting from the directory — present in the JS, not reachable in the UI

`property-advisor.js` also contains a second flow: a 3-step wizard (`#advisorWizardForm`, `#advisorNext`, `#advisorBack`, `#advisorResults`, `#favoriteSuccess`) that would call `action=recommend` and a "Love It ❤️" favorite button on each advisor result card. **None of these element IDs currently exist anywhere in `property_directory_tabs.php` or any other template**, so this code path never runs — the relevant `document.getElementById(...)` calls simply return `null` and the optional-chained listeners never attach. This is intentional, not a bug: the Guided Advisor tab that used to host this markup was deliberately removed in an earlier pass as redundant with the main Assessment Wizard, and the leftover JS was kept (rather than deleted) because the API side is fully implemented and harmless to leave in place. If you want to re-enable it, the wizard markup needs to be added back to the partial; the API side (`action=recommend` in `property-directory.php`, using `PropertyDirectoryRepository::recommend()`) already uses `tenure_preference`, `bedrooms`, `low_flood_risk`, `near_school`, and five `*_priority` weights — a **second, independent scoring formula** from the one used by the assessment wizard (§13).

> Note: this is the one remaining place in the codebase with UI-adjacent code that has no rendered input to trigger it. It's called out here rather than "fixed" because there's no `<input>` on any page currently pointing at it — the audit in this pass was scoped to inputs a user can actually see and fill in.

**Practical consequence:** today, the *only* way to add a property to Favorites is from the full **Property Detail page** (`page=property&id=N`, reached via "View Details" on the **Results** page after running an assessment) — not from the Directory's modal or search grid.

---

## 9. Property Detail — `page=property&id=N`

Read-only spec sheet (Core Data, Financials, Risk & Safety, Proximity, Smart Home Scores, Smart Features) plus one live piece and one form.

**Rule badges** (`#propertyRuleBadges`): on page load, an inline `<script>` fires `GET api/rules.php?property_id=<id>&budget=<your last assessment's budget>` and renders whatever badges come back (see §13).

### Favorite toggle — action=`favorite_toggle`

| Field | Type |
|---|---|
| `property_id` | hidden |
| `return_page` | hidden, defaults to `property_directory` |
| `id` | hidden, the assessment id to return to if you came from `results` |

**Fires on:** clicking "Save to Favorites" / "Saved to Favorites" (same button toggles both ways).
**Server logic:** checks if a `favorites` row already exists for `(user_id, property_id)` — deletes it if so, inserts it if not.
**Output:** flash ("Property saved to favorites." or "Property removed from favorites.") → redirects back to wherever you came from (`results&id=...` or `property_directory`), so you land back on the page you were browsing, not on the property page itself.

---

## 10. Admin Pages

All three require `Auth::requireAdmin()` — logged in **and** `role = 'admin'`.

### 10.1 User management — `page=admin_users`, action=`admin_user_role`

| Field | Type |
|---|---|
| `id` | hidden, target user id |
| `role` | select (`user` / `admin`) |

**Server logic:** if the target id ≠ the currently-logged-in admin's own id (self-demotion is silently blocked), `UPDATE users SET role = ?`.
**Output:** flash "User role updated." → redirect `admin_users`.

### 10.2 Property management — `page=admin_properties`

**Save (add or edit) — action=`admin_property_save`**

One shared form for both creating and editing, distinguished by a hidden `id` (0 = new). Roughly 30 fields across four groups: Core Data (`township`, `area`, `property_name`, legacy `property_type`/`location`/`price`, `state`, `tenure`, `type`, `image`), Metrics & Financials (`median_price`, `median_psf`, `estimated_rental_yield_pct`, `historical_capital_appreciation_3yr_pct`, `est_monthly_mortgage_rm`, `transactions`), Risk & Proximity (`safety_score`, `crime_risk`, `flood_risk`, four `distance_to_*_km` fields), Specs & AI Scores (`bedrooms`, `bathrooms`, legacy `built_up_sqft`, `house_size_sqft`, and the five 0–100 score fields used by the recommendation engine: `smart_readiness_score`, `security_score`, `sustainability_score`, `family_score`, `acoustic_score`), plus a free-text `description`.

**Server logic:** numeric fields are cast to `float`/`int` (blank → `null` or `0` depending on the field); several **legacy/new field pairs are auto-backfilled from each other** if one side is left blank — `property_name`⇄`township`, `property_type`⇄`type`, `location`⇄`area`, `price`⇄`median_price`, `built_up_sqft`⇄`house_size_sqft` — so the app keeps working whether a property was entered through the old schema's fields or the new dataset's fields. Then either `UPDATE` (if `id > 0`) or `INSERT` into `properties`.
**Output:** flash "Property updated." / "Property added." → redirect `admin_properties`.

**Delete — action=`admin_property_delete`**

| Field | Type |
|---|---|
| `id` | hidden |

Confirmed client-side with a JS `confirm()` dialog before the form even submits. Server just `DELETE`s by id. Output: flash "Property deleted." (warning) → redirect `admin_properties`. **No cascade check** — if recommendations/favorites reference this property, deleting it may leave orphaned rows (there's no visible error if so, since the page doesn't join against those tables here).

### 10.3 Reports — `page=admin_reports`

Read-only table of every assessment (user, budget, type, location, best match %, date). One link: **Export CSV** → `page=export_reports`, which isn't a page at all — it sets CSV headers and streams `Content-Disposition: attachment` directly, joining `recommendations` → `assessments` → `users` → `properties`. No form inputs involved.

---

## 11. Mortgage Calculator — `page=mortgage`

Entirely client-side — nothing here ever touches the server or database.

| Field | Type | Default |
|---|---|---|
| `calcPrice` | number | 550000 |
| `calcDown` | number (%) | 10 |
| `calcRate` | number (%) | 4.2 |
| `calcYears` | number | 35 |

**Fires on:** clicking "Calculate" (`onclick="calculateMortgage()"` in `app.js`), and once automatically on page load.
**Logic:** standard amortizing-loan payment formula run in JavaScript: `P × (r(1+r)^n) / ((1+r)^n − 1)`, where `P` = price × (1 − down%), `r` = monthly rate, `n` = months.
**Output:** writes the formatted RM amount into `#mortgageResult`. Nothing is saved — refreshing the page resets it to the defaults above.

---

## 12. The two GET-only JSON APIs not reachable from any page UI

These exist and work if called directly, but nothing currently links to them:

| Endpoint | Auth | Purpose |
|---|---|---|
| `api/properties.php` | Logged in (add `?view=staff` for extra fields, admin only) | Generic filterable property listing (`township`, `area`, `state`, `type`, `tenure`, `budget`, `min_price`, `max_price`, `bedrooms`, `bathrooms`, `limit`, `offset`, or `?id=N` for one record) via `PropertyRepository`. This is a separate, simpler query layer from `PropertyDirectoryRepository` used by the Directory page. |
| `api/insights.php` | None (no `Auth::check()` at all) | Dumps every row of a `property_insights` table (transactions, safety score, crime risk, distances, flood risk, rental yield, appreciation, mortgage estimate). This table/API pair is part of an analytics feature that was never wired to any frontend — see `README.md` §1 "Known orphaned code" for the full list of files involved. |

---

## 13. The scoring formula, in one place

Every property gets five 0–100 sub-scores, combined with weights that default to **Affordability 30% · Security 20% · Smart Readiness 20% · Environment 15% · Family 15%** (overridable per-key via the `assessment_criteria` table, normalized to sum to 1 if any are set):

- **Affordability** starts from price ÷ budget (continuous curve, 86 at exactly-on-budget, up to 100 well under, down to 0 well over), then adjusted by mortgage-to-net-income ratio (bonus if mortgage ≤ 30% of net income, penalty if mortgage-to-net > 40%), then nudged further by occupation category (government stable-income bonus, self-employed penalty/reward depending on how conservative the price choice is, high-income bonus/penalty depending on commitment load).
- **Security** = property's base security score, +8 if you flagged `smart_security`, **+6/−6 if you flagged "I prefer low flood risk areas" and the property's flood risk is low/high** *(new this pass)*.
- **Smart** = property's base smart-readiness score, +3 per smart-feature flag you checked (lighting/security/appliances/energy), capped at 100.
- **Environment** = property's sustainability score, +7 if `smart_energy` flagged, +4 for high-income/low-commitment profiles, then a retiree-specific hospital-proximity bonus or penalty, then +5 if `comfort_priority` resolved to `"Energy efficiency"` (see §6, Step 3 — this is now genuinely competed for by the sustainability slider, not just a fallback default).
- **Family** = property's base family score, +8 if household size ≥ 4 and the property has ≥3 bedrooms *(note: household size is no longer collected anywhere in the current UI, so this branch is effectively always using whatever default/legacy value is in the assessment row)*, +6 for retirees on high-safety properties, +5/−10 for location/type match or mismatch, +5 if `comfort_priority` resolved to `"Family growth"`, and **(new this pass)**: +5/−5 for tenure-preference match/mismatch, +5/−8 for meeting/missing your minimum-bedrooms request, and up to +10 (linearly tapering to 0 at 5km) if you flagged "Within 3 km of a school" and the property is within that range.
- All five are combined with the normalized weights into the final `match_percentage`, which is what's shown, ranked, and stored.

A **second, independent** rules engine (`public/api/rules.php`, ~108 rules across 8 domains) runs separately to generate the plain-language badges you see on property detail pages and directory modals — it does not feed back into `match_percentage` at all; it's purely explanatory. It already read `tenure`, `bedrooms`, `flood_risk`, and `distance_to_school_km` off the *property* row (that part was never broken); what this pass fixed was the *assessment-side preferences* for those same dimensions actually reaching the scoring engine.

---

## 14. Recent fix log (kept here so the "why" isn't lost)

- **Date of Birth leaking onto the Login form:** the DOB label/input in the shared login-or-register template (`index.php`, the `page === 'login' || page === 'register'` block) was originally placed *outside* the `if ($page === 'register')` conditional that wraps the other registration-only fields, so it rendered — and could be submitted — on the Login page too, even though the `login` action handler never reads it. It's now correctly nested inside that conditional and only appears when creating an account.

- **Input/output audit (this pass):** four Step 2 assessment fields (`tenure_preference`, `bedrooms`, `low_flood_risk`, `near_school`) were rendered, collected by JS, and even transmitted to the server — but nothing server-side ever read them, so they had zero effect on scoring, ranking, or the saved assessment. Similarly, `sustainability_priority_slider` (Step 3) was collected but never read at all. Fixed:
  - `RecommendationEngine::score()` now applies all four Step 2 fields as scoring bonuses/penalties (§13).
  - `assessment_preview` and `assessment_store` (both in `index.php`) now read all four fields.
  - `copyAssessmentToFinalForm()` (`app.js`) now forwards them into the hidden submission form.
  - `slidersToAssessmentFields()` (`app.js`) now has the sustainability slider genuinely compete for the `comfort_priority` bonus instead of `"Energy efficiency"` only ever winning as an unconditional fallback.
  - New DB columns: `assessments.tenure_preference`, `assessments.min_bedrooms`, `assessments.low_flood_risk`, `assessments.near_school` — added to `database/schema.sql` for fresh installs, with `database/migrate_assessment_preferences.sql` for upgrading an existing database.
  - The Results page now shows a "Home Preferences Used for Ranking" chip row so the effect of these inputs is visible, not just baked into the numbers.
  - Also fixed in the same pass: the live preview (`assessment_preview`) was silently scoring against a net income of 0 (it read `monthly_income`/`monthly_commitment` from POST fields that don't exist on the wizard page) instead of the user's actual saved Financial Profile — it now fetches the real figures from the database, matching what `assessment_store` does, so preview and final results agree.
  - Test coverage: `tests/test_recommendation_engine.php` gained 12 new assertions covering all four Step 2 fields; all 26 assertions in that file (and all 16 in `tests/test_rules_engine.php`) pass.
  - Not touched: the orphaned "Advisor" wizard JS in `property-advisor.js` (§8.2) — it has no rendered `<input>` anywhere to wire up, so it was out of scope for an input→output audit and was left as previously documented.

- **Missing-migration gap fixed (this pass) — this one was more severe than "no effect," it was "fatal error":** `database/migrate_financial_profile.sql` adds `users.date_of_birth`, `users.gross_monthly_income`, and the entire `user_commitments` table — all three are load-bearing for Registration, Profile, and the Financial Profile page. `README.md`'s setup guide never mentioned this migration at all, and `schema.sql` didn't include those columns either. Following the documented setup steps exactly (`schema.sql` + `migrate_properties_dataset.sql` only) would leave a database where the very first registration with a date of birth filled in throws `Unknown column 'date_of_birth' in 'field list'`, and the Financial Profile page is unusable. Fixed by merging `users.date_of_birth`, `users.gross_monthly_income`, and `user_commitments` directly into `schema.sql` (same treatment `monthly_commitment`/`net_income` already got), and updating `README.md`'s Step 3 + Troubleshooting section to explain that `migrate_financial_profile.sql` (and the new `migrate_assessment_preferences.sql`) are now upgrade-only, not required on a fresh install.

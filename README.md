# smart-home-advisor
# Smart Home Advisor System

> An expert system that recommends smart home properties in Malaysia based on a user's financial profile, household needs, and smart home preferences — powered by a CLIPS-style forward-chaining inference engine built in PHP.

---

## Table of Contents

- [Overview](#overview)
- [Features](#features)
- [System Architecture](#system-architecture)
- [Inference Engine](#inference-engine)
- [Tech Stack](#tech-stack)
- [Database Schema](#database-schema)
- [Getting Started](#getting-started)
- [Project Structure](#project-structure)
- [Scoring Criteria](#scoring-criteria)
- [User Roles](#user-roles)
- [Academic Context](#academic-context)

---

## Overview

Smart Home Advisor is a full-stack web-based expert system developed for the ISP543 course. It guides users through a structured assessment — collecting their budget, income, household size, occupation, and smart home feature preferences — and then evaluates a property dataset against 106 inference rules across 8 domains to produce ranked, explainable property recommendations.

The system is designed around Malaysian property data, with timezone and currency (RM) set accordingly.

---

## Features

- **User Authentication** — Secure login, session management with 30-minute timeout, and CSRF protection
- **Smart Assessment Form** — Collects age, income, budget, household size, preferred location, property type, and smart home priorities (lighting, security, appliances, energy)
- **Rule-Based Inference Engine** — 106 CLIPS-style forward-chaining rules across 8 domains evaluate every property in the database against the user's profile
- **Weighted Scoring Model** — Each property receives a composite match percentage based on five weighted criteria
- **Occupation-Aware Recommendations** — Scoring adjusts dynamically based on whether the user is a government employee, self-employed, high-income professional, student, or retiree
- **Property Directory** — Browseable and filterable list of all properties with detailed scores
- **Favourites** — Users can save and revisit preferred properties
- **Admin Dashboard** — Staff can manage users, properties, and view system-wide assessment statistics
- **Explanation Engine** — Each recommendation is accompanied by labelled rule explanations (positive, info, warning) so users understand why a property was recommended

---

## System Architecture

The system follows a Single Page Application (SPA) pattern with a single `public/index.php` entry point that routes views based on the `page` query parameter. There is no JavaScript framework — all routing and rendering is server-side PHP.

```
Browser Request
      │
      ▼
public/index.php  (routing + session + auth check)
      │
      ├── Auth::check()           (session validation)
      ├── Database::connect()     (singleton PDO → MySQL)
      ├── RecommendationEngine    (weighted scoring)
      └── rules.php API           (106-rule inference engine)
```

---

## Inference Engine

The inference engine in `public/api/rules.php` implements a CLIPS-style forward-chaining approach. Rules are defined as data — each rule is a tuple of `[id, domain, condition, label, severity, explanation]` — and the engine evaluates all rules against a property at query time.

### 8 Rule Domains

| Domain | Rules | Code Range |
|--------|-------|------------|
| Affordability | 16 | A01–A16 |
| Safety & Risk | 14 | S01–S14 |
| Family Suitability | 16 | F01–F16 |
| Smart Home Readiness | 18 | H01–H18 |
| Space & Physical Suitability | 10 | P01–P10 |
| Investment Value | 14 | I01–I14 |
| Location Quality | 6 | L01–L06 |
| Occupation Suitability | 12 | O01–O12 |

Each fired rule produces an explanation with a severity label (`positive`, `info`, or `warning`) that is returned to the frontend and displayed alongside the recommendation result.

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend Language | PHP 8 (strict types, OOP) |
| Database | MySQL 8 (utf8mb4) |
| Frontend | HTML5, CSS3, JavaScript (ES6+) |
| DB Access | PDO with prepared statements |
| Local Server | XAMPP |
| Tunnelling (dev) | ngrok |
| Version Control | Git |
| IDE | Visual Studio Code (Kiro AI) |

---

## Database Schema

Six core tables power the system:

| Table | Purpose |
|-------|---------|
| `users` | User accounts with roles (`admin` / `user`) |
| `properties` | Property listings with all score and proximity columns |
| `assessments` | Stored user assessment inputs |
| `recommendations` | Scored and ranked results per assessment |
| `favorites` | User-saved properties |
| `smart_home_features` | Smart features linked to each property |
| `assessment_criteria` | Configurable weights for the scoring model |

The `properties` table includes the following computed/dataset fields relevant to scoring: `smart_readiness_score`, `security_score`, `sustainability_score`, `family_score`, `acoustic_score`, `safety_score`, `crime_risk`, `flood_risk`, `distance_to_public_transport_km`, `distance_to_mall_km`, `distance_to_school_km`, `distance_to_hospital_km`, `estimated_rental_yield_pct`, and `historical_capital_appreciation_3yr_pct`.

---

## Getting Started

### Prerequisites

- PHP 8.0 or higher
- MySQL 8.0
- XAMPP (or any local Apache/PHP/MySQL stack)

### Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/your-username/smart-home-advisor-system.git
   ```

2. **Move to your server root**
   ```bash
   # For XAMPP on Windows
   mv smart-home-advisor-system C:/xampp/htdocs/

   # For XAMPP on macOS
   mv smart-home-advisor-system /Applications/XAMPP/htdocs/
   ```

3. **Create the database**

   Open phpMyAdmin or your MySQL client and run the schema file:
   ```sql
   SOURCE /path/to/smart-home-advisor-system/database/schema.sql;
   ```

4. **Import the extended properties table** (if using the full dataset)
   ```sql
   SOURCE /path/to/smart-home-advisor-system/database/properties_table.sql;
   ```

5. **Import property data** (optional CSV dataset)
   ```bash
   php database/import_properties.php
   ```

6. **Seed an admin account**
   ```bash
   php database/seed_admin.php
   ```

7. **Configure the app**

   Open `app/config.php` and verify your database credentials:
   ```php
   define('DB_HOST', '127.0.0.1');
   define('DB_NAME', 'smart_home_advisor');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```

8. **Start XAMPP** and visit:
   ```
   http://localhost/smart-home-advisor-system/public/
   ```

---

## Project Structure

```
smart-home-advisor-system/
│
├── app/
│   ├── config.php                  # App config, DB credentials, timezone
│   ├── core/
│   │   ├── Auth.php                # Session auth, login/logout, role guards
│   │   ├── Csrf.php                # CSRF token generation and validation
│   │   └── Database.php            # Singleton PDO connection
│   ├── helpers/
│   │   └── helpers.php             # Utility functions (route, redirect, flash, money)
│   └── models/
│       ├── RecommendationEngine.php    # Weighted scoring model
│       ├── PropertyRepository.php      # Property DB queries
│       └── PropertyDirectoryRepository.php  # Directory listing queries
│
├── database/
│   ├── schema.sql                  # Core schema (all 6 tables + seed data)
│   ├── properties_table.sql        # Extended properties table schema
│   ├── migrate_properties_dataset.sql
│   ├── schema_insights.sql
│   ├── property_data.csv           # Source property dataset
│   ├── import_properties.php       # CSV import script
│   ├── import_excel.php
│   └── seed_admin.php              # Admin account seeder
│
└── public/
    ├── index.php                   # SPA entry point and view router (1149 lines)
    ├── .htaccess                   # DirectoryIndex config
    ├── api/
    │   ├── rules.php               # 106-rule forward-chaining inference engine
    │   ├── properties.php          # Property listing API
    │   ├── property-directory.php  # Directory API
    │   └── insights.php            # Insights API
    ├── assets/
    │   ├── css/
    │   │   ├── app.css
    │   │   └── property-advisor.css
    │   └── js/
    │       ├── app.js
    │       ├── property-advisor.js
    │       └── property-components.js
    ├── components/
    │   ├── customer-property-cards.html
    │   └── staff-property-table.html
    └── partials/
        ├── dashboard_favorites.php
        └── property_directory_tabs.php
```

---

## Scoring Criteria

The `RecommendationEngine` computes a weighted match score (0–100%) for each property:

| Criterion | Weight | Description |
|-----------|--------|-------------|
| Affordability | 30% | Compares property price against user budget with stretch penalties |
| Security | 20% | Property security score, boosted if smart security is requested |
| Smart Readiness | 20% | Smart readiness score + bonus per smart feature requested |
| Environmental Comfort | 15% | Sustainability score + energy efficiency, hospital proximity for retirees |
| Family Suitability | 15% | Bedroom fit, household size, location and property type match |

### Occupation Adjustments

The engine applies contextual score modifiers based on occupation:

- **Government / Public Sector** — affordability bonus when price is within budget (stable income)
- **Self-Employed / Freelance** — affordability penalty when over budget (higher loan rejection risk); bonus for conservative picks
- **High-Income Professionals** — smart readiness and sustainability score bonuses
- **Retirees** — hospital proximity scoring applied to environment; safety-based family score boost

---

## User Roles

| Role | Capabilities |
|------|-------------|
| `user` | Run assessments, view recommendations, manage favourites, browse property directory |
| `admin` | All user capabilities + manage properties, view all users, system-wide stats |

---

## Academic Context

This project was developed as part of **ISP543 — Expert Systems** at a Malaysian university. It demonstrates the application of expert system concepts including:

- Knowledge acquisition and representation
- Rule-based inference (forward chaining)
- Weighted scoring and conflict resolution
- Explanation generation
- Expert system architecture (working memory, inference engine, explanation engine)

---

## License

This project is developed for academic purposes under ISP543.

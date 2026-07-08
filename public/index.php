<?php
declare(strict_types=1);

session_start();

require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/helpers/helpers.php';
require __DIR__ . '/../app/core/Database.php';
require __DIR__ . '/../app/core/Csrf.php';
require __DIR__ . '/../app/core/Auth.php';
require __DIR__ . '/../app/models/RecommendationEngine.php';
require __DIR__ . '/../app/models/PropertyRepository.php';
require __DIR__ . '/../app/models/PropertyDirectoryRepository.php';

if (!empty($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > SESSION_TIMEOUT) {
    Auth::logout();
    session_start();
    flash('Your session expired. Please sign in again.', 'warning');
}
$_SESSION['last_activity'] = time();

$page = $_GET['page'] ?? (Auth::check() ? 'dashboard' : 'landing');
$pdo = null;
$dbError = null;

try {
    $pdo = Database::connect();
} catch (Throwable $exception) {
    $dbError = $exception->getMessage();
}

function run_query(string $sql, array $params = []): PDOStatement
{
    $stmt = Database::connect()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function property_text(array $property, string $newKey, string $oldKey = '', string $default = ''): string
{
    $value = $property[$newKey] ?? ($oldKey !== '' ? ($property[$oldKey] ?? null) : null);
    return trim((string) ($value ?? '')) !== '' ? (string) $value : $default;
}

function property_number(array $property, string $newKey, string $oldKey = '', float $default = 0): float
{
    $value = $property[$newKey] ?? ($oldKey !== '' ? ($property[$oldKey] ?? null) : null);
    return is_numeric($value) ? (float) $value : $default;
}

function dashboard_stats(int $userId): array
{
    return [
        'assessments' => (int) run_query('SELECT COUNT(*) total FROM assessments WHERE user_id = ?', [$userId])->fetch()['total'],
        'favorites' => (int) run_query('SELECT COUNT(*) total FROM favorites WHERE user_id = ?', [$userId])->fetch()['total'],
        'best_match' => (float) (run_query(
            'SELECT MAX(r.match_percentage) best FROM recommendations r JOIN assessments a ON a.id = r.assessment_id WHERE a.user_id = ?',
            [$userId]
        )->fetch()['best'] ?? 0),
        'properties' => (int) run_query('SELECT COUNT(*) total FROM properties')->fetch()['total'],
    ];
}

function admin_stats(): array
{
    return [
        'users' => (int) run_query('SELECT COUNT(*) total FROM users')->fetch()['total'],
        'properties' => (int) run_query('SELECT COUNT(*) total FROM properties')->fetch()['total'],
        'assessments' => (int) run_query('SELECT COUNT(*) total FROM assessments')->fetch()['total'],
        'popular_type' => run_query(
            'SELECT property_type, COUNT(*) total FROM assessments GROUP BY property_type ORDER BY total DESC LIMIT 1'
        )->fetch()['property_type'] ?? 'Not enough data',
    ];
}

function save_recommendations(int $assessmentId, array $assessment): void
{
    // Use net income (gaji bersih) for budget-feasibility filter and scoring
    $netIncome = (float) ($assessment['net_income'] ?? max(0, (float) $assessment['monthly_income'] - (float) ($assessment['monthly_commitment'] ?? 0)));

    // FIX: Extend filter to 120 % of budget so near-budget properties are
    // included. The affordability formula already penalises over-budget
    // properties proportionally, so ranking remains correct.
    $sql    = 'SELECT * FROM properties WHERE COALESCE(median_price, price) <= ?';
    $params = [$assessment['budget'] * 1.20];

    // Add location filter if the user specified one
    if (!empty($assessment['preferred_location'])) {
        $sql .= ' AND (township LIKE ? OR area LIKE ? OR state LIKE ?)';
        $searchTerm = '%' . $assessment['preferred_location'] . '%';
        array_push($params, $searchTerm, $searchTerm, $searchTerm);
    }

    $properties = run_query($sql, $params)->fetchAll();

    $criteria    = run_query('SELECT criteria_key, weight FROM assessment_criteria')->fetchAll();
    $weights     = RecommendationEngine::WEIGHTS;
    foreach ($criteria as $criterion) {
        $weights[$criterion['criteria_key']] = ((float) $criterion['weight']) / 100;
    }
    $weightTotal = array_sum($weights);
    if ($weightTotal > 0) {
        foreach ($weights as $key => $weight) {
            $weights[$key] = $weight / $weightTotal;
        }
    }

    $ranked = [];

    // Pass net_income into assessment so the scoring engine uses gaji bersih.
    // occupation is already in $assessment when called from assessment_store or
    // the admin_criteria recalc loop (both now merge it before calling here).
    $assessmentWithNet = array_merge($assessment, ['net_income' => $netIncome]);

    foreach ($properties as $property) {
        $score = RecommendationEngine::score($assessmentWithNet, $property, $weights);

        // Only keep reasonably good matches
        if ($score['match_percentage'] > 40) {
            $ranked[] = ['property' => $property, 'score' => $score];
        }
    }

    // FIX: Sort by match_percentage — total_score was a redundant duplicate and
    // has been removed from the engine return value.
    usort($ranked, fn ($a, $b) => $b['score']['match_percentage'] <=> $a['score']['match_percentage']);
    run_query('DELETE FROM recommendations WHERE assessment_id = ?', [$assessmentId]);

    $topRanked = array_slice($ranked, 0, 10);
    $rank      = 1;

    foreach ($topRanked as $item) {
        run_query(
            'INSERT INTO recommendations
            (assessment_id, property_id, affordability_score, security_score, smart_score,
             environment_score, family_score, total_score, match_percentage, rank_position)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $assessmentId,
                $item['property']['id'],
                $item['score']['affordability_score'],
                $item['score']['security_score'],
                $item['score']['smart_score'],
                $item['score']['environment_score'],
                $item['score']['family_score'],
                $item['score']['match_percentage'],   // FIX: was total_score (now removed)
                $item['score']['match_percentage'],
                $rank++,
            ]
        );
    }
}


 // ====================================================================
        // 1. LIVE PREVIEW AJAX ENDPOINT (Asynchronous & isolated)
        // ====================================================================
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'assessment_preview') {
            header('Content-Type: application/json');
            try {
                // Collect submitted wizard choices
                $budget     = (float) ($_POST['budget'] ?? 0);
                $location   = $_POST['preferred_location'] ?? 'Any';
                $propType   = $_POST['property_type'] ?? 'Any';
                $household  = (int) ($_POST['household_size'] ?? 1);
                $comfort    = $_POST['comfort_priority'] ?? '';

                // FIX (input/output audit): tenure_preference, bedrooms, low_flood_risk,
                // and near_school are collected on Step 2 (data-assessment-field) and were
                // already being sent in this AJAX payload, but were never read here — the
                // preview ignored them entirely. Now passed through to the scoring engine.
                $tenurePreference = trim((string) ($_POST['tenure_preference'] ?? ''));
                $minBedrooms      = (int) ($_POST['bedrooms'] ?? 0);
                $lowFloodRisk     = !empty($_POST['low_flood_risk']) ? 1 : 0;
                $nearSchool       = !empty($_POST['near_school']) ? 1 : 0;

                $features = [
                    'smart_security'   => (int) ($_POST['smart_security']   ?? 0),
                    'smart_lighting'   => (int) ($_POST['smart_lighting']   ?? 0),
                    'smart_energy'     => (int) ($_POST['smart_energy']     ?? 0),
                    'smart_appliances' => (int) ($_POST['smart_appliances'] ?? 0),
                ];

                // Fetch user weights from assessment_criteria
                $criteria = run_query('SELECT criteria_key, weight FROM assessment_criteria')->fetchAll();
                $weights  = RecommendationEngine::WEIGHTS;
                foreach ($criteria as $criterion) {
                    $weights[$criterion['criteria_key']] = ((float) $criterion['weight']) / 100;
                }
                $weightTotal = array_sum($weights);
                if ($weightTotal > 0) {
                    foreach ($weights as $key => $weight) {
                        $weights[$key] = $weight / $weightTotal;
                    }
                }

                // FIX: Extend filter to 120 % of budget — same as save_recommendations —
                // so the preview and saved results are drawn from the same candidate pool.
                $sql    = 'SELECT * FROM properties WHERE COALESCE(median_price, price) <= ?';
                $params = [$budget * 1.20];

                if (!empty($location) && $location !== 'Any') {
                    $sql .= ' AND (township LIKE ? OR area LIKE ? OR state LIKE ?)';
                    $searchTerm = '%' . $location . '%';
                    array_push($params, $searchTerm, $searchTerm, $searchTerm);
                }
                $properties = run_query($sql, $params)->fetchAll();

                // FIX: Fetch the logged-in user's occupation so occupation-based
                // adjustments in the engine are actually applied during preview.
                //
                // FIX (input/output audit): the visible wizard has no income/
                // commitment inputs (those live on the Financial Profile page), so
                // $_POST['monthly_income']/['monthly_commitment'] were always 0 —
                // meaning the preview's affordability tilt silently ran on a net
                // income of 0 no matter what the user had actually set up, and the
                // preview could rank differently from the saved results. Pull the
                // real gross income + commitments the same way assessment_store
                // does, so preview and final scoring agree.
                $previewOccupation = '';
                $income            = 0.0;
                $commitment        = 0.0;
                if (Auth::check()) {
                    $uid  = (int) Auth::user()['id'];
                    $uRow = run_query(
                        'SELECT occupation, gross_monthly_income FROM users WHERE id = ? LIMIT 1',
                        [$uid]
                    )->fetch();
                    $previewOccupation = $uRow['occupation'] ?? '';
                    $income            = (float) ($uRow['gross_monthly_income'] ?? 0);
                    $commitment        = (float) run_query(
                        'SELECT COALESCE(SUM(amount),0) AS total FROM user_commitments WHERE user_id = ?',
                        [$uid]
                    )->fetch()['total'];
                }

                $netIncome      = max(0.0, $income - $commitment);
                $assessmentMock = [
                    'budget'             => $budget,
                    'monthly_income'     => $income,
                    'monthly_commitment' => $commitment,
                    'net_income'         => $netIncome,
                    'preferred_location' => $location,
                    'property_type'      => $propType,
                    'household_size'     => $household,
                    'comfort_priority'   => $comfort,
                    'occupation'         => $previewOccupation,   // FIX: was always missing
                    'smart_security'     => $features['smart_security'],
                    'smart_lighting'     => $features['smart_lighting'],
                    'smart_energy'       => $features['smart_energy'],
                    'smart_appliances'   => $features['smart_appliances'],
                    'tenure_preference'  => $tenurePreference,    // FIX: collected, never read
                    'bedrooms'           => $minBedrooms,         // FIX: collected, never read
                    'low_flood_risk'     => $lowFloodRisk,        // FIX: collected, never read
                    'near_school'        => $nearSchool,          // FIX: collected, never read
                ];

                $scored = [];
                foreach ($properties as $property) {
                    $scoreDetails = RecommendationEngine::score($assessmentMock, $property, $weights);
                    if ($scoreDetails['match_percentage'] > 40) {
                        $scored[] = [
                            'id'               => $property['id'],
                            'title'            => $property['property_name'] ?? $property['township'] ?? 'Property',
                            'match_percentage' => number_format((float) $scoreDetails['match_percentage'], 1) . '%',
                            '_sort'            => $scoreDetails['match_percentage'],
                        ];
                    }
                }

                // FIX: Sort by match_percentage (total_score was redundant — now removed).
                usort($scored, fn ($a, $b) => $b['_sort'] <=> $a['_sort']);
                // Strip the internal sort key before sending to client
                $output = array_map(static fn ($r) => [
                    'id'               => $r['id'],
                    'title'            => $r['title'],
                    'match_percentage' => $r['match_percentage'],
                ], array_slice($scored, 0, 3));

                echo json_encode(['success' => true, 'data' => $output]);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
        }
        
// ====================================================================
// 2. STANDARD PERSISTENT FORMS (Original security context untouched)
// ====================================================================
if ($dbError === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $action = $_POST['action'] ?? '';


    if ($action === 'register') {
        $name = trim((string) post('full_name'));
        $email = strtolower(trim((string) post('email')));
        $password = (string) post('password');

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            flash('Please enter a valid name, email and password with at least 8 characters.', 'danger');
            redirect('register');
        }

        $dob = trim((string) post('date_of_birth'));
        if ($dob !== '') {
            $dobTs = strtotime($dob);
            if ($dobTs === false || $dobTs >= strtotime('-18 years')) {
                flash('Please enter a valid date of birth. You must be at least 18 years old.', 'danger');
                redirect('register');
            }
        }
        try {
            run_query(
                'INSERT INTO users (full_name, email, password, phone, occupation, date_of_birth, role) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$name, $email, password_hash($password, PASSWORD_DEFAULT), post('phone'), post('occupation'), $dob !== '' ? $dob : null, 'user']
            );
            flash('Account created. You can sign in now.');
            redirect('login');
        } catch (Throwable) {
            flash('That email is already registered.', 'danger');
            redirect('register');
        }
    }

    if ($action === 'login') {
        if (Auth::login((string) post('email'), (string) post('password'))) {
            $role = Auth::user()['role'] ?? '';
            if ($role === 'admin') {
                redirect('admin_dashboard');
            }

            $hasAssessment = (int) run_query(
                'SELECT COUNT(*) total FROM assessments WHERE user_id = ?',
                [Auth::user()['id']]
            )->fetch()['total'];
            redirect($hasAssessment > 0 ? 'dashboard' : 'assessment');
        }
        flash('Invalid email or password.', 'danger');
        redirect('login');
    }

    if ($action === 'profile') {
        Auth::requireLogin();
        $dob = trim((string) post('date_of_birth'));
        if ($dob !== '') {
            $dobTs = strtotime($dob);
            if ($dobTs === false || $dobTs >= strtotime('-18 years')) {
                flash('Please enter a valid date of birth. You must be at least 18 years old.', 'danger');
                redirect('profile');
            }
        }
        run_query(
            'UPDATE users SET full_name = ?, phone = ?, occupation = ?, date_of_birth = ? WHERE id = ?',
            [
                trim((string) post('full_name')),
                trim((string) post('phone')),
                (string) post('occupation'),
                $dob !== '' ? $dob : null,
                Auth::user()['id'],
            ]
        );
        flash('Profile updated.');
        redirect('profile');
    }

    if ($action === 'financial_profile_save') {
        Auth::requireLogin();
        $gross = (float) post('gross_monthly_income');
        if ($gross <= 0) {
            flash('Please enter a valid gross monthly income greater than zero.', 'danger');
            redirect('financial_profile');
        }
        run_query(
            'UPDATE users SET gross_monthly_income = ? WHERE id = ?',
            [$gross, Auth::user()['id']]
        );
        flash('Income saved.');
        redirect('financial_profile');
    }

    if ($action === 'commitment_add') {
        Auth::requireLogin();
        $label    = trim((string) post('label'));
        $category = (string) post('category');
        $amount   = (float) post('amount');
        $allowed  = ['car_loan','study_loan','personal_loan','credit_card','existing_mortgage','other'];
        if ($label === '') {
            flash('Please enter a description for this commitment.', 'danger');
            redirect('financial_profile');
        }
        if (!in_array($category, $allowed, true)) {
            flash('Invalid commitment category.', 'danger');
            redirect('financial_profile');
        }
        if ($amount <= 0) {
            flash('Amount must be greater than zero.', 'danger');
            redirect('financial_profile');
        }
        run_query(
            'INSERT INTO user_commitments (user_id, label, category, amount) VALUES (?, ?, ?, ?)',
            [Auth::user()['id'], $label, $category, $amount]
        );
        flash('Commitment added.');
        redirect('financial_profile');
    }

    if ($action === 'commitment_delete') {
        Auth::requireLogin();
        $commitId = (int) post('commitment_id');
        if ($commitId <= 0) {
            flash('Invalid commitment.', 'danger');
            redirect('financial_profile');
        }
        $row = run_query(
            'SELECT id FROM user_commitments WHERE id = ? AND user_id = ?',
            [$commitId, Auth::user()['id']]
        )->fetch();
        if (!$row) {
            flash('Commitment not found.', 'danger');
            redirect('financial_profile');
        }
        run_query('DELETE FROM user_commitments WHERE id = ?', [$commitId]);
        flash('Commitment removed.', 'warning');
        redirect('financial_profile');
    }

    if ($action === 'assessment_store') {
        Auth::requireLogin();
        $uid = (int) Auth::user()['id'];

        // Pull income + commitments from financial profile — not from the form.
        // If the user hasn't set up their financial profile yet, redirect them there first.
        $uRow = run_query(
            'SELECT occupation, gross_monthly_income, date_of_birth FROM users WHERE id = ? LIMIT 1',
            [$uid]
        )->fetch();

        $grossIncome = (float) ($uRow['gross_monthly_income'] ?? 0);
        if ($grossIncome <= 0) {
            flash('Please set up your financial profile (income and commitments) before running an assessment.', 'danger');
            redirect('financial_profile');
        }

        $totalCommitment = (float) run_query(
            'SELECT COALESCE(SUM(amount),0) AS total FROM user_commitments WHERE user_id = ?',
            [$uid]
        )->fetch()['total'];

        $netIncome = max(0.0, $grossIncome - $totalCommitment);
        if ($netIncome <= 0) {
            flash('Your total monthly commitments exceed your gross income. Please review your financial profile.', 'danger');
            redirect('financial_profile');
        }

        // Derive age from date_of_birth stored in the users table
        $age = null;
        if (!empty($uRow['date_of_birth'])) {
            $age = (int) date_diff(new DateTime($uRow['date_of_birth']), new DateTime())->y;
        }

        $budget = (float) post('budget');
        if ($budget <= 0) {
            flash('Please enter a valid budget.', 'danger');
            redirect('assessment');
        }

        $assessment = [
            'user_id'            => $uid,
            'age'                => $age,
            'monthly_income'     => $grossIncome,
            'monthly_commitment' => $totalCommitment,
            'net_income'         => $netIncome,
            'budget'             => $budget,
            'household_size'     => 1, // kept in DB for backward compat; no longer collected
            'preferred_location' => trim((string) post('preferred_location')),
            'property_type'      => (string) post('property_type', 'Any'),
            'smart_lighting'     => in_array((string) post('smart_lighting', '0'), ['1', 'on'], true) ? 1 : 0,
            'smart_security'     => in_array((string) post('smart_security', '0'), ['1', 'on'], true) ? 1 : 0,
            'smart_appliances'   => in_array((string) post('smart_appliances', '0'), ['1', 'on'], true) ? 1 : 0,
            'smart_energy'       => in_array((string) post('smart_energy', '0'), ['1', 'on'], true) ? 1 : 0,
            'comfort_priority'   => (string) post('comfort_priority'),
            'occupation'         => $uRow['occupation'] ?? '',
            // FIX (input/output audit): these four Step 2 fields were collected
            // by the wizard (data-assessment-field) and even reached this far in
            // $_POST, but were never read here, never saved, and never influenced
            // scoring — filling them in changed nothing. Now read, persisted, and
            // passed into save_recommendations() so RecommendationEngine::score()
            // actually applies them (see RecommendationEngine.php).
            'tenure_preference'  => trim((string) post('tenure_preference')),
            'bedrooms'           => (int) post('bedrooms', 0),
            'low_flood_risk'     => in_array((string) post('low_flood_risk', '0'), ['1', 'on'], true) ? 1 : 0,
            'near_school'        => in_array((string) post('near_school', '0'), ['1', 'on'], true) ? 1 : 0,
        ];

        run_query(
            'INSERT INTO assessments
            (user_id, age, monthly_income, monthly_commitment, budget, household_size, preferred_location, property_type, smart_lighting, smart_security, smart_appliances, smart_energy, comfort_priority, tenure_preference, min_bedrooms, low_flood_risk, near_school)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $assessment['user_id'],
                $assessment['age'],
                $assessment['monthly_income'],
                $assessment['monthly_commitment'],
                $assessment['budget'],
                $assessment['household_size'],
                $assessment['preferred_location'],
                $assessment['property_type'],
                $assessment['smart_lighting'],
                $assessment['smart_security'],
                $assessment['smart_appliances'],
                $assessment['smart_energy'],
                $assessment['comfort_priority'],
                $assessment['tenure_preference'] !== '' ? $assessment['tenure_preference'] : null,
                $assessment['bedrooms'] > 0 ? $assessment['bedrooms'] : null,
                $assessment['low_flood_risk'],
                $assessment['near_school'],
            ]
        );
        $assessmentId = (int) Database::connect()->lastInsertId();

        save_recommendations($assessmentId, $assessment);
        redirect('results', ['id' => $assessmentId]);
    }

    if ($action === 'favorite_toggle') {
        Auth::requireLogin();
        $propertyId = (int) post('property_id');
        $exists = run_query('SELECT id FROM favorites WHERE user_id = ? AND property_id = ?', [Auth::user()['id'], $propertyId])->fetch();
        if ($exists) {
            run_query('DELETE FROM favorites WHERE id = ?', [$exists['id']]);
            flash('Property removed from favorites.', 'warning');
        } else {
            run_query('INSERT INTO favorites (user_id, property_id) VALUES (?, ?)', [Auth::user()['id'], $propertyId]);
            flash('Property saved to favorites.');
        }
        $returnPage = (string) post('return_page', 'property_directory');
        $returnId   = (int) post('id', 0);
        $params     = $returnId > 0 ? ['id' => $returnId] : [];
        redirect($returnPage === 'favorites' ? 'dashboard' : $returnPage, $params);
    }

    if ($action === 'admin_property_save') {
        Auth::requireAdmin();
        $fields = [
            'township', 'area', 'property_name', 'property_type', 'location', 'state', 'tenure', 'type',
            'price', 'description', 'image', 'median_price', 'median_psf', 'estimated_rental_yield_pct',
            'historical_capital_appreciation_3yr_pct', 'est_monthly_mortgage_rm', 'transactions',
            'safety_score', 'crime_risk', 'flood_risk', 'distance_to_public_transport_km',
            'distance_to_mall_km', 'distance_to_school_km', 'distance_to_hospital_km',
            'bedrooms', 'bathrooms', 'built_up_sqft', 'house_size_sqft', 'smart_readiness_score',
            'security_score', 'sustainability_score', 'family_score', 'acoustic_score',
        ];
        $numericFields = [
            'price', 'median_price', 'median_psf', 'estimated_rental_yield_pct',
            'historical_capital_appreciation_3yr_pct', 'est_monthly_mortgage_rm', 'safety_score',
            'distance_to_public_transport_km', 'distance_to_mall_km', 'distance_to_school_km',
            'distance_to_hospital_km',
        ];
        $intFields = [
            'transactions', 'bedrooms', 'bathrooms', 'built_up_sqft', 'house_size_sqft',
            'smart_readiness_score', 'security_score', 'sustainability_score', 'family_score', 'acoustic_score',
        ];

        $propertyData = [];
        foreach ($fields as $field) {
            $value = post($field);
            if (in_array($field, $numericFields, true)) {
                $propertyData[$field] = trim((string) $value) === '' ? null : (float) $value;
            } elseif (in_array($field, $intFields, true)) {
                $propertyData[$field] = trim((string) $value) === '' ? 0 : (int) $value;
            } else {
                $propertyData[$field] = trim((string) $value);
            }
        }

        $propertyData['property_name'] = $propertyData['property_name'] ?: $propertyData['township'];
        $propertyData['property_type'] = $propertyData['property_type'] ?: $propertyData['type'];
        $propertyData['location'] = $propertyData['location'] ?: $propertyData['area'];
        $propertyData['price'] = $propertyData['price'] ?: (float) ($propertyData['median_price'] ?? 0);
        $propertyData['median_price'] = $propertyData['median_price'] ?: (float) ($propertyData['price'] ?? 0);
        $propertyData['built_up_sqft'] = $propertyData['built_up_sqft'] ?: (int) $propertyData['house_size_sqft'];
        $propertyData['house_size_sqft'] = $propertyData['house_size_sqft'] ?: (int) $propertyData['built_up_sqft'];
        $values = array_map(static fn (string $field) => $propertyData[$field], $fields);

        if ((int) post('id') > 0) {
            $setClause = implode(', ', array_map(static fn (string $field): string => "{$field}=?", $fields));
            run_query(
                "UPDATE properties SET {$setClause} WHERE id=?",
                array_merge($values, [(int) post('id')])
            );
            flash('Property updated.');
        } else {
            $columns = implode(', ', $fields);
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            run_query(
                "INSERT INTO properties ({$columns}) VALUES ({$placeholders})",
                $values
            );
            flash('Property added.');
        }
        redirect('admin_properties');
    }

    if ($action === 'admin_property_delete') {
        Auth::requireAdmin();
        run_query('DELETE FROM properties WHERE id = ?', [(int) post('id')]);
        flash('Property deleted.', 'warning');
        redirect('admin_properties');
    }

    if ($action === 'admin_user_role') {
        Auth::requireAdmin();
        if ((int) post('id') !== (int) Auth::user()['id']) {
            run_query('UPDATE users SET role = ? WHERE id = ?', [post('role'), (int) post('id')]);
            flash('User role updated.');
        }
        redirect('admin_users');
    }

}

if ($page === 'logout') {
    Auth::logout();
    session_start();
    flash('You have signed out.');
    redirect('landing');
}

if ($page === 'export_reports') {
    Auth::requireAdmin();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="smart-home-advisor-reports.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Assessment ID', 'User', 'Budget', 'Property', 'Rank', 'Match %', 'Created']);
    $rows = run_query(
        'SELECT a.id, u.full_name, a.budget, p.property_name, r.rank_position, r.match_percentage, a.created_at
         FROM recommendations r
         JOIN assessments a ON a.id = r.assessment_id
         JOIN users u ON u.id = a.user_id
         JOIN properties p ON p.id = r.property_id
         ORDER BY a.created_at DESC, r.rank_position ASC'
    )->fetchAll();
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    exit;
}

function render_header(string $title = APP_NAME): void
{
    $user = Auth::user();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?></title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
        <link href="assets/css/app.css" rel="stylesheet">
        <link href="assets/css/property-advisor.css" rel="stylesheet">
    </head>
    <body>
    <nav class="navbar navbar-expand-lg sticky-top app-nav">
        <div class="container">
            <a class="navbar-brand fw-bold text-sage" href="<?= route('landing') ?>">Smart Home Advisor<span class="brand-dot"></span></a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                    <?php if ($user): ?>
                        <li class="nav-item"><a class="nav-link" href="<?= route($user['role'] === 'admin' ? 'admin_dashboard' : 'dashboard') ?>">Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= route('assessment') ?>">Assessment</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= route('property_directory') ?>">Directory</a></li>
                        <?php if ($user['role'] === 'admin'): ?>
                            <li class="nav-item"><a class="nav-link" href="<?= route('admin_properties') ?>">Admin</a></li>
                        <?php endif; ?>
                        <li class="nav-item"><a class="btn btn-sage btn-sm" href="<?= route('logout') ?>">Logout</a></li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="<?= route('landing') ?>#system">Our System</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= route('login') ?>">Login</a></li>
                        <li class="nav-item"><a class="btn btn-sage btn-sm" href="<?= route('register') ?>">Create Account</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    <main>
    <?php
    $flash = flash();
    if ($flash): ?>
        <div class="container mt-4">
            <div class="alert alert-<?= e($flash['type']) ?> border-0 shadow-sm"><?= e($flash['message']) ?></div>
        </div>
    <?php endif;
}

function render_footer(): void
{
    ?>
    </main>
    <footer class="border-top py-4 mt-5">
        <div class="container d-flex flex-column flex-md-row justify-content-between gap-2 small text-muted">
            <span>Smart Home Advisor. Serene systems for intelligent homes.</span>
            <span>Affordability 30% | Security 20% | Smart Readiness 20% | Comfort 15% | Family 15%</span>
        </div>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="assets/js/app.js"></script>
    </body>
    </html>
    <?php
}

if ($dbError !== null) {
    render_header('Database setup required');
    ?>
    <section class="container py-5">
        <div class="system-card p-4 p-lg-5">
            <p class="eyebrow">Setup needed</p>
            <h1 class="display-6 fw-bold text-sage">Connect the MySQL database first.</h1>
            <p class="text-muted mb-4">Import <code>database/schema.sql</code> into phpMyAdmin, then run <code>database/seed_admin.php</code> once from the command line or browser.</p>
            <pre class="setup-box"><?= e($dbError) ?></pre>
        </div>
    </section>
    <?php
    render_footer();
    exit;
}

render_header(APP_NAME);

if ($page === 'landing'): ?>
    <section class="hero">
        <div class="container py-5">
            <div class="row align-items-center g-5 py-lg-5">
                <div class="col-lg-6">
                    <span class="pill mb-4"><i class="fa-solid fa-leaf"></i> Eco-intelligent property advisory</span>
                    <h1 class="display-3 fw-bold text-sage tight">Intelligent living, balanced by nature.</h1>
                    <p class="lead text-muted mt-4">Find the right Malaysian smart-home property using budget, comfort, security, sustainability and family-fit scoring.</p>
                    <div class="d-flex flex-wrap gap-3 mt-4">
                        <a class="btn btn-sage btn-lg" href="<?= route('register') ?>">Start Assessment</a>
                        <a class="btn btn-outline-sage btn-lg" href="<?= route('login') ?>">Login</a>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="dashboard-preview">
                        <div class="d-flex justify-content-between align-items-start mb-4">
                            <div>
                                <p class="eyebrow mb-1">System overview</p>
                                <h2 class="h4 fw-bold text-ink">Recommendation Engine</h2>
                            </div>
                            <div class="icon-box"><i class="fa-solid fa-chart-simple"></i></div>
                        </div>
                        <div class="row g-3">
                            <div class="col-6"><div class="metric"><span>Best Match</span><strong>94%</strong></div></div>
                            <div class="col-6"><div class="metric"><span>Security Index</span><strong>9.2</strong></div></div>
                            <div class="col-12">
                                <div class="score-bars">
                                    <span style="height:45%"></span><span style="height:70%"></span><span style="height:62%"></span><span style="height:88%"></span><span style="height:76%"></span><span style="height:92%"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <section id="system" class="mint-section py-5">
        <div class="container py-lg-4">
            <p class="eyebrow">Our System</p>
            <h2 class="fw-bold text-sage mb-4">A complete property advisor, not just a brochure.</h2>
            <div class="row g-4">
                <?php foreach ([
                    ['fa-bolt', 'Weighted Scoring', 'Ranks properties using affordability, security, smart readiness, comfort and family suitability.'],
                    ['fa-user-shield', 'Secure Accounts', 'Session login, password hashing, CSRF protection and role-based access.'],
                    ['fa-house-laptop', 'Admin Control', 'Manage users, properties, criteria, reports and recommendation records.'],
                ] as $card): ?>
                    <div class="col-md-4"><div class="system-card h-100 p-4"><div class="icon-box mb-3"><i class="fa-solid <?= e($card[0]) ?>"></i></div><h3 class="h5 fw-bold text-ink"><?= e($card[1]) ?></h3><p class="text-muted mb-0"><?= e($card[2]) ?></p></div></div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php elseif ($page === 'login' || $page === 'register' || $page === 'forgot'): ?>
    <section class="container py-5 auth-wrap">
        <div class="system-card p-4 p-lg-5 mx-auto" style="max-width:520px">
            <p class="eyebrow"><?= $page === 'register' ? 'Create account' : ($page === 'forgot' ? 'Password help' : 'Welcome back') ?></p>
            <h1 class="h2 fw-bold text-sage mb-4"><?= $page === 'register' ? 'Register as a customer' : ($page === 'forgot' ? 'Forgot password' : 'Login to your advisor') ?></h1>
            <?php if ($page === 'forgot'): ?>
                <p class="text-muted">For this XAMPP classroom build, password reset is handled by the administrator from the user management panel.</p>
                <a class="btn btn-sage" href="<?= route('login') ?>">Back to Login</a>
            <?php else: ?>
                <form method="post" class="vstack gap-3">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="<?= $page === 'register' ? 'register' : 'login' ?>">
                    <?php if ($page === 'register'): ?>
                        <input class="form-control" name="full_name" required placeholder="Full name">
                        <input class="form-control" name="phone" placeholder="Phone number">
                        <select class="form-select" name="occupation">
                            <option value="">Occupation (optional)</option>
                            <optgroup label="Government / Public Sector">
                                <option>Civil Servant</option>
                                <option>Teacher / Lecturer</option>
                                <option>Doctor / Physician</option>
                                <option>Nurse</option>
                                <option>Army / Police</option>
                            </optgroup>
                            <optgroup label="Professional">
                                <option>Engineer</option>
                                <option>Lawyer / Attorney</option>
                                <option>Architect</option>
                                <option>Accountant</option>
                                <option>Banker / Finance</option>
                                <option>Pharmacist</option>
                                <option>Pilot</option>
                                <option>Manager / Executive</option>
                                <option>Director / CEO</option>
                            </optgroup>
                            <optgroup label="Self-Employed / Business">
                                <option>Business Owner / Entrepreneur</option>
                                <option>Freelancer / Consultant</option>
                                <option>Contractor</option>
                                <option>Trader / Hawker</option>
                            </optgroup>
                            <optgroup label="Other">
                                <option>Student / Graduate</option>
                                <option>Retired / Pensioner</option>
                                <option>Other</option>
                            </optgroup>
                        </select>
                        <label class="form-label">Date of Birth <span class="text-muted small">(must be 18 or older)</span></label>
                        <input class="form-control" type="date" name="date_of_birth"
                               max="<?= date('Y-m-d', strtotime('-18 years')) ?>"
                               placeholder="YYYY-MM-DD">
                    <?php endif; ?>
                    <input class="form-control" type="email" name="email" required placeholder="Email address">
                    <input class="form-control" type="password" name="password" required placeholder="Password (min 8 characters)">
                    <button class="btn btn-sage btn-lg" type="submit"><?= $page === 'register' ? 'Create Account' : 'Login' ?></button>
                    <div class="d-flex justify-content-between small">
                        <a href="<?= route($page === 'register' ? 'login' : 'register') ?>"><?= $page === 'register' ? 'Already have an account?' : 'Create account' ?></a>
                        <a href="<?= route('forgot') ?>">Forgot password?</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </section>
<?php elseif ($page === 'dashboard'):
    Auth::requireLogin();
    $stats = dashboard_stats((int) Auth::user()['id']);
    $recent = run_query(
        'SELECT a.*, MAX(r.match_percentage) best_match FROM assessments a LEFT JOIN recommendations r ON r.assessment_id = a.id WHERE a.user_id = ? GROUP BY a.id ORDER BY a.created_at DESC LIMIT 5',
        [Auth::user()['id']]
    )->fetchAll();
    ?>
    <section class="container py-5">
        <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
            <div><p class="eyebrow">User dashboard</p><h1 class="fw-bold text-sage">Welcome, <?= e(Auth::user()['full_name']) ?></h1></div>
            <a class="btn btn-sage align-self-start" href="<?= route('assessment') ?>"><i class="fa-solid fa-wand-magic-sparkles me-2"></i>New Assessment</a>
        </div>
        <div class="row g-4 mb-4">
            <?php foreach ([['Assessments', $stats['assessments']], ['Saved Properties', $stats['favorites']], ['Best Match', number_format($stats['best_match'], 1) . '%'], ['Properties', $stats['properties']]] as $stat): ?>
                <div class="col-6 col-lg-3"><div class="metric-card"><span><?= e($stat[0]) ?></span><strong><?= e((string) $stat[1]) ?></strong></div></div>
            <?php endforeach; ?>
        </div>
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="system-card p-4 h-100">
                    <h2 class="h5 fw-bold text-ink mb-3">Recent assessments</h2>
                    <?php if (!$recent): ?><p class="text-muted">No assessments yet.</p><?php endif; ?>
                    <?php foreach ($recent as $item): ?>
                        <div class="list-row">
                            <div><strong><?= e($item['property_type'] ?: 'Any property') ?></strong><span><?= e($item['preferred_location'] ?: 'Any location') ?> | <?= money((float) $item['budget']) ?></span></div>
                            <a class="btn btn-outline-sage btn-sm" href="<?= route('results', ['id' => $item['id']]) ?>"><?= number_format((float) $item['best_match'], 1) ?>%</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="system-card p-4 h-100">
                    <h2 class="h5 fw-bold text-ink mb-3">Quick tools</h2>
                    <div class="d-grid gap-2">
                        <a class="btn btn-outline-sage" href="<?= route('property_directory') ?>">Browse Properties</a>
                        <a class="btn btn-outline-sage" href="#favorited-properties">Saved Favorites</a>
                        <a class="btn btn-outline-sage" href="<?= route('mortgage') ?>">Mortgage Calculator</a>
                        <a class="btn btn-outline-sage" href="<?= route('profile') ?>">Edit Profile</a>
                        <a class="btn btn-outline-sage" href="<?= route('financial_profile') ?>">Financial Profile</a>
                    </div>
                </div>
            </div>
        </div>
        <?php require __DIR__ . '/partials/dashboard_favorites.php'; ?>
    </section>
<?php elseif ($page === 'assessment'):
    Auth::requireLogin();
    $uid = (int) Auth::user()['id'];
    // Load the user's saved financial profile to pre-fill income/commitment on this page
    $financialUser = run_query(
        'SELECT gross_monthly_income, date_of_birth FROM users WHERE id = ? LIMIT 1',
        [$uid]
    )->fetch();
    $profileGross = (float) ($financialUser['gross_monthly_income'] ?? 0);
    $profileCommitment = (float) run_query(
        'SELECT COALESCE(SUM(amount),0) AS total FROM user_commitments WHERE user_id = ?',
        [$uid]
    )->fetch()['total'];
    $profileNet = max(0, $profileGross - $profileCommitment);
    $profileMissing = $profileGross <= 0;

    $lastAssessment = run_query(
        'SELECT budget FROM assessments WHERE user_id = ? ORDER BY created_at DESC LIMIT 1',
        [$uid]
    )->fetch();
    $lastBudget = (float) ($lastAssessment['budget'] ?? 0);
    ?>
    <section class="container py-5" id="assessmentAdvisorShell" data-user-budget="<?= e((string) $lastBudget) ?>">
        <div class="system-card p-4 p-lg-5">
            <p class="eyebrow">Smart Home Advisor</p>
            <h1 class="fw-bold text-sage mb-2">Find your best property match.</h1>
            <p class="text-muted mb-4">Answer a few questions and we'll rank the best smart homes for you.</p>

            <div class="advisor-progress mb-4">
                <span class="active" data-assessment-step-indicator="1">1</span>
                <span data-assessment-step-indicator="2">2</span>
                <span data-assessment-step-indicator="3">3</span>
                <span data-assessment-step-indicator="4">4</span>
            </div>

            <?php if ($profileMissing): ?>
                <div class="alert alert-warning d-flex align-items-center gap-3 mb-4">
                    <i class="fa-solid fa-triangle-exclamation fa-lg"></i>
                    <div>
                        <strong>Financial profile not set up yet.</strong>
                        Your assessment needs your income and commitment details to work.
                        <a class="alert-link ms-1" href="<?= route('financial_profile') ?>">Set it up now &rarr;</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-sage d-flex align-items-center justify-content-between gap-3 mb-4">
                    <div>
                        <i class="fa-solid fa-circle-check me-2 text-sage"></i>
                        <strong>Income:</strong> RM <?= number_format($profileGross, 0) ?> &nbsp;&middot;&nbsp;
                        <strong>Commitments:</strong> RM <?= number_format($profileCommitment, 0) ?> &nbsp;&middot;&nbsp;
                        <strong>Net:</strong> RM <?= number_format($profileNet, 0) ?>
                    </div>
                    <a class="btn btn-outline-sage btn-sm" href="<?= route('financial_profile') ?>">Update</a>
                </div>
            <?php endif; ?>

            <form method="post" id="assessmentFinalForm">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="assessment_store">
                <input type="hidden" name="budget" value="">
                <input type="hidden" name="preferred_location" value="">
                <input type="hidden" name="property_type" value="Any">
                <input type="hidden" name="smart_lighting" value="1">
                <input type="hidden" name="smart_security" value="1">
                <input type="hidden" name="smart_appliances" value="1">
                <input type="hidden" name="smart_energy" value="1">
                <input type="hidden" name="comfort_priority" value="Energy efficiency">
            </form>

            <div id="advisorWizardAssessment">
                <div class="advisor-step active" data-assessment-step="1">
                    <p class="eyebrow">Step 1</p>
                    <h2 class="h4 fw-bold text-ink mb-3">Your Budget</h2>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Property Budget (RM)</label>
                            <input class="form-control form-control-lg" type="number" name="budget" min="1"
                                   required placeholder="550000"
                                   value="<?= $lastBudget > 0 ? (int)$lastBudget : '' ?>"
                                   data-assessment-field>
                            <div class="form-text">This is the maximum price you are willing to pay for a property.</div>
                        </div>
                    </div>
                    <script>
                    (function () {
                        const finalForm = document.getElementById('assessmentFinalForm');
                        // Sync budget visible input -> hidden budget field in the submit form
                        const budgetVis = document.querySelector('.advisor-step[data-assessment-step="1"] input[name="budget"]');
                        if (budgetVis && finalForm) {
                            budgetVis.addEventListener('input', function () {
                                const h = finalForm.querySelector('input[name="budget"]');
                                if (h) h.value = budgetVis.value;
                            });
                            // Initialise on load
                            const h = finalForm.querySelector('input[name="budget"]');
                            if (h && budgetVis.value) h.value = budgetVis.value;
                        }
                    })();
                    </script>
                </div>

                <div class="advisor-step" data-assessment-step="2">
                    <p class="eyebrow">Step 2</p>
                    <h2 class="h4 fw-bold text-ink mb-3">Your Ideal Home</h2>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Preferred Area or Township</label>
                            <input class="form-control" name="preferred_location" placeholder="Shah Alam, Bangi, Kulai" data-assessment-field>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Property Type</label>
                            <select class="form-select" name="property_type" data-assessment-field>
                                <option>Any</option>
                                <option>Condominium</option>
                                <option>Apartment</option>
                                <option>Terrace</option>
                                <option>Terrace House</option>
                                <option>Semi-D</option>
                                <option>Cluster House</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Tenure Preference</label>
                            <select class="form-select" name="tenure_preference" data-assessment-field>
                                <option>Any</option>
                                <option>Freehold</option>
                                <option>Leasehold</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Min Bedrooms Needed</label>
                            <input class="form-control" type="number" name="bedrooms" min="1" placeholder="3" data-assessment-field>
                        </div>
                        <div class="col-md-4">
                            <label class="check-card h-100"><input type="checkbox" name="low_flood_risk" value="1" data-assessment-field> <span>I prefer low flood risk areas</span></label>
                        </div>
                        <div class="col-md-4">
                            <label class="check-card h-100"><input type="checkbox" name="near_school" value="1" data-assessment-field> <span>Within 3 km of a school</span></label>
                        </div>
                    </div>
                </div>

                <div class="advisor-step" data-assessment-step="3">
                    <p class="eyebrow">Step 3</p>
                    <h2 class="h4 fw-bold text-ink mb-3">What Matters Most</h2>
                    <div class="row g-4">
                        <?php foreach ([
                            'smart_priority_slider' => ['Smart Home Readiness matters to me', 50],
                            'security_priority_slider' => ['Security & Safety is a priority', 50],
                            'sustainability_priority_slider' => ['Eco / Sustainability matters', 50],
                            'family_priority_slider' => ['Family & Space comfort', 70],
                            'quiet_priority_slider' => ['Quiet & Acoustic comfort', 50],
                        ] as $name => [$label, $value]): ?>
                            <div class="col-md-6">
                                <label class="form-label d-flex justify-content-between">
                                    <span><?= e($label) ?></span>
                                    <strong data-range-value="<?= e($name) ?>"><?= (int) $value ?></strong>
                                </label>
                                <input class="form-range advisor-range" type="range" min="0" max="100" value="<?= (int) $value ?>" name="<?= e($name) ?>" data-assessment-field>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="advisor-step" data-assessment-step="4">
                    <p class="eyebrow">Step 4</p>
                    <h2 class="h4 fw-bold text-ink mb-3">Your Matches Preview</h2>
                    <p class="text-muted">These preview matches use your advisor filters. Saving will store your assessment and generate the full ranked results.</p>
                    <div class="row g-4" id="assessmentPreviewResults">
                        <div class="col-12 text-muted">Your preview will appear here.</div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between flex-wrap gap-2 mt-4">
                <button class="btn btn-outline-sage" type="button" id="assessmentBack" disabled>Back</button>
                <button class="btn btn-sage" type="button" id="assessmentNext">Next</button>
            </div>
        </div>
    </section>
<?php elseif ($page === 'results'):
    Auth::requireLogin();
    $assessmentId = (int) ($_GET['id'] ?? 0);
    $assessment = run_query('SELECT * FROM assessments WHERE id = ? AND user_id = ?', [$assessmentId, Auth::user()['id']])->fetch();
    if (!$assessment && Auth::user()['role'] !== 'admin') { redirect('dashboard'); }
    $rows = run_query(
        'SELECT r.*, p.* FROM recommendations r JOIN properties p ON p.id = r.property_id WHERE r.assessment_id = ? ORDER BY r.rank_position ASC',
        [$assessmentId]
    )->fetchAll();
    // Compute net income for display — use DB generated column if available, otherwise derive it
    $displayNetIncome = (float) ($assessment['net_income']
        ?? max(0, (float)$assessment['monthly_income'] - (float)($assessment['monthly_commitment'] ?? 0)));
    $displayCommitment = (float) ($assessment['monthly_commitment'] ?? 0);
    $commitmentRatioDisplay = (float)$assessment['monthly_income'] > 0
        ? round($displayCommitment / (float)$assessment['monthly_income'] * 100, 1) : 0;
    ?>
    <section class="container py-5">
        <div class="d-flex justify-content-between gap-3 flex-wrap mb-4">
            <div><p class="eyebrow">Recommendation results</p><h1 class="fw-bold text-sage">Ranked property matches</h1></div>
            <button class="btn btn-outline-sage" onclick="window.print()"><i class="fa-solid fa-file-pdf me-2"></i>Print PDF Report</button>
        </div>

        <!-- Gaji Bersih Summary Panel -->
        <div class="system-card p-3 mb-4">
            <p class="eyebrow mb-2">Financial Profile Used for Ranking</p>
            <div class="row g-3 text-center">
                <div class="col-6 col-md-3">
                    <div class="text-muted small">Gross Monthly Income</div>
                    <div class="fw-bold text-ink"><?= money((float)$assessment['monthly_income']) ?></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted small">Monthly Commitments</div>
                    <div class="fw-bold <?= $commitmentRatioDisplay > 40 ? 'text-danger' : 'text-ink' ?>"><?= money($displayCommitment) ?>
                        <?php if ($commitmentRatioDisplay > 0): ?>
                        <span class="small text-muted">(<?= $commitmentRatioDisplay ?>% of income)</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted small">Gaji Bersih (Net Income)</div>
                    <div class="fw-bold text-sage fs-5"><?= money($displayNetIncome) ?></div>
                    <div class="text-muted" style="font-size:0.72rem;">Used for mortgage stress analysis</div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted small">Budget</div>
                    <div class="fw-bold text-ink"><?= money((float)$assessment['budget']) ?></div>
                </div>
            </div>
            <?php if ($commitmentRatioDisplay > 40): ?>
            <div class="alert alert-warning mt-3 mb-0 py-2 small">
                ⚠️ Your existing commitments are <strong><?= $commitmentRatioDisplay ?>%</strong> of your gross income. Property rankings reflect your <strong>net repayment capacity</strong>, not gross salary alone.
            </div>
            <?php endif; ?>
        </div>

        <?php
        // Home preferences used for ranking — these fields (Step 2 of the
        // wizard) now actually influence the family/security scores above,
        // so surface what was applied for transparency.
        $prefChips = [];
        if (!empty($assessment['tenure_preference']) && strcasecmp($assessment['tenure_preference'], 'Any') !== 0) {
            $prefChips[] = 'Tenure: ' . $assessment['tenure_preference'];
        }
        if (!empty($assessment['min_bedrooms'])) {
            $prefChips[] = (int) $assessment['min_bedrooms'] . '+ bedrooms';
        }
        if (!empty($assessment['low_flood_risk'])) {
            $prefChips[] = 'Low flood risk preferred';
        }
        if (!empty($assessment['near_school'])) {
            $prefChips[] = 'Within 3km of a school';
        }
        ?>
        <?php if ($prefChips): ?>
        <div class="system-card p-3 mb-4">
            <p class="eyebrow mb-2">Home Preferences Used for Ranking</p>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($prefChips as $chip): ?>
                    <span class="badge text-bg-light border"><?= e($chip) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <?php foreach ($rows as $row): ?>
                <div class="col-lg-4">
                    <div class="property-card h-100">
                        <div class="rank">#<?= (int) $row['rank_position'] ?></div>
                        <h2 class="h5 fw-bold text-ink"><?= e(property_text($row, 'property_name', 'township')) ?></h2>
                        <p class="text-muted small mb-2"><?= e(property_text($row, 'area', 'location')) ?>, <?= e(property_text($row, 'state')) ?></p>
                        <div class="match-ring"><?= number_format((float) $row['match_percentage'], 1) ?>%</div>
                        <p class="fw-bold text-sage mb-2"><?= money(property_number($row, 'median_price', 'price')) ?></p>
                        <div class="mini-scores">
                            <span>Afford <?= number_format((float) $row['affordability_score']) ?></span>
                            <span>Security <?= number_format((float) $row['security_score']) ?></span>
                            <span>Smart <?= number_format((float) $row['smart_score']) ?></span>
                        </div>
                        <a class="btn btn-outline-sage btn-sm mt-3" href="<?= route('property', ['id' => $row['property_id'], 'from' => 'results', 'assessment_id' => $assessmentId]) ?>">View Details</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php elseif ($page === 'property_directory'):
    require __DIR__ . '/partials/property_directory_tabs.php';
?>
<?php elseif ($page === 'properties'):
    redirect('property_directory');
elseif ($page === 'favorites'):
    redirect('dashboard');
?>
<?php elseif ($page === 'property'):
    Auth::requireLogin();
    $property = run_query('SELECT * FROM properties WHERE id = ?', [(int) ($_GET['id'] ?? 0)])->fetch();
    if (!$property) { redirect('property_directory'); }
    $features = run_query('SELECT * FROM smart_home_features WHERE property_id = ?', [$property['id']])->fetchAll();
    $lastAssessment = run_query(
        'SELECT budget FROM assessments WHERE user_id = ? ORDER BY created_at DESC LIMIT 1',
        [Auth::user()['id']]
    )->fetch();
    $userBudget = (float) ($lastAssessment['budget'] ?? 0);
    $returnPage = $_GET['from'] ?? 'results';
    $returnId   = (int) ($_GET['assessment_id'] ?? 0);
    $backUrl    = $returnId > 0 ? route($returnPage, ['id' => $returnId]) : route('property_directory');
?>
<section class="container py-5" id="propertyDetailPage"
         data-property-id="<?= (int) $property['id'] ?>"
         data-user-budget="<?= $userBudget ?>">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <p class="eyebrow">Property details</p>
            <h1 class="fw-bold text-sage"><?= e(property_text($property, 'property_name', 'township')) ?></h1>
            <p class="text-muted mb-0"><?= e(property_text($property, 'area', 'location')) ?>, <?= e(property_text($property, 'state')) ?></p>
        </div>
        <a class="btn btn-outline-sage" href="<?= e($backUrl) ?>">← Back to Results</a>
    </div>

    <!-- Rule badges — loaded by JS below -->
    <div id="propertyRuleBadges" class="d-flex flex-wrap gap-2 mb-4"></div>

    <!-- Description -->
    <?php if (!empty($property['description'])): ?>
    <p class="lead text-muted mb-4"><?= e($property['description']) ?></p>
    <?php endif; ?>

    <div class="row g-4">

        <!-- GROUP 1: Core Data -->
        <div class="col-lg-6">
            <div class="system-card p-4 h-100">
                <h2 class="h6 fw-bold text-ink mb-3">Core Data</h2>
                <?php foreach ([
                    ['Township',       property_text($property, 'township')],
                    ['Area',           property_text($property, 'area', 'location')],
                    ['State',          property_text($property, 'state')],
                    ['Property Type',  property_text($property, 'type', 'property_type')],
                    ['Tenure',         property_text($property, 'tenure')],
                    ['Bedrooms',       (string) ((int) ($property['bedrooms'] ?? 0))],
                    ['Bathrooms',      (string) ((int) ($property['bathrooms'] ?? 0))],
                    ['Size (sqft)',    number_format(property_number($property, 'house_size_sqft', 'built_up_sqft'))],
                ] as [$label, $value]): ?>
                    <div class="d-flex justify-content-between border-bottom py-2">
                        <span class="text-muted"><?= e($label) ?></span>
                        <strong><?= e($value ?: '—') ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- GROUP 2: Financials -->
        <div class="col-lg-6">
            <div class="system-card p-4 h-100">
                <h2 class="h6 fw-bold text-ink mb-3">Financials</h2>
                <?php foreach ([
                    ['Median Price',          money(property_number($property, 'median_price', 'price'))],
                    ['Median PSF',            'RM ' . number_format((float) ($property['median_psf'] ?? 0), 2)],
                    ['Est. Monthly Mortgage', money((float) ($property['est_monthly_mortgage_rm'] ?? 0))],
                    ['Rental Yield',          number_format((float) ($property['estimated_rental_yield_pct'] ?? 0), 2) . '%'],
                    ['3yr Capital Growth',    number_format((float) ($property['historical_capital_appreciation_3yr_pct'] ?? 0), 2) . '%'],
                    ['Total Transactions',    (string) ((int) ($property['transactions'] ?? 0))],
                ] as [$label, $value]): ?>
                    <div class="d-flex justify-content-between border-bottom py-2">
                        <span class="text-muted"><?= e($label) ?></span>
                        <strong><?= e($value ?: '—') ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- GROUP 3: Risk & Safety -->
        <div class="col-lg-6">
            <div class="system-card p-4 h-100">
                <h2 class="h6 fw-bold text-ink mb-3">Risk & Safety</h2>
                <?php foreach ([
                    ['Safety Score', (string) ($property['safety_score'] ?? '—')],
                    ['Crime Risk',   property_text($property, 'crime_risk')],
                    ['Flood Risk',   property_text($property, 'flood_risk')],
                ] as [$label, $value]): ?>
                    <div class="d-flex justify-content-between border-bottom py-2">
                        <span class="text-muted"><?= e($label) ?></span>
                        <strong><?= e($value ?: '—') ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- GROUP 4: Proximity -->
        <div class="col-lg-6">
            <div class="system-card p-4 h-100">
                <h2 class="h6 fw-bold text-ink mb-3">Proximity to Amenities</h2>
                <?php foreach ([
                    ['Public Transport', number_format((float) ($property['distance_to_public_transport_km'] ?? 0), 1) . ' km'],
                    ['Nearest Mall',     number_format((float) ($property['distance_to_mall_km'] ?? 0), 1) . ' km'],
                    ['Nearest School',   number_format((float) ($property['distance_to_school_km'] ?? 0), 1) . ' km'],
                    ['Nearest Hospital', number_format((float) ($property['distance_to_hospital_km'] ?? 0), 1) . ' km'],
                ] as [$label, $value]): ?>
                    <div class="d-flex justify-content-between border-bottom py-2">
                        <span class="text-muted"><?= e($label) ?></span>
                        <strong><?= e($value ?: '—') ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- GROUP 5: Smart Home Scores -->
        <div class="col-lg-6">
            <div class="system-card p-4 h-100">
                <h2 class="h6 fw-bold text-ink mb-3">Smart Home Scores</h2>
                <?php foreach ([
                    ['Smart Readiness',  (string) ((int) ($property['smart_readiness_score'] ?? 0))],
                    ['Security Score',   (string) ((int) ($property['security_score'] ?? 0))],
                    ['Sustainability',   (string) ((int) ($property['sustainability_score'] ?? 0))],
                    ['Family Score',     (string) ((int) ($property['family_score'] ?? 0))],
                    ['Acoustic Comfort', (string) ((int) ($property['acoustic_score'] ?? 0))],
                ] as [$label, $value]): ?>
                    <div class="d-flex justify-content-between border-bottom py-2">
                        <span class="text-muted"><?= e($label) ?></span>
                        <strong><?= e($value ?: '—') ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- GROUP 6: Smart Features (from smart_home_features table) -->
        <?php if ($features): ?>
        <div class="col-lg-6">
            <div class="system-card p-4 h-100">
                <h2 class="h6 fw-bold text-ink mb-3">Smart Features</h2>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($features as $feature): ?>
                        <span class="pill"><?= e($feature['feature_name']) ?> · <?= e($feature['category']) ?> · <?= e($feature['impact_level']) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /row -->

    <!-- Favorite toggle -->
    <?php
    $isFavorite = (bool) run_query(
        'SELECT id FROM favorites WHERE user_id = ? AND property_id = ?',
        [Auth::user()['id'], $property['id']]
    )->fetch();
    ?>
    <div class="mt-4">
        <form method="post" class="d-inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="favorite_toggle">
            <input type="hidden" name="property_id" value="<?= (int) $property['id'] ?>">
            <input type="hidden" name="return_page" value="property">
            <input type="hidden" name="id" value="<?= (int) $property['id'] ?>">
            <button class="btn <?= $isFavorite ? 'btn-sage' : 'btn-outline-sage' ?>">
                <i class="fa-solid fa-heart me-2"></i><?= $isFavorite ? 'Saved to Favorites' : 'Save to Favorites' ?>
            </button>
        </form>
    </div>

</section>

<script>
(function () {
    const section = document.getElementById('propertyDetailPage');
    if (!section) return;
    const propertyId = section.dataset.propertyId;
    const budget = section.dataset.userBudget || 0;
    const badgeContainer = document.getElementById('propertyRuleBadges');

    fetch(`api/rules.php?property_id=${encodeURIComponent(propertyId)}&budget=${encodeURIComponent(budget)}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
    })
    .then(r => r.json())
    .then(payload => {
        if (!payload.success) return;
        const all = [...(payload.rules_fired || []), ...(payload.warnings || [])];
        if (!all.length) return;
        const severityIcon = { positive: '✅', warning: '⚠️', info: '💡' };
        badgeContainer.innerHTML = all.map(r =>
            `<span class="pill pill-${r.severity}" title="${r.explanation || ''}">${severityIcon[r.severity] || '•'} ${r.label}</span>`
        ).join('');
    })
    .catch(() => {});
})();
</script>
<?php elseif ($page === 'mortgage'):
    Auth::requireLogin(); ?>
    <section class="container py-5">
        <div class="system-card p-4 p-lg-5">
            <p class="eyebrow">Affordability tool</p>
            <h1 class="fw-bold text-sage mb-4">Mortgage calculator</h1>
            <div class="row g-4">
                <div class="col-lg-6">
                    <label class="form-label">Property Price</label><input id="calcPrice" class="form-control mb-3" type="number" value="550000">
                    <label class="form-label">Down Payment (%)</label><input id="calcDown" class="form-control mb-3" type="number" value="10">
                    <label class="form-label">Interest Rate (%)</label><input id="calcRate" class="form-control mb-3" type="number" step="0.1" value="4.2">
                    <label class="form-label">Years</label><input id="calcYears" class="form-control mb-3" type="number" value="35">
                    <button class="btn btn-sage" onclick="calculateMortgage()">Calculate</button>
                </div>
                <div class="col-lg-6"><div class="metric-card h-100 d-flex flex-column justify-content-center"><span>Estimated Monthly Payment</span><strong id="mortgageResult">RM 0</strong></div></div>
            </div>
        </div>
    </section>
<?php elseif ($page === 'profile'):
    Auth::requireLogin();
    $user = run_query('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) Auth::user()['id']])->fetch(); ?>
    <section class="container py-5">
        <div class="system-card p-4 p-lg-5 mx-auto" style="max-width:680px">
            <p class="eyebrow">Profile</p><h1 class="fw-bold text-sage mb-4">Edit your profile</h1>
            <form method="post" class="vstack gap-3">
                <?= Csrf::field() ?><input type="hidden" name="action" value="profile">
                <div>
                    <label class="form-label">Full Name</label>
                    <input class="form-control" name="full_name" required value="<?= e($user['full_name']) ?>">
                </div>
                <div>
                    <label class="form-label">Phone</label>
                    <input class="form-control" name="phone" value="<?= e($user['phone'] ?? '') ?>" placeholder="Phone">
                </div>
                <div>
                    <label class="form-label">Date of Birth <span class="text-muted small">(must be 18 or older)</span></label>
                    <input class="form-control" type="date" name="date_of_birth"
                           max="<?= date('Y-m-d', strtotime('-18 years')) ?>"
                           value="<?= e($user['date_of_birth'] ?? '') ?>">
                    <?php if (!empty($user['date_of_birth'])): ?>
                        <div class="form-text">Age: <?= (int) date_diff(new DateTime($user['date_of_birth']), new DateTime())->y ?> years old</div>
                    <?php endif; ?>
                </div>
                <?php
                $occupationOptions = [
                    'Government / Public Sector' => ['Civil Servant','Teacher / Lecturer','Doctor / Physician','Nurse','Army / Police'],
                    'Professional'               => ['Engineer','Lawyer / Attorney','Architect','Accountant','Banker / Finance','Pharmacist','Pilot','Manager / Executive','Director / CEO'],
                    'Self-Employed / Business'   => ['Business Owner / Entrepreneur','Freelancer / Consultant','Contractor','Trader / Hawker'],
                    'Other'                      => ['Student / Graduate','Retired / Pensioner','Other'],
                ];
                ?>
                <select class="form-select" name="occupation">
                    <option value="">Select occupation</option>
                    <?php foreach ($occupationOptions as $group => $opts): ?>
                        <optgroup label="<?= e($group) ?>">
                            <?php foreach ($opts as $opt): ?>
                                <option <?= $user['occupation'] === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sage">Save Profile</button>
            </form>
        </div>
    </section>
<?php elseif ($page === 'financial_profile'):
    Auth::requireLogin();
    $uid = (int) Auth::user()['id'];
    $fpUser = run_query('SELECT gross_monthly_income FROM users WHERE id = ? LIMIT 1', [$uid])->fetch();
    $commitments = run_query(
        'SELECT * FROM user_commitments WHERE user_id = ? ORDER BY category, created_at',
        [$uid]
    )->fetchAll();
    $totalCommitment = array_sum(array_column($commitments, 'amount'));
    $gross = (float) ($fpUser['gross_monthly_income'] ?? 0);
    $net   = max(0, $gross - $totalCommitment);
    $categoryLabels = [
        'car_loan'          => 'Car Loan',
        'study_loan'        => 'Study Loan (PTPTN etc.)',
        'personal_loan'     => 'Personal Loan',
        'credit_card'       => 'Credit Card',
        'existing_mortgage' => 'Existing Mortgage / Home Loan',
        'other'             => 'Other',
    ];
    ?>
    <section class="container py-5">
        <div class="row g-4 justify-content-center">

            <!-- Gross Income Card -->
            <div class="col-lg-5">
                <div class="system-card p-4 h-100">
                    <p class="eyebrow">Financial Profile</p>
                    <h1 class="fw-bold text-sage mb-1">Your Income</h1>
                    <p class="text-muted mb-4">Set your gross monthly income (before EPF, SOCSO, tax deductions).</p>
                    <form method="post" class="vstack gap-3">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="financial_profile_save">
                        <div>
                            <label class="form-label fw-semibold">Gross Monthly Income (RM)</label>
                            <input class="form-control form-control-lg" type="number" name="gross_monthly_income"
                                   min="1" step="1" required placeholder="6500"
                                   value="<?= $gross > 0 ? (int)$gross : '' ?>">
                            <div class="form-text">Total salary before any deductions.</div>
                        </div>
                        <button class="btn btn-sage">Save Income</button>
                    </form>

                    <?php if ($gross > 0): ?>
                    <hr class="my-4">
                    <div class="row g-3 text-center">
                        <div class="col-4">
                            <div class="metric-card">
                                <span>Gross Income</span>
                                <strong class="text-sage">RM <?= number_format($gross, 0) ?></strong>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="metric-card">
                                <span>Total Commitments</span>
                                <strong class="text-danger">RM <?= number_format($totalCommitment, 0) ?></strong>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="metric-card">
                                <span>Net Income</span>
                                <strong class="<?= $net > 0 ? 'text-sage' : 'text-danger' ?>">RM <?= number_format($net, 0) ?></strong>
                            </div>
                        </div>
                    </div>
                    <?php if ($net <= 0 && $gross > 0): ?>
                        <div class="alert alert-danger mt-3 mb-0">
                            <i class="fa-solid fa-triangle-exclamation me-2"></i>
                            Your commitments exceed your income. Please review and remove some entries below.
                        </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Commitments Card -->
            <div class="col-lg-7">
                <div class="system-card p-4 h-100">
                    <h2 class="fw-bold text-ink mb-1">Monthly Commitments</h2>
                    <p class="text-muted mb-4">List every fixed monthly payment — car loan, study loan, credit card minimum, personal loan, existing mortgage, etc.</p>

                    <!-- Add commitment form -->
                    <form method="post" class="row g-2 mb-4">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="commitment_add">
                        <div class="col-md-4">
                            <input class="form-control" name="label" required placeholder="e.g. Myvi car loan"
                                   maxlength="150">
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" name="category" required>
                                <option value="">Category</option>
                                <?php foreach ($categoryLabels as $val => $lbl): ?>
                                    <option value="<?= e($val) ?>"><?= e($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <div class="input-group">
                                <span class="input-group-text">RM</span>
                                <input class="form-control" type="number" name="amount"
                                       min="1" step="1" required placeholder="450">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-sage w-100">Add</button>
                        </div>
                        <div class="col-12">
                            <div class="form-text">Enter the <strong>monthly</strong> amount you are obligated to pay.</div>
                        </div>
                    </form>

                    <!-- Commitments list -->
                    <?php if (!$commitments): ?>
                        <p class="text-muted">No commitments added yet. Add one above.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Description</th>
                                        <th>Category</th>
                                        <th class="text-end">Amount / mo</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($commitments as $c): ?>
                                    <tr>
                                        <td><?= e($c['label']) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= e($categoryLabels[$c['category']] ?? $c['category']) ?></span></td>
                                        <td class="text-end fw-semibold">RM <?= number_format((float)$c['amount'], 0) ?></td>
                                        <td class="text-end">
                                            <form method="post" class="d-inline">
                                                <?= Csrf::field() ?>
                                                <input type="hidden" name="action" value="commitment_delete">
                                                <input type="hidden" name="commitment_id" value="<?= (int) $c['id'] ?>">
                                                <button class="btn btn-outline-danger btn-sm"
                                                        onclick="return confirm('Remove this commitment?')">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="table-light fw-bold">
                                        <td colspan="2">Total Monthly Commitments</td>
                                        <td class="text-end text-danger">RM <?= number_format($totalCommitment, 0) ?></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>

                    <div class="mt-3 text-end">
                        <a class="btn btn-sage" href="<?= route('assessment') ?>">
                            <i class="fa-solid fa-wand-magic-sparkles me-2"></i>Go to Assessment
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

<?php elseif ($page === 'admin_dashboard'):
    Auth::requireAdmin(); $stats = admin_stats(); ?>
    <section class="container py-5">
        <p class="eyebrow">Admin dashboard</p><h1 class="fw-bold text-sage mb-4">System administration</h1>
        <div class="row g-4 mb-4">
            <?php foreach ([['Users', $stats['users']], ['Properties', $stats['properties']], ['Assessments', $stats['assessments']], ['Popular Type', $stats['popular_type']]] as $stat): ?>
                <div class="col-6 col-lg-3"><div class="metric-card"><span><?= e($stat[0]) ?></span><strong><?= e((string) $stat[1]) ?></strong></div></div>
            <?php endforeach; ?>
        </div>
        <div class="row g-3">
            <div class="col-md-4"><a class="btn btn-outline-sage w-100" href="<?= route('admin_users') ?>">Manage Users</a></div>
            <div class="col-md-4"><a class="btn btn-outline-sage w-100" href="<?= route('admin_properties') ?>">Manage Properties</a></div>
            <div class="col-md-4"><a class="btn btn-sage w-100" href="<?= route('admin_reports') ?>">Reports</a></div>
        </div>
    </section>
<?php elseif ($page === 'admin_users'):
    Auth::requireAdmin(); $users = run_query('SELECT * FROM users ORDER BY created_at DESC')->fetchAll(); ?>
    <section class="container py-5">
        <p class="eyebrow">Admin</p><h1 class="fw-bold text-sage mb-4">User management</h1>
        <div class="table-responsive system-card p-0"><table class="table align-middle mb-0"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Created</th><th></th></tr></thead><tbody>
            <?php foreach ($users as $user): ?><tr><td><?= e($user['full_name']) ?></td><td><?= e($user['email']) ?></td><td><?= e($user['role']) ?></td><td><?= e($user['created_at']) ?></td><td>
                <form method="post" class="d-flex gap-2 justify-content-end"><?= Csrf::field() ?><input type="hidden" name="action" value="admin_user_role"><input type="hidden" name="id" value="<?= (int) $user['id'] ?>"><select class="form-select form-select-sm w-auto" name="role"><option <?= $user['role'] === 'user' ? 'selected' : '' ?>>user</option><option <?= $user['role'] === 'admin' ? 'selected' : '' ?>>admin</option></select><button class="btn btn-outline-sage btn-sm">Save</button></form>
            </td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
<?php elseif ($page === 'admin_properties'):
    Auth::requireAdmin();
    $edit = !empty($_GET['id']) ? run_query('SELECT * FROM properties WHERE id = ?', [(int) $_GET['id']])->fetch() : null;
    $properties = run_query('SELECT * FROM properties ORDER BY created_at DESC')->fetchAll();
    $adminFields = [
        'Core Data' => [
            'township' => 'Township',
            'area' => 'Area',
            'property_name' => 'Property Name',
            'property_type' => 'Legacy Property Type',
            'location' => 'Legacy Location',
            'state' => 'State',
            'tenure' => 'Tenure',
            'type' => 'Type',
            'price' => 'Legacy Price',
            'image' => 'Image Path',
        ],
        'Metrics & Financials' => [
            'median_price' => 'Median Price',
            'median_psf' => 'Median PSF',
            'estimated_rental_yield_pct' => 'Rental Yield %',
            'historical_capital_appreciation_3yr_pct' => '3Yr Appreciation %',
            'est_monthly_mortgage_rm' => 'Monthly Mortgage RM',
            'transactions' => 'Transactions',
        ],
        'Risk & Proximity' => [
            'safety_score' => 'Safety Score',
            'crime_risk' => 'Crime Risk',
            'flood_risk' => 'Flood Risk',
            'distance_to_public_transport_km' => 'Public Transport KM',
            'distance_to_mall_km' => 'Mall KM',
            'distance_to_school_km' => 'School KM',
            'distance_to_hospital_km' => 'Hospital KM',
        ],
        'Specs & AI Scores' => [
            'bedrooms' => 'Bedrooms',
            'bathrooms' => 'Bathrooms',
            'built_up_sqft' => 'Legacy Built-up SQFT',
            'house_size_sqft' => 'House Size SQFT',
            'smart_readiness_score' => 'Smart Score',
            'security_score' => 'Security Score',
            'sustainability_score' => 'Sustainability Score',
            'family_score' => 'Family Score',
            'acoustic_score' => 'Acoustic Score',
        ],
    ];
    $numberFields = [
        'price', 'median_price', 'median_psf', 'estimated_rental_yield_pct',
        'historical_capital_appreciation_3yr_pct', 'est_monthly_mortgage_rm', 'transactions',
        'safety_score', 'distance_to_public_transport_km', 'distance_to_mall_km',
        'distance_to_school_km', 'distance_to_hospital_km', 'bedrooms', 'bathrooms',
        'built_up_sqft', 'house_size_sqft', 'smart_readiness_score', 'security_score',
        'sustainability_score', 'family_score', 'acoustic_score',
    ];
    ?>
    <section class="container py-5">
        <p class="eyebrow">Admin</p><h1 class="fw-bold text-sage mb-4">Property management</h1>
        <div class="system-card p-4 mb-4">
            <h2 class="h5 fw-bold text-ink"><?= $edit ? 'Edit property' : 'Add property' ?></h2>
            <form method="post" class="row g-3">
                <?= Csrf::field() ?><input type="hidden" name="action" value="admin_property_save"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
                <?php foreach ($adminFields as $group => $fields): ?>
                    <div class="col-12"><h3 class="h6 fw-bold text-ink mt-3 mb-0"><?= e($group) ?></h3></div>
                    <?php foreach ($fields as $name => $label): ?>
                        <div class="col-md-3">
                            <label class="form-label"><?= e($label) ?></label>
                            <input class="form-control" name="<?= e($name) ?>" type="<?= in_array($name, $numberFields, true) ? 'number' : 'text' ?>" step="<?= in_array($name, $numberFields, true) ? '0.01' : '' ?>" value="<?= e((string) ($edit[$name] ?? '')) ?>">
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3"><?= e($edit['description'] ?? '') ?></textarea></div>
                <div class="col-12"><button class="btn btn-sage">Save Property</button></div>
            </form>
        </div>
        <div class="table-responsive system-card p-0"><table class="table align-middle mb-0"><thead><tr><th>Property</th><th>Location</th><th>Price</th><th>Scores</th><th></th></tr></thead><tbody>
            <?php foreach ($properties as $property): ?><tr><td><?= e(property_text($property, 'property_name', 'township')) ?><br><small class="text-muted"><?= e(property_text($property, 'type', 'property_type')) ?></small></td><td><?= e(property_text($property, 'area', 'location')) ?></td><td><?= money(property_number($property, 'median_price', 'price')) ?></td><td><?= (int) property_number($property, 'smart_readiness_score') ?> smart / <?= (int) property_number($property, 'security_score') ?> security</td><td class="text-end"><a class="btn btn-outline-sage btn-sm" href="<?= route('admin_properties', ['id' => $property['id']]) ?>">Edit</a><form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="action" value="admin_property_delete"><input type="hidden" name="id" value="<?= (int) $property['id'] ?>"><button class="btn btn-outline-danger btn-sm" onclick="return confirm('Delete this property?')">Delete</button></form></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>

<?php elseif ($page === 'admin_reports'):
    Auth::requireAdmin();
    $rows = run_query('SELECT a.*, u.full_name, COUNT(r.id) recs, MAX(r.match_percentage) best FROM assessments a JOIN users u ON u.id = a.user_id LEFT JOIN recommendations r ON r.assessment_id = a.id GROUP BY a.id ORDER BY a.created_at DESC')->fetchAll(); ?>
    <section class="container py-5"><div class="d-flex justify-content-between flex-wrap gap-3 mb-4"><div><p class="eyebrow">Reports</p><h1 class="fw-bold text-sage">Assessment records</h1></div><a class="btn btn-sage" href="<?= route('export_reports') ?>">Export CSV</a></div><div class="table-responsive system-card p-0"><table class="table align-middle mb-0"><thead><tr><th>User</th><th>Budget</th><th>Type</th><th>Location</th><th>Best Match</th><th>Date</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?= e($row['full_name']) ?></td><td><?= money((float) $row['budget']) ?></td><td><?= e($row['property_type']) ?></td><td><?= e($row['preferred_location']) ?></td><td><?= number_format((float) $row['best'], 1) ?>%</td><td><?= e($row['created_at']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php else:
    redirect(Auth::check() ? 'dashboard' : 'landing');
endif;

render_footer();

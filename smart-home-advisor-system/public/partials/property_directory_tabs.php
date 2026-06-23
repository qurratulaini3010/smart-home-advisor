<?php
declare(strict_types=1);

Auth::requireLogin();
$csrfToken = Csrf::token();
$lastAssessment = run_query(
    'SELECT budget FROM assessments WHERE user_id = ? ORDER BY created_at DESC LIMIT 1',
    [Auth::user()['id']]
)->fetch();
$lastBudget = (float) ($lastAssessment['budget'] ?? 0);
?>
<section class="container py-5 property-directory-shell" data-csrf-token="<?= e($csrfToken) ?>" data-user-budget="<?= e((string) $lastBudget) ?>">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
        <div>
            <p class="eyebrow">Property discovery</p>
            <h1 class="fw-bold text-sage mb-1">Find a smart home that fits your life.</h1>
            <p class="text-muted mb-0">Search the full property dataset to shortlist the best matches.</p>
        </div>
        <a class="btn btn-outline-sage align-self-start" href="<?= route('dashboard') ?>">Back to Dashboard</a>
    </div>

    <div class="system-card p-4 mb-4">
        <form id="propertySearchForm" class="row g-3 align-items-end">
            <div class="col-md-4 col-xl-3">
                <label class="form-label">Township or Area</label>
                <input class="form-control" name="township_area" placeholder="Kulai, Shah Alam, Penang">
            </div>
            <div class="col-md-4 col-xl-2">
                <label class="form-label">Property Type</label>
                <select class="form-select" name="property_type">
                    <option value="">Any type</option>
                    <option>Condominium</option>
                    <option>Apartment</option>
                    <option>Terrace</option>
                    <option>Terrace House</option>
                    <option>Semi-D</option>
                    <option>Cluster House</option>
                </select>
            </div>
            <div class="col-md-4 col-xl-2">
                <label class="form-label">Min Price</label>
                <input class="form-control" type="number" min="0" name="min_price" placeholder="250000">
            </div>
            <div class="col-md-4 col-xl-2">
                <label class="form-label">Max Price</label>
                <input class="form-control" type="number" min="0" name="max_price" placeholder="750000">
            </div>
            <div class="col-md-4 col-xl-1">
                <label class="form-label">Beds</label>
                <input class="form-control" type="number" min="0" name="bedrooms" placeholder="3">
            </div>
            <div class="col-md-4 col-xl-1">
                <label class="form-label">Smart</label>
                <input class="form-control" type="number" min="0" max="100" name="min_smart_score" placeholder="75">
            </div>
            <div class="col-md-4 col-xl-1">
                <label class="form-label">Eco</label>
                <input class="form-control" type="number" min="0" max="100" name="min_sustainability_score" placeholder="75">
            </div>
        </form>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 fw-bold text-ink mb-0">Property Directory</h2>
        <span class="text-muted small" id="directoryCount">Loading...</span>
    </div>
    <div class="row g-4" id="propertyDirectoryGrid"></div>
</section>

<div class="modal fade" id="propertyDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content property-detail-modal">
            <div class="modal-header border-0">
                <div>
                    <p class="eyebrow mb-1">Property details</p>
                    <h2 class="modal-title h4 fw-bold text-ink" id="propertyDetailTitle">Property</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="propertyDetailBody"></div>
        </div>
    </div>
</div>

<script src="assets/js/property-advisor.js"></script>

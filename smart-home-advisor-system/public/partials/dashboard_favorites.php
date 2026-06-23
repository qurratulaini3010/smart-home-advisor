<?php
declare(strict_types=1);

$favoritePreview = (new PropertyDirectoryRepository(Database::connect()))
    ->favoritesForUser((int) Auth::user()['id'], 4);
?>
<div class="row g-4 mt-1" id="favorited-properties">
    <div class="col-12">
        <div class="system-card p-4">
            <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-3">
                <div>
                    <p class="eyebrow">My Favorited Properties</p>
                    <h2 class="h5 fw-bold text-ink mb-0">Homes you saved from the advisor.</h2>
                </div>
                <a class="btn btn-outline-sage align-self-start" href="<?= route('property_directory') ?>">Continue Browsing</a>
            </div>

            <?php if (!$favoritePreview): ?>
                <p class="text-muted mb-0">No saved properties yet. Use the guided advisor or browse the directory to start a shortlist.</p>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($favoritePreview as $property): ?>
                        <div class="col-md-6 col-xl-3">
                            <article class="favorite-mini-card h-100">
                                <strong><?= e(property_text($property, 'property_name', 'township')) ?></strong>
                                <span><?= e(property_text($property, 'area', 'location')) ?>, <?= e(property_text($property, 'state')) ?></span>
                                <div class="mini-scores my-2">
                                    <span><?= (int) $property['bedrooms'] ?> bed</span>
                                    <span><?= (int) $property['smart_readiness_score'] ?> smart</span>
                                </div>
                                <p class="fw-bold text-sage mb-0"><?= money(property_number($property, 'median_price', 'price')) ?></p>
                            </article>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

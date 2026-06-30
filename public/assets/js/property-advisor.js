(function () {
  const shell = document.querySelector(".property-directory-shell");
  if (!shell) {
    return;
  }

  const csrfToken = shell.dataset.csrfToken;
  const currency = new Intl.NumberFormat("en-MY", {
    style: "currency",
    currency: "MYR",
    maximumFractionDigits: 0
  });
  const userBudget = Number(shell.dataset.userBudget || 0);

  let advisorStep = 1;

  function escapeHtml(value) {
    return String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function money(value) {
    return value === null || value === "" ? "N/A" : currency.format(Number(value));
  }

  function firstValue(...values) {
    return values.find((value) => value !== undefined && value !== null && String(value).trim() !== "") ?? "";
  }

  function pct(value) {
    return `${Number(value || 0).toFixed(2)}%`;
  }

  function km(value) {
    return `${Number(value || 0).toFixed(1)} km`;
  }

  function collectForm(form) {
    const params = new URLSearchParams();
    new FormData(form).forEach((value, key) => {
      if (String(value).trim() !== "") {
        params.set(key, String(value).trim());
      }
    });
    return params;
  }

  async function getJson(url) {
    const response = await fetch(url, {
      headers: { Accept: "application/json" },
      credentials: "same-origin"
    });
    const payload = await response.json();
    if (!response.ok || !payload.success) {
      throw new Error(payload.message || "Request failed.");
    }
    return payload;
  }

  function propertyCard(property, advisor = false) {
    const title = firstValue(property.property_name, property.township, "Property");
    const area = firstValue(property.area, property.location);
    const propertyType = firstValue(property.type, property.property_type);
    const propertyPrice = firstValue(property.median_price, property.price);
    const match = advisor && property.advisor_match_score
      ? `<div class="match-chip">${Number(property.advisor_match_score).toFixed(0)}% match</div>`
      : "";

    return `
      <div class="col-md-6 col-xl-4">
        <article class="property-card directory-card h-100">
          ${match}
          <p class="eyebrow mb-2">${escapeHtml(area)}, ${escapeHtml(property.state)}</p>
          <h3 class="h5 fw-bold text-ink">${escapeHtml(title)}</h3>
          <p class="text-muted small">${escapeHtml(propertyType)} | ${escapeHtml(property.tenure || "Tenure N/A")}</p>
          <p class="fs-5 fw-bold text-sage mb-2">${money(propertyPrice)}</p>
          <div class="mini-scores mb-3">
            <span>${escapeHtml(property.bedrooms)} bed</span>
            <span>${escapeHtml(property.bathrooms)} bath</span>
            <span>${escapeHtml(property.smart_readiness_score)} smart</span>
            <span>${escapeHtml(property.sustainability_score)} eco</span>
          </div>
          <div class="proximity-strip mb-3">
            <span>School ${km(property.distance_to_school_km)}</span>
            <span>Mall ${km(property.distance_to_mall_km)}</span>
            <span>Transit ${km(property.distance_to_public_transport_km)}</span>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-outline-sage btn-sm" type="button" data-view-property="${property.id}">View Details</button>
            ${advisor ? `<button class="btn btn-sage btn-sm love-button" type="button" data-favorite-property="${property.id}">Love It <span aria-hidden="true">❤️</span></button>` : ""}
          </div>
        </article>
      </div>
    `;
  }

  async function loadRuleLabels(propertyId, budget = 0) {
    try {
      const payload = await getJson(`api/rules.php?property_id=${encodeURIComponent(propertyId)}&budget=${encodeURIComponent(budget)}`);
      const allFired = [...(payload.rules_fired || []), ...(payload.warnings || [])];
      if (!allFired.length) {
        return "";
      }

      return allFired.map((rule) => {
        const icon = rule.severity === "warning" ? "&#9888;" : rule.severity === "info" ? "&#128161;" : "&#10003;";
        return `<span class="pill pill-${escapeHtml(rule.severity)}">${icon} ${escapeHtml(rule.label)}</span>`;
      }).join("");
    } catch {
      return "";
    }
  }

  async function loadDirectory() {
    const form = document.getElementById("propertySearchForm");
    const grid = document.getElementById("propertyDirectoryGrid");
    const count = document.getElementById("directoryCount");
    if (!form || !grid) {
      return;
    }

    grid.innerHTML = '<div class="col-12 text-muted">Loading properties...</div>';
    const params = collectForm(form);
    params.set("action", "search");

    try {
      const payload = await getJson(`api/property-directory.php?${params.toString()}`);
      count.textContent = `${payload.count} properties`;
      grid.innerHTML = payload.data.length
        ? payload.data.map((property) => propertyCard(property)).join("")
        : '<div class="col-12 text-muted">No properties match the selected filters.</div>';
    } catch (error) {
      grid.innerHTML = `<div class="col-12 text-danger">${escapeHtml(error.message)}</div>`;
    }
  }

  function detailMetrics(property) {
    const propertyType = firstValue(property.type, property.property_type);
    const propertyLocation = firstValue(property.area, property.location);
    const propertyPrice = firstValue(property.median_price, property.price);
    const propertySize = firstValue(property.house_size_sqft, property.built_up_sqft);
    const groups = [
      ["Core Data", [
        ["Township", property.township],
        ["Area", property.area],
        ["Property Name", property.property_name],
        ["Property Type", propertyType],
        ["Location", propertyLocation],
        ["State", property.state],
        ["Tenure", property.tenure],
        ["Type", property.type],
        ["Price", money(propertyPrice)],
      ]],
      ["Metrics & Financials", [
        ["Median Price", money(property.median_price)],
        ["Median PSF", money(property.median_psf)],
        ["Rental Yield", pct(property.estimated_rental_yield_pct)],
        ["3Yr Appreciation", pct(property.historical_capital_appreciation_3yr_pct)],
        ["Monthly Mortgage", money(property.est_monthly_mortgage_rm)],
        ["Transactions", property.transactions],
      ]],
      ["Risk & Proximity", [
        ["Safety Score", property.safety_score],
        ["Crime Risk", property.crime_risk],
        ["Flood Risk", property.flood_risk],
        ["Public Transport", km(property.distance_to_public_transport_km)],
        ["Mall", km(property.distance_to_mall_km)],
        ["School", km(property.distance_to_school_km)],
        ["Hospital", km(property.distance_to_hospital_km)],
      ]],
      ["Specs", [
        ["Bedrooms", property.bedrooms],
        ["Bathrooms", property.bathrooms],
        ["Built-up SQFT", firstValue(property.built_up_sqft, property.house_size_sqft)],
        ["House Size SQFT", propertySize],
      ]],
      ["Custom AI Scores", [
        ["Smart Readiness", property.smart_readiness_score],
        ["Security", property.security_score],
        ["Sustainability", property.sustainability_score],
        ["Family", property.family_score],
        ["Acoustic", property.acoustic_score],
      ]],
    ];

    return groups.map(([title, rows]) => `
      <div class="col-lg-6">
        <div class="detail-group h-100">
          <h3>${escapeHtml(title)}</h3>
          ${rows.map(([label, value]) => `
            <div class="detail-line">
              <span>${escapeHtml(label)}</span>
              <strong>${escapeHtml(value ?? "N/A")}</strong>
            </div>
          `).join("")}
        </div>
      </div>
    `).join("");
  }

  async function showPropertyDetails(propertyId) {
    const payload = await getJson(`api/property-directory.php?action=details&id=${encodeURIComponent(propertyId)}`);
    const property = payload.data;
    const ruleLabels = await loadRuleLabels(property.id, userBudget);
    document.getElementById("propertyDetailTitle").textContent = property.property_name || property.township || "Property";
    document.getElementById("propertyDetailBody").innerHTML = `
      <p class="lead text-muted">${escapeHtml(property.description || "No description provided.")}</p>
      ${ruleLabels ? `<div class="rules-labels d-flex flex-wrap gap-2 mt-3 mb-3">${ruleLabels}</div>` : ""}
      <div class="row g-3">${detailMetrics(property)}</div>
    `;
    bootstrap.Modal.getOrCreateInstance(document.getElementById("propertyDetailModal")).show();
  }

  function updateAdvisorStep() {
    document.querySelectorAll("[data-advisor-step]").forEach((step) => {
      step.classList.toggle("active", Number(step.dataset.advisorStep) === advisorStep);
    });
    document.querySelectorAll("[data-step-indicator]").forEach((step) => {
      step.classList.toggle("active", Number(step.dataset.stepIndicator) <= advisorStep);
    });
    document.getElementById("advisorBack").disabled = advisorStep === 1;
    document.getElementById("advisorNext").textContent = advisorStep === 2 ? "Show Matches" : advisorStep === 3 ? "Start Over" : "Next";
  }

  async function loadAdvisorResults() {
    const results = document.getElementById("advisorResults");
    const form = document.getElementById("advisorWizardForm");
    results.innerHTML = '<div class="col-12 text-muted">Finding your best matches...</div>';
    const params = collectForm(form);
    params.set("action", "recommend");

    try {
      const payload = await getJson(`api/property-directory.php?${params.toString()}`);
      results.innerHTML = payload.data.length
        ? payload.data.map((property) => propertyCard(property, true)).join("")
        : '<div class="col-12 text-muted">No advisor matches yet. Try widening the budget or area.</div>';
    } catch (error) {
      results.innerHTML = `<div class="col-12 text-danger">${escapeHtml(error.message)}</div>`;
    }
  }

  async function favoriteProperty(propertyId) {
    const body = new URLSearchParams();
    body.set("action", "favorite");
    body.set("property_id", propertyId);
    body.set("csrf_token", csrfToken);

    const response = await fetch("api/property-directory.php", {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/x-www-form-urlencoded"
      },
      credentials: "same-origin",
      body: body.toString()
    });
    const payload = await response.json();
    if (!response.ok || !payload.success) {
      throw new Error(payload.message || "Unable to save favorite.");
    }

    const message = document.getElementById("favoriteSuccess");
    message.classList.remove("d-none");
    message.classList.add("pop");
    setTimeout(() => {
      window.location.href = payload.redirect || "index.php?page=dashboard";
    }, 1100);
  }

  document.getElementById("propertySearchForm")?.addEventListener("input", () => {
    window.clearTimeout(window.propertySearchTimer);
    window.propertySearchTimer = window.setTimeout(loadDirectory, 250);
  });

  document.getElementById("advisorNext")?.addEventListener("click", async () => {
    if (advisorStep === 1) {
      advisorStep = 2;
    } else if (advisorStep === 2) {
      advisorStep = 3;
      updateAdvisorStep();
      await loadAdvisorResults();
      return;
    } else {
      advisorStep = 1;
      document.getElementById("favoriteSuccess").classList.add("d-none");
      document.getElementById("advisorResults").innerHTML = "";
    }
    updateAdvisorStep();
  });

  document.getElementById("advisorBack")?.addEventListener("click", () => {
    advisorStep = Math.max(1, advisorStep - 1);
    updateAdvisorStep();
  });

  document.addEventListener("input", (event) => {
    const range = event.target.closest(".advisor-range");
    if (!range) {
      return;
    }
    const output = document.querySelector(`[data-range-value="${range.name}"]`);
    if (output) {
      output.textContent = range.value;
    }
  });

  document.addEventListener("click", async (event) => {
    const detailsButton = event.target.closest("[data-view-property]");
    if (detailsButton) {
      await showPropertyDetails(detailsButton.dataset.viewProperty);
      return;
    }

    const favoriteButton = event.target.closest("[data-favorite-property]");
    if (favoriteButton) {
      favoriteButton.disabled = true;
      favoriteButton.textContent = "Saving...";
      try {
        await favoriteProperty(favoriteButton.dataset.favoriteProperty);
      } catch (error) {
        favoriteButton.disabled = false;
        favoriteButton.textContent = "Love It ❤️";
        alert(error.message);
      }
    }
  });

  updateAdvisorStep();
  loadDirectory();
})();

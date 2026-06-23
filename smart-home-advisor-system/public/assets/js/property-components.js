(function () {
  const money = new Intl.NumberFormat("en-MY", {
    style: "currency",
    currency: "MYR",
    maximumFractionDigits: 0
  });

  function escapeHtml(value) {
    return String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function formatMoney(value) {
    return value === null || value === "" ? "N/A" : money.format(Number(value));
  }

  function formatKm(value) {
    return `${Number(value || 0).toFixed(1)} km`;
  }

  function formatPct(value) {
    return `${Number(value || 0).toFixed(2)}%`;
  }

  function firstValue(...values) {
    return values.find((value) => value !== undefined && value !== null && String(value).trim() !== "") ?? "";
  }

  function queryFromForm(target) {
    const form = document.querySelector(`[data-property-filter-form][data-property-target="${target}"]`);
    const params = new URLSearchParams();

    if (!form) {
      return params;
    }

    new FormData(form).forEach((value, key) => {
      if (String(value).trim() !== "") {
        params.set(key, String(value).trim());
      }
    });

    return params;
  }

  async function fetchProperties(view) {
    const params = queryFromForm(view);
    params.set("view", view);
    const response = await fetch(`api/properties.php?${params.toString()}`, {
      headers: { Accept: "application/json" },
      credentials: "same-origin"
    });
    const payload = await response.json();

    if (!response.ok || !payload.success) {
      throw new Error(payload.message || "Unable to load property data.");
    }

    return payload.data;
  }

  function renderStaffTable(rows) {
    const target = document.querySelector("[data-property-staff-table]");
    if (!target) {
      return;
    }

    if (rows.length === 0) {
      target.innerHTML = '<tr><td colspan="10" class="text-muted p-4">No properties match the current filters.</td></tr>';
      return;
    }

    target.innerHTML = rows.map((property) => `
      <tr>
        <td><strong class="text-ink">${escapeHtml(firstValue(property.township, property.property_name))}</strong><br><small>${escapeHtml(property.tenure)}</small></td>
        <td>${escapeHtml(firstValue(property.area, property.location))}, ${escapeHtml(property.state)}</td>
        <td>${escapeHtml(firstValue(property.type, property.property_type))}<br><small>${escapeHtml(property.bedrooms)} bed / ${escapeHtml(property.bathrooms)} bath</small></td>
        <td>${formatMoney(firstValue(property.median_price, property.price))}<br><small>${formatMoney(property.median_psf)} psf</small></td>
        <td>${formatPct(property.estimated_rental_yield_pct)}</td>
        <td>${formatPct(property.historical_capital_appreciation_3yr_pct)}</td>
        <td>${escapeHtml(property.transactions)}</td>
        <td>${Number(property.safety_score || 0).toFixed(0)}/100</td>
        <td><span class="pill">${escapeHtml(property.crime_risk)} crime</span><br><span class="pill mt-1">${escapeHtml(property.flood_risk)} flood</span></td>
        <td>
          Transit ${formatKm(property.distance_to_public_transport_km)}<br>
          Mall ${formatKm(property.distance_to_mall_km)}<br>
          School ${formatKm(property.distance_to_school_km)}
        </td>
      </tr>
    `).join("");
  }

  function renderCustomerCards(rows) {
    const target = document.querySelector("[data-property-customer-cards]");
    if (!target) {
      return;
    }

    if (rows.length === 0) {
      target.innerHTML = '<div class="col-12 text-muted">No properties match the current filters.</div>';
      return;
    }

    target.innerHTML = rows.map((property) => `
      <div class="col-md-6 col-xl-4">
        <article class="property-card h-100">
          <p class="eyebrow mb-2">${escapeHtml(firstValue(property.area, property.location))}, ${escapeHtml(property.state)}</p>
          <h2 class="h5 fw-bold text-ink">${escapeHtml(firstValue(property.township, property.property_name))}</h2>
          <p class="text-muted small mb-3">${escapeHtml(firstValue(property.type, property.property_type))} | ${escapeHtml(property.tenure)} | ${escapeHtml(firstValue(property.house_size_sqft, property.built_up_sqft))} sqft</p>

          <div class="metric-card mb-3">
            <span>Median Price</span>
            <strong>${formatMoney(firstValue(property.median_price, property.price))}</strong>
          </div>

          <div class="mini-scores mb-3">
            <span>${escapeHtml(property.bedrooms)} bed</span>
            <span>${escapeHtml(property.bathrooms)} bath</span>
            <span>${formatPct(property.estimated_rental_yield_pct)} yield</span>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6"><div class="metric"><span>School</span><strong>${formatKm(property.distance_to_school_km)}</strong></div></div>
            <div class="col-6"><div class="metric"><span>Mall</span><strong>${formatKm(property.distance_to_mall_km)}</strong></div></div>
            <div class="col-6"><div class="metric"><span>Transit</span><strong>${formatKm(property.distance_to_public_transport_km)}</strong></div></div>
            <div class="col-6"><div class="metric"><span>Hospital</span><strong>${formatKm(property.distance_to_hospital_km)}</strong></div></div>
          </div>

          <p class="fw-bold text-sage mb-0">Est. mortgage: ${formatMoney(property.est_monthly_mortgage_rm)}</p>
        </article>
      </div>
    `).join("");
  }

  async function loadStaff() {
    const target = document.querySelector("[data-property-staff-table]");
    if (!target) {
      return;
    }

    try {
      renderStaffTable(await fetchProperties("staff"));
    } catch (error) {
      target.innerHTML = `<tr><td colspan="10" class="text-danger p-4">${escapeHtml(error.message)}</td></tr>`;
    }
  }

  async function loadCustomer() {
    const target = document.querySelector("[data-property-customer-cards]");
    if (!target) {
      return;
    }

    try {
      renderCustomerCards(await fetchProperties("customer"));
    } catch (error) {
      target.innerHTML = `<div class="col-12 text-danger">${escapeHtml(error.message)}</div>`;
    }
  }

  document.addEventListener("submit", (event) => {
    const form = event.target.closest("[data-property-filter-form]");
    if (!form) {
      return;
    }

    event.preventDefault();
    if (form.dataset.propertyTarget === "staff") {
      loadStaff();
    } else {
      loadCustomer();
    }
  });

  document.addEventListener("DOMContentLoaded", () => {
    loadStaff();
    loadCustomer();
  });
})();

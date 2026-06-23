function calculateMortgage() {
  const price = Number(document.getElementById("calcPrice")?.value || 0);
  const downPercent = Number(document.getElementById("calcDown")?.value || 0);
  const rate = Number(document.getElementById("calcRate")?.value || 0) / 100 / 12;
  const years = Number(document.getElementById("calcYears")?.value || 0);
  const months = years * 12;
  const principal = price * (1 - downPercent / 100);
  let payment = 0;

  if (principal > 0 && months > 0) {
    payment = rate > 0
      ? principal * (rate * Math.pow(1 + rate, months)) / (Math.pow(1 + rate, months) - 1)
      : principal / months;
  }

  const output = document.getElementById("mortgageResult");
  if (output) {
    output.textContent = new Intl.NumberFormat("en-MY", {
      style: "currency",
      currency: "MYR",
      maximumFractionDigits: 0
    }).format(payment);
  }
}

document.addEventListener("DOMContentLoaded", () => {
  if (document.getElementById("mortgageResult")) {
    calculateMortgage();
  }

  const assessmentShell = document.getElementById("assessmentAdvisorShell");
  if (!assessmentShell) {
    return;
  }

  const currency = new Intl.NumberFormat("en-MY", {
    style: "currency",
    currency: "MYR",
    maximumFractionDigits: 0
  });
  const finalForm = document.getElementById("assessmentFinalForm");
  const nextButton = document.getElementById("assessmentNext");
  const backButton = document.getElementById("assessmentBack");
  const previewResults = document.getElementById("assessmentPreviewResults");
  let assessmentStep = 1;
  let previewTimer;

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

  function collectAssessmentData() {
    const formData = new FormData();
    assessmentShell.querySelectorAll("[data-assessment-field]").forEach((field) => {
      if (!field.name) {
        return;
      }
      if (field.type === "checkbox") {
        if (field.checked) {
          formData.set(field.name, field.value || "1");
        }
        return;
      }
      formData.set(field.name, field.value);
    });
    return formData;
  }

  function slidersToAssessmentFields(formData) {
    const smart = Number(formData.get("smart_priority_slider") ?? 50);
    const security = Number(formData.get("security_priority_slider") ?? 50);
    const family = Number(formData.get("family_priority_slider") ?? 70);
    const quiet = Number(formData.get("quiet_priority_slider") ?? 50);

    formData.set("smart_lighting", smart >= 50 ? "1" : "0");
    formData.set("smart_appliances", smart >= 50 ? "1" : "0");
    formData.set("smart_energy", smart >= 50 ? "1" : "0");
    formData.set("smart_security", security >= 50 ? "1" : "0");

    if (family >= 60) formData.set("comfort_priority", "Family growth");
    else if (quiet >= 60) formData.set("comfort_priority", "Acoustic comfort");
    else formData.set("comfort_priority", "Energy efficiency");

    return formData;
  }

  function validateCurrentStep() {
    const current = assessmentShell.querySelector(`[data-assessment-step="${assessmentStep}"]`);
    const fields = current ? Array.from(current.querySelectorAll("input, select, textarea")) : [];
    for (const field of fields) {
      if (!field.checkValidity()) {
        field.reportValidity();
        return false;
      }
    }
    return true;
  }

  function updateAssessmentStep() {
    assessmentShell.querySelectorAll("[data-assessment-step]").forEach((step) => {
      step.classList.toggle("active", Number(step.dataset.assessmentStep) === assessmentStep);
    });
    assessmentShell.querySelectorAll("[data-assessment-step-indicator]").forEach((indicator) => {
      indicator.classList.toggle("active", Number(indicator.dataset.assessmentStepIndicator) <= assessmentStep);
    });
    backButton.disabled = assessmentStep === 1;
    nextButton.textContent = assessmentStep === 4 ? "Save & Get Full Results" : "Next";

    if (assessmentStep === 4) {
      loadAssessmentPreview();
    }
  }

  function previewCard(property) {
    const area = firstValue(property.township, property.area, property.location, "Area N/A");
    const type = firstValue(property.type, property.property_type, "Property");
    const price = firstValue(property.median_price, property.price);
    const match = Number(property.advisor_match_score || 0).toFixed(0);

    return `
      <div class="col-md-6 col-xl-4">
        <article class="property-card directory-card h-100">
          <div class="match-chip">${match}% match</div>
          <p class="eyebrow mb-2">${escapeHtml(area)}</p>
          <h3 class="h5 fw-bold text-ink">${escapeHtml(firstValue(property.property_name, property.township, "Property"))}</h3>
          <p class="text-muted small">${escapeHtml(type)} | ${escapeHtml(property.tenure || "Tenure N/A")}</p>
          <p class="fs-5 fw-bold text-sage mb-2">${money(price)}</p>
          <div class="mini-scores">
            <span>${escapeHtml(property.bedrooms || "N/A")} bed</span>
            <span>${escapeHtml(property.smart_readiness_score || 0)} smart</span>
            <span>${escapeHtml(property.sustainability_score || 0)} eco</span>
          </div>
        </article>
      </div>
    `;
  }

  async function loadAssessmentPreview() {
    const formData = collectAssessmentData();
    const params = new URLSearchParams();
    params.set("action", "recommend");
    params.set("areas", String(formData.get("preferred_location") || ""));
    params.set("max_budget", String(formData.get("budget") || ""));
    params.set("bedrooms", String(formData.get("bedrooms") || ""));
    params.set("smart_priority", String(formData.get("smart_priority_slider") || 50));
    params.set("security_priority", String(formData.get("security_priority_slider") || 50));
    params.set("sustainability_priority", String(formData.get("sustainability_priority_slider") || 50));
    params.set("family_priority", String(formData.get("family_priority_slider") || 70));
    params.set("quiet_priority", String(formData.get("quiet_priority_slider") || 50));

    if (formData.get("property_type") && formData.get("property_type") !== "Any") {
      params.set("property_type", String(formData.get("property_type")));
    }
    if (formData.get("tenure_preference") && formData.get("tenure_preference") !== "Any") {
      params.set("tenure_preference", String(formData.get("tenure_preference")));
    }
    if (formData.get("near_school")) {
      params.set("near_school", "1");
    }
    if (formData.get("low_flood_risk")) {
      params.set("low_flood_risk", "1");
    }

    previewResults.innerHTML = '<div class="col-12 text-muted">Finding your preview matches...</div>';
    try {
      const response = await fetch(`api/property-directory.php?${params.toString()}`, {
        headers: { Accept: "application/json" },
        credentials: "same-origin"
      });
      const payload = await response.json();
      if (!response.ok || !payload.success) {
        throw new Error(payload.message || "Unable to load preview matches.");
      }
      previewResults.innerHTML = payload.data.length
        ? payload.data.slice(0, 6).map(previewCard).join("")
        : '<div class="col-12 text-muted">No preview matches yet. Try widening the area, budget, or filters.</div>';
    } catch (error) {
      previewResults.innerHTML = `<div class="col-12 text-danger">${escapeHtml(error.message)}</div>`;
    }
  }

  function copyAssessmentToFinalForm() {
    const formData = slidersToAssessmentFields(collectAssessmentData());
    const storedFields = [
      "age",
      "monthly_income",
      "budget",
      "household_size",
      "preferred_location",
      "property_type",
      "smart_lighting",
      "smart_security",
      "smart_appliances",
      "smart_energy",
      "comfort_priority"
    ];

    storedFields.forEach((name) => {
      let input = finalForm.querySelector(`[name="${name}"]`);
      if (!input) {
        input = document.createElement("input");
        input.type = "hidden";
        input.name = name;
        finalForm.appendChild(input);
      }
      input.value = String(formData.get(name) || "");
    });
  }

  nextButton.addEventListener("click", () => {
    if (assessmentStep < 4) {
      if (!validateCurrentStep()) {
        return;
      }
      assessmentStep += 1;
      updateAssessmentStep();
      return;
    }

    if (!validateCurrentStep()) {
      return;
    }
    copyAssessmentToFinalForm();
    nextButton.disabled = true;
    nextButton.textContent = "Saving...";
    finalForm.submit();
  });

  backButton.addEventListener("click", () => {
    assessmentStep = Math.max(1, assessmentStep - 1);
    updateAssessmentStep();
  });

  assessmentShell.addEventListener("input", (event) => {
    const range = event.target.closest(".advisor-range");
    if (range) {
      const output = assessmentShell.querySelector(`[data-range-value="${range.name}"]`);
      if (output) {
        output.textContent = range.value;
      }
    }

    if (assessmentStep === 4) {
      window.clearTimeout(previewTimer);
      previewTimer = window.setTimeout(loadAssessmentPreview, 250);
    }
  });

  updateAssessmentStep();
});

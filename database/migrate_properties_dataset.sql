USE smart_home_advisor;

ALTER TABLE properties
  ADD COLUMN IF NOT EXISTS township VARCHAR(150) NULL AFTER id,
  ADD COLUMN IF NOT EXISTS area VARCHAR(150) NULL AFTER township,
  ADD COLUMN IF NOT EXISTS tenure VARCHAR(80) NULL AFTER state,
  ADD COLUMN IF NOT EXISTS type VARCHAR(150) NULL AFTER tenure,
  ADD COLUMN IF NOT EXISTS house_size_sqft INT UNSIGNED NULL AFTER built_up_sqft,
  ADD COLUMN IF NOT EXISTS median_price DECIMAL(12,2) NULL AFTER price,
  ADD COLUMN IF NOT EXISTS median_psf DECIMAL(10,2) NULL AFTER median_price,
  ADD COLUMN IF NOT EXISTS estimated_rental_yield_pct DECIMAL(5,2) NULL AFTER median_psf,
  ADD COLUMN IF NOT EXISTS historical_capital_appreciation_3yr_pct DECIMAL(5,2) NULL AFTER estimated_rental_yield_pct,
  ADD COLUMN IF NOT EXISTS est_monthly_mortgage_rm DECIMAL(12,2) NULL AFTER historical_capital_appreciation_3yr_pct,
  ADD COLUMN IF NOT EXISTS transactions INT UNSIGNED NULL AFTER est_monthly_mortgage_rm,
  ADD COLUMN IF NOT EXISTS safety_score DECIMAL(5,2) NULL AFTER transactions,
  ADD COLUMN IF NOT EXISTS crime_risk VARCHAR(50) NULL AFTER safety_score,
  ADD COLUMN IF NOT EXISTS flood_risk VARCHAR(50) NULL AFTER crime_risk,
  ADD COLUMN IF NOT EXISTS distance_to_public_transport_km DECIMAL(6,2) NULL AFTER flood_risk,
  ADD COLUMN IF NOT EXISTS distance_to_mall_km DECIMAL(6,2) NULL AFTER distance_to_public_transport_km,
  ADD COLUMN IF NOT EXISTS distance_to_school_km DECIMAL(6,2) NULL AFTER distance_to_mall_km,
  ADD COLUMN IF NOT EXISTS distance_to_hospital_km DECIMAL(6,2) NULL AFTER distance_to_school_km;

UPDATE properties
SET
  township = COALESCE(township, property_name),
  area = COALESCE(area, location),
  type = COALESCE(type, property_type),
  house_size_sqft = COALESCE(house_size_sqft, built_up_sqft),
  median_price = COALESCE(median_price, price),
  median_psf = COALESCE(median_psf, CASE WHEN built_up_sqft > 0 THEN price / built_up_sqft ELSE 0 END),
  estimated_rental_yield_pct = COALESCE(estimated_rental_yield_pct, 0),
  historical_capital_appreciation_3yr_pct = COALESCE(historical_capital_appreciation_3yr_pct, 0),
  transactions = COALESCE(transactions, 0),
  safety_score = COALESCE(safety_score, security_score),
  crime_risk = COALESCE(crime_risk, 'Unknown'),
  flood_risk = COALESCE(flood_risk, 'Unknown'),
  distance_to_public_transport_km = COALESCE(distance_to_public_transport_km, 0),
  distance_to_mall_km = COALESCE(distance_to_mall_km, 0),
  distance_to_school_km = COALESCE(distance_to_school_km, 0),
  distance_to_hospital_km = COALESCE(distance_to_hospital_km, 0)
WHERE township IS NULL
   OR area IS NULL
   OR type IS NULL
   OR median_price IS NULL
   OR safety_score IS NULL;

CREATE INDEX idx_properties_township ON properties (township);
CREATE INDEX idx_properties_area ON properties (area);
CREATE INDEX idx_properties_median_price ON properties (median_price);
CREATE INDEX idx_properties_township_price ON properties (township, median_price);
CREATE INDEX idx_properties_area_price ON properties (area, median_price);

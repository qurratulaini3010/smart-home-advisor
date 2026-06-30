CREATE DATABASE IF NOT EXISTS smart_home_advisor
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE smart_home_advisor;

CREATE TABLE IF NOT EXISTS properties (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- Compatibility fields used by the existing Smart Home Advisor screens.
  property_name VARCHAR(255) NOT NULL,
  property_type VARCHAR(150) NOT NULL,
  location VARCHAR(150) NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  built_up_sqft INT UNSIGNED NOT NULL DEFAULT 0,
  smart_readiness_score TINYINT UNSIGNED NOT NULL DEFAULT 70,
  security_score TINYINT UNSIGNED NOT NULL DEFAULT 70,
  sustainability_score TINYINT UNSIGNED NOT NULL DEFAULT 70,
  family_score TINYINT UNSIGNED NOT NULL DEFAULT 70,
  acoustic_score TINYINT UNSIGNED NOT NULL DEFAULT 70,
  description TEXT,
  image VARCHAR(255),

  -- Geographic and structural dataset columns.
  township VARCHAR(150) NOT NULL,
  area VARCHAR(150) NOT NULL,
  state VARCHAR(100) NOT NULL,
  tenure VARCHAR(80) NOT NULL,
  type VARCHAR(150) NOT NULL,
  bedrooms TINYINT UNSIGNED NOT NULL,
  bathrooms TINYINT UNSIGNED NOT NULL,
  house_size_sqft INT UNSIGNED NOT NULL,

  -- Financial metrics.
  median_price DECIMAL(12,2) NOT NULL,
  median_psf DECIMAL(10,2) NOT NULL,
  estimated_rental_yield_pct DECIMAL(5,2) NOT NULL,
  historical_capital_appreciation_3yr_pct DECIMAL(5,2) NOT NULL,
  est_monthly_mortgage_rm DECIMAL(12,2) NULL,

  -- Market activity.
  transactions INT UNSIGNED NOT NULL DEFAULT 0,

  -- Risk and safety.
  safety_score DECIMAL(5,2) NOT NULL,
  crime_risk VARCHAR(50) NOT NULL,
  flood_risk VARCHAR(50) NOT NULL,

  -- Amenities and proximity.
  distance_to_public_transport_km DECIMAL(6,2) NOT NULL,
  distance_to_mall_km DECIMAL(6,2) NOT NULL,
  distance_to_school_km DECIMAL(6,2) NOT NULL,
  distance_to_hospital_km DECIMAL(6,2) NOT NULL,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_properties_township (township),
  INDEX idx_properties_area (area),
  INDEX idx_properties_median_price (median_price),
  INDEX idx_properties_township_price (township, median_price),
  INDEX idx_properties_area_price (area, median_price),
  INDEX idx_properties_type (type),
  INDEX idx_properties_location (location),
  INDEX idx_properties_price (price)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

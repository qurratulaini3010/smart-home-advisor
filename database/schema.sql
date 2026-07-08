CREATE DATABASE IF NOT EXISTS smart_home_advisor
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE smart_home_advisor;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  phone VARCHAR(30),
  occupation VARCHAR(150),
  date_of_birth DATE NULL,
  gross_monthly_income DECIMAL(12,2) NULL,
  role ENUM('admin','user') NOT NULL DEFAULT 'user',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_role (role)
) ENGINE=InnoDB;

-- Itemised monthly commitments table. Each row is one commitment line
-- belonging to a user; total_commitment is SUM(amount) over their rows.
CREATE TABLE user_commitments (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT NOT NULL,
  label        VARCHAR(150) NOT NULL,
  category     ENUM(
                 'car_loan',
                 'study_loan',
                 'personal_loan',
                 'credit_card',
                 'existing_mortgage',
                 'other'
               ) NOT NULL DEFAULT 'other',
  amount       DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_uc_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE properties (
  id INT AUTO_INCREMENT PRIMARY KEY,
  property_name VARCHAR(255) NOT NULL,
  property_type VARCHAR(100) NOT NULL,
  location VARCHAR(255) NOT NULL,
  state VARCHAR(100) NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  bedrooms INT NOT NULL DEFAULT 0,
  bathrooms INT NOT NULL DEFAULT 0,
  built_up_sqft INT NOT NULL DEFAULT 0,
  smart_readiness_score INT NOT NULL DEFAULT 70,
  security_score INT NOT NULL DEFAULT 70,
  sustainability_score INT NOT NULL DEFAULT 70,
  family_score INT NOT NULL DEFAULT 70,
  acoustic_score INT NOT NULL DEFAULT 70,
  description TEXT,
  image VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_properties_location (location),
  INDEX idx_properties_type (property_type),
  INDEX idx_properties_price (price)
) ENGINE=InnoDB;

CREATE TABLE assessment_criteria (
  id INT AUTO_INCREMENT PRIMARY KEY,
  criteria_key VARCHAR(80) NOT NULL UNIQUE,
  label VARCHAR(120) NOT NULL,
  weight DECIMAL(5,2) NOT NULL,
  description TEXT,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE assessments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  age INT,
  monthly_income DECIMAL(12,2) NOT NULL,
  monthly_commitment DECIMAL(12,2) NOT NULL DEFAULT 0,
  net_income DECIMAL(12,2) GENERATED ALWAYS AS (monthly_income - monthly_commitment) STORED,
  budget DECIMAL(12,2) NOT NULL,
  household_size INT NOT NULL,
  preferred_location VARCHAR(255),
  property_type VARCHAR(100),
  tenure_preference VARCHAR(80),
  min_bedrooms INT,
  low_flood_risk TINYINT(1) NOT NULL DEFAULT 0,
  near_school TINYINT(1) NOT NULL DEFAULT 0,
  smart_lighting TINYINT(1) NOT NULL DEFAULT 0,
  smart_security TINYINT(1) NOT NULL DEFAULT 0,
  smart_appliances TINYINT(1) NOT NULL DEFAULT 0,
  smart_energy TINYINT(1) NOT NULL DEFAULT 0,
  comfort_priority VARCHAR(100),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_assessments_user (user_id),
  INDEX idx_assessments_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE recommendations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  assessment_id INT NOT NULL,
  property_id INT NOT NULL,
  affordability_score DECIMAL(5,2) NOT NULL,
  security_score DECIMAL(5,2) NOT NULL,
  smart_score DECIMAL(5,2) NOT NULL,
  environment_score DECIMAL(5,2) NOT NULL,
  family_score DECIMAL(5,2) NOT NULL,
  total_score DECIMAL(5,2) NOT NULL,
  match_percentage DECIMAL(5,2) NOT NULL,
  rank_position INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
  FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
  INDEX idx_recommendations_assessment (assessment_id),
  INDEX idx_recommendations_rank (rank_position)
) ENGINE=InnoDB;

CREATE TABLE favorites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  property_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_favorite (user_id, property_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE smart_home_features (
  id INT AUTO_INCREMENT PRIMARY KEY,
  property_id INT NOT NULL,
  feature_name VARCHAR(150) NOT NULL,
  category VARCHAR(100) NOT NULL,
  impact_level ENUM('Low','Medium','High') NOT NULL DEFAULT 'Medium',
  FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
  INDEX idx_features_category (category)
) ENGINE=InnoDB;

INSERT INTO assessment_criteria (criteria_key, label, weight, description) VALUES
('affordability', 'Affordability', 30.00, 'Compares budget and property price.'),
('security', 'Security', 20.00, 'Measures safety readiness and security technology.'),
('smart', 'Smart Readiness', 20.00, 'Measures automation infrastructure and device compatibility.'),
('environment', 'Environmental Comfort', 15.00, 'Measures energy, sustainability and comfort.'),
('family', 'Family Suitability', 15.00, 'Measures bedroom fit, long-term household suitability and location fit.')
ON DUPLICATE KEY UPDATE weight = VALUES(weight), description = VALUES(description);

INSERT INTO properties
(property_name, property_type, location, state, price, bedrooms, bathrooms, built_up_sqft, smart_readiness_score, security_score, sustainability_score, family_score, acoustic_score, description, image)
VALUES
('EcoNest Residences', 'Condominium', 'Shah Alam', 'Selangor', 485000, 3, 2, 980, 91, 88, 94, 84, 82, 'A calm smart-ready condominium with energy monitoring and family-friendly shared spaces.', 'assets/img/property-1.jpg'),
('Seri Hijau Smart Terrace', 'Terrace', 'Bangi', 'Selangor', 650000, 4, 3, 1650, 84, 86, 87, 93, 79, 'A landed home suited for growing families, solar upgrades and multi-zone security.', 'assets/img/property-2.jpg'),
('MintView Service Apartment', 'Apartment', 'Cheras', 'Kuala Lumpur', 398000, 2, 2, 760, 79, 82, 81, 74, 76, 'Compact city living with essential automation, strong access control and efficient cooling.', 'assets/img/property-3.jpg'),
('Eucalyptus Garden Home', 'Semi-D', 'Putrajaya', 'Putrajaya', 980000, 5, 4, 2600, 88, 92, 90, 96, 88, 'Premium low-density home with excellent comfort zoning and future-ready infrastructure.', 'assets/img/property-4.jpg'),
('Seafoam Suites', 'Condominium', 'Johor Bahru', 'Johor', 520000, 3, 2, 1020, 86, 83, 89, 82, 84, 'Smart condo optimized for young families and hybrid work routines.', 'assets/img/property-5.jpg');

INSERT INTO smart_home_features (property_id, feature_name, category, impact_level) VALUES
(1, 'Smart Energy Dashboard', 'Energy', 'High'),
(1, 'Motion Lighting', 'Lighting', 'Medium'),
(2, 'Perimeter Security Sensors', 'Security', 'High'),
(2, 'Solar-Ready Electrical Layout', 'Energy', 'High'),
(3, 'Smart Access Control', 'Security', 'Medium'),
(3, 'Efficient Cooling Zones', 'Comfort', 'Medium'),
(4, 'Whole-Home Automation Hub', 'Automation', 'High'),
(4, 'Acoustic Rest Mode', 'Comfort', 'High'),
(5, 'Scene-Based Lighting', 'Lighting', 'Medium'),
(5, 'Energy Usage Alerts', 'Energy', 'High');


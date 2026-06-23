CREATE TABLE IF NOT EXISTS property_insights (
    id INT AUTO_INCREMENT PRIMARY KEY,
    Transactions INT NOT NULL,
    Safety_Score DECIMAL(5,2) NOT NULL,
    Crime_Risk VARCHAR(50) NOT NULL,
    Distance_to_Public_Transport_KM DECIMAL(5,2) NOT NULL,
    Distance_to_Mall_KM DECIMAL(5,2) NOT NULL,
    Distance_to_School_KM DECIMAL(5,2) NOT NULL,
    Distance_to_Hospital_KM DECIMAL(5,2) NOT NULL,
    Flood_Risk VARCHAR(50) NOT NULL,
    Estimated_Rental_Yield_Pct DECIMAL(5,2) NOT NULL,
    Historical_Capital_Appreciation_3Yr_Pct DECIMAL(5,2) NOT NULL,
    Est_Monthly_Mortgage_RM DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS additional_fees (
    fee_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fee_code VARCHAR(80) NOT NULL UNIQUE,
    category VARCHAR(40) NOT NULL,
    fee_label VARCHAR(150) NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    unit VARCHAR(40) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO additional_fees (fee_code, category, fee_label, amount, unit) VALUES
('environmental_foreigner', 'Environmental', 'Foreigner', 100.00, 'per person'),
('environmental_local', 'Environmental', 'Local', 50.00, 'per person'),
('environmental_mercedeno', 'Environmental', 'Mercedeño', 25.00, 'per person'),
('environmental_senior', 'Environmental', 'Senior citizen', 40.00, 'per person'),
('entrance_apuao_grande', 'Entrance', 'Apuao Grande Island', 30.00, 'per head'),
('entrance_caringo', 'Entrance', 'Caringo Island', 20.00, 'per head'),
('entrance_canimog_day', 'Entrance', 'Canimog Island — day tour', 100.00, 'per head'),
('entrance_canimog_overnight', 'Entrance', 'Canimog Island — overnight', 250.00, 'per head'),
('docking_apuao_pequena', 'Docking', 'Apuao Pequeña Island', 500.00, 'per boat'),
('docking_malasugui', 'Docking', 'Malasugui Island', 300.00, 'per boat'),
('docking_canimog', 'Docking', 'Canimog Island', 500.00, 'per boat'),
('equipment_snorkeling', 'Equipment', 'Snorkeling set', 200.00, 'per day'),
('equipment_kayak', 'Equipment', 'Kayak', 500.00, 'per day')
ON DUPLICATE KEY UPDATE fee_code = VALUES(fee_code);

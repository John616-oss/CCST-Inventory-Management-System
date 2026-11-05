ALTER TABLE users
    MODIFY role ENUM('registrar','admin','teacher','staff','student','maintenance','principal') NOT NULL DEFAULT 'staff';

UPDATE users
SET role = CASE
    WHEN role IN ('registrar','admin') THEN 'admin'
    WHEN role = 'maintenance' THEN 'maintenance'
    ELSE 'staff'
END;

ALTER TABLE users
    MODIFY role ENUM('admin','staff','maintenance') NOT NULL DEFAULT 'staff';

CREATE TABLE IF NOT EXISTS inventory_items(
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_tag VARCHAR(40) NOT NULL UNIQUE,
    item_name VARCHAR(150) NOT NULL,
    category VARCHAR(100) NOT NULL,
    description TEXT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    unit VARCHAR(30) NOT NULL DEFAULT 'piece',
    location VARCHAR(150) NOT NULL,
    custodian VARCHAR(150) NULL,
    condition_status ENUM('New','Good','Needs Repair','Damaged') NOT NULL DEFAULT 'Good',
    status ENUM('Available','In Use','Under Repair','Disposed') NOT NULL DEFAULT 'Available',
    acquisition_date DATE NULL,
    acquisition_cost DECIMAL(12,2) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_inventory_category(category),
    INDEX idx_inventory_location(location),
    INDEX idx_inventory_status(status)
) ENGINE=InnoDB;
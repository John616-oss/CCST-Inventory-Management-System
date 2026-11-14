CREATE TABLE IF NOT EXISTS maintenance_requests (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    inventory_item_id INT NOT NULL,
    reported_by INT NOT NULL,
    assigned_to INT NULL,
    problem_category ENUM('hardware','physical','electrical','software','other') NOT NULL DEFAULT 'other',
    issue_description TEXT NOT NULL,
    priority ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    status ENUM('Pending','In Progress','Completed','Cannot Be Repaired') NOT NULL DEFAULT 'Pending',
    repair_description TEXT NULL,
    parts_replaced TEXT NULL,
    photo_path VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    CONSTRAINT fk_maintenance_request_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE RESTRICT,
    CONSTRAINT fk_maintenance_request_reporter FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_maintenance_request_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_maintenance_request_status (status, updated_at),
    INDEX idx_maintenance_request_item (inventory_item_id, status),
    INDEX idx_maintenance_request_assignee (assigned_to, status, created_at)
) ENGINE=InnoDB;

SET @has_request_id = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maintenance_history' AND COLUMN_NAME = 'request_id'
);
SET @add_request_id = IF(@has_request_id = 0,
    'ALTER TABLE maintenance_history ADD COLUMN request_id BIGINT NULL AFTER inventory_item_id',
    'SELECT 1');
PREPARE maintenance_stmt FROM @add_request_id;
EXECUTE maintenance_stmt;
DEALLOCATE PREPARE maintenance_stmt;

SET @has_request_index = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maintenance_history' AND INDEX_NAME = 'idx_maintenance_history_request'
);
SET @add_request_index = IF(@has_request_index = 0,
    'ALTER TABLE maintenance_history ADD INDEX idx_maintenance_history_request (request_id, created_at)',
    'SELECT 1');
PREPARE maintenance_stmt FROM @add_request_index;
EXECUTE maintenance_stmt;
DEALLOCATE PREPARE maintenance_stmt;

SET @has_request_fk = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'maintenance_history' AND CONSTRAINT_NAME = 'fk_maintenance_history_request'
);
SET @add_request_fk = IF(@has_request_fk = 0,
    'ALTER TABLE maintenance_history ADD CONSTRAINT fk_maintenance_history_request FOREIGN KEY (request_id) REFERENCES maintenance_requests(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE maintenance_stmt FROM @add_request_fk;
EXECUTE maintenance_stmt;
DEALLOCATE PREPARE maintenance_stmt;

ALTER TABLE maintenance_history
    MODIFY action ENUM('started','completed','reported','cannot_be_repaired') NOT NULL;

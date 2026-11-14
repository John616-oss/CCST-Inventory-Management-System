CREATE TABLE IF NOT EXISTS equipment_transactions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    request_id BIGINT NULL,
    inventory_item_id INT NOT NULL,
    user_id INT NOT NULL,
    action ENUM('released','returned','damaged_reported') NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    condition_status ENUM('New','Good','Needs Repair','Damaged') NULL,
    details TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_equipment_transaction_request_action(request_id,action),
    INDEX idx_equipment_transaction_item_action(inventory_item_id,action,created_at),
    INDEX idx_equipment_transaction_user(user_id,created_at),
    CONSTRAINT fk_staff_equipment_transaction_request FOREIGN KEY(request_id) REFERENCES equipment_requests(id) ON DELETE RESTRICT,
    FOREIGN KEY(inventory_item_id) REFERENCES inventory_items(id) ON DELETE RESTRICT,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
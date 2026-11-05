CREATE TABLE IF NOT EXISTS maintenance_history(
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    inventory_item_id INT NOT NULL,
    user_id INT NOT NULL,
    action ENUM('started','completed') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(inventory_item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_maintenance_history_item(inventory_item_id,created_at),
    INDEX idx_maintenance_history_user(user_id)
) ENGINE=InnoDB;
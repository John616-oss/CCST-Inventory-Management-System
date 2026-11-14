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

SET @has_work_description=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='maintenance_history' AND COLUMN_NAME='work_description');
SET @add_work_description=IF(@has_work_description=0,'ALTER TABLE maintenance_history ADD COLUMN work_description TEXT NULL AFTER action','SELECT 1');
PREPARE maintenance_stmt FROM @add_work_description;
EXECUTE maintenance_stmt;
DEALLOCATE PREPARE maintenance_stmt;

SET @has_parts_replaced=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='maintenance_history' AND COLUMN_NAME='parts_replaced');
SET @add_parts_replaced=IF(@has_parts_replaced=0,'ALTER TABLE maintenance_history ADD COLUMN parts_replaced TEXT NULL AFTER work_description','SELECT 1');
PREPARE maintenance_stmt FROM @add_parts_replaced;
EXECUTE maintenance_stmt;
DEALLOCATE PREPARE maintenance_stmt;
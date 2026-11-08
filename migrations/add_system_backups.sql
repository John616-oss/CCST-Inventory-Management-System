CREATE TABLE IF NOT EXISTS system_backups (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    file_name VARCHAR(255) NOT NULL UNIQUE,
    file_size BIGINT UNSIGNED NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    restored_by INT NULL,
    restored_at DATETIME NULL,
    FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY(restored_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_system_backups_created(created_at)
) ENGINE=InnoDB;
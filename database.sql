CREATE DATABASE IF NOT EXISTS ccst_inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ccst_inventory;
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS users(
 id INT AUTO_INCREMENT PRIMARY KEY, full_name VARCHAR(150) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE,
 password VARCHAR(255) NOT NULL, role ENUM('admin','staff','maintenance') NOT NULL DEFAULT 'staff',
 is_verified TINYINT(1) NOT NULL DEFAULT 0, status ENUM('active','inactive') DEFAULT 'active',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS email_verifications(
 id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,token_hash CHAR(64) NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,used_at DATETIME NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS password_resets(
 id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,token_hash VARCHAR(255) NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,used_at DATETIME NULL,attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS activity_logs(
 id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id INT NULL,action VARCHAR(100) NOT NULL,details TEXT NULL,ip_address VARCHAR(45) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,INDEX idx_log_date(created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS notification_dismissals(
 user_id INT NOT NULL,notification_key VARCHAR(190) NOT NULL,dismissed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(user_id,notification_key),INDEX idx_notification_dismissals_date(user_id,dismissed_at),
 CONSTRAINT fk_notification_dismissals_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS inventory_items(
 id INT AUTO_INCREMENT PRIMARY KEY,asset_tag VARCHAR(40) NOT NULL UNIQUE,item_name VARCHAR(150) NOT NULL,category VARCHAR(100) NOT NULL,
 description TEXT NULL,quantity INT UNSIGNED NOT NULL DEFAULT 1,unit VARCHAR(30) NOT NULL DEFAULT 'piece',location VARCHAR(150) NOT NULL,
 custodian VARCHAR(150) NULL,condition_status ENUM('New','Good','Needs Repair','Damaged') NOT NULL DEFAULT 'Good',
 status ENUM('Available','In Use','Under Repair','Disposed') NOT NULL DEFAULT 'Available',acquisition_date DATE NULL,acquisition_cost DECIMAL(12,2) NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_inventory_category(category),INDEX idx_inventory_location(location),INDEX idx_inventory_status(status)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS equipment_requests(
 id BIGINT AUTO_INCREMENT PRIMARY KEY,requester_id INT NOT NULL,inventory_item_id INT NOT NULL,
 quantity INT UNSIGNED NOT NULL DEFAULT 1,purpose VARCHAR(255) NOT NULL,expected_return_date DATE NOT NULL,
 status ENUM('pending','approved','rejected','returned','cancelled') NOT NULL DEFAULT 'pending',
 reviewed_by INT NULL,reviewed_at DATETIME NULL,review_note VARCHAR(500) NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(requester_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(inventory_item_id) REFERENCES inventory_items(id) ON DELETE RESTRICT,
 FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
 INDEX idx_equipment_request_status(status,created_at),INDEX idx_equipment_request_item(inventory_item_id,status),
 INDEX idx_equipment_request_user(requester_id,created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS maintenance_requests(
 id BIGINT AUTO_INCREMENT PRIMARY KEY,inventory_item_id INT NOT NULL,reported_by INT NOT NULL,assigned_to INT NULL,
 problem_category ENUM('hardware','physical','electrical','software','other') NOT NULL DEFAULT 'other',issue_description TEXT NOT NULL,
 priority ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
 status ENUM('Pending','In Progress','Completed','Cannot Be Repaired') NOT NULL DEFAULT 'Pending',
 repair_description TEXT NULL,parts_replaced TEXT NULL,photo_path VARCHAR(255) NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,completed_at DATETIME NULL,
 CONSTRAINT fk_maintenance_request_item FOREIGN KEY(inventory_item_id) REFERENCES inventory_items(id) ON DELETE RESTRICT,
 CONSTRAINT fk_maintenance_request_reporter FOREIGN KEY(reported_by) REFERENCES users(id) ON DELETE RESTRICT,
 CONSTRAINT fk_maintenance_request_assignee FOREIGN KEY(assigned_to) REFERENCES users(id) ON DELETE SET NULL,
 INDEX idx_maintenance_request_status(status,updated_at),INDEX idx_maintenance_request_item(inventory_item_id,status),
 INDEX idx_maintenance_request_assignee(assigned_to,status,created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS equipment_transactions(
 id BIGINT AUTO_INCREMENT PRIMARY KEY,request_id BIGINT NULL,inventory_item_id INT NOT NULL,user_id INT NOT NULL,
 action ENUM('released','returned','damaged_reported') NOT NULL,quantity INT UNSIGNED NOT NULL DEFAULT 1,
 condition_status ENUM('New','Good','Needs Repair','Damaged') NULL,details TEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_equipment_transaction_request_action(request_id,action),
 INDEX idx_equipment_transaction_item_action(inventory_item_id,action,created_at),INDEX idx_equipment_transaction_user(user_id,created_at),
 CONSTRAINT fk_staff_equipment_transaction_request FOREIGN KEY(request_id) REFERENCES equipment_requests(id) ON DELETE RESTRICT,
 FOREIGN KEY(inventory_item_id) REFERENCES inventory_items(id) ON DELETE RESTRICT,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS maintenance_history(
 id BIGINT AUTO_INCREMENT PRIMARY KEY,inventory_item_id INT NOT NULL,request_id BIGINT NULL,user_id INT NOT NULL,
 action ENUM('started','completed','reported','cannot_be_repaired') NOT NULL,
 work_description TEXT NULL,parts_replaced TEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(inventory_item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
 CONSTRAINT fk_maintenance_history_request FOREIGN KEY(request_id) REFERENCES maintenance_requests(id) ON DELETE SET NULL,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
 INDEX idx_maintenance_history_item(inventory_item_id,created_at),INDEX idx_maintenance_history_user(user_id),
 INDEX idx_maintenance_history_request(request_id,created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS system_settings(
 setting_key VARCHAR(64) PRIMARY KEY,setting_value TEXT NOT NULL,updated_by INT NULL,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS system_backups(
 id BIGINT AUTO_INCREMENT PRIMARY KEY,file_name VARCHAR(255) NOT NULL UNIQUE,file_size BIGINT UNSIGNED NOT NULL,
 created_by INT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,restored_by INT NULL,restored_at DATETIME NULL,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,FOREIGN KEY(restored_by) REFERENCES users(id) ON DELETE SET NULL,
 INDEX idx_system_backups_created(created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_rooms(
 id INT AUTO_INCREMENT PRIMARY KEY,
 room_name VARCHAR(150) NOT NULL UNIQUE,
 floor VARCHAR(80) NOT NULL DEFAULT 'Not specified',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Existing equipment locations become rooms so existing records remain visible.
INSERT IGNORE INTO inventory_rooms(room_name,floor)
SELECT DISTINCT TRIM(location),'Not specified'
FROM inventory_items
WHERE TRIM(location)<>'';

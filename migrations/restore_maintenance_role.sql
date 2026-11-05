ALTER TABLE users
    MODIFY role ENUM('admin','staff','maintenance') NOT NULL DEFAULT 'staff';
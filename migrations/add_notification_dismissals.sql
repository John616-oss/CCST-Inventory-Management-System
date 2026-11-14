CREATE TABLE IF NOT EXISTS notification_dismissals (
    user_id INT NOT NULL,
    notification_key VARCHAR(190) NOT NULL,
    dismissed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, notification_key),
    INDEX idx_notification_dismissals_date (user_id, dismissed_at),
    CONSTRAINT fk_notification_dismissals_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

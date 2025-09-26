CREATE TABLE IF NOT EXISTS user_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_identifier VARCHAR(190) NOT NULL,
    word_id INT NOT NULL,
    proficiency TINYINT NOT NULL DEFAULT 0,
    last_reviewed_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uniq_user_word (user_identifier, word_id),
    CONSTRAINT fk_user_progress_word FOREIGN KEY (word_id) REFERENCES words(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

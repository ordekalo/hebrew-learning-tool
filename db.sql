CREATE DATABASE IF NOT EXISTS hebrew_vocab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hebrew_vocab;

CREATE TABLE IF NOT EXISTS words (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hebrew VARCHAR(255) NOT NULL,
  transliteration VARCHAR(255) NULL,
  part_of_speech VARCHAR(64) NULL,
  notes TEXT NULL,
  audio_path VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS translations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  word_id INT NOT NULL,
  lang_code VARCHAR(16) NOT NULL,
  other_script VARCHAR(255) NULL,
  meaning VARCHAR(255) NULL,
  example TEXT NULL,
  CONSTRAINT fk_translations_word FOREIGN KEY (word_id) REFERENCES words(id) ON DELETE CASCADE,
  INDEX idx_translations_word (word_id),
  INDEX idx_translations_lang (lang_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

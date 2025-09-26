CREATE DATABASE IF NOT EXISTS ezyro_40031468_hebrew_vocab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ezyro_40031468_hebrew_vocab;

CREATE TABLE IF NOT EXISTS words (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hebrew VARCHAR(255) NOT NULL,
  transliteration VARCHAR(255) NULL,
  part_of_speech VARCHAR(64) NULL,
  notes TEXT NULL,
  audio_path VARCHAR(255) NULL,
  image_path VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_words_hebrew (hebrew)
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

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS decks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL UNIQUE,
  description TEXT NULL,
  cover_image VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS deck_words (
  deck_id INT NOT NULL,
  word_id INT NOT NULL,
  PRIMARY KEY (deck_id, word_id),
  CONSTRAINT fk_deck_words_deck FOREIGN KEY (deck_id) REFERENCES decks(id) ON DELETE CASCADE,
  CONSTRAINT fk_deck_words_word FOREIGN KEY (word_id) REFERENCES words(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tags (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS word_tags (
  word_id INT NOT NULL,
  tag_id INT NOT NULL,
  PRIMARY KEY (word_id, tag_id),
  CONSTRAINT fk_word_tags_word FOREIGN KEY (word_id) REFERENCES words(id) ON DELETE CASCADE,
  CONSTRAINT fk_word_tags_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_progress (
  user_id INT NOT NULL,
  word_id INT NOT NULL,
  interval_days SMALLINT DEFAULT 0,
  ease DECIMAL(3,2) DEFAULT 2.50,
  due_at DATETIME NULL,
  reps INT DEFAULT 0,
  lapses INT DEFAULT 0,
  last_result ENUM('again','hard','good','easy') NULL,
  PRIMARY KEY (user_id, word_id),
  CONSTRAINT fk_user_progress_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_progress_word FOREIGN KEY (word_id) REFERENCES words(id) ON DELETE CASCADE,
  INDEX idx_user_progress_due (due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS streaks (
  user_id INT NOT NULL,
  day DATE NOT NULL,
  learned INT DEFAULT 0,
  correct_rate DECIMAL(5,2) DEFAULT 0.00,
  PRIMARY KEY (user_id, day),
  CONSTRAINT fk_streaks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS achievements (
  user_id INT NOT NULL,
  code VARCHAR(64) NOT NULL,
  unlocked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, code),
  CONSTRAINT fk_achievements_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

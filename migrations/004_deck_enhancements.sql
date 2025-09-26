CREATE TABLE IF NOT EXISTS deck_words (
    deck_id INT NOT NULL,
    word_id INT NOT NULL,
    position INT NOT NULL DEFAULT 0,
    is_reversed TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (deck_id, word_id),
    KEY idx_deck_words_deck (deck_id),
    KEY idx_deck_words_word (word_id),
    CONSTRAINT fk_deck_words_deck FOREIGN KEY (deck_id) REFERENCES decks(id) ON DELETE CASCADE,
    CONSTRAINT fk_deck_words_word FOREIGN KEY (word_id) REFERENCES words(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE decks
    ADD COLUMN category VARCHAR(120) DEFAULT 'General' AFTER description,
    ADD COLUMN icon VARCHAR(60) DEFAULT 'book' AFTER category,
    ADD COLUMN color VARCHAR(20) DEFAULT '#6366f1' AFTER icon,
    ADD COLUMN rating DECIMAL(2,1) DEFAULT 5.0 AFTER color,
    ADD COLUMN learners_count INT DEFAULT 0 AFTER rating,
    ADD COLUMN is_frozen TINYINT(1) DEFAULT 0 AFTER learners_count,
    ADD COLUMN is_reversed TINYINT(1) DEFAULT 0 AFTER is_frozen,
    ADD COLUMN ai_generation_enabled TINYINT(1) DEFAULT 0 AFTER is_reversed,
    ADD COLUMN offline_enabled TINYINT(1) DEFAULT 0 AFTER ai_generation_enabled,
    ADD COLUMN published_at TIMESTAMP NULL DEFAULT NULL AFTER offline_enabled,
    ADD COLUMN published_description TEXT NULL AFTER published_at,
    ADD COLUMN min_cards_required INT DEFAULT 75 AFTER published_description,
    ADD COLUMN tts_enabled TINYINT(1) DEFAULT 0 AFTER min_cards_required,
    ADD COLUMN tts_autoplay TINYINT(1) DEFAULT 0 AFTER tts_enabled,
    ADD COLUMN tts_front_lang VARCHAR(20) DEFAULT 'en-US' AFTER tts_autoplay,
    ADD COLUMN tts_back_lang VARCHAR(20) DEFAULT 'he-IL' AFTER tts_front_lang;

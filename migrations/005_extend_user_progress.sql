-- Ensure the spaced-repetition fields exist on user_progress
SET @table := 'user_progress';

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table AND COLUMN_NAME = 'user_id');
SET @stmt := IF(@col = 0,
    'ALTER TABLE `user_progress` ADD COLUMN `user_id` INT NULL AFTER `id`;',
    'SELECT 1;'
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table AND COLUMN_NAME = 'interval_days');
SET @stmt := IF(@col = 0,
    'ALTER TABLE `user_progress` ADD COLUMN `interval_days` SMALLINT DEFAULT 0 AFTER `word_id`;',
    'SELECT 1;'
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table AND COLUMN_NAME = 'ease');
SET @stmt := IF(@col = 0,
    'ALTER TABLE `user_progress` ADD COLUMN `ease` DECIMAL(3,2) DEFAULT 2.50 AFTER `interval_days`;',
    'SELECT 1;'
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table AND COLUMN_NAME = 'due_at');
SET @stmt := IF(@col = 0,
    'ALTER TABLE `user_progress` ADD COLUMN `due_at` DATETIME NULL AFTER `ease`;',
    'SELECT 1;'
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table AND COLUMN_NAME = 'reps');
SET @stmt := IF(@col = 0,
    'ALTER TABLE `user_progress` ADD COLUMN `reps` INT DEFAULT 0 AFTER `due_at`;',
    'SELECT 1;'
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table AND COLUMN_NAME = 'lapses');
SET @stmt := IF(@col = 0,
    'ALTER TABLE `user_progress` ADD COLUMN `lapses` INT DEFAULT 0 AFTER `reps`;',
    'SELECT 1;'
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table AND COLUMN_NAME = 'last_result');
SET @stmt := IF(@col = 0,
    "ALTER TABLE `user_progress` ADD COLUMN `last_result` ENUM('again','hard','good','easy') NULL AFTER `lapses`;",
    'SELECT 1;'
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ensure we have a composite key over the numeric identifiers
SET @constraint := (SELECT COUNT(*)
                    FROM information_schema.TABLE_CONSTRAINTS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = @table
                      AND CONSTRAINT_TYPE = 'UNIQUE'
                      AND CONSTRAINT_NAME = 'uniq_user_word');
SET @stmt := IF(@constraint = 0,
    'ALTER TABLE `user_progress` ADD UNIQUE KEY `uniq_user_word` (`user_id`, `word_id`);',
    'SELECT 1;'
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for due dates if missing
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table AND INDEX_NAME = 'idx_user_progress_due');
SET @stmt := IF(@idx = 0,
    'CREATE INDEX `idx_user_progress_due` ON `user_progress` (`due_at`);',
    'SELECT 1;'
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ensure foreign keys exist when the referenced tables are available
SET @fk := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_user_progress_user');
SET @stmt := IF(@fk = 0,
    'ALTER TABLE `user_progress` ADD CONSTRAINT `fk_user_progress_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;',
    'SELECT 1;'
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_user_progress_word');
SET @stmt := IF(@fk = 0,
    'ALTER TABLE `user_progress` ADD CONSTRAINT `fk_user_progress_word` FOREIGN KEY (`word_id`) REFERENCES `words`(`id`) ON DELETE CASCADE;',
    'SELECT 1;'
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

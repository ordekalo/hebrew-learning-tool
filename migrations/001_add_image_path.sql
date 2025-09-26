SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'words'
      AND COLUMN_NAME = 'image_path'
);
SET @stmt := IF(@col_exists = 0,
    'ALTER TABLE `words` ADD COLUMN `image_path` VARCHAR(255) NULL AFTER `audio_path`;',
    'SELECT 1;'
);
PREPARE add_column_stmt FROM @stmt;
EXECUTE add_column_stmt;
DEALLOCATE PREPARE add_column_stmt;

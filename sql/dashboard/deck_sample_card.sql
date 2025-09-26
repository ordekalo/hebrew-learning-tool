SELECT w.*, t.lang_code, t.meaning, t.other_script, dw.is_reversed
FROM deck_words dw
INNER JOIN words w ON w.id = dw.word_id
LEFT JOIN (
    SELECT tr.word_id, tr.lang_code, tr.other_script, tr.meaning
    FROM translations tr
    INNER JOIN (
        SELECT word_id, MIN(id) AS min_id
        FROM translations
        GROUP BY word_id
    ) picked ON picked.word_id = tr.word_id AND picked.min_id = tr.id
) t ON t.word_id = w.id
WHERE dw.deck_id = ?
ORDER BY dw.position ASC, w.created_at DESC
LIMIT 1;

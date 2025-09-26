SELECT w.hebrew, w.transliteration, up.proficiency, up.last_reviewed_at
FROM deck_words dw
INNER JOIN user_progress up ON up.word_id = dw.word_id AND up.user_identifier = ?
INNER JOIN words w ON w.id = dw.word_id
WHERE dw.deck_id = ?
ORDER BY up.last_reviewed_at DESC
LIMIT 20;

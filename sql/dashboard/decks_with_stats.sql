SELECT d.*, COUNT(DISTINCT dw.word_id) AS cards_count,
       COUNT(DISTINCT up.word_id) AS studied_count,
       SUM(CASE WHEN up.proficiency >= 3 THEN 1 ELSE 0 END) AS mastered_count,
       MAX(up.last_reviewed_at) AS last_reviewed_at
FROM decks d
LEFT JOIN deck_words dw ON dw.deck_id = d.id
LEFT JOIN user_progress up ON up.word_id = dw.word_id AND up.user_identifier = :user
GROUP BY d.id
ORDER BY d.created_at DESC;

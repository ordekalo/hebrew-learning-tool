ALTER TABLE decks
    ADD COLUMN tts_front_voice VARCHAR(120) DEFAULT '' AFTER tts_front_lang,
    ADD COLUMN tts_back_voice VARCHAR(120) DEFAULT '' AFTER tts_back_lang;

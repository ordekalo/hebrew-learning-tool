<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$csrf = ensure_token();
$flash = get_flash();
$action = $_GET['a'] ?? 'learn';
$langFilter = isset($_GET['lang']) && $_GET['lang'] !== '' ? substr($_GET['lang'], 0, 10) : null;
$searchTerm = trim($_GET['q'] ?? '');

function fetch_random_cards(PDO $pdo, ?string $lang, int $limit = 1, bool $requireMeaning = false): array
{
    $limit = max(1, $limit);
    $conditions = [];
    $params = [];

    if ($lang !== null) {
        $conditions[] = 't.lang_code = ?';
        $params[] = $lang;
    }

    if ($requireMeaning) {
        $conditions[] = "(t.meaning IS NOT NULL AND t.meaning <> '')";
    }

    $sql = 'SELECT w.*, t.lang_code, t.other_script, t.meaning, t.example
            FROM words w
            LEFT JOIN (
                SELECT tr.word_id, tr.lang_code, tr.other_script, tr.meaning, tr.example
                FROM translations tr
                INNER JOIN (
                    SELECT word_id, MIN(id) AS min_id
                    FROM translations
                    GROUP BY word_id
                ) picked ON picked.word_id = tr.word_id AND picked.min_id = tr.id
            ) t ON t.word_id = w.id';

    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= ' ORDER BY ' . db_random_function() . ' LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function fetch_random_card(PDO $pdo, ?string $lang): ?array
{
    $cards = fetch_random_cards($pdo, $lang, 1);

    return $cards[0] ?? null;
}

if ($action === 'create_word' && is_post()) {
    check_token($_POST['csrf'] ?? null);

    $hebrew = trim($_POST['hebrew'] ?? '');
    $transliteration = trim($_POST['transliteration'] ?? '');
    $partOfSpeech = trim($_POST['part_of_speech'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($hebrew === '') {
        flash('Please enter the Hebrew word.', 'error');
        redirect('index.php');
    }

    try {
        $audioPath = handle_audio_upload($_FILES['audio'] ?? [], $UPLOAD_DIR);
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
        redirect('index.php');
    }

    $recordedAudio = $_POST['recorded_audio'] ?? '';

    if ($audioPath === null && $recordedAudio !== '') {
        try {
            $audioPath = save_recorded_audio($recordedAudio, $UPLOAD_DIR);
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'error');
            redirect('index.php');
        }
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('INSERT INTO words (hebrew, transliteration, part_of_speech, notes, audio_path)
                           VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$hebrew, $transliteration, $partOfSpeech, $notes, $audioPath]);
    $wordId = (int) $pdo->lastInsertId();

    $langCode = trim($_POST['lang_code'] ?? '');
    $otherScript = trim($_POST['other_script'] ?? '');
    $meaning = trim($_POST['meaning'] ?? '');
    $example = trim($_POST['example'] ?? '');

    if ($langCode !== '' || $meaning !== '' || $otherScript !== '') {
        $insertTranslation = $pdo->prepare('INSERT INTO translations (word_id, lang_code, other_script, meaning, example)
                                            VALUES (?, ?, ?, ?, ?)');
        $insertTranslation->execute([
            $wordId,
            $langCode !== '' ? $langCode : 'und',
            $otherScript !== '' ? $otherScript : null,
            $meaning !== '' ? $meaning : null,
            $example !== '' ? $example : null,
        ]);
    }

    $pdo->commit();
    flash('Word added.', 'success');
    redirect('index.php');
}

$searchResults = [];
if ($searchTerm !== '') {
    $translationSummarySelect = db_translation_summary_select();
    $sql = 'SELECT w.*, ' . $translationSummarySelect . '
            FROM words w
            LEFT JOIN translations t ON t.word_id = w.id
            WHERE w.hebrew LIKE ?
               OR w.transliteration LIKE ?
               OR t.meaning LIKE ?
               OR t.other_script LIKE ?
            GROUP BY w.id
            ORDER BY w.created_at DESC
            LIMIT 50';
    $stmt = $pdo->prepare($sql);
    $like = '%' . $searchTerm . '%';
    $stmt->execute([$like, $like, $like, $like]);
    $searchResults = $stmt->fetchAll();
}

$card = fetch_random_card($pdo, $langFilter);
$carouselCards = fetch_random_cards($pdo, $langFilter, 8);
$memoryPairs = fetch_random_cards($pdo, $langFilter, 6, true);
$memoryPairs = array_values(array_filter($memoryPairs, static fn(array $row): bool => ($row['meaning'] ?? '') !== ''));
$memoryData = array_map(
    static fn(array $row): array => [
        'id' => (int) $row['id'],
        'hebrew' => $row['hebrew'] ?? '',
        'meaning' => $row['meaning'] ?? '',
        'other_script' => $row['other_script'] ?? '',
        'transliteration' => $row['transliteration'] ?? '',
    ],
    $memoryPairs
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hebrew Vocabulary App</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="container">
    <header class="header">
        <nav class="nav">
            <a href="index.php">Learn</a>
            <a href="words.php">Admin: Words</a>
            <a href="import_csv.php">Bulk Import CSV</a>
        </nav>
        <form method="get" action="index.php" class="flex">
            <input type="text" name="q" placeholder="Search..." value="<?= h($searchTerm) ?>">
            <button class="btn" type="submit">Search</button>
        </form>
    </header>

    <h1>Test</h1>

    <?php if ($flash): ?>
        <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <section class="card flashcard-section">
        <div class="section-heading">
            <div>
                <h2>Flashcards</h2>
                <p class="section-subtitle">Swipe right and left to review fresh cards.</p>
            </div>
            <div class="flex wrap">
                <a class="btn secondary" href="index.php">Shuffle</a>
                <a class="btn" href="index.php?lang=ru">RU</a>
                <a class="btn" href="index.php?lang=en">EN</a>
                <a class="btn" href="index.php?lang=ar">AR</a>
            </div>
        </div>
        <?php if ($carouselCards): ?>
            <div class="flashcard-track" tabindex="0">
                <?php foreach ($carouselCards as $item): ?>
                    <article class="flashcard" role="listitem">
                        <header class="flashcard-header">
                            <span class="badge">Hebrew</span>
                            <?php if (!empty($item['part_of_speech'])): ?>
                                <span class="badge badge-muted"><?= h($item['part_of_speech']) ?></span>
                            <?php endif; ?>
                        </header>
                        <h3 class="hebrew-word"><?= h($item['hebrew']) ?></h3>
                        <?php if (!empty($item['transliteration'])): ?>
                            <p class="flashcard-text"><span class="badge">Transliteration</span> <?= h($item['transliteration']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($item['notes'])): ?>
                            <p class="flashcard-text"><?= nl2br(h($item['notes'])) ?></p>
                        <?php endif; ?>
                        <div class="flashcard-translation">
                            <span class="badge">Meaning</span>
                            <p class="flashcard-text">
                                <strong><?= h($item['lang_code'] ?? '—') ?>:</strong>
                                <?= h($item['meaning'] ?? '—') ?>
                            </p>
                            <?php if (!empty($item['other_script'])): ?>
                                <p class="flashcard-text alt-script"><?= h($item['other_script']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($item['example'])): ?>
                                <p class="flashcard-text example"><?= nl2br(h($item['example'])) ?></p>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($item['audio_path'])): ?>
                            <audio class="audio" controls preload="none" src="<?= h($item['audio_path']) ?>"></audio>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p>No words yet. Add some below.</p>
        <?php endif; ?>
    </section>

    <section class="card memory-section">
        <div class="section-heading">
            <div>
                <h2>Memory Trainer</h2>
                <p class="section-subtitle">Match each Hebrew word with its translation to build recall.</p>
            </div>
            <button type="button" class="btn secondary" id="memory-reset" <?= empty($memoryPairs) ? 'disabled' : '' ?>>Shuffle board</button>
        </div>
        <?php if ($memoryPairs): ?>
            <div class="memory-status">
                <span>Matches: <strong id="memory-matches">0</strong> / <?= count($memoryPairs) ?></span>
                <span id="memory-feedback" role="status" aria-live="polite"></span>
            </div>
            <div class="memory-board" id="memory-board" aria-label="Memory trainer board"></div>
        <?php else: ?>
            <p class="memory-empty">Add translations to play the memory game.</p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h3>Quick Add Word</h3>
        <form method="post" enctype="multipart/form-data" action="index.php?a=create_word" id="quick-add-form">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="recorded_audio" id="recorded_audio">
            <div class="grid grid-3">
                <div>
                    <label for="hebrew">Hebrew *</label>
                    <input id="hebrew" name="hebrew" required placeholder="לְדֻגְמָה">
                </div>
                <div>
                    <label for="transliteration">Transliteration</label>
                    <input id="transliteration" name="transliteration" placeholder="le-dugma">
                </div>
                <div>
                    <label for="part_of_speech">Part of speech</label>
                    <input id="part_of_speech" name="part_of_speech" placeholder="noun/verb/etc">
                </div>
            </div>
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" rows="3" placeholder="Any nuances, gender, irregular forms..."></textarea>

            <label for="audio">Pronunciation (audio/mp3/wav/ogg ≤ 10MB)</label>
            <div class="record-row">
                <input id="audio" type="file" name="audio" accept="audio/*">
                <div class="record-controls" id="record-controls">
                    <button type="button" class="btn secondary" id="record-toggle" data-state="idle">🎙️ Record</button>
                    <button type="button" class="btn" id="record-save" disabled>Use recording</button>
                </div>
            </div>
            <div class="record-preview" id="record-preview" hidden>
                <audio id="recorded-audio" controls></audio>
                <button type="button" class="btn secondary" id="record-discard">Discard</button>
            </div>

            <div class="grid grid-3">
                <div>
                    <label for="lang_code">Translation language</label>
                    <input id="lang_code" name="lang_code" placeholder="e.g., ru, en, fr">
                </div>
                <div>
                    <label for="other_script">Other script (spelling)</label>
                    <input id="other_script" name="other_script" placeholder="пример / example">
                </div>
                <div>
                    <label for="meaning">Meaning (gloss)</label>
                    <input id="meaning" name="meaning" placeholder="example / пример">
                </div>
            </div>
            <label for="example">Example (optional)</label>
            <textarea id="example" name="example" rows="2" placeholder="Use in a sentence"></textarea>

            <div class="form-actions">
                <button class="btn" type="submit">Add</button>
            </div>
        </form>
    </section>

    <?php if ($searchTerm !== '' && $searchResults): ?>
        <section class="card">
            <h3>Search Results</h3>
            <table class="table">
                <thead>
                <tr>
                    <th>Hebrew</th>
                    <th>Translit</th>
                    <th>POS</th>
                    <th>Translations</th>
                    <th>Audio</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($searchResults as $row): ?>
                    <tr>
                        <td><?= h($row['hebrew']) ?></td>
                        <td><?= h($row['transliteration']) ?></td>
                        <td><?= h($row['part_of_speech']) ?></td>
                        <td><pre class="translations-pre"><?= h($row['translations_summary']) ?></pre></td>
                        <td>
                            <?php if (!empty($row['audio_path'])): ?>
                                <audio controls src="<?= h($row['audio_path']) ?>"></audio>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><a class="btn secondary" href="edit_word.php?id=<?= (int) $row['id'] ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php elseif ($searchTerm !== ''): ?>
        <section class="card">
            <h3>Search Results</h3>
            <p>No results found.</p>
        </section>
    <?php endif; ?>
    <?php if (!empty($memoryData)): ?>
        <script type="application/json" id="memory-data"><?= json_encode($memoryData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>
    <?php endif; ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const memoryDataEl = document.getElementById('memory-data');
    const board = document.getElementById('memory-board');
    const matchesEl = document.getElementById('memory-matches');
    const feedbackEl = document.getElementById('memory-feedback');
    const resetBtn = document.getElementById('memory-reset');

    if (memoryDataEl && board && matchesEl) {
        const basePairs = JSON.parse(memoryDataEl.textContent || '[]');
        const matchedPairs = new Set();
        let flipped = [];

        const pickLabel = (item) => item.meaning || item.other_script || item.transliteration || '—';

        const buildDeck = () => {
            const deck = [];
            basePairs.forEach((item) => {
                deck.push({ pairId: String(item.id), type: 'hebrew', label: item.hebrew, announce: `Hebrew: ${item.hebrew}` });
                deck.push({ pairId: String(item.id), type: 'translation', label: pickLabel(item), announce: `Translation: ${pickLabel(item)}` });
            });
            for (let i = deck.length - 1; i > 0; i -= 1) {
                const j = Math.floor(Math.random() * (i + 1));
                [deck[i], deck[j]] = [deck[j], deck[i]];
            }
            return deck;
        };

        const clearBoardState = () => {
            matchedPairs.clear();
            flipped = [];
            matchesEl.textContent = '0';
            if (feedbackEl) {
                feedbackEl.textContent = '';
            }
        };

        const announceMatch = () => {
            if (!feedbackEl) {
                return;
            }
            if (matchedPairs.size === basePairs.length) {
                feedbackEl.textContent = 'All pairs matched! Great job!';
            } else {
                feedbackEl.textContent = `Matched ${matchedPairs.size} of ${basePairs.length} pairs.`;
            }
        };

        const renderBoard = () => {
            const deck = buildDeck();
            clearBoardState();
            board.innerHTML = '';
            deck.forEach((card, index) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'memory-card';
                button.dataset.pairId = card.pairId;
                button.dataset.type = card.type;
                button.setAttribute('aria-label', card.announce);
                button.setAttribute('data-index', String(index));

                const span = document.createElement('span');
                span.textContent = card.label;
                button.appendChild(span);

                button.addEventListener('click', () => handleFlip(button));
                board.appendChild(button);
            });
        };

        const unflipCards = (first, second) => {
            setTimeout(() => {
                first.classList.remove('flipped');
                second.classList.remove('flipped');
                first.disabled = false;
                second.disabled = false;
                flipped = [];
            }, 900);
        };

        const handleFlip = (button) => {
            if (button.classList.contains('matched') || flipped.includes(button)) {
                return;
            }

            button.classList.add('flipped');
            button.disabled = true;
            flipped.push(button);

            if (flipped.length === 2) {
                const [first, second] = flipped;
                const isMatch = first.dataset.pairId === second.dataset.pairId && first.dataset.type !== second.dataset.type;
                if (isMatch) {
                    first.classList.add('matched');
                    second.classList.add('matched');
                    matchedPairs.add(first.dataset.pairId || '');
                    matchesEl.textContent = String(matchedPairs.size);
                    flipped = [];
                    announceMatch();
                } else {
                    unflipCards(first, second);
                }
            }
        };

        resetBtn?.addEventListener('click', () => {
            renderBoard();
        });

        renderBoard();
    }

    const recordToggle = document.getElementById('record-toggle');
    const recordSave = document.getElementById('record-save');
    const recordDiscard = document.getElementById('record-discard');
    const recordPreview = document.getElementById('record-preview');
    const recordedAudioElement = document.getElementById('recorded-audio');
    const recordedAudioInput = document.getElementById('recorded_audio');
    const fileInput = document.getElementById('audio');

    let mediaRecorder = null;
    let audioChunks = [];
    let mediaStream = null;
    let recordedBlob = null;

    const stopStream = () => {
        if (mediaStream) {
            mediaStream.getTracks().forEach((track) => track.stop());
            mediaStream = null;
        }
    };

    const resetRecording = () => {
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            mediaRecorder.stop();
        }
        recordedBlob = null;
        audioChunks = [];
        recordedAudioInput.value = '';
        if (recordPreview) {
            recordPreview.hidden = true;
        }
        if (recordSave) {
            recordSave.disabled = true;
        }
        if (recordToggle) {
            recordToggle.dataset.state = 'idle';
            recordToggle.textContent = '🎙️ Record';
            recordToggle.disabled = false;
        }
        stopStream();
    };

    const handleDataAvailable = (event) => {
        if (event.data && event.data.size > 0) {
            audioChunks.push(event.data);
        }
    };

    const handleStop = () => {
        recordedBlob = new Blob(audioChunks, { type: audioChunks[0]?.type || 'audio/webm' });
        if (recordPreview && recordedAudioElement && recordedBlob.size > 0) {
            recordedAudioElement.src = URL.createObjectURL(recordedBlob);
            recordPreview.hidden = false;
            if (recordSave) {
                recordSave.disabled = false;
            }
        }
        if (recordToggle) {
            recordToggle.dataset.state = 'idle';
            recordToggle.textContent = '🎙️ Record again';
            recordToggle.disabled = false;
        }
        stopStream();
    };

    const startRecording = async () => {
        if (!navigator.mediaDevices || typeof MediaRecorder === 'undefined') {
            if (recordToggle) {
                recordToggle.textContent = 'Recording unsupported';
                recordToggle.disabled = true;
            }
            return;
        }

        try {
            mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            mediaRecorder = new MediaRecorder(mediaStream);
            audioChunks = [];

            mediaRecorder.addEventListener('dataavailable', handleDataAvailable);
            mediaRecorder.addEventListener('stop', handleStop, { once: true });
            mediaRecorder.start();
            if (recordToggle) {
                recordToggle.dataset.state = 'recording';
                recordToggle.textContent = '⏹️ Stop';
            }
        } catch (err) {
            console.error('Recorder error', err);
            if (recordToggle) {
                recordToggle.textContent = 'Permission denied';
                recordToggle.disabled = true;
            }
        }
    };

    const stopRecording = () => {
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            mediaRecorder.stop();
        }
        if (recordToggle) {
            recordToggle.dataset.state = 'processing';
            recordToggle.textContent = 'Processing…';
            recordToggle.disabled = true;
        }
    };

    recordToggle?.addEventListener('click', () => {
        const state = recordToggle.dataset.state;
        if (state === 'idle' || state === 'saved') {
            resetRecording();
            startRecording();
        } else if (state === 'recording') {
            stopRecording();
        }
    });

    recordSave?.addEventListener('click', () => {
        if (!recordedBlob) {
            return;
        }
        const reader = new FileReader();
        reader.addEventListener('loadend', () => {
            if (typeof reader.result === 'string') {
                recordedAudioInput.value = reader.result;
                if (recordToggle) {
                    recordToggle.dataset.state = 'saved';
                    recordToggle.textContent = 'Recording ready ✓';
                }
            }
        });
        reader.readAsDataURL(recordedBlob);
    });

    recordDiscard?.addEventListener('click', () => {
        resetRecording();
    });

    fileInput?.addEventListener('change', () => {
        if (fileInput.files && fileInput.files.length > 0) {
            resetRecording();
        }
    });
});
</script>
</body>
</html>

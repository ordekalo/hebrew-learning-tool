# Hebrew Vocabulary App — PHP/MySQL (Single‑Folder Version)

Below is a compact, ready‑to‑run implementation. Place all files in a web‑served folder (e.g., `/var/www/html/hebrewapp`). Create the database using the `db.sql` section, update `config.php`, and you’re ready.

---

## 📦 File: `config.php`
```php
<?php
// --- Database & App Config ---
$DB_HOST = 'localhost';
$DB_NAME = 'hebrew_vocab';
$DB_USER = 'root';
$DB_PASS = '';
$APP_BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); // base path
$UPLOAD_DIR = __DIR__ . '/uploads'; // server path
$UPLOAD_URL = $APP_BASE . '/uploads'; // public URL

if (!is_dir($UPLOAD_DIR)) {
    mkdir($UPLOAD_DIR, 0755, true);
}

// --- PDO ---
try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo "DB connection failed: " . htmlspecialchars($e->getMessage());
    exit;
}

// --- Helpers ---
function h($s){return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');}
function redirect($path){ header('Location: ' . $path); exit; }
function is_post(){return strtoupper($_SERVER['REQUEST_METHOD'])==='POST';}
function ensure_token(){ session_start(); if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function check_token(){ session_start(); if (empty($_POST['csrf']) || $_POST['csrf']!==($_SESSION['csrf']??'')) { http_response_code(403); echo 'Invalid CSRF'; exit; }}
```
```

---

## 🗄️ File: `db.sql` (run once in MySQL)
```sql
CREATE DATABASE IF NOT EXISTS hebrew_vocab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hebrew_vocab;

-- Words are the core items (usually Hebrew). Each word can have many translations in many languages.
CREATE TABLE IF NOT EXISTS words (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hebrew VARCHAR(255) NOT NULL,
  transliteration VARCHAR(255) NULL, -- optional: how to read in Latin/Russian, etc.
  part_of_speech VARCHAR(64) NULL,
  notes TEXT NULL,
  audio_path VARCHAR(255) NULL, -- pronunciation recording file path (server relative)
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Separate table for translations and alternate scripts per language
CREATE TABLE IF NOT EXISTS translations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  word_id INT NOT NULL,
  lang_code VARCHAR(16) NOT NULL, -- e.g., 'ru', 'en', 'fr', 'ar'
  other_script VARCHAR(255) NULL,  -- how the word is written in that language/script
  meaning VARCHAR(255) NULL,       -- translation (gloss)
  example TEXT NULL,
  CONSTRAINT fk_trans_word FOREIGN KEY (word_id) REFERENCES words(id) ON DELETE CASCADE,
  INDEX idx_trans_word (word_id),
  INDEX idx_trans_lang (lang_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
```

---

## 🎨 File: `styles.css`
```css
:root{--bg:#0b0f14;--card:#121822;--text:#e6edf3;--muted:#9fb2c7;--accent:#4aa3ff;--ok:#22c55e;--warn:#f59e0b;--danger:#ef4444}
*{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--text);font:16px/1.5 system-ui,Segoe UI,Roboto,Arial} a{color:var(--accent);text-decoration:none}
.container{max-width:1100px;margin:0 auto;padding:24px}
.header{display:flex;gap:12px;align-items:center;justify-content:space-between}
.nav a{margin-right:14px;opacity:.9}
.card{background:var(--card);border:1px solid #1b2331;border-radius:16px;padding:20px;margin:16px 0;box-shadow:0 10px 30px rgba(0,0,0,.25)}
input,select,textarea{width:100%;background:#0c121b;color:var(--text);border:1px solid #1b2232;padding:10px;border-radius:10px}
label{display:block;margin:12px 0 6px;color:var(--muted)}
.btn{display:inline-block;background:var(--accent);color:#00172b;border:none;padding:10px 16px;border-radius:12px;font-weight:600;cursor:pointer}
.btn.secondary{background:#223043;color:var(--text)}
.btn.danger{background:var(--danger)}
.table{width:100%;border-collapse:collapse}
.table th,.table td{padding:10px;border-bottom:1px solid #223043;text-align:left}
.badge{display:inline-block;padding:2px 8px;border-radius:999px;background:#223043;color:var(--muted);font-size:12px}
.flex{display:flex;gap:12px;align-items:center}
.grid{display:grid;gap:12px}
.grid-2{grid-template-columns:1fr 1fr}
.grid-3{grid-template-columns:repeat(3,1fr)}
.flash{padding:8px 12px;border-radius:12px;background:#12263a;border:1px solid #1a3758;margin-bottom:10px}
.audio{margin-top:8px}
```
```

---

## 🧭 File: `index.php` (Flashcards + Search + Quick Add)
```php
<?php require __DIR__ . '/config.php'; $csrf = ensure_token();
// Simple router actions
$action = $_GET['a'] ?? 'learn';

function fetch_random_card(PDO $pdo, $lang=null){
    $sql = "SELECT w.*, t.lang_code, t.other_script, t.meaning FROM words w LEFT JOIN translations t ON t.word_id=w.id" . ($lang?" AND t.lang_code=?":"") . " ORDER BY RAND() LIMIT 1";
    $stmt = $pdo->prepare($lang?"SELECT w.*, t.lang_code, t.other_script, t.meaning FROM words w LEFT JOIN translations t ON t.word_id=w.id AND t.lang_code=? ORDER BY RAND() LIMIT 1":"SELECT w.*, t.lang_code, t.other_script, t.meaning FROM words w LEFT JOIN translations t ON t.word_id=w.id ORDER BY RAND() LIMIT 1");
    $stmt->execute($lang?[ $lang ]:[]);
    return $stmt->fetch();
}

if ($action==='create_word' && is_post()){
    check_token();
    $hebrew = trim($_POST['hebrew']??'');
    $transliteration = trim($_POST['transliteration']??'');
    $pos = trim($_POST['part_of_speech']??'');
    $notes = trim($_POST['notes']??'');

    // handle audio upload (optional)
    $audio_path = null;
    if (!empty($_FILES['audio']['name'])){
        $ok = in_array(mime_content_type($_FILES['audio']['tmp_name']), ['audio/mpeg','audio/wav','audio/x-wav','audio/mp3','audio/ogg']);
        if ($ok && $_FILES['audio']['size'] <= 10*1024*1024){
            $ext = pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION);
            $fname = 'word_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . preg_replace('/[^a-z0-9]/i','',$ext);
            move_uploaded_file($_FILES['audio']['tmp_name'], $UPLOAD_DIR . '/' . $fname);
            $audio_path = 'uploads/' . $fname;
        }
    }

    if ($hebrew===''){ redirect('index.php?msg='.urlencode('Please enter the Hebrew word.')); }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO words(hebrew, transliteration, part_of_speech, notes, audio_path) VALUES (?,?,?,?,?)");
    $stmt->execute([$hebrew,$transliteration,$pos,$notes,$audio_path]);
    $word_id = (int)$pdo->lastInsertId();

    // optional first translation block
    $lang = trim($_POST['lang_code']??'');
    $other_script = trim($_POST['other_script']??'');
    $meaning = trim($_POST['meaning']??'');
    $example = trim($_POST['example']??'');
    if ($lang!=='' || $meaning!=='' || $other_script!==''){
        $stmt2 = $pdo->prepare("INSERT INTO translations(word_id, lang_code, other_script, meaning, example) VALUES (?,?,?,?,?)");
        $stmt2->execute([$word_id, $lang?:'und', $other_script?:null, $meaning?:null, $example?:null]);
    }
    $pdo->commit();
    redirect('index.php?msg='.urlencode('Word added.'));
}

// Search
$q = trim($_GET['q'] ?? '');
$rows = [];
if ($q!==''){
    $stmt=$pdo->prepare("SELECT w.*, GROUP_CONCAT(CONCAT(t.lang_code,':',COALESCE(t.meaning,'')) SEPARATOR '\n') as ts FROM words w LEFT JOIN translations t ON t.word_id=w.id WHERE w.hebrew LIKE ? OR w.transliteration LIKE ? OR t.meaning LIKE ? OR t.other_script LIKE ? GROUP BY w.id ORDER BY w.created_at DESC LIMIT 50");
    $stmt->execute(['%'.$q.'%','%'.$q.'%','%'.$q.'%','%'.$q.'%']);
    $rows=$stmt->fetchAll();
}

// Random card for learn mode
$card = fetch_random_card($pdo, $_GET['lang'] ?? null);
$msg = $_GET['msg'] ?? '';
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Hebrew Vocab App</title>
<link rel="stylesheet" href="styles.css">
</head><body>
<div class="container">
  <div class="header">
    <div class="nav">
      <a href="index.php">Learn</a>
      <a href="words.php">Admin: Words</a>
      <a href="import_csv.php">Bulk Import CSV</a>
    </div>
    <form method="get" action="index.php" class="flex">
      <input type="text" name="q" placeholder="Search..." value="<?=h($q)?>">
      <button class="btn">Search</button>
    </form>
  </div>

  <?php if($msg):?><div class="flash"><?=h($msg)?></div><?php endif; ?>

  <div class="card">
    <h2>Flashcard</h2>
    <?php if($card): ?>
      <div class="grid grid-2">
        <div>
          <div class="badge">Hebrew</div>
          <h1 style="margin:.2em 0 0.2em; font-size:42px;"><?=h($card['hebrew'])?></h1>
          <?php if($card['transliteration']): ?><div class="badge">Translit</div><div><?=h($card['transliteration'])?></div><?php endif; ?>
          <?php if($card['part_of_speech']): ?><div class="badge">Part of speech</div><div><?=h($card['part_of_speech'])?></div><?php endif; ?>
          <?php if($card['notes']): ?><div class="badge">Notes</div><div><?=nl2br(h($card['notes']))?></div><?php endif; ?>
          <?php if($card['audio_path']): ?>
            <audio class="audio" controls src="<?=h($card['audio_path'])?>"></audio>
          <?php endif; ?>
        </div>
        <div>
          <div class="badge">Translation</div>
          <p><strong>Lang:</strong> <?=h($card['lang_code']??'—')?>
          <br><strong>Other script:</strong> <?=h($card['other_script']??'—')?>
          <br><strong>Meaning:</strong> <?=h($card['meaning']??'—')?></p>
          <div class="flex">
            <a class="btn secondary" href="index.php">New Random</a>
            <a class="btn" href="index.php?lang=ru">RU</a>
            <a class="btn" href="index.php?lang=en">EN</a>
            <a class="btn" href="index.php?lang=ar">AR</a>
          </div>
        </div>
      </div>
    <?php else: ?>
      <p>No words yet. Add some below.</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Quick Add Word</h3>
    <form method="post" enctype="multipart/form-data" action="index.php?a=create_word">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <div class="grid grid-3">
        <div>
          <label>Hebrew *</label>
          <input required name="hebrew" placeholder="לְדֻגְמָה">
        </div>
        <div>
          <label>Transliteration</label>
          <input name="transliteration" placeholder="le‑dugma">
        </div>
        <div>
          <label>Part of speech</label>
          <input name="part_of_speech" placeholder="noun/verb/etc">
        </div>
      </div>
      <label>Notes</label>
      <textarea name="notes" rows="3" placeholder="Any nuances, gender, irregular forms..."></textarea>

      <label>Pronunciation (audio/mp3/wav/ogg ≤ 10MB)</label>
      <input type="file" name="audio" accept="audio/*">

      <div class="grid grid-3">
        <div>
          <label>Translation language</label>
          <input name="lang_code" placeholder="e.g., ru, en, fr">
        </div>
        <div>
          <label>Other script (spelling)</label>
          <input name="other_script" placeholder="пример / example">
        </div>
        <div>
          <label>Meaning (gloss)</label>
          <input name="meaning" placeholder="example / пример">
        </div>
      </div>
      <label>Example (optional)</label>
      <textarea name="example" rows="2" placeholder="Use in a sentence"></textarea>

      <div style="margin-top:12px"><button class="btn">Add</button></div>
    </form>
  </div>

  <?php if($q!=='' && $rows): ?>
  <div class="card">
    <h3>Search Results</h3>
    <table class="table">
      <thead><tr><th>Hebrew</th><th>Translit</th><th>POS</th><th>Translations</th><th>Audio</th><th></th></tr></thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?=h($r['hebrew'])?></td>
          <td><?=h($r['transliteration'])?></td>
          <td><?=h($r['part_of_speech'])?></td>
          <td><pre style="white-space:pre-wrap;margin:0;"><?=h($r['ts'])?></pre></td>
          <td><?php if($r['audio_path']): ?><audio controls src="<?=h($r['audio_path'])?>"></audio><?php endif; ?></td>
          <td><a class="btn secondary" href="edit_word.php?id=<?= (int)$r['id'] ?>">Edit</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
</body></html>
```
```

---

## 🛠️ File: `words.php` (Admin list)
```php
<?php require __DIR__ . '/config.php'; $csrf = ensure_token();

// Handle delete
if (isset($_POST['delete_id'])){ check_token(); $id=(int)$_POST['delete_id']; $pdo->prepare('DELETE FROM words WHERE id=?')->execute([$id]); redirect('words.php?msg='.urlencode('Deleted.')); }

// Fetch
$stmt = $pdo->query("SELECT w.*, COUNT(t.id) as tcount FROM words w LEFT JOIN translations t ON t.word_id=w.id GROUP BY w.id ORDER BY w.created_at DESC LIMIT 500");
$words = $stmt->fetchAll();
$msg = $_GET['msg'] ?? '';
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Words Admin</title>
<link rel="stylesheet" href="styles.css">
</head><body>
<div class="container">
  <div class="header">
    <div class="nav">
      <a href="index.php">← Back</a>
      <a href="import_csv.php">Bulk Import CSV</a>
    </div>
    <a class="btn" href="edit_word.php">+ New Word</a>
  </div>
  <?php if($msg):?><div class="flash"><?=h($msg)?></div><?php endif; ?>
  <div class="card">
    <h2>Words (<?=count($words)?>)</h2>
    <table class="table">
      <thead><tr><th>ID</th><th>Hebrew</th><th>Translit</th><th>POS</th><th>Translations</th><th>Audio</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach($words as $w): ?>
        <tr>
          <td><?= (int)$w['id'] ?></td>
          <td><?= h($w['hebrew']) ?></td>
          <td><?= h($w['transliteration']) ?></td>
          <td><?= h($w['part_of_speech']) ?></td>
          <td><span class="badge"><?= (int)$w['tcount'] ?> langs</span></td>
          <td><?= $w['audio_path']?'<audio controls src="'.h($w['audio_path']).'"></audio>':'—' ?></td>
          <td class="flex">
            <a class="btn secondary" href="edit_word.php?id=<?= (int)$w['id'] ?>">Edit</a>
            <form method="post" onsubmit="return confirm('Delete this word and all its translations?');">
              <input type="hidden" name="csrf" value="<?=h($csrf)?>">
              <input type="hidden" name="delete_id" value="<?= (int)$w['id'] ?>">
              <button class="btn danger">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</body></html>
```
```

---

## ✏️ File: `edit_word.php` (Create/Edit word + manage translations)
```php
<?php require __DIR__ . '/config.php'; $csrf = ensure_token();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (is_post()){
    check_token();
    $hebrew = trim($_POST['hebrew']??'');
    $transliteration = trim($_POST['transliteration']??'');
    $pos = trim($_POST['part_of_speech']??'');
    $notes = trim($_POST['notes']??'');

    // audio upload (optional)
    $audio_path = $_POST['existing_audio'] ?? null;
    if (!empty($_FILES['audio']['name'])){
        $ok = in_array(mime_content_type($_FILES['audio']['tmp_name']), ['audio/mpeg','audio/wav','audio/x-wav','audio/mp3','audio/ogg']);
        if ($ok && $_FILES['audio']['size'] <= 10*1024*1024){
            $ext = pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION);
            $fname = 'word_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . preg_replace('/[^a-z0-9]/i','',$ext);
            move_uploaded_file($_FILES['audio']['tmp_name'], $UPLOAD_DIR . '/' . $fname);
            $audio_path = 'uploads/' . $fname;
        }
    }

    if ($id>0){
        $stmt=$pdo->prepare("UPDATE words SET hebrew=?, transliteration=?, part_of_speech=?, notes=?, audio_path=? WHERE id=?");
        $stmt->execute([$hebrew,$transliteration,$pos,$notes,$audio_path,$id]);
        redirect('edit_word.php?id='.$id.'&msg='.urlencode('Saved'));
    } else {
        $stmt=$pdo->prepare("INSERT INTO words(hebrew, transliteration, part_of_speech, notes, audio_path) VALUES (?,?,?,?,?)");
        $stmt->execute([$hebrew,$transliteration,$pos,$notes,$audio_path]);
        $id=(int)$pdo->lastInsertId();
        redirect('edit_word.php?id='.$id.'&msg='.urlencode('Created'));
    }
}

// Add/Delete translation
if (isset($_POST['add_translation'])){
    check_token();
    $stmt=$pdo->prepare("INSERT INTO translations(word_id, lang_code, other_script, meaning, example) VALUES (?,?,?,?,?)");
    $stmt->execute([(int)$_POST['word_id'], trim($_POST['lang_code']), trim($_POST['other_script']), trim($_POST['meaning']), trim($_POST['example'])]);
    redirect('edit_word.php?id='.((int)$_POST['word_id']).'&msg='.urlencode('Translation added'));
}
if (isset($_POST['delete_translation'])){
    check_token();
    $pdo->prepare('DELETE FROM translations WHERE id=?')->execute([(int)$_POST['delete_translation']]);
    redirect('edit_word.php?id='.((int)$_POST['word_id']).'&msg='.urlencode('Translation deleted'));
}

// Load word & translations
$word = $id? (function($pdo,$id){$s=$pdo->prepare('SELECT * FROM words WHERE id=?');$s->execute([$id]);return $s->fetch();})($pdo,$id):null;
$trans = $id? (function($pdo,$id){$s=$pdo->prepare('SELECT * FROM translations WHERE word_id=? ORDER BY lang_code');$s->execute([$id]);return $s->fetchAll();})($pdo,$id):[];
$msg = $_GET['msg'] ?? '';
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $id? 'Edit Word #'.$id : 'New Word' ?></title>
<link rel="stylesheet" href="styles.css">
</head><body>
<div class="container">
  <div class="header">
    <div class="nav"><a href="words.php">← Back to list</a></div>
  </div>
  <?php if($msg):?><div class="flash"><?=h($msg)?></div><?php endif; ?>
  <div class="card">
    <h2><?= $id? 'Edit Word' : 'Create Word' ?></h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <div class="grid grid-3">
        <div><label>Hebrew *</label><input required name="hebrew" value="<?=h($word['hebrew']??'')?>"></div>
        <div><label>Transliteration</label><input name="transliteration" value="<?=h($word['transliteration']??'')?>"></div>
        <div><label>Part of speech</label><input name="part_of_speech" value="<?=h($word['part_of_speech']??'')?>"></div>
      </div>
      <label>Notes</label>
      <textarea name="notes" rows="3"><?=h($word['notes']??'')?></textarea>
      <label>Pronunciation (replace to upload new)</label>
      <input type="hidden" name="existing_audio" value="<?=h($word['audio_path']??'')?>">
      <input type="file" name="audio" accept="audio/*">
      <?php if(!empty($word['audio_path'])): ?><div class="audio"><audio controls src="<?=h($word['audio_path'])?>"></audio></div><?php endif; ?>
      <div style="margin-top:12px"><button class="btn">Save</button></div>
    </form>
  </div>

  <?php if($id): ?>
  <div class="card">
    <h3>Translations</h3>
    <table class="table"><thead><tr><th>Lang</th><th>Other script</th><th>Meaning</th><th>Example</th><th></th></tr></thead><tbody>
      <?php foreach($trans as $t): ?>
      <tr>
        <td><span class="badge"><?=h($t['lang_code'])?></span></td>
        <td><?=h($t['other_script'])?></td>
        <td><?=h($t['meaning'])?></td>
        <td><?=nl2br(h($t['example']))?></td>
        <td>
          <form method="post" onsubmit="return confirm('Delete translation?');" style="display:inline;">
            <input type="hidden" name="csrf" value="<?=h($csrf)?>">
            <input type="hidden" name="word_id" value="<?= (int)$id ?>">
            <input type="hidden" name="delete_translation" value="<?= (int)$t['id'] ?>">
            <button class="btn danger">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody></table>

    <h4>Add translation</h4>
    <form method="post" class="grid grid-3">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <input type="hidden" name="word_id" value="<?= (int)$id ?>">
      <input type="hidden" name="add_translation" value="1">
      <div><label>Language code</label><input name="lang_code" placeholder="ru/en/ar"></div>
      <div><label>Other script (spelling)</label><input name="other_script" placeholder="пример / example"></div>
      <div><label>Meaning (gloss)</label><input name="meaning" placeholder="meaning"></div>
      <div style="grid-column:1/-1"><label>Example</label><textarea name="example" rows="2"></textarea></div>
      <div><button class="btn">Add</button></div>
    </form>
  </div>
  <?php endif; ?>
</div>
</body></html>
```
```

---

## 📥 File: `import_csv.php` (Bulk import)
```php
<?php require __DIR__ . '/config.php'; $csrf = ensure_token();
$msg = $_GET['msg'] ?? '';

if (is_post() && isset($_POST['mode']) && $_POST['mode']==='import'){
    check_token();
    if (empty($_FILES['csv']['name'])) redirect('import_csv.php?msg='.urlencode('Please choose a CSV file.'));

    $tmp = $_FILES['csv']['tmp_name'];
    $fh = fopen($tmp, 'r');
    if (!$fh) redirect('import_csv.php?msg='.urlencode('Cannot read CSV.'));

    // Expected headers (order may vary): hebrew, transliteration, part_of_speech, notes, lang_code, other_script, meaning, example, audio_url
    $header = fgetcsv($fh);
    $map = array_flip($header);
    $required = ['hebrew'];
    foreach($required as $r){ if(!isset($map[$r])){ fclose($fh); redirect('import_csv.php?msg='.urlencode('Missing required column: '.$r)); }}

    $count=0; $pdo->beginTransaction();
    while(($row=fgetcsv($fh))!==false){
        $hebrew = trim($row[$map['hebrew']] ?? ''); if($hebrew==='') continue;
        $transliteration = trim($row[$map['transliteration']] ?? '');
        $pos = trim($row[$map['part_of_speech']] ?? '');
        $notes = trim($row[$map['notes']] ?? '');
        $lang = trim($row[$map['lang_code']] ?? '');
        $other_script = trim($row[$map['other_script']] ?? '');
        $meaning = trim($row[$map['meaning']] ?? '');
        $example = trim($row[$map['example']] ?? '');
        $audio_url = trim($row[$map['audio_url']] ?? '');
        $audio_path = null;

        // optional: if audio_url is a local path already in uploads/ keep it; we avoid remote downloads for security
        if ($audio_url && preg_match('~^uploads/[-a-zA-Z0-9_./]+$~', $audio_url)) {
            $audio_path = $audio_url;
        }

        $stmt=$pdo->prepare('INSERT INTO words(hebrew, transliteration, part_of_speech, notes, audio_path) VALUES (?,?,?,?,?)');
        $stmt->execute([$hebrew,$transliteration,$pos,$notes,$audio_path]);
        $word_id = (int)$pdo->lastInsertId();

        if ($lang!=='' || $meaning!=='' || $other_script!==''){
            $stmt2=$pdo->prepare('INSERT INTO translations(word_id, lang_code, other_script, meaning, example) VALUES (?,?,?,?,?)');
            $stmt2->execute([$word_id, $lang?:'und', $other_script?:null, $meaning?:null, $example?:null]);
        }
        $count++;
    }
    fclose($fh);
    $pdo->commit();
    redirect('import_csv.php?msg='.urlencode("Imported $count rows."));
}
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bulk Import CSV</title>
<link rel="stylesheet" href="styles.css">
</head><body>
<div class="container">
  <div class="header">
    <div class="nav"><a href="index.php">← Back</a> <a href="words.php">Words</a></div>
  </div>
  <?php if($msg):?><div class="flash"><?=h($msg)?></div><?php endif; ?>

  <div class="card">
    <h2>CSV Import</h2>
    <p>Upload a CSV with headers (comma‑separated):
      <code>hebrew, transliteration, part_of_speech, notes, lang_code, other_script, meaning, example, audio_url</code>
    </p>
    <details><summary>Download sample CSV</summary>
      <pre style="white-space:pre-wrap">hebrew,transliteration,part_of_speech,notes,lang_code,other_script,meaning,example,audio_url
שלום,shalom,noun,greeting,ru,привет,hello,"שלום! מה שלומך?", 
כלב,kelev,noun,masc.,ru,собака,dog,"הכלב רץ בפארק", 
לאכול,le'echol,verb,pa'al,en,eat,eat,"אני אוהב לאכול", 
      </pre>
    </details>

    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <input type="hidden" name="mode" value="import">
      <label>CSV file</label>
      <input type="file" name="csv" accept=".csv" required>
      <div style="margin-top:12px"><button class="btn">Import</button></div>
    </form>
  </div>
</div>
</body></html>
```
```

---

## 🔒 Optional: `.htaccess` (Apache pretty index protection)
```apache
Options -Indexes
<FilesMatch "\.(sql|ini|log)$">
Require all denied
</FilesMatch>
```
```

---

## 🚀 Quick Start
1) Save all files in one folder reachable by your PHP web server.
2) Create the DB: run `db.sql` in MySQL (phpMyAdmin, Adminer, or CLI).
3) Edit `config.php` DB credentials. Ensure folder `uploads/` is writable by the web server user.
4) Open `index.php` in the browser.
5) Use **Admin: Words** to manage entries; **Bulk Import CSV** for mass load.

### Notes
- Audio uploads are limited to **10MB**, accepted types: mp3/wav/ogg. Files stored in `/uploads` and linked for playback.
- Translations are modeled separately, so you can add many target languages per word.
- CSV importer expects at least `hebrew` column. You may leave others blank.
- Security basics included (prepared statements, simple CSRF). Add authentication if exposed publicly.

### Extending
- Add spaced‑repetition fields (`ease`, `next_review_at`) and a cron to schedule reviews.
- Add user accounts & progress tracking tables.
- Integrate text‑to‑speech to auto‑generate audio (server‑side job), if needed.
- Add export to CSV/Anki.

Good luck! 💪🇮🇱

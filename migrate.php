<?php
declare(strict_types=1);

// -----------------------------------------------------------------------------
// Database migration runner.
// Trigger via an authenticated POST request (X-Deploy-Token header) after code
// is deployed. The script discovers new SQL files inside ./migrations and
// executes them within transactions, recording applied filenames so each
// migration runs once.
// -----------------------------------------------------------------------------

require __DIR__ . '/config.php';

$deployToken = getenv('HEBREW_APP_DEPLOY_TOKEN') ?: 'CHANGE_ME_LONG_RANDOM';
$secretFile = __DIR__ . '/.deploy_secret.php';
if (is_file($secretFile)) {
    // Optional helper file that should return the secret token as a string.
    $tokenFromFile = include $secretFile;
    if (is_string($tokenFromFile) && $tokenFromFile !== '') {
        $deployToken = $tokenFromFile;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$headerToken = $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '';
if ($deployToken === 'CHANGE_ME_LONG_RANDOM' || $deployToken === '') {
    http_response_code(500);
    exit('Deployment token is not configured.');
}

if (!hash_equals($deployToken, $headerToken)) {
    http_response_code(403);
    exit('Invalid token');
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(190) UNIQUE,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
);

$alreadyRunStmt = $pdo->query('SELECT filename FROM migrations');
$alreadyRun = $alreadyRunStmt ? $alreadyRunStmt->fetchAll(PDO::FETCH_COLUMN) : [];
$alreadyRun = $alreadyRun ? array_flip($alreadyRun) : [];

$migrationsDir = __DIR__ . '/migrations';
if (!is_dir($migrationsDir)) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'migrations_ran' => 0]);
    exit;
}

$files = glob($migrationsDir . '/*.sql') ?: [];
natsort($files);

$ran = 0;

foreach ($files as $path) {
    $file = basename($path);
    if (isset($alreadyRun[$file])) {
        continue;
    }

    $sql = file_get_contents($path);
    if ($sql === false) {
        http_response_code(500);
        exit('Unable to read migration file: ' . $file);
    }

    $sql = trim($sql);
    if ($sql === '') {
        $insert = $pdo->prepare('INSERT INTO migrations(filename) VALUES (?)');
        $insert->execute([$file]);
        $ran++;
        continue;
    }

    try {
        $pdo->beginTransaction();

        $statements = preg_split('/;\s*(?:\n|$)/s', $sql);
        if ($statements === false) {
            throw new RuntimeException('Failed to parse SQL statements.');
        }

        foreach ($statements as $statementSql) {
            $statementSql = trim($statementSql);
            if ($statementSql === '') {
                continue;
            }
            $pdo->exec($statementSql);
        }

        $insert = $pdo->prepare('INSERT INTO migrations(filename) VALUES (?)');
        $insert->execute([$file]);

        $pdo->commit();
        $ran++;
    } catch (Throwable $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo 'Failed on ' . htmlspecialchars($file, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ': ' .
            htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        exit;
    }
}

header('Content-Type: application/json');
echo json_encode(['status' => 'ok', 'migrations_ran' => $ran]);

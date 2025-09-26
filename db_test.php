<?php require __DIR__ . '/config.php';
echo "Connected!\n";
$r = $pdo->query("SHOW TABLES")->fetchAll();
var_dump($r);

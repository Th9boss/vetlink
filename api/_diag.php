<?php
// api/_diag.php
declare(strict_types=1);

// ==== Bootstrap fatal/parse catcher (avant toute inclusion) ====
header('Content-Type: application/json; charset=utf-8');
ob_start();
ini_set('display_errors','0');
set_error_handler(function($severity,$message,$file,$line){
  if (!(error_reporting() & $severity)) return false;
  throw new ErrorException($message, 0, $severity, $file, $line);
});
register_shutdown_function(function(){
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR])) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'fatal'=>true,'message'=>$e['message'],'file'=>$e['file'],'line'=>$e['line']]);
  }
  ob_end_flush();
});

// ==== Inclusions dans l’ordre sûr ====
require_once __DIR__ . '/../config/env.php';      // si nécessaire
require_once __DIR__ . '/../config/config.php';   // si nécessaire
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

$info = [
  'php'        => PHP_VERSION,
  'extensions' => [
    'pdo'         => extension_loaded('pdo'),
    'pdo_mysql'   => extension_loaded('pdo_mysql'),
    'intl'        => extension_loaded('intl'),
    'mbstring'    => extension_loaded('mbstring'),
    'json'        => extension_loaded('json'),
  ],
];

try {
  $pdo = db();
  $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
  $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
  $one = $pdo->query('SELECT 1')->fetchColumn();

  // test tables clés
  $tables = [];
  foreach (['clients','patients','rappels','interventions'] as $t) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $stmt->execute([$dbName, $t]);
    $tables[$t] = (bool)$stmt->fetchColumn();
  }

  // sample query très simple
  $sample = [];
  if ($tables['clients']) {
    $sample['clients_top1'] = $pdo->query("SELECT id, prenom, nom FROM clients ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
  }

  echo json_encode([
    'ok' => true,
    'db' => ['name'=>$dbName, 'version'=>$ver, 'select1'=>(int)$one === 1],
    'tables' => $tables,
    'sample' => $sample,
    'env' => $info
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server_error','message'=>$e->getMessage()]);
}
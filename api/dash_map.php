<?php
// api/dash_map.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!function_exists('is_logged_in')) { echo json_encode(['__error'=>true,'msg'=>'auth bootstrap']); exit; }
if (!is_logged_in()) { http_response_code(401); echo json_encode(['__error'=>true,'msg'=>'unauth']); exit; }

// FIX: SHOW TABLES LIKE sans bind param
function table_exists(PDO $pdo, string $t): bool {
  $tq  = $pdo->quote($t);
  $sql = "SHOW TABLES LIKE $tq";
  $st  = $pdo->query($sql);
  return (bool)($st ? $st->fetchColumn() : false);
}

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $points = [];
  if (table_exists($pdo,'clients')) {
    // Colonnes exactes de ta structure : gps_lat, gps_lng, adresse, prenom, nom, gsm
    $sql = "
      SELECT `id`, `prenom`, `nom`, `gsm`, `adresse`, `gps_lat`, `gps_lng`
      FROM `clients`
      WHERE `gps_lat` IS NOT NULL AND `gps_lng` IS NOT NULL
      LIMIT 1000
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
      $points[] = [
        'id'      => (int)$r['id'],
        'client'  => trim(($r['prenom'] ?? '').' '.($r['nom'] ?? '')),
        'lat'     => (float)$r['gps_lat'],
        'lng'     => (float)$r['gps_lng'],
        'gsm'     => $r['gsm'] ?? null,
        'address' => $r['adresse'] ?? null,
      ];
    }
  }

  echo json_encode(['points'=>$points], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(200);
  echo json_encode(['__error'=>true,'msg'=>$e->getMessage()]);
}
<?php
// api/dash_charts.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!function_exists('is_logged_in')) { echo json_encode(['__error'=>true,'msg'=>'auth bootstrap']); exit; }
if (!is_logged_in()) { http_response_code(401); echo json_encode(['__error'=>true,'msg'=>'unauth']); exit; }

// FIX: SHOW TABLES LIKE ne supporte pas les bind params => quote + query
function table_exists(PDO $pdo, string $t): bool {
  $tq  = $pdo->quote($t);
  $sql = "SHOW TABLES LIKE $tq";
  $st  = $pdo->query($sql);
  return (bool)($st ? $st->fetchColumn() : false);
}

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $year   = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
  $metric = $_GET['metric'] ?? 'consults'; // 'consults' | 'revenue'
  $series = array_fill(0, 12, 0);

  if ($metric === 'revenue') {
    // CA par mois depuis factures.date_facture, somme total_ttc
    if (table_exists($pdo, 'factures')) {
      $sql = "SELECT MONTH(`date_facture`) AS m, COALESCE(SUM(`total_ttc`),0) AS s
              FROM `factures`
              WHERE YEAR(`date_facture`) = ?
              GROUP BY m";
      $st = $pdo->prepare($sql);
      $st->execute([$year]);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($rows as $r) {
        $m = (int)$r['m'];
        if ($m >= 1 && $m <= 12) $series[$m-1] = (float)$r['s'];
      }
    }
  } else {
    // Consultations par mois depuis consultations.date_consult, count(*)
    if (table_exists($pdo, 'consultations')) {
      $sql = "SELECT MONTH(`date_consult`) AS m, COUNT(*) AS c
              FROM `consultations`
              WHERE YEAR(`date_consult`) = ?
              GROUP BY m";
      $st = $pdo->prepare($sql);
      $st->execute([$year]);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($rows as $r) {
        $m = (int)$r['m'];
        if ($m >= 1 && $m <= 12) $series[$m-1] = (int)$r['c'];
      }
    }
  }

  echo json_encode([
    'year'   => $year,
    'metric' => $metric,
    'series' => $series
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(200);
  echo json_encode(['__error'=>true,'msg'=>$e->getMessage()]);
}
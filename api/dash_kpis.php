<?php
// api/dash_kpis.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!function_exists('is_logged_in')) { echo json_encode(['__error'=>true,'msg'=>'auth bootstrap']); exit; }
if (!is_logged_in()) { http_response_code(401); echo json_encode(['__error'=>true,'msg'=>'unauth']); exit; }

// SHOW TABLES LIKE ne supporte pas les bind params -> quote + query
function table_exists(PDO $pdo, string $t): bool {
  $tq  = $pdo->quote($t);
  $sql = "SHOW TABLES LIKE $tq";
  $st  = $pdo->query($sql);
  return (bool)($st ? $st->fetchColumn() : false);
}

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // ---------------- Actes non facturés ----------------
  // Tables avec colonne `invoiced`: consultations, analyses, imageries, interventions
  $actsTables = ['consultations','analyses','imageries','interventions'];
  $acts_total = 0;
  $acts_non   = 0;

  foreach ($actsTables as $tab) {
    if (!table_exists($pdo, $tab)) continue;

    $total = (int)($pdo->query("SELECT COUNT(*) FROM `$tab`")->fetchColumn() ?? 0);
    $non   = (int)($pdo->query("SELECT COUNT(*) FROM `$tab` WHERE `invoiced` IS NULL OR `invoiced`=0")->fetchColumn() ?? 0);

    $acts_total += $total;
    $acts_non   += $non;
  }

  $acts_pct = $acts_total > 0 ? round(($acts_non * 100.0) / $acts_total, 1) : 0.0;

  // ---------------- Paiements / Impayés ----------------
  // Paiements = SUM(factures.payement) / SUM(factures.total_ttc)
  // Impayés   = 100 - Paiements
  $paid_pct = 0.0;
  $unpaid_pct = 0.0;
  $paid_text = 'Aucune facture';

  if (table_exists($pdo, 'factures')) {
    $sumTotal = (float)($pdo->query("SELECT COALESCE(SUM(`total_ttc`),0) FROM `factures`")->fetchColumn() ?? 0);
    $sumPaid  = (float)($pdo->query("SELECT COALESCE(SUM(`payement`),0) FROM `factures`")->fetchColumn() ?? 0);

    $paid_pct   = $sumTotal > 0 ? min(100, max(0, $sumPaid * 100.0 / $sumTotal)) : 0.0;
    $unpaid_pct = $sumTotal > 0 ? round(100 - $paid_pct, 1) : 0.0;
    $paid_pct   = round($paid_pct, 1);

    $paid_text = sprintf("Payé %.0f / Total %.0f", $sumPaid, $sumTotal);
  }

  echo json_encode([
    'acts'=>[
      'non_invoiced_pct' => $acts_pct,
      'text'             => sprintf('%d non facturés / %d actes', $acts_non, $acts_total),
      'label'            => 'Non facturés'
    ],
    'payment'=>[
      'paid_pct'  => $paid_pct,
      'unpaid_pct'=> $unpaid_pct, // utilisé par le front pour le speedomètre Impayés
      'text'      => $paid_text,
      'label'     => 'Paiements'
    ]
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(200);
  echo json_encode(['__error'=>true,'msg'=>$e->getMessage()]);
}
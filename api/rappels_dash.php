<?php
// api/rappels_dash.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

// Auth stricte JSON
if (!function_exists('is_logged_in')) { echo json_encode(['error'=>'auth bootstrap error']); exit; }
if (!is_logged_in()) { http_response_code(401); echo json_encode(['error'=>'unauthenticated']); exit; }

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $today  = new DateTimeImmutable('today');

  // --- Fenêtre: même logique que interventions ---
  $window = $_GET['window'] ?? null; // today | tomorrow | 15 | 30
  if ($window) {
    if ($window === 'today') {
      $d1 = $today->format('Y-m-d'); $d2 = $d1;
    } elseif ($window === 'tomorrow') {
      $t = $today->modify('+1 day'); $d1 = $t->format('Y-m-d'); $d2 = $d1;
    } elseif ($window === '15') {
      $d1 = $today->format('Y-m-d'); $d2 = $today->modify('+15 day')->format('Y-m-d');
    } elseif ($window === '30') {
      $d1 = $today->format('Y-m-d'); $d2 = $today->modify('+30 day')->format('Y-m-d');
    } else {
      $d1 = $today->format('Y-m-d'); $d2 = $today->modify('+1 day')->format('Y-m-d');
    }
  } else {
    // Ancien mode "paires" (par défaut: aujourd’hui & demain)
    $startOffset = isset($_GET['start_offset']) ? (int)$_GET['start_offset'] : 0;
    $count       = isset($_GET['count']) ? max(1, min(10, (int)$_GET['count'])) : 2;
    $base = $today;
    $d1   = $base->modify($startOffset . ' day')->format('Y-m-d');
    $d2   = $base->modify(($startOffset + $count - 1) . ' day')->format('Y-m-d');
  }

  // --- Pagination ---
  $perPage = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : 10;
  $page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
  $offset  = ($page - 1) * $perPage;

  // --- clients.gsm ? (optionnel) ---
  $clientHasGsm = false;
  foreach ($pdo->query("SHOW COLUMNS FROM `clients`") as $col) {
    if (strtolower($col['Field']) === 'gsm') { $clientHasGsm = true; break; }
  }
  $gsmSelect = $clientHasGsm ? "c.`gsm` AS client_gsm," : "NULL AS client_gsm,";

  // --- Totaux pour pagination et barre ---
  $sqlTotal = "
    SELECT COUNT(*) FROM `rappels` r
    JOIN `patients` p ON p.`id` = r.`patient_id`
    JOIN `clients`  c ON c.`id` = p.`client_id`
    WHERE DATE(r.`date_rappel`) BETWEEN ? AND ?
  ";
  $stT = $pdo->prepare($sqlTotal);
  $stT->execute([$d1, $d2]);
  $total = (int)$stT->fetchColumn();
  $windowCount = $total; // charge de la fenêtre

  // --- Page de résultats ---
  $sql = "
    SELECT
      r.`id`,
      DATE(r.`date_rappel`)            AS r_date,
      r.`type`                         AS r_type,
      r.`statut`                       AS r_statut,
      r.`canal`                        AS r_canal,
      r.`meta`                         AS r_meta,
      r.`patient_id`,
      p.`nom`                          AS patient_nom,
      $gsmSelect
      c.`id`                           AS client_id,
      c.`prenom`                       AS client_prenom,
      c.`nom`                          AS client_nom
    FROM `rappels` r
    JOIN `patients` p ON p.`id` = r.`patient_id`
    JOIN `clients`  c ON c.`id` = p.`client_id`
    WHERE DATE(r.`date_rappel`) BETWEEN ? AND ?
    ORDER BY r.`date_rappel` ASC, r.`id` ASC
    LIMIT ? OFFSET ?
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(1, $d1);
  $stmt->bindValue(2, $d2);
  $stmt->bindValue(3, $perPage, PDO::PARAM_INT);
  $stmt->bindValue(4, $offset,  PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // --- Formatage items (NOTE extraite depuis meta) ---
  $items = [];
  foreach ($rows as $row) {
    $ymd = (string)$row['r_date'];

    $noteText = null;
    if (!empty($row['r_meta'])) {
      $tmp = json_decode($row['r_meta'], true);
      if (json_last_error() === JSON_ERROR_NONE) {
        foreach (['note','notes','message','remarque'] as $k) {
          if (!empty($tmp[$k])) { $noteText = (string)$tmp[$k]; break; }
        }
        if (!$noteText) { // première string
          foreach ($tmp as $v) { if (is_string($v) && trim($v)!==''){ $noteText = $v; break; } }
        }
      } else {
        $noteText = (string)$row['r_meta'];
      }
    }

    $items[] = [
      'id'          => (int)$row['id'],
      'date'        => $ymd,
      'date_human'  => $ymd, // le front gère l'affichage human
      'type'        => $row['r_type'] ?? null,
      'statut'      => $row['r_statut'] ?? null,
      'canal'       => $row['r_canal'] ?? null,
      'note'        => $noteText, // <<< uniquement la note
      'patient_id'  => (int)$row['patient_id'],
      'patient'     => (string)($row['patient_nom'] ?? ''),
      'client_id'   => (int)$row['client_id'],
      'client'      => trim(($row['client_prenom'] ?? '') . ' ' . ($row['client_nom'] ?? '')),
      'client_gsm'  => $row['client_gsm'] ?? null,
    ];
  }

  echo json_encode([
    'items'        => $items,
    'total'        => $total,
    'page'         => $page,
    'per_page'     => $perPage,
    'window_count' => $windowCount
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'server_error','message'=>$e->getMessage()]);
}
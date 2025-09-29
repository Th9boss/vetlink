<?php
// api/interventions_dash.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

if (!function_exists('is_logged_in')) { echo json_encode(['error'=>'auth bootstrap error']); exit; }
if (!is_logged_in()) { http_response_code(401); echo json_encode(['error'=>'unauthenticated']); exit; }

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Fenêtres : today | tomorrow | 15 | 30
  $window = $_GET['window'] ?? 'today';
  $today  = new DateTimeImmutable('today');
  $start  = $today; $end = $today;
  if ($window === 'tomorrow') { $start = $today->modify('+1 day'); $end = $start; }
  elseif ($window === '15')   { $end   = $today->modify('+15 day'); }
  elseif ($window === '30')   { $end   = $today->modify('+30 day'); }

  $startYmd = $start->format('Y-m-d');
  $endYmd   = $end->format('Y-m-d');
  $todayYmd = $today->format('Y-m-d');

  // Pagination
  $perPage = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : 10;
  $page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

  // clients.gsm ?
  $clientHasGsm = false;
  foreach ($pdo->query("SHOW COLUMNS FROM `clients`") as $col) {
    if (strtolower($col['Field']) === 'gsm') { $clientHasGsm = true; break; }
  }
  $gsmSelect = $clientHasGsm ? "c.`gsm` AS client_gsm," : "NULL AS client_gsm,";

  // Utils récurrences
  $addMonths = function(string $ymd, int $m): ?string {
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $ymd); if(!$d) return null;
    return $d->modify(($m>=0?'+':'') . $m . ' month')->format('Y-m-d');
  };
  $extractMonths = function(?string $txt): int {
    if (!$txt) return 0;
    if (preg_match('/(\d{1,3})/', $txt, $m)) return max(0, (int)$m[1]);
    return 0;
  };

  // Charger brut (patients.nom ; clients.prenom + nom [+ gsm])
  $sql = "
    SELECT
      i.`id`,
      DATE(i.`date_intervention`) AS d_interv,
      i.`prochaine_echeance`,
      i.`deuxieme_echeance`,
      i.`recurrence`,
      i.`type_acte`,
      i.`details`,
      i.`statut_rappel`,
      i.`patient_id`,
      p.`nom`                    AS patient_nom,
      $gsmSelect
      c.`id`                     AS client_id,
      c.`prenom`                 AS client_prenom,
      c.`nom`                    AS client_nom
    FROM `interventions` i
    JOIN `patients` p ON p.`id` = i.`patient_id`
    JOIN `clients`  c ON c.`id` = p.`client_id`
    ORDER BY i.`id` DESC
    LIMIT 2000
  ";
  $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // Filtrer / calculer prochaine occurrence
  $itemsAll = [];
  foreach ($rows as $r) {
    $first      = $r['d_interv'] ?: null;
    $second     = $r['deuxieme_echeance'] ?: null;
    $nextField  = $r['prochaine_echeance'] ?: null;
    $recMonths  = $extractMonths($r['recurrence'] ?? null);

    $next = null;
    if ($nextField && $nextField >= $todayYmd) {
      $next = $nextField;
    } else {
      if ($second && $first && $first < $todayYmd && $second >= $todayYmd) {
        if ($recMonths > 0) {
          $occ = $second;
          while ($occ < $todayYmd) {
            $n = $addMonths($occ, $recMonths);
            if (!$n || $n === $occ) break;
            $occ = $n;
          }
          $next = $occ;
        } else {
          $next = $second;
        }
      } else {
        if ($first) {
          $occ = $first;
          if ($recMonths > 0) {
            while ($occ < $todayYmd) {
              $n = $addMonths($occ, $recMonths);
              if (!$n || $n === $occ) break;
              $occ = $n;
            }
          }
          $next = $occ >= $todayYmd ? $occ : null;
        }
      }
    }

    if (!$next) continue;
    if ($next < $startYmd || $next > $endYmd) continue;

    $itemsAll[] = [
      'id'              => (int)$r['id'],
      'prochaine_date'  => $next,
      'prochaine_human' => $next, // front formate
      'label'           => $r['type_acte'] ?? 'Intervention',
      'acte'            => $r['type_acte'] ?? null,
      'patient_id'      => (int)$r['patient_id'],
      'patient'         => (string)($r['patient_nom'] ?? ''),
      'client_id'       => (int)$r['client_id'],
      'client'          => trim(($r['client_prenom'] ?? '') . ' ' . ($r['client_nom'] ?? '')),
      'client_gsm'      => $r['client_gsm'] ?? null,
      'statut'          => $r['statut_rappel'] ?? null,
      'note'            => $r['details'] ?? null,
    ];
  }

  // Tri + pagination
  usort($itemsAll, fn($a,$b)=> $a['prochaine_date'] === $b['prochaine_date']
      ? strcmp($a['patient'] ?? '', $b['patient'] ?? '')
      : strcmp($a['prochaine_date'], $b['prochaine_date']));

  $total = count($itemsAll);
  $items = array_slice($itemsAll, ($page-1)*$perPage, $perPage);

  echo json_encode([
    'items'        => array_values($items),
    'total'        => $total,
    'page'         => $page,
    'per_page'     => $perPage,
    'window_count' => $total // charge de la fenêtre courante
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'server_error','message'=>$e->getMessage()]);
}
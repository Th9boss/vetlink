<?php
// api/dash_meta.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!function_exists('is_logged_in')) { echo json_encode(['__error'=>true,'msg'=>'auth bootstrap']); exit; }
if (!is_logged_in()) { http_response_code(401); echo json_encode(['__error'=>true,'msg'=>'unauth']); exit; }

/** Helpers **/
function table_exists(PDO $pdo, string $t): bool {
  $tq  = $pdo->quote($t);
  $sql = "SHOW TABLES LIKE $tq";
  $st  = $pdo->query($sql);
  return (bool)($st ? $st->fetchColumn() : false);
}
function has_col(PDO $pdo, string $t, string $c): bool {
  $cq  = $pdo->quote($c);
  $sql = "SHOW COLUMNS FROM `$t` LIKE $cq";
  $st  = $pdo->query($sql);
  return (bool)($st ? $st->fetchColumn() : false);
}
function pick_col(PDO $pdo, string $t, array $cands, ?string $fallback=null): ?string {
  foreach ($cands as $c) if (has_col($pdo,$t,$c)) return $c;
  return $fallback;
}

try{
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  /** ---------------- Clinic depuis `config` ---------------- */
  $clinic = ['name'=>null,'logo_url'=>null];
  if (table_exists($pdo,'config')) {
    // Structure attendue: nom, logo_img (avec fallback au cas où)
    $nameCol = pick_col($pdo,'config',['nom','name','clinic_name','raison_sociale']);
    $logoCol = pick_col($pdo,'config',['logo_img','logo_url','logo','logo_path','image']);
    if ($nameCol || $logoCol) {
      $select = [];
      if ($nameCol) $select[] = "`$nameCol` AS _name";
      if ($logoCol) $select[] = "`$logoCol` AS _logo";
      $sql = "SELECT ".implode(',',$select)." FROM `config` ORDER BY `id` ASC LIMIT 1";
      $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: null;
      if ($row) {
        $clinic['name']     = $row['_name'] ?? null;
        $clinic['logo_url'] = $row['_logo'] ?? null;
      }
    }
  }

  /** ---------------- User (via session / helper) ---------------- */
  $user = ['name'=>null,'role'=>null];
  $u = function_exists('current_user') ? current_user() : ($_SESSION['user'] ?? null);
  if (is_array($u)) {
    $user['role'] = $u['role'] ?? ($u['type'] ?? null); // 'ADMIN' | 'DOCTOR' | ...
    // la structure users a bien prenom/nom
    $user['name'] = trim(($u['prenom'] ?? '').' '.($u['nom'] ?? '')) ?: ($u['name'] ?? null);
  }

  /** ---------------- Dernières 5 consultations ----------------
   * Structure DB : consultations.date_consult (datetime), consultations.patient_id,
   * patients.nom, clients.{prenom,nom,gsm}
   * On tolère alias: id_patient / date_consultation / objet ...
   */
  $list = [];
  if (table_exists($pdo,'consultations')) {

    $co_pid   = pick_col($pdo,'consultations',['patient_id','id_patient']);
    $co_date  = pick_col($pdo,'consultations',['date_consult','date_consultation','date','created_at']);
    $co_motif = pick_col($pdo,'consultations',['motif','objet','note','notes']);

    // patients
    $hasPatients = table_exists($pdo,'patients');
    $p_name = $hasPatients ? pick_col($pdo,'patients',['nom','name']) : null;

    // clients
    $hasClients = table_exists($pdo,'clients');
    $c_first = $hasClients ? pick_col($pdo,'clients',['prenom','first_name','firstname']) : null;
    $c_last  = $hasClients ? pick_col($pdo,'clients',['nom','last_name','lastname']) : null;
    $c_gsm   = $hasClients ? pick_col($pdo,'clients',['gsm','phone','tel','telephone','mobile']) : null;

    // Sélection dynamique
    $sel = ["co.`id`"];
    if ($co_pid)   $sel[] = "co.`$co_pid` AS patient_id";
    if ($co_date)  $sel[] = "co.`$co_date` AS dte";
    if ($co_motif) $sel[] = "co.`$co_motif` AS motif";
    if ($p_name)   $sel[] = "p.`$p_name` AS patient_nom";
    if ($c_first)  $sel[] = "c.`$c_first` AS client_prenom";
    if ($c_last)   $sel[] = "c.`$c_last`  AS client_nom";
    if ($c_gsm)    $sel[] = "c.`$c_gsm`   AS client_gsm";

    // LEFT JOIN pour ne pas filtrer si client manquant
    $sql =
      "SELECT ".implode(', ',$sel)."
       FROM `consultations` co
       ".($hasPatients && $co_pid ? "LEFT JOIN `patients` p ON p.`id`=co.`$co_pid`" : "")."
       ".($hasClients && $hasPatients ? "LEFT JOIN `clients`  c ON c.`id`=p.`client_id`" : "")."
       ORDER BY ".($co_date ? "co.`$co_date` DESC, " : "")." co.`id` DESC
       LIMIT 5";

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r){
      $list[] = [
        'id'          => (int)$r['id'],
        'patient_id'  => isset($r['patient_id']) ? (int)$r['patient_id'] : null,
        'patient'     => (string)($r['patient_nom'] ?? ''),
        'client'      => trim(($r['client_prenom'] ?? '').' '.($r['client_nom'] ?? '')),
        'client_gsm'  => $r['client_gsm'] ?? null,
        'motif'       => $r['motif'] ?? null,
        'date'        => $r['dte'] ?? null,
        'date_human'  => !empty($r['dte']) ? date('d/m/Y', strtotime((string)$r['dte'])) : null,
      ];
    }
  }

  echo json_encode([
    'clinic'=>$clinic,
    'user'=>$user,
    'last_consultations'=>$list
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

}catch(Throwable $e){
  http_response_code(200);
  echo json_encode(['__error'=>true,'msg'=>$e->getMessage()]);
}
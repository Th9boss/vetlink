<?php
// api/ordonnances.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

/* ============ Dompdf loader ============ */
function try_autoload_dompdf(): bool {
    if (class_exists('\Dompdf\Dompdf')) return true;
    foreach ([
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/../assets/vendor/autoload.php',
        __DIR__ . '/../vendor/dompdf/dompdf/autoload.inc.php',
    ] as $cand) {
        if (is_file($cand)) { require_once $cand; if (class_exists('\Dompdf\Dompdf')) return true; }
    }
    return false;
}
$DOMPDF_READY = try_autoload_dompdf();

/* ============ JSON helpers ============ */
function json_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status'=>'error','message'=>$msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function ok_json(array $payload): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============ Path helpers ============ */
function norm_rel_upload(?string $p): ?string {
    if (!$p) return null;
    $p = str_replace('\\','/', trim($p));
    $idx = stripos($p, 'uploads/');
    if ($idx !== false) $p = substr($p, $idx);
    $p = ltrim($p, '/');
    return preg_match('~^uploads/~i', $p) ? $p : null;
}
function abs_path_from_rel(?string $rel): ?string {
    if (!$rel) return null;
    $root = realpath(__DIR__ . '/..');
    if (!$root) return null;
    $abs = str_replace('\\','/',$root.'/'.ltrim($rel,'/'));
    return is_file($abs) ? $abs : null;
}
function fmt_age(?string $birth): string {
    if (!$birth || $birth==='0000-00-00') return '—';
    try {
        $d1 = new DateTime($birth); $d2 = new DateTime(); $d=$d1->diff($d2);
        return $d->y>0 ? ($d->y.' ans'.($d->m>0?' '.$d->m.' mois':'')) : ($d->m.' mois');
    } catch (\Throwable $e) { return '—'; }
}
function fmt_date_human(?string $dt): string {
    if (!$dt) return '—'; $ts = strtotime($dt); return $ts?date('d/m/Y',$ts):'—';
}
function fmt_weight(?float $weight): string {
    if ($weight === null) return '';
    $text = number_format($weight, 2, ',', '');
    $text = rtrim(rtrim($text, '0'), ',');
    return $text . ' Kg';
}

/* ============ DB access ============ */
function fetch_config(){ $st=db()->query("SELECT * FROM config ORDER BY id ASC LIMIT 1"); return $st->fetch() ?: []; }
function fetch_user_by_id(?int $uid){ if(!$uid)return null; $st=db()->prepare("SELECT * FROM users WHERE id=?"); $st->execute([$uid]); return $st->fetch() ?: null; }
function fetch_last_weight_for_patient(int $pid): ?float { $st=db()->prepare("SELECT poids FROM consultations WHERE patient_id=? AND poids IS NOT NULL ORDER BY date_consult DESC, id DESC LIMIT 1"); $st->execute([$pid]); $w=$st->fetchColumn(); return $w!==false?(float)$w:null; }
function fullname(?string $a, ?string $b): string { $a=trim((string)$a); $b=trim((string)$b); $x=trim($a.' '.$b); return $x!==''?$x:'—'; }

/* ============ Inputs ============ */
$type   = isset($_GET['type']) ? strtolower(trim((string)$_GET['type'])) : '';
$id     = isset($_GET['id'])   ? (int)$_GET['id'] : 0;
$format = isset($_GET['format']) ? strtolower(trim((string)$_GET['format'])) : 'json';
if (!in_array($type, ['consultation','intervention'], true)) json_fail("Paramètre 'type' invalide.");
if ($id<=0) json_fail("Paramètre 'id' invalide.");

/* ============ Load data ============ */
$cfg = fetch_config();
$clinic_name     = $cfg['nom'] ?? '';
$clinic_logo_rel = norm_rel_upload($cfg['logo_img'] ?? null);
$clinic_tel      = $cfg['tel'] ?? '';
$clinic_mail     = $cfg['email'] ?? '';
$clinic_addr     = $cfg['adresse'] ?? '';

$ord_date = null; $animal_nom='—'; $animal_espece='—'; $animal_age='—'; $animal_poids='—'; $ord_content=''; $owner_name='—';
$cachet_rel=null; $sign_rel=null;

if ($type==='consultation') {
    $q=db()->prepare("SELECT c.*, p.id pid,p.nom p_nom,p.espece p_espece,p.date_naissance p_birth,p.client_id,
                             cl.nom cl_nom, cl.prenom cl_prenom,
                             u.id u_id, u.cachet_img, u.signature_img
                      FROM consultations c
                      JOIN patients p ON p.id=c.patient_id
                      LEFT JOIN clients cl ON cl.id=p.client_id
                      LEFT JOIN users u ON u.id=c.praticien_id
                      WHERE c.id=? LIMIT 1");
    $q->execute([$id]); $row=$q->fetch(); if(!$row) json_fail("Consultation introuvable.",404);
    $animal_nom=$row['p_nom']?:'—'; $animal_espece=$row['p_espece']?:'—'; $animal_age=fmt_age($row['p_birth']??null);
    $owner_name=fullname($row['cl_nom']??null,$row['cl_prenom']??null);
    $ord_date=$row['date_consult']??null; $ord_content=trim((string)($row['traitement']??''));
    if ($row['poids']!==null && $row['poids']!=='') $animal_poids=fmt_weight((float)$row['poids']);
    $cachet_rel=norm_rel_upload($row['cachet_img']??null); $sign_rel=norm_rel_upload($row['signature_img']??null);
} else {
    $q=db()->prepare("SELECT i.*, p.id pid,p.nom p_nom,p.espece p_espece,p.date_naissance p_birth,p.client_id,
                             cl.nom cl_nom, cl.prenom cl_prenom
                      FROM interventions i
                      JOIN patients p ON p.id=i.patient_id
                      LEFT JOIN clients cl ON cl.id=p.client_id
                      WHERE i.id=? LIMIT 1");
    $q->execute([$id]); $row=$q->fetch(); if(!$row) json_fail("Intervention introuvable.",404);
    $animal_nom=$row['p_nom']?:'—'; $animal_espece=$row['p_espece']?:'—'; $animal_age=fmt_age($row['p_birth']??null);
    $owner_name=fullname($row['cl_nom']??null,$row['cl_prenom']??null);
    $ord_date=$row['date_intervention'] ?? ($row['created_at'] ?? null);
    $ord_content=trim((string)($row['details']??''));
    $w=fetch_last_weight_for_patient((int)$row['pid']); if($w!==null) $animal_poids=fmt_weight($w);
    $cu=current_user(); if($cu && !empty($cu['id'])){ $u=fetch_user_by_id((int)$cu['id']); $cachet_rel=norm_rel_upload($u['cachet_img']??null); $sign_rel=norm_rel_upload($u['signature_img']??null); }
}
if ($animal_poids==='—') $animal_poids='';
$display_date = fmt_date_human($ord_date);

/* ============ HTML parts (aperçu) ============ */
$logo_html   = $clinic_logo_rel ? '<img src="'.h($clinic_logo_rel).'" alt="logo" />' : '';
$cachet_html = $cachet_rel ? '<img class="cachet" src="'.h($cachet_rel).'" alt="cachet">' : '';
$sign_html   = $sign_rel   ? '<img class="signature" src="'.h($sign_rel).'" alt="signature">' : '';
$trait_html  = $ord_content!=='' ? nl2br(h($ord_content)) : '<span class="text-muted">—</span>';

$subline = trim(
  ($clinic_addr ? $clinic_addr : '') .
  ($clinic_addr && $clinic_tel ? ' · ' : '') .
  ($clinic_tel ? $clinic_tel : '')
);

$clinic_block = '
  <div class="ord-header">
    <div class="logo">'.$logo_html.'</div>
    <div class="ord-hclinic">
      <div class="name">'.h($clinic_name ?: 'Clinique vétérinaire').'</div>
      <div class="submeta">'.h($subline).'</div>
    </div>
    <div class="ord-date">
      <div class="label">Date</div>
      <div class="value">'.h($display_date).'</div>
    </div>
  </div>
';

$patient_block = '
  <div class="ord-grid">
    <div><b>Pour :</b> '.h($animal_espece).'</div>
    <div></div>
    <div><b>Nom animal :</b> '.h($animal_nom).'</div>
    <div><b>Poids :</b> '.h($animal_poids).'</div>
    <div><b>Âge :</b> '.h($animal_age).'</div>
    <div></div>
  </div>
';
$footer_legal = '
  <div id="ord-footer" class="ord-footer">
    <div class="legal">
      <div class="sep"></div>
      <div><b>Propriétaire :</b> '.h($owner_name).'</div>
      <div class="muted">Ordonnance strictement vétérinaire — valable uniquement pour l’animal désigné.</div>
    </div>
    <div class="ord-stamp">'.$cachet_html.$sign_html.'</div>
  </div>
';

/* ============ SCREEN preview (A5) — NE PAS TOUCHER ============ */
$html_screen = '
<div class="a5-page">
  <style>
    @page { size: A5 portrait; margin: 14mm 12mm 18mm 12mm; }
    html, body { margin:0; padding:0; }
    @media screen {
      .a5-page{
        width:148mm; min-height:210mm; margin:10px auto; background:#fff;
        box-shadow:0 0 0 1px #ececec, 0 8px 28px rgba(0,0,0,.08);
        padding:0;
      }
      .page-wrap{ padding:14mm 12mm 18mm 12mm; }
    }
    @media print { .page-wrap{ padding:0; } }
.ord-hclinic .submeta{font-size:10px; color:#666; line-height:1.2; margin-top:2px}

    .ord-wrap{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#222;font-size:12px; position:relative;}
    .content{ padding-bottom:36mm; }
    .ord-header{display:flex;align-items:center;gap:12px;border-bottom:1px solid #e6e6e6;padding-bottom:6px;margin-bottom:10px}
    .ord-header .logo img{max-height:56px; max-width:56px; height:auto; width:auto; object-fit:contain;}
    .ord-hclinic .name{font-weight:700;font-size:14px;line-height:1.1}
    .ord-hclinic .meta{font-size:11px;color:#666}
    .ord-header .ord-date{margin-left:auto;text-align:right}
    .ord-header .ord-date .label{font-size:10px;color:#888}
    .ord-header .ord-date .value{font-weight:600}
    .ord-title{text-transform:uppercase;letter-spacing:.08em;font-weight:800;text-align:center;margin:10px 0 12px;font-size:13px}
    .ord-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px 16px;font-size:12px;margin-bottom:10px}
    .ord-block{background:#fafafa;border:1px solid #eee;border-radius:6px;padding:10px}
    .ord-footer{position:absolute; left:0; right:0; bottom:0; padding-top:8px;}
    .ord-footer .sep{border-top:1px solid #dcdcdc; margin-bottom:6px;}
    .ord-footer{display:flex;justify-content:space-between;align-items:flex-end;color:#6c757d;font-size:11px}
    .ord-footer .legal .muted{color:#8a8f98;font-size:10px;margin-top:4px;max-width:110mm}
    .ord-stamp{position:relative; width:200px; height:96px}
    .ord-stamp img.cachet{position:absolute; bottom:0; right:20; max-width:160px; width:auto; height:auto; opacity:.95;}
    .ord-stamp img.signature{position:absolute; bottom:22px; right:12px; max-width:130px; width:auto; height:auto;}
  </style>

  <div class="page-wrap">
    <div class="ord-wrap">
      <div class="content">
        '.$clinic_block.'
        <div class="ord-title">Ordonnance vétérinaire</div>
        '.$patient_block.'
        <div class="ord-block">
          <div style="font-weight:600;margin-bottom:4px">Traitement</div>
          <div>'.$trait_html.'</div>
        </div>
      </div>
      '.$footer_legal.'
    </div>
  </div>
</div>
';

/* ============ PDF stream — SECTION UNIQUEMENT MODIFIÉE ============ */
if ($format==='pdf') {
    if (!try_autoload_dompdf()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Dompdf introuvable. Installez : composer require dompdf/dompdf";
        exit;
    }
    $root = realpath(__DIR__ . '/..');

    // 1) Remplacer images par chemins ABS (ratio natif)
    $clinic_block_pdf = $clinic_block;
    if ($clinic_logo_rel && ($abs=abs_path_from_rel($clinic_logo_rel))) {
        $clinic_block_pdf = str_replace('src="'.h($clinic_logo_rel).'"', 'src="'.h($abs).'"', $clinic_block_pdf);
    }
    $cachet_abs = ($cachet_rel && ($a=abs_path_from_rel($cachet_rel))) ? $a : null;
    $sign_abs   = ($sign_rel   && ($a=abs_path_from_rel($sign_rel)))   ? $a : null;

    // 2) Footer pour le PDF **sans** bloc <div class="ord-stamp"> (on va le fixer à part)
    $footer_bar_pdf = '
      <div class="ord-footer">
        <div class="sep"></div>
        <div class="row">
          <div class="legal">
            <div><b>Propriétaire :</b> '.h($owner_name).'</div>
            <div class="muted">Ordonnance strictement vétérinaire — valable uniquement pour l’animal désigné.</div>
          </div>
        </div>
      </div>';

    // 3) Tampon & signature fixes, bas-droite, non déformés
    $stamp_pdf = '<div class="ord-stamp">'.
        ($cachet_abs ? '<img class="cachet" src="'.h($cachet_abs).'" alt="cachet">' : '').
        ($sign_abs   ? '<img class="signature" src="'.h($sign_abs).'" alt="signature">' : '').
      '</div>';

    // 4) CSS PDF : marges larges + footer fixe + cachet/signature fixes bas-droite
    $html_pdf = '
    <!doctype html><html><head><meta charset="utf-8">
    <style>
      /* A5 + grosses marges */
      @page { size: A5 portrait; margin: 20mm 18mm 28mm 18mm; }
      html, body { margin:10; padding:10; }

      .ord-wrap{font-family:DejaVu Sans, Arial, sans-serif; color:#222; font-size:12px; position:relative;}
      .content{ padding-bottom:44mm; } /* réserve pour footer + tampon */
.ord-hclinic .submeta{font-size:10px; color:#666; line-height:1.2; margin-top:2px}

      .ord-header{display:flex;align-items:center;gap:12px;border-bottom:1px solid #e1e1e1;padding-bottom:8px;margin-bottom:12px}
      .ord-header .logo img{max-height:58px; max-width:58px; height:auto; width:auto; object-fit:contain;}
      .ord-hclinic .name{font-weight:700;font-size:14px;line-height:1.1}
      .ord-hclinic .meta{font-size:11px;color:#666}
      .ord-header .ord-date{margin-left:auto;text-align:right}
      .ord-header .ord-date .label{font-size:10px;color:#888}
      .ord-header .ord-date .value{font-weight:600}
      .ord-title{text-transform:uppercase;letter-spacing:.08em;font-weight:800;text-align:center;margin:12px 0 14px;font-size:13px}
      .ord-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 18px;font-size:12px;margin-bottom:12px}
      .ord-block{background:#fafafa;border:1px solid #eee;border-radius:6px;padding:10px}

      /* Footer FIXE en bas avec ligne de séparation, aligné à l’intérieur des marges */
      .ord-footer{position:fixed; left:18mm; right:18mm; bottom:18mm;}
      .ord-footer .sep{border-top:1px solid #dcdcdc; margin-bottom:6px;}
      .ord-footer .row{display:flex;justify-content:space-between;align-items:flex-end}
      .ord-footer .legal{color:#6c757d;font-size:11px;max-width:110mm}
      .ord-footer .legal .muted{color:#8a8f98;font-size:10px;margin-top:4px}

      /* Cachet + Signature FIXES en bas-droite (superposés, non déformés) */
      .ord-stamp {
  position: absolute;
  bottom: 25px;
  right: 25px;
  width: 200px;
  height: 120px;
}

.ord-stamp img.cachet {
  position: absolute;
  bottom: 40;
  right: 0;
  max-width: 165px;
  height: auto;
  opacity: .98;
}

.ord-stamp img.signature {
  position: absolute;
  bottom: 35px;   /* légèrement au-dessus du cachet */
  right: 10px;    /* un petit décalage intérieur */
  max-width: 135px;
  height: auto;
}
    </style></head><body>
      <div class="ord-wrap">
        <div class="content">
          '.$clinic_block_pdf.'
          <div class="ord-title">Ordonnance vétérinaire</div>
          '.$patient_block.'
          <div class="ord-block">
            <div style="font-weight:600;margin-bottom:4px">Traitement</div>
            <div>'.$trait_html.'</div>
          </div>
        </div>
        '.$footer_bar_pdf.'
        '.$stamp_pdf.'
      </div>
    </body></html>';

    $fqcn='\Dompdf\Dompdf'; $opt='\Dompdf\Options';
    /** @var \Dompdf\Dompdf $dompdf */
    $dompdf = new $fqcn();
    if (class_exists($opt)) {
        $o = new $opt();
        if ($root && method_exists($o,'setChroot')) $o->setChroot($root);
        if (method_exists($o,'set')) {
            $o->set('isRemoteEnabled', false); // images via chemins absolus uniquement (pas de SSRF)
            $o->set('defaultFont', 'DejaVu Sans');
        }
        if (method_exists($dompdf,'setOptions')) $dompdf->setOptions($o);
    }
    if ($root && method_exists($dompdf,'setBasePath')) $dompdf->setBasePath($root);
    if (method_exists($dompdf,'setPaper'))   $dompdf->setPaper('A5','portrait');
    if (method_exists($dompdf,'loadHtml'))   $dompdf->loadHtml($html_pdf,'UTF-8');
    if (method_exists($dompdf,'render'))     $dompdf->render();

    $fname = sprintf('ord_%s_%d_%s.pdf', $type, $id, date('YmdHis'));
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="'.$fname.'"');
    echo method_exists($dompdf,'output') ? $dompdf->output() : '';
    exit;
}

/* ============ JSON (preview + lien PDF) ============ */
$pdf_url = 'api/ordonnances.php?format=pdf&type=' . rawurlencode($type) . '&id=' . $id;
ok_json([
  'status'    => 'ok',
  'html'      => $html_screen,
  'pdf_url'   => $pdf_url,
  'pdf_ready' => $DOMPDF_READY
]);

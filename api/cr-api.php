<?php
// api/cr-api.php
// Endpoint JSON pour modifier le compte-rendu d’une imagerie
// Entrée POST: __csrf*, imagerie_id, compte_rendu
// Sortie JSON: { ok:1, text:"..." } ou { ok:0, msg:"..." }

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    require_login();
    // CSRF facultatif si tu veux ouvrir à d’autres clients; ici on le garde
    if (function_exists('csrf_check')) { csrf_check(); }

    $imid = isset($_POST['imagerie_id']) ? (int)$_POST['imagerie_id'] : 0;
    $text = trim($_POST['compte_rendu'] ?? '');

    if ($imid <= 0) {
        echo json_encode(['ok'=>0,'msg'=>'ID imagerie manquant']); exit;
    }

    // Vérifier que l’imagerie existe
    $st = db()->prepare("SELECT id FROM imageries WHERE id=?");
    $st->execute([$imid]);
    if (!$st->fetchColumn()) {
        echo json_encode(['ok'=>0,'msg'=>'Imagerie introuvable']); exit;
    }

    $up = db()->prepare("UPDATE imageries SET compte_rendu=?, updated_at=NOW() WHERE id=?");
    $up->execute([$text, $imid]);

    echo json_encode(['ok'=>1,'text'=>$text]); exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>0,'msg'=>'Erreur serveur']); // pas de leak d’info
    exit;
}

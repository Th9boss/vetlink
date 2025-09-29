<?php
// cap.php — page de test ultra-clean
// Prérequis: Bootstrap 5 (CSS + JS) accessible (CDN ci-dessous ou tes fichiers locaux)
// et includes/capture.php présent (version fournie précédemment)

require_once __DIR__ . '/includes/capture.php';

// Dossier de test (change si tu veux)
$storeDir = 'uploads/captest';

// On prépare le modal mais il restera invisible (Bootstrap gère .modal{display:none})
// NB: si Bootstrap n'est pas chargé, on a un CSS de secours plus bas qui garde le modal caché.
capture_handle($storeDir, [
  'modal_id' => 'capTestModal',
  'maxWidth' => 1600,
  'quality'  => 0.99,
]);
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Capture — Test</title>

  <!-- Bootstrap via CDN (tu peux remplacer par tes fichiers locaux) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <!-- Filet de sécurité: si Bootstrap ne charge pas, on force la modal à rester cachée -->
  <style>
    /* cache toute modal tant que .modal.show n'est pas appliqué par Bootstrap */
    .modal:not(.show){ display:none !important; }
    body { margin:0; }
    .center-wrap { min-height:100vh; display:grid; place-items:center; }
    .result { position:fixed; left:12px; bottom:12px; right:12px; }
    .result a { word-break:break-all; }
  </style>
</head>
<body>

<div class="center-wrap">
  <button id="btn-joindre" class="btn btn-primary btn-lg shadow">
    Joindre
  </button>
</div>

<div class="result">
  <div class="small text-muted">Chemin retourné :</div>
  <div id="pathOut" class="fw-semibold"></div>
</div>

<script>
  // Ouvre la modale à la demande, et affiche le 1er chemin retourné après “Valider”.
  document.getElementById('btn-joindre').addEventListener('click', () => {
    if (typeof openCaptureModal !== 'function') {
      alert('Le composant de capture n’est pas initialisé.');
      return;
    }
    openCaptureModal({
      onDone: (files) => {
        const out = document.getElementById('pathOut');
        if (!files || !files.length) {
          out.textContent = '—';
          return;
        }
        const p = files[0]; // on prend le premier pour le test
        out.innerHTML = `<a href="${p}" target="_blank">${p}</a>`;
      }
    });
  });
</script>

</body>
</html>

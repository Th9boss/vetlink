<?php
// includes/capture.php
// Modal universel de capture (caméra + upload) pour VETLINK
// - API: capture_handle($storeDir, $options=[])
// - Expose window.openCaptureModal({ onDone(files: string[]) })

// ── Protection : accès direct à l'endpoint upload ──────────────────────────
if (isset($_GET['capture_upload']) && !function_exists('capture_handle')) {
    require_once __DIR__ . '/auth.php';
    if (!is_logged_in()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'unauthorized']);
        exit;
    }
}

if (!function_exists('capture_handle')) {

  function _cap_validate_store_dir(string $dir): ?string {
    $dir = str_replace(["\0", "\r", "\n", '\\'], ['', '', '', '/'], trim($dir));
    $dir = ltrim($dir, '/');
    if (strncmp($dir, 'uploads/', 8) !== 0) return null;
    foreach (explode('/', $dir) as $seg) {
      if ($seg === '..' || $seg === '.') return null;
    }
    if (!preg_match('#^[a-zA-Z0-9/_\-]+$#', $dir)) return null;
    if (strlen($dir) > 200) return null;
    return $dir;
  }

  function _cap_abs(string $path): string {
    $root = realpath(__DIR__ . '/..');
    return rtrim($root, '/') . '/' . ltrim($path, '/');
  }

  function _cap_ensure_dir(string $storeDir): array {
    $abs = _cap_abs($storeDir);
    if (!is_dir($abs)) { @mkdir($abs, 0775, true); }
    $ht = $abs.'/.htaccess';
    if (!is_file($ht)) {
      @file_put_contents($ht, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar)$\">\nDeny from all\n</FilesMatch>\n");
      @chmod($ht, 0644);
    }
    return [$abs, $storeDir];
  }

  function _cap_unique_name(string $prefix, string $ext): string {
    $ext = ltrim($ext, '.');
    return $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
  }

  /**
   * Compresse une image JPEG ou PNG via GD (redimensionne + qualité réduite).
   * Cible : ≤ 500 Ko pour JPEG, PNG compressé niveau 7.
   */
  function _cap_compress_image(string $bin, string $mime, int $maxW = 1920): ?string {
    $src = @imagecreatefromstring($bin);
    if (!$src) return null;

    $w = imagesx($src); $h = imagesy($src);
    if ($w > $maxW) {
      $nh  = (int)round($h * $maxW / $w);
      $dst = imagecreatetruecolor($maxW, $nh);
      if ($mime === 'image/png') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
      }
      imagecopyresampled($dst, $src, 0, 0, 0, 0, $maxW, $nh, $w, $h);
      imagedestroy($src);
      $src = $dst;
    }

    ob_start();
    if ($mime === 'image/png') {
      imagepng($src, null, 7);
      $out = ob_get_clean();
    } else {
      $quality = 85;
      do {
        ob_clean();
        imagejpeg($src, null, $quality);
        $quality -= 5;
      } while (ob_get_length() > 500 * 1024 && $quality > 30);
      $out = ob_get_clean();
    }
    imagedestroy($src);
    return $out ?: null;
  }

  function _cap_save_data_url(string $dataUrl, string $storeDir): ?string {
    // Accepte les MIME avec paramètres : "video/webm;codecs=vp8,opus"
    if (!preg_match('#^data:((?:image|video)/[a-zA-Z0-9]+)[^,]*;base64,(.+)$#s', $dataUrl, $m)) return null;
    $mime = $m[1]; // ex: "image/jpeg", "video/webm"
    $bin  = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) < 4) return null;

    if (in_array($mime, ['image/jpeg', 'image/png'], true)) {
      $compressed = _cap_compress_image($bin, $mime);
      if ($compressed) $bin = $compressed;
    }

    // Déduire l'extension depuis le MIME de base
    if      (str_contains($mime, 'webm')) $ext = 'webm';
    elseif  (str_contains($mime, 'mp4'))  $ext = 'mp4';
    elseif  (str_contains($mime, 'png'))  $ext = 'png';
    elseif  (str_contains($mime, 'gif'))  $ext = 'gif';
    else                                   $ext = 'jpg';
    [$abs, $rel] = _cap_ensure_dir($storeDir);
    $name    = _cap_unique_name('cap', $ext);
    $absFile = $abs . '/' . $name;
    if (@file_put_contents($absFile, $bin) === false) return null;
    @chmod($absFile, 0664);
    return rtrim($rel, '/') . '/' . $name;
  }

  function _cap_handle_files(string $storeDir, string $field = 'files'): array {
    if (empty($_FILES[$field]) || empty($_FILES[$field]['name'])) return [];
    [$abs, $rel] = _cap_ensure_dir($storeDir);

    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webm', 'mp4'];
    $saved      = [];
    $names  = (array)$_FILES[$field]['name'];
    $tmps   = (array)$_FILES[$field]['tmp_name'];
    $errors = (array)$_FILES[$field]['error'];
    $sizes  = (array)($_FILES[$field]['size'] ?? []);

    foreach ($names as $i => $nm) {
      if (($errors[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
      $tmp = $tmps[$i] ?? null;
      $sz  = (int)($sizes[$i] ?? 0);
      if (!$tmp || !is_uploaded_file($tmp)) continue;
      if ($sz > 50 * 1024 * 1024) continue; // 50 Mo max

      $ext = strtolower(pathinfo($nm, PATHINFO_EXTENSION));
      if (!in_array($ext, $allowedExt, true)) continue;

      // Compression serveur JPEG/PNG (sécurité : le client compresse déjà, mais pas toujours)
      if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        $mime = ($ext === 'png') ? 'image/png' : 'image/jpeg';
        $bin  = @file_get_contents($tmp);
        if ($bin) {
          $compressed = _cap_compress_image($bin, $mime);
          if ($compressed) @file_put_contents($tmp, $compressed);
        }
      }

      $safe   = _cap_unique_name('up', $ext);
      $target = $abs . '/' . $safe;
      if (@move_uploaded_file($tmp, $target)) {
        @chmod($target, 0664);
        $saved[] = rtrim($rel, '/') . '/' . $safe;
      }
    }
    return $saved;
  }

  // —— Endpoint AJAX : ?capture_upload=1 ——
  if (isset($_GET['capture_upload'])) {
    header('Content-Type: application/json; charset=utf-8');

    $dir = _cap_validate_store_dir($_POST['storeDir'] ?? 'uploads/misc');
    if ($dir === null) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'invalid_store_dir']);
      exit;
    }

    $mode = $_POST['mode'] ?? 'data';
    $out  = ['ok' => false, 'files' => []];

    try {
      if ($mode === 'data' && !empty($_POST['dataUrl'])) {
        $saved = _cap_save_data_url($_POST['dataUrl'], $dir);
        if ($saved) { $out['ok'] = true; $out['files'] = [$saved]; }
        else { $out['error'] = 'save_failed'; }

      } elseif ($mode === 'upload') {
        $saved = _cap_handle_files($dir, 'files');
        if ($saved) { $out['ok'] = true; $out['files'] = $saved; }
        else { $out['error'] = 'no_files_saved'; }

      } else {
        $out['error'] = 'invalid_mode';
      }
    } catch (\Throwable $e) {
      $out['error'] = 'server_exception';
    }
    echo json_encode($out);
    exit;
  }

  // —— Rendu du composant ——
  function capture_handle(string $storeDir, array $options = []): void {
    [$abs, $rel] = _cap_ensure_dir($storeDir);
    $modalId = $options['modal_id'] ?? 'vlCapture';
    $maxW    = (int)($options['maxWidth'] ?? 1600);
    $quality = (float)($options['quality'] ?? 0.85);
    // $options['debug'] accepté pour compat ascendante, ignoré
    ?>
    <style>
      .cap-modal .modal-dialog { max-width: 400px; margin: auto; }
      .cap-modal .modal-content {
        border-radius: 12px; box-shadow: 0 10px 28px rgba(0,0,0,.25);
        background:#fff; max-height:92vh; display:flex; flex-direction:column; overflow:hidden;
        overscroll-behavior: contain;
      }
      .cap-toolbar { padding:.6rem .9rem; display:flex; align-items:center; border-bottom:1px solid #eee; gap:.5rem; }
      .cap-toolbar .title { font-weight:700; }

      .cap-body { flex:1 1 auto; background:#fff; overflow:auto; min-height:0; padding-bottom:.5rem; }

      @media (max-width: 576px) {
        .cap-modal .modal-dialog { max-width: 94vw; }
        .cap-cam-aspect { aspect-ratio: 1 / 1; }
      }
      @media (max-height: 780px) {
        .cap-cam-aspect { aspect-ratio: 4 / 5; }
        .cap-controls { flex-wrap: wrap; }
      }

      .cap-tabs { display:flex; border-bottom:1px solid #eee; }
      .cap-tab { flex:1; text-align:center; padding:.6rem .5rem; font-weight:600; cursor:pointer; user-select:none; }
      .cap-tab.active { border-bottom:3px solid var(--bs-primary); color:var(--bs-primary); }
      .cap-pane { display:none; padding:1rem; }
      .cap-pane.active { display:block; }

      .cap-cam-wrap { width:100%; max-width:640px; margin:0 auto; }
      .cap-cam-aspect { position:relative; width:100%; aspect-ratio: 3 / 4; background:#000; border-radius:12px; overflow:hidden; }
      .cap-cam-aspect video, .cap-cam-aspect canvas { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
      .cap-cam-aspect .cap-guides { position:absolute; inset:10px; border:2px dashed rgba(255,255,255,.85); border-radius:10px; pointer-events:none; }

      .cap-controls { display:flex; gap:.5rem; justify-content:center; margin-top:.65rem; flex-wrap:nowrap; }
      .cap-controls .btn { padding:.55rem .9rem; font-weight:700; border-radius:10px; }
      .cap-controls [data-act="video"].recording { background:#dc3545; color:#fff; border-color:#dc3545; animation: cap-blink .8s infinite; }
      .cap-controls [data-act="video"].uploading { background:#198754; color:#fff; border-color:#198754; animation: cap-blink .8s infinite; }
      @keyframes cap-blink{0%,100%{opacity:.6}50%{opacity:1}}

      .cap-pane[data-name="cam"] [data-role="list"] { display:none !important; }

      .cap-drop { border:2px dashed #cbd5e1; border-radius:12px; padding:1rem; text-align:center; max-width:640px; margin:0 auto; }
      .cap-drop.drag { border-color: var(--bs-primary); background:#f8fafc; }
      .cap-list { max-width:640px; margin:1rem auto 0; }
      .cap-item { display:flex; align-items:center; gap:.5rem; padding:.5rem; border:1px solid #eee; border-radius:10px; margin-bottom:.5rem; }
      .cap-item small { word-break:break-all; }
      .cap-item .cap-compress-tag { font-size:.7rem; color:#198754; font-weight:600; margin-left:.25rem; }

      @media (max-width: 576px) {
        .cap-pane { padding:.75rem; }
        .cap-cam-wrap { max-width: 94vw; }
        .cap-controls { gap:.4rem; }
        .cap-controls .btn { padding:.5rem .75rem; font-weight:700; }
      }
    </style>

    <div class="modal fade cap-modal" id="<?= htmlspecialchars($modalId) ?>" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="cap-toolbar">
            <div class="title">Capture de fichiers</div>
            <div class="ms-auto">
              <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Fermer</button>
            </div>
          </div>

          <div class="cap-tabs">
            <div class="cap-tab active" data-pane="cam">Caméra</div>
            <div class="cap-tab" data-pane="upload">Uploader</div>
          </div>

          <div class="cap-body">
            <div class="cap-pane active" data-name="cam">
              <div class="cap-cam-wrap">
                <div class="cap-cam-aspect">
                  <video autoplay playsinline muted></video>
                  <canvas style="display:none;"></canvas>
                  <div class="cap-guides"></div>
                </div>
                <div class="cap-controls">
                  <button type="button" class="btn btn-primary" data-act="photo">Photo</button>
                  <button type="button" class="btn btn-outline-primary" data-act="video">Vidéo</button>
                  <button type="button" class="btn btn-warning" data-act="recap" title="Réinitialiser" disabled>↺</button>
                </div>
              </div>
              <div class="cap-list" data-role="list"></div>
            </div>

            <div class="cap-pane" data-name="upload">
              <div class="cap-drop" data-role="drop">
                <p class="mb-2 fw-semibold">Déposez vos fichiers ici</p>
                <p class="text-muted small mb-2">PDF, JPG, PNG, GIF (10 Mo max)</p>
                <label class="btn btn-outline-primary">
                  Choisir des fichiers
                  <input type="file" name="files[]" multiple
                         accept=".pdf,image/jpeg,image/png,image/gif" hidden>
                </label>
              </div>
              <div class="cap-list" data-role="list"></div>
            </div>
          </div>

          <div class="p-2 text-center">
            <button type="button" class="btn btn-success" data-act="done" disabled>Valider</button>
          </div>
        </div>
      </div>
    </div>

    <script>
    (function(){
      const MODAL_ID  = <?= json_encode($modalId) ?>;
      const STORE_DIR = <?= json_encode($rel) ?>;
      const MAX_W     = <?= (int)$maxW ?>;
      const QUALITY   = <?= (float)$quality ?>;

      const ENDPOINT = (function(){
        const b = location.pathname + (location.search ? location.search + '&' : '?') + 'capture_upload=1';
        return b;
      })();

      let onDoneCb = null;
      let modalInst = null;
      function _modal(){
        if (!modalInst) modalInst = new bootstrap.Modal(document.getElementById(MODAL_ID), {backdrop:'static'});
        return modalInst;
      }

      const $modal   = document.getElementById(MODAL_ID);
      const $tabs    = $modal.querySelectorAll('.cap-tab');
      const $panes   = $modal.querySelectorAll('.cap-pane');
      const $video   = $modal.querySelector('video');
      const $canvas  = $modal.querySelector('canvas');
      const $camList = $modal.querySelector('[data-name="cam"] [data-role="list"]');
      const $btnDone = $modal.querySelector('[data-act="done"]');
      const $btnRecap= $modal.querySelector('[data-act="recap"]');
      const $btnVid  = $modal.querySelector('[data-act="video"]');
      const $uplList = $modal.querySelector('[data-name="upload"] [data-role="list"]');
      const $drop    = $modal.querySelector('[data-name="upload"] [data-role="drop"]');
      const $file    = $drop ? $drop.querySelector('input[type="file"]') : null;

      let stream = null;
      let previewEl = null;
      let lastSavedPaths = [];
      let isUploading = 0;
      let uplQueue = [];
      let mediaRec = null;
      let activeControllers = [];

      function registerController(ctrl){ activeControllers.push(ctrl); return ctrl; }

      function setUploading(on){
        isUploading += on ? 1 : -1;
        if (isUploading < 0) isUploading = 0;
        $btnDone.textContent = (isUploading > 0) ? 'Envoi…' : 'Valider';
        refreshDoneState();
      }

      function refreshDoneState(){
        $btnDone.disabled = (isUploading > 0) || !(uplQueue.length > 0 || lastSavedPaths.length > 0);
      }

      function abortAllUploads(){
        activeControllers.forEach(c=>{ try{ c.abort(); }catch(e){} });
        activeControllers = []; isUploading = 0;
        $btnDone.textContent = 'Valider';
        refreshDoneState();
      }

      /* ── Tabs ── */
      $tabs.forEach(t=>{
        t.addEventListener('click', ()=>{
          $tabs.forEach(x=>x.classList.remove('active'));
          t.classList.add('active');
          const name = t.dataset.pane;
          $panes.forEach(p=>p.classList.toggle('active', p.dataset.name === name));
          refreshDoneState();
        });
      });

      /* ── Caméra ── */
      async function startCamera() {
        await stopCamera();
        // Effacer tout src blob éventuel avant de brancher le stream
        $video.pause();
        $video.removeAttribute('src');
        $video.srcObject = null;
        try {
          stream = await navigator.mediaDevices.getUserMedia({ video:{ facingMode:{ ideal:'environment' } }, audio:false });
          $video.srcObject = stream;
        } catch(e) {
          console.warn('[capture] camera error', e);
          alert("Caméra indisponible.");
        }
      }
      async function stopCamera(){
        if (stream) { stream.getTracks().forEach(t=>t.stop()); stream = null; }
      }

      const $camAspect = $modal.querySelector('.cap-cam-aspect');
      const $guides    = $modal.querySelector('.cap-guides');

      /* ── Aperçu photo ── */
      function showPreviewCanvas() {
        _removeVideoPlayer();
        $video.style.display = 'none';
        $canvas.style.display = 'block';
        if ($guides) $guides.style.display = 'none';
        previewEl = $canvas;
        $btnRecap.disabled = false;
      }

      /* ── Lecteur vidéo après upload serveur ── */
      function showVideoPlayer(serverRelPath) {
        // Arrêter la caméra et masquer ses éléments
        stopCamera();
        $video.style.display = 'none';
        $canvas.style.display = 'none';
        if ($guides) $guides.style.display = 'none';

        // Créer (ou réutiliser) un lecteur <video controls> dans la zone caméra
        let player = $camAspect.querySelector('.cap-vid-player');
        if (!player) {
          player = document.createElement('video');
          player.className = 'cap-vid-player';
          player.controls = true;
          player.loop     = true;
          player.muted    = false;
          player.playsInline = true;
          // Couvre toute la zone, object-fit:contain pour voir la vidéo entière
          player.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;object-fit:contain;background:#000;z-index:10;';
          $camAspect.appendChild(player);
        }

        // Résoudre le chemin relatif par rapport à la base de la page courante (même logique que le shim)
        player.src = new URL(serverRelPath, document.baseURI || location.href).href;
        player.onerror = () => alert('Vidéo inaccessible : ' + player.src + '\n' + (player.error?.message || ''));
        player.load();
        player.play().catch(() => {});
        previewEl = player;
        $btnVid.disabled = true;   // grisé jusqu'au reset
        $btnRecap.disabled = false;
        refreshDoneState();
      }

      function _removeVideoPlayer() {
        const player = $camAspect ? $camAspect.querySelector('.cap-vid-player') : null;
        if (player) { player.onerror = null; player.pause(); player.src = ''; player.remove(); }
        if ($guides) $guides.style.display = '';
        $btnVid.disabled = false;
        previewEl = null;
      }

      async function recapture() {
        if (mediaRec && mediaRec.state === 'recording') { mediaRec.stop(); mediaRec = null; }
        abortAllUploads();
        uplQueue = [];
        lastSavedPaths = [];
        $uplList && ($uplList.innerHTML = '');
        _removeVideoPlayer();
        $canvas.style.display = 'none';
        $video.style.display = 'block';
        $btnRecap.disabled = true;
        $btnVid.classList.remove('recording','uploading');
        $btnVid.textContent = 'Vidéo';
        await startCamera();
      }
      $btnRecap.addEventListener('click', recapture);

      /* ── Photo ── */
      async function takePhoto() {
        if (!stream) { await startCamera(); if (!stream) return; }
        const w = $video.videoWidth || 1280, h = $video.videoHeight || 720;
        const scale = w > MAX_W ? MAX_W / w : 1;
        const tw = Math.round(w*scale), th = Math.round(h*scale);
        $canvas.width = tw; $canvas.height = th;
        $canvas.getContext('2d').drawImage($video, 0, 0, tw, th);
        showPreviewCanvas();

        const dataUrl = $canvas.toDataURL('image/jpeg', QUALITY);
        setUploading(true);
        const json = await uploadDataUrl(dataUrl);
        if (json?.ok && json.files?.length) {
          lastSavedPaths.unshift(json.files[0]);
          addListItemCam(json.files[0]);
        } else {
          alert('Erreur upload (photo).');
        }
        setUploading(false);
      }

      /* ── Vidéo (MediaRecorder WebM) ── */
      async function recordVideo() {
        if (!stream) { await startCamera(); if (!stream) return; }

        // Sélectionner le meilleur codec disponible
        const mimeType = ['video/webm;codecs=vp9','video/webm;codecs=vp8','video/webm','video/mp4']
          .find(t => { try { return MediaRecorder.isTypeSupported(t); } catch(e){ return false; } })
          || 'video/webm';

        $btnVid.classList.add('recording'); $btnVid.textContent = '● 3s…';

        const chunks = [];
        try {
          mediaRec = new MediaRecorder(stream, { mimeType, videoBitsPerSecond: 800_000 });
        } catch(e) {
          // Fallback sans options
          mediaRec = new MediaRecorder(stream);
        }

        mediaRec.ondataavailable = e => { if (e.data?.size > 0) chunks.push(e.data); };

        mediaRec.onstop = async () => {
          const actualType = mediaRec.mimeType || mimeType;
          const blob = new Blob(chunks, { type: actualType });
          mediaRec = null;

          $btnVid.classList.remove('recording'); $btnVid.classList.add('uploading'); $btnVid.textContent = 'Envoi…';
          setUploading(true);

          // Convertir en dataURL → même chemin serveur que les photos (éprouvé)
          const json = await new Promise(resolve => {
            const fr = new FileReader();
            fr.onload  = async () => resolve(await uploadDataUrl(fr.result));
            fr.onerror = () => resolve(null);
            fr.readAsDataURL(blob);
          });

          setUploading(false);
          $btnVid.classList.remove('uploading'); $btnVid.textContent = 'Vidéo';

          if (json?.ok && json.files?.length) {
            lastSavedPaths.unshift(json.files[0]);
            showVideoPlayer(json.files[0]);
          } else {
            alert('Erreur upload (vidéo).');
          }
        };

        // start() sans timeslice : un seul ondataavailable à la fin, blob complet garanti
        mediaRec.start();
        setTimeout(() => { if (mediaRec && mediaRec.state === 'recording') mediaRec.stop(); }, 3000);
      }

      $modal.querySelector('[data-act="photo"]').addEventListener('click', takePhoto);
      $btnVid.addEventListener('click', () => {
        if (mediaRec && mediaRec.state === 'recording') { mediaRec.stop(); return; }
        recordVideo();
      });

      /* ── Upload réseau ── */
      async function uploadDataUrl(dataUrl) {
        const fd = new FormData();
        fd.append('storeDir', STORE_DIR);
        fd.append('mode', 'data');
        fd.append('dataUrl', dataUrl);
        const ctrl = registerController(new AbortController());
        try {
          const resp = await fetch(ENDPOINT, { method:'POST', body:fd, signal:ctrl.signal });
          return await resp.json();
        } catch(e) { return null; }
        finally { activeControllers = activeControllers.filter(c=>c!==ctrl); }
      }

      async function uploadBlob(blob, ext) {
        const fd = new FormData();
        fd.append('storeDir', STORE_DIR);
        fd.append('mode', 'upload');
        fd.append('files[]', blob, `cap_${Date.now()}.${ext}`);
        const ctrl = registerController(new AbortController());
        try {
          const resp = await fetch(ENDPOINT, { method:'POST', body:fd, signal:ctrl.signal });
          return await resp.json();
        } catch(e) { return null; }
        finally { activeControllers = activeControllers.filter(c=>c!==ctrl); }
      }

      function addListItemCam(relPath) { refreshDoneState(); }

      /* ── Compression image côté client (upload tab) ── */
      function compressImageFile(file) {
        return new Promise(resolve => {
          const img = new Image();
          const url = URL.createObjectURL(file);
          img.onload = () => {
            URL.revokeObjectURL(url);
            const scale = file.type === 'image/png' ? 1 : Math.min(1, MAX_W / (img.naturalWidth || MAX_W));
            const w = Math.round((img.naturalWidth || MAX_W) * scale);
            const h = Math.round((img.naturalHeight || w) * scale);
            const c = document.createElement('canvas');
            c.width = w; c.height = h;
            c.getContext('2d').drawImage(img, 0, 0, w, h);
            const outType = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
            const outQ    = file.type === 'image/png' ? 1 : QUALITY;
            c.toBlob(blob => {
              resolve(blob ? new File([blob], file.name, { type: outType }) : file);
            }, outType, outQ);
          };
          img.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
          img.src = url;
        });
      }

      /* ── Upload tab : drag & drop / file input ── */
      if ($drop && $file) {
        ['dragenter','dragover'].forEach(ev=>{
          $drop.addEventListener(ev, e=>{ e.preventDefault(); e.stopPropagation(); $drop.classList.add('drag'); });
        });
        ['dragleave','drop'].forEach(ev=>{
          $drop.addEventListener(ev, e=>{ e.preventDefault(); e.stopPropagation(); $drop.classList.remove('drag'); });
        });
        $drop.addEventListener('drop', e=>{ pushToQueue(Array.from(e.dataTransfer.files||[])); });
        $file.addEventListener('change', e=>{ pushToQueue(Array.from($file.files||[])); $file.value=''; });
      }

      async function pushToQueue(files) {
        const allowed = ['application/pdf','image/jpeg','image/png','image/gif'];
        for (const f of files) {
          if (!allowed.includes(f.type)) continue;
          if (f.size > 10 * 1024 * 1024) { alert(`${f.name} dépasse 10 Mo.`); continue; }

          let file = f;
          if (['image/jpeg','image/png'].includes(f.type)) {
            file = await compressImageFile(f);
          }

          uplQueue.push(file);
          const li = document.createElement('div');
          li.className = 'cap-item';
          const icon = f.type === 'application/pdf' ? '📄' : '🖼️';
          const sizeOrig = Math.round(f.size / 1024);
          const sizeNew  = Math.round(file.size / 1024);
          const tag = (file.size < f.size) ? `<span class="cap-compress-tag">→ ${sizeNew} Ko ✓</span>` : '';
          li.innerHTML = `${icon} <small>${f.name} (${sizeOrig} Ko)${tag}</small>`;
          $uplList.appendChild(li);
        }
        refreshDoneState();
      }

      /* ── Valider ── */
      $btnDone.addEventListener('click', async () => {
        if (uplQueue.length > 0) {
          const fd = new FormData();
          fd.append('storeDir', STORE_DIR);
          fd.append('mode', 'upload');
          uplQueue.forEach(f => fd.append('files[]', f, f.name));
          const ctrl = registerController(new AbortController());
          setUploading(true);
          try {
            const resp = await fetch(ENDPOINT, { method:'POST', body:fd, signal:ctrl.signal });
            const json = await resp.json();
            if (json?.ok) {
              for (const p of json.files) lastSavedPaths.unshift(p);
              uplQueue = []; $uplList.innerHTML = '';
            } else {
              alert('Erreur upload (fichiers).');
            }
          } catch(e) {
            alert('Erreur réseau.');
          } finally {
            setUploading(false);
            activeControllers = activeControllers.filter(c=>c!==ctrl);
          }
        }
        if (onDoneCb) { try { onDoneCb(lastSavedPaths.slice()); } catch(e){} }
        _modal().hide();
      });

      /* ── Cycle de vie du modal ── */
      $modal.addEventListener('shown.bs.modal', async () => {
        lastSavedPaths = []; uplQueue = []; isUploading = 0; activeControllers = [];
        $uplList && ($uplList.innerHTML = '');
        $btnDone.disabled = true;
        await startCamera();
      });

      $modal.addEventListener('hidden.bs.modal', async () => {
        if (mediaRec && mediaRec.state === 'recording') { mediaRec.stop(); mediaRec = null; }
        abortAllUploads();
        _removeVideoPlayer();
        await stopCamera();
        $canvas.style.display = 'none';
        $video.style.display  = 'block';
        $btnRecap.disabled = true;
        $btnVid.classList.remove('recording','uploading');
        $btnVid.textContent = 'Vidéo';
        lastSavedPaths = []; uplQueue = [];
      });

      window.openCaptureModal = function(opts){
        onDoneCb = (opts && typeof opts.onDone === 'function') ? opts.onDone : null;
        _modal().show();
      };
    })();

/* Auto-shim : réécrit les requêtes capture_upload vers l'URL absolue de includes/capture.php */
(function () {
  const ABS = new URL('includes/capture.php?capture_upload=1', document.baseURI || location.href).href;
  const _fetch = window.fetch;
  function hasUp(u){ return /(?:^|[?&])capture_upload=1\b/.test(u); }

  window.fetch = function (input, init) {
    try {
      if (typeof input === 'string' && hasUp(input)) {
        input = ABS;
      } else if (input && typeof input === 'object' && 'url' in input && hasUp(input.url)) {
        input = new Request(ABS, input);
      }
    } catch (e) {}
    if (!init) init = {};
    if (!('credentials' in init)) init.credentials = 'same-origin';
    return _fetch(input, init);
  };
})();

    </script>
    <?php
  }
}

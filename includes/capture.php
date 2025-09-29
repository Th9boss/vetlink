<?php
// includes/capture.php
// Modal universel de capture (caméra + upload) pour VETLINK
// - API: capture_handle($storeDir, $options=[])
// - Expose window.openCaptureModal({ onDone(files: string[]) })
//
// Dépendances front : Bootstrap 5 (CSS+JS) + (optionnel) ./assets/vendor/gif.js/gif.js & gif.worker.js
//
// Exemple d’usage (dans cap.php ou une page de ton app):
//   require_once __DIR__.'/includes/capture.php';
//   capture_handle('uploads/captest', ['modal_id'=>'capTestModal', 'debug'=>1]);
//   <button onclick="openCaptureModal({onDone:(files)=>console.log(files)})">Joindre</button>

if (!function_exists('capture_handle')) {

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

  function _cap_perm_octal($path): ?string {
    clearstatcache(true, $path);
    $p = @fileperms($path);
    return $p !== false ? substr(sprintf('%o', $p), -4) : null;
  }

  function _cap_owner_group($path): array {
    $uid = @fileowner($path);
    $gid = @filegroup($path);
    $u = function_exists('posix_getpwuid') && $uid !== false ? (@posix_getpwuid($uid)['name'] ?? $uid) : $uid;
    $g = function_exists('posix_getgrgid') && $gid !== false ? (@posix_getgrgid($gid)['name'] ?? $gid) : $gid;
    return [$u, $g];
  }

  function _cap_php_ini(): array {
    return [
      'file_uploads'        => ini_get('file_uploads'),
      'upload_max_filesize' => ini_get('upload_max_filesize'),
      'post_max_size'       => ini_get('post_max_size'),
      'max_file_uploads'    => ini_get('max_file_uploads'),
      'memory_limit'        => ini_get('memory_limit'),
      'max_input_vars'      => ini_get('max_input_vars'),
      'max_input_time'      => ini_get('max_input_time'),
      'default_socket_timeout'=> ini_get('default_socket_timeout'),
      'session.save_path'   => ini_get('session.save_path'),
      'sys_get_temp_dir'    => sys_get_temp_dir(),
    ];
  }

  function _cap_save_data_url(string $dataUrl, string $storeDir, bool $debug=false, array &$dbg_ref = []): ?string {
    if (!preg_match('#^data:(image/(?:jpeg|png|gif));base64,(.+)$#', $dataUrl, $m)) return null;
    $mime = $m[1];
    $bin  = base64_decode($m[2], true);
    if ($bin === false) return null;

    $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/gif' ? 'gif' : 'jpg');
    [$abs, $rel] = _cap_ensure_dir($storeDir);
    $name = _cap_unique_name('cap', $ext);
    $absFile = $abs.'/'.$name;
    $ok = @file_put_contents($absFile, $bin);
    if ($ok === false) return null;
    @chmod($absFile, 0664);

    if ($debug) {
      $perm = _cap_perm_octal($absFile);
      [$u,$g] = _cap_owner_group($absFile);
      $dbg_ref['saved'] = [
        'target_abs' => $absFile,
        'target_rel' => rtrim($rel,'/').'/'.$name,
        'bytes'      => strlen($bin),
        'mime'       => $mime,
        'exists'     => file_exists($absFile),
        'size'       => @filesize($absFile),
        'perm'       => $perm,
        'owner'      => $u,
        'group'      => $g,
        'is_writable'=> is_writable($absFile),
      ];
    }

    return rtrim($rel,'/').'/'.$name;
  }

  function _cap_handle_files(string $storeDir, string $field = 'files', bool $debug=false, array &$dbg_ref = []): array {
    if (empty($_FILES[$field]) || empty($_FILES[$field]['name'])) return [];
    [$abs, $rel] = _cap_ensure_dir($storeDir);

    $saved = [];
    $names  = (array)$_FILES[$field]['name'];
    $tmps   = (array)$_FILES[$field]['tmp_name'];
    $errors = (array)$_FILES[$field]['error'];
    $sizes  = (array)($_FILES[$field]['size'] ?? []);
    $types  = (array)($_FILES[$field]['type'] ?? []);

    if ($debug) { $dbg_ref['incoming_files'] = []; }

    foreach ($names as $i => $nm) {
      $err = ($errors[$i] ?? UPLOAD_ERR_NO_FILE);
      $tmp = $tmps[$i] ?? null;
      $sz  = $sizes[$i] ?? null;
      $typ = $types[$i] ?? null;

      if ($debug) {
        $dbg_ref['incoming_files'][] = [
          'name'   => $nm,
          'tmp'    => $tmp,
          'size'   => $sz,
          'type'   => $typ,
          'error'  => $err,
          'is_uploaded' => $tmp ? is_uploaded_file($tmp) : false,
        ];
      }

      if ($err !== UPLOAD_ERR_OK) continue;
      if (!$tmp || !is_uploaded_file($tmp)) continue;

      $ext = strtolower(pathinfo($nm, PATHINFO_EXTENSION));
      if (!in_array($ext, ['pdf','jpg','jpeg','png'], true)) continue;

      $safe = _cap_unique_name('up', $ext);
      $target = $abs.'/'.$safe;
      $moved = @move_uploaded_file($tmp, $target);

      if ($moved) {
        @chmod($target, 0664);
        $relPath = rtrim($rel,'/').'/'.$safe;
        $saved[] = $relPath;

        if ($debug) {
          $perm = _cap_perm_octal($target);
          [$u,$g] = _cap_owner_group($target);
          $dbg_ref['incoming_files'][count($saved)-1]['saved'] = [
            'target_abs' => $target,
            'target_rel' => $relPath,
            'exists'     => file_exists($target),
            'size'       => @filesize($target),
            'perm'       => $perm,
            'owner'      => $u,
            'group'      => $g,
            'is_writable'=> is_writable($target),
          ];
        }
      } else {
        if ($debug) {
          $dbg_ref['incoming_files'][count($saved)]['move_failed'] = [
            'target_abs' => $target,
            'last_error' => error_get_last(),
          ];
        }
      }
    }
    return $saved;
  }

  // —— Endpoint AJAX: ?capture_upload=1 (+ debug optionnel) ——
  if (isset($_GET['capture_upload'])) {
    header('Content-Type: application/json; charset=utf-8');
    $dir    = $_POST['storeDir'] ?? 'uploads/misc';
    $mode   = $_POST['mode'] ?? 'data';
    $debug  = (isset($_REQUEST['debug']) && in_array(strtolower((string)$_REQUEST['debug']), ['1','true','yes'], true));
    $out    = ['ok'=>false, 'files'=>[]];

    try {
      if ($debug) {
        [$abs, $rel] = _cap_ensure_dir($dir);
        [$u,$g] = _cap_owner_group($abs);
        $out['_debug'] = [
          'time'        => date('c'),
          'request'     => [
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'uri'    => $_SERVER['REQUEST_URI'] ?? '',
            'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
            'ua'     => $_SERVER['HTTP_USER_AGENT'] ?? '',
          ],
          'php_ini'     => _cap_php_ini(),
          'target'      => [
            'storeDir_rel' => $dir,
            'storeDir_abs' => $abs,
            'exists'       => is_dir($abs),
            'is_writable'  => is_writable($abs),
            'perm'         => _cap_perm_octal($abs),
            'owner'        => $u,
            'group'        => $g,
            'realpath'     => realpath($abs),
            'free_space'   => @disk_free_space($abs),
          ],
          'post_keys'   => array_keys($_POST),
          'files_keys'  => array_keys($_FILES),
        ];
      }

      if ($mode === 'data' && !empty($_POST['dataUrl'])) {
        if ($debug) {
          $len = strlen($_POST['dataUrl']);
          $head = substr($_POST['dataUrl'], 0, 64);
          $out['_debug']['data_url'] = ['length'=> $len, 'head64'=> $head];
        }
        $saved = _cap_save_data_url($_POST['dataUrl'], $dir, $debug, $out['_debug']);
        if ($saved) { $out['ok'] = true; $out['files'] = [$saved]; }
        else { $out['error'] = 'save_data_url_failed'; }

      } elseif ($mode === 'upload') {
        $saved = _cap_handle_files($dir, 'files', $debug, $out['_debug']);
        if ($saved) { $out['ok'] = true; $out['files'] = $saved; }
        else { $out['error'] = 'move_uploaded_files_failed_or_empty'; }

      } else {
        $out['error'] = 'invalid_mode_or_missing_payload';
      }
    } catch (\Throwable $e) {
      $out['error'] = 'capture_exception';
      if ($debug) {
        $out['_debug']['exception'] = [
          'message' => $e->getMessage(),
          'file'    => $e->getFile(),
          'line'    => $e->getLine(),
          'trace'   => explode("\n", $e->getTraceAsString()),
          'last_error' => error_get_last(),
        ];
      }
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
    $debug   = (int)($options['debug'] ?? 0);

    echo '<script>window.__VL_GIFJS__=false;</script>';
    if (is_file(_cap_abs('assets/vendor/gif.js/gif.js'))) {
      echo '<script src="./assets/vendor/gif.js/gif.js"></script><script>window.__VL_GIFJS__=true;</script>';
    }
    ?>
    <style>
      .cap-modal .modal-dialog { max-width: 400px; max-height: 80vh; margin: auto; }
      .cap-modal .modal-content {
        border-radius: 12px; box-shadow: 0 10px 28px rgba(0,0,0,.25);
        background:#fff; max-height:92vh; display:flex; flex-direction:column; overflow:hidden;
        overscroll-behavior: contain;
      }
      .cap-toolbar { padding:.6rem .9rem; display:flex; align-items:center; border-bottom:1px solid #eee; gap:.5rem; }
      .cap-toolbar .title { font-weight:700; }
      .cap-toolbar .btn-debug { display:inline-flex; align-items:center; gap:.4rem; }
      <?php if (!$debug): ?>
      .cap-toolbar .btn-debug { display:none !important; }
      <?php endif; ?>

      .cap-body { flex:1 1 auto; background:#fff; overflow:auto; min-height:0; padding-bottom:.5rem; }

      @media (max-width: 576px) {
        .cap-modal .modal-dialog { max-width: 94vw; max-height: 86vh; }
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
      .cap-cam-aspect video, .cap-cam-aspect canvas, .cap-cam-aspect img.preview { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
      .cap-cam-aspect .cap-guides{ position:absolute; inset:10px; border:2px dashed rgba(255,255,255,.85); border-radius:10px; pointer-events:none; }

      .cap-controls { display:flex; gap:.5rem; justify-content:center; margin-top:.65rem; flex-wrap:nowrap; }
      .cap-controls .btn { padding:.55rem .9rem; font-weight:700; border-radius:10px; }
      .cap-controls [data-act="gif3s"].recording { background:#dc3545; color:#fff; border-color:#dc3545; animation: blink .8s infinite; }
      .cap-controls [data-act="gif3s"].uploading { background:#198754; color:#fff; border-color:#198754; animation: blink .8s infinite; }
      @keyframes blink{0%,100%{opacity:.6}50%{opacity:1}}

      .cap-pane[data-name="cam"] [data-role="list"] { display:none !important; }

      .cap-drop { border:2px dashed #cbd5e1; border-radius:12px; padding:1rem; text-align:center; max-width:640px; margin:0 auto; }
      .cap-drop.drag { border-color: var(--bs-primary); background:#f8fafc; }
      .cap-list { max-width:640px; margin:1rem auto 0; }
      .cap-item { display:flex; align-items:center; gap:.5rem; padding:.5rem; border:1px solid #eee; border-radius:10px; margin-bottom:.5rem; }
      .cap-item small { word-break:break-all; }

      @media (max-width: 576px) {
        .cap-pane { padding:.75rem; }
        .cap-cam-wrap { max-width: 94vw; }
        .cap-controls { gap:.4rem; }
        .cap-controls .btn { padding:.5rem .75rem; font-weight:700; }
      }

      /* —— PANNEAU DEBUG —— */
      .cap-debug {
        display:none;
        background:#0b1220; color:#dbeafe;
        border-top:1px solid rgba(255,255,255,.08);
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        font-size:12px; line-height:1.4;
        max-height:35vh; overflow:auto; padding:.75rem;
      }
      .cap-debug.show { display:block; }
      .cap-debug h6 { font-size:12px; margin:0 0 .5rem; color:#93c5fd; }
      .cap-debug pre { margin:0; white-space:pre-wrap; word-break:break-word; }
      .cap-debug .muted { color:#9ca3af; }
      .cap-debug .sep { margin:.5rem 0; border-top:1px dashed rgba(255,255,255,.15); }
    </style>

    <div class="modal fade cap-modal" id="<?= htmlspecialchars($modalId) ?>" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="cap-toolbar">
            <div class="title">Capture de fichiers</div>
            <div class="ms-auto d-flex align-items-center gap-2">
              <button type="button" class="btn btn-sm btn-outline-secondary btn-debug" id="<?= htmlspecialchars($modalId) ?>_dbgBtn">CMD</button>
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
                  <button type="button" class="btn btn-outline-primary" data-act="gif3s">Vidéo</button>
                  <button type="button" class="btn btn-warning" data-act="recap" title="Réinitialiser" disabled>↺</button>
                </div>
              </div>
              <div class="cap-list" data-role="list"></div>
            </div>

            <div class="cap-pane" data-name="upload">
              <div class="cap-drop" data-role="drop">
                <p class="mb-2 fw-semibold">Déposez vos fichiers ici</p>
                <p class="text-muted small mb-2">PDF, JPG, PNG (10 Mo max)</p>
                <label class="btn btn-outline-primary">
                  Choisir des fichiers
                  <input type="file" name="files[]" multiple accept=".pdf,image/jpeg,image/png" hidden>
                </label>
              </div>
              <div class="cap-list" data-role="list"></div>
            </div>
          </div>

          <div class="p-2 text-center">
            <button type="button" class="btn btn-success" data-act="done" disabled>Valider</button>
          </div>

          <!-- —— Panneau Debug —— -->
          <div class="cap-debug" id="<?= htmlspecialchars($modalId) ?>_debugPanel">
            <h6>Client logs</h6>
            <pre id="<?= htmlspecialchars($modalId) ?>_debugClient" class="muted">–</pre>
            <div class="sep"></div>
            <h6>Serveur (_debug)</h6>
            <pre id="<?= htmlspecialchars($modalId) ?>_debugServer" class="muted">–</pre>
          </div>
        </div>
      </div>
    </div>

    <script>
    (function(){
      const MODAL_ID = <?= json_encode($modalId) ?>;
      const STORE_DIR = <?= json_encode($rel) ?>;
      const MAX_W = <?= (int)$maxW ?>;
      const QUALITY = <?= (float)$quality ?>;
      const GIF_WORKER = './assets/vendor/gif.js/gif.worker.js';
      const DEBUG = <?= (int)$debug ?>;

      const GIF_MAX_W = 960, GIF_FPS = 8, GIF_DELAY = Math.round(1000 / GIF_FPS), GIF_QLTY = 16;

      const ENDPOINT = (function(){
        const base = location.pathname + (location.search ? location.search + '&' : '?') + 'capture_upload=1';
        return DEBUG ? (base + '&debug=1') : base;
      })();

      // —— Debug UI helpers ——
      const $dbgBtn   = document.getElementById(MODAL_ID + '_dbgBtn');
      const $dbgPanel = document.getElementById(MODAL_ID + '_debugPanel');
      const $dbgClient= document.getElementById(MODAL_ID + '_debugClient');
      const $dbgServer= document.getElementById(MODAL_ID + '_debugServer');

      function dbg(msg, payload){
        if (!DEBUG) return;
        try {
          const ts = new Date().toISOString();
          const line = payload !== undefined
            ? `[${ts}] ${msg} ${typeof payload === 'string' ? payload : JSON.stringify(payload)}\n`
            : `[${ts}] ${msg}\n`;
          if ($dbgClient) {
            if ($dbgClient.textContent === '–') $dbgClient.textContent = '';
            $dbgClient.textContent += line;
            // autoscroll
            $dbgClient.parentElement.scrollTop = $dbgClient.parentElement.scrollHeight;
          }
          console.debug('[CAPTURE]', msg, payload ?? '');
        } catch(e){}
      }
      function dbgServerDump(obj){
        if (!DEBUG || !$dbgServer) return;
        try {
          $dbgServer.textContent = JSON.stringify(obj, null, 2);
          $dbgServer.parentElement.scrollTop = 0;
        } catch(e){}
      }
      if ($dbgBtn) {
        $dbgBtn.addEventListener('click', ()=>{
          if (!$dbgPanel) return;
          $dbgPanel.classList.toggle('show');
        });
      }

      let onDoneCb = null;
      let modalInst = null;
      function _modal(){
        if (!modalInst) modalInst = new bootstrap.Modal(document.getElementById(MODAL_ID), {backdrop:'static'});
        return modalInst;
      }

      const $modal = document.getElementById(MODAL_ID);
      const $tabs = $modal.querySelectorAll('.cap-tab');
      const $panes = $modal.querySelectorAll('.cap-pane');

      $tabs.forEach(t=>{
        t.addEventListener('click', ()=>{
          $tabs.forEach(x=>x.classList.remove('active'));
          t.classList.add('active');
          const name = t.dataset.pane;
          $panes.forEach(p=>p.classList.toggle('active', p.dataset.name === name));
          refreshDoneState();
          dbg('Tab switched', {pane: name});
        });
      });

      const $video = $modal.querySelector('video');
      const $canvas = $modal.querySelector('canvas');
      const $camList = $modal.querySelector('[data-name="cam"] [data-role="list"]');
      const $btnDone = $modal.querySelector('[data-act="done"]');
      const $btnRecap = $modal.querySelector('[data-act="recap"]');
      const $btnGif  = $modal.querySelector('[data-act="gif3s"]');

      let stream = null;
      let previewEl = null;
      let lastSavedPaths = [];
      let isUploading = 0;
      let uplQueue = [];

      let activeControllers = [];
      function registerController(ctrl){ activeControllers.push(ctrl); return ctrl; }
      function abortAllUploads(){
        activeControllers.forEach(c=>{ try{ c.abort(); }catch(e){} });
        activeControllers = [];
        isUploading = 0;
        refreshDoneState();
        $btnDone.textContent = 'Valider';
        dbg('Uploads aborted');
      }

      function setUploading(on){
        isUploading += on ? 1 : -1;
        if (isUploading < 0) isUploading = 0;
        refreshDoneState();
        $btnDone.textContent = (isUploading > 0) ? 'Envoi…' : 'Valider';
        dbg('setUploading', {count:isUploading});
      }
      function refreshDoneState(){
        const hasChoixUpload = uplQueue.length > 0;
        const hasSaved = lastSavedPaths.length > 0;
        $btnDone.disabled = (isUploading > 0) || !(hasChoixUpload || hasSaved);
      }

      async function startCamera() {
        await stopCamera();
        try {
          const opts = { video: { facingMode: { ideal: 'environment' } }, audio:false };
          dbg('startCamera opts', opts);
          stream = await navigator.mediaDevices.getUserMedia(opts);
          $video.srcObject = stream;
          dbg('camera started', stream.getVideoTracks().map(t=>t.label));
        } catch(e) {
          console.warn(e); alert("Caméra indisponible.");
          dbg('startCamera error', {message:e?.message});
        }
      }
      async function stopCamera(){
        if (stream) { stream.getTracks().forEach(t=>t.stop()); stream = null; dbg('camera stopped'); }
      }

      function showPreviewCanvas() {
        $video.style.display = 'none';
        $canvas.style.display = 'block';
        previewEl = $canvas;
        $btnRecap.disabled = false;
        dbg('preview from canvas');
      }
      function showPreviewImage(dataUrl) {
        if (!previewEl || previewEl.tagName !== 'IMG') {
          const img = document.createElement('img');
          img.className = 'preview';
          $video.parentElement.appendChild(img);
          previewEl = img;
        }
        previewEl.src = dataUrl;
        $video.style.display = 'none';
        $canvas.style.display = 'none';
        $btnRecap.disabled = false;
        dbg('preview from image', {len:(dataUrl||'').length});
      }

      async function recapture() {
        abortAllUploads();
        uplQueue = [];
        const $uplList = $modal.querySelector('[data-name="upload"] [data-role="list"]');
        $uplList && ($uplList.innerHTML = '');
        if (previewEl && previewEl.tagName === 'IMG') { previewEl.remove(); previewEl = null; }
        $canvas.style.display = 'none';
        $video.style.display = 'block';
        $btnRecap.disabled = true;
        $btnGif.classList.remove('recording','uploading');
        $btnGif.textContent = 'Vidéo';
        dbg('recapture reset');
        await startCamera();
      }
      $btnRecap.addEventListener('click', recapture);

      async function takePhoto() {
        if (!stream) { await startCamera(); if (!stream) return; }
        const w = $video.videoWidth || 1280;
        const h = $video.videoHeight|| 720;
        const scale = w > MAX_W ? (MAX_W / w) : 1;
        const tw = Math.round(w*scale), th = Math.round(h*scale);
        dbg('takePhoto dims', {src:`${w}x${h}`, out:`${tw}x${th}`, quality:QUALITY});

        $canvas.width = tw; $canvas.height = th;
        const ctx = $canvas.getContext('2d');
        ctx.drawImage($video, 0,0, tw, th);
        showPreviewCanvas();

        const dataUrl = $canvas.toDataURL('image/jpeg', QUALITY);
        setUploading(true);
        const json = await uploadDataUrl(dataUrl);
        dbg('photo upload resp', json);
        if (json && json.ok && json.files && json.files.length) {
          lastSavedPaths.unshift(json.files[0]);
          addListItemCam(json.files[0]);
        } else if (!json?.ok) {
          alert('Erreur upload (photo).'); dbg('photo upload failed');
        }
        setUploading(false);
      }

      async function recordGif3s() {
        if (!window.__VL_GIFJS__ || !(window.GIF)) {
          alert("gif.js requis. Placez ./assets/vendor/gif.js/gif.js et gif.worker.js"); return;
        }
        if (!stream) { await startCamera(); if (!stream) return; }

        $btnGif.classList.add('recording'); $btnGif.classList.remove('uploading'); $btnGif.textContent = 'Vidéo';

        const vw = $video.videoWidth || 640, vh = $video.videoHeight|| 480;
        const scaleW = Math.min(1, GIF_MAX_W / (vw || GIF_MAX_W));
        const tw = Math.max(1, Math.round((vw || 640) * scaleW));
        const th = Math.max(1, Math.round((vh || 480) * scaleW));
        dbg('recordGif3s dims', {src:`${vw}x${vh}`, out:`${tw}x${th}`, fps:GIF_FPS, delay:GIF_DELAY, quality:GIF_QLTY});

        const c = document.createElement('canvas'); c.width = tw; c.height = th;
        const ctx = c.getContext('2d');
        const gif = new GIF({ workers:2, quality: GIF_QLTY, width:tw, height:th, workerScript: GIF_WORKER, repeat:0 });

        const frames = GIF_FPS * 3; let n = 0;
        const frameTimer = setInterval(()=>{
          ctx.drawImage($video, 0,0, tw, th);
          gif.addFrame(c, {copy:true, delay: GIF_DELAY});
          if (++n >= frames) {
            clearInterval(frameTimer);
            $btnGif.classList.remove('recording'); $btnGif.classList.add('uploading'); $btnGif.textContent = 'Envoi…';
            dbg('gif frames added', {count:n});

            gif.on('finished', async function(blob){
              dbg('gif finished blob', {size: blob.size});
              const fr = new FileReader();
              fr.onload = async () => {
                const dataUrl = fr.result;
                showPreviewImage(dataUrl);
                setUploading(true);
                const json = await uploadDataUrl(dataUrl);
                dbg('gif upload resp', json);
                if (json && json.ok && json.files && json.files.length) {
                  lastSavedPaths.unshift(json.files[0]);
                  addListItemCam(json.files[0]);
                } else if (!json?.ok) {
                  alert('Erreur upload (gif).'); dbg('gif upload failed');
                }
                setUploading(false);
                $btnGif.classList.remove('uploading'); $btnGif.textContent = 'Vidéo';
              };
              fr.readAsDataURL(blob);
            });
            gif.render();
          }
        }, GIF_DELAY);
      }

      async function uploadDataUrl(dataUrl){
        const fd = new FormData();
        fd.append('storeDir', STORE_DIR);
        fd.append('mode', 'data');
        fd.append('dataUrl', dataUrl);
        if (DEBUG) fd.append('debug', '1');

        const ctrl = registerController(new AbortController());
        try{
          dbg('POST dataUrl ->', {ENDPOINT});
          const resp = await fetch(ENDPOINT, {method:'POST', body: fd, signal: ctrl.signal});
          const text = await resp.text();
          dbg('raw response', text.slice(0, 400) + (text.length>400?'…':''));
          let json = null; try { json = JSON.parse(text); } catch(e){ dbg('JSON parse error', e?.message); }
          if (json && json._debug) { dbg('SERVER DEBUG', '— dump attached —'); dbgServerDump(json._debug); }
          return json;
        } catch(e){
          dbg('fetch error', e?.message || e);
          return null;
        } finally {
          activeControllers = activeControllers.filter(c => c !== ctrl);
        }
      }

      function addListItemCam(_relPath){ refreshDoneState(); }

      $modal.querySelector('[data-act="photo"]').addEventListener('click', takePhoto);
      $modal.querySelector('[data-act="gif3s"]').addEventListener('click', recordGif3s);

      const $drop = $modal.querySelector('[data-name="upload"] [data-role="drop"]');
      const $file = $drop ? $drop.querySelector('input[type="file"]') : null;
      const $uplList = $modal.querySelector('[data-name="upload"] [data-role="list"]');

      if ($drop && $file) {
        ['dragenter','dragover'].forEach(ev=>{
          $drop.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); $drop.classList.add('drag'); });
        });
        ['dragleave','drop'].forEach(ev=>{
          $drop.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); $drop.classList.remove('drag'); });
        });
        $drop.addEventListener('drop', e=>{
          const files = Array.from(e.dataTransfer.files || []);
          pushToQueue(files);
        });
        $file.addEventListener('change', e=>{
          pushToQueue(Array.from($file.files||[]));
          $file.value = '';
        });
      }

      function pushToQueue(files){
        const allowed = ['application/pdf','image/jpeg','image/png'];
        for (const f of files) {
          if (!allowed.includes(f.type)) continue;
          if (f.size > 10*1024*1024) continue;
          uplQueue.push(f);
          const li = document.createElement('div');
          li.className='cap-item';
          li.innerHTML = '📄 <small>'+f.name+' ('+Math.round(f.size/1024)+' Ko)</small>';
          $uplList.appendChild(li);
        }
        dbg('files queued', uplQueue.map(f=>({name:f.name,size:f.size,type:f.type})));
        refreshDoneState();
      }

      $modal.querySelector('[data-act="done"]').addEventListener('click', async ()=>{
        if (uplQueue.length > 0) {
          const fd = new FormData();
          fd.append('storeDir', STORE_DIR);
          fd.append('mode', 'upload');
          uplQueue.forEach(f=> fd.append('files[]', f, f.name));
          if (DEBUG) fd.append('debug', '1');
          const ctrl = registerController(new AbortController());
          setUploading(true);
          try{
            dbg('POST files ->', {ENDPOINT, count: uplQueue.length});
            const resp = await fetch(ENDPOINT, {method:'POST', body: fd, signal: ctrl.signal});
            const text = await resp.text();
            dbg('raw response (files)', text.slice(0, 400) + (text.length>400?'…':''));
            let json = null; try{ json = JSON.parse(text); }catch(e){ dbg('JSON parse error', e?.message); }
            if (json && json._debug) { dbg('SERVER DEBUG (files)', '— dump attached —'); dbgServerDump(json._debug); }
            if (json && json.ok) {
              for (const p of json.files) { lastSavedPaths.unshift(p); }
              uplQueue = [];
              $uplList.innerHTML = '';
            } else {
              alert('Erreur upload (fichiers).'); dbg('files upload failed', json);
            }
          } catch(e){
            dbg('fetch error (files)', e?.message || e);
          } finally {
            setUploading(false);
            activeControllers = activeControllers.filter(c => c !== ctrl);
          }
        }
        if (onDoneCb) { try { onDoneCb(lastSavedPaths.slice()); } catch(e){ dbg('onDone error', e?.message); } }
        _modal().hide();
      });

      $modal.addEventListener('shown.bs.modal', async ()=>{
        lastSavedPaths = [];
        uplQueue = [];
        isUploading = 0;
        activeControllers = [];
        $uplList && ($uplList.innerHTML = '');
        $btnDone.disabled = true;
        if ($dbgClient) $dbgClient.textContent = '–';
        if ($dbgServer) $dbgServer.textContent = '–';
        dbg('modal shown');
        await startCamera();
      });
      $modal.addEventListener('hidden.bs.modal', async ()=>{
        abortAllUploads();
        await stopCamera();
        if (previewEl && previewEl.tagName === 'IMG') { previewEl.remove(); previewEl = null; }
        $canvas.style.display = 'none';
        $video.style.display = 'block';
        $btnRecap.disabled = true;
        $uplList && ($uplList.innerHTML = '');
        $btnGif.classList.remove('recording','uploading');
        $btnGif.textContent = 'Vidéo';
        dbg('modal hidden, cleaned');
      });

      window.openCaptureModal = function(opts){
        onDoneCb = (opts && typeof opts.onDone === 'function') ? opts.onDone : null;
        dbg('openCaptureModal', {onDone: !!onDoneCb, STORE_DIR, ENDPOINT});
        _modal().show();
      };
    })();
  
/* Auto-shim injecté par includes/capture.php — ne nécessite aucune configuration côté page */
(function () {
  // construit automatiquement l'URL basée sur la page courante (fonctionne en sous-dossier)
  const ABS = new URL('includes/capture.php?capture_upload=1', document.baseURI || location.href).href;

  const _fetch = window.fetch;
  function hasUp(u){ return /(?:^|[?&])capture_upload=1\b/.test(u); }
  function hasDbg(u){ return /(?:^|[?&])debug=1\b/.test(u); }

  window.fetch = function (input, init) {
    try {
      if (typeof input === 'string' && hasUp(input)) {
        input = ABS + (hasDbg(input) ? '&debug=1' : '');
      } else if (input && typeof input === 'object' && 'url' in input && hasUp(input.url)) {
        const newUrl = ABS + (hasDbg(input.url) ? '&debug=1' : '');
        input = new Request(newUrl, input); // conserve méthode/headers/body
      }
    } catch (e) {
      console.warn('capture.shim error', e);
    }

    // s'assurer que les cookies/session sont envoyés
    if (!init) init = {};
    if (!('credentials' in init)) init.credentials = 'same-origin';
    return _fetch(input, init);
  };
})();

    </script>
    <?php
  }
}
<?php
// api/voice_consult.php
// POST multipart: audio (webm blob) + csrf_token
// → OpenAI STT (gpt-4o-mini-transcribe) → GPT extraction (gpt-4o-mini)
// → JSON { ok:1, transcript:"...", fields:{motif,poids,examen,anamnese,diagnostic,traitement} }

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    require_login();

    // ── CSRF ─────────────────────────────────────────────────────────
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        echo json_encode(['ok' => 0, 'msg' => 'Token CSRF invalide']); exit;
    }

    // ── Fichier audio ─────────────────────────────────────────────────
    if (empty($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['ok' => 0, 'msg' => 'Fichier audio manquant ou invalide']); exit;
    }
    if ($_FILES['audio']['size'] > 25 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['ok' => 0, 'msg' => 'Fichier trop volumineux (max 25 Mo)']); exit;
    }

    // ── Clé API ───────────────────────────────────────────────────────
    $apiKey = $_ENV['OPEN_AI_API_KEY'] ?? '';
    if (empty($apiKey)) {
        http_response_code(500);
        echo json_encode(['ok' => 0, 'msg' => 'Clé API OpenAI non configurée']); exit;
    }

    // ── Étape 1 : transcription STT ───────────────────────────────────
    $transcript = stt_transcribe($_FILES['audio']['tmp_name'], $apiKey);
    if ($transcript === null) {
        http_response_code(502);
        echo json_encode(['ok' => 0, 'msg' => 'Échec de la transcription (vérifiez la clé API)']); exit;
    }
    if (trim($transcript) === '') {
        echo json_encode(['ok' => 0, 'msg' => 'Aucune parole détectée dans l\'enregistrement']); exit;
    }

    // ── Étape 2 : extraction des champs ───────────────────────────────
    $fields = gpt_extract_fields($transcript, $apiKey);
    if ($fields === null) {
        http_response_code(502);
        echo json_encode(['ok' => 0, 'msg' => 'Échec de l\'extraction des champs']); exit;
    }

    echo json_encode(
        ['ok' => 1, 'transcript' => $transcript, 'fields' => $fields],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => 0, 'msg' => 'Erreur serveur interne']); exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// STT  :  OpenAI gpt-4o-mini-transcribe
// ─────────────────────────────────────────────────────────────────────────────
function stt_transcribe(string $tmpPath, string $apiKey): ?string
{
    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'model'    => 'gpt-4o-mini-transcribe',
            'language' => 'fr',
            'file'     => new CURLFile($tmpPath, 'audio/webm', 'enregistrement.webm'),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$resp) return null;

    $data = json_decode($resp, true);
    return isset($data['text']) ? (string)$data['text'] : null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Extraction des champs  :  gpt-4o-mini  →  JSON strict
// ─────────────────────────────────────────────────────────────────────────────
function gpt_extract_fields(string $transcript, string $apiKey): ?array
{
    $system = <<<'SYS'
Tu es un assistant vétérinaire expert. Tu analyses la transcription vocale d'un vétérinaire et extrais les informations dans un JSON strict.

RÈGLES ABSOLUES :
1. Ne retourne QUE les champs explicitement mentionnés dans la transcription ; mets null pour tout ce qui n'est pas dit.
2. Ne devine rien, ne complète pas, n'invente aucune information.
3. Reformule SYSTÉMATIQUEMENT le contenu en terminologie médicale vétérinaire professionnelle : remplace les expressions familières ou approximatives par les termes cliniques appropriés (ex : "il boite" → "Boiterie du membre", "a vomi" → "Épisodes de vomissements", "les yeux qui coulent" → "Épiphora bilatéral").
4. TYPOGRAPHIE — toutes les valeurs textuelles :
   - Chaque phrase commence par une majuscule.
   - Les abréviations médicales standard restent en majuscules (IV, SC, SID, BID, TID, PO, etc.).
5. motif : phrase nominale courte (≤ 10 mots), majuscule initiale.
6. examen, anamnese, diagnostic : texte continu, phrases complètes, chaque phrase commence par une majuscule, séparées par un point.
7. traitement : liste à tirets, UN tiret par ligne, chaque ligne commence par "- " suivi d'une majuscule.
   Exemple de format traitement :
   "- Amoxicilline 20 mg/kg PO BID pendant 7 jours.\n- Anti-inflammatoire non stéroïdien : méloxicam 0,2 mg/kg SC SID pendant 3 jours.\n- Repos strict en cage pendant 5 jours."
8. poids : chaîne numérique avec virgule décimale uniquement (ex : "5,4"), sans unité, ou null.
9. montant_ligne : nombre décimal uniquement (ex : "150" ou "75.50"), sans symbole monétaire, ou null.
10. commentaire_facturation : texte court, majuscule initiale, ou null.
11. mode : "append" si le vétérinaire dit "ajoute", "ajouter", "rajoute" ou "en plus" ; sinon "replace".
12. Si la transcription ne porte que sur quelques champs, seuls ceux-là seront non-null.

Retourne UNIQUEMENT le JSON ci-dessous, sans markdown, sans commentaire :
{
  "mode": "replace",
  "motif": null,
  "poids": null,
  "commentaire_facturation": null,
  "montant_ligne": null,
  "examen": null,
  "anamnese": null,
  "diagnostic": null,
  "traitement": null
}
SYS;

    $payload = json_encode([
        'model'           => 'gpt-4o-mini',
        'messages'        => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => "Transcription :\n" . $transcript],
        ],
        'temperature'     => 0.1,
        'response_format' => ['type' => 'json_object'],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$resp) return null;

    $data    = json_decode($resp, true);
    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!$content) return null;

    $raw = json_decode($content, true);
    if (!is_array($raw)) return null;

    // mode : "append" ou "replace"
    $mode = (isset($raw['mode']) && $raw['mode'] === 'append') ? 'append' : 'replace';

    // Filtrer : uniquement les clés attendues, valeurs string non vides ou null
    $allowed = ['motif', 'poids', 'commentaire_facturation', 'montant_ligne', 'examen', 'anamnese', 'diagnostic', 'traitement'];
    $clean   = ['mode' => $mode];
    foreach ($allowed as $key) {
        $v          = $raw[$key] ?? null;
        $clean[$key] = (is_string($v) && trim($v) !== '') ? trim($v) : null;
    }

    return $clean;
}

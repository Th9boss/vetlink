<?php
// api/voice_imagerie.php
// POST multipart: audio (webm blob) + csrf_token
// → OpenAI STT → GPT reformulation spécialisée imagerie vétérinaire

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    require_login();

    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        echo json_encode(['ok' => 0, 'msg' => 'Token CSRF invalide']); exit;
    }

    if (empty($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['ok' => 0, 'msg' => 'Fichier audio manquant ou invalide']); exit;
    }
    if ($_FILES['audio']['size'] > 25 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['ok' => 0, 'msg' => 'Fichier trop volumineux (max 25 Mo)']); exit;
    }

    $apiKey = $_ENV['OPEN_AI_API_KEY'] ?? '';
    if ($apiKey === '') {
        http_response_code(500);
        echo json_encode(['ok' => 0, 'msg' => 'Clé API OpenAI non configurée']); exit;
    }

    $transcript = stt_transcribe_imagerie($_FILES['audio']['tmp_name'], $apiKey);
    if ($transcript === null) {
        http_response_code(502);
        echo json_encode(['ok' => 0, 'msg' => 'Échec de la transcription']); exit;
    }
    if (trim($transcript) === '') {
        echo json_encode(['ok' => 0, 'msg' => 'Aucune parole détectée dans l\'enregistrement']); exit;
    }

    $report = gpt_rewrite_imagerie_report($transcript, $apiKey);
    if ($report === null || trim($report) === '') {
        http_response_code(502);
        echo json_encode(['ok' => 0, 'msg' => 'Échec de la reformulation du compte-rendu']); exit;
    }

    echo json_encode([
        'ok' => 1,
        'transcript' => $transcript,
        'report' => trim($report),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => 0, 'msg' => 'Erreur serveur interne']); exit;
}

function stt_transcribe_imagerie(string $tmpPath, string $apiKey): ?string
{
    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'model' => 'gpt-4o-mini-transcribe',
            'language' => 'fr',
            'file' => new CURLFile($tmpPath, 'audio/webm', 'enregistrement.webm'),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 180,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$resp) return null;

    $data = json_decode($resp, true);
    return isset($data['text']) ? (string)$data['text'] : null;
}

function gpt_rewrite_imagerie_report(string $transcript, string $apiKey): ?string
{
    $system = <<<'SYS'
Tu es un spécialiste en imagerie vétérinaire.

Ta mission :
- reformuler la dictée du vétérinaire en un compte-rendu d'imagerie clair, sobre, médical et cohérent ;
- conserver STRICTEMENT le sens clinique de ce qui a été prononcé ;
- ne jamais inventer un constat, une lésion, une conclusion ou une recommandation non dite ;
- ne jamais supprimer une nuance, une hésitation, une incertitude ou une réserve exprimée oralement ;
- si le vétérinaire formule une hypothèse, elle doit rester une hypothèse ;
- si la dictée est incomplète, tu reformules seulement ce qui existe.

Style attendu :
- français médical professionnel ;
- texte fluide, sans markdown, sans puces ;
- phrases complètes, ponctuation propre ;
- terminologie d'imagerie vétérinaire appropriée ;
- ton neutre, clinique, précis.

Important :
- corrige uniquement la forme, pas le fond ;
- n'ajoute aucune interprétation personnelle ;
- ne crée aucune structure standard artificielle si elle n'est pas soutenue par la dictée ;
- retourne uniquement le compte-rendu final brut.
SYS;

    $payload = json_encode([
        'model' => 'gpt-4o-mini',
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "Dictée du vétérinaire :\n" . $transcript],
        ],
        'temperature' => 0.1,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$resp) return null;

    $data = json_decode($resp, true);
    $content = $data['choices'][0]['message']['content'] ?? null;
    return is_string($content) ? trim($content) : null;
}

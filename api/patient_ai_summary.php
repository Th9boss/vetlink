<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    require_login();
    csrf_check();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => 0, 'msg' => 'Méthode invalide'], 405);
    }

    $patientId = (int)($_POST['patient_id'] ?? 0);
    $mode = (string)($_POST['mode'] ?? 'latest');
    if (!in_array($mode, ['latest', 'history'], true)) {
        $mode = 'latest';
    }

    if ($patientId <= 0) {
        json_response(['ok' => 0, 'msg' => 'Patient invalide'], 400);
    }

    $apiKey = trim((string)($_ENV['DEEPSEEK_API_KEY'] ?? ''));
    $model = trim((string)($_ENV['DEEPSEEK_MODEL'] ?? 'deepseek-chat'));
    $apiUrl = trim((string)($_ENV['DEEPSEEK_API_URL'] ?? 'https://api.deepseek.com/chat/completions'));
    if ($apiKey === '') {
        json_response(['ok' => 0, 'msg' => 'Clé API DeepSeek non configurée'], 500);
    }

    $patient = load_patient_context($patientId);
    if (!$patient) {
        json_response(['ok' => 0, 'msg' => 'Patient introuvable'], 404);
    }

    $consultations = load_patient_consultations($patientId);
    $selectedConsultations = $mode === 'latest' ? array_slice($consultations, 0, 1) : $consultations;
    $payload = build_ai_payload($patient, $selectedConsultations);
    if (empty($payload['consultations'])) {
        json_response(['ok' => 0, 'msg' => 'Aucune consultation exploitable'], 400);
    }

    $sourceStamp = build_source_stamp($selectedConsultations);
    $sourceHash = build_source_hash($payload);
    $cache = load_patient_ai_cache($patientId, $mode);
    if ($cache && (string)$cache['source_stamp'] === $sourceStamp && (string)$cache['source_hash'] === $sourceHash) {
        json_response([
            'ok' => 1,
            'summary' => trim((string)$cache['summary']),
            'mode' => $mode,
            'label' => $mode === 'history' ? 'Aperçu global du cas' : 'Résumé de la dernière consultation',
            'cached' => 1,
        ]);
    }

    $summary = deepseek_patient_summary($payload, $mode, $apiUrl, $apiKey, $model);
    if ($summary === null || trim($summary) === '') {
        json_response(['ok' => 0, 'msg' => 'Échec de la génération du résumé'], 502);
    }

    save_patient_ai_cache($patientId, $mode, $sourceStamp, $sourceHash, trim($summary), $model);

    json_response([
        'ok' => 1,
        'summary' => trim($summary),
        'mode' => $mode,
        'label' => $mode === 'history' ? 'Aperçu global du cas' : 'Résumé de la dernière consultation',
        'cached' => 0,
    ]);
} catch (Throwable $e) {
    json_response(['ok' => 0, 'msg' => 'Erreur serveur interne'], 500);
}

function load_patient_context(int $patientId): ?array
{
    $st = db()->prepare("
        SELECT p.*,
               c.nom AS c_nom,
               c.prenom AS c_prenom,
               c.gsm AS c_gsm,
               c.email AS c_email
        FROM patients p
        JOIN clients c ON c.id = p.client_id
        WHERE p.id = ?
        LIMIT 1
    ");
    $st->execute([$patientId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function load_patient_consultations(int $patientId): array
{
    $cq = db()->prepare("
        SELECT c.*, CONCAT_WS(' ', u.prenom, u.nom) AS praticien_nom
        FROM consultations c
        LEFT JOIN users u ON u.id = c.praticien_id
        WHERE c.patient_id = ?
        ORDER BY c.date_consult DESC, c.id DESC
    ");
    $cq->execute([$patientId]);
    $consultations = $cq->fetchAll(PDO::FETCH_ASSOC);
    if (!$consultations) {
        return [];
    }

    $ids = array_column($consultations, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));

    $analysesByConsult = [];
    $a = db()->prepare("SELECT * FROM analyses WHERE consultation_id IN ($in) ORDER BY id DESC");
    $a->execute($ids);
    foreach ($a->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $analysesByConsult[(int)$row['consultation_id']][] = $row;
    }

    $imageriesByConsult = [];
    $im = db()->prepare("SELECT * FROM imageries WHERE consultation_id IN ($in) ORDER BY id DESC");
    $im->execute($ids);
    foreach ($im->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $imageriesByConsult[(int)$row['consultation_id']][] = $row;
    }

    foreach ($consultations as &$consult) {
        $cid = (int)$consult['id'];
        $consult['_analyses'] = $analysesByConsult[$cid] ?? [];
        $consult['_imageries'] = $imageriesByConsult[$cid] ?? [];
    }
    unset($consult);

    return $consultations;
}

function build_ai_payload(array $patient, array $consultations): array
{
    return [
        'patient' => compact_patient_context($patient),
        'consultations' => array_values(array_filter(array_map('compact_consultation_context', $consultations))),
    ];
}

function compact_patient_context(array $patient): array
{
    return array_filter([
        'nom' => trim((string)($patient['nom'] ?? '')),
        'espece' => trim((string)($patient['espece'] ?? '')),
        'race' => trim((string)($patient['race'] ?? '')),
        'sexe' => trim((string)($patient['sexe'] ?? '')),
        'date_naissance' => trim((string)($patient['date_naissance'] ?? '')),
        'robe' => trim((string)($patient['robe'] ?? '')),
        'sterilise' => normalize_yes_no($patient['sterilise'] ?? null),
        'client' => trim((string)(($patient['c_nom'] ?? '') . ' ' . ($patient['c_prenom'] ?? ''))),
    ], 'payload_value_present');
}

function compact_consultation_context(array $consult): array
{
    $analyses = [];
    foreach (($consult['_analyses'] ?? []) as $analyse) {
        $item = array_filter([
            'type' => trim((string)($analyse['type_analyse'] ?? '')),
            'resultat' => trim((string)($analyse['resultat'] ?? '')),
            'unite' => trim((string)($analyse['unite'] ?? '')),
            'reference' => build_reference_range($analyse),
        ], 'payload_value_present');
        if ($item) {
            $analyses[] = $item;
        }
    }

    $imageries = [];
    foreach (($consult['_imageries'] ?? []) as $imagerie) {
        $item = array_filter([
            'type' => trim((string)($imagerie['type_imagerie'] ?? '')),
            'compte_rendu' => trim((string)($imagerie['compte_rendu'] ?? '')),
        ], 'payload_value_present');
        if ($item) {
            $imageries[] = $item;
        }
    }

    $compact = array_filter([
        'date' => trim((string)($consult['date_consult'] ?? '')),
        'praticien' => trim((string)($consult['praticien_nom'] ?? '')),
        'motif' => trim((string)($consult['motif'] ?? '')),
        'poids' => payload_number_or_null($consult['poids'] ?? null),
        'temperature' => payload_number_or_null($consult['temperature'] ?? null),
        'anamnese' => trim((string)($consult['anamnese'] ?? '')),
        'examen' => trim((string)($consult['examen'] ?? '')),
        'diagnostic' => trim((string)($consult['diagnostic'] ?? '')),
        'traitement' => trim((string)($consult['traitement'] ?? '')),
        'commentaire_facturation' => trim((string)($consult['commentaire_facturation'] ?? '')),
    ], 'payload_value_present');

    if ($analyses) {
        $compact['analyses'] = $analyses;
    }
    if ($imageries) {
        $compact['imageries'] = $imageries;
    }

    return $compact;
}

function build_reference_range(array $analyse): ?string
{
    $min = trim((string)($analyse['ref_min'] ?? ''));
    $max = trim((string)($analyse['ref_max'] ?? ''));
    if ($min === '' && $max === '') {
        return null;
    }
    return trim($min . ' - ' . $max);
}

function normalize_yes_no($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    return ((string)$value === '1' || strtolower((string)$value) === 'oui') ? 'oui' : 'non';
}

function payload_number_or_null($value)
{
    if ($value === null || $value === '') {
        return null;
    }
    return is_numeric($value) ? (float)$value : trim((string)$value);
}

function payload_value_present($value): bool
{
    if (is_array($value)) {
        return $value !== [];
    }
    if (is_string($value)) {
        return trim($value) !== '';
    }
    return $value !== null;
}

function build_source_hash(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return hash('sha256', $json === false ? serialize($payload) : $json);
}

function build_source_stamp(array $consultations): string
{
    $latest = 0;
    foreach ($consultations as $consult) {
        foreach ([
            $consult['updated_at'] ?? null,
            $consult['created_at'] ?? null,
            $consult['date_consult'] ?? null,
        ] as $candidate) {
            $latest = max($latest, safe_strtotime($candidate));
        }

        foreach (($consult['_analyses'] ?? []) as $analyse) {
            foreach ([$analyse['updated_at'] ?? null, $analyse['created_at'] ?? null] as $candidate) {
                $latest = max($latest, safe_strtotime($candidate));
            }
        }

        foreach (($consult['_imageries'] ?? []) as $imagerie) {
            foreach ([$imagerie['updated_at'] ?? null, $imagerie['created_at'] ?? null] as $candidate) {
                $latest = max($latest, safe_strtotime($candidate));
            }
        }
    }

    if ($latest <= 0) {
        $latest = time();
    }

    return date('Y-m-d H:i:s', $latest);
}

function safe_strtotime($value): int
{
    if (!is_string($value) || trim($value) === '') {
        return 0;
    }
    $ts = strtotime($value);
    return $ts !== false ? $ts : 0;
}

function load_patient_ai_cache(int $patientId, string $scope): ?array
{
    $st = db()->prepare('SELECT * FROM patient_ai_cache WHERE patient_id = ? AND scope = ? LIMIT 1');
    $st->execute([$patientId, $scope]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function save_patient_ai_cache(int $patientId, string $scope, string $sourceStamp, string $sourceHash, string $summary, string $model): void
{
    $sql = "
        INSERT INTO patient_ai_cache (patient_id, scope, source_stamp, source_hash, summary, provider, model, cached_at)
        VALUES (?, ?, ?, ?, ?, 'deepseek', ?, NOW())
        ON DUPLICATE KEY UPDATE
            source_stamp = VALUES(source_stamp),
            source_hash = VALUES(source_hash),
            summary = VALUES(summary),
            provider = VALUES(provider),
            model = VALUES(model),
            cached_at = NOW()
    ";
    db()->prepare($sql)->execute([$patientId, $scope, $sourceStamp, $sourceHash, $summary, $model]);
}

function deepseek_patient_summary(array $payload, string $mode, string $apiUrl, string $apiKey, string $model): ?string
{
    $system = $mode === 'history'
        ? patient_history_system_prompt()
        : patient_latest_system_prompt();

    $userPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($userPayload === false) {
        return null;
    }

    $body = json_encode([
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userPayload],
        ],
        'temperature' => 0.2,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($body === false) {
        return null;
    }

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 90,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$resp) {
        return null;
    }

    $data = json_decode($resp, true);
    $content = $data['choices'][0]['message']['content'] ?? null;
    return is_string($content) ? trim($content) : null;
}

function patient_latest_system_prompt(): string
{
    return <<<'SYS'
Tu es un assistant vétérinaire expert.

Tu reçois un JSON compact contenant le contexte patient et les données réellement disponibles de la dernière consultation.

Ta mission :
- produire un résumé clinique court, utile et directement exploitable ;
- mettre en avant uniquement les éléments réellement importants de cette dernière consultation ;
- intégrer si présentes les analyses disponibles et les comptes-rendus d'imagerie ;
- rester strictement fidèle aux données fournies ;
- ne rien inventer, ne pas extrapoler, ne pas compléter ;
- si une donnée manque, tu n'en parles pas.

Style attendu :
- français professionnel, sobre et synthétique ;
- 3 à 6 phrases courtes maximum ;
- pas de markdown, pas de puces, pas de titres ;
- ton d'assistant vétérinaire expérimenté ;
- éviter les banalités, aller droit à l'essentiel.
SYS;
}

function patient_history_system_prompt(): string
{
    return <<<'SYS'
Tu es un assistant vétérinaire expert.

Tu reçois un JSON compact contenant le contexte patient et l'historique pertinent des consultations.

Ta mission :
- produire un aperçu général du cas depuis le début ;
- faire ressortir l'évolution, les points récurrents, les examens marquants, les analyses disponibles et les imageries importantes ;
- garder une vue globale clinique, sans noyer le lecteur ;
- rester strictement fidèle aux données fournies ;
- ne rien inventer, ne pas extrapoler, ne pas compléter ;
- si une donnée manque, tu n'en parles pas.

Style attendu :
- français professionnel, sobre et synthétique ;
- 4 à 7 phrases courtes maximum ;
- pas de markdown, pas de puces, pas de titres ;
- ton d'assistant vétérinaire expérimenté ;
- aperçu global pertinent, pas de roman.
SYS;
}

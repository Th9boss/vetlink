<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

require_login();

function api_ok(array $payload = []): void {
    echo json_encode(['ok' => true] + $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_fail(string $message, int $status = 400, array $extra = []): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalize_ma_phone(string $raw): string {
    $s = preg_replace('/[^0-9+]/', '', $raw ?? '');
    if ($s === null) $s = '';
    if (strpos($s, '00') === 0) $s = '+' . substr($s, 2);
    if (strpos($s, '+212') === 0) return $s;
    if (strpos($s, '212') === 0) return '+' . $s;
    if (strpos($s, '0') === 0) return '+212' . substr($s, 1);
    if (strpos($s, '+') === 0) return $s;
    return $s !== '' ? '+212' . $s : '';
}

function client_latlng_candidates(): array {
    return [
        ['latitude', 'longitude'],
        ['gps_lat', 'gps_lng'],
        ['lat', 'lng'],
        ['lat', 'lon'],
        ['coord_lat', 'coord_lng'],
    ];
}

function detect_client_latlng_columns(PDO $pdo): array {
    static $cached = null;
    if ($cached !== null) return $cached;
    $cols = [];
    $q = $pdo->query("SHOW COLUMNS FROM clients");
    foreach (($q ? $q->fetchAll(PDO::FETCH_ASSOC) : []) as $c) {
        $cols[strtolower($c['Field'])] = $c['Field'];
    }
    foreach (client_latlng_candidates() as [$la, $lo]) {
        if (isset($cols[strtolower($la)], $cols[strtolower($lo)])) {
            $cached = [$cols[strtolower($la)], $cols[strtolower($lo)]];
            return $cached;
        }
    }
    $cached = [null, null];
    return $cached;
}

function row_latlng(array $row): array {
    foreach (client_latlng_candidates() as [$la, $lo]) {
        if (array_key_exists($la, $row) && array_key_exists($lo, $row) && $row[$la] !== null && $row[$lo] !== null && $row[$la] !== '' && $row[$lo] !== '') {
            return [floatval($row[$la]), floatval($row[$lo])];
        }
    }
    return [null, null];
}

function fetch_client_by_id(PDO $pdo, int $id): ?array {
    if ($id <= 0) return null;
    $st = $pdo->prepare("SELECT * FROM clients WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    $pc = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE client_id=?");
    $pc->execute([$id]);
    $count = (int)$pc->fetchColumn();

    [$lat, $lng] = row_latlng($row);
    return [
        'id' => (int)$row['id'],
        'nom' => (string)($row['nom'] ?? ''),
        'prenom' => (string)($row['prenom'] ?? ''),
        'gsm' => (string)($row['gsm'] ?? ''),
        'email' => (string)($row['email'] ?? ''),
        'adresse' => (string)($row['adresse'] ?? ''),
        'lat' => $lat,
        'lng' => $lng,
        'patient_count' => $count,
        'updated_at' => (string)($row['updated_at'] ?? ''),
    ];
}

try {
    $pdo = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $action = strtolower(trim((string)($_GET['action'] ?? 'list')));
        if ($action !== 'list') api_fail('Action GET invalide.');

        $q = trim((string)($_GET['q'] ?? ''));
        $page = max(1, (int)($_GET['p'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = " WHERE 1=1 ";
        $params = [];
        if ($q !== '') {
            $where .= " AND (c.nom LIKE ? OR c.prenom LIKE ? OR c.email LIKE ? OR c.gsm LIKE ? OR c.adresse LIKE ?) ";
            $like = '%' . $q . '%';
            $params = [$like, $like, $like, $like, $like];
        }

        $countSql = "SELECT COUNT(*) FROM clients c $where";
        $countSt = $pdo->prepare($countSql);
        $countSt->execute($params);
        $total = (int)$countSt->fetchColumn();

        $sql = "SELECT c.* FROM clients c $where ORDER BY c.updated_at DESC, c.id DESC LIMIT $limit OFFSET $offset";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $ids = array_column($rows, 'id');
        $counts = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $pc = $pdo->prepare("SELECT client_id, COUNT(*) n FROM patients WHERE client_id IN ($in) GROUP BY client_id");
            $pc->execute($ids);
            foreach ($pc->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $counts[(int)$r['client_id']] = (int)$r['n'];
            }
        }

        $items = [];
        foreach ($rows as $row) {
            [$lat, $lng] = row_latlng($row);
            $id = (int)$row['id'];
            $items[] = [
                'id' => $id,
                'nom' => (string)($row['nom'] ?? ''),
                'prenom' => (string)($row['prenom'] ?? ''),
                'gsm' => (string)($row['gsm'] ?? ''),
                'email' => (string)($row['email'] ?? ''),
                'adresse' => (string)($row['adresse'] ?? ''),
                'lat' => $lat,
                'lng' => $lng,
                'patient_count' => (int)($counts[$id] ?? 0),
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ];
        }

        $pages = max(1, (int)ceil($total / $limit));
        api_ok([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'limit' => $limit,
            'q' => $q,
        ]);
    }

    if ($method === 'POST') {
        csrf_check();
        $action = strtolower(trim((string)($_POST['action'] ?? '')));

        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $nom = trim((string)($_POST['nom'] ?? ''));
            $prenom = trim((string)($_POST['prenom'] ?? ''));
            $gsm = normalize_ma_phone(trim((string)($_POST['gsm'] ?? '')));
            $email = trim((string)($_POST['email'] ?? ''));
            $adresse = trim((string)($_POST['adresse'] ?? ''));
            $lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float)$_POST['lat'] : null;
            $lng = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float)$_POST['lng'] : null;

            if ($nom === '') {
                api_fail('Le nom est obligatoire.');
            }

            [$latCol, $lngCol] = detect_client_latlng_columns($pdo);

            if ($id > 0) {
                $sql = "UPDATE clients SET nom=:nom, prenom=:prenom, gsm=:gsm, email=:email, adresse=:adresse";
                $params = [
                    ':id' => $id, ':nom' => $nom, ':prenom' => $prenom, ':gsm' => $gsm,
                    ':email' => $email, ':adresse' => $adresse,
                ];
                if ($latCol && $lngCol) {
                    $sql .= ", `$latCol`=:lat, `$lngCol`=:lng";
                    $params[':lat'] = $lat;
                    $params[':lng'] = $lng;
                }
                $sql .= ", updated_at=NOW() WHERE id=:id";
                $st = $pdo->prepare($sql);
                foreach ($params as $k => $v) $st->bindValue($k, $v);
                $st->execute();
            } else {
                $sql = "INSERT INTO clients (nom, prenom, gsm, email, adresse, created_at, updated_at) VALUES (:nom,:prenom,:gsm,:email,:adresse,NOW(),NOW())";
                $st = $pdo->prepare($sql);
                $st->execute([
                    ':nom' => $nom, ':prenom' => $prenom, ':gsm' => $gsm,
                    ':email' => $email, ':adresse' => $adresse,
                ]);
                $id = (int)$pdo->lastInsertId();

                if ($latCol && $lngCol) {
                    $u = $pdo->prepare("UPDATE clients SET `$latCol`=:lat, `$lngCol`=:lng, updated_at=NOW() WHERE id=:id");
                    $u->execute([':lat' => $lat, ':lng' => $lng, ':id' => $id]);
                }
            }

            $item = fetch_client_by_id($pdo, $id);
            api_ok(['item' => $item]);
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) api_fail('ID client invalide.');
            $st = $pdo->prepare("DELETE FROM clients WHERE id=?");
            $st->execute([$id]);
            api_ok(['deleted_id' => $id]);
        }

        api_fail('Action POST invalide.');
    }

    api_fail('Méthode non supportée.', 405);
} catch (Throwable $e) {
    $payload = [];
    if (defined('DEBUG') && DEBUG) {
        $payload['debug'] = $e->getMessage();
    }
    api_fail('Erreur serveur.', 500, $payload);
}

<?php
// =============================================================================
// api_upload.php - Subida automática de manos desde IA-CORRECTOR-MANOS
//
// POST multipart/form-data con cabecera X-Api-Token.
// Campos: hand_id, modalidad, variedad, subtipo, nivel, rival, posicion,
//         narracionMano, duda, categorias (JSON de ids), jugador_alias,
//         analisis_ia (opcional) y el archivo foto_mano.
//
// Respuestas JSON:
//   201 {status: success, id, hand_id}      mano guardada
//   409 {status: duplicate, id, hand_id}    esa mano ya estaba
//   401 / 400 / 422 / 500 {status: error, message}
//
// Necesita en la misma carpeta: db_connect.php (getDB) y config_api.php (API_TOKEN).
// =============================================================================
header('Content-Type: application/json; charset=utf-8');

function responder(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(405, ['status' => 'error', 'message' => 'Método no permitido']);
}

if (!is_file(__DIR__ . '/config_api.php')) {
    responder(500, ['status' => 'error', 'message' => 'Falta config_api.php en el servidor']);
}
require_once __DIR__ . '/config_api.php';
require_once __DIR__ . '/db_connect.php';

// ── 1. TOKEN ─────────────────────────────────────────────────────────────────
$token = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
if (!defined('API_TOKEN') || API_TOKEN === '' || !hash_equals(API_TOKEN, $token)) {
    responder(401, ['status' => 'error', 'message' => 'Token no válido']);
}

// ── 2. CAMPOS ────────────────────────────────────────────────────────────────
$hand_id       = trim($_POST['hand_id'] ?? '');
$modalidad     = $_POST['modalidad'] ?? 'NoLimitHoldem';
$variedad      = $_POST['variedad'] ?? '';
$subtipo       = $_POST['subtipo'] ?? '';
$nivel         = $_POST['nivel'] ?? null;
$rival         = $_POST['rival'] ?? null;
$posicion      = $_POST['posicion'] ?? 'all';
$narracionMano = $_POST['narracionMano'] ?? null;
$duda          = $_POST['duda'] ?? null;
$jugador_alias = ($_POST['jugador_alias'] ?? '') !== '' ? $_POST['jugador_alias'] : null;
$analisis_ia   = ($_POST['analisis_ia'] ?? '') !== '' ? $_POST['analisis_ia'] : null;
$categorias    = json_decode($_POST['categorias'] ?? '[]', true);

if (!preg_match('/^\d{6,32}$/', $hand_id)) {
    responder(400, ['status' => 'error', 'message' => 'hand_id no válido']);
}
if (!in_array($modalidad, ['NoLimitHoldem', 'PotLimitOmaha'], true)) {
    responder(422, ['status' => 'error', 'message' => "Modalidad no válida: $modalidad"]);
}
if (!is_array($categorias)) {
    responder(400, ['status' => 'error', 'message' => 'categorias debe ser un JSON de ids']);
}
$categorias = array_values(array_unique(array_map('intval', $categorias)));

// ── 3. IMAGEN (validar antes de tocar la BD) ─────────────────────────────────
$f = $_FILES['foto_mano'] ?? null;
if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
    responder(400, ['status' => 'error', 'message' => 'Falta la imagen (foto_mano)']);
}
if ($f['size'] > 15 * 1024 * 1024) {
    responder(400, ['status' => 'error', 'message' => 'Imagen demasiado grande (máx. 15 MB)']);
}
$ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'bmp'], true) || @getimagesize($f['tmp_name']) === false) {
    responder(400, ['status' => 'error', 'message' => 'El archivo no es una imagen válida']);
}

$conn = getDB();
$conn->set_charset('utf8mb4');

// ── 4. DUPLICADO ─────────────────────────────────────────────────────────────
$stmt = $conn->prepare('SELECT id FROM manos_review WHERE hand_id = ?');
$stmt->bind_param('s', $hand_id);
$stmt->execute();
$dup = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($dup) {
    responder(409, ['status' => 'duplicate', 'id' => (int)$dup['id'], 'hand_id' => $hand_id,
                    'message' => 'La mano ya estaba en la biblioteca']);
}

// ── 5. VARIEDAD / SUBTIPO / CATEGORÍAS (deben existir, no se crean) ─────────
$stmt = $conn->prepare('SELECT id FROM variedades WHERE nombre = ?');
$stmt->bind_param('s', $variedad);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$row) {
    responder(422, ['status' => 'error', 'message' => "Variedad desconocida: $variedad"]);
}
$variedad_id = (int)$row['id'];

$stmt = $conn->prepare('SELECT id FROM subtipos WHERE nombre = ? AND variedad_id = ?');
$stmt->bind_param('si', $subtipo, $variedad_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$row) {
    responder(422, ['status' => 'error', 'message' => "Subtipo desconocido: $subtipo"]);
}
$subtipo_id = (int)$row['id'];

foreach ($categorias as $catId) {
    $stmt = $conn->prepare('SELECT 1 FROM categorias WHERE id = ?');
    $stmt->bind_param('i', $catId);
    $stmt->execute();
    $ok = $stmt->get_result()->fetch_row();
    $stmt->close();
    if (!$ok) {
        responder(422, ['status' => 'error', 'message' => "Categoría desconocida: $catId"]);
    }
}

// ── 6. GUARDAR IMAGEN + INSERT (transacción) ─────────────────────────────────
$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
// Mismo formato que upload.php: en ruta_imagen solo va el nombre del archivo
$uniqueName = time() . '_' . substr(bin2hex(random_bytes(4)), 0, 6) . "_hand_{$hand_id}.{$ext}";
$destPath   = $uploadDir . $uniqueName;
if (!move_uploaded_file($f['tmp_name'], $destPath)) {
    responder(500, ['status' => 'error', 'message' => 'Error al guardar la imagen']);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn->begin_transaction();

    $estado = 'pendiente';
    $stmt = $conn->prepare(
        'INSERT INTO manos_review
            (hand_id, modalidad, variedad_id, subtipo_id, nivel_stake, tipo_rival, narracionMano,
             duda, posicion, ruta_imagen, jugador_alias, analisis_ia, estado_revision)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ssiisssssssss', $hand_id, $modalidad, $variedad_id, $subtipo_id, $nivel,
                      $rival, $narracionMano, $duda, $posicion, $uniqueName, $jugador_alias,
                      $analisis_ia, $estado);
    $stmt->execute();
    $mano_id = $stmt->insert_id;
    $stmt->close();

    $stmt = $conn->prepare('INSERT INTO mano_categorias (mano_id, categoria_id) VALUES (?, ?)');
    foreach ($categorias as $catId) {
        $stmt->bind_param('ii', $mano_id, $catId);
        $stmt->execute();
    }
    $stmt->close();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    @unlink($destPath);
    // Carrera: otra petición metió la misma mano entre el SELECT y el INSERT
    if ($e instanceof mysqli_sql_exception && $e->getCode() === 1062) {
        responder(409, ['status' => 'duplicate', 'hand_id' => $hand_id,
                        'message' => 'La mano ya estaba en la biblioteca']);
    }
    error_log('api_upload.php: ' . $e->getMessage());
    responder(500, ['status' => 'error', 'message' => 'Error al guardar la mano']);
}

$conn->close();
responder(201, ['status' => 'success', 'id' => $mano_id, 'hand_id' => $hand_id,
                'message' => 'Mano guardada con éxito']);

<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $whatsapp = trim($_POST['whatsapp'] ?? '');
    $red_social = trim($_POST['red_social'] ?? '');
    $nombre_red_social = trim($_POST['nombre_red_social'] ?? '');
    $tipo_entrega = 'Retiro en Agencia Shalom';
    $departamento = trim($_POST['departamento'] ?? '');
    $provincia = trim($_POST['provincia'] ?? '');
    $distrito = trim($_POST['distrito'] ?? '');
    $empresa = 'Shalom';
    $agencia_detalle = trim($_POST['agencia_detalle'] ?? '');
    $documento = trim($_POST['documento'] ?? '');
    $nombre_completo = trim($_POST['nombre_completo'] ?? '');
    $fecha_envio = trim($_POST['fecha_envio'] ?? '');

    if (!preg_match('/^[0-9]{9}$/D', $whatsapp) || !preg_match('/^[0-9]{8}$/D', $documento)) {
        echo json_encode(['success' => false, 'message' => 'El WhatsApp debe tener 9 dígitos y el DNI 8 dígitos.']);
        exit;
    }

    if (!$whatsapp || !$agencia_detalle || !$documento || !$nombre_completo || !$fecha_envio) {
        echo json_encode(['success' => false, 'message' => 'Por favor completa todos los campos requeridos y selecciona una agencia Shalom del mapa.']);
        exit;
    }

    try {
        $sql = "INSERT INTO envios (whatsapp, red_social, nombre_red_social, tipo_entrega, departamento, provincia, distrito, empresa, agencia_detalle, documento, nombre_completo, fecha_envio, estado) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pendiente')";
        $stmt = $pdo->prepare($sql);
        $res = $stmt->execute([$whatsapp, $red_social, $nombre_red_social, $tipo_entrega, $departamento, $provincia, $distrito, $empresa, $agencia_detalle, $documento, $nombre_completo, $fecha_envio]);
        $nuevo_id = $pdo->lastInsertId();

        if ($res) {
            echo json_encode([
                'success' => true,
                'id' => $nuevo_id,
                'message' => '¡Envío guardado exitosamente en tu base de datos!'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error al guardar en la base de datos.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}
?>

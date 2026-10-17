<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

$dni = trim($_GET['dni'] ?? '');

if (empty($dni)) {
    echo json_encode(['success' => false, 'message' => 'DNI no proporcionado']);
    exit;
}

try {
    // Buscar en la tabla 'envios' el registro más reciente con este documento
    $stmt = $pdo->prepare("SELECT nombre_completo, documento, whatsapp, agencia_detalle, departamento, provincia, distrito 
                           FROM envios 
                           WHERE documento = ? 
                           ORDER BY id DESC 
                           LIMIT 1");
    $stmt->execute([$dni]);
    $persona = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($persona) {
        echo json_encode([
            'success' => true,
            'encontrado' => true,
            'datos' => [
                'nombre_completo' => $persona['nombre_completo'],
                'documento'       => $persona['documento'],
                'whatsapp'        => $persona['whatsapp'],
                'agencia'         => $persona['agencia_detalle']
            ]
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => true,
            'encontrado' => false,
            'message' => 'No se encontraron registros previos para este DNI en Registro de Pedidos.'
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>

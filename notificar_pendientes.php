<?php
// Ejecutar por el Programador de tareas de Windows a las 09:00 y 13:00:
// C:\xampp\php\php.exe C:\xampp\htdocs\JURUSTORE\notificar_pendientes.php
// También permite el botón manual desde una sesión de administrador.
$cli = PHP_SAPI === 'cli';
if (!$cli) {
    session_start();
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['usuario_id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'mensaje' => 'Inicia sesión para enviar alertas.']);
        exit;
    }
    if (($_GET['manual'] ?? '') !== '1') {
        http_response_code(403);
        echo json_encode(['success' => false, 'mensaje' => 'Solo se permite el envío manual desde el panel.']);
        exit;
    }
}
require __DIR__ . '/conexion.php';
require __DIR__ . '/config_correo.php';
require __DIR__ . '/whatsapp_api.php';
date_default_timezone_set('America/Lima');

$hora = (int) date('G');
$slot = $hora >= 13 ? 2 : ($hora >= 9 ? 1 : 0);
if (!$cli) $slot = 0; // manual: solo pedidos que aún no recibieron dos avisos.
if ($cli && $slot === 0) {
    $respuesta = ['success' => false, 'mensaje' => 'Fuera del horario programado.'];
} else {
    $fecha = date('Y-m-d', strtotime('+1 day'));
    $consulta = $pdo->prepare("SELECT * FROM envios WHERE estado = 'Pendiente' AND fecha_envio = ? AND alertas_enviadas < 2 ORDER BY id");
    $consulta->execute([$fecha]);
    $pedidos = array_filter($consulta->fetchAll(), function ($p) use ($slot, $cli) {
        return !$cli || (int)$p['alertas_enviadas'] < $slot;
    });
    if (!$pedidos) {
        $respuesta = ['success' => false, 'mensaje' => 'No hay pedidos pendientes que requieran aviso para mañana.'];
    } else {
        $cfg = configuracionPrivada();
        $destino = trim($cfg['alertas_correo'] ?? $cfg['gmail_remitente'] ?? '');
        $cuerpo = "Pedidos pendientes para mañana ({$fecha}):\n\n";
        foreach ($pedidos as $p) {
            $cuerpo .= "Pedido #{$p['id']}: {$p['nombre_completo']} | DNI: {$p['documento']} | {$p['agencia_detalle']}\n";
        }
        $ok = filter_var($destino, FILTER_VALIDATE_EMAIL)
            && enviarCorreoGmail($destino, 'Administrador', 'Pedidos pendientes para mañana', $cuerpo);
        $whatsappConfigurado = !empty($cfg['whatsapp_access_token']) && !empty($cfg['whatsapp_phone_number_id']) && !empty($cfg['whatsapp_plantilla']);
        $whatsappOk = $ok && $whatsappConfigurado ? enviarAlertaWhatsapp(count($pedidos), $fecha) : false;
        if ($ok) {
            $actualizar = $pdo->prepare("UPDATE envios SET alertas_enviadas = alertas_enviadas + 1, ultima_alerta = NOW() WHERE id = ? AND estado = 'Pendiente' AND alertas_enviadas < 2");
            foreach ($pedidos as $p) $actualizar->execute([$p['id']]);
        }
        $respuesta = [
            'success' => (bool)$ok,
            'cantidad' => count($pedidos),
            'mensaje' => $ok ? ("Correo enviado a {$destino}." . ($whatsappConfigurado ? ($whatsappOk ? ' WhatsApp enviado.' : ' WhatsApp no se pudo enviar.') : ' WhatsApp automático pendiente de Cloud API.')) : 'No se pudo enviar el correo; los avisos siguen pendientes.'
        ];
    }
}
if ($cli) echo $respuesta['mensaje'] . PHP_EOL;
else echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);

<?php
require_once __DIR__ . '/config_correo.php';

// Envía una plantilla aprobada por Meta. Requiere configurar Cloud API.
function enviarAlertaWhatsapp(int $cantidad, string $fecha): bool {
    $cfg = configuracionPrivada();
    $token = trim($cfg['whatsapp_access_token'] ?? '');
    $phoneId = trim($cfg['whatsapp_phone_number_id'] ?? '');
    $plantilla = trim($cfg['whatsapp_plantilla'] ?? '');
    $destino = preg_replace('/\D/', '', $cfg['whatsapp_destino'] ?? '');
    if (!$token || !ctype_digit($phoneId) || !$plantilla || !preg_match('/^[1-9][0-9]{7,14}$/D', $destino) || !function_exists('curl_init')) return false;
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $destino,
        'type' => 'template',
        'template' => [
            'name' => $plantilla,
            'language' => ['code' => $cfg['whatsapp_idioma'] ?? 'es'],
            'components' => [[
                'type' => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => (string)$cantidad],
                    ['type' => 'text', 'text' => $fecha]
                ]
            ]]
        ]
    ];
    $ch = curl_init('https://graph.facebook.com/v23.0/' . $phoneId . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20
    ]);
    $respuesta = curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($codigo < 200 || $codigo >= 300) {
        error_log('WhatsApp Cloud API rechazó la alerta; HTTP ' . $codigo);
        return false;
    }
    return $respuesta !== false;
}

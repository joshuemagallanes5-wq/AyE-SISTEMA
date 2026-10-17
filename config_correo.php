<?php
function configuracionPrivada(): array {
    $ruta = __DIR__ . '/configuracion_privada.php';
    return is_file($ruta) ? require $ruta : [];
}
function enviarCorreoGmail(string $destinatario, string $nombre, string $asunto, string $cuerpo): bool {
    $cfg = configuracionPrivada();
    $remitente = trim($cfg['gmail_remitente'] ?? '');
    $clave = preg_replace('/\s+/', '', $cfg['gmail_clave_app'] ?? '');
    if (!filter_var($remitente, FILTER_VALIDATE_EMAIL) || !filter_var($destinatario, FILTER_VALIDATE_EMAIL) || strlen($clave) !== 16) return false;
    $socket = @stream_socket_client('ssl://smtp.gmail.com:465', $errno, $error, 15);
    if (!$socket) return false;
    stream_set_timeout($socket, 15);
    $leer = function () use ($socket): int {
        do { $linea = fgets($socket, 1024); if ($linea === false) return 0; }
        while (isset($linea[3]) && $linea[3] === '-');
        return (int) substr($linea, 0, 3);
    };
    $enviar = function (string $comando, int $esperado) use ($socket, $leer): bool {
        return fwrite($socket, $comando . "\r\n") !== false && $leer() === $esperado;
    };
    $ok = $leer() === 220 && $enviar('EHLO localhost', 250) && $enviar('AUTH LOGIN', 334)
        && $enviar(base64_encode($remitente), 334) && $enviar(base64_encode($clave), 235)
        && $enviar('MAIL FROM: <' . $remitente . '>', 250)
        && $enviar('RCPT TO: <' . $destinatario . '>', 250) && $enviar('DATA', 354);
    if ($ok) {
        $cabeceras = "From: Bell's Store Shop <{$remitente}>\r\nTo: <{$destinatario}>\r\n"
            . 'Subject: =?UTF-8?B?' . base64_encode($asunto) . "?=\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n";
        $mensaje = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $cabeceras . $cuerpo));
        $ok = $enviar(str_replace("\n", "\r\n", $mensaje) . "\r\n.", 250);
    }
    @fwrite($socket, "QUIT\r\n");
    fclose($socket);
    return $ok;
}
<?php
session_start();
require 'conexion.php';
require 'config_correo.php';
$mensaje = '';
$tipo_alerta = '';
$link_simulado = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = trim($_POST['correo'] ?? '');

    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE correo = ?");
    $stmt->execute([$correo]);
    $usuario = $stmt->fetch();

    if ($usuario) {
        // Enlace válido por 15 minutos.
        $token = bin2hex(random_bytes(32));
        $update = $pdo->prepare("UPDATE usuarios SET reset_token = ?, token_expira = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?");
        $update->execute([hash('sha256', $token), $usuario['id']]);

        // Construir enlace de restablecimiento
        $cfg = configuracionPrivada();
        $reset_link = rtrim($cfg['url_base'] ?? '', '/') . "/restablecer_clave.php?token=" . $token;

        // Envío por correo con mail() estándar de PHP
        $to = $correo;
        $subject = "Recuperacion de Contrasena - Sistema de Envios";
        $message_body = "Hola " . $usuario['nombre'] . ",\n\n" .
                        "Has solicitado restablecer tu contraseña.\n" .
                        "Haz clic en el siguiente enlace para crear una nueva clave:\n" .
                        $reset_link . "\n\n" .
                        "Este enlace expirará en 15 minutos.\nSi no realizaste esta solicitud, ignora este mensaje.";
        $enviado = !empty($cfg['url_base']) && filter_var($cfg['url_base'], FILTER_VALIDATE_URL) && enviarCorreoGmail($to, $usuario['nombre'], $subject, $message_body);

        $tipo_alerta = 'alert-success';
        $mensaje = $enviado ? 'Si el correo está registrado, recibirás el enlace. Revisa también Spam.' : 'No se pudo enviar el correo. Revisa Gmail y la URL en la configuración privada.';
        if (!$enviado) {
            $tipo_alerta = 'alert-danger';
            $pdo->prepare('UPDATE usuarios SET reset_token = NULL, token_expira = NULL WHERE id = ? AND reset_token = ?')->execute([$usuario['id'], hash('sha256', $token)]);
        }
    } else {
        $tipo_alerta = 'alert-success';
        $mensaje = 'Si el correo está registrado, recibirás el enlace. Revisa también Spam.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Contraseña</title>
    <link rel="stylesheet" href="estilos.css">
    <style>
        body.recuperar-body { background: linear-gradient(rgba(10,15,29,.76),rgba(6,23,15,.82)), url('uploads/fondo_login.jpg') center center / cover no-repeat fixed; }
        .recuperar-body .login-card { background: rgba(255,255,255,.95); max-width: 420px; box-shadow: 0 20px 45px rgba(0,0,0,.4); }
        @media (max-width: 600px) { body.recuperar-body { padding: 16px; min-height: 100dvh; background-attachment: scroll; } .recuperar-body .login-card { padding: 22px 18px; } }
    </style>
</head>
<body class="recuperar-body">

<div class="card login-card">
    <div class="header">
        <h2>Recuperar Contraseña</h2>
        <p>INGRESA TU GMAIL REGISTRADO</p>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert-box <?= $tipo_alerta ?>">
            <?= $mensaje ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Correo Electrónico (Gmail)</label>
            <input type="email" name="correo" class="form-control" placeholder="ejemplo@gmail.com" required>
        </div>

        <button type="submit" class="btn">Enviar Enlace de Recuperación</button>

        <div class="login-footer">
            <a href="login.php" class="forgot-link">← Volver a Iniciar Sesión</a>
        </div>
    </form>
</div>

</body>
</html>

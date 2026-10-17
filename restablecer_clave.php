<?php
session_start();
require 'conexion.php';
$token = trim($_GET['token'] ?? '');
$error = '';

if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
    die("Token inválido o expirado.");
}

$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE reset_token = ? AND token_expira > NOW()");
$stmt->execute([hash('sha256', $token)]);
$usuario = $stmt->fetch();

if (!$usuario) {
    die("El enlace de recuperación es inválido o ha expirado. Por favor, solicítalo de nuevo.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nueva_clave = trim($_POST['password'] ?? '');
    $confirmar_clave = trim($_POST['confirm_password'] ?? '');

    if (strlen($nueva_clave) < 6) {
        $error = "La contraseña debe tener al menos 6 caracteres.";
    } elseif ($nueva_clave !== $confirmar_clave) {
        $error = "Las contraseñas no coinciden.";
    } else {
        $hash = password_hash($nueva_clave, PASSWORD_BCRYPT);
        $update = $pdo->prepare("UPDATE usuarios SET password = ?, reset_token = NULL, token_expira = NULL WHERE id = ? AND reset_token = ? AND token_expira > NOW()");
        $update->execute([$hash, $usuario['id'], hash('sha256', $token)]);
        if ($update->rowCount() === 1) {
            header("Location: login.php?reset=success");
            exit;
        }
        $error = 'El enlace venció o ya fue utilizado.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Nueva Contraseña</title>
    <link rel="stylesheet" href="estilos.css">
</head>
<body>

<div class="card login-card">
    <div class="header">
        <h2>Nueva Contraseña</h2>
        <p>USUARIO: <?= htmlspecialchars($usuario['username']) ?></p>
    </div>

    <?php if ($error): ?>
        <div class="alert-box alert-danger">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Nueva Contraseña</label>
            <input type="password" name="password" class="form-control" placeholder="Mínimo 6 caracteres" required>
        </div>

        <div class="form-group">
            <label>Confirmar Nueva Contraseña</label>
            <input type="password" name="confirm_password" class="form-control" placeholder="Repite la contraseña" required>
        </div>

        <button type="submit" class="btn">Guardar Nueva Contraseña</button>
    </form>
</div>

</body>
</html>

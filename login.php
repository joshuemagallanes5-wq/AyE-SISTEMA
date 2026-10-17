<?php
session_start();
require 'conexion.php';

$error = '';
$success = '';

if (isset($_GET['reset']) && $_GET['reset'] == 'success') {
    $success = 'Contraseña restablecida exitosamente. Inicia sesión con tu nueva clave.';
}

if (isset($_SESSION['usuario_id'])) {
    header('Location: panel.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_input = trim($_POST['username'] ?? '');
    $password    = trim($_POST['password'] ?? '');

    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE (username = ? OR correo = ?) LIMIT 1");
    $stmt->execute([$login_input, $login_input]);
    $user = $stmt->fetch();

    if ($user) {
        $clave_correcta = password_verify($password, $user['password']) || ($password === $user['password']);

        if ($clave_correcta) {
            if (isset($user['estado']) && $user['estado'] === 'Inactivo') {
                $error = 'Tu cuenta está desactivada. Consulta con el administrador.';
            } else {
                $_SESSION['usuario_id']     = $user['id'];
                $_SESSION['usuario_nombre'] = $user['nombre'] . ' ' . $user['apellido'];
                $_SESSION['usuario_correo'] = $user['correo'];
                $_SESSION['usuario_foto']   = $user['foto'] ?? 'default_avatar.png';
                $_SESSION['usuario_rol']    = $user['rol'] ?? 'SuperAdmin';
                
                header('Location: panel.php');
                exit;
            }
        } else {
            $error = 'Contraseña incorrecta.';
        }
    } else {
        $error = 'El usuario o correo no existe.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Bell's Store Shop</title>
    <link rel="stylesheet" href="estilos.css">
    <style>
        body.login-body-bg {
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background: linear-gradient(rgba(10, 15, 29, 0.75), rgba(6, 23, 15, 0.82)), url('uploads/fondo_login.jpg') no-repeat center center fixed;
            background-size: cover;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            box-sizing: border-box;
        }
        .login-glass-card {
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-radius: 20px;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.45);
            max-width: 420px;
            width: 100%;
            padding: 32px 28px;
            box-sizing: border-box;
            border: 1px solid rgba(255, 255, 255, 0.5);
            text-align: center;
            animation: popIn 0.3s ease;
        }

        @keyframes popIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

        .login-logo-img {
            width: 120px;
            height: 120px;
            object-fit: contain;
            border-radius: 50%;
            margin: 0 auto 12px auto;
            display: block;
            filter: drop-shadow(0 6px 14px rgba(0, 0, 0, 0.35));
            border: 3px solid #d4af37;
            background: #000;
        }

        .login-brand-title {
            font-size: 16px;
            font-weight: 900;
            color: #0b2417;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-bottom: 2px;
        }
        .login-brand-sub {
            font-size: 11px;
            color: #b45309;
            font-weight: 700;
            letter-spacing: 0.8px;
            margin-bottom: 18px;
            text-transform: uppercase;
        }

        .form-group {
            text-align: left;
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-size: 12.5px;
            font-weight: 700;
            color: #1e293b;
        }
        .form-control {
            width: 100%;
            padding: 11px 13px;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            font-size: 13.5px;
            outline: none;
            box-sizing: border-box;
            background: #ffffff;
            transition: all 0.2s;
        }
        .form-control:focus {
            border-color: #059669;
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.18);
        }

        .btn-login-submit {
            width: 100%;
            background: #0b2417;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 13px;
            font-size: 14.5px;
            font-weight: 800;
            cursor: pointer;
            transition: background 0.2s;
            margin-top: 10px;
        }
        .btn-login-submit:hover {
            background: #143d27;
        }

        .login-footer {
            margin-top: 18px;
            font-size: 12px;
        }
        .forgot-link {
            color: #059669;
            text-decoration: none;
            font-weight: 600;
        }
        .forgot-link:hover {
            text-decoration: underline;
        }
        @media (max-width: 600px) {
            html, body { width: 100%; max-width: 100%; height: 100%; overflow: hidden; }
            body.login-body-bg {
                height: 100dvh; min-height: 0; padding: 12px; overflow: hidden;
                background-attachment: scroll; background-position: center center;
            }
            .login-glass-card { padding: clamp(12px, 3vh, 24px) 18px; max-height: calc(100dvh - 24px); }
            .login-logo-img { width: clamp(64px, 14vh, 96px); height: clamp(64px, 14vh, 96px); }
            .login-glass-card .form-group { margin-bottom: clamp(8px, 2vh, 18px); }
            .login-footer { margin-top: 10px; }
            .login-brand-sub { margin-bottom: 10px; }
        }
        @media (max-width: 600px) and (max-height: 620px) {
            .login-glass-card { padding: 10px 16px; }
            .login-logo-img { width: 58px; height: 58px; margin-bottom: 4px; }
            .login-brand-sub { margin-bottom: 5px; }
            .login-glass-card .form-group { margin-bottom: 6px; }
            .login-glass-card .form-control { padding: 8px 10px; }
            .btn-login-submit { padding: 9px; margin-top: 4px; }
            .login-footer { margin-top: 6px; }
        }
    </style>
</head>
<body class="login-body-bg">

<div class="login-glass-card">
    <img src="uploads/logo.png" alt="Bell's Store Shop" class="login-logo-img" onerror="this.src='uploads/logo.svg'">
    <div class="login-brand-title">BELL'S STORE SHOP</div>
    <div class="login-brand-sub">Ropa Americana Exclusiva</div>

    <?php if ($error): ?>
        <div class="alert-box alert-danger" style="text-align:left; margin-bottom:15px; padding:10px; border-radius:8px; font-size:12.5px;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert-box alert-success" style="text-align:left; margin-bottom:15px; padding:10px; border-radius:8px; font-size:12.5px;">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <div class="form-group">
            <label>Correo o Usuario</label>
            <input type="text" name="username" class="form-control" placeholder="Ingresa tu correo o usuario" required autofocus>
        </div>

        <div class="form-group">
            <label>Contraseña</label>
            <input type="password" name="password" class="form-control" placeholder="••••••••" required>
        </div>

        <button type="submit" class="btn-login-submit">Ingresar al Sistema</button>

        <div class="login-footer">
            <a href="recuperar_clave.php" class="forgot-link">¿Olvidaste tu contraseña?</a>
        </div>
    </form>
</div>

</body>
</html>

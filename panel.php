<?php
session_start();
require 'conexion.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}

$stmtCurrent = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmtCurrent->execute([$_SESSION['usuario_id']]);
$currentUser = $stmtCurrent->fetch();

$stmtPermisos = $pdo->prepare("SELECT modulo_slug FROM usuario_permisos WHERE usuario_id = ?");
$stmtPermisos->execute([$_SESSION['usuario_id']]);
$misPermisos = $stmtPermisos->fetchAll(PDO::FETCH_COLUMN);

function tieneAcceso($moduloSlug, $usuario, $permisos) {
    if ($usuario['rol'] === 'SuperAdmin') return true;
    return in_array($moduloSlug, $permisos);
}

$listaModulos = $pdo->query("SELECT * FROM modulos ORDER BY id ASC")->fetchAll();

$msg = '';
$tipo_msg = '';
if (isset($_SESSION['pedido_flash'])) {
    $flash = $_SESSION['pedido_flash'];
    $msg = is_array($flash) ? ($flash['texto'] ?? '') : $flash;
    $tipo_msg = is_array($flash) ? ($flash['tipo'] ?? 'alert-success') : 'alert-success';
    unset($_SESSION['pedido_flash']);
}

// --- GESTIÓN DE ACCIONES DE USUARIOS Y CORRECCIÓN DE ACTUALIZACIÓN DE CLAVE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_usuario'])) {
    if (!tieneAcceso('usuarios', $currentUser, $misPermisos)) {
        $msg = "No tienes permisos para gestionar usuarios.";
        $tipo_msg = "alert-danger";
    } else {
        $accion = $_POST['action_usuario'];

        if ($accion === 'crear') {
            $nombre = trim($_POST['nombre'] ?? '');
            $apellido = trim($_POST['apellido'] ?? '');
            $dni = trim($_POST['dni'] ?? '');
            $celular = trim($_POST['celular'] ?? '');
            $correo = trim($_POST['correo'] ?? '');
            $clave = trim($_POST['clave'] ?? '');
            $confirmar_clave = trim($_POST['confirmar_clave'] ?? '');
            $rol = ($_POST['rol'] === 'SuperAdmin') ? 'SuperAdmin' : 'Operador';
            $username = strtolower(explode('@', $correo)[0]);
            $permisosAsignados = $_POST['permisos'] ?? [];

            if (!$nombre || !$apellido || !$dni || !$correo || !$clave || !$confirmar_clave) {
                $msg = "Completa los campos obligatorios.";
                $tipo_msg = "alert-danger";
            } elseif ($clave !== $confirmar_clave) {
                $msg = "Las contraseñas no coinciden.";
                $tipo_msg = "alert-danger";
            } else {
                $foto_nombre = 'default_avatar.png';
                if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
                    $permitidas = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                    if (in_array($ext, $permitidas)) {
                        $foto_nombre = 'user_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                        move_uploaded_file($_FILES['foto']['tmp_name'], 'uploads/administrados/' . $foto_nombre);
                    }
                }

                try {
                    $hashed_pwd = password_hash($clave, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, apellido, dni, celular, correo, username, password, foto, rol, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Activo')");
                    $stmt->execute([$nombre, $apellido, $dni, $celular, $correo, $username, $hashed_pwd, $foto_nombre, $rol]);
                    $nuevoId = $pdo->lastInsertId();

                    $stmtInsPerm = $pdo->prepare("INSERT INTO usuario_permisos (usuario_id, modulo_slug) VALUES (?, ?)");
                    foreach ($permisosAsignados as $slug) {
                        $stmtInsPerm->execute([$nuevoId, $slug]);
                    }

                    $msg = "Usuario registrado exitosamente.";
                    $tipo_msg = "alert-success";
                } catch (PDOException $e) {
                    $msg = "Error: DNI o Correo ya registrado.";
                    $tipo_msg = "alert-danger";
                }
            }
        } elseif ($accion === 'editar') {
            // CORRECCIÓN EXACTA DE ACTUALIZACIÓN DE CLAVE
            $id = intval($_POST['user_id'] ?? 0);
            $nombre = trim($_POST['nombre'] ?? '');
            $apellido = trim($_POST['apellido'] ?? '');
            $dni = trim($_POST['dni'] ?? '');
            $celular = trim($_POST['celular'] ?? '');
            $correo = trim($_POST['correo'] ?? '');
            $rol = ($_POST['rol'] === 'SuperAdmin') ? 'SuperAdmin' : 'Operador';
            $nueva_clave = trim($_POST['clave'] ?? '');
            $permisosAsignados = $_POST['permisos'] ?? [];

            try {
                if (isset($_FILES['foto_edit']) && $_FILES['foto_edit']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['foto_edit']['name'], PATHINFO_EXTENSION));
                    $permitidas = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                    if (in_array($ext, $permitidas)) {
                        $foto_nueva = 'user_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                        if (move_uploaded_file($_FILES['foto_edit']['tmp_name'], 'uploads/administrados/' . $foto_nueva)) {
                            $pdo->prepare("UPDATE usuarios SET foto = ? WHERE id = ?")->execute([$foto_nueva, $id]);
                            if ($id == $_SESSION['usuario_id']) {
                                $_SESSION['usuario_foto'] = $foto_nueva;
                            }
                        }
                    }
                }

                // Si escribió una nueva clave, se encripta y se actualiza de inmediato
                if (!empty($nueva_clave)) {
                    $hashed = password_hash($nueva_clave, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("UPDATE usuarios SET nombre=?, apellido=?, dni=?, celular=?, correo=?, rol=?, password=? WHERE id=?");
                    $stmt->execute([$nombre, $apellido, $dni, $celular, $correo, $rol, $hashed, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE usuarios SET nombre=?, apellido=?, dni=?, celular=?, correo=?, rol=? WHERE id=?");
                    $stmt->execute([$nombre, $apellido, $dni, $celular, $correo, $rol, $id]);
                }

                // Actualizar permisos modulares
                $pdo->prepare("DELETE FROM usuario_permisos WHERE usuario_id = ?")->execute([$id]);
                $stmtInsPerm = $pdo->prepare("INSERT INTO usuario_permisos (usuario_id, modulo_slug) VALUES (?, ?)");
                foreach ($permisosAsignados as $slug) {
                    $stmtInsPerm->execute([$id, $slug]);
                }

                $msg = "¡Datos y contraseña actualizados correctamente!";
                $tipo_msg = "alert-success";
            } catch (PDOException $e) {
                $msg = "Error al actualizar: " . $e->getMessage();
                $tipo_msg = "alert-danger";
            }
        } elseif ($accion === 'cambiar_estado') {
            $id = intval($_POST['user_id'] ?? 0);
            $nuevo_est = ($_POST['nuevo_estado'] === 'Inactivo') ? 'Inactivo' : 'Activo';
            $stmt = $pdo->prepare("UPDATE usuarios SET estado = ? WHERE id = ?");
            $stmt->execute([$nuevo_est, $id]);
            $msg = "Estado de usuario cambiado.";
            $tipo_msg = "alert-success";
        } elseif ($accion === 'eliminar') {
            $id = intval($_POST['user_id'] ?? 0);
            if ($id == $_SESSION['usuario_id']) {
                $msg = "No puedes eliminar tu cuenta en uso.";
                $tipo_msg = "alert-danger";
            } else {
                $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
                $stmt->execute([$id]);
                $msg = "Usuario eliminado.";
                $tipo_msg = "alert-success";
            }
        }
    }
}

// --- GESTIÓN DE ACCIONES: ENVÍO DE SHALOM (NUEVO MÓDULO) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_shalom'])) {
    if (!tieneAcceso('envios_shalom', $currentUser, $misPermisos)) {
        $msg = "No tienes permisos para el módulo Envíos Shalom.";
        $tipo_msg = "alert-danger";
    } else {
        $accionShalom = $_POST['action_shalom'];

        if ($accionShalom === 'crear') {
            $dni = trim($_POST['dni'] ?? '');
            $nom = trim($_POST['nombre_apellido'] ?? '');
            $orden = trim($_POST['numero_orden'] ?? '');
            $boleta = trim($_POST['codigo_boleta'] ?? ($_POST['numero_boleta'] ?? ''));
            $clave = trim($_POST['clave'] ?? '');

            // Subir foto opcional (activar cámara / archivo)
            $foto_boleta = null;
            if (isset($_FILES['foto_boleta']) && $_FILES['foto_boleta']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['foto_boleta']['name'], PATHINFO_EXTENSION));
                $permitidas = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                if (in_array($ext, $permitidas)) {
                    $foto_boleta = 'boleta_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                    move_uploaded_file($_FILES['foto_boleta']['tmp_name'], 'uploads/boletas/' . $foto_boleta);
                }
            }

            if ($nom && $orden && $boleta && $clave) {
                // Compatible con columna codigo_boleta o numero_boleta
                try {
                    $stmt = $pdo->prepare("INSERT INTO envios_shalom (dni, nombre_apellido, numero_orden, codigo_boleta, clave, foto_boleta) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$dni, $nom, $orden, $boleta, $clave, $foto_boleta]);
                } catch (PDOException $e) {
                    $stmt = $pdo->prepare("INSERT INTO envios_shalom (dni, nombre_apellido, numero_orden, numero_boleta, clave, foto_boleta) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$dni, $nom, $orden, $boleta, $clave, $foto_boleta]);
                }
                $msg = "Envío Shalom registrado con éxito.";
                $tipo_msg = "alert-success";
            } else {
                $msg = "Completa todos los campos obligatorios del envío Shalom.";
                $tipo_msg = "alert-danger";
            }
        } elseif ($accionShalom === 'editar') {
            $id = intval($_POST['shalom_id'] ?? 0);
            $dni = trim($_POST['dni'] ?? '');
            $nom = trim($_POST['nombre_apellido'] ?? '');
            $orden = trim($_POST['numero_orden'] ?? '');
            $boleta = trim($_POST['codigo_boleta'] ?? ($_POST['numero_boleta'] ?? ''));
            $clave = trim($_POST['clave'] ?? '');

            // Subida de nueva foto si se proporciona
            if (isset($_FILES['foto_boleta_edit']) && $_FILES['foto_boleta_edit']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['foto_boleta_edit']['name'], PATHINFO_EXTENSION));
                $permitidas = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                if (in_array($ext, $permitidas)) {
                    $nueva_foto = 'boleta_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                    if (move_uploaded_file($_FILES['foto_boleta_edit']['tmp_name'], 'uploads/boletas/' . $nueva_foto)) {
                        $pdo->prepare("UPDATE envios_shalom SET foto_boleta = ? WHERE id = ?")->execute([$nueva_foto, $id]);
                    }
                }
            }

            try {
                $stmt = $pdo->prepare("UPDATE envios_shalom SET dni=?, nombre_apellido=?, numero_orden=?, codigo_boleta=?, clave=? WHERE id=?");
                $stmt->execute([$dni, $nom, $orden, $boleta, $clave, $id]);
            } catch (PDOException $e) {
                $stmt = $pdo->prepare("UPDATE envios_shalom SET dni=?, nombre_apellido=?, numero_orden=?, numero_boleta=?, clave=? WHERE id=?");
                $stmt->execute([$dni, $nom, $orden, $boleta, $clave, $id]);
            }
            $msg = "Envío Shalom modificado correctamente.";
            $tipo_msg = "alert-success";
        } elseif ($accionShalom === 'eliminar') {
            $id = intval($_POST['shalom_id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM envios_shalom WHERE id = ?");
            $stmt->execute([$id]);
            $msg = "Envío Shalom eliminado.";
            $tipo_msg = "alert-success";
        }
    }
}

// --- GESTIÓN DE ACCIONES DE ENVÍOS GENERALES ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_envio'])) {
    if (!tieneAcceso('pedidos', $currentUser, $misPermisos)) {
        $msg = "No tienes permisos para modificar pedidos.";
        $tipo_msg = "alert-danger";
    } else {
        $accion = $_POST['action_envio'];

        if ($accion === 'editar_completo') {
            $id = intval($_POST['envio_id'] ?? 0);
            $checkStmt = $pdo->prepare("SELECT estado FROM envios WHERE id = ?");
            $checkStmt->execute([$id]);
            $estadoActual = $checkStmt->fetchColumn();

            $whatsapp = trim($_POST['whatsapp'] ?? '');
            $red_social = trim($_POST['red_social'] ?? '');
            $nombre_red_social = trim($_POST['nombre_red_social'] ?? '');
            $tipo_entrega = trim($_POST['tipo_entrega'] ?? '');
            $empresa = trim($_POST['empresa'] ?? '');
            $agencia_detalle = trim($_POST['agencia_detalle'] ?? '');
            $documento = trim($_POST['documento'] ?? '');
            $nombre_completo = trim($_POST['nombre_completo'] ?? '');
            $fecha_envio = trim($_POST['fecha_envio'] ?? '');
            $observacion = trim($_POST['observacion'] ?? '');

            if ($estadoActual === 'Despachado') {
                $estado = 'Despachado';
            } else {
                $estado = ($_POST['estado'] === 'Despachado') ? 'Despachado' : 'Pendiente';
            }

            $stmt = $pdo->prepare("UPDATE envios SET whatsapp=?, red_social=?, nombre_red_social=?, tipo_entrega=?, empresa=?, agencia_detalle=?, documento=?, nombre_completo=?, fecha_envio=?, estado=?, observacion=? WHERE id=?");
            $stmt->execute([$whatsapp, $red_social, $nombre_red_social, $tipo_entrega, $empresa, $agencia_detalle, $documento, $nombre_completo, $fecha_envio, $estado, $observacion, $id]);
            $msg = "Pedido #$id actualizado.";
            $tipo_msg = "alert-success";
        } elseif ($accion === 'eliminar') {
            $id = intval($_POST['envio_id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM envios WHERE id = ?");
            $stmt->execute([$id]);
            $msg = "Pedido eliminado.";
            $tipo_msg = "alert-success";
        }
    }
}

// Tras cualquier acción, volver al mismo módulo mediante GET. Evita repetir el POST al actualizar.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $moduloRetorno = isset($_POST['action_usuario']) ? 'tab-usuarios'
        : (isset($_POST['action_shalom']) ? 'tab-shalom' : 'tab-envios');
    $_SESSION['pedido_flash'] = ['texto' => $msg, 'tipo' => $tipo_msg];
    $paginaRetorno = max(1, intval($_POST['pagina_retorno'] ?? $_GET['p'] ?? 1));
    header('Location: panel.php?p=' . $paginaRetorno . '#' . $moduloRetorno, true, 303);
    exit;
}

// Consultas
$total_pedidos = $pdo->query("SELECT COUNT(*) FROM envios")->fetchColumn();
$pedidos_pendientes = $pdo->query("SELECT COUNT(*) FROM envios WHERE estado = 'Pendiente'")->fetchColumn();
$pedidos_despachados = $pdo->query("SELECT COUNT(*) FROM envios WHERE estado = 'Despachado'")->fetchColumn();

// Envíos de Shalom
$listaShalom = $pdo->query("SELECT * FROM envios_shalom ORDER BY id DESC")->fetchAll();

// Alertas de mañana
$fecha_manana = date('Y-m-d', strtotime('+1 day'));
$stmtAlertas = $pdo->prepare("SELECT * FROM envios WHERE estado = 'Pendiente' AND fecha_envio = ?");
$stmtAlertas->execute([$fecha_manana]);
$pedidosParaManana = $stmtAlertas->fetchAll();
$totalAlertasManana = count($pedidosParaManana);

$adminCelular = preg_replace('/[^0-9]/', '', $currentUser['celular'] ?? '51912955592');
$mensajeWspAlerta = "*⚠️ ALERTA DE PEDIDOS PENDIENTES*%0A" .
                    "Hola {$currentUser['nombre']}, tienes *{$totalAlertasManana} pedido(s) pendiente(s)* para despachar *MAÑANA ({$fecha_manana})*:%0A%0A";
foreach ($pedidosParaManana as $pman) {
    $mensajeWspAlerta .= "📦 *#{$pman['id']}:* {$pman['nombre_completo']} | DNI: {$pman['documento']} | {$pman['empresa']}%0A";
}
$linkWspAlertaAdmin = "https://api.whatsapp.com/send?phone={$adminCelular}&text={$mensajeWspAlerta}";

$pagina = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$limite = 10;
$offset = ($pagina - 1) * $limite;
$total_paginas = max(1, ceil($total_pedidos / $limite));

$sql_envios = "SELECT * FROM envios 
               ORDER BY CASE WHEN estado = 'Pendiente' THEN 0 ELSE 1 END ASC, id ASC
               LIMIT $limite OFFSET $offset";
$envios = $pdo->query($sql_envios)->fetchAll();

$usuarios = $pdo->query("SELECT u.*, GROUP_CONCAT(up.modulo_slug) as permisos_slugs 
                         FROM usuarios u 
                         LEFT JOIN usuario_permisos up ON u.id = up.usuario_id 
                         GROUP BY u.id 
                         ORDER BY u.id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Administrativo - BELL'S STORE SHOP</title>
    <link rel="stylesheet" href="estilos.css">
    <style>
        :root {
            --sidebar-bg: #0b2417;
            --sidebar-dark: #06170f;
            --sidebar-hover: #143d27;
            --sidebar-active: #194d32;
            --sidebar-text: #96c4ab;
            --accent-neon: #38e388;
            --main-bg: #eaf3ed;
            --card-bg: #ffffff;
            --text-dark: #12281e;
            --color-verde: #059669;
            --color-verde-bg: #d1fae5;
            --color-verde-dark: #065f46;
            --color-rojo: #dc2626;
            --color-rojo-bg: #fee2e2;
            --color-rojo-dark: #991b1b;
        }

        html, body {
            margin: 0 !important;
            padding: 0 !important;
            width: 100vw;
            height: 100vh;
            overflow: hidden;
            display: flex;
            background-color: var(--main-bg);
            color: var(--text-dark);
        }

        .sidebar {
            width: 265px;
            height: 100vh;
            background-color: var(--sidebar-bg);
            color: #fff;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            margin: 0 !important;
            padding: 0 !important;
            box-shadow: 4px 0 18px rgba(0,0,0,0.18);
            z-index: 100;
        }

        .sidebar-logo {
            padding: 22px 15px;
            background: var(--sidebar-dark);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            gap: 6px;
            text-align: center;
        }
        .logo-emblem-bells {
            width: 110px;
            height: 110px;
            object-fit: contain;
            border-radius: 50%;
            display: block;
            margin: 0 auto;
            filter: drop-shadow(0 0 10px rgba(255, 255, 255, 0.9)) drop-shadow(0 0 20px rgba(234, 179, 8, 0.45));
            transition: transform 0.3s ease;
        }

        .sidebar-menu {
            list-style: none;
            padding: 20px 12px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex-grow: 1;
            margin: 0;
            overflow-y: auto;
        }
        .sidebar-menu li a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: var(--sidebar-text);
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 600;
            border-radius: 10px;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .sidebar-menu li a:hover {
            color: #fff;
            background-color: var(--sidebar-hover);
        }
        .sidebar-menu li a.active {
            color: #fff;
            background-color: var(--sidebar-active);
            box-shadow: 0 4px 12px rgba(0,0,0,0.25);
            border-left: 4px solid var(--accent-neon);
        }
        .menu-svg {
            width: 18px;
            height: 18px;
            fill: currentColor;
            flex-shrink: 0;
        }

        .main-wrapper {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
            margin: 0;
            padding: 0;
        }

        .topbar {
            padding: 16px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: transparent;
            flex-shrink: 0;
        }
        .topbar-title {
            font-size: 22px;
            font-weight: 700;
            color: #0b2417;
        }
        .topbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn-alerta-campana {
            background: #ffffff;
            border: 1.5px solid #fecaca;
            color: var(--color-rojo);
            padding: 8px 14px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(220, 38, 38, 0.12);
        }
        .badge-alert-count {
            background: var(--color-rojo);
            color: #fff;
            font-size: 11px;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 20px;
        }

        .user-profile-badge {
            background: #ffffff;
            padding: 6px 14px 6px 8px;
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 3px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .user-profile-badge img {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--accent-neon);
        }
        .profile-email { max-width: 210px; text-align: center; overflow-wrap: anywhere; font-size: 11px; font-weight: 700; color: #0b2417; line-height: 1.15; }
        .btn-logout-pill {
            background: #fff;
            color: var(--color-rojo);
            border: 1px solid #fecaca;
            padding: 8px 16px;
            border-radius: 30px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .content-area {
            padding: 5px 30px 30px 30px;
            flex-grow: 1;
            overflow-y: auto;
        }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; animation: fadeIn 0.25s ease; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .white-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.03);
            margin-bottom: 25px;
        }
        .card-header-flex {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 1px solid #edf2f7;
            flex-wrap: wrap;
            gap: 12px;
        }

        .search-box-container {
            display: flex;
            align-items: center;
            position: relative;
            width: 320px;
            max-width: 100%;
        }
        .search-icon-svg {
            position: absolute;
            left: 12px;
            width: 16px;
            height: 16px;
            fill: #94a3b8;
            pointer-events: none;
        }
        .input-live-search {
            width: 100%;
            padding: 9px 12px 9px 36px;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            font-size: 13px;
            outline: none;
            background: #f8fafc;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
            white-space: nowrap;
        }
        th, td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid #edf2f7;
            vertical-align: middle;
        }
        th {
            background: #f8fafc;
            color: #475569;
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
        }

        .badge-estado {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 700;
            text-align: center;
        }
        .badge-estado.Despachado { background-color: var(--color-verde-bg); color: var(--color-verde-dark); border: 1px solid var(--color-verde); }
        .badge-estado.Pendiente { background-color: var(--color-rojo-bg); color: var(--color-rojo-dark); border: 1px solid var(--color-rojo); }

        .btn-icon-only {
            width: 30px;
            height: 30px;
            border-radius: 6px;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-icon-only svg { width: 15px; height: 15px; fill: currentColor; }
        .btn-icon-edit { background: #e2e8f0; color: #334155; }
        .btn-icon-delete { background: var(--color-rojo-bg); color: var(--color-rojo); }
        .btn-icon-toggle { background: #dbeafe; color: #1d4ed8; }

        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 15px;
            margin-top: 10px;
            border-top: 1px solid #edf2f7;
            font-size: 13px;
            color: #64748b;
        }
        .btn-page {
            padding: 6px 12px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #334155;
            text-decoration: none;
            font-weight: 600;
            font-size: 12px;
        }
        .btn-page.active { background: var(--color-verde); color: #fff; border-color: var(--color-verde); }

        .modal-overlay {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.55);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 2000;
            padding: 20px;
        }
        .modal-box-large {
            background: #ffffff;
            border-radius: 16px;
            width: 100%;
            max-width: 960px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 28px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.25);
            animation: popIn 0.25s ease;
        }
        @keyframes popIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
        .grid-3-col {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }
        @media (max-width: 850px) { .grid-3-col { grid-template-columns: 1fr; } }
        .col-span-3 { grid-column: span 3; }
        @media (max-width: 850px) { .col-span-3 { grid-column: span 1; } }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #edf2f7;
        }
        .btn-cancel { background: #e2e8f0; color: #475569; padding: 10px 20px; border-radius: 8px; border: none; font-weight: 600; cursor: pointer; }
        .btn-confirm { padding: 10px 22px; border-radius: 8px; border: none; font-weight: 600; color: #fff; cursor: pointer; }
        .btn-confirm.danger { background: var(--color-rojo); }
        .btn-confirm.primary { background: var(--color-verde); }

        .btn-primary-green {
            background: var(--sidebar-bg);
            color: #fff;
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            font-size: 13.5px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary-green:hover { background: var(--sidebar-hover); }

        .permisos-card {
            background: #f8fafc;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            padding: 14px 16px;
            margin-top: 6px;
        }
        .permisos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
            margin-top: 8px;
        }
        .permiso-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
            cursor: pointer;
        }

        .sticky-search-bar {
            position: sticky;
            top: 0;
            z-index: 50;
            background: #ffffff;
            padding: 14px 18px;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.08);
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid #e2e8f0;
        }

        .table-responsive-floating {
            width: 100%;
            overflow-x: auto;
            position: relative;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
        }
        .table-responsive-floating thead th { position: sticky; top: 0; z-index: 20; background: #f1f5f9; }

        .floating-scroll-track {
            position: fixed;
            bottom: 8px; left: 0; width: 100%; height: 22px;
            overflow-x: auto; overflow-y: hidden;
            background: rgba(248, 250, 252, 0.98);
            border-top: 1.5px solid #cbd5e1;
            z-index: 1000;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,.2);
            display: none;
        }
        .floating-scroll-dummy { height: 1px; }

        .locked-notice {
            background: #f1f5f9;
            color: #475569;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 5px;
            border-left: 4px solid #94a3b8;
        }
    
        /* BOTÓN DE MENÚ MÓVIL HAMBURGUESA */
        .btn-menu-mobile {
            display: none;
            background: #0b2417;
            color: #38e388;
            border: 1.5px solid #143d27;
            border-radius: 10px;
            width: 44px;
            height: 44px;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            margin-right: 12px;
            flex-shrink: 0;
            box-shadow: 0 3px 10px rgba(0,0,0,0.15);
            transition: all 0.2s ease;
        }
        .btn-menu-mobile:active { transform: scale(0.94); background: #143d27; }
        .btn-menu-mobile svg { width: 24px; height: 24px; fill: currentColor; }

        /* OVERLAY OSCURO DE FONDO PARA DISPOSITIVOS MÓVILES */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(0, 0, 0, 0.68);
            backdrop-filter: blur(3px);
            z-index: 1500;
            transition: opacity 0.3s ease;
        }
        .sidebar-overlay.active { display: block; }

        .btn-close-sidebar-mobile {
            display: none;
            position: absolute;
            top: 14px;
            right: 14px;
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
            border: none;
            border-radius: 50%;
            width: 34px;
            height: 34px;
            font-size: 18px;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            line-height: 1;
        }

        /* MEDIA QUERIES PARA CELULARES Y TABLETS (< 992px) */
        @media (max-width: 991px) {
            .btn-menu-mobile { display: inline-flex; }
            .btn-close-sidebar-mobile { display: inline-flex; }

            .sidebar {
                position: fixed;
                top: 0;
                left: -300px;
                width: 285px;
                height: 100vh;
                z-index: 1600;
                transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                box-shadow: 8px 0 25px rgba(0,0,0,0.35);
            }
            .sidebar.open {
                transform: translateX(300px);
            }

            .topbar {
                padding: 12px 16px;
                background: #ffffff;
                box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            }
            .topbar-title { font-size: 17px; }
            .user-profile-badge .profile-email { display: block; }
            .btn-menu-mobile { margin-right: 2px; }
            .btn-alerta-campana .alert-long { display: none; }
            .btn-alerta-campana .alert-short { display: inline !important; }
            .btn-alerta-campana { padding: 7px 9px; gap: 4px; font-size: 11px; }
            .topbar { padding-left: 20px; padding-right: 8px; }
            .content-area { padding: 12px; }

            /* Panel Inicio métricas 1 columna */
            #tab-inicio > div:nth-child(2) {
                grid-template-columns: 1fr !important;
                gap: 12px !important;
            }

            .search-box-container { width: 100% !important; }
            .card-header-flex { flex-direction: column; align-items: stretch !important; }
            .sticky-search-bar { flex-direction: column; gap: 10px; align-items: stretch !important; }
        }

    
        /* BOTÓN CERRAR SESIÓN SEGÚN DISPOSITIVO */
        .desktop-only-logout {
            display: inline-flex !important;
        }
        .mobile-only-logout-item {
            display: none !important;
            margin-top: auto;
            padding-top: 15px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        .btn-logout-sidebar {
            color: #ef4444 !important;
            background: rgba(239, 68, 68, 0.1) !important;
            border: 1px solid rgba(239, 68, 68, 0.25) !important;
        }
        .btn-logout-sidebar:hover {
            background: rgba(239, 68, 68, 0.2) !important;
        }

        @media (max-width: 991px) {
            .desktop-only-logout {
                display: none !important;
            }
            .mobile-only-logout-item {
                display: block !important;
            }
            .sidebar-menu { padding-bottom: 35px; }
            .mobile-only-logout-item { display: none !important; }
            .btn-logout-sidebar, .btn-logout-sidebar span {
                color: #ef4444 !important; font-weight: 900 !important;
                text-shadow: 0 1px 2px #fff, 0 0 5px #fff !important;
            }
        }
        .alert-short { display: none; }
        .sidebar-logout { align-self: flex-start; margin: 3px 0 0 8px; padding: 7px 10px; background: #fff; color: #111 !important; border-radius: 8px; font-size: 12px; font-weight: 800; display: none; align-items: center; gap: 6px; text-decoration: none; }
        .sidebar-logout svg { width: 17px; height: 17px; fill: #111; }
        @media (max-width: 991px) {
            .sidebar-logout { display: inline-flex; }
            html, body { width: 100%; max-width: 100%; overflow-x: hidden; }
            .main-wrapper { width: 100%; min-width: 0; height: 100dvh; }
            .content-area { min-width: 0; overflow-x: hidden; padding: 10px 8px 36px; }
            .topbar { min-width: 0; gap: 5px; }
            .topbar-right { gap: 5px; min-width: 0; }
            .user-profile-badge { padding: 3px; max-width: 105px; }
            .user-profile-badge img { width: 30px; height: 30px; }
            .user-profile-badge .profile-email { font-size: 9px; max-width: 100px; }
            .btn-menu-mobile { width: 38px; height: 38px; }
            .white-card, .table-responsive-floating { max-width: 100%; min-width: 0; }
            .modal-overlay { padding: 8px; }
            .modal-box-large { max-width: 100%; padding: 14px; max-height: 90dvh; }
            .pagination-container { flex-wrap: wrap; gap: 10px; }
            .table-responsive, .table-responsive-floating { overflow-x: auto !important; -webkit-overflow-scrolling: touch; }
            .table-responsive table, .table-responsive-floating table { min-width: 720px; }
            .modal-box-large { width: calc(100vw - 16px); box-sizing: border-box; overflow-x: auto; }
            .modal-box-large input, .modal-box-large select, .modal-box-large textarea { max-width: 100%; box-sizing: border-box; font-size: 16px !important; }
            .modal-box-large form, .modal-box-large .grid-3-col { min-width: 0; }
            .topbar-title { font-size: clamp(13px, 4vw, 17px); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .topbar-right { flex-shrink: 0; }
            button, a.btn-confirm { min-height: 40px; }
            @media (max-width: 480px) {
                .content-area { padding: 8px 6px 32px; }
                .topbar { padding: 8px 6px; }
                .user-profile-badge .profile-email { display: none; }
                .modal-overlay { padding: 4px; }
                .modal-box-large { width: calc(100vw - 8px); padding: 10px; }
            }
        }
        .pdf-icon { display: inline-flex; align-items: center; justify-content: center; padding: 5px 8px; border-radius: 6px; background: #fef2f2; color: #b91c1c; font-size: 12px; font-weight: 900; text-decoration: none; white-space: nowrap; }
        .pdf-icon:hover { background: #fee2e2; }

    </style>
</head>
<body>

    <!-- MENÚ LATERAL: 1. INICIO | 2. CREAR USUARIO | 3. REGISTRO DE PEDIDO | 4. ENVÍO DE SHALOM -->
        <!-- FONDO OSCURO PARA CIERRE DE MENÚ EN CELULARES -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="cerrarMenuMobile()"></div>

    <aside class="sidebar" id="sidebarMenu">
        <div class="sidebar-logo" style="position: relative;">
            <button type="button" class="btn-close-sidebar-mobile" onclick="cerrarMenuMobile()" title="Cerrar Menú">✕</button>
            <img src="uploads/logo.png" alt="Bell's Store Shop" class="logo-emblem-bells" style="width:95px; height:95px; object-fit:contain; border-radius:50%; border:2.5px solid #d4af37; background:#000; box-shadow:0 4px 15px rgba(0,0,0,0.5);" onerror="this.src='uploads/logo.svg'">
            <div style="font-size: 13px; font-weight: 900; color: #facc15; letter-spacing: 1.2px; text-transform: uppercase; margin-top:4px;">BELL'S STORE SHOP</div>
            <div style="font-size: 10px; color: #96c4ab; letter-spacing: 0.8px; font-weight:600;">ROPA AMERICANA EXCLUSIVA</div>
            <a href="logout.php" class="sidebar-logout" title="Cerrar sesión"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5v3H2v4h8v3zm9-14H9v2h10v14H9v2h10a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"/></svg>Cerrar sesión</a>
        </div>

        <ul class="sidebar-menu">
            <!-- 1. INICIO -->
            <li>
                <a class="active" onclick="switchTab('tab-inicio', this, '')">
                    <svg class="menu-svg" viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
                    <span>Inicio</span>
                </a>
            </li>

            <!-- 2. CREAR USUARIO (EXACTAMENTE DEBAJO DE INICIO) -->
            <?php if (tieneAcceso('usuarios', $currentUser, $misPermisos)): ?>
            <li>
                <a onclick="switchTab('tab-usuarios', this, '')">
                    <svg class="menu-svg" viewBox="0 0 24 24"><path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                    <span>Crear Usuario</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- 3. REGISTRO DE PEDIDO -->
            <?php if (tieneAcceso('pedidos', $currentUser, $misPermisos)): ?>
            <li>
                <a onclick="switchTab('tab-envios', this, '')">
                    <svg class="menu-svg" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
                    <span>Registro de Pedido</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- 4. ENVÍO DE SHALOM (NUEVO MÓDULO) -->
            <?php if (tieneAcceso('envios_shalom', $currentUser, $misPermisos)): ?>
            <li>
                <a onclick="switchTab('tab-shalom', this, '')">
                    <svg class="menu-svg" viewBox="0 0 24 24"><path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4zM6 18.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zm13.5-9l1.96 2.5H17V9.5h2.5zm-1.5 9c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>
                    <span>Envío de Shalom</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- MÓDULOS FUTUROS ESCALABLES -->
            <?php if (tieneAcceso('inventario', $currentUser, $misPermisos)): ?>
            <li>
                <a onclick="switchTab('tab-inventario', this, 'Módulo: Inventario')">
                    <svg class="menu-svg" viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-1 9h-4v2h4v7H5v-7h4v-2H5V5h14v6z"/></svg>
                    <span>Inventario</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (tieneAcceso('reportes', $currentUser, $misPermisos)): ?>
            <li>
                <a onclick="switchTab('tab-reportes', this, 'Módulo: Reportes')">
                    <svg class="menu-svg" viewBox="0 0 24 24"><path d="M5 9.2h3V19H5zM10.6 5h2.8v14h-2.8zm5.6 8H19v6h-2.8z"/></svg>
                    <span>Reportes</span>
                </a>
            </li>
            <?php endif; ?>

            <li>
                <a href="index.php" target="_blank">
                    <svg class="menu-svg" viewBox="0 0 24 24"><path d="M19 19H5V5h7V3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2v-7h-2v7zM14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3h-7z"/></svg>
                    <span>Enlace del Formulario</span>
                </a>
            </li>

            <!-- CERRAR SESIÓN INTEGRADO EN EL MENÚ (SOLO PARA CELULAR / TABLET) -->
            <li class="mobile-only-logout-item">
                <a href="logout.php" class="btn-logout-sidebar" title="Cerrar sesión">
                    <svg class="menu-svg" style="fill:#ef4444;" viewBox="0 0 24 24"><path d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>
                    <span style="color:#ef4444; font-weight:700;">Cerrar sesión</span>
                </a>
            </li>
        </ul>
    </aside>

    <!-- WRAPPER PRINCIPAL -->
    <div class="main-wrapper">
        <header class="topbar">
            <div style="display: flex; align-items: center;">
                <button type="button" class="btn-menu-mobile" onclick="toggleMenuMobile()" title="Abrir Menú">
                    <svg viewBox="0 0 24 24"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>
                </button>
                <div class="topbar-title" id="page-title"></div>
            </div>

            <div class="topbar-right">
                <?php if (tieneAcceso('pedidos', $currentUser, $misPermisos)): ?>
                <button type="button" class="btn-alerta-campana" onclick="abrirModalAlertas()">
                    <span>🔔</span>
                    <span class="alert-long">Pedidos para Mañana</span><span class="alert-short">Alerta de pedidos</span>
                    <span class="badge-alert-count"><?= $totalAlertasManana ?></span>
                </button>
                <?php endif; ?>

                <div class="user-profile-badge">
                    <img src="uploads/administrados/<?= htmlspecialchars($_SESSION['usuario_foto'] ?? 'default_avatar.png') ?>" 
                         onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($_SESSION['usuario_nombre']) ?>&background=0b2417&color=38e388'">
                    <div class="profile-email"><?= htmlspecialchars($currentUser['correo'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                </div>

                <a href="logout.php" class="btn-logout-pill desktop-only-logout" title="Cerrar sesión">
                    <svg style="width:14px;height:14px;fill:currentColor;" viewBox="0 0 24 24"><path d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>
                    <span>Cerrar sesión</span>
                </a>
            </div>
        </header>

        <main class="content-area">
            <?php if ($msg): ?>
                <div class="alert-box <?= $tipo_msg ?>" style="margin-bottom: 20px;">
                    <?= $msg ?>
                </div>
            <?php endif; ?>

            <!-- 1. PESTAÑA INICIO -->
            <div id="tab-inicio" class="tab-panel active">
                <div class="white-card">
                    <h2 style="margin: 0;">Bienvenido al Sistema de Despachos 👋</h2>
                </div>

                <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:20px;">
                    <div class="white-card" style="border-left:5px solid var(--color-verde);">
                        <div style="font-size:12.5px; font-weight:700; color:#64748b;">TOTAL DE PEDIDOS</div>
                        <div style="font-size:36px; font-weight:800; color:#0b2417;"><?= $total_pedidos ?></div>
                    </div>
                    <div class="white-card" style="border-left:5px solid var(--color-rojo);">
                        <div style="font-size:12.5px; font-weight:700; color:#64748b;">ENVÍOS PENDIENTES</div>
                        <div style="font-size:36px; font-weight:800; color:var(--color-rojo);"><?= $pedidos_pendientes ?></div>
                    </div>
                    <div class="white-card" style="border-left:5px solid var(--color-verde);">
                        <div style="font-size:12.5px; font-weight:700; color:#64748b;">PEDIDOS DESPACHADOS</div>
                        <div style="font-size:36px; font-weight:800; color:var(--color-verde);"><?= $pedidos_despachados ?></div>
                    </div>
                </div>
            </div>

            <!-- 2. PESTAÑA CREAR USUARIO (UBICADA 2DO) -->
            <?php if (tieneAcceso('usuarios', $currentUser, $misPermisos)): ?>
            <div id="tab-usuarios" class="tab-panel">
                <div class="white-card">
                    <div class="card-header-flex">
                        <div>
                            <small style="color: #64748b; font-weight:600;">Crea y administra usuarios con sus permisos modulares</small>
                        </div>

                        <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                            <div class="search-box-container">
                                <svg class="search-icon-svg" viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                                <input type="text" id="buscarUsuarioInput" class="input-live-search" placeholder="Buscar usuario, DNI o correo...">
                            </div>

                            <button type="button" class="btn-primary-green" onclick="abrirModalCrearUsuario()">
                                <svg style="width:16px;height:16px;fill:currentColor;" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                                <span>Crear Usuario</span>
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table id="tablaUsuarios">
                            <thead>
                                <tr>
                                    <th>Foto</th>
                                    <th>Nombre Completo</th>
                                    <th>DNI</th>
                                    <th>Celular</th>
                                    <th>Correo</th>
                                    <th>Rol</th>
                                    <th>Permisos</th>
                                    <th>Estado</th>
                                    <th style="text-align:center;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($usuarios as $u): ?>
                                <tr class="fila-usuario">
                                    <td>
                                        <img src="uploads/administrados/<?= htmlspecialchars($u['foto'] ?? 'default_avatar.png') ?>" 
                                             style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:1.5px solid #cbd5e1;"
                                             onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($u['nombre']) ?>&background=0b2417&color=38e388'">
                                    </td>
                                    <td><strong><?= htmlspecialchars($u['nombre'] . ' ' . $u['apellido']) ?></strong></td>
                                    <td><?= htmlspecialchars($u['dni']) ?></td>
                                    <td><?= htmlspecialchars($u['celular'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($u['correo']) ?></td>
                                    <td><strong><?= htmlspecialchars($u['rol']) ?></strong></td>
                                    <td>
                                        <?php if ($u['rol'] === 'SuperAdmin'): ?>
                                            <span style="color:var(--color-verde);font-weight:700;">★ Acceso Total</span>
                                        <?php else: ?>
                                            <small><?= htmlspecialchars($u['permisos_slugs'] ?? 'Sin módulos') ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge-estado <?= $u['estado'] === 'Activo' ? 'Despachado' : 'Pendiente' ?>">
                                            <?= htmlspecialchars($u['estado']) ?>
                                        </span>
                                    </td>
                                    <td style="text-align:center;">
                                        <div class="action-icons-wrap">
                                            <button type="button" class="btn-icon-only btn-icon-edit" title="Editar y Cambiar Clave" onclick="abrirModalEditarUsuario(<?= htmlspecialchars(json_encode($u)) ?>)">
                                                <svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                            </button>
                                            
                                            <?php if($u['estado'] === 'Activo'): ?>
                                                <button type="button" class="btn-icon-only btn-icon-toggle" title="Desactivar" onclick="confirmarCambiarEstadoUsuario(<?= $u['id'] ?>, 'Inactivo')">
                                                    <svg viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn-icon-only btn-icon-toggle" title="Activar" onclick="confirmarCambiarEstadoUsuario(<?= $u['id'] ?>, 'Activo')">
                                                    <svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                                </button>
                                            <?php endif; ?>

                                            <button type="button" class="btn-icon-only btn-icon-delete" title="Eliminar" onclick="confirmarEliminarUsuario(<?= $u['id'] ?>)">
                                                <svg viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- 3. PESTAÑA REGISTRO DE PEDIDOS -->
            <?php if (tieneAcceso('pedidos', $currentUser, $misPermisos)): ?>
            <div id="tab-envios" class="tab-panel">
                <div class="sticky-search-bar">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span style="font-size:18px;">🔍</span>
                        <div>
                            <strong style="font-size:14px; color:#0b2417;">Buscar en Pedidos</strong>
                            <div style="font-size:11px; color:#64748b;">Pendientes primero • Scroll sincronizado</div>
                        </div>
                    </div>

                    <div class="search-box-container" style="width: 380px;">
                        <svg class="search-icon-svg" viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                        <input type="text" id="buscarPedidoInput" class="input-live-search" placeholder="Buscar por destinatario, DNI, WhatsApp, agencia...">
                    </div>
                </div>

                <div class="white-card" style="padding: 15px;">
                    <div class="table-responsive-floating" id="mainTableScroll">
                        <table id="tablaPedidos">
                            <thead>
                                <tr>
                                    <th>N.º</th>
                                    <th>Fecha / Hora</th>
                                    <th>Destinatario</th>
                                    <th>DNI/CE</th>
                                    <th>WhatsApp</th>
                                    <th>Red</th>
                                    <th>Tipo</th>
                                    <th>Empresa</th>
                                    <th>Agencia</th>
                                    <th>Estado</th>
                                    <th>Obs.</th>
                                    <th style="text-align:center;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($envios)): ?>
                                    <tr><td colspan="12" style="text-align: center; color: #94a3b8; padding: 25px;">No hay registros de pedidos.</td></tr>
                                <?php else: ?>
                                    <?php foreach($envios as $indice => $e): ?>
                                    <tr class="fila-pedido">
                                        <td><strong><?= $offset + $indice + 1 ?></strong></td>
                                        <td class="search-target" style="white-space:nowrap;">
                                            <span style="font-weight:700; color:#1e293b;"><?= htmlspecialchars($e['fecha_envio']) ?></span>
                                            <?php if (!empty($e['created_at'])): ?>
                                                <br><small style="color:#64748b; font-size:11px;">🕒 <?= date('h:i A', strtotime($e['created_at'])) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="search-target"><strong><?= htmlspecialchars($e['nombre_completo']) ?></strong></td>
                                        <td class="search-target"><?= htmlspecialchars($e['documento']) ?></td>
                                        <td class="search-target">
                                            <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $e['whatsapp']) ?>" target="_blank" style="color:var(--color-verde); text-decoration: none; font-weight: 700;">
                                                <?= htmlspecialchars($e['whatsapp']) ?>
                                            </a>
                                        </td>
                                        <td class="search-target">
                                            <?php if (!empty($e['red_social'])): ?>
                                                <span style="font-weight:600;"><?= htmlspecialchars($e['red_social']) ?>:</span>
                                                <small><?= htmlspecialchars($e['nombre_red_social']) ?></small>
                                            <?php else: ?>
                                                <span>-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="search-target tipo-pedido" style="white-space:normal; min-width:110px; line-height:1.35;">
                                            <?php if ($e['tipo_entrega'] === 'Retiro en Agencia Shalom'): ?>
                                                Retiro en<br>Agencia Shalom
                                            <?php else: ?><?= htmlspecialchars($e['tipo_entrega']) ?><?php endif; ?>
                                        </td>
                                        <td class="search-target"><strong><?= htmlspecialchars($e['empresa']) ?></strong></td>
                                        <td class="search-target"><a class="pdf-icon" href="pedido_pdf.php?id=<?= (int)$e['id'] ?>&tipo=agencia" target="_blank" rel="noopener" title="Ver dirección de la agencia en PDF">PDF</a></td>
                                        <td>
                                            <span class="badge-estado <?= $e['estado'] ?>">
                                                <?= ($e['estado'] === 'Pendiente') ? '● Pendiente' : '✔ Despachado' ?>
                                            </span>
                                        </td>
                                        <td><?php if (trim($e['observacion'] ?? '') !== ''): ?>
                                            <a class="pdf-icon" href="pedido_pdf.php?id=<?= (int)$e['id'] ?>&tipo=observacion" target="_blank" rel="noopener" title="Abrir observación en PDF">PDF</a>
                                        <?php else: ?>—<?php endif; ?></td>
                                        <td style="text-align:center;">
                                            <div class="action-icons-wrap">
                                                <a class="pdf-icon" href="pedido_pdf.php?id=<?= (int)$e['id'] ?>&tipo=cliente" target="_blank" rel="noopener" title="Datos del cliente en PDF horizontal">PDF ↔</a>
                                                <button type="button" class="btn-icon-only btn-icon-edit" title="Editar pedido" onclick="abrirModalEditarEnvio(<?= htmlspecialchars(json_encode($e)) ?>)">
                                                    <svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                                </button>
                                                <button type="button" class="btn-icon-only btn-icon-delete" title="Eliminar pedido" onclick="confirmarEliminarEnvio(<?= $e['id'] ?>)">
                                                    <svg viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="floating-scroll-track" id="floatingScrollTrack">
                        <div class="floating-scroll-dummy" id="floatingScrollDummy"></div>
                    </div>

                    <div class="pagination-container">
                        <div>Mostrando página <strong><?= $pagina ?></strong> de <strong><?= $total_paginas ?></strong></div>
                        <div class="pagination-btns">
                            <?php if ($pagina > 1): ?>
                                <a href="?p=<?= $pagina - 1 ?>#tab-envios" class="btn-page">« Anterior</a>
                            <?php endif; ?>
                            <?php for($i = max(1, $pagina - 2); $i <= min($total_paginas, $pagina + 2); $i++): ?>
                                <a href="?p=<?= $i ?>#tab-envios" class="btn-page <?= ($i == $pagina) ? 'active' : '' ?>"><?= $i ?></a>
                            <?php endfor; ?>
                            <?php if ($pagina < $total_paginas): ?>
                                <a href="?p=<?= $pagina + 1 ?>#tab-envios" class="btn-page">Siguiente »</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- 4. PESTAÑA NUEVA: ENVÍO DE SHALOM -->
            <?php if (tieneAcceso('envios_shalom', $currentUser, $misPermisos)): ?>
            <div id="tab-shalom" class="tab-panel">
                <div class="white-card">
                    <div class="card-header-flex">
                        <div>
                            <small style="color: #64748b; font-weight:600;">Control de envíos con número de orden, boleta y clave de retiro</small>
                        </div>

                        <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                            <div class="search-box-container">
                                <svg class="search-icon-svg" viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                                <input type="text" id="buscarShalomInput" class="input-live-search" placeholder="Buscar por nombre, orden, boleta...">
                            </div>

                            <button type="button" class="btn-primary-green" onclick="abrirModalCrearShalom()">
                                <svg style="width:16px;height:16px;fill:currentColor;" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                                <span>Nuevo Envío Shalom</span>
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table id="tablaShalom">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>DNI/CE</th>
                                    <th>Nombre y Apellidos</th>
                                    <th>N° Orden</th>
                                    <th>Cód. Boleta</th>
                                    <th>Clave Seg.</th>
                                    <th>Foto Boleta</th>
                                    <th>Fecha / Hora</th>
                                    <th style="text-align:center;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($listaShalom)): ?>
                                    <tr><td colspan="9" style="text-align:center; padding:25px; color:#94a3b8;">No hay registros de Envíos Shalom aún.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($listaShalom as $s): ?>
                                    <tr class="fila-shalom">
                                        <td><strong>#<?= $s['id'] ?></strong></td>
                                        <td><span style="font-weight:700; color:#1e293b;"><?= htmlspecialchars($s['dni'] ?? '-') ?></span></td>
                                        <td><strong><?= htmlspecialchars($s['nombre_apellido']) ?></strong></td>
                                        <td><code style="font-size:13px;"><?= htmlspecialchars($s['numero_orden']) ?></code></td>
                                        <td><code style="font-size:13px; font-weight:700; color:#0b2417;"><?= htmlspecialchars($s['codigo_boleta'] ?? ($s['numero_boleta'] ?? '-')) ?></code></td>
                                        <td><span style="background:#fef08a; padding:4px 8px; border-radius:6px; font-weight:700; color:#854d0e;"><?= htmlspecialchars($s['clave']) ?></span></td>
                                        <td>
                                            <?php if (!empty($s['foto_boleta'])): ?>
                                                <a href="uploads/boletas/<?= htmlspecialchars($s['foto_boleta']) ?>" target="_blank" style="display:inline-flex; align-items:center; gap:4px; color:#2563eb; font-weight:700; text-decoration:none; font-size:12px; background:#eff6ff; padding:3px 8px; border-radius:6px; border:1px solid #bfdbfe;">
                                                    📷 Ver Foto
                                                </a>
                                            <?php else: ?>
                                                <span style="color:#94a3b8; font-size:11.5px;">Sin foto</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="white-space:nowrap;">
                                            <span style="font-weight:700; color:#1e293b;"><?= date('Y-m-d', strtotime($s['created_at'])) ?></span>
                                            <br><small style="color:#64748b; font-size:11px;">🕒 <?= date('h:i A', strtotime($s['created_at'])) ?></small>
                                        </td>
                                        <td style="text-align:center;">
                                            <div class="action-icons-wrap">
                                                <button type="button" class="btn-icon-only btn-icon-edit" title="Editar" onclick="abrirModalEditarShalom(<?= htmlspecialchars(json_encode($s)) ?>)">
                                                    <svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                                </button>
                                                <button type="button" class="btn-icon-only btn-icon-delete" title="Eliminar" onclick="confirmarEliminarShalom(<?= $s['id'] ?>)">
                                                    <svg viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- MÓDULOS FUTUROS -->
            <?php if (tieneAcceso('inventario', $currentUser, $misPermisos)): ?>
            <div id="tab-inventario" class="tab-panel">
                <div class="white-card">
                    <h3>📦 Módulo: Inventario (Preparado a Futuro)</h3>
                    <p style="color:#64748b; margin-top:8px;">Módulo escalable protegido por el sistema de permisos.</p>
                </div>
            </div>
            <?php endif; ?>

            <?php if (tieneAcceso('reportes', $currentUser, $misPermisos)): ?>
            <div id="tab-reportes" class="tab-panel">
                <div class="white-card">
                    <h3>📊 Módulo: Reportes (Preparado a Futuro)</h3>
                    <p style="color:#64748b; margin-top:8px;">Módulo escalable protegido por el sistema de permisos.</p>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- MODAL CREAR ENVÍO SHALOM (CON BÚSQUEDA Y AUTOCOMPLETADO POR DNI) -->
    <div id="modalCrearShalom" class="modal-overlay">
        <div class="modal-box-large" style="max-width: 600px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
                <div style="font-size:18px; font-weight:700; color:#0b2417;">Nuevo Registro: Envío de Shalom</div>
                <button type="button" class="btn-cancel" style="padding:4px 10px;" onclick="cerrarModal('modalCrearShalom')">✕</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action_shalom" value="crear">
                
                <!-- 1. CAMPO DNI QUE JALA LOS DATOS AUTOMÁTICAMENTE DE REGISTRO DE PEDIDOS -->
                <div class="form-group">
                    <label style="color:#0b2417; font-weight:700;">Ingrese Número de DNI / CE *</label>
                    <div style="display:flex; gap:8px;">
                        <input type="text" name="dni" id="shalom_crear_dni" class="form-control" placeholder="Digita el DNI del destinatario..." required autofocus>
                        <button type="button" class="btn" style="width:auto; padding:0 15px; background:#0b2417; color:#fff; font-size:12px; font-weight:700;" onclick="buscarDatosPorDniShalom()">
                            🔍 Buscar
                        </button>
                    </div>
                    <small id="dni_status_feedback" style="font-size:11.5px; margin-top:4px; display:block; color:#64748b;">
                        Ingresa el DNI para autocompletar el nombre desde Registro de Pedidos.
                    </small>
                </div>

                <!-- 2. NOMBRE Y APELLIDO (AUTOCOMPLETADO AL DIGITAR DNI) -->
                <div class="form-group">
                    <label>Nombre y Apellido de la persona *</label>
                    <input type="text" name="nombre_apellido" id="shalom_crear_nombre" class="form-control" placeholder="Se completará automáticamente con el DNI" required>
                </div>

                <!-- 3. NÚMERO DE ORDEN -->
                <div class="form-group">
                    <label>Número de Orden *</label>
                    <input type="text" name="numero_orden" class="form-control" placeholder="Ej: ORD-98234" required>
                </div>

                <!-- 4. CÓDIGO DE BOLETA (ACTUALIZADO) -->
                <div class="form-group">
                    <label>Código de Boleta *</label>
                    <input type="text" name="codigo_boleta" class="form-control" placeholder="Ej: B001-000492" required>
                </div>

                <!-- 5. CLAVE DE SEGURIDAD -->
                <div class="form-group">
                    <label>Clave de Seguridad *</label>
                    <input type="text" name="clave" class="form-control" placeholder="Ej: 4892" required>
                </div>

                <!-- 6. SUBIR FOTO (OPCIONAL - ACTIVA CÁMARA O ARCHIVO) -->
                <div class="form-group" style="background:#f8fafc; border:1.5px dashed #cbd5e1; padding:12px; border-radius:10px;">
                    <label style="color:#0b2417; font-weight:700; display:flex; align-items:center; gap:6px;">
                        <span>📷</span>
                        <span>Subir Foto de Boleta (Opcional - Activa tu cámara)</span>
                    </label>
                    <input type="file" name="foto_boleta" id="input_foto_boleta" class="form-control" accept="image/*" capture="environment" style="background:#fff;">
                    <small style="color:#64748b; font-size:11.5px; display:block; margin-top:4px;">
                        📱 En celular o tablet abrirá automáticamente la cámara trasera para fotografiar la boleta.
                    </small>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="cerrarModal('modalCrearShalom')">Cancelar</button>
                    <button type="submit" class="btn-confirm primary">Guardar Envío Shalom</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL EDITAR ENVÍO SHALOM -->
    <div id="modalEditarShalom" class="modal-overlay">
        <div class="modal-box-large" style="max-width: 600px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
                <div style="font-size:18px; font-weight:700; color:#0b2417;">Editar Envío de Shalom</div>
                <button type="button" class="btn-cancel" style="padding:4px 10px;" onclick="cerrarModal('modalEditarShalom')">✕</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action_shalom" value="editar">
                <input type="hidden" name="shalom_id" id="edit_shalom_id">
                <div class="form-group">
                    <label>Número de DNI / CE</label>
                    <input type="text" name="dni" id="edit_shalom_dni" class="form-control">
                </div>
                <div class="form-group">
                    <label>Nombre y Apellido *</label>
                    <input type="text" name="nombre_apellido" id="edit_shalom_nombre" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Número de Orden *</label>
                    <input type="text" name="numero_orden" id="edit_shalom_orden" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Código de Boleta *</label>
                    <input type="text" name="codigo_boleta" id="edit_shalom_boleta" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Clave *</label>
                    <input type="text" name="clave" id="edit_shalom_clave" class="form-control" required>
                </div>
                <div class="form-group" style="background:#f8fafc; border:1.5px dashed #cbd5e1; padding:12px; border-radius:10px;">
                    <label style="color:#0b2417; font-weight:700;">Cambiar/Subir Foto de Boleta (Activa cámara)</label>
                    <input type="file" name="foto_boleta_edit" class="form-control" accept="image/*" capture="environment" style="background:#fff;">
                    <div id="preview_foto_actual" style="font-size:11.5px; color:#2563eb; margin-top:4px;"></div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="cerrarModal('modalEditarShalom')">Cancelar</button>
                    <button type="submit" class="btn-confirm primary">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL CREAR USUARIO -->
    <div id="modalCrearUsuario" class="modal-overlay">
        <div class="modal-box-large" style="max-width: 850px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
                <div style="font-size:18px; font-weight:700; color:#0b2417;">Registrar Nuevo Usuario</div>
                <button type="button" class="btn-cancel" style="padding:4px 10px;" onclick="cerrarModal('modalCrearUsuario')">✕</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action_usuario" value="crear">
                <div class="grid-3-col">
                    <div class="form-group">
                        <label>Nombre *</label>
                        <input type="text" name="nombre" class="form-control" placeholder="Ej: Juan" required>
                    </div>
                    <div class="form-group">
                        <label>Apellido *</label>
                        <input type="text" name="apellido" class="form-control" placeholder="Ej: Pérez" required>
                    </div>
                    <div class="form-group">
                        <label>DNI *</label>
                        <input type="text" name="dni" class="form-control" placeholder="Número de DNI" required>
                    </div>
                    <div class="form-group">
                        <label>Celular (Alertas) *</label>
                        <input type="text" name="celular" class="form-control" placeholder="+51 9XXXXXXXX" required>
                    </div>
                    <div class="form-group">
                        <label>Correo Electrónico *</label>
                        <input type="email" name="correo" class="form-control" placeholder="usuario@gmail.com" required>
                    </div>
                    <div class="form-group">
                        <label>Rol del Usuario *</label>
                        <select name="rol" class="form-control">
                            <option value="Operador" selected>Operador (Permisos Asignados)</option>
                            <option value="SuperAdmin">SuperAdmin (Acceso a Todo)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Contraseña *</label>
                        <input type="password" name="clave" class="form-control" placeholder="••••••••" required>
                    </div>
                    <div class="form-group">
                        <label>Confirmar Contraseña *</label>
                        <input type="password" name="confirmar_clave" class="form-control" placeholder="Repite la contraseña" required>
                    </div>
                    <div class="form-group">
                        <label>Foto de Perfil</label>
                        <input type="file" name="foto" class="form-control" accept="image/*">
                    </div>

                    <div class="form-group col-span-3">
                        <div class="permisos-card">
                            <label style="color:#0b2417; font-weight:700;">🔐 Permisos de Módulos (Presentes y Futuros):</label>
                            <div class="permisos-grid">
                                <?php foreach($listaModulos as $mod): ?>
                                <label class="permiso-label">
                                    <input type="checkbox" name="permisos[]" value="<?= htmlspecialchars($mod['slug']) ?>" <?= in_array($mod['slug'], ['pedidos', 'envios_shalom']) ? 'checked' : '' ?>>
                                    <span><?= htmlspecialchars($mod['nombre']) ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="cerrarModal('modalCrearUsuario')">Cancelar</button>
                    <button type="submit" class="btn-confirm primary">Guardar Usuario</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL EDITAR USUARIO (CON ACTUALIZACIÓN INMEDIATA DE CLAVE) -->
    <div id="modalEditarUsuario" class="modal-overlay">
        <div class="modal-box-large" style="max-width: 850px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
                <div style="font-size:18px; font-weight:700; color:#0b2417;">Editar Datos y Cambiar Contraseña</div>
                <button type="button" class="btn-cancel" style="padding:4px 10px;" onclick="cerrarModal('modalEditarUsuario')">✕</button>
            </div>
            <form method="POST" id="formEditarUsuario" enctype="multipart/form-data">
                <input type="hidden" name="action_usuario" value="editar">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="grid-3-col">
                    <div class="form-group">
                        <label>Nombre</label>
                        <input type="text" name="nombre" id="edit_user_nombre" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Apellido</label>
                        <input type="text" name="apellido" id="edit_user_apellido" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>DNI</label>
                        <input type="text" name="dni" id="edit_user_dni" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Celular</label>
                        <input type="text" name="celular" id="edit_user_celular" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Correo</label>
                        <input type="email" name="correo" id="edit_user_correo" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Rol</label>
                        <select name="rol" id="edit_user_rol" class="form-control">
                            <option value="Operador">Operador</option>
                            <option value="SuperAdmin">SuperAdmin</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label style="color:#b91c1c; font-weight:700;">Nueva Clave (escribe aquí para cambiarla)</label>
                        <input type="password" name="clave" class="form-control" placeholder="Escribe nueva clave si deseas cambiarla">
                    </div>
                    <div class="form-group col-span-2">
                        <label>Cambiar Imagen de Perfil</label>
                        <input type="file" name="foto_edit" class="form-control" accept="image/*">
                    </div>

                    <div class="form-group col-span-3">
                        <div class="permisos-card">
                            <label style="color:#0b2417; font-weight:700;">🔐 Permisos de Acceso:</label>
                            <div class="permisos-grid" id="contenedorPermisosEditar">
                                <?php foreach($listaModulos as $mod): ?>
                                <label class="permiso-label">
                                    <input type="checkbox" name="permisos[]" value="<?= htmlspecialchars($mod['slug']) ?>" id="edit_perm_<?= htmlspecialchars($mod['slug']) ?>">
                                    <span><?= htmlspecialchars($mod['nombre']) ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="cerrarModal('modalEditarUsuario')">Cancelar</button>
                    <button type="button" class="btn-confirm primary" onclick="solicitarConfirmacionEditarUsuario()">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL EDITAR ENVÍO GENERAL -->
    <div id="modalEditarEnvio" class="modal-overlay">
        <div class="modal-box-large">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
                <div style="font-size:18px; font-weight:700; color:#0b2417;">Editar Registro de Pedido</div>
                <button type="button" class="btn-cancel" style="padding:4px 10px;" onclick="cerrarModal('modalEditarEnvio')">✕</button>
            </div>
            <form method="POST" id="formEditarEnvio">
                <input type="hidden" name="action_envio" value="editar_completo">
                <input type="hidden" name="envio_id" id="edit_envio_id">
                <input type="hidden" name="pagina_retorno" value="<?= $pagina ?>">
                
                <div class="grid-3-col">
                    <div class="form-group">
                        <label>Destinatario *</label>
                        <input type="text" name="nombre_completo" id="edit_envio_nombre" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>DNI / Documento *</label>
                        <input type="text" name="documento" id="edit_envio_documento" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>WhatsApp *</label>
                        <input type="text" name="whatsapp" id="edit_envio_whatsapp" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Red Social</label>
                        <select name="red_social" id="edit_envio_red" class="form-control">
                            <option value="">Ninguna</option>
                            <option value="TikTok">TikTok</option>
                            <option value="Facebook">Facebook</option>
                            <option value="Instagram">Instagram</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Nombre en Red Social</label>
                        <input type="text" name="nombre_red_social" id="edit_envio_nom_red" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Fecha de Envío *</label>
                        <input type="date" name="fecha_envio" id="edit_envio_fecha" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Empresa *</label>
                        <input type="text" name="empresa" id="edit_envio_empresa" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Tipo Entrega *</label>
                        <input type="text" name="tipo_entrega" id="edit_envio_tipo" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Estado del Envío</label>
                        <select name="estado" id="edit_envio_estado" class="form-control">
                            <option value="Pendiente">● Pendiente</option>
                            <option value="Despachado">✔ Despachado</option>
                        </select>
                        <div id="aviso_bloqueo_despacho" class="locked-notice" style="display:none;">
                            🔒 Este pedido ya fue despachado y no puede revertirse.
                        </div>
                    </div>
                    <div class="form-group col-span-3">
                        <label>Agencia / Dirección Destino *</label>
                        <input type="text" name="agencia_detalle" id="edit_envio_agencia" class="form-control" required>
                    </div>
                    <div class="form-group col-span-3">
                        <label>Observación</label>
                        <input type="text" name="observacion" id="edit_envio_obs" class="form-control" placeholder="Escribe el motivo...">
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="cerrarModal('modalEditarEnvio')">Cancelar</button>
                    <button type="button" class="btn-confirm primary" onclick="solicitarConfirmacionEditarEnvio()">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL ALERTAS -->
    <div id="modalAlertas" class="modal-overlay">
        <div class="modal-box-large" style="max-width: 750px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
                <div style="font-size:18px; font-weight:700; color:var(--color-rojo);">
                    ⚠️ Alertas: Pedidos Pendientes para Mañana (<?= $fecha_manana ?>)
                </div>
                <button type="button" class="btn-cancel" style="padding:4px 10px;" onclick="cerrarModal('modalAlertas')">✕</button>
            </div>

            <div style="font-size:13.5px; color:#475569; margin-bottom:15px;">
                Tienes <strong><?= $totalAlertasManana ?></strong> pedido(s) pendientes de despacho para mañana:
            </div>

            <?php if ($totalAlertasManana > 0): ?>
                <div class="table-responsive" style="max-height:260px; overflow-y:auto; margin-bottom:20px;">
                    <table>
                        <thead>
                            <tr>
                                <th>#ID</th>
                                <th>Destinatario</th>
                                <th>WhatsApp</th>
                                <th>Empresa</th>
                                <th>Agencia</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pedidosParaManana as $pm): ?>
                            <tr>
                                <td><strong>#<?= $pm['id'] ?></strong></td>
                                <td><?= htmlspecialchars($pm['nombre_completo']) ?><br><small>DNI: <?= htmlspecialchars($pm['documento']) ?></small></td>
                                <td><?= htmlspecialchars($pm['whatsapp']) ?></td>
                                <td><strong><?= htmlspecialchars($pm['empresa']) ?></strong></td>
                                <td style="font-size:12px;"><?= htmlspecialchars($pm['agencia_detalle']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                    <a href="<?= $linkWspAlertaAdmin ?>" target="_blank" class="btn-confirm primary" style="background:#25d366; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                        <span>💬</span> Enviar Alerta a mi WhatsApp
                    </a>
                    <button type="button" class="btn-confirm primary" onclick="enviarAlertaCorreo()">
                        <span>✉️</span> Enviar Alerta a mi Correo
                    </button>
                </div>
            <?php else: ?>
                <div style="padding:25px; text-align:center; color:var(--color-verde); font-weight:600;">
                    🎉 ¡No hay pedidos pendientes programados para mañana!
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- MODAL CONFIRMACIÓN GENÉRICO -->
    <div id="modalConfirmacion" class="modal-overlay">
        <div class="modal-box-large" style="max-width: 440px;">
            <div style="font-size:18px; font-weight:700; color:#0b2417; margin-bottom:10px;" id="modalConfirmTitle">Confirmación</div>
            <div style="font-size: 14px; color: #475569; margin-bottom: 20px;" id="modalConfirmMessage">
                ¿Está seguro de realizar esta acción?
            </div>
            <div class="modal-actions" style="margin:0; padding:0; border:none;">
                <button type="button" class="btn-cancel" onclick="cerrarModalConfirmacion()">Cancelar</button>
                <button type="button" class="btn-confirm danger" id="btnModalConfirmOk">OK</button>
            </div>
        </div>
    </div>

    <form id="formActionRapida" method="POST" style="display: none;"></form>

    <script>
        // Barra horizontal que permanece visible mientras se recorre el registro.
        (function() {
            const table = document.getElementById('mainTableScroll');
            const track = document.getElementById('floatingScrollTrack');
            const dummy = document.getElementById('floatingScrollDummy');
            const scroller = document.querySelector('.content-area');
            if (!table || !track || !dummy || !scroller) return;
            let syncing = false;
            function update() {
                const visible = document.getElementById('tab-envios').classList.contains('active');
                const rect = table.getBoundingClientRect();
                const area = scroller.getBoundingClientRect();
                const useful = visible && table.scrollWidth > table.clientWidth + 2 &&
                    rect.top < area.bottom - 30 && rect.bottom > area.bottom - 30;
                track.style.display = useful ? 'block' : 'none';
                if (!useful) return;
                track.style.left = rect.left + 'px';
                track.style.width = rect.width + 'px';
                dummy.style.width = table.scrollWidth + 'px';
                track.scrollLeft = table.scrollLeft;
            }
            track.addEventListener('scroll', () => { if (syncing) return; syncing = true; table.scrollLeft = track.scrollLeft; syncing = false; });
            table.addEventListener('scroll', () => { if (syncing) return; syncing = true; track.scrollLeft = table.scrollLeft; syncing = false; });
            scroller.addEventListener('scroll', update, {passive: true});
            window.addEventListener('resize', update);
            new ResizeObserver(update).observe(table);
            document.addEventListener('click', () => requestAnimationFrame(update));
            requestAnimationFrame(update);
        })();

        // GESTIÓN DEL MENÚ RESPONSIVE PARA CELULARES Y TABLETS
        function toggleMenuMobile() {
            const sidebar = document.getElementById('sidebarMenu');
            const overlay = document.getElementById('sidebarOverlay');
            if (sidebar) sidebar.classList.toggle('open');
            if (overlay) overlay.classList.toggle('active');
        }

        function cerrarMenuMobile() {
            const sidebar = document.getElementById('sidebarMenu');
            const overlay = document.getElementById('sidebarOverlay');
            if (sidebar) sidebar.classList.remove('open');
            if (overlay) overlay.classList.remove('active');
        }

        function switchTab(tabId, el, titulo) {
            document.querySelectorAll('.tab-panel').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.sidebar-menu a').forEach(a => a.classList.remove('active'));
            
            const target = document.getElementById(tabId);
            if (target) {
                target.classList.add('active');
                if (el) el.classList.add('active');
                document.getElementById('page-title').innerText = titulo;
                if (window.location.hash !== '#' + tabId) {
                    history.replaceState(null, '', window.location.pathname + window.location.search + '#' + tabId);
                }
                cerrarMenuMobile();
            }
        }

        function restaurarModulo() {
            const slug = window.location.hash.slice(1);
            const permitidos = ['tab-inicio', 'tab-usuarios', 'tab-envios', 'tab-shalom', 'tab-inventario', 'tab-reportes'];
            if (!permitidos.includes(slug)) return;
            const link = document.querySelector('.sidebar-menu a[onclick*="' + slug + '"]');
            if (link) switchTab(slug, link, '');
        }
        restaurarModulo();
        window.addEventListener('hashchange', restaurarModulo);

        function cerrarModal(id) { document.getElementById(id).style.display = 'none'; }
        function abrirModalCrearUsuario() { document.getElementById('modalCrearUsuario').style.display = 'flex'; }
        function abrirModalCrearShalom() { document.getElementById('modalCrearShalom').style.display = 'flex'; }
        function abrirModalAlertas() { document.getElementById('modalAlertas').style.display = 'flex'; }

        // Buscador de Usuarios
        const bUser = document.getElementById('buscarUsuarioInput');
        if (bUser) {
            bUser.addEventListener('input', function() {
                const query = this.value.toLowerCase().trim();
                document.querySelectorAll('#tablaUsuarios .fila-usuario').forEach(f => {
                    f.style.display = f.innerText.toLowerCase().includes(query) ? '' : 'none';
                });
            });
        }

        // Buscador de Pedidos Generales
        const bPed = document.getElementById('buscarPedidoInput');
        if (bPed) {
            bPed.addEventListener('input', function() {
                const query = this.value.toLowerCase().trim();
                document.querySelectorAll('#tablaPedidos .fila-pedido').forEach(f => {
                    f.style.display = f.innerText.toLowerCase().includes(query) ? '' : 'none';
                });
            });
        }

        // Buscador de Envíos Shalom
        const bShal = document.getElementById('buscarShalomInput');
        if (bShal) {
            bShal.addEventListener('input', function() {
                const query = this.value.toLowerCase().trim();
                document.querySelectorAll('#tablaShalom .fila-shalom').forEach(f => {
                    f.style.display = f.innerText.toLowerCase().includes(query) ? '' : 'none';
                });
            });
        }

        function enviarAlertaCorreo() {
            fetch('notificar_pendientes.php?ajax=1&manual=1')
            .then(res => res.json())
            .then(data => alert(data.mensaje))
            .catch(() => alert('Error al enviar alerta.'));
        }

        let accionConfirmadaCallback = null;
        function mostrarModalConfirmacion(titulo, mensaje, callback, esPeligroso = true) {
            document.getElementById('modalConfirmTitle').innerText = titulo;
            document.getElementById('modalConfirmMessage').innerText = mensaje;
            const btnOk = document.getElementById('btnModalConfirmOk');
            btnOk.className = esPeligroso ? 'btn-confirm danger' : 'btn-confirm primary';
            accionConfirmadaCallback = callback;
            document.getElementById('modalConfirmacion').style.display = 'flex';
        }

        function cerrarModalConfirmacion() {
            document.getElementById('modalConfirmacion').style.display = 'none';
            accionConfirmadaCallback = null;
        }

        document.getElementById('btnModalConfirmOk').addEventListener('click', function() {
            if (accionConfirmadaCallback) accionConfirmadaCallback();
            cerrarModalConfirmacion();
        });


        // AUTOCOMPLETADO DE NOMBRE SEGÚN DNI DESDE REGISTRO DE PEDIDOS
        const inputDniShalom = document.getElementById('shalom_crear_dni');
        const feedbackDni = document.getElementById('dni_status_feedback');
        let timerDni = null;

        if (inputDniShalom) {
            inputDniShalom.addEventListener('input', function() {
                clearTimeout(timerDni);
                const val = this.value.trim();
                if (val.length >= 7) {
                    timerDni = setTimeout(() => {
                        buscarDatosPorDniShalom();
                    }, 300);
                } else {
                    feedbackDni.innerHTML = 'Ingresa el DNI para autocompletar el nombre desde Registro de Pedidos.';
                    feedbackDni.style.color = '#64748b';
                }
            });
        }

        function buscarDatosPorDniShalom() {
            const dniVal = document.getElementById('shalom_crear_dni').value.trim();
            if (!dniVal) return;

            feedbackDni.innerHTML = '⏳ Buscando persona en Registro de Pedidos...';
            feedbackDni.style.color = '#2563eb';

            fetch(`buscar_persona_dni.php?dni=${encodeURIComponent(dniVal)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.encontrado) {
                        document.getElementById('shalom_crear_nombre').value = data.datos.nombre_completo;
                        feedbackDni.innerHTML = `✅ <strong>Persona encontrada:</strong> ${data.datos.nombre_completo} (Tel: ${data.datos.whatsapp || 'Sin cel'})`;
                        feedbackDni.style.color = '#059669';
                    } else {
                        feedbackDni.innerHTML = '⚠️ No se encontró este DNI en Registro de Pedidos. Puedes escribir el nombre manualmente.';
                        feedbackDni.style.color = '#d97706';
                    }
                })
                .catch(() => {
                    feedbackDni.innerHTML = 'Error al consultar la base de datos.';
                    feedbackDni.style.color = '#dc2626';
                });
        }

        // Editar Shalom con DNI y Código de Boleta
        function abrirModalEditarShalom(s) {
            document.getElementById('edit_shalom_id').value = s.id;
            document.getElementById('edit_shalom_dni').value = s.dni || '';
            document.getElementById('edit_shalom_nombre').value = s.nombre_apellido;
            document.getElementById('edit_shalom_orden').value = s.numero_orden;
            document.getElementById('edit_shalom_boleta').value = s.codigo_boleta || (s.numero_boleta || '');
            document.getElementById('edit_shalom_clave').value = s.clave;
            
            const prevFoto = document.getElementById('preview_foto_actual');
            if (prevFoto) {
                if (s.foto_boleta) {
                    prevFoto.innerHTML = `<a href="uploads/boletas/${s.foto_boleta}" target="_blank">📷 Ver foto actual de la boleta</a>`;
                } else {
                    prevFoto.innerHTML = 'Sin foto previa adjunta.';
                }
            }
            document.getElementById('modalEditarShalom').style.display = 'flex';
        }


        function confirmarEliminarShalom(id) {
            mostrarModalConfirmacion('Eliminar Envío Shalom', '¿Está seguro de eliminar este registro de Shalom?', function() {
                const f = document.getElementById('formActionRapida');
                f.innerHTML = `<input type="hidden" name="action_shalom" value="eliminar"><input type="hidden" name="shalom_id" value="${id}">`;
                f.submit();
            }, true);
        }

        // ENVÍOS GENERALES: EDITAR Y ELIMINAR
        function abrirModalEditarEnvio(envio) {
            document.getElementById('edit_envio_id').value = envio.id;
            document.getElementById('edit_envio_nombre').value = envio.nombre_completo;
            document.getElementById('edit_envio_documento').value = envio.documento;
            document.getElementById('edit_envio_whatsapp').value = envio.whatsapp;
            document.getElementById('edit_envio_fecha').value = envio.fecha_envio;
            document.getElementById('edit_envio_red').value = envio.red_social || '';
            document.getElementById('edit_envio_nom_red').value = envio.nombre_red_social || '';
            document.getElementById('edit_envio_empresa').value = envio.empresa;
            document.getElementById('edit_envio_tipo').value = envio.tipo_entrega;
            document.getElementById('edit_envio_agencia').value = envio.agencia_detalle;
            document.getElementById('edit_envio_obs').value = envio.observacion || '';

            const selectEstado = document.getElementById('edit_envio_estado');
            const aviso = document.getElementById('aviso_bloqueo_despacho');
            selectEstado.value = envio.estado;

            if (envio.estado === 'Despachado') {
                selectEstado.disabled = true;
                aviso.style.display = 'block';
            } else {
                selectEstado.disabled = false;
                aviso.style.display = 'none';
            }

            document.getElementById('modalEditarEnvio').style.display = 'flex';
        }

        function solicitarConfirmacionEditarEnvio() {
            document.getElementById('edit_envio_estado').disabled = false;
            mostrarModalConfirmacion('Confirmar Edición', '¿Está seguro de guardar los cambios de este pedido?', function() {
                document.getElementById('formEditarEnvio').submit();
            }, false);
        }

        function confirmarEliminarEnvio(id) {
            mostrarModalConfirmacion('Confirmar Eliminación', '¿Está seguro de eliminar este pedido permanentemente?', function() {
                const f = document.getElementById('formActionRapida');
                f.innerHTML = `<input type="hidden" name="action_envio" value="eliminar"><input type="hidden" name="envio_id" value="${id}">`;
                f.submit();
            }, true);
        }

        // USUARIOS: EDITAR Y CAMBIAR CLAVE
        function abrirModalEditarUsuario(u) {
            document.getElementById('edit_user_id').value = u.id;
            document.getElementById('edit_user_nombre').value = u.nombre;
            document.getElementById('edit_user_apellido').value = u.apellido;
            document.getElementById('edit_user_dni').value = u.dni;
            document.getElementById('edit_user_celular').value = u.celular || '';
            document.getElementById('edit_user_correo').value = u.correo;
            document.getElementById('edit_user_rol').value = u.rol;

            const permisosArray = (u.permisos_slugs || '').split(',');
            document.querySelectorAll('#contenedorPermisosEditar input[type="checkbox"]').forEach(cb => {
                cb.checked = (u.rol === 'SuperAdmin') ? true : permisosArray.includes(cb.value);
            });

            document.getElementById('modalEditarUsuario').style.display = 'flex';
        }

        function solicitarConfirmacionEditarUsuario() {
            mostrarModalConfirmacion('Confirmar Edición', '¿Está seguro de actualizar los datos y la contraseña del usuario?', function() {
                document.getElementById('formEditarUsuario').submit();
            }, false);
        }

        function confirmarCambiarEstadoUsuario(id, nuevoEstado) {
            const accionTexto = (nuevoEstado === 'Inactivo') ? 'desactivar' : 'activar';
            mostrarModalConfirmacion('Cambio de Estado', `¿Está seguro de ${accionTexto} a este usuario?`, function() {
                const f = document.getElementById('formActionRapida');
                f.innerHTML = `<input type="hidden" name="action_usuario" value="cambiar_estado"><input type="hidden" name="user_id" value="${id}"><input type="hidden" name="nuevo_estado" value="${nuevoEstado}">`;
                f.submit();
            }, (nuevoEstado === 'Inactivo'));
        }

        function confirmarEliminarUsuario(id) {
            mostrarModalConfirmacion('Confirmar Eliminación', '¿Está seguro de eliminar a este usuario?', function() {
                const f = document.getElementById('formActionRapida');
                f.innerHTML = `<input type="hidden" name="action_usuario" value="eliminar"><input type="hidden" name="user_id" value="${id}">`;
                f.submit();
            }, true);
        }
    </script>
</body>
</html>

<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "sistema_envios";

try {
    $pdo_init = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    $pdo_init->exec("CREATE DATABASE IF NOT EXISTS `$db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Crear tablas
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        apellido VARCHAR(100) NOT NULL,
        dni VARCHAR(20) NOT NULL,
        celular VARCHAR(20) NULL,
        correo VARCHAR(150) NOT NULL,
        username VARCHAR(50) NOT NULL,
        password VARCHAR(255) NOT NULL,
        foto VARCHAR(255) DEFAULT 'default_avatar.png',
        rol ENUM('SuperAdmin', 'Operador') DEFAULT 'Operador',
        estado ENUM('Activo', 'Inactivo') DEFAULT 'Activo',
        reset_token VARCHAR(255) NULL,
        token_expira DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS modulos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(50) NOT NULL UNIQUE,
        nombre VARCHAR(100) NOT NULL,
        icono VARCHAR(50) DEFAULT 'box',
        descripcion VARCHAR(255) NULL
    ) ENGINE=InnoDB;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS usuario_permisos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        modulo_slug VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS envios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        whatsapp VARCHAR(20) NOT NULL,
        red_social VARCHAR(50) NULL,
        nombre_red_social VARCHAR(150) NULL,
        tipo_entrega VARCHAR(50) NOT NULL,
        departamento VARCHAR(100) NULL,
        provincia VARCHAR(100) NULL,
        distrito VARCHAR(100) NULL,
        empresa VARCHAR(50) NOT NULL,
        agencia_detalle TEXT NOT NULL,
        documento VARCHAR(20) NOT NULL,
        nombre_completo VARCHAR(150) NOT NULL,
        fecha_envio DATE NOT NULL,
        estado ENUM('Pendiente', 'Despachado') DEFAULT 'Pendiente',
        observacion TEXT NULL,
        alertas_enviadas INT DEFAULT 0,
        ultima_alerta DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS envios_shalom (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre_apellido VARCHAR(150) NOT NULL,
        numero_orden VARCHAR(50) NOT NULL,
        codigo_boleta VARCHAR(50) NOT NULL,
        foto_boleta VARCHAR(255) NULL,
        clave VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;");

    $pdo->exec("ALTER TABLE envios_shalom ADD COLUMN IF NOT EXISTS dni VARCHAR(20) NULL AFTER id;");
    $pdo->exec("ALTER TABLE envios_shalom ADD COLUMN IF NOT EXISTS codigo_boleta VARCHAR(50) NULL AFTER numero_orden;");
    $pdo->exec("ALTER TABLE envios_shalom ADD COLUMN IF NOT EXISTS foto_boleta VARCHAR(255) NULL AFTER clave;");
    $pdo->exec("INSERT IGNORE INTO modulos (slug, nombre, icono, descripcion) VALUES 
        ('usuarios', 'Crear Usuario', 'users', 'Gestión de usuarios y accesos'),
        ('pedidos', 'Registro de Pedido', 'package', 'Control y despacho de pedidos generales'),
        ('envios_shalom', 'Envío de Shalom', 'truck', 'Módulo de envíos específicos Shalom con orden, boleta y clave'),
        ('inventario', 'Inventario (Módulo Futuro)', 'archive', 'Control de stock y prendas'),
        ('reportes', 'Reportes y Métricas (Módulo Futuro)', 'chart', 'Estadísticas del negocio');");

} catch (PDOException $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}
?>

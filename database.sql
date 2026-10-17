-- REINICIO TOTAL: elimina los datos anteriores al importar este archivo.
-- El nombre exacto «agencia nacionales» se elimina si existe.
DROP DATABASE IF EXISTS `agencia nacionales`;
DROP DATABASE IF EXISTS `sistema_envios`;
CREATE DATABASE sistema_envios DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sistema_envios;

-- Tabla de usuarios
CREATE TABLE IF NOT EXISTS usuarios (
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
) ENGINE=InnoDB;

-- Tabla catálogo de módulos
CREATE TABLE IF NOT EXISTS modulos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(50) NOT NULL UNIQUE,
    nombre VARCHAR(100) NOT NULL,
    icono VARCHAR(50) DEFAULT 'box',
    descripcion VARCHAR(255) NULL
) ENGINE=InnoDB;

-- Tabla relacional de permisos por usuario
CREATE TABLE IF NOT EXISTS usuario_permisos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    modulo_slug VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabla de envíos (Registro de pedidos general)
CREATE TABLE IF NOT EXISTS envios (
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
) ENGINE=InnoDB;

-- NUEVA TABLA: Envío de Shalom
CREATE TABLE IF NOT EXISTS envios_shalom (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dni VARCHAR(20) NULL,
    nombre_apellido VARCHAR(150) NOT NULL,
    numero_orden VARCHAR(50) NOT NULL,
    codigo_boleta VARCHAR(50) NOT NULL,
    clave VARCHAR(50) NOT NULL,
    foto_boleta VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Insertar catálogo de módulos (incluye nuevo módulo 'envios_shalom')
INSERT INTO modulos (slug, nombre, icono, descripcion) VALUES 
('usuarios', 'Crear Usuario', 'users', 'Gestión de usuarios y accesos'),
('pedidos', 'Registro de Pedido', 'package', 'Control y despacho de pedidos generales'),
('envios_shalom', 'Envío de Shalom', 'truck', 'Módulo de envíos específicos Shalom con orden, boleta y clave'),
('inventario', 'Inventario (Módulo Futuro)', 'archive', 'Control de stock y prendas'),
('reportes', 'Reportes y Métricas (Módulo Futuro)', 'chart', 'Estadísticas del negocio')
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), descripcion=VALUES(descripcion);

-- SuperAdmin inicial
INSERT INTO usuarios (id, nombre, apellido, dni, celular, correo, username, password, foto, rol, estado) 
VALUES (1, 'Administrador', 'Principal', '77777777', '+51939641745', 'admin@ejemplo.com', 'admin', '$2y$10$wOq9xYmKzJ/PcmwLw3GveOBk9iXUv7P/1V2k20b4X0uU14u3u8Qyq', 'default_avatar.png', 'SuperAdmin', 'Activo')
ON DUPLICATE KEY UPDATE rol='SuperAdmin', password='$2y$10$wOq9xYmKzJ/PcmwLw3GveOBk9iXUv7P/1V2k20b4X0uU14u3u8Qyq', estado='Activo';

-- Asignar todos los permisos al SuperAdmin
INSERT IGNORE INTO usuario_permisos (usuario_id, modulo_slug) VALUES 
(1, 'usuarios'),
(1, 'pedidos'),
(1, 'envios_shalom'),
(1, 'inventario'),
(1, 'reportes');

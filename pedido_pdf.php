<?php
session_start();
require 'conexion.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    exit('Acceso no autorizado');
}
$usuario = $pdo->prepare('SELECT rol FROM usuarios WHERE id = ?');
$usuario->execute([$_SESSION['usuario_id']]);
$rol = $usuario->fetchColumn();
$permiso = $pdo->prepare("SELECT 1 FROM usuario_permisos WHERE usuario_id = ? AND modulo_slug = 'pedidos' LIMIT 1");
$permiso->execute([$_SESSION['usuario_id']]);
if ($rol !== 'SuperAdmin' && !$permiso->fetchColumn()) {
    http_response_code(403);
    exit('Sin permiso para ver pedidos');
}
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$tipo = $_GET['tipo'] ?? '';
if (!$id || !in_array($tipo, ['cliente', 'observacion', 'agencia'], true)) {
    http_response_code(400);
    exit('Solicitud incorrecta');
}
$consulta = $pdo->prepare('SELECT nombre_completo, documento, whatsapp, tipo_entrega, agencia_detalle, observacion FROM envios WHERE id = ?');
$consulta->execute([$id]);
$pedido = $consulta->fetch(PDO::FETCH_ASSOC);
if (!$pedido) {
    http_response_code(404);
    exit('Pedido no encontrado');
}

// PDF ligero en una sola página, sin dependencias adicionales para XAMPP.
function pdfText($valor) {
    $valor = preg_replace('/\s+/u', ' ', trim((string)$valor));
    $valor = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $valor);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $valor ?: '-');
}
function pdfWrap($texto, $ancho = 78) {
    $texto = trim(preg_replace('/\s+/u', ' ', (string)$texto));
    if ($texto === '') return ['-'];
    return explode("\n", wordwrap($texto, $ancho, "\n", true));
}
$ancho = $tipo === 'cliente' ? 842 : 595;
$alto = $tipo === 'cliente' ? 595 : 842;
$titulo = $tipo === 'cliente' ? 'DATOS DEL CLIENTE' : ($tipo === 'agencia' ? 'AGENCIA / DESTINO' : 'OBSERVACION DEL PEDIDO');
$campos = $tipo === 'cliente' ? [
    'Nombre del destinatario' => $pedido['nombre_completo'],
    'DNI' => $pedido['documento'],
    'Numero de WhatsApp' => $pedido['whatsapp'],
    'Tipo de retiro' => $pedido['tipo_entrega'],
    'Agencia / Destino' => $pedido['agencia_detalle'],
] : ($tipo === 'agencia' ? ['Agencia / Destino' => $pedido['agencia_detalle']] : ['Observacion' => $pedido['observacion']]);
$stream = "0.05 0.14 0.09 rg 0 " . ($alto - 95) . " $ancho 95 re f\n";
$stream .= "BT /F1 19 Tf 1 1 1 rg 42 " . ($alto - 57) . " Td (" . pdfText($titulo) . ") Tj ET\n";
$y = $alto - 128;
foreach ($campos as $etiqueta => $valor) {
    $stream .= "BT /F1 12 Tf 0.15 0.34 0.22 rg 44 $y Td (" . pdfText($etiqueta) . ") Tj ET\n";
    $y -= 22;
    foreach (pdfWrap($valor, $tipo === 'cliente' ? 102 : 78) as $linea) {
        if ($y < 48) break;
        $stream .= "BT /F1 13 Tf 0.12 0.16 0.13 rg 44 $y Td (" . pdfText($linea) . ") Tj ET\n";
        $y -= 20;
    }
    $y -= 18;
}
$objetos = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $ancho $alto] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>",
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
    '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream",
];
$pdf = "%PDF-1.4\n";
$offsets = [0];
foreach ($objetos as $i => $objeto) {
    $offsets[] = strlen($pdf);
    $pdf .= ($i + 1) . " 0 obj\n" . $objeto . "\nendobj\n";
}
$xref = strlen($pdf);
$pdf .= "xref\n0 6\n0000000000 65535 f \n";
for ($i = 1; $i <= 5; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
$pdf .= "trailer << /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="pedido_' . $id . '_' . $tipo . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
echo $pdf;

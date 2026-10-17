<?php
header('Content-Type: text/html; charset=UTF-8');
$shalomAgencias = [];
$shalomApiUrl = 'https://api.shalom-api.lat/public/agencies';

if (function_exists('curl_init')) {
    $ch = curl_init($shalomApiUrl);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ],
    ]);

    $shalomResponse = curl_exec($ch);
    $shalomHttpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($shalomResponse !== false && $shalomHttpCode >= 200 && $shalomHttpCode < 300) {
        $shalomJson = json_decode($shalomResponse, true);

        if (
            is_array($shalomJson) &&
            ($shalomJson['success'] ?? false) === true &&
            isset($shalomJson['data']) &&
            is_array($shalomJson['data'])
        ) {
            $shalomAgencias = $shalomJson['data'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formulario de Envío con Mapa Leaflet - Shalom</title>
    <link rel="stylesheet" href="estilos.css">
    <!-- 1. Cargar CSS de Leaflet (Librería libre y ligera usada por Shalom) -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        :root {
            --shalom-red: #d90429;
            --shalom-dark: #991b1b;
            --border-color: #cbd5e1;
        }

        body {
            background: #f1f5f9;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 12px 10px;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
        }

        .card-formulario {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            width: min(100%, 760px) !important;
            max-width: calc(100vw - 24px) !important;
            flex: 0 1 800px;
            padding: 16px 16px;
            box-sizing: border-box;
        }
        .card-formulario .form-group { margin-bottom: 12px; }
        .card-formulario .form-control { padding: 9px 11px; font-size: 13px; }

        
        
        
        

        /* REDES SOCIALES VECTORIALES */
        .redes-selector-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-top: 6px;
        }
        .red-item-btn {
            border: 2px solid #e2e8f0;
            background: #fff;
            border-radius: 10px;
            padding: 10px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .red-item-btn:hover { border-color: #94a3b8; }
        .red-item-btn.selected {
            border-color: #ef4444;
            background: #fef2f2;
            box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.2);
        }
        .red-icon-svg { width: 24px; height: 24px; }
        .red-name-lbl { font-size: 12px; font-weight: 700; color: #334155; }

        /* SECCIÓN PASO 2: MAPA LEAFLET Y BUSCADOR INTEGRADO EN EL FORMULARIO */
        .paso2-map-box {
            background: #ffffff;
            border: 2px solid #fecaca;
            border-radius: 12px;
            padding: 14px;
            margin-top: 6px;
        }

        .input-buscador-live {
            width: 100%;
            padding: 11px 14px 11px 36px;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            font-size: 13.5px;
            outline: none;
            box-sizing: border-box;
            background: #f8fafc;
        }
        .input-buscador-live:focus {
            border-color: #dc2626;
            background: #fff;
        }

        .search-icon-pos {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 14px;
            pointer-events: none;
        }

        /* CONTENEDOR DEL MAPA LEAFLET */
        #mapaLeafletDestino {
            width: 100%;
            height: 205px;
            border-radius: 10px;
            margin-top: 10px;
            border: 1.5px solid #e2e8f0;
            z-index: 10;
        }

        /* LISTADO DE SUCURSALES RENDERIZADAS CON COORDENADAS */
        .lista-sucursales-encontradas {
            margin-top: 10px;
            max-height: 180px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 6px;
            padding-right: 4px;
        }
        .sucursal-item-card {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px;
            background: #fff;
            cursor: pointer;
            transition: all 0.15s;
            font-size: 12px;
        }
        .sucursal-item-card:hover {
            border-color: #dc2626;
            background: #fef2f2;
        }
        .sucursal-item-card.activa {
            border-color: #dc2626;
            background: #fef2f2;
            font-weight: 600;
        }

        /* DETALLE DE AGENCIA SELECCIONADA */
        .alert-agencia-seleccionada {
            background: #fef2f2;
            border: 1.5px solid #ef4444;
            color: #991b1b;
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 12.5px;
            margin-top: 10px;
            display: none;
            line-height: 1.4;
        }

        .btn-submit-guardar {
            width: 100%;
            background: #059669;
            color: #fff;
            border: none;
            padding: 14px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 800;
            cursor: pointer;
            margin-top: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-submit-guardar:hover { background: #047857; }
            /* ENCABEZADO SOLICITADO: SOLO TÍTULO Y LOGO A LA DERECHA */
        .header-registro-pedido {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 7px;
            text-align: center;
            margin-bottom: 10px;
            padding-bottom: 9px;
            border-bottom: 2px solid #e2e8f0;
        }
        .titulo-registro-pedido {
            margin: 0;
            font-size: 21px;
            font-weight: 900;
            color: #0b2417;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            line-height: 1.2;
        }
        .logo-box-header {
            flex-shrink: 0;
        }
        .logo-header-img {
            width: 68px;
            height: 68px;
            object-fit: contain;
            border-radius: 50%;
            border: 2.5px solid #d4af37;
            background: #000;
            display: block;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
        }
        .brand-formulario { font-size: 15px; font-weight: 900; color: #0b2417; text-shadow: 1px 2px 3px rgba(212,175,55,.5); letter-spacing: .5px; }
        .brand-formulario-wrap { display: flex; align-items: center; gap: 10px; }
        .btn-enviar-whatsapp { width: 100%; margin-top: 10px; padding: 13px; border: 0; border-radius: 10px; background: #25d366; color: #fff; font-size: 15px; font-weight: 800; cursor: pointer; }
        .form-actions { display: flex; justify-content: center; align-items: center; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
        .form-actions .btn-submit-guardar, .form-actions .btn-enviar-whatsapp { width: auto; flex: 0 0 auto; margin: 0; min-width: 0; padding: 9px 13px; font-size: 13px; display: inline-flex; justify-content: center; align-items: center; gap: 6px; }
        .form-actions svg { width: 16px; height: 16px; fill: currentColor; flex-shrink: 0; }
        html, body { max-width: 100%; overflow-x: hidden; }
        .card-formulario, .paso2-map-box, #mapaLeafletDestino, .form-control { min-width: 0; max-width: 100%; }

        @media (max-width: 600px) {
            body { padding: 8px; align-items: flex-start; min-height: 100dvh; }
            .card-formulario { padding: 12px 11px; border-radius: 12px; width: 100% !important; max-width: calc(100vw - 16px) !important; }
            .titulo-registro-pedido { font-size: clamp(16px, 5vw, 18px); }
            .logo-header-img { width: 52px; height: 52px; }
            .header-registro-pedido { flex-direction: column; text-align: center; gap: 8px; }
            .brand-formulario { font-size: clamp(11px, 3.5vw, 15px); }
            .brand-formulario-wrap { justify-content: center; min-width: 0; }
            .redes-selector-grid { grid-template-columns: repeat(3, 1fr); gap: 6px; }
            .red-name-lbl { font-size: 11px; }
            .paso2-map-box { padding: 7px; }
            #mapaLeafletDestino { height: 175px; }
            .card-formulario .form-group { margin-bottom: 10px; }
            .form-actions { flex-direction: column; align-items: stretch; }
            .form-actions .btn-submit-guardar, .form-actions .btn-enviar-whatsapp { width: 100%; min-height: 44px; }
            .red-item-btn { min-width: 0; padding: 8px 3px; }
            .red-name-lbl { overflow-wrap: anywhere; }
            input, select, textarea { font-size: 16px !important; }
            .lista-sucursales-encontradas { max-height: 35dvh; }
        }
    </style>
</head>
<body>

<div class="card-formulario">
        <!-- ENCABEZADO: TÍTULO "REGISTRO DE PEDIDO" A LA IZQUIERDA Y LOGO A LA DERECHA -->
    <div class="header-registro-pedido">
        <h1 class="titulo-registro-pedido">REGISTRO DE PEDIDO</h1>
        <div class="brand-formulario-wrap"><div class="logo-box-header">
            <img src="uploads/logo.png" alt="Bell's Store Shop" class="logo-header-img" onerror="this.src='https://ui-avatars.com/api/?name=Bells+Store&background=0b2417&color=38e388'">
        </div><span class="brand-formulario">BELL'S STORE SHOP</span></div>
    </div>

    <form id="envioForm" autocomplete="off">
        <!-- 1. WHATSAPP -->
        <div class="form-group">
            <label>Ingrese su número de WhatsApp *</label>
            <input type="tel" name="whatsapp" id="whatsapp" class="form-control" placeholder="9 dígitos" inputmode="numeric" pattern="[0-9]{9}" minlength="9" maxlength="9" title="Ingrese exactamente 9 dígitos" required>
        </div>

        <!-- 2. RED SOCIAL CON ICONOS ORIGINALES -->
        <div class="form-group">
            <label>¿Por qué Red Social nos contactas? *</label>
            <input type="hidden" name="red_social" id="red_social_input" required>
            <div class="redes-selector-grid">
                <!-- TikTok -->
                <div class="red-item-btn" onclick="seleccionarRedSocial('TikTok', this)">
                    <svg class="red-icon-svg" viewBox="0 0 24 24"><path fill="#000000" d="M12.53.02C13.84 0 15.14.01 16.44 0c.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.24 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>
                    <span class="red-name-lbl">TikTok</span>
                </div>
                <!-- Facebook -->
                <div class="red-item-btn" onclick="seleccionarRedSocial('Facebook', this)">
                    <svg class="red-icon-svg" viewBox="0 0 24 24"><path fill="#1877F2" d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                    <span class="red-name-lbl">Facebook</span>
                </div>
                <!-- Instagram -->
                <div class="red-item-btn" onclick="seleccionarRedSocial('Instagram', this)">
                    <svg class="red-icon-svg" viewBox="0 0 24 24"><defs><radialGradient id="rg" r="150%" cx="30%" cy="107%"><stop stop-color="#fdf497" offset="0%"/><stop stop-color="#fd5949" offset="45%"/><stop stop-color="#d6249f" offset="60%"/><stop stop-color="#285AEB" offset="90%"/></radialGradient></defs><path fill="url(#rg)" d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
                    <span class="red-name-lbl">Instagram</span>
                </div>
            </div>
        </div>

        <div class="form-group" id="grupo_nombre_red" style="display: none;">
            <label id="label_nombre_red">Ingrese su nombre de usuario de la red social *</label>
            <input type="text" name="nombre_red_social" id="nombre_red_social" class="form-control" placeholder="Ej: @tuusuario">
        </div>

        <!-- 3. RETIRO EN AGENCIA SHALOM FIJO -->
        <div class="form-group">
            <label>¿Cómo quieres recibir tu pedido? *</label>
            <input type="text" class="form-control" value="Retiro en Agencia Shalom" readonly style="background:#f8fafc; font-weight:700; color:#dc2626; border-color:#fca5a5;">
            <input type="hidden" name="tipo_entrega" value="Retiro en Agencia Shalom">
        </div>

        <!-- 4. CAMPO REQUERIDO: 📍 Busca tu Distrito, Ciudad o Departamento de destino * -->
        <!-- CON MAPA LEAFLET RENDERIZADO DIRECTAMENTE EN PANTALLA SEGÚN LAT/LNG -->
        <div class="form-group">
            <label>📍 Buscar distrito *</label>
            
            <div class="paso2-map-box">
                <!-- Buscador de destino -->
                <div style="position:relative;">
                    <span class="search-icon-pos">🔍</span>
                    <input type="text" id="inputBuscarDestinoPaso2" class="input-buscador-live" placeholder="Digitar el nombre de su distrito" autocomplete="off">
                </div>

                <!-- CONTENEDOR DONDE SE RENDERIZA EL MAPA LEAFLET -->
                <div id="mapaLeafletDestino"></div>

                <!-- LISTADO DE SUCURSALES PINTADAS EN EL MAPA -->
                <div style="font-size:11.5px; font-weight:700; color:#64748b; margin-top:10px;">
                    SUCURSALES DISPONIBLES EN EL MAPA (Haz clic en el mapa o en la lista para elegir):
                </div>
                <div class="lista-sucursales-encontradas" id="listaSucursalesContenedor"></div>

                <!-- CONFIRMACIÓN DE LA AGENCIA SELECCIONADA -->
                <div id="boxAgenciaSeleccionada" class="alert-agencia-seleccionada">
                    <strong>✅ AGENCIA SELECCIONADA PARA TU RETIRO:</strong><br>
                    <span id="txtAgenciaSeleccionada">Ninguna</span>
                </div>
            </div>

            <!-- CAMPOS OCULTOS QUE SE ENVIARÁN A LA BASE DE DATOS -->
            <input type="hidden" name="departamento" id="departamento_val" required>
            <input type="hidden" name="provincia" id="provincia_val" required>
            <input type="hidden" name="distrito" id="distrito_val" required>
            <input type="hidden" name="agencia_detalle" id="agencia_detalle" required>
        </div>

        <!-- 5. DNI / CE -->
        <div class="form-group">
            <label>DNI para recoger *</label>
            <input type="text" name="documento" id="documento" class="form-control" placeholder="DNI de 8 dígitos" inputmode="numeric" pattern="[0-9]{8}" minlength="8" maxlength="8" title="Ingrese exactamente 8 dígitos" required>
        </div>

        <!-- 6. NOMBRE COMPLETO -->
        <div class="form-group">
            <label>Nombre y Apellidos de la persona que recoge *</label>
            <input type="text" name="nombre_completo" id="nombre_completo" class="form-control" placeholder="Nombre Completo" required>
        </div>

        <!-- 7. FECHA SOLO JUEVES Y DOMINGO -->
        <div class="form-group">
            <label>Fecha de Despacho (Solo Jueves y Domingos) *</label>
            <select name="fecha_envio" id="fecha_envio" class="form-control" required>
                <option value="">-- Elige fecha de despacho --</option>
            </select>
        </div>

        <!-- 8. BOTÓN GUARDAR -->
        <div class="form-actions">
            <button type="submit" class="btn-submit-guardar" id="btnGuardar">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7l-4-4zm-5 16a3 3 0 1 1 0-6 3 3 0 0 1 0 6zM6 4h9v5H6V4z"/></svg>
                <span>Guardar</span>
            </button>
            <button type="button" class="btn-enviar-whatsapp" onclick="enviarFormularioWhatsapp()">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12.04 2A9.92 9.92 0 0 0 3.5 17.08L2 22l5.04-1.46A9.99 9.99 0 1 0 12.04 2zm0 18a7.98 7.98 0 0 1-4.08-1.11l-.29-.17-2.99.87.9-2.91-.19-.3a8 8 0 1 1 6.65 3.62zm4.39-5.97c-.24-.12-1.42-.7-1.64-.78-.22-.08-.38-.12-.54.12-.16.24-.62.78-.76.94-.14.16-.28.18-.52.06-.24-.12-1.01-.37-1.92-1.19-.71-.63-1.19-1.41-1.33-1.65-.14-.24-.02-.37.1-.49.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.54-1.3-.74-1.78-.19-.47-.39-.4-.54-.41h-.46c-.16 0-.42.06-.64.3-.22.24-.84.82-.84 2s.86 2.31.98 2.47c.12.16 1.69 2.58 4.09 3.62.57.25 1.02.4 1.37.51.58.18 1.11.15 1.53.09.47-.07 1.42-.58 1.62-1.14.2-.56.2-1.04.14-1.14-.06-.1-.22-.16-.46-.28z"/></svg>
                <span>WhatsApp</span>
            </button>
        </div>
    </form>
</div>

<!-- Leaflet JS (Motor del mapa) -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
// --- DATOS DE SUCURSALES SHALOM OBTENIDOS DESDE LA API ---
const agenciasApiShalom = <?php echo json_encode(
    $shalomAgencias,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
); ?>;

const sucursalesShalom = agenciasApiShalom
    .map(agencia => ({
        dep: String(agencia.departamento ?? '').trim(),
        prov: String(agencia.provincia ?? '').trim(),
        dist: String(agencia.zona ?? agencia.distrito ?? '').trim(),
        sede: String(
            agencia.lugar_over ??
            agencia.nombre ??
            agencia.ter_zona ??
            agencia.zona ??
            'Agencia Shalom'
        ).trim(),
        direccion: String(agencia.direccion ?? '').trim(),
        lat: parseFloat(agencia.latitud),
        lng: parseFloat(agencia.longitud)
    }))
    .filter(sucursal =>
        Number.isFinite(sucursal.lat) &&
        Number.isFinite(sucursal.lng)
    );


// --- PASO 2: INICIALIZAR Y RENDERIZAR MAPA LEAFLET ---
let map = null;
let layerMarcadores = null;

// Icono personalizado rojo estilo Shalom
const redPinIcon = L.icon({
    iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png',
    shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
    iconSize: [25, 41],
    iconAnchor: [12, 41],
    popupAnchor: [1, -34],
    shadowSize: [41, 41]
});

function inicializarPaso2Mapa() {
    // Centro inicial: Lima / Perú (-12.04, -77.03)
    map = L.map('mapaLeafletDestino').setView([-12.0463, -77.0427], 6);

    // Tiles libres de OpenStreetMap
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap | Sucursales Shalom',
        maxZoom: 18
    }).addTo(map);

    layerMarcadores = L.layerGroup().addTo(map);

    // Renderizar inicialmente todas las sucursales
    renderizarSucursalesEnMapa(sucursalesShalom, false);
}

// Renderiza los marcadores con latitud y longitud devueltas
function renderizarSucursalesEnMapa(lista, ajustarZoom = true) {
    layerMarcadores.clearLayers();
    const contenedorLista = document.getElementById('listaSucursalesContenedor');
    contenedorLista.innerHTML = '';

    if (lista.length === 0) {
        contenedorLista.innerHTML = '<div style="padding:10px; color:#64748b; font-size:12px; text-align:center;">No encontramos agencias para este lugar. Escribe otra ciudad o distrito.</div>';
        return;
    }

    const bounds = [];

    lista.forEach((suc, idx) => {
        // 1. Pintar marcador en el mapa con Leaflet usando lat y lng
        const marker = L.marker([suc.lat, suc.lng], { icon: redPinIcon }).addTo(layerMarcadores);
        
        // Popup informativo del marcador
        marker.bindPopup(`
            <div style="font-family:sans-serif; font-size:12px;">
                <strong style="color:#dc2626;">🔴 Shalom: ${suc.dist} — ${suc.sede}</strong><br>
                <span>📍 ${suc.direccion}</span><br>
                <button type="button" onclick="seleccionarSucursal(${idx})" style="margin-top:6px; background:#dc2626; color:#fff; border:none; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:bold; cursor:pointer;">
                    ✓ Seleccionar esta Agencia
                </button>
            </div>
        `);

        marker.on('click', () => {
            seleccionarSucursalDirecto(suc);
        });

        bounds.push([suc.lat, suc.lng]);

        // 2. Pintar la tarjeta correspondiente en la lista
        const card = document.createElement('div');
        card.className = 'sucursal-item-card';
        card.id = `card_suc_${idx}`;
        card.innerHTML = `
            <strong style="color:#dc2626;">🔴 ${suc.dist} — ${suc.sede}</strong>
            <div style="color:#334155; margin-top:2px;">📍 ${suc.direccion}</div>
            <div style="color:#64748b; font-size:11px; margin-top:2px;">${suc.prov} - ${suc.dep}</div>
        `;
        card.onclick = () => {
            map.flyTo([suc.lat, suc.lng], 15);
            marker.openPopup();
            seleccionarSucursalDirecto(suc);
        };
        contenedorLista.appendChild(card);
    });

    // Centrar y ajustar el zoom a las sucursales filtradas
    if (ajustarZoom && bounds.length > 0) {
        map.fitBounds(bounds, { maxZoom: 14, padding: [30, 30] });
    }
}

// Captura de datos al seleccionar una sucursal
function seleccionarSucursalDirecto(suc) {
    document.getElementById('departamento_val').value = suc.dep;
    document.getElementById('provincia_val').value = suc.prov;
    document.getElementById('distrito_val').value = suc.dist;
    
    const textoDetalle = `Shalom - ${suc.dist} (${suc.sede}) | Dirección: ${suc.direccion}`;
    document.getElementById('agencia_detalle').value = textoDetalle;

    const box = document.getElementById('boxAgenciaSeleccionada');
    const txt = document.getElementById('txtAgenciaSeleccionada');
    txt.innerHTML = `<strong>${suc.sede}</strong><br>📍 Dirección exacta: ${suc.direccion} (${suc.dist}, ${suc.prov} - ${suc.dep})`;
    box.style.display = 'block';
}

window.seleccionarSucursal = function(idx) {
    const suc = sucursalesShalom[idx];
    if (suc) seleccionarSucursalDirecto(suc);
};

// BUSCADOR EN VIVO DE DISTRITO / CIUDAD / DEPARTAMENTO
const inputBuscador = document.getElementById('inputBuscarDestinoPaso2');
inputBuscador.addEventListener('input', function() {
    const q = this.value.toLowerCase().trim();
    if (!q) {
        renderizarSucursalesEnMapa(sucursalesShalom, false);
        map.setView([-12.0463, -77.0427], 6);
        return;
    }

    const filtradas = sucursalesShalom.filter(s => 
        s.dist.toLowerCase().includes(q) ||
        s.prov.toLowerCase().includes(q) ||
        s.dep.toLowerCase().includes(q) ||
        s.sede.toLowerCase().includes(q) ||
        s.direccion.toLowerCase().includes(q)
    );

    renderizarSucursalesEnMapa(filtradas, true);
});

// Inicializar el mapa al cargar la ventana
window.addEventListener('load', () => {
    inicializarPaso2Mapa();
});

// --- LÓGICA DE RED SOCIAL ---
function seleccionarRedSocial(nombreRed, el) {
    document.querySelectorAll('.red-item-btn').forEach(btn => btn.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('red_social_input').value = nombreRed;

    const grupoUser = document.getElementById('grupo_nombre_red');
    const inputUser = document.getElementById('nombre_red_social');
    const labelUser = document.getElementById('label_nombre_red');

    grupoUser.style.display = 'block';
    labelUser.innerText = 'Ingrese su nombre de usuario de la red social *';
    inputUser.placeholder = `Tu @usuario en ${nombreRed}`;
    inputUser.required = true;
    inputUser.focus();
}

// --- FECHAS DE DESPACHO (SOLO JUEVES Y DOMINGOS) ---
function cargarFechasSoloJuevesYDomingo() {
    const sel = document.getElementById('fecha_envio');
    const meses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    let hoy = new Date();
    let iter = new Date(hoy);
    let encontrados = 0;

    while (encontrados < 2) {
        let diaNum = iter.getDay(); // 0 = Domingo, 4 = Jueves
        if (diaNum === 4 || diaNum === 0) {
            let anio = iter.getFullYear();
            let mes = String(iter.getMonth() + 1).padStart(2, '0');
            let dia = String(iter.getDate()).padStart(2, '0');
            let iso = `${anio}-${mes}-${dia}`;
            let nombreDia = (diaNum === 4) ? 'Jueves' : 'Domingo';
            let texto = `${nombreDia} ${dia} de ${meses[iter.getMonth()]} (${anio})`;

            let opt = document.createElement('option');
            opt.value = iso;
            opt.textContent = `Despacho: ${texto}`;
            sel.appendChild(opt);
            encontrados++;
        }
        iter.setDate(iter.getDate() + 1);
    }
}
cargarFechasSoloJuevesYDomingo();

function enviarFormularioWhatsapp() {
    const form = document.getElementById('envioForm');
    if (!form.reportValidity()) return;
    if (!document.getElementById('red_social_input').value) { alert('Selecciona la red social.'); return; }
    if (!document.getElementById('agencia_detalle').value) { alert('Selecciona una agencia.'); return; }
    const data = new FormData(form);
    // Escapes Unicode: los iconos se conservan aunque el servidor use otra codificación.
    const iconoRed = {
        TikTok: '\u{1F3B5}', Facebook: '\u{1F4D8}', Instagram: '\u{1F4F8}'
    };
    const campos = [
        ['\u{1F7E2}\u{1F4DE}', 'WhatsApp', 'whatsapp'],
        [iconoRed[data.get('red_social')] || '\u{1F310}', 'Red social', 'red_social'],
        ['\u{1F464}', 'Usuario en red social', 'nombre_red_social'],
        ['\u{1F4E6}', 'Tipo de retiro', 'tipo_entrega'],
        ['\u{1F5FA}\uFE0F', 'Departamento', 'departamento'],
        ['\u{1F4CD}', 'Provincia', 'provincia'],
        ['\u{1F3D8}\uFE0F', 'Distrito', 'distrito'],
        ['\u{1F3E2}', 'Agencia / destino', 'agencia_detalle'],
        ['\u{1F4C4}', 'DNI', 'documento'],
        ['\u{1F9D1}', 'Nombre y apellidos', 'nombre_completo'],
        ['\u{1F4C5}', 'Fecha de despacho', 'fecha_envio']
    ];
    const mensaje = "*BELL'S STORE SHOP - REGISTRO DE PEDIDO*\n" +
        campos.map(([icono, titulo, clave]) => icono + ' *' + titulo + ':* ' + (data.get(clave) || '-')).join('\n');
    window.open('https://api.whatsapp.com/send?text=' + encodeURIComponent(mensaje), '_blank', 'noopener');
}

// --- GUARDAR EN BASE DE DATOS MYSQL ---
document.getElementById('envioForm').addEventListener('submit', function(e) {
    e.preventDefault();

    if (!document.getElementById('red_social_input').value) {
        alert("Por favor selecciona por qué Red Social nos contactas.");
        return;
    }

    if (!document.getElementById('agencia_detalle').value) {
        alert("Por favor selecciona una agencia Shalom en el mapa.");
        inputBuscador.focus();
        return;
    }
    if (!/^[0-9]{9}$/.test(document.getElementById('whatsapp').value) || !/^[0-9]{8}$/.test(document.getElementById('documento').value)) {
        alert('Ingrese un WhatsApp de 9 dígitos y un DNI de 8 dígitos.');
        return;
    }

    const btn = document.getElementById('btnGuardar');
    btn.disabled = true;
    btn.innerHTML = '<span>⏳ Guardando...</span>';

    const formData = new FormData(this);

    fetch('guardar_envio.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(`✅ ¡Envío guardado con éxito en tu base de datos!\nNúmero de Pedido: #${data.id}`);
            location.reload();
        } else {
            alert('Error al guardar: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = '<span>💾 Guardar</span>';
        }
    })
    .catch(() => {
        alert('Error de conexión con el servidor.');
        btn.disabled = false;
        btn.innerHTML = '<span>💾 Guardar</span>';
    });
});
</script>
</body>
</html>

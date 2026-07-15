<?php

declare(strict_types=1);

// Habilitar reporte de errores
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Inicializar sesión
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/controladores/SyncController.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'index';
$controller = new SyncController();

// Lógica de autorización
$sesionActiva = isset($_SESSION['migrador_user']);

if (!$sesionActiva && $action !== 'login') {
    if ($action === 'index') {
        // Cargar la vista del login si no hay sesión
        require_once __DIR__ . '/vistas/login.php';
        exit;
    } else {
        // Para peticiones AJAX, retornar JSON de error de autorización
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Sesión expirada o no autorizada.']);
        exit;
    }
}

try {
    switch ($action) {
        case 'index':
            $controller->index();
            break;
        case 'login':
            $controller->login();
            break;
        case 'logout':
            $controller->logout();
            break;
        case 'get_clientes':
            $controller->getClientesMap();
            break;
        case 'iniciar_job':
            $controller->iniciarJob();
            break;
        case 'sincronizar_tabla':
            $controller->sincronizarTabla();
            break;
        case 'completar_job':
            $controller->completarJob();
            break;
        case 'fallar_job':
            $controller->fallarJob();
            break;
        case 'get_logs':
            $controller->getLogs();
            break;
        case 'sincronizar_catalogo':
            $controller->sincronizarCatalogo();
            break;
        default:
            header("HTTP/1.0 404 Not Found");
            echo "Acción no encontrada.";
            break;
    }
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Error en el enrutador: ' . $e->getMessage()
    ]);
}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SNU Quality - Sincronizador de Bases de Datos</title>
    
    <!-- Google Fonts: Outfit -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        :root {
            --bg-dark: #0a0c10;
            --bg-card: rgba(18, 22, 33, 0.7);
            --border-glass: rgba(255, 255, 255, 0.08);
            --accent-primary: #6366f1; /* Indigo */
            --accent-secondary: #06b6d4; /* Cian */
            --accent-violet: #8b5cf6; /* Violeta */
            --text-main: #f3f4f6;
            --text-muted: #9ca3af;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
        }

        body {
            background-color: var(--bg-dark);
            color: var(--text-main);
            font-family: 'Outfit', sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
            background-image: 
                radial-gradient(circle at 10% 20%, rgba(99, 102, 241, 0.05) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(6, 182, 212, 0.05) 0%, transparent 40%);
        }

        /* Glassmorphism Cards */
        .glass-card {
            background: var(--bg-card);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--border-glass);
            border-radius: 16px;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .glass-card:hover {
            border-color: rgba(99, 102, 241, 0.3);
            box-shadow: 0 12px 40px 0 rgba(99, 102, 241, 0.1);
            transform: translateY(-2px);
        }

        /* Navbar Style */
        .navbar-premium {
            background: rgba(10, 12, 16, 0.8);
            backdrop-filter: blur(8px);
            border-bottom: 1px solid var(--border-glass);
            padding: 15px 0;
        }

        .navbar-brand-premium {
            font-weight: 700;
            font-size: 1.5rem;
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* KPI Cards */
        .kpi-card {
            position: relative;
            overflow: hidden;
            padding: 24px;
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--accent-primary);
        }

        .kpi-card.kpi-success::before { background: var(--success); }
        .kpi-card.kpi-danger::before { background: var(--danger); }
        .kpi-card.kpi-info::before { background: var(--accent-secondary); }

        .kpi-val {
            font-size: 2.25rem;
            font-weight: 700;
            line-height: 1;
            margin-top: 8px;
            background: linear-gradient(135deg, #ffffff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Custom Badges */
        .badge-custom {
            padding: 6px 12px;
            border-radius: 30px;
            font-weight: 500;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid transparent;
        }

        .badge-ok {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border-color: rgba(16, 185, 129, 0.2);
        }

        .badge-err {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border-color: rgba(239, 68, 68, 0.2);
        }

        .badge-warning-custom {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border-color: rgba(245, 158, 11, 0.2);
        }

        .badge-active {
            background: rgba(99, 102, 241, 0.1);
            color: var(--accent-primary);
            border-color: rgba(99, 102, 241, 0.3);
            animation: pulse-active 2s infinite;
        }

        @keyframes pulse-active {
            0% { box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(99, 102, 241, 0); }
            100% { box-shadow: 0 0 0 0 rgba(99, 102, 241, 0); }
        }

        /* Buttons Style */
        .btn-action {
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-violet));
            color: white;
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-action:hover {
            filter: brightness(1.1);
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4);
            transform: translateY(-1px);
            color: white;
        }

        .btn-action:disabled {
            background: #374151;
            color: #9ca3af;
            box-shadow: none;
            transform: none;
            filter: none;
        }

        .btn-secondary-custom {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-glass);
            color: var(--text-main);
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-secondary-custom:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-color: rgba(255, 255, 255, 0.2);
        }

        /* Custom Table */
        .table-premium {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
        }

        .table-premium th {
            color: var(--text-muted);
            font-weight: 500;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            padding: 12px 16px;
            border: none;
        }

        .table-premium td {
            background: rgba(255, 255, 255, 0.015);
            padding: 16px;
            vertical-align: middle;
            border-top: 1px solid var(--border-glass);
            border-bottom: 1px solid var(--border-glass);
        }

        .table-premium td:first-child {
            border-left: 1px solid var(--border-glass);
            border-top-left-radius: 12px;
            border-bottom-left-radius: 12px;
        }

        .table-premium td:last-child {
            border-right: 1px solid var(--border-glass);
            border-top-right-radius: 12px;
            border-bottom-right-radius: 12px;
        }

        .table-premium tr:hover td {
            background: rgba(255, 255, 255, 0.03);
            border-color: rgba(99, 102, 241, 0.2);
        }

        /* Console Terminal Box */
        .terminal-container {
            background: #05070a;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            overflow: hidden;
            font-family: 'Courier New', Courier, monospace;
        }

        .terminal-header {
            background: #111827;
            padding: 10px 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .terminal-dots {
            display: flex;
            gap: 6px;
        }

        .terminal-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        .dot-red { background: var(--danger); }
        .dot-yellow { background: var(--warning); }
        .dot-green { background: var(--success); }

        .terminal-body {
            height: 350px;
            overflow-y: auto;
            padding: 16px;
            color: #10b981; /* Verde terminal */
            font-size: 0.85rem;
            line-height: 1.5;
        }

        .terminal-log-row {
            margin-bottom: 6px;
            white-space: pre-wrap;
        }

        .log-time { color: var(--text-muted); margin-right: 8px; }
        .log-level-info { color: var(--accent-secondary); }
        .log-level-warning { color: var(--warning); }
        .log-level-error { color: var(--danger); font-weight: bold; }

        /* Progress Bar Premium */
        .progress-premium {
            background-color: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            height: 10px;
            overflow: hidden;
            position: relative;
        }

        .progress-bar-premium {
            background: linear-gradient(90deg, var(--accent-primary), var(--accent-secondary));
            height: 100%;
            border-radius: 10px;
            transition: width 0.4s ease;
        }

        /* Modal Styles */
        .modal-content-premium {
            background: #0f131a;
            border: 1px solid var(--border-glass);
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.5);
            color: var(--text-main);
        }

        .modal-header-premium {
            border-bottom: 1px solid var(--border-glass);
            padding: 20px;
        }

        .modal-footer-premium {
            border-top: 1px solid var(--border-glass);
            padding: 20px;
        }
        .text-muted{
            --bs-text-opacity: 1;
            color: rgb(241 241 241 / 75%) !important;
        }
    </style>
</head>
<body>

    <!-- Navegación Premium -->
    <nav class="navbar navbar-premium sticky-top">
        <div class="container">
            <a class="navbar-brand navbar-brand-premium" href="#">
                <i class="bi bi-arrow-repeat rotate-icon" style="font-size: 1.7rem;"></i>
                <span>SNU QUALITY MIGRATOR</span>
            </a>
            <div class="d-flex align-items-center gap-4">
                <div class="d-flex align-items-center gap-2">
                    <span class="d-inline-block rounded-circle bg-success" style="width: 8px; height: 8px; animation: pulse-active 1.5s infinite;"></span>
                    <span style="font-size: 0.85rem; font-weight: 500; color: var(--text-muted);">Servidor Destino Local</span>
                </div>
                <?php if (isset($_SESSION['migrador_user'])): ?>
                <div class="d-flex align-items-center gap-3 border-start ps-4 border-secondary border-opacity-25">
                    <span style="font-size: 0.85rem; font-weight: 500; color: var(--text-muted);">
                        <i class="bi bi-person-circle me-1"></i> <?php echo htmlspecialchars($_SESSION['migrador_user']['nombre']); ?>
                    </span>
                    <a href="index.php?action=logout" class="btn btn-outline-danger btn-sm px-3 py-1" style="font-size: 0.75rem; border-radius: 8px;" title="Cerrar Sesión">
                        <i class="bi bi-box-arrow-right"></i> Salir
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Contenido Principal -->
    <div class="container my-5">
        
        <!-- Tarjetas de Métricas / KPIs -->
        <div class="row g-4 mb-5">
            <div class="col-md-3">
                <div class="glass-card kpi-card kpi-info">
                    <div class="text-muted text-uppercase fw-semibold" style="font-size: 0.75rem;">Total Clientes</div>
                    <div class="kpi-val" id="kpi-total">0</div>
                    <i class="bi bi-people position-absolute text-muted opacity-25" style="font-size: 3rem; right: 20px; bottom: 10px;"></i>
                </div>
            </div>
            <div class="col-md-3">
                <div class="glass-card kpi-card kpi-success">
                    <div class="text-muted text-uppercase fw-semibold" style="font-size: 0.75rem;">Listos para Sincronizar</div>
                    <div class="kpi-val" id="kpi-ready">0</div>
                    <i class="bi bi-check-circle position-absolute text-success opacity-25" style="font-size: 3rem; right: 20px; bottom: 10px;"></i>
                </div>
            </div>
            <div class="col-md-3">
                <div class="glass-card kpi-card kpi-danger">
                    <div class="text-muted text-uppercase fw-semibold" style="font-size: 0.75rem;">Con Errores</div>
                    <div class="kpi-val" id="kpi-errors">0</div>
                    <i class="bi bi-exclamation-triangle position-absolute text-danger opacity-25" style="font-size: 3rem; right: 20px; bottom: 10px;"></i>
                </div>
            </div>
            <div class="col-md-3">
                <div class="glass-card kpi-card">
                    <div class="text-muted text-uppercase fw-semibold" style="font-size: 0.75rem;">Completados</div>
                    <div class="kpi-val" id="kpi-completed">0</div>
                    <i class="bi bi-cloud-check position-absolute text-muted opacity-25" style="font-size: 3rem; right: 20px; bottom: 10px;"></i>
                </div>
            </div>
        </div>

        <!-- Panel de Sincronización Activa (Hidden por defecto) -->
        <div class="glass-card p-4 mb-5 d-none" id="active-sync-panel">
            <h5 class="mb-4 d-flex align-items-center gap-2">
                <i class="bi bi-activity text-info"></i>
                <span>Sincronizando: <strong id="syncing-client-name" class="text-info">Empresa A</strong></span>
            </h5>
            
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Progreso General (Tablas)</span>
                            <span class="fw-semibold text-info" id="sync-general-pct">0%</span>
                        </div>
                        <div class="progress-premium">
                            <div class="progress-bar-premium" id="sync-general-progress" style="width: 0%;"></div>
                        </div>
                        <div class="text-muted mt-1" style="font-size: 0.85rem;" id="sync-general-details">0 de 0 tablas completadas</div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted" id="sync-table-name">Procesando tabla...</span>
                            <span class="fw-semibold text-cyan" id="sync-table-pct">0%</span>
                        </div>
                        <div class="progress-premium">
                            <div class="progress-bar-premium" id="sync-table-progress" style="width: 0%; background: linear-gradient(90deg, var(--accent-secondary), #34d399);"></div>
                        </div>
                        <div class="text-muted mt-1" style="font-size: 0.85rem;" id="sync-table-details">0 de 0 registros migrados</div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="terminal-container">
                        <div class="terminal-header">
                            <div class="terminal-dots">
                                <div class="terminal-dot dot-red"></div>
                                <div class="terminal-dot dot-yellow"></div>
                                <div class="terminal-dot dot-green"></div>
                            </div>
                            <span class="text-muted" style="font-size: 0.75rem;">Consola de Sincronización</span>
                        </div>
                        <div class="terminal-body" id="sync-live-terminal">
                            <div class="terminal-log-row">[SISTEMA] Listo para iniciar la sincronización...</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabla de Clientes del Sistema -->
        <div class="glass-card p-4">
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                <h5 class="mb-0 fw-semibold">Estatus de Clientes y Tenant Schemas</h5>
                <div class="d-flex gap-2">
                    <button class="btn btn-action btn-sm" id="btn-sync-catalog" title="Sincroniza las tablas principales de Clientes y Esquemas desde el Origen">
                        <i class="bi bi-cloud-arrow-down-fill"></i> Sincronizar Catálogo
                    </button>
                    <button class="btn btn-secondary-custom btn-sm" id="btn-refresh-map">
                        <i class="bi bi-arrow-clockwise"></i> Actualizar Listado
                    </button>
                </div>
            </div>

            <!-- Buscador y Filtros -->
            <div class="row g-3 mb-4 align-items-center">
                <div class="col-md-6 col-sm-12">
                    <div class="input-group-premium" style="background: rgba(0, 0, 0, 0.25); border: 1px solid var(--border-glass); border-radius: 12px; display: flex; align-items: center; padding: 2px 14px; max-width: 450px;">
                        <i class="bi bi-search" style="color: var(--text-muted); margin-right: 12px; font-size: 1.1rem;"></i>
                        <input type="text" id="search-client" class="form-control-premium" placeholder="Buscar cliente por nombre o esquema..." style="background: transparent; border: none; color: var(--text-main); box-shadow: none; padding: 10px 0; font-size: 0.95rem; width: 100%;">
                    </div>
                </div>
                <div class="col-md-6 col-sm-12 text-md-end text-start">
                    <button class="btn btn-outline-danger btn-sm px-3 py-2" id="btn-clear-filters" style="border-radius: 10px; font-weight: 500; font-size: 0.85rem; display: none;">
                        <i class="bi bi-x-circle-fill me-1"></i> Limpiar Filtros
                    </button>
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="table-premium">
                    <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>Esquema</th>
                            <th>Estado de Validación</th>
                            <th>Última Sincronización</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="clientes-table-body">
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted">
                                <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                                Cargando información de clientes...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Terminal de Logs Completos -->
    <div class="modal fade" id="logsModal" tabindex="-1" aria-labelledby="logsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content modal-content-premium">
                <div class="modal-header modal-header-premium">
                    <h5 class="modal-title d-flex align-items-center gap-2" id="logsModalLabel">
                        <i class="bi bi-terminal text-info"></i>
                        <span>Bitácora de Sincronización - <span id="modal-client-name" class="text-info">Empresa A</span></span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="terminal-container">
                        <div class="terminal-header">
                            <div class="terminal-dots">
                                <div class="terminal-dot dot-red"></div>
                                <div class="terminal-dot dot-yellow"></div>
                                <div class="terminal-dot dot-green"></div>
                            </div>
                            <span class="text-muted" style="font-size: 0.75rem;" id="modal-schema-name">esquema: empresa_a</span>
                        </div>
                        <div class="terminal-body" id="modal-logs-body" style="height: 400px; color: #e5e7eb;">
                            <div class="text-center py-5 text-muted">
                                <div class="spinner-border spinner-border-sm text-info me-2"></div> Cargando bitácora de eventos...
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer modal-footer-premium">
                    <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Cerrar Consola</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS Bundle via CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Frontend Core JS -->
    <script>
        // Guardar la lista completa de clientes en memoria para búsqueda instantánea
        let todosLosClientes = [];

        document.addEventListener('DOMContentLoaded', function() {
            // Leer filtro guardado en localStorage
            const savedSearch = localStorage.getItem('migrador_search') || '';
            const searchInput = document.getElementById('search-client');
            searchInput.value = savedSearch;

            loadClientesMap();

            // Event Listeners
            document.getElementById('btn-refresh-map').addEventListener('click', loadClientesMap);
            document.getElementById('btn-sync-catalog').addEventListener('click', iniciarSincronizacionCatalogo);
            
            // Evento input para buscar en tiempo real
            searchInput.addEventListener('input', filtrarYRenderizar);
            
            // Botón de limpiar filtros
            document.getElementById('btn-clear-filters').addEventListener('click', function() {
                searchInput.value = '';
                filtrarYRenderizar();
            });
        });

        // Cargar mapa de clientes
        function loadClientesMap() {
            const tbody = document.getElementById('clientes-table-body');
            
            fetch('index.php?action=get_clientes')
                .then(response => response.json())
                .then(res => {
                    if (!res.success) {
                        tbody.innerHTML = `<tr><td colspan="5" class="text-center text-danger py-4">Error al cargar clientes: ${res.error}</td></tr>`;
                        return;
                    }

                    todosLosClientes = res.data;
                    filtrarYRenderizar();
                    renderKPIs(res.data);
                })
                .catch(err => {
                    tbody.innerHTML = `<tr><td colspan="5" class="text-center text-danger py-4">Error de conexión: ${err}</td></tr>`;
                });
        }

        // Filtrar clientes basándonos en el buscador y renderizar
        function filtrarYRenderizar() {
            const searchVal = document.getElementById('search-client').value.trim().toLowerCase();
            const btnClear = document.getElementById('btn-clear-filters');
            
            let clientesFiltrados = todosLosClientes;
            
            if (searchVal !== '') {
                localStorage.setItem('migrador_search', searchVal);
                btnClear.style.display = 'inline-block';
                
                clientesFiltrados = todosLosClientes.filter(c => {
                    const nombreMatch = (c.nombre || '').toLowerCase().includes(searchVal);
                    const schemaMatch = (c.schema || '').toLowerCase().includes(searchVal);
                    const dirMatch = (c.dir || '').toLowerCase().includes(searchVal);
                    return nombreMatch || schemaMatch || dirMatch;
                });
            } else {
                localStorage.removeItem('migrador_search');
                btnClear.style.display = 'none';
            }
            
            renderTable(clientesFiltrados);
        }

        // Renderizar la tabla de clientes
        function renderTable(clientes) {
            const tbody = document.getElementById('clientes-table-body');
            tbody.innerHTML = '';

            if (clientes.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">No se encontraron clientes en el sistema.</td></tr>';
                return;
            }

            clientes.forEach(c => {
                const tr = document.createElement('tr');
                
                // Cliente Column
                const tdCliente = document.createElement('td');
                tdCliente.innerHTML = `<span class="fw-semibold text-white">${c.nombre}</span><br><small class="text-muted">Carpeta: ${c.dir}</small>`;
                tr.appendChild(tdCliente);

                // Esquema Column
                const tdSchema = document.createElement('td');
                tdSchema.innerHTML = c.schema ? `<code class="text-cyan">${c.schema}</code>` : `<span class="text-muted">—</span>`;
                tr.appendChild(tdSchema);

                // Estado de Validación Column
                const tdEstado = document.createElement('td');
                let badgeHtml = '';
                let canSync = false;

                switch (c.status) {
                    case 'ok':
                        badgeHtml = '<span class="badge-custom badge-ok"><i class="bi bi-check-circle-fill"></i> Listo</span>';
                        canSync = true;
                        break;
                    case 'error_cliente_no_existe':
                        badgeHtml = '<span class="badge-custom badge-err" title="Cliente no existe en destino"><i class="bi bi-x-circle-fill"></i> Falta Cliente en Destino</span>';
                        break;
                    case 'error_schema_no_registrado':
                        badgeHtml = '<span class="badge-custom badge-err" title="Esquema no registrado en destino"><i class="bi bi-x-circle-fill"></i> Esquema no Registrado</span>';
                        break;
                    case 'error_db_fisica_origen':
                        badgeHtml = '<span class="badge-custom badge-err" title="Base de datos física no existe en origen"><i class="bi bi-database-fill-exclamation"></i> DB Física Origen Faltante</span>';
                        break;
                    case 'error_db_fisica_destino':
                        badgeHtml = '<span class="badge-custom badge-warning-custom" title="Base de datos física no existe en destino (Se creará automáticamente al iniciar)"><i class="bi bi-database-fill-exclamation"></i> Listo (Creará DB)</span>';
                        canSync = true;
                        break;
                    default:
                        badgeHtml = `<span class="badge-custom badge-warning-custom">${c.status}</span>`;
                }

                tdEstado.innerHTML = badgeHtml;
                tr.appendChild(tdEstado);

                // Último Job Column
                const tdUltimo = document.createElement('td');
                if (c.ultimo_job) {
                    let jobBadge = '';
                    switch (c.ultimo_job.estado) {
                        case 'completado':
                            jobBadge = `<span class="text-success"><i class="bi bi-cloud-check-fill"></i> Completado</span>`;
                            break;
                        case 'fallido':
                            jobBadge = `<span class="text-danger" title="${c.ultimo_job.error_mensaje || ''}"><i class="bi bi-cloud-slash-fill"></i> Fallido</span>`;
                            break;
                        case 'en_progreso':
                            jobBadge = `<span class="badge-custom badge-active"><i class="bi bi-arrow-repeat"></i> Activo</span>`;
                            break;
                        default:
                            jobBadge = `<span class="text-muted">${c.ultimo_job.estado}</span>`;
                    }
                    
                    const pct = c.ultimo_job.total_tablas > 0 
                        ? Math.round((c.ultimo_job.tablas_completadas / c.ultimo_job.total_tablas) * 100) 
                        : 0;
                    
                    tdUltimo.innerHTML = `
                        <div class="d-flex flex-column">
                            <div>${jobBadge} <span class="text-muted" style="font-size: 0.8rem;">(${pct}%)</span></div>
                            <small class="text-muted" style="font-size: 0.75rem;">${c.ultimo_job.fecha_fin || c.ultimo_job.fecha_inicio}</small>
                        </div>
                    `;
                } else {
                    tdUltimo.innerHTML = '<span class="text-muted" style="font-size: 0.85rem;">Nunca sincronizado</span>';
                }
                tr.appendChild(tdUltimo);

                // Acciones Column
                const tdAcciones = document.createElement('td');
                tdAcciones.className = 'text-end';
                
                const btnSync = document.createElement('button');
                btnSync.className = 'btn-action btn btn-sm me-2';
                btnSync.innerHTML = '<i class="bi bi-play-fill"></i> Sincronizar';
                btnSync.disabled = !canSync;
                btnSync.onclick = () => iniciarProcesoSincronizacion(c);

                const btnLogs = document.createElement('button');
                btnLogs.className = 'btn-secondary-custom btn btn-sm';
                btnLogs.innerHTML = '<i class="bi bi-terminal"></i>';
                btnLogs.title = 'Ver Logs';
                btnLogs.onclick = () => verLogsCliente(c);

                tdAcciones.appendChild(btnSync);
                tdAcciones.appendChild(btnLogs);
                tr.appendChild(tdAcciones);

                tbody.appendChild(tr);
            });
        }

        // Renderizar KPIs
        function renderKPIs(clientes) {
            document.getElementById('kpi-total').innerText = clientes.length;
            
            const ready = clientes.filter(c => c.status === 'ok').length;
            document.getElementById('kpi-ready').innerText = ready;

            const errors = clientes.filter(c => c.status !== 'ok').length;
            document.getElementById('kpi-errors').innerText = errors;

            const completed = clientes.filter(c => c.ultimo_job && c.ultimo_job.estado === 'completado').length;
            document.getElementById('kpi-completed').innerText = completed;
        }

        // ==========================================
        // FLUJO DE MIGRACIÓN/SINCRONIZACIÓN ASÍNCRONA
        // ==========================================
        let terminalLogs = [];

        function appendTerminal(msg, level = 'info') {
            const term = document.getElementById('sync-live-terminal');
            const row = document.createElement('div');
            row.className = 'terminal-log-row';
            
            const time = new Date().toLocaleTimeString();
            let levelClass = `log-level-${level}`;
            
            row.innerHTML = `<span class="log-time">[${time}]</span><span class="${levelClass}">[${level.toUpperCase()}]</span> ${msg}`;
            term.appendChild(row);
            term.scrollTop = term.scrollHeight;
        }

        function iniciarProcesoSincronizacion(cliente) {
            // Deshabilitar todos los botones de la tabla
            document.querySelectorAll('.btn-action, .btn-secondary-custom, #btn-refresh-map, #btn-sync-catalog').forEach(b => b.disabled = true);
            
            // Limpiar terminal
            const term = document.getElementById('sync-live-terminal');
            term.innerHTML = '';
            
            // Configurar panel de progreso
            document.getElementById('syncing-client-name').innerText = cliente.nombre;
            document.getElementById('active-sync-panel').classList.remove('d-none');
            
            // Hacer scroll hasta el panel de progreso
            document.getElementById('active-sync-panel').scrollIntoView({ behavior: 'smooth' });

            appendTerminal(`Iniciando sincronización para ${cliente.nombre}...`, 'info');
            appendTerminal(`Validando existencia y permisos de base de datos destino (\`${cliente.schema}\`)...`, 'info');

            // 1. Iniciar Job
            fetch(`index.php?action=iniciar_job&cliente_id=${cliente.id}`)
                .then(response => response.json())
                .then(async res => {
                    if (!res.success) {
                        throw new Error(res.error || 'Error desconocido al inicializar el job.');
                    }

                    const jobId = res.job_id;
                    const tablas = res.tablas;
                    
                    appendTerminal(`Base de datos de destino verificada y lista para migración.`, 'info');
                    appendTerminal(`Job de sincronización #${jobId} creado con éxito.`, 'info');
                    appendTerminal(`Detectadas ${tablas.length} tablas para migración.`, 'info');

                    // Inicializar progreso general
                    updateProgressBar('sync-general', 0, tablas.length, `0 de ${tablas.length} tablas completadas`);

                    // Procesar tablas una por una secuencialmente
                    let tablasCompletadas = 0;
                    let tablasConErrores = [];
                    
                    for (let i = 0; i < tablas.length; i++) {
                        const tabla = tablas[i];
                        appendTerminal(`Iniciando procesamiento de tabla: \`${tabla.nombre}\` (${tabla.registros} registros)...`, 'info');
                        
                        try {
                            await sincronizarTablaPorLotes(jobId, tabla.nombre, tabla.registros);
                            tablasCompletadas++;
                        } catch (err) {
                            tablasCompletadas++; // Avanzar contador general para continuar
                            tablasConErrores.push({ tabla: tabla.nombre, error: err.message });
                            appendTerminal(`[ERROR] Falló la tabla \`${tabla.nombre}\`: ${err.message}`, 'error');
                        }

                        updateProgressBar(
                            'sync-general', 
                            tablasCompletadas, 
                            tablas.length, 
                            `${tablasCompletadas} de ${tablas.length} tablas completadas`
                        );
                    }

                    // Finalizar el Job según los resultados de las tablas
                    if (tablasConErrores.length > 0) {
                        appendTerminal(`[ADVERTENCIA] Sincronización finalizada. ${tablasConErrores.length} de ${tablas.length} tablas fallaron y deberán revisarse.`, 'error');
                        
                        // Generar reporte consolidado de errores para el Job
                        let resumenErrores = `Finalizado con errores en ${tablasConErrores.length} tablas:\n`;
                        tablasConErrores.forEach(item => {
                            resumenErrores += `- ${item.tabla}: ${item.error}\n`;
                        });
                        
                        await marcarJobComoFallido(jobId, resumenErrores);
                    } else {
                        appendTerminal("Todas las tablas procesadas correctamente. Finalizando el Job...", 'info');
                        await marcarJobComoCompletado(jobId);
                    }
                    finalizarFlujoUI();
                })
                .catch(err => {
                    appendTerminal(`ERROR de orquestación: ${err.message}`, 'error');
                    finalizarFlujoUI();
                });
        }

        // Sincronizar una tabla específica por lotes de forma recursiva/asíncrona
        function sincronizarTablaPorLotes(jobId, tablaNombre, totalRegistros) {
            return new Promise((resolve, reject) => {
                let offset = 0;
                
                function procesarLote() {
                    updateProgressBar(
                        'sync-table', 
                        offset, 
                        totalRegistros, 
                        `Procesados ${offset} de ${totalRegistros} registros`
                    );
                    document.getElementById('sync-table-name').innerText = `Migrando: ${tablaNombre}`;

                    fetch(`index.php?action=sincronizar_tabla&job_id=${jobId}&tabla=${tablaNombre}&offset=${offset}`)
                        .then(response => response.json())
                        .then(res => {
                            if (!res.success) {
                                reject(new Error(res.error || 'Error desconocido al migrar lote.'));
                                return;
                            }

                            offset = res.registros_migrados;
                            const totalReal = res.total_registros;

                            if (res.completado) {
                                updateProgressBar(
                                    'sync-table', 
                                    totalReal, 
                                    totalReal, 
                                    `Sincronizados ${totalReal} registros`
                                );
                                appendTerminal(`Tabla \`${tablaNombre}\` migrada con éxito (${totalReal} registros).`, 'info');
                                resolve();
                            } else {
                                // Siguiente lote
                                setTimeout(procesarLote, 50); // micro-delay para aliviar la UI
                            }
                        })
                        .catch(err => {
                            reject(err);
                        });
                }

                procesarLote();
            });
        }

        // Enviar evento de completar Job
        function marcarJobComoCompletado(jobId) {
            return fetch(`index.php?action=completar_job&job_id=${jobId}`)
                .then(response => response.json())
                .then(res => {
                    if (res.success) {
                        appendTerminal("¡Sincronización del cliente finalizada con éxito!", 'info');
                    } else {
                        appendTerminal(`Advertencia al cerrar el job: ${res.error}`, 'warning');
                    }
                });
        }

        // Enviar evento de fallo del Job
        function marcarJobComoFallido(jobId, mensaje) {
            const formData = new FormData();
            formData.append('error_mensaje', mensaje);

            return fetch(`index.php?action=fallar_job&job_id=${jobId}`, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(res => {
                appendTerminal("Job marcado como fallido en el servidor de control.", 'error');
            });
        }

        // Helper para actualizar barras de progreso
        function updateProgressBar(idPrefix, current, total, textDetails) {
            const pct = total > 0 ? Math.round((current / total) * 100) : 0;
            document.getElementById(`${idPrefix}-pct`).innerText = `${pct}%`;
            document.getElementById(`${idPrefix}-progress`).style.width = `${pct}%`;
            document.getElementById(`${idPrefix}-details`).innerText = textDetails;
        }

        // Restaurar botones de la UI al terminar
        function finalizarFlujoUI() {
            document.querySelectorAll('.btn-action, .btn-secondary-custom, #btn-refresh-map, #btn-sync-catalog').forEach(b => b.disabled = false);
            // Recargar mapa de clientes para ver estados actualizados
            loadClientesMap();
        }

        // Sincronizar las tablas clientes y squemas (Catálogo principal)
        function iniciarSincronizacionCatalogo() {
            const btnSync = document.getElementById('btn-sync-catalog');
            const btnRefresh = document.getElementById('btn-refresh-map');
            
            // Deshabilitar botones de control
            btnSync.disabled = true;
            btnRefresh.disabled = true;
            document.querySelectorAll('.btn-action, .btn-secondary-custom').forEach(b => b.disabled = true);
            
            // Cambiar texto de carga en el botón
            const originalHtml = btnSync.innerHTML;
            btnSync.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Sincronizando...';

            fetch('index.php?action=sincronizar_catalogo')
                .then(response => response.json())
                .then(res => {
                    if (res.success) {
                        alert(res.mensaje || 'Catálogo sincronizado correctamente.');
                    } else {
                        alert('Error al sincronizar el catálogo: ' + res.error);
                    }
                })
                .catch(err => {
                    alert('Error de conexión: ' + err);
                })
                .finally(() => {
                    // Restaurar botones y recargar listado
                    btnSync.innerHTML = originalHtml;
                    finalizarFlujoUI();
                });
        }

        // ==========================================
        // LECTURA DE LOGS HISTÓRICOS Y EN TIEMPO REAL
        // ==========================================
        function verLogsCliente(cliente) {
            document.getElementById('modal-client-name').innerText = cliente.nombre;
            document.getElementById('modal-schema-name').innerText = `esquema: ${cliente.schema || 'sin esquema'}`;
            
            const logsBody = document.getElementById('modal-logs-body');
            logsBody.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm text-info me-2"></div> Cargando bitácora...</div>';
            
            const modal = new bootstrap.Modal(document.getElementById('logsModal'));
            modal.show();

            fetch(`index.php?action=get_logs&schema=${cliente.schema || ''}`)
                .then(response => response.json())
                .then(res => {
                    if (!res.success) {
                        logsBody.innerHTML = `<div class="text-danger p-3">Error al cargar logs: ${res.error}</div>`;
                        return;
                    }

                    renderLogsTerminal(res.data, logsBody);
                })
                .catch(err => {
                    logsBody.innerHTML = `<div class="text-danger p-3">Error de comunicación: ${err}</div>`;
                });
        }

        function renderLogsTerminal(logs, container) {
            container.innerHTML = '';
            
            if (logs.length === 0) {
                container.innerHTML = '<div class="text-muted p-3">No hay registros de eventos para este cliente.</div>';
                return;
            }

            logs.forEach(log => {
                const row = document.createElement('div');
                row.className = 'terminal-log-row';
                
                const time = log.fecha_registro;
                let levelClass = `log-level-${log.nivel}`;
                let tag = log.nivel.toUpperCase();

                row.innerHTML = `<span class="log-time">[${time}]</span><span class="${levelClass}">[${tag}]</span> ${log.mensaje}`;
                if (log.detalles_tecnicos) {
                    row.innerHTML += `<br><small class="text-muted" style="margin-left: 20px;">Detalles: ${log.detalles_tecnicos}</small>`;
                }

                container.appendChild(row);
            });
        }
    </script>
</body>
</html>

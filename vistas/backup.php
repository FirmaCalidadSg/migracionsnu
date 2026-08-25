<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SNU Quality - Copia de Seguridad de Bases de Datos</title>
    
    <!-- Google Fonts: Outfit -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        :root {
            --bg-dark: #0a0c10;
            --bg-card: rgba(18, 24, 38, 0.95);
            --border-glass: rgba(255, 255, 255, 0.12);
            --accent-primary: #6366f1; /* Indigo */
            --accent-secondary: #06b6d4; /* Cian */
            --accent-violet: #8b5cf6; /* Violeta */
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --text-secondary: #cbd5e1;
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
                radial-gradient(circle at 10% 20%, rgba(99, 102, 241, 0.08) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(6, 182, 212, 0.08) 0%, transparent 40%);
        }

        /* Text Utilities High Contrast */
        .text-muted {
            color: var(--text-muted) !important;
        }
        .text-secondary-light {
            color: var(--text-secondary) !important;
        }

        /* Glassmorphism Cards */
        .glass-card {
            background: var(--bg-card);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--border-glass);
            border-radius: 16px;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.4);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .glass-card:hover {
            border-color: rgba(99, 102, 241, 0.4);
            box-shadow: 0 12px 40px 0 rgba(99, 102, 241, 0.15);
        }

        /* Navbar Style */
        .navbar-premium {
            background: rgba(10, 14, 22, 0.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-glass);
            padding: 15px 0;
        }

        .navbar-brand-premium {
            font-weight: 700;
            font-size: 1.5rem;
            background: linear-gradient(135deg, #818cf8, #22d3ee);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        /* Nav Pills Modern */
        .nav-link-custom {
            color: var(--text-muted);
            border-radius: 10px;
            padding: 8px 16px;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .nav-link-custom:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.08);
        }
        .nav-link-custom.active {
            color: #ffffff;
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.4), rgba(6, 182, 212, 0.4));
            border: 1px solid rgba(99, 102, 241, 0.5);
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
        .kpi-card.kpi-warning::before { background: var(--warning); }

        .kpi-title {
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            color: #94a3b8;
        }

        .kpi-val {
            font-size: 2.2rem;
            font-weight: 700;
            line-height: 1.2;
            margin-top: 8px;
            color: #ffffff;
        }

        /* Progress Bar Modern */
        .progress-premium {
            height: 14px;
            background-color: rgba(255, 255, 255, 0.08);
            border-radius: 9999px;
            overflow: hidden;
            border: 1px solid var(--border-glass);
        }

        .progress-bar-premium {
            background: linear-gradient(90deg, #6366f1, #06b6d4);
            border-radius: 9999px;
            transition: width 0.4s ease;
        }

        /* Terminal Console */
        .terminal-container {
            background: #080c14;
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            overflow: hidden;
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
        }

        .terminal-header {
            background: #111827;
            padding: 10px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            color: #cbd5e1;
            font-size: 0.8rem;
            font-weight: 500;
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

        .dot-red { background: #ef4444; }
        .dot-yellow { background: #f59e0b; }
        .dot-green { background: #10b981; }

        .terminal-body {
            padding: 16px;
            font-size: 0.85rem;
            max-height: 240px;
            overflow-y: auto;
            color: #38bdf8;
            line-height: 1.6;
            background: #080c14;
        }

        .terminal-log-row {
            margin-bottom: 5px;
            color: #7dd3fc;
            word-break: break-all;
        }

        .terminal-log-row.text-muted {
            color: #94a3b8 !important;
        }

        /* Buttons */
        .btn-action-backup {
            background: linear-gradient(135deg, #6366f1, #06b6d4);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            padding: 14px 28px;
            font-weight: 600;
            font-size: 1rem;
            box-shadow: 0 4px 20px rgba(99, 102, 241, 0.35);
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-action-backup:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(99, 102, 241, 0.5);
            color: #ffffff;
        }

        .btn-action-backup:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-secondary-custom {
            background: rgba(255, 255, 255, 0.08);
            color: #e2e8f0;
            border: 1px solid var(--border-glass);
            border-radius: 10px;
            padding: 8px 16px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .btn-secondary-custom:hover {
            background: rgba(255, 255, 255, 0.15);
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.3);
        }

        /* Table Styles (High Contrast Dark Mode) */
        .table-custom {
            color: #f1f5f9 !important;
            vertical-align: middle;
            margin-bottom: 0;
            background-color: transparent !important;
            --bs-table-bg: transparent !important;
            --bs-table-color: #f1f5f9 !important;
        }

        .table-custom th {
            background: rgba(255, 255, 255, 0.04) !important;
            color: #94a3b8 !important;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12) !important;
            padding: 14px 16px;
        }

        .table-custom td {
            background: transparent !important;
            color: #f1f5f9 !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
            padding: 14px 16px;
            font-size: 0.92rem;
        }

        .table-custom tbody tr {
            background: rgba(255, 255, 255, 0.015) !important;
            transition: background 0.2s ease;
        }

        .table-custom tbody tr:hover td {
            background: rgba(99, 102, 241, 0.08) !important;
            color: #ffffff !important;
        }

        .sha256-pill {
            font-size: 0.78rem;
            color: #c7d2fe !important;
            background: rgba(99, 102, 241, 0.2) !important;
            border: 1px solid rgba(99, 102, 241, 0.35);
            padding: 3px 8px;
            border-radius: 6px;
            font-family: 'SFMono-Regular', Consolas, monospace;
        }

        .badge-status {
            padding: 6px 12px;
            border-radius: 9999px;
            font-size: 0.78rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .badge-success-custom {
            background: rgba(16, 185, 129, 0.2);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.4);
        }
        .badge-warning-custom {
            background: rgba(245, 158, 11, 0.2);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.4);
        }
        .badge-info-custom {
            background: rgba(6, 182, 212, 0.2);
            color: #38bdf8;
            border: 1px solid rgba(6, 182, 212, 0.4);
        }
    </style>
</head>
<body>

    <!-- Barra de Navegación -->
    <nav class="navbar navbar-premium sticky-top">
        <div class="container">
            <div class="d-flex align-items-center gap-4">
                <a class="navbar-brand-premium" href="index.php?action=index">
                    <i class="bi bi-shield-check text-primary" style="font-size: 1.7rem;"></i>
                    <span>SNU QUALITY</span>
                </a>

                <div class="d-none d-md-flex align-items-center gap-2">
                    <a href="index.php?action=index" class="nav-link-custom">
                        <i class="bi bi-arrow-repeat"></i> Sincronizador
                    </a>
                    <a href="index.php?action=backup_index" class="nav-link-custom active">
                        <i class="bi bi-archive-fill"></i> Copias de Seguridad
                    </a>
                </div>
            </div>

            <div class="d-flex align-items-center gap-4">
                <div class="d-flex align-items-center gap-2">
                    <span class="d-inline-block rounded-circle bg-success" style="width: 8px; height: 8px;"></span>
                    <span style="font-size: 0.85rem; font-weight: 500; color: #cbd5e1;">Servidor SNU MariaDB</span>
                </div>
                <?php if (isset($_SESSION['migrador_user'])): ?>
                <div class="d-flex align-items-center gap-3 border-start ps-4 border-secondary border-opacity-25">
                    <span style="font-size: 0.85rem; font-weight: 500; color: #cbd5e1;">
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

    <div class="container my-5">

        <!-- Encabezado de Sección -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h2 class="fw-bold mb-1 d-flex align-items-center gap-2 text-white">
                    <i class="bi bi-database-down text-info"></i>
                    <span>Copia de Seguridad de Bases de Datos</span>
                </h2>
                <p class="text-secondary-light mb-0" style="font-size: 0.95rem;">
                    Generación de respaldo individual por esquema de cliente (<code style="color: #67e8f9; background: rgba(6,182,212,0.15); padding: 2px 6px; border-radius: 4px;">fugzcdpo_*</code>) empaquetado en un único archivo ZIP seguro con integridad SHA-256.
                </p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-secondary-custom" id="btn-refresh-status" onclick="loadStatus()">
                    <i class="bi bi-arrow-clockwise me-1"></i> Actualizar
                </button>
            </div>
        </div>

        <!-- Tarjetas de Métricas / KPIs -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="glass-card kpi-card kpi-info">
                    <div class="kpi-title">Bases Detectadas</div>
                    <div class="kpi-val" id="kpi-total-dbs"><?php echo count($databases); ?></div>
                    <i class="bi bi-database position-absolute text-muted opacity-25" style="font-size: 3rem; right: 20px; bottom: 10px;"></i>
                </div>
            </div>
            <div class="col-md-3">
                <div class="glass-card kpi-card kpi-success">
                    <div class="kpi-title">Backups Disponibles</div>
                    <div class="kpi-val" id="kpi-ready-count"><?php echo count($readyBackups); ?></div>
                    <i class="bi bi-file-earmark-zip position-absolute text-success opacity-25" style="font-size: 3rem; right: 20px; bottom: 10px;"></i>
                </div>
            </div>
            <div class="col-md-3">
                <div class="glass-card kpi-card kpi-warning">
                    <div class="kpi-title">Espacio en Disco</div>
                    <div class="kpi-val" id="kpi-disk-free">
                        <?php 
                            $freeBytes = @disk_free_space(BackupService::getBackupBasePath());
                            echo $freeBytes ? BackupService::formatBytes((int)$freeBytes) : 'OK';
                        ?>
                    </div>
                    <i class="bi bi-hdd position-absolute text-warning opacity-25" style="font-size: 3rem; right: 20px; bottom: 10px;"></i>
                </div>
            </div>
            <div class="col-md-3">
                <div class="glass-card kpi-card">
                    <div class="kpi-title">Estado de Ejecución</div>
                    <div class="kpi-val" id="kpi-lock-status" style="font-size: 1.25rem; margin-top: 14px;">
                        <?php if ($isRunning): ?>
                            <span class="badge-status badge-warning-custom"><i class="bi bi-hourglass-split"></i> En Proceso</span>
                        <?php else: ?>
                            <span class="badge-status badge-success-custom"><i class="bi bi-check-circle"></i> Disponible</span>
                        <?php endif; ?>
                    </div>
                    <i class="bi bi-lock position-absolute text-muted opacity-25" style="font-size: 3rem; right: 20px; bottom: 10px;"></i>
                </div>
            </div>
        </div>

        <!-- Panel de Control y Ejecución de Backup -->
        <div class="glass-card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                <div>
                    <h5 class="fw-bold mb-1 text-white">Generador de Respaldo Maestro</h5>
                    <p class="text-secondary-light mb-0" style="font-size: 0.88rem;">
                        Ejecuta un volcado transaccional individual por cada base detectada, genera metadatos <code style="color: #67e8f9; background: rgba(6,182,212,0.15); padding: 2px 6px; border-radius: 4px;">manifest.json</code> y valida la integridad final del archivo ZIP.
                    </p>
                </div>
                <button class="btn btn-action-backup" id="btn-start-backup" onclick="iniciarBackup()">
                    <i class="bi bi-play-circle-fill"></i>
                    <span id="btn-start-backup-text">GENERAR BACKUP</span>
                </button>
            </div>

            <!-- Sección de Progreso en Vivo (Se muestra al ejecutar) -->
            <div id="backup-progress-container" class="<?php echo $isRunning ? '' : 'd-none'; ?>">
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="fw-semibold" style="color: #e2e8f0;" id="progress-phase-label">Procesando volcado de bases...</span>
                                <span class="fw-bold text-info" id="progress-pct">0%</span>
                            </div>
                            <div class="progress-premium">
                                <div class="progress-bar-premium" id="progress-bar" style="width: 0%;"></div>
                            </div>
                            <div class="d-flex justify-content-between mt-2" style="font-size: 0.88rem;">
                                <span style="color: #94a3b8;" id="progress-details">0 de 0 bases procesadas</span>
                                <span class="text-info fw-semibold" id="progress-current-db">Iniciando...</span>
                            </div>
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
                                <span style="color: #cbd5e1;">Consola de Volcado en Vivo</span>
                            </div>
                            <div class="terminal-body" id="terminal-live-logs">
                                <div class="terminal-log-row text-muted">[SISTEMA] Esperando inicio del proceso de respaldo...</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Listado de Copias de Seguridad Disponibles -->
        <div class="glass-card p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 d-flex align-items-center gap-2 text-white">
                    <i class="bi bi-folder2-open text-primary"></i>
                    <span>Archivos de Backup Disponibles para Descarga</span>
                </h5>
            </div>

            <div class="table-responsive">
                <table class="table table-custom align-middle">
                    <thead>
                        <tr>
                            <th>Archivo de Backup</th>
                            <th>Fecha y Hora</th>
                            <th>Bases</th>
                            <th>Tamaño</th>
                            <th>Integridad SHA-256</th>
                            <th>Estado</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="backups-table-body">
                        <?php if (empty($readyBackups)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-2 d-block mb-2 text-muted"></i>
                                    No hay copias de seguridad generadas aún. Presione <strong>"GENERAR BACKUP"</strong> para crear la primera.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($readyBackups as $bk): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="bi bi-file-earmark-zip-fill text-warning fs-5"></i>
                                            <span class="fw-bold" style="color: #f8fafc; font-size: 0.95rem;"><?php echo htmlspecialchars($bk['filename']); ?></span>
                                        </div>
                                    </td>
                                    <td style="color: #cbd5e1;"><?php echo htmlspecialchars($bk['created_at']); ?></td>
                                    <td>
                                        <span class="badge bg-secondary bg-opacity-25 px-2 py-1" style="color: #f1f5f9; font-weight: 600;">
                                            <?php echo $bk['successful']; ?> / <?php echo $bk['total_databases']; ?>
                                        </span>
                                    </td>
                                    <td class="fw-bold" style="color: #38bdf8;"><?php echo htmlspecialchars($bk['size_formatted']); ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="sha256-pill">
                                                <?php echo substr($bk['sha256'], 0, 16); ?>...
                                            </span>
                                            <button class="btn btn-sm btn-link p-0" style="color: #94a3b8;" title="Copiar SHA-256 completo" onclick="copiarSha256('<?php echo $bk['sha256']; ?>')">
                                                <i class="bi bi-clipboard"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($bk['status'] === BackupService::STATE_COMPLETED): ?>
                                            <span class="badge-status badge-success-custom"><i class="bi bi-check-circle-fill"></i> Exitoso</span>
                                        <?php else: ?>
                                            <span class="badge-status badge-warning-custom"><i class="bi bi-exclamation-triangle-fill"></i> Con Observaciones</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex justify-content-end gap-2">
                                            <a href="index.php?action=backup_download&file=<?php echo urlencode($bk['filename']); ?>" class="btn btn-sm btn-primary px-3 fw-semibold" style="border-radius: 8px;">
                                                <i class="bi bi-download me-1"></i> Descargar
                                            </a>
                                            <button class="btn btn-sm btn-outline-danger px-2" style="border-radius: 8px;" onclick="eliminarBackup('<?php echo htmlspecialchars($bk['filename']); ?>')" title="Eliminar archivo">
                                                <i class="bi bi-trash"></i>
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

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        let pollingInterval = null;

        document.addEventListener('DOMContentLoaded', function() {
            loadStatus();
        });

        function loadStatus() {
            fetch('index.php?action=backup_get_status')
                .then(r => r.json())
                .then(res => {
                    if (!res.success) return;

                    document.getElementById('kpi-total-dbs').innerText = res.total_databases || 0;
                    document.getElementById('kpi-ready-count').innerText = (res.ready_backups || []).length;
                    document.getElementById('kpi-disk-free').innerText = res.disk_free || 'N/A';

                    const lockStatusEl = document.getElementById('kpi-lock-status');
                    if (res.is_running) {
                        lockStatusEl.innerHTML = '<span class="badge-status badge-warning-custom"><i class="bi bi-hourglass-split"></i> En Proceso</span>';
                        setUiRunning(true);
                        startPolling();
                    } else {
                        lockStatusEl.innerHTML = '<span class="badge-status badge-success-custom"><i class="bi bi-check-circle"></i> Disponible</span>';
                        setUiRunning(false);
                    }

                    renderBackupsTable(res.ready_backups || []);
                })
                .catch(err => console.error('Error al cargar estado:', err));
        }

        function iniciarBackup() {
            if (!confirm('¿Desea iniciar la generación de la copia de seguridad de todas las bases de datos de clientes?')) {
                return;
            }

            setUiRunning(true);
            addTerminalLog('[SISTEMA] Solicitando inicio de copia de seguridad al servidor...');

            fetch('index.php?action=backup_start', { method: 'POST' })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        addTerminalLog('[SISTEMA] ' + res.message);
                        startPolling();
                    } else {
                        alert(res.error || 'No se pudo iniciar el proceso de backup.');
                        setUiRunning(false);
                    }
                })
                .catch(err => {
                    alert('Error de conexión: ' + err);
                    setUiRunning(false);
                });
        }

        function startPolling() {
            if (pollingInterval) clearInterval(pollingInterval);

            pollingInterval = setInterval(() => {
                fetch('index.php?action=backup_poll')
                    .then(r => r.json())
                    .then(res => {
                        if (!res.success) return;

                        const state = res.state;
                        if (!state) return;

                        const pct = state.percent || 0;
                        document.getElementById('progress-pct').innerText = pct + '%';
                        document.getElementById('progress-bar').style.width = pct + '%';

                        document.getElementById('progress-phase-label').innerText = state.message || 'Procesando...';
                        document.getElementById('progress-details').innerText = `${state.processed || 0} de ${state.total || 0} bases procesadas`;
                        document.getElementById('progress-current-db').innerText = state.current_database ? `Base: ${state.current_database}` : '';

                        if (state.current_database) {
                            addTerminalLog(`[DUMP] Volcando esquema: ${state.current_database}`);
                        }

                        // Si finalizó
                        if (!res.is_running && (state.status === 'completed' || state.status === 'completed_with_errors' || state.status === 'failed')) {
                            clearInterval(pollingInterval);
                            pollingInterval = null;
                            setUiRunning(false);

                            if (state.status === 'completed') {
                                addTerminalLog(`[ÉXITO] Backup completado exitosamente: ${state.archive} (SHA-256: ${state.sha256 ? state.sha256.substring(0, 16) + '...' : ''})`);
                            } else if (state.status === 'completed_with_errors') {
                                addTerminalLog(`[AVISO] Backup completado con observaciones. Exitosas: ${state.successful}, Fallidas: ${state.failed}`);
                            } else {
                                addTerminalLog(`[ERROR] Fallo en el proceso de backup: ${state.error || 'Error desconocido'}`);
                            }

                            loadStatus();
                        }
                    })
                    .catch(err => console.error('Error durante el sondeo:', err));
            }, 1500);
        }

        function setUiRunning(running) {
            const btn = document.getElementById('btn-start-backup');
            const btnText = document.getElementById('btn-start-backup-text');
            const container = document.getElementById('backup-progress-container');

            if (running) {
                btn.disabled = true;
                btnText.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> GENERANDO BACKUP...';
                container.classList.remove('d-none');
            } else {
                btn.disabled = false;
                btnText.innerText = 'GENERAR BACKUP';
            }
        }

        function addTerminalLog(message) {
            const terminal = document.getElementById('terminal-live-logs');
            const row = document.createElement('div');
            row.className = 'terminal-log-row';
            const time = new Date().toLocaleTimeString();
            row.innerText = `[${time}] ${message}`;
            terminal.appendChild(row);
            terminal.scrollTop = terminal.scrollHeight;
        }

        function renderBackupsTable(backups) {
            const tbody = document.getElementById('backups-table-body');
            if (!backups || backups.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2 text-muted"></i>
                            No hay copias de seguridad generadas aún. Presione <strong>"GENERAR BACKUP"</strong> para crear la primera.
                        </td>
                    </tr>
                `;
                return;
            }

            let html = '';
            backups.forEach(bk => {
                const statusBadge = (bk.status === 'completed')
                    ? '<span class="badge-status badge-success-custom"><i class="bi bi-check-circle-fill"></i> Exitoso</span>'
                    : '<span class="badge-status badge-warning-custom"><i class="bi bi-exclamation-triangle-fill"></i> Con Observaciones</span>';

                html += `
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-file-earmark-zip-fill text-warning fs-5"></i>
                                <span class="fw-bold" style="color: #f8fafc; font-size: 0.95rem;">${escapeHtml(bk.filename)}</span>
                            </div>
                        </td>
                        <td style="color: #cbd5e1;">${escapeHtml(bk.created_at)}</td>
                        <td>
                            <span class="badge bg-secondary bg-opacity-25 px-2 py-1" style="color: #f1f5f9; font-weight: 600;">
                                ${bk.successful} / ${bk.total_databases}
                            </span>
                        </td>
                        <td class="fw-bold" style="color: #38bdf8;">${escapeHtml(bk.size_formatted)}</td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="sha256-pill">
                                    ${escapeHtml(bk.sha256.substring(0, 16))}...
                                </span>
                                <button class="btn btn-sm btn-link p-0" style="color: #94a3b8;" title="Copiar SHA-256 completo" onclick="copiarSha256('${escapeHtml(bk.sha256)}')">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                        </td>
                        <td>${statusBadge}</td>
                        <td class="text-end">
                            <div class="d-flex justify-content-end gap-2">
                                <a href="index.php?action=backup_download&file=${encodeURIComponent(bk.filename)}" class="btn btn-sm btn-primary px-3 fw-semibold" style="border-radius: 8px;">
                                    <i class="bi bi-download me-1"></i> Descargar
                                </a>
                                <button class="btn btn-sm btn-outline-danger px-2" style="border-radius: 8px;" onclick="eliminarBackup('${escapeHtml(bk.filename)}')" title="Eliminar archivo">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;
        }

        function copiarSha256(hash) {
            navigator.clipboard.writeText(hash).then(() => {
                alert('Hash SHA-256 copiado al portapapeles:\n' + hash);
            });
        }

        function eliminarBackup(filename) {
            if (!confirm(`¿Está seguro de que desea eliminar permanentemente la copia de seguridad "${filename}"?`)) {
                return;
            }

            const formData = new FormData();
            formData.append('file', filename);

            fetch('index.php?action=backup_delete', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        alert(res.message);
                        loadStatus();
                    } else {
                        alert(res.error || 'No se pudo eliminar el archivo.');
                    }
                })
                .catch(err => alert('Error: ' + err));
        }

        function escapeHtml(text) {
            if (!text) return '';
            return text.replace(/[&<>"']/g, function(m) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
            });
        }
    </script>
</body>
</html>

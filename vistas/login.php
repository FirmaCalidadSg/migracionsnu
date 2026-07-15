<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso - SNU Quality Migrator</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        :root {
            --bg-dark: #07090e;
            --accent-primary: #00f2fe;
            --accent-secondary: #4facfe;
            --border-glass: rgba(255, 255, 255, 0.08);
            --bg-glass: rgba(15, 20, 30, 0.6);
            --text-main: #f1f3f9;
            --text-muted: #8b9bb4;
        }

        body {
            background-color: var(--bg-dark);
            background-image: 
                radial-gradient(circle at 10% 20%, rgba(79, 172, 254, 0.05) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(0, 242, 254, 0.05) 0%, transparent 40%);
            color: var(--text-main);
            font-family: 'Outfit', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            overflow: hidden;
        }

        /* Ambient Glow animation background */
        .glow-sphere {
            position: absolute;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(79, 172, 254, 0.15) 0%, rgba(0, 242, 254, 0) 70%);
            border-radius: 50%;
            filter: blur(50px);
            z-index: 1;
            animation: floatGlow 15s infinite alternate ease-in-out;
        }

        .glow-sphere-1 {
            top: 15%;
            left: 20%;
        }

        .glow-sphere-2 {
            bottom: 15%;
            right: 20%;
            animation-delay: -5s;
        }

        @keyframes floatGlow {
            0% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(50px, -30px) scale(1.2); }
            100% { transform: translate(-20px, 40px) scale(0.9); }
        }

        .login-card {
            background: var(--bg-glass);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-glass);
            border-radius: 24px;
            padding: 40px;
            width: 100%;
            max-width: 440px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.4);
            z-index: 10;
            position: relative;
        }

        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-primary), var(--accent-secondary));
            border-radius: 24px 24px 0 0;
        }

        .logo-section {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo-icon {
            font-size: 3rem;
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: spinSlow 12s infinite linear;
            display: inline-block;
        }

        @keyframes spinSlow {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .logo-title {
            font-size: 1.45rem;
            font-weight: 700;
            letter-spacing: 1px;
            margin-top: 10px;
            background: linear-gradient(90deg, #ffffff, #c2d6ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .form-label-premium {
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            margin-bottom: 8px;
            text-transform: uppercase;
        }

        .input-group-premium {
            background: rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border-glass);
            border-radius: 12px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            padding: 2px 14px;
        }

        .input-group-premium:focus-within {
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(0, 242, 254, 0.15);
        }

        .input-group-premium i {
            color: var(--text-muted);
            font-size: 1.1rem;
            margin-right: 12px;
        }

        .form-control-premium {
            background: transparent !important;
            border: none !important;
            color: var(--text-main) !important;
            box-shadow: none !important;
            padding: 10px 0;
            font-size: 0.95rem;
            width: 100%;
        }

        .form-control-premium::placeholder {
            color: rgba(139, 155, 180, 0.5);
        }

        .btn-premium {
            background: linear-gradient(90deg, var(--accent-secondary), var(--accent-primary));
            border: none;
            color: #07090e;
            font-weight: 600;
            letter-spacing: 0.5px;
            padding: 12px;
            border-radius: 12px;
            width: 100%;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 242, 254, 0.2);
            margin-top: 10px;
        }

        .btn-premium:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 242, 254, 0.4);
            color: #07090e;
        }

        .btn-premium:active {
            transform: translateY(0);
        }

        .error-alert {
            background: rgba(220, 53, 69, 0.1);
            border: 1px solid rgba(220, 53, 69, 0.2);
            color: #ea868f;
            border-radius: 12px;
            padding: 12px;
            font-size: 0.88rem;
            display: none;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .footer-text {
            text-align: center;
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 30px;
        }
    </style>
</head>
<body>

    <div class="glow-sphere glow-sphere-1"></div>
    <div class="glow-sphere glow-sphere-2"></div>

    <div class="login-card">
        <div class="logo-section">
            <i class="bi bi-arrow-repeat logo-icon"></i>
            <div class="logo-title">SNU QUALITY MIGRATOR</div>
            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 4px;">Control de Acceso Seguro</div>
        </div>

        <div id="login-error" class="error-alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span id="error-text">Credenciales incorrectas</span>
        </div>

        <form id="login-form">
            <div class="mb-3">
                <label for="username" class="form-label-premium">Usuario Administrador</label>
                <div class="input-group-premium">
                    <i class="bi bi-person-fill"></i>
                    <input type="text" id="username" name="username" class="form-control-premium" placeholder="Ingresa tu usuario" required autocomplete="username">
                </div>
            </div>

            <div class="mb-4">
                <label for="password" class="form-label-premium">Contraseña</label>
                <div class="input-group-premium">
                    <i class="bi bi-lock-fill"></i>
                    <input type="password" id="password" name="password" class="form-control-premium" placeholder="Ingresa tu contraseña" required autocomplete="current-password">
                </div>
            </div>

            <button type="submit" id="btn-login-submit" class="btn-premium">
                <span id="btn-text">Iniciar Sesión</span>
            </button>
        </form>

        <div class="footer-text">
            &copy; 2026 SNU Quality. Todos los derechos reservados.
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.getElementById('login-form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const btn = document.getElementById('btn-login-submit');
            const btnText = document.getElementById('btn-text');
            const errorDiv = document.getElementById('login-error');
            const errorText = document.getElementById('error-text');
            
            // Ocultar alerta de error previa
            errorDiv.style.display = 'none';
            
            // Estado cargando
            btn.disabled = true;
            const originalHtml = btnText.innerHTML;
            btnText.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Autenticando...';
            
            const formData = new FormData(this);
            formData.append('action', 'login');
            
            fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(res => {
                if (res.success) {
                    // Redirigir al index para cargar el dashboard
                    window.location.href = 'index.php';
                } else {
                    errorText.innerText = res.error || 'Error al iniciar sesión.';
                    errorDiv.style.display = 'flex';
                    btn.disabled = false;
                    btnText.innerHTML = originalHtml;
                }
            })
            .catch(err => {
                errorText.innerText = 'Error de conexión con el servidor.';
                errorDiv.style.display = 'flex';
                btn.disabled = false;
                btnText.innerHTML = originalHtml;
            });
        });
    </script>
</body>
</html>

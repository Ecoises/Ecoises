{{-- resources/views/emails/reset-password-html.blade.php --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Restablecer contraseña - {{ $appName }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Poppins', sans-serif;
            line-height: 1.6;
            color: #374424;
            background-color: #f4f7ee;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: white;
            box-shadow: 0 10px 25px rgba(26, 46, 5, 0.1);
        }
        
        .logo-container {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        .header {
        background: linear-gradient(135deg, #3f4e26 0%, #1a2e05 100%);
        padding: 20px 20px; /* ⬅ Reducido */
        text-align: center;
        border-radius: 12px 12px 0 0;
    }
        .logo {
            width: 40px;   /* ⬅ Más pequeño */
            height: 40px;  /* ⬅ Más pequeño */
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .logo img {
            width: 100%;
            height: 100%;
            object-fit: contain; /* tu icono PNG se ajusta */
            border-radius: 8px;
        }
        .app-name {
            font-size: 24px;
            color: #fff;  /* ⬅ Reducido */
        }
        .custom-url {
        color: #a3e635; /* verde lima */
        text-decoration: underline;
        font-weight: 600;
        word-break: break-all; /* para que no se desborde si la URL es muy larga */
    }
        .header-subtitle {
            color: #ecfccb;
            font-size: 16px;
            margin-top: 8px;
        }
        .content {
            padding: 40px 30px;
        }
        .greeting {
            font-size: 24px;
            font-weight: 600;
            color: #1a2e05;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .message {
            font-size: 16px;
            color: #4a5d2c;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        .cta-container {
            text-align: center;
            margin: 40px 0;
        }
        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #84cc16 0%, #65a30d 100%);
            color: white;
            text-decoration: none;
            padding: 16px 32px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 16px;
            box-shadow: 0 4px 15px rgba(132, 204, 22, 0.3);
            transition: all 0.3s ease;
        }
        .cta-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(132, 204, 22, 0.4);
        }
        .expiry-warning {
            background-color: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 8px;
            padding: 16px;
            margin: 25px 0;
            text-align: center;
        }
        .expiry-warning-icon {
            font-size: 20px;
            margin-bottom: 8px;
        }
        .expiry-text {
            color: #92400e;
            font-weight: 600;
            font-size: 14px;
        }
        .security-tips {
            background-color: #f7f7f7;
            border-left: 4px solid #84cc16;
            padding: 20px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }
        .security-title {
            color: #1a2e05;
            font-weight: 600;
            font-size: 16px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .security-list {
            list-style: none;
            padding: 0;
        }
        .security-list li {
            color: #4a5d2c;
            margin-bottom: 6px;
            padding-left: 20px;
            position: relative;
        }
        .security-list li:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #84cc16;
            font-weight: bold;
        }
        .footer {
            background-color: #f4f7ee;
            padding: 30px;
            text-align: center;
            border-radius: 0 0 12px 12px;
            border-top: 1px solid #e6ecd9;
        }
        .footer-text {
            color: #7a9748;
            font-size: 14px;
            margin-bottom: 15px;
        }
        .url-fallback {
            background-color: #f9fafb;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 12px;
            margin-top: 20px;
            word-break: break-all;
            font-size: 12px;
            color: #6b7280;
        }
        .signature {
            margin-top: 25px;
            color: #1a2e05;
            font-weight: 500;
        }
        .team-name {
            color: #84cc16;
            font-weight: 600;
        }
        @media only screen and (max-width: 600px) {
            .container {
                margin: 0;
                border-radius: 0;
            }
            .header, .footer {
                border-radius: 0;
            }
            .content, .header, .footer {
                padding: 20px 15px;
            }
            .app-name {
                font-size: 24px;
            }
            .cta-button {
                padding: 14px 24px;
                font-size: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="logo-container">
                <img src="https://i.imgur.com/oVpuLgm.png" 
                alt="Logo Ecoises" 
                class="h-8 w-8" 
                width="40" height="40" />
                <h1 class="app-name">{{ $appName }}</h1>
            </div>
        </div>

        <!-- Content -->
        <div class="content">
            <h2 class="greeting">¡Hola! 🌿</h2>
            
            <p class="message">
                Recibiste este correo porque solicitaste restablecer la contraseña de tu cuenta en <strong>{{ $appName }}</strong>.
            </p>

            <p class="message">
                Haz clic en el botón de abajo para crear una nueva contraseña segura:
            </p>

            <!-- CTA Button -->
            <div class="cta-container">
                <a href="{{ $url }}" class="cta-button">
                    🔑 Restablecer mi contraseña
                </a>
            </div>

            <!-- Expiry Warning -->
            <div class="expiry-">
                <p class="expiry-text">⏰ Este enlace expirará en {{ $count }} minutos por tu seguridad</p>
            </div>

            <!-- Security Tips -->
            <div class="security-tips">
                <h3 class="security-title">Consejos de seguridad</h3>
                <ul class="security-list">
                    <li>Usa una contraseña única y fuerte</li>
                    <li>Incluye mayúsculas, minúsculas, números y símbolos</li>
                    <li>No compartas tu contraseña con nadie</li>
                    <li>Considera usar un gestor de contraseñas</li>
                </ul>
            </div>

            <p class="message">
                Si no solicitaste este restablecimiento, puedes ignorar este correo con seguridad. Tu contraseña actual no ha sido cambiada.
            </p>

            <!-- URL Fallback -->
           <div class="url-fallback">
                <strong>¿Problemas con el botón?</strong><br>
                Copia y pega esta URL en tu navegador:<br>
                <a href="{{ $url }}" class="custom-url">{{ $url }}</a>
            </div>



            <!-- Signature -->
            <div class="signature">
                Saludos,<br>
                El equipo de <span class="team-name">{{ $appName }}</span> 🌱
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p class="footer-text">
                Este es un mensaje automático, por favor no respondas a este correo.
            </p>
            <p class="footer-text">
                © {{ date('Y') }} {{ $appName }}. Todos los derechos reservados.
            </p>
        </div>
    </div>
</body>
</html>
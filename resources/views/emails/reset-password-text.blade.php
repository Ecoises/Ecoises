{{-- resources/views/emails/reset-password-text.blade.php --}}
¡Hola!

{{ $appName }} - Tu aventura en la naturaleza

Recibiste este correo porque solicitaste restablecer la contraseña de tu cuenta en {{ $appName }}.

Para crear una nueva contraseña, visita el siguiente enlace:
{{ $url }}

IMPORTANTE: Este enlace expirará en {{ $count }} minutos por tu seguridad.

CONSEJOS DE SEGURIDAD:
- Usa una contraseña única y fuerte
- Incluye mayúsculas, minúsculas, números y símbolos  
- No compartas tu contraseña con nadie
- Considera usar un gestor de contraseñas

Si no solicitaste este restablecimiento, puedes ignorar este correo con seguridad. Tu contraseña actual no ha sido cambiada.

Saludos,
El equipo de {{ $appName }}

---
Este es un mensaje automático, por favor no respondas a este correo.
© {{ date('Y') }} {{ $appName }}. Conectando personas con la naturaleza.
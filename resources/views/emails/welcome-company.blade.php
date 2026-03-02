<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: #f4f7fa; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
    .wrapper { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0; }

    /* CSS Logo Header (Zero External Images) */
    .header {
      padding: 40px;
      text-align: center;
      background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
      color: #ffffff;
    }
    .logo-box {
      display: inline-flex;
      align-items: center;
      background: #ffffff;
      padding: 10px 20px;
      border-radius: 8px;
      margin-bottom: 20px;
    }
    .logo-icon {
      font-size: 24px;
      margin-right: 10px;
      color: #2563eb;
      font-weight: bold;
    }
    .logo-text {
      font-size: 22px;
      font-weight: 800;
      color: #1e3a8a;
      letter-spacing: -1px;
    }
    .logo-subtitle { color: #2563eb; }

    .header h1 { font-size: 26px; font-weight: 800; margin-bottom: 8px; }
    .header p { color: rgba(255,255,255,0.85); font-size: 15px; }

    /* Body */
    .body { padding: 40px; }
    .greeting { font-size: 18px; color: #1e293b; font-weight: 700; margin-bottom: 12px; }
    .intro { font-size: 15px; color: #475569; line-height: 1.6; margin-bottom: 30px; }

    /* Credentials Card */
    .creds-card {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 24px;
      margin-bottom: 30px;
    }
    .creds-title {
      font-size: 12px; font-weight: 800; color: #64748b;
      text-transform: uppercase; letter-spacing: 0.1em;
      margin-bottom: 15px;
    }
    .cred-row {
      margin-bottom: 12px;
      padding: 12px;
      background: #ffffff;
      border: 1px solid #edf2f7;
      border-radius: 8px;
    }
    .cred-label { font-size: 11px; color: #94a3b8; text-transform: uppercase; font-weight: 600; }
    .cred-value { font-size: 15px; font-weight: 700; color: #1e293b; margin-top: 2px; }
    .cred-value a { color: #2563eb !important; text-decoration: none; }

    /* CTA */
    .cta-btn {
      display: block;
      background: #1e3a8a;
      color: #ffffff !important;
      text-align: center;
      text-decoration: none;
      font-size: 16px; font-weight: 700;
      padding: 16px;
      border-radius: 8px;
      margin-bottom: 30px;
    }

    /* Steps */
    .steps {
      background: #eff6ff;
      border-radius: 12px;
      padding: 24px;
    }
    .step-item { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 12px; }
    .step-num {
      width: 22px; height: 22px; background: #2563eb; color: #fff;
      border-radius: 50%; display: flex; align-items: center; justify-content: center;
      font-size: 11px; font-weight: 800; flex-shrink: 0;
    }
    .step-text { font-size: 13px; color: #334155; line-height: 1.5; }

    /* Footer */
    .footer { padding: 30px; text-align: center; font-size: 12px; color: #94a3b8; border-top: 1px solid #f1f5f9; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <div class="logo-box">
        <span class="logo-icon">⇄</span>
        <span class="logo-text">Controll <span class="logo-subtitle">CD</span></span>
      </div>
      <h1>¡Tu cuenta está lista! 🎉</h1>
      <p>Bienvenido al sistema oficial de Control-C&D</p>
    </div>

    <div class="body">
      <p class="greeting">Hola, {{ $userName }}!</p>
      <p class="intro">
        Tu empresa <strong>{{ $companyName }}</strong> ha sido registrada. 
        Usa las siguientes credenciales para acceder al panel de administración.
      </p>

      <div class="creds-card">
        <p class="creds-title">🔑 Credenciales de Acceso</p>
        <div class="cred-row">
          <div class="cred-label">Usuario</div>
          <div class="cred-value">{{ $username }}</div>
        </div>
        <div class="cred-row">
          <div class="cred-label">Correo</div>
          <div class="cred-value"><a href="mailto:{{ $userEmail }}">{{ $userEmail }}</a></div>
        </div>
        <div class="cred-row">
          <div class="cred-label">Contraseña Temporal</div>
          <div class="cred-value">{{ $password }}</div>
        </div>
      </div>

      <a href="{{ $appUrl }}" class="cta-btn">Ingresar al Sistema</a>

      <div class="steps">
        <p style="font-weight: 800; color: #1e3a8a; font-size: 14px; margin-bottom: 15px;">📋 Primeros pasos:</p>
        <div class="step-item">
          <div class="step-num">1</div>
          <div class="step-text">Accede con tu usuario y la contraseña temporal.</div>
        </div>
        <div class="step-item">
          <div class="step-num">2</div>
          <div class="step-text">Cambia tu contraseña por una segura en el primer ingreso.</div>
        </div>
        <div class="step-item">
          <div class="step-num">3</div>
          <div class="step-text">Configura tus rutas y comienza a operar.</div>
        </div>
      </div>
    </div>

    <div class="footer">
      <p><strong>Control-C&D — Soluciones de Crédito</strong></p>
      <p>© {{ date('Y') }} Todos los derechos reservados.</p>
    </div>
  </div>
</body>
</html>

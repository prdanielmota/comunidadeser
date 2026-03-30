<?php
// Sessão dura 12 horas
session_set_cookie_params(['lifetime' => 43200, 'samesite' => 'Lax']);
session_start();

define('CHECKIN_PASS', 'ComunidadeSer@2026');

// ── LOGIN / LOGOUT ────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    unset($_SESSION['checkin_auth'], $_SESSION['checkin_auth_time']);
    header('Location: /amigos/checkin'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['senha'])) {
    if (hash_equals(CHECKIN_PASS, $_POST['senha'])) {
        $_SESSION['checkin_auth']      = true;
        $_SESSION['checkin_auth_time'] = time();
        header('Location: ' . $_SERVER['REQUEST_URI']); exit;
    }
    $erro_login = 'Senha incorreta.';
}

// Expira sessão após 12 horas
if (!empty($_SESSION['checkin_auth_time']) && time() - $_SESSION['checkin_auth_time'] > 43200) {
    unset($_SESSION['checkin_auth'], $_SESSION['checkin_auth_time']);
}

$auth_checkin = !empty($_SESSION['checkin_auth']);

$token = trim($_GET['token'] ?? '');

// ── REGISTRAR ENTRADA (requer auth) ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'checkin') {
    if (!$auth_checkin) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'Não autorizado.']); exit; }
    header('Content-Type: application/json');
    $tk   = trim($_POST['token'] ?? '');
    $file = __DIR__ . '/eventos.json';
    $lock = fopen($file . '.lock', 'c');
    if (!flock($lock, LOCK_EX)) { echo json_encode(['ok'=>false,'error'=>'Tente novamente.']); exit; }
    $evs  = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    $found = false;
    foreach ($evs as &$ev) {
        foreach ($ev['confirmacoes'] as &$c) {
            if ($c['token'] === $tk) {
                if (!empty($c['checked_in_at'])) {
                    flock($lock, LOCK_UN); fclose($lock);
                    echo json_encode(['ok'=>false,'error'=>'Entrada já registrada em ' . date('d/m/Y H:i', strtotime($c['checked_in_at'])) . '.']); exit;
                }
                $c['checked_in_at'] = date('c');
                $found = true;
                break 2;
            }
        }
    }
    unset($ev, $c);
    if (!$found) { flock($lock, LOCK_UN); fclose($lock); echo json_encode(['ok'=>false,'error'=>'Token inválido.']); exit; }
    file_put_contents($file, json_encode($evs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    flock($lock, LOCK_UN); fclose($lock);
    echo json_encode(['ok'=>true,'at'=>date('d/m/Y \à\s H:i')]); exit;
}

// ── BUSCAR DADOS DO TOKEN ─────────────────────────────────────────────────────
$resultado = null; // ['evento'=>..., 'confirmacao'=>...]

if ($token !== '') {
    $file = __DIR__ . '/eventos.json';
    $evs  = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    foreach ($evs as $ev) {
        foreach ($ev['confirmacoes'] ?? [] as $c) {
            if ($c['token'] === $token) {
                $resultado = ['evento' => $ev, 'confirmacao' => $c];
                break 2;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Check-in</title>
<link rel="stylesheet" href="/assets/ser.css">
<style>
body{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2rem 1rem;position:relative;}

.card{width:100%;max-width:420px;border-radius:24px;animation:riseIn .5s cubic-bezier(.22,1,.36,1) both;}

.card-top{padding:2rem 2rem 1.5rem;border-bottom:1px solid var(--border);text-align:center;}
.icon-wrap{width:64px;height:64px;border-radius:18px;display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;font-size:2rem;}
.icon-ok{background:rgba(46,204,113,0.12);border:1px solid rgba(46,204,113,0.25);}
.icon-warn{background:rgba(201,168,76,0.12);border:1px solid rgba(201,168,76,0.25);}
.icon-err{background:rgba(231,76,60,0.12);border:1px solid rgba(231,76,60,0.25);}
.card-top h1{font-family:'Playfair Display',serif;font-size:1.5rem;font-weight:700;color:var(--text);margin-bottom:.35rem;}
.card-top p{font-size:.875rem;color:var(--text-dim);}

.card-body{padding:1.5rem 2rem;display:flex;flex-direction:column;gap:.85rem;}

.info-row{display:flex;flex-direction:column;gap:.25rem;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.85rem 1.1rem;}
.info-row label{font-size:.68rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);}
.info-row span{font-size:.95rem;color:var(--text);font-weight:500;word-break:break-all;}
.info-row span.muted{color:var(--text-dim);font-weight:400;}

.badge{display:inline-flex;align-items:center;gap:.35rem;font-size:.75rem;border-radius:20px;padding:.3rem .7rem;font-weight:500;width:fit-content;}
.badge-ok{background:rgba(46,204,113,0.1);border:1px solid rgba(46,204,113,0.25);color:var(--green);}
.badge-warn{background:rgba(201,168,76,0.12);border:1px solid rgba(201,168,76,0.25);color:var(--gold);}
.badge-err{background:rgba(231,76,60,0.1);border:1px solid rgba(231,76,60,0.25);color:var(--red);}

.divider{height:1px;background:var(--border);}

.btn-checkin{width:100%;padding:.9rem;background:var(--green);color:#0A0F1E;border:none;border-radius:var(--radius-sm);font-family:'Outfit',sans-serif;font-size:1rem;font-weight:700;cursor:pointer;transition:opacity .2s;letter-spacing:.02em;}
.btn-checkin:hover{opacity:.88;}
.btn-checkin:disabled{opacity:.5;cursor:default;}

.btn-next{width:100%;padding:.8rem;background:transparent;border:1px solid var(--border-active);border-radius:var(--radius-sm);color:var(--text-dim);font-family:'Outfit',sans-serif;font-size:.9rem;font-weight:500;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:.5rem;text-decoration:none;}
.btn-next:hover{border-color:var(--gold);color:var(--text);}

.checkin-done{width:100%;padding:.9rem;background:rgba(46,204,113,0.1);border:1px solid rgba(46,204,113,0.3);border-radius:var(--radius-sm);color:var(--green);font-family:'Outfit',sans-serif;font-size:.95rem;font-weight:600;text-align:center;}

.err-msg{font-size:.83rem;color:var(--red);text-align:center;padding:.5rem;background:rgba(231,76,60,0.1);border:1px solid rgba(231,76,60,0.25);border-radius:8px;}

.token-row{font-size:.72rem;color:var(--text-muted);text-align:center;font-family:monospace;padding:.25rem 0;}

.card-foot{padding:.75rem 2rem;border-top:1px solid var(--border);font-size:.75rem;color:var(--text-muted);text-align:center;}

/* LOGIN */
.login-card{background:var(--surface);border:1px solid var(--border);border-radius:24px;padding:2.5rem 2rem;width:100%;max-width:360px;position:relative;z-index:1;animation:riseIn .5s cubic-bezier(.22,1,.36,1) both;}
.login-card h1{font-size:1.3rem;margin-bottom:.3rem;}
.login-card p{color:var(--text-dim);font-size:.88rem;margin-bottom:1.5rem;}
.login-card label{display:block;font-size:.8rem;color:var(--text-dim);margin-bottom:.4rem;}
.login-card input[type=password]{width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:.75rem 1rem;color:var(--text);font-size:1rem;font-family:inherit;outline:none;transition:border .2s;}
.login-card input[type=password]:focus{border-color:var(--gold);}
.login-card button{margin-top:1.1rem;width:100%;padding:.8rem;background:var(--gold);color:#0A0F1E;border:none;border-radius:10px;font-size:1rem;font-weight:600;cursor:pointer;font-family:inherit;transition:opacity .2s;}
.login-card button:hover{opacity:.88;}
.login-err{margin-top:.75rem;color:var(--red);font-size:.85rem;text-align:center;}

/* SAIR link */
.link-logout{font-size:.75rem;color:var(--text-muted);text-align:right;display:block;margin-bottom:.5rem;cursor:pointer;}
.link-logout:hover{color:var(--text-dim);}

@media(max-width:440px){.card-body{padding:1.25rem 1.5rem}.card-top{padding:1.5rem 1.5rem 1.25rem}}
</style>
</head>
<body>

<?php if (!$auth_checkin): ?>
<!-- ── TELA DE LOGIN ── -->
<div class="login-card">
  <h1>Check-in</h1>
  <p>Informe a senha para acessar.</p>
  <form method="POST" action="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
    <label>Senha</label>
    <input type="password" name="senha" autofocus placeholder="••••••••">
    <button type="submit">Entrar</button>
    <?php if (!empty($erro_login)): ?>
      <p class="login-err"><?= htmlspecialchars($erro_login) ?></p>
    <?php endif; ?>
  </form>
</div>

<?php else: ?>
<!-- ── CONTEÚDO PRINCIPAL ── -->
<div class="card">
<?php if ($token === ''): ?>
  <!-- PRONTO PARA ESCANEAR -->
  <div class="card-top">
    <div class="icon-wrap icon-ok">📷</div>
    <h1>Pronto para escanear</h1>
    <p>Aponte a câmera para o QR Code do participante.</p>
  </div>
  <div class="card-body">
    <p style="font-size:.85rem;color:var(--text-dim);text-align:center;line-height:1.6">
      Use o aplicativo de câmera do celular para escanear o QR Code recebido pelo WhatsApp.
    </p>
  </div>

<?php elseif ($resultado === null): ?>
  <!-- TOKEN NÃO ENCONTRADO -->
  <div class="card-top">
    <div class="icon-wrap icon-err">❌</div>
    <h1>Não encontrado</h1>
    <p>Este QR Code não é válido ou foi removido.</p>
  </div>
  <div class="card-body">
    <div class="token-row">Token: <?= htmlspecialchars(substr($token,0,16)) ?>…</div>
    <a href="/amigos/checkin" class="btn-next">📷 Ler outro QR Code</a>
  </div>

<?php else:
  $ev   = $resultado['evento'];
  $conf = $resultado['confirmacao'];
  $ini  = $ev['data_inicio'] ?? '';
  $fim  = $ev['data_fim']    ?? '';
  $dt   = $ini ? date('d/m/Y H:i', strtotime($ini)) : '';
  if ($fim) $dt .= ' → ' . date('d/m/Y H:i', strtotime($fim));
  $confirmado_em = date('d/m/Y \à\s H:i', strtotime($conf['confirmed_at']));
  $ja_checkin    = !empty($conf['checked_in_at']);
  $checkin_em    = $ja_checkin ? date('d/m/Y \à\s H:i', strtotime($conf['checked_in_at'])) : '';
?>
  <!-- DADOS VÁLIDOS -->
  <div class="card-top">
    <div class="icon-wrap <?= $ja_checkin ? 'icon-warn' : 'icon-ok' ?>">
      <?= $ja_checkin ? '✅' : '🎟️' ?>
    </div>
    <h1><?= htmlspecialchars($ev['titulo']) ?></h1>
    <p><?= $dt ?></p>
  </div>

  <div class="card-body">

    <div class="info-row">
      <label>Participante</label>
      <span><?= htmlspecialchars($conf['nome'] ?: '—') ?></span>
    </div>

    <div class="info-row">
      <label>WhatsApp</label>
      <span><?= htmlspecialchars($conf['wpp']) ?></span>
    </div>

    <?php if ($ev['local'] ?? ''): ?>
    <div class="info-row">
      <label>Local</label>
      <span><?= htmlspecialchars($ev['local']) ?></span>
    </div>
    <?php endif; ?>

    <div class="info-row">
      <label>Confirmado em</label>
      <span><?= $confirmado_em ?></span>
    </div>

    <div class="divider"></div>

    <?php if ($ja_checkin): ?>
      <div class="checkin-done">✓ Entrada registrada em <?= $checkin_em ?></div>
    <?php else: ?>
      <button class="btn-checkin" id="btn-checkin" onclick="registrarEntrada()">Registrar entrada</button>
      <div id="checkin-err"></div>
    <?php endif; ?>

    <a href="/amigos/checkin" class="btn-next">📷 Ler outro QR Code</a>

    <div class="token-row">Token: <?= htmlspecialchars(substr($token, 0, 16)) ?>…</div>

  </div>
<?php endif; ?>

  <div class="card-foot">
    Comunidade Ser · Sistema de presença &nbsp;·&nbsp;
    <a href="/amigos/checkin?logout=1" style="color:var(--text-muted)">Sair</a>
  </div>
</div>
<?php endif; // fim do else (auth) ?>

<?php if ($auth_checkin && $resultado !== null && empty($resultado['confirmacao']['checked_in_at'])): ?>
<script>
async function registrarEntrada() {
  const btn   = document.getElementById('btn-checkin');
  const errEl = document.getElementById('checkin-err');
  btn.disabled = true; btn.textContent = 'Registrando…'; errEl.innerHTML = '';

  try {
    const fd = new FormData();
    fd.append('action', 'checkin');
    fd.append('token', <?= json_encode($token) ?>);
    const r = await fetch('checkin.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.ok) {
      errEl.innerHTML = '<div class="err-msg">' + d.error + '</div>';
      btn.disabled = false; btn.textContent = 'Registrar entrada';
      return;
    }
    btn.outerHTML = '<div class="checkin-done">✓ Entrada registrada em ' + d.at + '</div><a href="/amigos/checkin" class="btn-next" style="margin-top:.5rem">📷 Ler outro QR Code</a>';
  } catch {
    errEl.innerHTML = '<div class="err-msg">Erro de conexão. Tente novamente.</div>';
    btn.disabled = false; btn.textContent = 'Registrar entrada';
  }
}
</script>
<?php endif; ?>
</body>
</html>

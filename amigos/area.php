<?php
session_start();

require_once __DIR__ . '/../app/config.php';
define('SITE_URL', 'https://comunidadeser.com/amigos');

if (isset($_GET['logout'])) { session_destroy(); header('Location: index.html'); exit; }
if (empty($_SESSION['user'])) { header('Location: index.html'); exit; }

// ── ENVIAR OTP (troca de WhatsApp) ───────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'send_otp') {
    header('Content-Type: application/json');
    $wpp = preg_replace('/\D/', '', $_POST['wpp'] ?? '');
    if (strlen($wpp) < 10) { echo json_encode(['ok'=>false,'error'=>'WhatsApp inválido.']); exit; }
    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['otp_code'] = $code;
    $_SESSION['otp_wpp']  = $wpp;
    $_SESSION['otp_exp']  = time() + 300;
    $num = str_starts_with($wpp,'55') ? $wpp : '55'.$wpp;
    $msg = "Seu código de verificação para alterar o WhatsApp cadastrado:\n\n🔐 *{$code}*\n\nVálido por 5 minutos. Não compartilhe com ninguém.";
    $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\napikey: ".EVO_KEY."\r\n",'content'=>json_encode(['number'=>$num,'text'=>$msg]),'ignore_errors'=>true]]);
    $res = @file_get_contents(EVO_URL.'/message/sendText/'.EVO_INST, false, $ctx);
    $code_http = isset($http_response_header) ? (int)substr($http_response_header[0], 9, 3) : 0;
    if ($res === false || $code_http >= 400) { echo json_encode(['ok'=>false,'error'=>'Falha ao enviar o código.']); } else { echo json_encode(['ok'=>true]); }
    exit;
}

// ── CONFIRMAR PRESENÇA ───────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'confirm_event') {
    header('Content-Type: application/json');
    $evento_id = trim($_POST['evento_id'] ?? '');
    $user      = $_SESSION['user'];
    $user_wpp  = $user['wpp'] ?? '';
    $user_nome = $user['nome'] ?? ($user['email'] ?? '');
    if (!$evento_id || !$user_wpp) { echo json_encode(['ok'=>false,'error'=>'Dados inválidos.']); exit; }

    $file = __DIR__ . '/eventos.json';
    $lock = fopen($file . '.lock', 'c');
    if (!flock($lock, LOCK_EX)) { echo json_encode(['ok'=>false,'error'=>'Tente novamente.']); exit; }
    $evs = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];

    foreach ($evs as &$ev) {
        if ($ev['id'] === $evento_id) {
            foreach ($ev['confirmacoes'] ?? [] as $c) {
                if ($c['wpp'] === $user_wpp) {
                    flock($lock, LOCK_UN); fclose($lock);
                    echo json_encode(['ok'=>false,'error'=>'Você já confirmou presença neste evento.']); exit;
                }
            }
            $token = bin2hex(random_bytes(16));
            $ev['confirmacoes'][] = ['wpp'=>$user_wpp,'nome'=>$user_nome,'token'=>$token,'confirmed_at'=>date('c')];
            file_put_contents($file, json_encode($evs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            flock($lock, LOCK_UN); fclose($lock);

            // Gera e envia QR via WhatsApp
            $titulo   = $ev['titulo'];
            $local_ev = $ev['local'] ?? '';
            $inicio   = $ev['data_inicio'] ?? '';
            $fim      = $ev['data_fim']    ?? '';
            $data_fmt = $inicio ? date('d/m/Y H:i', strtotime($inicio)) : '';
            if ($fim) $data_fmt .= ' até ' . date('d/m/Y H:i', strtotime($fim));
            $qr_data  = urlencode(SITE_URL . '/checkin.php?token=' . $token);
            $qr_url   = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . $qr_data . '&format=png';
            $num      = str_starts_with($user_wpp,'55') ? $user_wpp : '55'.$user_wpp;
            $caption  = "🎉 Presença confirmada!\n\n*{$titulo}*\n📅 {$data_fmt}" . ($local_ev ? "\n📍 {$local_ev}" : '') . "\n\nApresente este QR Code na entrada do evento.";
            $payload  = json_encode(['number'=>$num,'mediatype'=>'image','mimetype'=>'image/png','caption'=>$caption,'media'=>$qr_url,'fileName'=>'confirmacao.png']);
            $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\napikey: ".EVO_KEY."\r\n",'content'=>$payload,'ignore_errors'=>true]]);
            @file_get_contents(EVO_URL.'/message/sendMedia/'.EVO_INST, false, $ctx);

            echo json_encode(['ok'=>true,'token'=>$token]); exit;
        }
    }
    unset($ev);
    flock($lock, LOCK_UN); fclose($lock);
    echo json_encode(['ok'=>false,'error'=>'Evento não encontrado.']); exit;
}

$erro = '';
$ok   = false;

// ── SALVAR PERFIL ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $novo_nome  = mb_substr(trim($_POST['nome']  ?? ''), 0, 120);
    $novo_email = trim($_POST['email'] ?? '');
    $novo_wpp   = preg_replace('/\D/', '', $_POST['wpp'] ?? '');
    $otp_input  = trim($_POST['otp_code'] ?? '');
    $orig_wpp   = $_SESSION['user']['wpp'];
    $wpp_mudou  = ($novo_wpp !== $orig_wpp);

    if ($novo_nome === '' && $novo_email === '') {
        $erro = 'Informe pelo menos nome ou e-mail.';
    } elseif ($novo_wpp === '' || strlen($novo_wpp) < 10) {
        $erro = 'WhatsApp inválido (mínimo 10 dígitos).';
    } elseif ($novo_email !== '' && !filter_var($novo_email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'E-mail inválido.';
    } elseif ($wpp_mudou) {
        $otp_ok = (
            !empty($_SESSION['otp_code']) && !empty($_SESSION['otp_wpp']) && isset($_SESSION['otp_exp']) &&
            time() <= $_SESSION['otp_exp'] && $_SESSION['otp_wpp'] === $novo_wpp &&
            hash_equals($_SESSION['otp_code'], $otp_input)
        );
        if (!$otp_ok) $erro = 'Código de verificação inválido ou expirado.';
    }

    if ($erro === '') {
        $file = __DIR__ . '/cadastros.json';
        $lock = fopen($file . '.lock', 'c');
        if (flock($lock, LOCK_EX)) {
            $list = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
            if ($wpp_mudou) {
                foreach ($list as $r) {
                    if ($r['wpp'] === $novo_wpp) {
                        $erro = 'Esse WhatsApp já está cadastrado por outro usuário.';
                        flock($lock, LOCK_UN); fclose($lock);
                        goto end_post;
                    }
                }
            }
            foreach ($list as &$item) {
                if ($item['wpp'] === $_SESSION['user']['wpp']) {
                    $item['nome'] = $novo_nome; $item['email'] = $novo_email; $item['wpp'] = $novo_wpp;
                    $_SESSION['user'] = $item; break;
                }
            }
            unset($item);
            file_put_contents($file, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            flock($lock, LOCK_UN); $ok = true;
        }
        fclose($lock);
        if ($ok) { unset($_SESSION['otp_code'],$_SESSION['otp_wpp'],$_SESSION['otp_exp']); header('Location: area.php?saved=1'); exit; }
    }
    end_post:;
}

// ── DADOS ─────────────────────────────────────────────────────────────────────
$user     = $_SESSION['user'];
$nome     = htmlspecialchars($user['nome']  ?? '');
$email    = htmlspecialchars($user['email'] ?? '');
$wpp      = htmlspecialchars($user['wpp']   ?? '');
$primeiro = $nome ? htmlspecialchars(explode(' ', $user['nome'])[0]) : ($email ?: 'Usuário');
$at       = isset($user['at']) ? date('d/m/Y \à\s H:i', strtotime($user['at'])) : '';
$saved    = isset($_GET['saved']);

// Carrega eventos futuros
$eventos_disp = [];
$fev = __DIR__ . '/eventos.json';
if (file_exists($fev)) {
    $evs_raw = json_decode(file_get_contents($fev), true) ?: [];
    $now = date('Y-m-d\TH:i');
    $user_wpp_raw = $user['wpp'] ?? '';
    foreach ($evs_raw as $ev) {
        $fim_ev = $ev['data_fim'] ?: $ev['data_inicio'];
        if ($fim_ev >= $now) {
            $already = false;
            foreach ($ev['confirmacoes'] ?? [] as $c) {
                if ($c['wpp'] === $user_wpp_raw) { $already = true; break; }
            }
            $ev['_confirmed'] = $already;
            $eventos_disp[] = $ev;
        }
    }
    usort($eventos_disp, fn($a,$b) => strcmp($a['data_inicio'], $b['data_inicio']));
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Minha Área</title>
<link rel="stylesheet" href="/assets/ser.css">
<style>
body{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2rem 1rem;position:relative;}

.card{width:100%;max-width:460px;border-radius:24px;animation:riseIn .5s cubic-bezier(.22,1,.36,1) both;}
.card-top{padding:2.25rem 2rem 1.75rem;border-bottom:1px solid var(--border);}
.logo-mark{width:48px;height:48px;background:var(--gold-glow);border:1px solid rgba(201,168,76,0.3);border-radius:14px;display:flex;align-items:center;justify-content:center;margin-bottom:1.25rem;}
.logo-mark svg{width:22px;height:22px;color:var(--gold)}
.card-top h1{font-family:'Playfair Display',serif;font-size:1.6rem;font-weight:700;color:var(--text);line-height:1.2;margin-bottom:.35rem;}
.card-top p{font-size:.875rem;color:var(--text-muted);}
.card-body{padding:1.75rem 2rem;display:flex;flex-direction:column;gap:1rem;}
.card-foot{padding:.875rem 2rem;border-top:1px solid var(--border);font-size:.75rem;color:var(--text-muted);text-align:center;}

/* FIELDS */
.field{display:flex;flex-direction:column;gap:.4rem;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:1rem 1.25rem;}
.field label{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);}
.field input{background:transparent;border:none;border-bottom:1px solid var(--border);border-radius:0;padding:.35rem 0;color:var(--text);font-family:'Outfit',sans-serif;font-size:1rem;font-weight:500;outline:none;transition:border-color .2s;width:100%;}
.field input:focus{border-bottom-color:var(--gold);}
.field input[readonly]{color:var(--text-dim);cursor:default;}
.field input::placeholder{color:var(--text-muted);font-weight:400;}
.badge-verified{display:inline-flex;align-items:center;gap:.35rem;font-size:.75rem;color:var(--green);background:rgba(46,204,113,0.1);border:1px solid rgba(46,204,113,0.2);border-radius:20px;padding:.25rem .65rem;margin-top:.25rem;width:fit-content;}
.badge-verified svg{width:13px;height:13px}

.section-title{font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);padding:.25rem 0;}

/* EVENTOS */
.evento-item{background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:1rem 1.25rem;display:flex;flex-direction:column;gap:.5rem;}
.evento-item-title{font-size:.95rem;font-weight:600;color:var(--text);}
.evento-item-meta{font-size:.78rem;color:var(--text-dim);display:flex;flex-direction:column;gap:.15rem;}
.evento-item-desc{font-size:.82rem;color:var(--text-dim);line-height:1.4;}
.btn-confirm{width:100%;padding:.65rem;background:var(--gold);color:#0A0F1E;border:none;border-radius:8px;font-family:'Outfit',sans-serif;font-size:.85rem;font-weight:600;cursor:pointer;transition:background .2s;margin-top:.25rem;}
.btn-confirm:hover{background:var(--gold-light);}
.btn-confirm:disabled{opacity:.55;cursor:default;}
.btn-confirmed{width:100%;padding:.65rem;background:rgba(46,204,113,0.12);color:var(--green);border:1px solid rgba(46,204,113,0.3);border-radius:8px;font-family:'Outfit',sans-serif;font-size:.85rem;font-weight:600;cursor:default;margin-top:.25rem;}

/* BUTTONS */
.btn-save{width:100%;padding:.85rem;background:var(--gold);color:#0A0F1E;border:none;border-radius:var(--radius-sm);font-family:'Outfit',sans-serif;font-size:.95rem;font-weight:600;cursor:pointer;transition:background .2s;margin-top:.25rem;}
.btn-save:hover{background:var(--gold-light);}
.btn-save:disabled{opacity:.6;cursor:default;}
.btn-logout{width:100%;padding:.85rem;background:transparent;border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--text-dim);font-family:'Outfit',sans-serif;font-size:.9rem;font-weight:500;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:.5rem;text-decoration:none;}
.btn-logout:hover{border-color:var(--border-active);color:var(--text);}

.msg{font-size:.83rem;padding:.6rem .9rem;border-radius:8px;text-align:center;}
.msg.err{background:rgba(231,76,60,0.12);border:1px solid rgba(231,76,60,0.3);color:var(--red);}
.msg.ok{background:rgba(46,204,113,0.1);border:1px solid rgba(46,204,113,0.25);color:var(--green);}

/* OTP OVERLAY */
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:100;align-items:center;justify-content:center;padding:1rem;}
.overlay.open{display:flex;}
.modal{background:var(--surface);border:1px solid var(--border-active);border-radius:20px;padding:2rem;width:100%;max-width:400px;position:relative;}
.modal h2{font-size:1.1rem;margin-bottom:.3rem;}
.modal .modal-sub{font-size:.85rem;color:var(--text-dim);margin-bottom:1.5rem;}
.modal-close{position:absolute;top:1rem;right:1rem;background:none;border:none;color:var(--text-dim);font-size:1.3rem;cursor:pointer;line-height:1;}
.modal-close:hover{color:var(--text);}
.otp-row{display:flex;gap:.5rem;justify-content:center;margin-bottom:1rem;}
.otp{width:44px;height:54px;text-align:center;font-size:1.4rem;font-weight:600;background:var(--surface2);border:1.5px solid var(--border);border-radius:10px;color:var(--text);font-family:'Outfit',sans-serif;outline:none;transition:border-color .2s;}
.otp:focus{border-color:var(--gold);}
.otp.has-error{border-color:var(--red);animation:shake .3s;}
@keyframes shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-4px)}75%{transform:translateX(4px)}}
.otp-err{font-size:.82rem;color:var(--red);text-align:center;min-height:1.2em;margin-bottom:.75rem;}
.otp-actions{display:flex;gap:.75rem;justify-content:flex-end;}
.btn-outline{padding:.6rem 1rem;background:transparent;border:1px solid var(--border-active);border-radius:8px;color:var(--text-dim);font-family:'Outfit',sans-serif;font-size:.88rem;font-weight:500;cursor:pointer;transition:all .2s;}
.btn-outline:hover{color:var(--text);}
.btn-otp-confirm{padding:.6rem 1.2rem;background:var(--gold);color:#0A0F1E;border:none;border-radius:8px;font-family:'Outfit',sans-serif;font-size:.88rem;font-weight:600;cursor:pointer;transition:background .2s;}
.btn-otp-confirm:hover{background:var(--gold-light);}
.btn-otp-confirm:disabled{opacity:.6;cursor:default;}
.timer-row{font-size:.8rem;color:var(--text-muted);text-align:center;margin-bottom:.75rem;}
.resend-btn{background:none;border:none;color:var(--gold);font-family:'Outfit',sans-serif;font-size:.8rem;cursor:pointer;padding:0;}
.resend-btn:hover{text-decoration:underline;}

@media(max-width:440px){
  .card-body{padding:1.5rem}
  .card-top{padding:1.75rem 1.5rem 1.5rem}
  .otp{width:38px;height:48px;font-size:1.2rem;}
}
</style>
</head>
<body>

<!-- MODAL OTP (troca de WhatsApp) -->
<div class="overlay" id="ov-otp">
  <div class="modal">
    <button class="modal-close" onclick="closeOtp()">✕</button>
    <h2>Verificar novo número</h2>
    <p class="modal-sub" id="otp-sub">Digite o código enviado ao seu novo WhatsApp.</p>
    <div class="otp-row">
      <input class="otp" maxlength="1" inputmode="numeric" oninput="oIn(this,0)" onkeydown="oKey(event,this,0)" onpaste="oPaste(event)">
      <input class="otp" maxlength="1" inputmode="numeric" oninput="oIn(this,1)" onkeydown="oKey(event,this,1)">
      <input class="otp" maxlength="1" inputmode="numeric" oninput="oIn(this,2)" onkeydown="oKey(event,this,2)">
      <input class="otp" maxlength="1" inputmode="numeric" oninput="oIn(this,3)" onkeydown="oKey(event,this,3)">
      <input class="otp" maxlength="1" inputmode="numeric" oninput="oIn(this,4)" onkeydown="oKey(event,this,4)">
      <input class="otp" maxlength="1" inputmode="numeric" oninput="oIn(this,5)" onkeydown="oKey(event,this,5)">
    </div>
    <div class="otp-err" id="otp-err"></div>
    <div class="timer-row" id="timer-row">Reenviar em <span id="timer-count">60s</span></div>
    <div class="timer-row" id="resend-wrap" style="display:none"><button class="resend-btn" onclick="sendOtp()">Reenviar código</button></div>
    <div class="otp-actions">
      <button class="btn-outline" onclick="closeOtp()">Cancelar</button>
      <button class="btn-otp-confirm" id="btn-otp-confirm" onclick="confirmarOtp()">Confirmar</button>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-top">
    <div class="logo-mark">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
      </svg>
    </div>
    <h1>Olá, <?= $primeiro ?>!</h1>
    <p>Bem-vindo à sua área pessoal</p>
  </div>

  <div class="card-body">

    <?php if ($erro): ?>
      <div class="msg err"><?= htmlspecialchars($erro) ?></div>
    <?php elseif ($saved): ?>
      <div class="msg ok">Dados salvos com sucesso!</div>
    <?php endif; ?>

    <!-- PERFIL -->
    <form id="main-form" method="POST" action="area.php" onsubmit="return handleSubmit(event)">
      <input type="hidden" name="otp_code" id="hidden-otp">
      <div style="display:flex;flex-direction:column;gap:1rem">
        <div class="field">
          <label>Nome completo</label>
          <input type="text" name="nome" id="f-nome" value="<?= $nome ?>" placeholder="Seu nome completo">
        </div>
        <div class="field">
          <label>E-mail</label>
          <input type="email" name="email" id="f-email" value="<?= $email ?>" placeholder="seu@email.com">
        </div>
        <div class="field">
          <label>WhatsApp</label>
          <input type="tel" name="wpp" id="f-wpp" value="<?= $wpp ?>" placeholder="5592999999999" data-original="<?= $wpp ?>">
          <div class="badge-verified" id="badge-verified">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            Número verificado
          </div>
        </div>
        <?php if ($at): ?>
        <div class="field">
          <label>Cadastrado em</label>
          <input type="text" value="<?= $at ?>" readonly tabindex="-1">
        </div>
        <?php endif; ?>
        <button type="submit" class="btn-save" id="btn-save">Salvar</button>
      </div>
    </form>

    <!-- EVENTOS -->
    <?php if (!empty($eventos_disp)): ?>
    <div class="section-title">Eventos</div>
    <?php foreach ($eventos_disp as $ev):
      $ev_id     = htmlspecialchars($ev['id']);
      $ev_tit    = htmlspecialchars($ev['titulo']);
      $ev_desc   = htmlspecialchars($ev['descricao'] ?? '');
      $ev_loc    = htmlspecialchars($ev['local'] ?? '');
      $ev_ini    = $ev['data_inicio'] ?? '';
      $ev_fim    = $ev['data_fim']    ?? '';
      $ev_dt     = $ev_ini ? date('d/m/Y H:i', strtotime($ev_ini)) : '';
      if ($ev_fim) $ev_dt .= ' → ' . date('d/m/Y H:i', strtotime($ev_fim));
      $confirmed = $ev['_confirmed'];
    ?>
    <div class="evento-item" id="ev-item-<?= $ev_id ?>">
      <div class="evento-item-title"><?= $ev_tit ?></div>
      <div class="evento-item-meta">
        <span>📅 <?= $ev_dt ?></span>
        <?php if ($ev_loc): ?><span>📍 <?= $ev_loc ?></span><?php endif; ?>
      </div>
      <?php if ($ev_desc): ?><div class="evento-item-desc"><?= $ev_desc ?></div><?php endif; ?>
      <?php if ($confirmed): ?>
        <div class="btn-confirmed">✓ Presença confirmada</div>
      <?php else: ?>
        <button class="btn-confirm" id="btn-ev-<?= $ev_id ?>" onclick="confirmarPresenca('<?= $ev_id ?>')">Confirmar presença</button>
      <?php endif; ?>
      <div class="msg" id="ev-msg-<?= $ev_id ?>" style="display:none"></div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <a href="area.php?logout=1" class="btn-logout">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
      Sair
    </a>

  </div>
  <div class="card-foot">Seus dados estão protegidos e não serão compartilhados.</div>
</div>

<script>
let timerID = null;

/* ── CONFIRMAR PRESENÇA ── */
async function confirmarPresenca(eventoId) {
  const btn   = document.getElementById('btn-ev-' + eventoId);
  const msgEl = document.getElementById('ev-msg-' + eventoId);
  btn.disabled = true; btn.textContent = 'Confirmando…';
  msgEl.style.display = 'none'; msgEl.className = 'msg';

  try {
    const fd = new FormData();
    fd.append('evento_id', eventoId);
    const r = await fetch('area.php?action=confirm_event', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.ok) {
      btn.disabled = false; btn.textContent = 'Confirmar presença';
      msgEl.className = 'msg err'; msgEl.textContent = d.error || 'Erro ao confirmar.'; msgEl.style.display = 'block';
      return;
    }
    // Sucesso
    btn.outerHTML = '<div class="btn-confirmed">✓ Presença confirmada</div>';
    msgEl.className = 'msg ok';
    msgEl.textContent = 'QR Code enviado pelo WhatsApp!';
    msgEl.style.display = 'block';
  } catch {
    btn.disabled = false; btn.textContent = 'Confirmar presença';
    msgEl.className = 'msg err'; msgEl.textContent = 'Erro de conexão.'; msgEl.style.display = 'block';
  }
}

/* ── SUBMIT PERFIL ── */
async function handleSubmit(e) {
  const wpp    = document.getElementById('f-wpp').value.replace(/\D/g,'');
  const origWpp = document.getElementById('f-wpp').dataset.original.replace(/\D/g,'');
  if (wpp !== origWpp && !document.getElementById('hidden-otp').value) {
    e.preventDefault();
    await sendOtp();
    return false;
  }
  return true;
}

/* ── ENVIAR OTP ── */
async function sendOtp() {
  const wpp = document.getElementById('f-wpp').value.replace(/\D/g,'');
  if (!wpp || wpp.length < 10) { alert('WhatsApp inválido.'); return; }
  const btn = document.getElementById('btn-save');
  btn.disabled = true; btn.textContent = 'Enviando código…';
  try {
    const fd = new FormData(); fd.append('wpp', wpp);
    const r = await fetch('area.php?action=send_otp', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.ok) { alert(d.error || 'Erro ao enviar o código.'); return; }
    const num = wpp.startsWith('55') ? wpp : '55' + wpp;
    document.getElementById('otp-sub').textContent = 'Digite o código enviado para +' + num + '.';
    resetOtp();
    document.getElementById('ov-otp').classList.add('open');
    startTimer();
    document.querySelector('.otp').focus();
  } catch { alert('Erro de conexão ao enviar o código.'); }
  finally { btn.disabled = false; btn.textContent = 'Salvar'; }
}

async function confirmarOtp() {
  const code  = Array.from(document.querySelectorAll('.otp')).map(i => i.value).join('');
  const errEl = document.getElementById('otp-err');
  if (code.length < 6) { errEl.textContent = 'Preencha todos os 6 dígitos.'; return; }
  const btn = document.getElementById('btn-otp-confirm');
  btn.disabled = true; btn.textContent = 'Verificando…'; errEl.textContent = '';
  document.getElementById('hidden-otp').value = code;
  const fd = new FormData(document.getElementById('main-form'));
  fd.set('otp_code', code);
  try {
    const r  = await fetch('area.php', { method: 'POST', body: fd });
    const url = r.url;
    if (url.includes('saved=1')) { window.location.href = 'area.php?saved=1'; return; }
    const text = await r.text();
    if (text.includes('Código de verificação inválido')) {
      errEl.textContent = 'Código incorreto ou expirado.';
      document.querySelectorAll('.otp').forEach(i => i.classList.add('has-error'));
      setTimeout(() => document.querySelectorAll('.otp').forEach(i => i.classList.remove('has-error')), 600);
    } else { closeOtp(); window.location.href = 'area.php'; }
  } catch { errEl.textContent = 'Erro de conexão.'; }
  finally { btn.disabled = false; btn.textContent = 'Confirmar'; }
}
function closeOtp() { clearInterval(timerID); document.getElementById('ov-otp').classList.remove('open'); }

/* ── OTP INPUTS ── */
function getOtps() { return Array.from(document.querySelectorAll('.otp')); }
function resetOtp() { getOtps().forEach(i => { i.value = ''; i.classList.remove('has-error'); }); document.getElementById('otp-err').textContent = ''; }
function oIn(el, idx) { el.value = el.value.replace(/\D/g,'').slice(0,1); if (el.value && idx < 5) getOtps()[idx+1].focus(); }
function oKey(e, el, idx) { if (e.key==='Backspace' && !el.value && idx>0) getOtps()[idx-1].focus(); if (e.key==='Enter') confirmarOtp(); }
function oPaste(e) {
  e.preventDefault();
  const digits = (e.clipboardData||window.clipboardData).getData('text').replace(/\D/g,'').slice(0,6);
  getOtps().forEach((el,i) => { if (digits[i]) el.value = digits[i]; });
  if (digits.length === 6) confirmarOtp();
}

/* ── TIMER ── */
function startTimer() {
  let s = 60;
  document.getElementById('timer-row').style.display = 'block';
  document.getElementById('resend-wrap').style.display = 'none';
  document.getElementById('timer-count').textContent = s + 's';
  clearInterval(timerID);
  timerID = setInterval(() => {
    s--;
    document.getElementById('timer-count').textContent = s + 's';
    if (s <= 0) { clearInterval(timerID); document.getElementById('timer-row').style.display = 'none'; document.getElementById('resend-wrap').style.display = 'block'; }
  }, 1000);
}

/* ── BADGE WPP ── */
document.getElementById('f-wpp').addEventListener('input', function() {
  const orig = this.dataset.original.replace(/\D/g,'');
  const cur  = this.value.replace(/\D/g,'');
  document.getElementById('badge-verified').style.opacity = (cur === orig) ? '1' : '0.3';
});
</script>
</body>
</html>

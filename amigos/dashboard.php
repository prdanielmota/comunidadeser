<?php
session_start();

define('PASS_PLAIN', 'ComunidadeSer@2026');
define('EVO_URL',    'https://evolution.osmota.org');
define('EVO_KEY',    '1E0C076ACE4B-4974-8450-E622B0129B6F');
define('EVO_INST',   'ComunidadeSer');
define('SITE_URL',   'https://comunidadeser.com/amigos');

function checkPass(string $p): bool { return hash_equals(PASS_PLAIN, $p); }

$auth   = !empty($_SESSION['auth']);
$action = $_GET['action'] ?? '';

// ── API: CADASTROS ────────────────────────────────────────────────────────────
if ($auth && in_array($action, ['add','edit','delete'])) {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $file = __DIR__ . '/cadastros.json';
    $lock = fopen($file . '.lock', 'c');
    if (!flock($lock, LOCK_EX)) { echo json_encode(['ok'=>false,'error'=>'Lock timeout']); exit; }
    $list = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];

    if ($action === 'add') {
        $nome  = mb_substr(trim($data['nome']  ?? ''), 0, 120);
        $email = mb_substr(trim($data['email'] ?? ''), 0, 200);
        $wpp   = preg_replace('/\D/', '', $data['wpp'] ?? '');
        $classificacao = in_array($data['classificacao'] ?? '', ['interessado','estudo_biblico','candidato','batizado']) ? $data['classificacao'] : 'interessado';
        if (($nome === '' && $email === '') || strlen($wpp) < 10) {
            flock($lock, LOCK_UN); fclose($lock);
            echo json_encode(['ok'=>false,'error'=>'Dados inválidos.']); exit;
        }
        foreach ($list as $r) {
            if ($r['wpp'] === $wpp) {
                flock($lock, LOCK_UN); fclose($lock);
                echo json_encode(['ok'=>false,'error'=>'Esse WhatsApp já está cadastrado.']); exit;
            }
        }
        $entry = ['nome'=>$nome,'email'=>$email,'wpp'=>$wpp,'classificacao'=>$classificacao,'at'=>date('c')];
        $list[] = $entry;
        file_put_contents($file, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        flock($lock, LOCK_UN); fclose($lock);
        echo json_encode(['ok'=>true,'entry'=>$entry]); exit;
    }
    if ($action === 'delete') {
        $wpp  = preg_replace('/\D/', '', $data['wpp'] ?? '');
        $list = array_values(array_filter($list, fn($r) => $r['wpp'] !== $wpp));
        file_put_contents($file, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        flock($lock, LOCK_UN); fclose($lock);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($action === 'edit') {
        $orig = preg_replace('/\D/', '', $data['wpp_original'] ?? '');
        $novo = preg_replace('/\D/', '', $data['wpp'] ?? '');
        $classificacao = in_array($data['classificacao'] ?? '', ['interessado','estudo_biblico','candidato','batizado']) ? $data['classificacao'] : 'interessado';
        if ($novo !== $orig) {
            foreach ($list as $r) {
                if ($r['wpp'] === $novo) {
                    flock($lock, LOCK_UN); fclose($lock);
                    echo json_encode(['ok'=>false,'error'=>'Esse WhatsApp já está cadastrado.']); exit;
                }
            }
        }
        $found = false;
        foreach ($list as &$r) {
            if ($r['wpp'] === $orig) {
                $r['nome']  = mb_substr(trim($data['nome']  ?? ''), 0, 120);
                $r['email'] = mb_substr(trim($data['email'] ?? ''), 0, 200);
                $r['wpp']   = $novo ?: $orig;
                $r['classificacao'] = $classificacao;
                $found = true; break;
            }
        }
        unset($r);
        if (!$found) { flock($lock, LOCK_UN); fclose($lock); echo json_encode(['ok'=>false,'error'=>'Registro não encontrado.']); exit; }
        file_put_contents($file, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        flock($lock, LOCK_UN); fclose($lock);
        echo json_encode(['ok'=>true]); exit;
    }
}

// ── API: EVENTOS ──────────────────────────────────────────────────────────────
// ── API: ENVIAR CONFIRMAÇÃO DE EVENTO (manual) ────────────────────────────────
if ($auth && $action === 'enviar_confirmacao') {
    header('Content-Type: application/json');
    $data      = json_decode(file_get_contents('php://input'), true) ?: [];
    $wpp       = preg_replace('/\D/', '', $data['wpp'] ?? '');
    $evento_id = trim($data['evento_id'] ?? '');
    if (!$wpp || !$evento_id) { echo json_encode(['ok'=>false,'error'=>'Dados inválidos.']); exit; }

    // Busca nome do contato
    $cfile = __DIR__ . '/cadastros.json';
    $clist = file_exists($cfile) ? (json_decode(file_get_contents($cfile), true) ?: []) : [];
    $user_nome = '';
    foreach ($clist as $r) {
        if ($r['wpp'] === $wpp) { $user_nome = $r['nome'] ?? ($r['email'] ?? $wpp); break; }
    }

    $file = __DIR__ . '/eventos.json';
    $lock = fopen($file . '.lock', 'c');
    if (!flock($lock, LOCK_EX)) { echo json_encode(['ok'=>false,'error'=>'Lock timeout']); exit; }
    $evs = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];

    foreach ($evs as &$ev) {
        if ($ev['id'] === $evento_id) {
            foreach ($ev['confirmacoes'] ?? [] as $c) {
                if ($c['wpp'] === $wpp) {
                    flock($lock, LOCK_UN); fclose($lock);
                    echo json_encode(['ok'=>false,'error'=>'Este contato já confirmou presença neste evento.']); exit;
                }
            }
            $token = bin2hex(random_bytes(16));
            $ev['confirmacoes'][] = ['wpp'=>$wpp,'nome'=>$user_nome,'token'=>$token,'confirmed_at'=>date('c')];
            file_put_contents($file, json_encode($evs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            flock($lock, LOCK_UN); fclose($lock);

            $titulo   = $ev['titulo'];
            $local_ev = $ev['local'] ?? '';
            $inicio   = $ev['data_inicio'] ?? '';
            $fim      = $ev['data_fim']    ?? '';
            $data_fmt = $inicio ? date('d/m/Y H:i', strtotime($inicio)) : '';
            if ($fim) $data_fmt .= ' até ' . date('d/m/Y H:i', strtotime($fim));
            $num      = str_starts_with($wpp,'55') ? $wpp : '55'.$wpp;
            $qr_data  = urlencode(SITE_URL . '/checkin.php?token=' . $token);
            $qr_url   = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . $qr_data . '&format=png';
            $caption  = "🎉 Presença confirmada!\n\n*{$titulo}*\n📅 {$data_fmt}" . ($local_ev ? "\n📍 {$local_ev}" : '') . "\n\nApresente este QR Code na entrada do evento.";
            $payload  = json_encode(['number'=>$num,'mediatype'=>'image','mimetype'=>'image/png','caption'=>$caption,'media'=>$qr_url,'fileName'=>'confirmacao.png']);
            $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\napikey: ".EVO_KEY."\r\n",'content'=>$payload,'ignore_errors'=>true]]);
            @file_get_contents(EVO_URL.'/message/sendMedia/'.EVO_INST, false, $ctx);

            echo json_encode(['ok'=>true]); exit;
        }
    }
    unset($ev);
    flock($lock, LOCK_UN); fclose($lock);
    echo json_encode(['ok'=>false,'error'=>'Evento não encontrado.']); exit;
}

if ($auth && $action === 'get_confirmacoes') {
    header('Content-Type: application/json');
    $id   = trim($_GET['id'] ?? '');
    $file = __DIR__ . '/eventos.json';
    $evs  = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    foreach ($evs as $ev) {
        if ($ev['id'] === $id) { echo json_encode(['ok'=>true,'confirmacoes'=>$ev['confirmacoes']??[]]); exit; }
    }
    echo json_encode(['ok'=>false,'error'=>'Evento não encontrado.']); exit;
}

if ($auth && in_array($action, ['add_event','edit_event','delete_event'])) {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $file = __DIR__ . '/eventos.json';
    $lock = fopen($file . '.lock', 'c');
    if (!flock($lock, LOCK_EX)) { echo json_encode(['ok'=>false,'error'=>'Lock timeout']); exit; }
    $evs  = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];

    if ($action === 'add_event') {
        $titulo     = mb_substr(trim($data['titulo']    ?? ''), 0, 120);
        $descricao  = mb_substr(trim($data['descricao'] ?? ''), 0, 500);
        $data_inicio = trim($data['data_inicio'] ?? '');
        $data_fim    = trim($data['data_fim']    ?? '');
        $local      = mb_substr(trim($data['local']     ?? ''), 0, 200);
        if ($titulo === '' || $data_inicio === '') {
            flock($lock, LOCK_UN); fclose($lock);
            echo json_encode(['ok'=>false,'error'=>'Título e data de início são obrigatórios.']); exit;
        }
        $entry = [
            'id'           => bin2hex(random_bytes(8)),
            'titulo'       => $titulo,
            'descricao'    => $descricao,
            'data_inicio'  => $data_inicio,
            'data_fim'     => $data_fim,
            'local'        => $local,
            'created_at'   => date('c'),
            'confirmacoes' => [],
        ];
        $evs[] = $entry;
        file_put_contents($file, json_encode($evs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        flock($lock, LOCK_UN); fclose($lock);
        echo json_encode(['ok'=>true,'event'=>$entry]); exit;
    }
    if ($action === 'edit_event') {
        $id          = trim($data['id'] ?? '');
        $titulo      = mb_substr(trim($data['titulo']    ?? ''), 0, 120);
        $descricao   = mb_substr(trim($data['descricao'] ?? ''), 0, 500);
        $data_inicio = trim($data['data_inicio'] ?? '');
        $data_fim    = trim($data['data_fim']    ?? '');
        $local       = mb_substr(trim($data['local']     ?? ''), 0, 200);
        if ($titulo === '' || $data_inicio === '') {
            flock($lock, LOCK_UN); fclose($lock);
            echo json_encode(['ok'=>false,'error'=>'Título e data de início são obrigatórios.']); exit;
        }
        $found = false;
        foreach ($evs as &$ev) {
            if ($ev['id'] === $id) {
                $ev['titulo']      = $titulo;
                $ev['descricao']   = $descricao;
                $ev['data_inicio'] = $data_inicio;
                $ev['data_fim']    = $data_fim;
                $ev['local']       = $local;
                $found = true; break;
            }
        }
        unset($ev);
        if (!$found) { flock($lock, LOCK_UN); fclose($lock); echo json_encode(['ok'=>false,'error'=>'Evento não encontrado.']); exit; }
        file_put_contents($file, json_encode($evs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        flock($lock, LOCK_UN); fclose($lock);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($action === 'delete_event') {
        $id  = trim($data['id'] ?? '');
        $evs = array_values(array_filter($evs, fn($ev) => $ev['id'] !== $id));
        file_put_contents($file, json_encode($evs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        flock($lock, LOCK_UN); fclose($lock);
        echo json_encode(['ok'=>true]); exit;
    }
}

// ── LOGOUT ────────────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) { session_destroy(); header('Location: dashboard.php'); exit; }

// ── LOGIN ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['senha'])) {
    if (checkPass($_POST['senha'])) { $_SESSION['auth'] = true; header('Location: dashboard.php'); exit; }
    $erro_login = 'Senha incorreta.';
}

// ── EXPORTAR CSV ──────────────────────────────────────────────────────────────
if ($auth && isset($_GET['export'])) {
    $file = __DIR__ . '/cadastros.json';
    $list = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="cadastros_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    echo "Nome,Email,WhatsApp,Data\n";
    foreach (array_reverse($list) as $r) {
        $dt = date('d/m/Y H:i', strtotime($r['at']));
        echo '"'.str_replace('"','""',$r['nome']??'').'",'.
             '"'.str_replace('"','""',$r['email']??'').'",'.
             '"'.($r['wpp']??'').'","'.$dt.'"'."\n";
    }
    exit;
}

// ── DADOS ─────────────────────────────────────────────────────────────────────
$list   = [];
$eventos = [];
if ($auth) {
    $file = __DIR__ . '/cadastros.json';
    if (file_exists($file)) { $list = array_reverse(json_decode(file_get_contents($file), true) ?: []); }

    $fev = __DIR__ . '/eventos.json';
    if (file_exists($fev)) {
        $eventos = json_decode(file_get_contents($fev), true) ?: [];
        usort($eventos, fn($a,$b) => strcmp($b['data_inicio'], $a['data_inicio']));
    }
}
$total         = count($list);
$total_eventos = count($eventos);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard</title>
<link rel="stylesheet" href="/assets/ser.css">
<style>
/* Override de paleta — dashboard usa cores mais escuras */
:root{
  --bg:#070C17;--surface:#0E1520;--surface2:#141D2B;--surface3:#1A2436;
  --border:rgba(255,255,255,0.07);--border-active:rgba(255,255,255,0.16);
  --text:#EAE6DF;--text-muted:rgba(234,230,223,0.38);--text-dim:rgba(234,230,223,0.58);
  --gold-light:#DDB96A;--gold-glow:rgba(201,168,76,0.1);
  --green:#27AE60;--red:#C0392B;--radius:14px;
}
body{padding:1.5rem 1.25rem;}
.login-card{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:2.5rem 2rem;width:100%;max-width:360px;}
.login-card h1{font-size:1.4rem;margin-bottom:.3rem;}
.login-card p{color:var(--text-dim);font-size:.9rem;margin-bottom:1.8rem;}
.login-card label{display:block;font-size:.82rem;color:var(--text-dim);margin-bottom:.4rem;}
.login-card input[type=password]{width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:9px;padding:.7rem 1rem;color:var(--text);font-size:1rem;font-family:inherit;outline:none;transition:border .2s;}
.login-card input[type=password]:focus{border-color:var(--gold);}
.login-card button{margin-top:1.2rem;width:100%;padding:.8rem;background:var(--gold);color:#070C17;border:none;border-radius:9px;font-size:1rem;font-weight:600;cursor:pointer;font-family:inherit;transition:opacity .2s;}
.login-card button:hover{opacity:.88;}
.err{margin-top:.8rem;color:var(--red);font-size:.88rem;text-align:center;}

/* topbar/dash — herdado de ser.css */
.dash{max-width:1100px;margin:0 auto;width:100%;}

/* tabs — herdado de ser.css */

/* BUTTONS */
.btn{padding:.48rem .95rem;border-radius:8px;font-size:.84rem;font-weight:500;cursor:pointer;font-family:inherit;border:none;transition:opacity .15s;display:inline-flex;align-items:center;gap:.35rem;white-space:nowrap;}
.btn:hover{opacity:.78;}
.btn-gold{background:var(--gold);color:#070C17;}
.btn-green{background:var(--green);color:#fff;}
.btn-red{background:var(--red);color:#fff;}
.btn-blue{background:var(--blue);color:#fff;}
.btn-outline{background:transparent;border:1px solid var(--border-active);color:var(--text-dim);}
.btn-sm{padding:.28rem .65rem;font-size:.78rem;}
.btn-xs{padding:.17rem .44rem;font-size:.7rem;border-radius:20px;font-weight:500;letter-spacing:.01em;}

/* stats — herdado de ser.css */
.stats{margin-bottom:1.1rem;}

/* TOOLBAR */
.toolbar{display:flex;gap:.65rem;margin-bottom:.8rem;flex-wrap:wrap;align-items:center;}
.toolbar input{flex:1;min-width:180px;background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:.58rem .9rem;color:var(--text);font-size:.88rem;font-family:inherit;outline:none;transition:border .18s;}
.toolbar input:focus{border-color:var(--gold);}
.toolbar input::placeholder{color:var(--text-muted);}
.toolbar select{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:.58rem .9rem;color:var(--text);font-size:.88rem;font-family:inherit;outline:none;transition:border .18s;cursor:pointer;appearance:none;padding-right:2rem;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='rgba(234,230,223,0.4)' d='M6 8L1 3h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right .65rem center;}
.toolbar select:focus{border-color:var(--gold);}
.toolbar select option{background:var(--surface2);}

/* TABLE */
.table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;overflow-x:auto;width:100%;}
table{width:100%;border-collapse:collapse;table-layout:fixed;}
thead th{text-align:left;padding:.5rem .7rem;font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);border-bottom:1px solid var(--border);}
thead th:nth-child(1){width:34px;}
thead th:nth-child(2){width:34px;}
thead th:nth-child(3){width:22%;}
thead th:nth-child(4){width:20%;}
thead th:nth-child(5){width:100px;}
thead th:nth-child(6){width:105px;}
thead th:nth-child(7){width:88px;}
thead th:nth-child(8){width:148px;}
tbody tr{border-bottom:1px solid var(--border);transition:background .12s;}
tbody tr:last-child{border-bottom:none;}
tbody tr:hover{background:var(--surface2);}
tbody td{padding:.52rem .7rem;font-size:.86rem;vertical-align:middle;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
tbody td:nth-child(3){white-space:normal;word-break:break-word;}
.wpp-link{color:var(--gold);font-size:.76rem;display:block;margin-top:.08rem;}
.date-col{color:var(--text-dim);font-size:.78rem;}
.email-col{color:var(--text-dim);font-size:.82rem;}
.empty{text-align:center;padding:3rem 1rem;color:var(--text-muted);}
.actions-cell{display:flex;gap:.3rem;align-items:center;}
input[type=checkbox]{accent-color:var(--gold);width:15px;height:15px;cursor:pointer;}

/* CLASSIFICATION BADGES */
.class-badge{display:inline-block;font-size:.69rem;font-weight:500;padding:.17rem .48rem;border-radius:20px;white-space:nowrap;}
.class-interessado{background:rgba(36,113,163,0.14);color:#5dade2;border:1px solid rgba(36,113,163,0.2);}
.class-estudo_biblico{background:rgba(201,168,76,0.13);color:var(--gold-light);border:1px solid rgba(201,168,76,0.2);}
.class-candidato{background:rgba(125,60,152,0.14);color:#bb8fce;border:1px solid rgba(125,60,152,0.2);}
.class-batizado{background:rgba(39,174,96,0.13);color:#58d68d;border:1px solid rgba(39,174,96,0.2);}

/* EVENTOS GRID */
.eventos-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(275px,1fr));gap:.875rem;}
.evento-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem 1.15rem;display:flex;flex-direction:column;gap:.45rem;transition:border-color .18s;}
.evento-card:hover{border-color:var(--border-active);}
.evento-card-title{font-size:.95rem;font-weight:600;}
.evento-card-meta{font-size:.79rem;color:var(--text-dim);display:flex;flex-direction:column;gap:.18rem;}
.evento-card-meta span{display:flex;align-items:center;gap:.35rem;}
.evento-card-desc{font-size:.8rem;color:var(--text-dim);line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.evento-card-footer{display:flex;align-items:center;justify-content:space-between;margin-top:.3rem;flex-wrap:wrap;gap:.4rem;}
.evento-badge{font-size:.7rem;padding:.17rem .5rem;border-radius:20px;font-weight:500;}
.badge-future{background:rgba(36,113,163,0.14);color:#5dade2;border:1px solid rgba(36,113,163,0.2);}
.badge-ongoing{background:rgba(39,174,96,0.13);color:#58d68d;border:1px solid rgba(39,174,96,0.2);}
.badge-past{background:var(--surface2);color:var(--text-muted);border:1px solid var(--border);}
.confirmacoes-count{font-size:.77rem;color:var(--text-dim);}

/* OVERLAY / MODAL */
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:100;align-items:center;justify-content:center;padding:1rem;backdrop-filter:blur(3px);}
.overlay.open{display:flex;}
.modal{background:var(--surface);border:1px solid var(--border-active);border-radius:16px;padding:1.4rem 1.5rem;width:100%;max-width:460px;position:relative;max-height:92vh;overflow-y:auto;}
.modal h2{font-size:1.08rem;font-weight:600;margin-bottom:.2rem;}
.modal .modal-sub{font-size:.82rem;color:var(--text-dim);margin-bottom:.85rem;}
.modal label{display:block;font-size:.79rem;color:var(--text-dim);margin-bottom:.28rem;margin-top:.65rem;}
.modal label:first-of-type{margin-top:0;}
.modal input[type=text],.modal input[type=email],.modal input[type=tel],.modal input[type=datetime-local],.modal textarea{width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:.6rem .85rem;color:var(--text);font-size:.88rem;font-family:inherit;outline:none;transition:border .18s;}
.modal input:focus,.modal textarea:focus{border-color:var(--gold);}
.modal input::placeholder,.modal textarea::placeholder{color:var(--text-muted);}
.modal textarea{resize:vertical;min-height:78px;}
.modal-actions{display:flex;gap:.55rem;margin-top:.85rem;justify-content:flex-end;}
.modal-close{position:absolute;top:.85rem;right:.85rem;background:none;border:none;color:var(--text-dim);font-size:1.15rem;cursor:pointer;line-height:1;transition:color .15s;}
.modal-close:hover{color:var(--text);}
.modal-err{color:var(--red);font-size:.79rem;margin-top:.5rem;min-height:1em;}
.send-status{margin-top:.7rem;font-size:.82rem;min-height:1.2em;}
.send-status.ok{color:var(--green);}
.send-status.err{color:var(--red);}
.modal select{width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:.6rem .85rem;color:var(--text);font-size:.88rem;font-family:inherit;outline:none;transition:border .18s;cursor:pointer;appearance:none;}
.modal select:focus{border-color:var(--gold);}
.modal select option{background:var(--surface2);}
.conf-contact-card{background:var(--surface2);border:1px solid var(--border-active);border-radius:8px;padding:.75rem .9rem;margin-bottom:.2rem;}
.conf-contact-label{font-size:.69rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.25rem;}
.conf-contact-name{font-weight:600;font-size:.95rem;color:var(--text);}
.conf-contact-wpp{font-size:.84rem;color:var(--gold);margin-top:.1rem;}

/* CONFIRMADOS MODAL */
.modal-wide{max-width:620px;}
.conf-table{width:100%;border-collapse:collapse;margin-top:.7rem;}
.conf-table th{text-align:left;padding:.48rem .7rem;font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);border-bottom:1px solid var(--border);}
.conf-table td{padding:.52rem .7rem;font-size:.82rem;border-bottom:1px solid var(--border);color:var(--text-dim);}
.conf-table td:first-child{color:var(--text);font-weight:500;}
.token-chip{font-family:monospace;font-size:.69rem;background:var(--surface2);border:1px solid var(--border);border-radius:5px;padding:.1rem .35rem;color:var(--text-muted);}

/* BROADCAST BAR */
.bcast-bar{display:none;background:var(--surface2);border:1px solid var(--border-active);border-radius:10px;padding:.6rem .9rem;margin-bottom:.8rem;align-items:center;gap:.875rem;flex-wrap:wrap;}
.bcast-bar.show{display:flex;}
.bcast-info{font-size:.84rem;color:var(--text-dim);flex:1;}
.bcast-info strong{color:var(--text);}

/* DISPAROS */
.disp-compose{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:1.2rem 1.4rem;max-width:640px;margin-bottom:1.5rem;}
.disp-compose h3{font-size:.95rem;font-weight:600;margin-bottom:.9rem;}
.disp-compose label{display:block;font-size:.79rem;color:var(--text-dim);margin-bottom:.28rem;margin-top:.65rem;}
.disp-compose label:first-of-type{margin-top:0;}
.disp-compose select,.disp-compose textarea{width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:.6rem .85rem;color:var(--text);font-size:.88rem;font-family:inherit;outline:none;transition:border .18s;}
.disp-compose select:focus,.disp-compose textarea:focus{border-color:var(--gold);}
.disp-compose select{cursor:pointer;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='rgba(234,230,223,0.4)' d='M6 8L1 3h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right .65rem center;padding-right:2rem;}
.disp-compose select option{background:var(--surface2);}
.disp-compose textarea{resize:vertical;min-height:110px;}
.disp-count{font-size:.8rem;color:var(--text-muted);margin:.35rem 0 .1rem;}
.disp-progress{background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:.65rem .9rem;margin-top:.75rem;}
.disp-progress-bar-wrap{background:var(--surface3,#1A2436);border-radius:4px;height:5px;overflow:hidden;margin:.45rem 0 .35rem;}
.disp-progress-bar{background:var(--gold);height:100%;border-radius:4px;transition:width .4s;width:0%;}
.disp-progress-text{font-size:.79rem;color:var(--text-dim);}
.disp-history-title{font-size:.92rem;font-weight:600;margin-bottom:.75rem;}
.disp-empty{color:var(--text-muted);font-size:.86rem;padding:.5rem 0;}
.tag-chip{background:var(--surface3,#1A2436);border:1px solid var(--border-active);border-radius:6px;padding:.18rem .55rem;font-size:.75rem;color:var(--gold-light,#DDB96A);cursor:pointer;font-family:inherit;transition:background .15s;}
.tag-chip:hover{background:var(--gold-glow,rgba(201,168,76,0.1));}
.tag-chip code{font-family:monospace;font-size:.78rem;}
</style>
</head>
<body>

<?php if (!$auth): ?>
<div class="login-wrap">
  <div class="login-card">
    <h1>Dashboard</h1>
    <p>Acesse para acompanhar os cadastros.</p>
    <form method="POST">
      <label>Senha</label>
      <input type="password" name="senha" autofocus placeholder="••••••••">
      <button type="submit">Entrar</button>
      <?php if (!empty($erro_login)): ?>
        <p class="err"><?= htmlspecialchars($erro_login) ?></p>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php else: ?>

<!-- ── MODAIS: CADASTROS ── -->
<div class="overlay" id="ov-msg" onclick="if(event.target===this)closeMsg()">
  <div class="modal">
    <button class="modal-close" onclick="closeMsg()">✕</button>
    <h2 id="msg-title">Enviar mensagem</h2>
    <p class="modal-sub" id="msg-sub"></p>
    <label>Mensagem</label>
    <textarea id="modal-msg" placeholder="Digite a mensagem… use {nome} para personalizar"></textarea>
    <div class="send-status" id="send-status"></div>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="closeMsg()">Cancelar</button>
      <button class="btn btn-green" id="btn-send" onclick="enviar()">Enviar via WhatsApp</button>
    </div>
  </div>
</div>

<div class="overlay" id="ov-add" onclick="if(event.target===this)closeAdd()">
  <div class="modal">
    <button class="modal-close" onclick="closeAdd()">✕</button>
    <h2>Adicionar cadastro</h2>
    <p class="modal-sub">Preencha os dados do novo contato.</p>
    <label>Nome completo</label>
    <input type="text" id="add-nome" placeholder="Nome completo">
    <label>E-mail</label>
    <input type="email" id="add-email" placeholder="email@exemplo.com">
    <label>WhatsApp (somente números)</label>
    <input type="tel" id="add-wpp" placeholder="5592999999999">
    <label>Classificação</label>
    <select id="add-classificacao">
      <option value="interessado">Interessado</option>
      <option value="estudo_biblico">Estudo Bíblico</option>
      <option value="candidato">Candidato</option>
      <option value="batizado">Batizado</option>
    </select>
    <div class="modal-err" id="add-err"></div>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="closeAdd()">Cancelar</button>
      <button class="btn btn-gold" id="btn-add-save" onclick="salvarAdd()">Adicionar</button>
    </div>
  </div>
</div>

<div class="overlay" id="ov-edit" onclick="if(event.target===this)closeEdit()">
  <div class="modal">
    <button class="modal-close" onclick="closeEdit()">✕</button>
    <h2>Editar cadastro</h2>
    <p class="modal-sub">Altere os dados e salve.</p>
    <input type="hidden" id="edit-wpp-orig">
    <label>Nome completo</label>
    <input type="text" id="edit-nome" placeholder="Nome completo">
    <label>E-mail</label>
    <input type="email" id="edit-email" placeholder="email@exemplo.com">
    <label>WhatsApp (somente números)</label>
    <input type="tel" id="edit-wpp" placeholder="5592999999999">
    <label>Classificação</label>
    <select id="edit-classificacao">
      <option value="interessado">Interessado</option>
      <option value="estudo_biblico">Estudo Bíblico</option>
      <option value="candidato">Candidato</option>
      <option value="batizado">Batizado</option>
    </select>
    <div class="modal-err" id="edit-err"></div>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="closeEdit()">Cancelar</button>
      <button class="btn btn-gold" id="btn-edit-save" onclick="salvarEdicao()">Salvar alterações</button>
    </div>
  </div>
</div>

<div class="overlay" id="ov-del" onclick="if(event.target===this)closeDel()">
  <div class="modal" style="max-width:400px">
    <button class="modal-close" onclick="closeDel()">✕</button>
    <h2>Excluir cadastro</h2>
    <p class="modal-sub" id="del-sub"></p>
    <p style="font-size:.9rem;color:var(--text-dim)">Esta ação não pode ser desfeita.</p>
    <div class="modal-err" id="del-err"></div>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="closeDel()">Cancelar</button>
      <button class="btn btn-red" id="btn-del-confirm" onclick="confirmarExclusao()">Excluir</button>
    </div>
  </div>
</div>

<!-- ── MODAIS: EVENTOS ── -->
<div class="overlay" id="ov-ev-add" onclick="if(event.target===this)closeEvAdd()">
  <div class="modal">
    <button class="modal-close" onclick="closeEvAdd()">✕</button>
    <h2>Novo evento</h2>
    <p class="modal-sub">Preencha os dados do evento.</p>
    <label>Título *</label>
    <input type="text" id="ev-add-titulo" placeholder="Nome do evento">
    <label>Início *</label>
    <input type="datetime-local" id="ev-add-inicio">
    <label>Fim</label>
    <input type="datetime-local" id="ev-add-fim">
    <label>Local</label>
    <input type="text" id="ev-add-local" placeholder="Endereço ou nome do local">
    <label>Descrição</label>
    <textarea id="ev-add-desc" placeholder="Detalhes do evento (opcional)"></textarea>
    <div class="modal-err" id="ev-add-err"></div>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="closeEvAdd()">Cancelar</button>
      <button class="btn btn-gold" id="btn-ev-add-save" onclick="salvarEvento()">Criar evento</button>
    </div>
  </div>
</div>

<div class="overlay" id="ov-ev-edit" onclick="if(event.target===this)closeEvEdit()">
  <div class="modal">
    <button class="modal-close" onclick="closeEvEdit()">✕</button>
    <h2>Editar evento</h2>
    <p class="modal-sub">Altere os dados do evento.</p>
    <input type="hidden" id="ev-edit-id">
    <label>Título *</label>
    <input type="text" id="ev-edit-titulo" placeholder="Nome do evento">
    <label>Início *</label>
    <input type="datetime-local" id="ev-edit-inicio">
    <label>Fim</label>
    <input type="datetime-local" id="ev-edit-fim">
    <label>Local</label>
    <input type="text" id="ev-edit-local" placeholder="Endereço ou nome do local">
    <label>Descrição</label>
    <textarea id="ev-edit-desc" placeholder="Detalhes do evento (opcional)"></textarea>
    <div class="modal-err" id="ev-edit-err"></div>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="closeEvEdit()">Cancelar</button>
      <button class="btn btn-gold" id="btn-ev-edit-save" onclick="salvarEdicaoEvento()">Salvar alterações</button>
    </div>
  </div>
</div>

<div class="overlay" id="ov-ev-del" onclick="if(event.target===this)closeEvDel()">
  <div class="modal" style="max-width:400px">
    <button class="modal-close" onclick="closeEvDel()">✕</button>
    <h2>Excluir evento</h2>
    <p class="modal-sub" id="ev-del-sub"></p>
    <p style="font-size:.9rem;color:var(--text-dim)">Todas as confirmações serão perdidas.</p>
    <div class="modal-err" id="ev-del-err"></div>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="closeEvDel()">Cancelar</button>
      <button class="btn btn-red" id="btn-ev-del-confirm" onclick="confirmarExclusaoEvento()">Excluir</button>
    </div>
  </div>
</div>

<div class="overlay" id="ov-conf" onclick="if(event.target===this)closeConf()">
  <div class="modal modal-wide">
    <button class="modal-close" onclick="closeConf()">✕</button>
    <h2 id="conf-title">Confirmados</h2>
    <p class="modal-sub" id="conf-sub"></p>
    <div id="conf-body">
      <p style="color:var(--text-muted);font-size:.9rem">Carregando…</p>
    </div>
    <div class="modal-actions" style="margin-top:1rem">
      <button class="btn btn-outline" onclick="closeConf()">Fechar</button>
    </div>
  </div>
</div>

<!-- ── MODAL: CONFIRMAR PRESENÇA EM EVENTO ── -->
<div class="overlay" id="ov-confirmar-evento" onclick="if(event.target===this)closeConfirmarEvento()">
  <div class="modal">
    <button class="modal-close" onclick="closeConfirmarEvento()">✕</button>
    <h2>Confirmar presença</h2>
    <p class="modal-sub">Verifique os dados do contato antes de enviar o QR Code.</p>
    <div class="conf-contact-card">
      <div class="conf-contact-label">Contato</div>
      <div class="conf-contact-name" id="conf-ev-nome"></div>
      <div class="conf-contact-wpp" id="conf-ev-wpp"></div>
    </div>
    <label style="margin-top:1rem">Selecione o evento</label>
    <select id="conf-ev-select">
      <option value="">— Selecione um evento —</option>
    </select>
    <div class="modal-err" id="conf-ev-err"></div>
    <div class="send-status" id="conf-ev-status"></div>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="closeConfirmarEvento()">Cancelar</button>
      <button class="btn btn-green" id="btn-conf-ev-send" onclick="enviarConfirmacaoEvento()">Enviar QR Code</button>
    </div>
  </div>
</div>

<!-- ── DASHBOARD ── -->
<div class="dash">
  <div class="topbar">
    <h1>Dashboard</h1>
    <div class="topbar-actions">
      <a href="?logout=1"><button class="btn btn-outline">Sair</button></a>
    </div>
  </div>

  <!-- TABS -->
  <div class="tabs">
    <button class="tab-btn active" id="tab-btn-cadastros" onclick="showTab('cadastros')">Cadastros</button>
    <button class="tab-btn" id="tab-btn-eventos" onclick="showTab('eventos')">Eventos</button>
    <button class="tab-btn" id="tab-btn-disparos" onclick="showTab('disparos')">Disparos</button>
  </div>

  <!-- ── TAB: CADASTROS ── -->
  <div class="tab-panel active" id="tab-cadastros">
    <div style="display:flex;justify-content:flex-end;gap:.75rem;margin-bottom:1.25rem;flex-wrap:wrap;">
      <button class="btn btn-gold" onclick="openAdd()">+ Adicionar</button>
      <a href="?export=1"><button class="btn btn-outline">Exportar CSV</button></a>
    </div>

    <div class="stats">
      <div class="stat">
        <div class="stat-val"><?= $total ?></div>
        <div class="stat-lbl">Total de cadastros</div>
      </div>
      <?php if ($total > 0):
        $hoje = array_filter($list, fn($r) => str_starts_with($r['at'], date('Y-m-d')));
      ?>
      <div class="stat">
        <div class="stat-val"><?= count($hoje) ?></div>
        <div class="stat-lbl">Hoje</div>
      </div>
      <?php endif; ?>
    </div>

    <div class="bcast-bar" id="bcast-bar">
      <span class="bcast-info"><strong id="bcast-count">0</strong> selecionados</span>
      <button class="btn btn-green btn-sm" onclick="openBroadcast()">Enviar mensagem para selecionados</button>
      <button class="btn btn-outline btn-sm" onclick="clearSelection()">Limpar seleção</button>
    </div>

    <div class="toolbar">
      <input type="text" id="busca" placeholder="Buscar por nome, e-mail ou WhatsApp…" oninput="filtrar()">
      <select id="filtro-class" onchange="filtrar()">
        <option value="">Todas as classificações</option>
        <option value="interessado">Interessado</option>
        <option value="estudo_biblico">Estudo Bíblico</option>
        <option value="candidato">Candidato</option>
        <option value="batizado">Batizado</option>
      </select>
      <select id="filtro-periodo" onchange="filtrar()">
        <option value="">Todo período</option>
        <option value="hoje">Hoje</option>
        <option value="semana">Esta semana</option>
        <option value="mes">Este mês</option>
      </select>
      <?php if ($total > 0): ?>
      <button class="btn btn-outline btn-sm" onclick="toggleSelectAll()">Selecionar todos</button>
      <?php endif; ?>
    </div>

    <div class="table-wrap">
      <?php if ($total === 0): ?>
        <p class="empty">Nenhum cadastro ainda.</p>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th><input type="checkbox" id="chk-all" onchange="selectAll(this.checked)"></th>
            <th>#</th><th>Nome</th><th>E-mail</th><th>WhatsApp</th><th>Classificação</th><th>Data</th><th></th>
          </tr>
        </thead>
        <tbody id="tabela">
          <?php foreach ($list as $i => $r):
            $wpp      = $r['wpp'];
            $num      = str_starts_with($wpp,'55') ? $wpp : '55'.$wpp;
            $dt       = date('d/m/Y H:i', strtotime($r['at']));
            $nome_esc = htmlspecialchars($r['nome']  ?? '');
            $email_esc= htmlspecialchars($r['email'] ?? '');
            $class_esc= htmlspecialchars($r['classificacao'] ?? 'interessado');
          ?>
          <tr data-busca="<?= htmlspecialchars(strtolower(($r['nome']??'').' '.($r['email']??'').' '.$r['wpp'])) ?>"
              data-wpp="<?= htmlspecialchars($num) ?>"
              data-wpp-raw="<?= htmlspecialchars($wpp) ?>"
              data-nome="<?= $nome_esc ?>"
              data-email="<?= $email_esc ?>"
              data-classificacao="<?= $class_esc ?>"
              data-at="<?= htmlspecialchars($r['at']) ?>">
            <td><input type="checkbox" class="row-chk" onchange="updateSelection()"></td>
            <td><?= $total - $i ?></td>
            <td><?= $nome_esc ?></td>
            <td class="email-col"><?= $email_esc ?: '<span style="opacity:.35">—</span>' ?></td>
            <td>
              <?= htmlspecialchars($r['wpp']) ?><br>
              <a class="wpp-link" href="https://wa.me/<?= $num ?>" target="_blank">Abrir ↗</a>
            </td>
            <td>
              <?php
                $cl = $r['classificacao'] ?? 'interessado';
                $labels = ['interessado'=>'Interessado','estudo_biblico'=>'Estudo Bíblico','candidato'=>'Candidato','batizado'=>'Batizado'];
                echo '<span class="class-badge class-'.htmlspecialchars($cl).'">'.htmlspecialchars($labels[$cl] ?? $cl).'</span>';
              ?>
            </td>
            <td class="date-col"><?= $dt ?></td>
            <td>
              <div class="actions-cell">
                <button class="btn btn-green btn-xs" onclick="openConfirmarEvento('<?= htmlspecialchars($wpp, ENT_QUOTES) ?>','<?= $num ?>','<?= addslashes($r['nome']??'') ?>')">Evento</button>
                <button class="btn btn-gold btn-xs" onclick="openEdit(this)">Editar</button>
                <button class="btn btn-red btn-xs" onclick="openDel('<?= htmlspecialchars($wpp, ENT_QUOTES) ?>','<?= addslashes($r['nome']??$r['wpp']) ?>')">Excluir</button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div><!-- /tab-cadastros -->

  <!-- ── TAB: EVENTOS ── -->
  <div class="tab-panel" id="tab-eventos">
    <div style="display:flex;justify-content:flex-end;margin-bottom:1.25rem;">
      <button class="btn btn-gold" onclick="openEvAdd()">+ Novo evento</button>
    </div>

    <div class="stats">
      <div class="stat">
        <div class="stat-val" id="stat-total-ev"><?= $total_eventos ?></div>
        <div class="stat-lbl">Total de eventos</div>
      </div>
      <?php
        $now_str  = date('Y-m-d\TH:i');
        $proximos = array_filter($eventos, fn($ev) => ($ev['data_fim'] ?: $ev['data_inicio']) >= $now_str);
      ?>
      <div class="stat">
        <div class="stat-val"><?= count($proximos) ?></div>
        <div class="stat-lbl">Próximos / em curso</div>
      </div>
    </div>

    <?php if ($total_eventos === 0): ?>
      <div class="table-wrap"><p class="empty">Nenhum evento cadastrado.</p></div>
    <?php else: ?>
    <div class="eventos-grid" id="eventos-grid">
      <?php foreach ($eventos as $ev):
        $inicio   = $ev['data_inicio'] ?? '';
        $fim      = $ev['data_fim']    ?? '';
        $dt_ini   = $inicio ? date('d/m/Y H:i', strtotime($inicio)) : '';
        $dt_fim   = $fim    ? date('d/m/Y H:i', strtotime($fim))    : '';
        // Status: futuro / em curso / encerrado
        $now_str  = date('Y-m-d\TH:i');
        if ($inicio > $now_str) { $status = 'future'; $label = 'Próximo'; }
        elseif (!$fim || $fim >= $now_str) { $status = 'ongoing'; $label = 'Em curso'; }
        else { $status = 'past'; $label = 'Encerrado'; }
        $n_conf  = count($ev['confirmacoes'] ?? []);
        $ev_id   = htmlspecialchars($ev['id']);
        $ev_tit  = htmlspecialchars($ev['titulo']);
        $ev_desc = htmlspecialchars($ev['descricao'] ?? '');
        $ev_loc  = htmlspecialchars($ev['local'] ?? '');
      ?>
      <div class="evento-card" id="ev-card-<?= $ev_id ?>">
        <div class="evento-card-title"><?= $ev_tit ?></div>
        <div class="evento-card-meta">
          <span>📅 <?= $dt_ini ?><?= $dt_fim ? ' → ' . $dt_fim : '' ?></span>
          <?php if ($ev_loc): ?><span>📍 <?= $ev_loc ?></span><?php endif; ?>
        </div>
        <?php if ($ev_desc): ?><div class="evento-card-desc"><?= $ev_desc ?></div><?php endif; ?>
        <div class="evento-card-footer">
          <span class="evento-badge <?= $status === 'future' ? 'badge-future' : ($status === 'ongoing' ? 'badge-ongoing' : 'badge-past') ?>"><?= $label ?></span>
          <span class="confirmacoes-count" id="ev-conf-count-<?= $ev_id ?>"><?= $n_conf ?> confirmado<?= $n_conf !== 1 ? 's' : '' ?></span>
        </div>
        <div class="actions-cell" style="margin-top:.25rem">
          <button class="btn btn-blue btn-sm"
            data-ev-id="<?= $ev_id ?>"
            data-ev-titulo="<?= $ev_tit ?>"
            onclick="openConf(this.dataset.evId, this.dataset.evTitulo)">Ver confirmados</button>
          <button class="btn btn-gold btn-sm"
            data-ev='<?= htmlspecialchars(json_encode(['id'=>$ev['id'],'titulo'=>$ev['titulo'],'data_inicio'=>$inicio,'data_fim'=>$fim,'local'=>$ev['local']??'','descricao'=>$ev['descricao']??'']), ENT_QUOTES) ?>'
            onclick="openEvEdit(this)">Editar</button>
          <button class="btn btn-red btn-sm"
            data-ev-id="<?= $ev_id ?>"
            data-ev-titulo="<?= $ev_tit ?>"
            onclick="openEvDel(this.dataset.evId, this.dataset.evTitulo)">Excluir</button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div><!-- /tab-eventos -->

  <!-- ── TAB: DISPAROS ── -->
  <div class="tab-panel" id="tab-disparos">

    <div class="disp-compose">
      <h3>Novo disparo</h3>

      <label>Origem</label>
      <select id="disp-origem" onchange="dispTrocarOrigem()">
        <option value="cadastros">Cadastros</option>
        <option value="evento">Confirmados em evento</option>
      </select>

      <div id="disp-origem-cadastros">
        <label>Filtrar por classificação</label>
        <select id="disp-classificacao" onchange="dispAtualizar()">
          <option value="">Todos os cadastros</option>
          <option value="interessado">Interessado</option>
          <option value="estudo_biblico">Estudo Bíblico</option>
          <option value="candidato">Candidato</option>
          <option value="batizado">Batizado</option>
        </select>
      </div>

      <div id="disp-origem-evento" style="display:none">
        <label>Evento</label>
        <select id="disp-evento" onchange="dispAtualizar()">
          <option value="">— Selecione um evento —</option>
          <?php foreach ($eventos as $ev): ?>
          <option value="<?= htmlspecialchars($ev['id']) ?>">
            <?= htmlspecialchars($ev['titulo']) ?>
            <?php if (!empty($ev['data_inicio'])): ?>
              · <?= date('d/m/Y', strtotime($ev['data_inicio'])) ?>
            <?php endif; ?>
            (<?= count($ev['confirmacoes'] ?? []) ?> confirmado<?= count($ev['confirmacoes'] ?? []) !== 1 ? 's' : '' ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <p class="disp-count" id="disp-count"></p>

      <label>Mensagem</label>
      <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.35rem;">
        <span style="font-size:.75rem;color:var(--text-muted);">Tags disponíveis:</span>
        <button type="button" class="tag-chip" onclick="inserirTag('{nome}')"><code>{nome}</code></button>
      </div>
      <textarea id="disp-msg" placeholder="Digite a mensagem…"></textarea>

      <div class="disp-progress" id="disp-progress" style="display:none">
        <div class="disp-progress-bar-wrap"><div class="disp-progress-bar" id="disp-progress-bar"></div></div>
        <div class="disp-progress-text" id="disp-progress-text"></div>
      </div>

      <div style="display:flex;justify-content:flex-end;margin-top:.9rem;">
        <button class="btn btn-green" id="btn-disp-send" onclick="dispararMensagem()">Disparar via WhatsApp</button>
      </div>
    </div>

    <p class="disp-history-title">Histórico de disparos</p>
    <div id="disp-history"><p class="disp-empty">Carregando…</p></div>

  </div><!-- /tab-disparos -->

</div><!-- /dash -->

<script>
const EVO = { url:'<?= EVO_URL ?>', key:'<?= EVO_KEY ?>', inst:'<?= EVO_INST ?>' };
const CADASTROS_DISP = <?= json_encode(array_map(fn($r) => [
  'nome'          => $r['nome'] ?? '',
  'wpp'           => str_starts_with($r['wpp'] ?? '', '55') ? $r['wpp'] : '55'.($r['wpp'] ?? ''),
  'classificacao' => $r['classificacao'] ?? 'interessado',
], $list), JSON_UNESCAPED_UNICODE) ?>;
const EVENTOS_DISP = <?= json_encode(array_map(fn($ev) => [
  'id'          => $ev['id'],
  'titulo'      => $ev['titulo'],
  'data_inicio' => $ev['data_inicio'] ?? '',
  'data_fim'    => $ev['data_fim']    ?? '',
  'local'       => $ev['local']       ?? '',
], $eventos), JSON_UNESCAPED_UNICODE) ?>;
const EVENTOS_CONF = <?= json_encode(array_map(fn($ev) => [
  'id'     => $ev['id'],
  'titulo' => $ev['titulo'],
  'confs'  => array_values(array_map(fn($c) => [
    'nome' => $c['nome'] ?? '',
    'wpp'  => str_starts_with($c['wpp'] ?? '', '55') ? $c['wpp'] : '55'.($c['wpp'] ?? ''),
  ], $ev['confirmacoes'] ?? [])),
], $eventos), JSON_UNESCAPED_UNICODE) ?>;

/* ── TABS ── */
function showTab(name) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  document.getElementById('tab-btn-' + name).classList.add('active');
  if (name === 'disparos') { dispAtualizar(); loadDispHistory(); }
}

/* ── FILTRO ── */
function filtrar() {
  const q      = document.getElementById('busca').value.toLowerCase().trim();
  const classe = document.getElementById('filtro-class').value;
  const periodo= document.getElementById('filtro-periodo').value;

  const now   = new Date();
  const hoje  = now.toISOString().slice(0, 10);
  const semana = new Date(now); semana.setDate(now.getDate() - 7);
  const mes    = new Date(now); mes.setMonth(now.getMonth() - 1);

  document.querySelectorAll('#tabela tr').forEach(tr => {
    const okBusca  = !q || tr.dataset.busca.includes(q);
    const okClasse = !classe || tr.dataset.classificacao === classe;

    let okPeriodo = true;
    if (periodo && tr.dataset.at) {
      const at = new Date(tr.dataset.at);
      if (periodo === 'hoje')   okPeriodo = tr.dataset.at.slice(0, 10) === hoje;
      if (periodo === 'semana') okPeriodo = at >= semana;
      if (periodo === 'mes')    okPeriodo = at >= mes;
    }

    tr.style.display = (okBusca && okClasse && okPeriodo) ? '' : 'none';
  });
}

/* ── SELEÇÃO ── */
function getChecked() {
  return Array.from(document.querySelectorAll('.row-chk:checked'))
    .map(c => ({ wpp: c.closest('tr').dataset.wpp, nome: c.closest('tr').dataset.nome }));
}
function updateSelection() {
  const n = getChecked().length;
  document.getElementById('bcast-count').textContent = n;
  document.getElementById('bcast-bar').classList.toggle('show', n > 0);
  document.getElementById('chk-all').checked = (n === document.querySelectorAll('.row-chk').length);
}
function selectAll(v) {
  document.querySelectorAll('.row-chk').forEach(c => { if (c.closest('tr').style.display !== 'none') c.checked = v; });
  updateSelection();
}
function toggleSelectAll() {
  const anyUnchecked = [...document.querySelectorAll('.row-chk')].some(c => !c.checked && c.closest('tr').style.display !== 'none');
  selectAll(anyUnchecked);
}
function clearSelection() {
  document.querySelectorAll('.row-chk').forEach(c => c.checked = false);
  document.getElementById('chk-all').checked = false;
  updateSelection();
}

/* ── MODAL MENSAGEM ── */
let _targets = [];
function openSingle(wpp, nome) {
  _targets = [{ wpp, nome }];
  document.getElementById('msg-title').textContent = 'Enviar mensagem';
  document.getElementById('msg-sub').textContent = 'Para: ' + nome + ' (' + wpp + ')';
  document.getElementById('modal-msg').value = '';
  document.getElementById('send-status').textContent = '';
  document.getElementById('send-status').className = 'send-status';
  document.getElementById('ov-msg').classList.add('open');
  setTimeout(() => document.getElementById('modal-msg').focus(), 100);
}
function openBroadcast() {
  _targets = getChecked();
  const n = _targets.length;
  document.getElementById('msg-title').textContent = 'Enviar para ' + n + ' contato' + (n > 1 ? 's' : '');
  document.getElementById('msg-sub').textContent = 'A mensagem será enviada individualmente para cada contato selecionado.';
  document.getElementById('modal-msg').value = '';
  document.getElementById('send-status').textContent = '';
  document.getElementById('send-status').className = 'send-status';
  document.getElementById('ov-msg').classList.add('open');
  setTimeout(() => document.getElementById('modal-msg').focus(), 100);
}
function closeMsg() { document.getElementById('ov-msg').classList.remove('open'); }

async function enviar() {
  const msg = document.getElementById('modal-msg').value.trim();
  if (!msg) { alert('Digite uma mensagem.'); return; }
  const btn = document.getElementById('btn-send');
  const status = document.getElementById('send-status');
  btn.disabled = true; btn.textContent = 'Enviando…';
  status.className = 'send-status'; status.textContent = '';
  let ok = 0, fail = 0;
  for (const t of _targets) {
    try {
      const txt = msg.replace(/\{nome\}/gi, t.nome.split(' ')[0]);
      const r = await fetch(`${EVO.url}/message/sendText/${EVO.inst}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'apikey': EVO.key },
        body: JSON.stringify({ number: t.wpp, text: txt })
      });
      if (r.ok) ok++; else fail++;
    } catch { fail++; }
    if (_targets.length > 1) await new Promise(r => setTimeout(r, 400));
  }
  btn.disabled = false; btn.textContent = 'Enviar via WhatsApp';
  if (fail === 0) {
    status.className = 'send-status ok';
    status.textContent = '✓ Enviado' + (ok > 1 ? ' para ' + ok + ' contatos' : '') + '!';
    setTimeout(closeMsg, 1800);
  } else {
    status.className = 'send-status err';
    status.textContent = `${ok} enviado(s), ${fail} falha(s).`;
  }
}

/* ── MODAL ADICIONAR CADASTRO ── */
function openAdd() {
  document.getElementById('add-nome').value = '';
  document.getElementById('add-email').value = '';
  document.getElementById('add-wpp').value = '';
  document.getElementById('add-err').textContent = '';
  document.getElementById('ov-add').classList.add('open');
  setTimeout(() => document.getElementById('add-nome').focus(), 100);
}
function closeAdd() { document.getElementById('ov-add').classList.remove('open'); }

async function salvarAdd() {
  const nome  = document.getElementById('add-nome').value.trim();
  const email = document.getElementById('add-email').value.trim();
  const wpp   = document.getElementById('add-wpp').value.replace(/\D/g, '');
  const classificacao = document.getElementById('add-classificacao').value;
  const errEl = document.getElementById('add-err');
  if (!nome && !email) { errEl.textContent = 'Informe pelo menos nome ou e-mail.'; return; }
  if (!wpp || wpp.length < 10) { errEl.textContent = 'WhatsApp inválido (mínimo 10 dígitos).'; return; }
  const btn = document.getElementById('btn-add-save');
  btn.disabled = true; btn.textContent = 'Salvando…'; errEl.textContent = '';
  try {
    const r = await fetch('dashboard.php?action=add', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ nome, email, wpp, classificacao }) });
    const d = await r.json();
    if (!d.ok) { errEl.textContent = d.error || 'Erro ao adicionar.'; return; }
    closeAdd(); location.reload();
  } catch { errEl.textContent = 'Erro de conexão.'; }
  finally { btn.disabled = false; btn.textContent = 'Adicionar'; }
}

/* ── MODAL EDITAR CADASTRO ── */
let _editRow = null;
function openEdit(btn) {
  _editRow = btn.closest('tr');
  document.getElementById('edit-wpp-orig').value = _editRow.dataset.wppRaw;
  document.getElementById('edit-nome').value      = _editRow.dataset.nome;
  document.getElementById('edit-email').value     = _editRow.dataset.email;
  document.getElementById('edit-wpp').value       = _editRow.dataset.wppRaw;
  document.getElementById('edit-classificacao').value = _editRow.dataset.classificacao || 'interessado';
  document.getElementById('edit-err').textContent = '';
  document.getElementById('ov-edit').classList.add('open');
  setTimeout(() => document.getElementById('edit-nome').focus(), 100);
}
function closeEdit() { document.getElementById('ov-edit').classList.remove('open'); }

async function salvarEdicao() {
  const orig  = document.getElementById('edit-wpp-orig').value;
  const nome  = document.getElementById('edit-nome').value.trim();
  const email = document.getElementById('edit-email').value.trim();
  const wpp   = document.getElementById('edit-wpp').value.replace(/\D/g, '');
  const classificacao = document.getElementById('edit-classificacao').value;
  const errEl = document.getElementById('edit-err');
  if (!nome && !email) { errEl.textContent = 'Informe pelo menos nome ou e-mail.'; return; }
  if (!wpp || wpp.length < 10) { errEl.textContent = 'WhatsApp inválido (mínimo 10 dígitos).'; return; }
  const btn = document.getElementById('btn-edit-save');
  btn.disabled = true; btn.textContent = 'Salvando…'; errEl.textContent = '';
  try {
    const r = await fetch('dashboard.php?action=edit', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ wpp_original: orig, nome, email, wpp, classificacao }) });
    const d = await r.json();
    if (!d.ok) { errEl.textContent = d.error || 'Erro ao salvar.'; return; }
    const num = wpp.startsWith('55') ? wpp : '55' + wpp;
    _editRow.dataset.wppRaw = wpp; _editRow.dataset.wpp = num;
    _editRow.dataset.nome = nome; _editRow.dataset.email = email; _editRow.dataset.classificacao = classificacao;
    _editRow.dataset.busca = (nome + ' ' + email + ' ' + wpp).toLowerCase();
    const cells = _editRow.querySelectorAll('td');
    cells[2].textContent = nome;
    cells[3].innerHTML   = email || '<span style="opacity:.35">—</span>';
    cells[4].innerHTML   = wpp + '<br><a class="wpp-link" href="https://wa.me/' + num + '" target="_blank">Abrir ↗</a>';
    const labels = {'interessado':'Interessado','estudo_biblico':'Estudo Bíblico','candidato':'Candidato','batizado':'Batizado'};
    cells[5].innerHTML = `<span class="class-badge class-${classificacao}">${labels[classificacao] || classificacao}</span>`;
    const btns = cells[7].querySelectorAll('button');
    btns[0].setAttribute('onclick', `openConfirmarEvento('${wpp}','${num}','${nome.replace(/'/g, "\\'")}')`);
    btns[2].setAttribute('onclick', `openDel('${wpp}','${(nome || wpp).replace(/'/g, "\\'")}')`);
    closeEdit();
  } catch { errEl.textContent = 'Erro de conexão.'; }
  finally { btn.disabled = false; btn.textContent = 'Salvar alterações'; }
}

/* ── MODAL EXCLUIR CADASTRO ── */
let _delWpp = '', _delRow = null;
function openDel(wpp, nome) {
  _delWpp = wpp; _delRow = document.querySelector(`tr[data-wpp-raw="${wpp}"]`);
  document.getElementById('del-sub').textContent = 'Excluir o cadastro de ' + nome + '?';
  document.getElementById('del-err').textContent = '';
  document.getElementById('ov-del').classList.add('open');
}
function closeDel() { document.getElementById('ov-del').classList.remove('open'); }

async function confirmarExclusao() {
  const btn = document.getElementById('btn-del-confirm');
  btn.disabled = true; btn.textContent = 'Excluindo…';
  document.getElementById('del-err').textContent = '';
  try {
    const r = await fetch('dashboard.php?action=delete', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ wpp: _delWpp }) });
    const d = await r.json();
    if (!d.ok) { document.getElementById('del-err').textContent = d.error || 'Erro ao excluir.'; return; }
    if (_delRow) _delRow.remove();
    closeDel();
    const statVal = document.querySelector('.stat-val');
    if (statVal) statVal.textContent = parseInt(statVal.textContent) - 1;
  } catch { document.getElementById('del-err').textContent = 'Erro de conexão.'; }
  finally { btn.disabled = false; btn.textContent = 'Excluir'; }
}

/* ── EVENTOS: ABRIR/FECHAR MODAIS ── */
function openEvAdd() {
  ['ev-add-titulo','ev-add-local','ev-add-desc'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('ev-add-inicio').value = '';
  document.getElementById('ev-add-fim').value    = '';
  document.getElementById('ev-add-err').textContent = '';
  document.getElementById('ov-ev-add').classList.add('open');
  setTimeout(() => document.getElementById('ev-add-titulo').focus(), 100);
}
function closeEvAdd() { document.getElementById('ov-ev-add').classList.remove('open'); }

function openEvEdit(btn) {
  const ev = JSON.parse(btn.dataset.ev);
  document.getElementById('ev-edit-id').value      = ev.id;
  document.getElementById('ev-edit-titulo').value  = ev.titulo;
  document.getElementById('ev-edit-inicio').value  = ev.data_inicio;
  document.getElementById('ev-edit-fim').value     = ev.data_fim;
  document.getElementById('ev-edit-local').value   = ev.local;
  document.getElementById('ev-edit-desc').value    = ev.descricao;
  document.getElementById('ev-edit-err').textContent = '';
  document.getElementById('ov-ev-edit').classList.add('open');
  setTimeout(() => document.getElementById('ev-edit-titulo').focus(), 100);
}
function closeEvEdit() { document.getElementById('ov-ev-edit').classList.remove('open'); }

let _evDelId = '';
function openEvDel(id, titulo) {
  _evDelId = id;
  document.getElementById('ev-del-sub').textContent = 'Excluir o evento "' + titulo + '"?';
  document.getElementById('ev-del-err').textContent = '';
  document.getElementById('ov-ev-del').classList.add('open');
}
function closeEvDel() { document.getElementById('ov-ev-del').classList.remove('open'); }

/* ── EVENTOS: SALVAR ── */
async function salvarEvento() {
  const titulo      = document.getElementById('ev-add-titulo').value.trim();
  const data_inicio = document.getElementById('ev-add-inicio').value;
  const data_fim    = document.getElementById('ev-add-fim').value;
  const local       = document.getElementById('ev-add-local').value.trim();
  const descricao   = document.getElementById('ev-add-desc').value.trim();
  const errEl       = document.getElementById('ev-add-err');
  if (!titulo) { errEl.textContent = 'Informe o título do evento.'; return; }
  if (!data_inicio) { errEl.textContent = 'Informe a data de início.'; return; }
  if (data_fim && data_fim < data_inicio) { errEl.textContent = 'A data de fim não pode ser anterior ao início.'; return; }
  const btn = document.getElementById('btn-ev-add-save');
  btn.disabled = true; btn.textContent = 'Salvando…'; errEl.textContent = '';
  try {
    const r = await fetch('dashboard.php?action=add_event', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ titulo, data_inicio, data_fim, local, descricao }) });
    const d = await r.json();
    if (!d.ok) { errEl.textContent = d.error || 'Erro ao criar evento.'; return; }
    closeEvAdd(); location.reload();
  } catch { errEl.textContent = 'Erro de conexão.'; }
  finally { btn.disabled = false; btn.textContent = 'Criar evento'; }
}

async function salvarEdicaoEvento() {
  const id          = document.getElementById('ev-edit-id').value;
  const titulo      = document.getElementById('ev-edit-titulo').value.trim();
  const data_inicio = document.getElementById('ev-edit-inicio').value;
  const data_fim    = document.getElementById('ev-edit-fim').value;
  const local       = document.getElementById('ev-edit-local').value.trim();
  const descricao   = document.getElementById('ev-edit-desc').value.trim();
  const errEl       = document.getElementById('ev-edit-err');
  if (!titulo) { errEl.textContent = 'Informe o título do evento.'; return; }
  if (!data_inicio) { errEl.textContent = 'Informe a data de início.'; return; }
  if (data_fim && data_fim < data_inicio) { errEl.textContent = 'A data de fim não pode ser anterior ao início.'; return; }
  const btn = document.getElementById('btn-ev-edit-save');
  btn.disabled = true; btn.textContent = 'Salvando…'; errEl.textContent = '';
  try {
    const r = await fetch('dashboard.php?action=edit_event', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id, titulo, data_inicio, data_fim, local, descricao }) });
    const d = await r.json();
    if (!d.ok) { errEl.textContent = d.error || 'Erro ao salvar.'; return; }
    closeEvEdit(); location.reload();
  } catch { errEl.textContent = 'Erro de conexão.'; }
  finally { btn.disabled = false; btn.textContent = 'Salvar alterações'; }
}

async function confirmarExclusaoEvento() {
  const btn = document.getElementById('btn-ev-del-confirm');
  btn.disabled = true; btn.textContent = 'Excluindo…';
  document.getElementById('ev-del-err').textContent = '';
  try {
    const r = await fetch('dashboard.php?action=delete_event', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: _evDelId }) });
    const d = await r.json();
    if (!d.ok) { document.getElementById('ev-del-err').textContent = d.error || 'Erro ao excluir.'; return; }
    const card = document.getElementById('ev-card-' + _evDelId);
    if (card) card.remove();
    closeEvDel();
    const sv = document.getElementById('stat-total-ev');
    if (sv) sv.textContent = parseInt(sv.textContent) - 1;
  } catch { document.getElementById('ev-del-err').textContent = 'Erro de conexão.'; }
  finally { btn.disabled = false; btn.textContent = 'Excluir'; }
}

/* ── VER CONFIRMADOS ── */
async function openConf(id, titulo) {
  document.getElementById('conf-title').textContent = 'Confirmados — ' + titulo;
  document.getElementById('conf-sub').textContent = '';
  document.getElementById('conf-body').innerHTML = '<p style="color:var(--text-muted);font-size:.9rem">Carregando…</p>';
  document.getElementById('ov-conf').classList.add('open');
  try {
    const r = await fetch('dashboard.php?action=get_confirmacoes&id=' + encodeURIComponent(id));
    const d = await r.json();
    if (!d.ok) { document.getElementById('conf-body').innerHTML = '<p style="color:var(--red);font-size:.9rem">' + (d.error||'Erro.') + '</p>'; return; }
    const list = d.confirmacoes;
    document.getElementById('conf-sub').textContent = list.length + ' confirmado' + (list.length !== 1 ? 's' : '');
    if (list.length === 0) {
      document.getElementById('conf-body').innerHTML = '<p style="color:var(--text-muted);font-size:.9rem;text-align:center;padding:1.5rem 0">Nenhuma confirmação ainda.</p>';
      return;
    }
    let html = '<table class="conf-table"><thead><tr><th>#</th><th>Nome</th><th>WhatsApp</th><th>Confirmado em</th><th>Check-in</th></tr></thead><tbody>';
    list.forEach((c, i) => {
      const dt  = new Date(c.confirmed_at).toLocaleString('pt-BR');
      const cin = c.checked_in_at
        ? `<span style="color:var(--green);font-size:.8rem">✓ ${new Date(c.checked_in_at).toLocaleString('pt-BR')}</span>`
        : `<span style="color:var(--text-muted);font-size:.8rem">—</span>`;
      html += `<tr><td>${i+1}</td><td>${esc(c.nome||'—')}</td><td>${esc(c.wpp)}</td><td style="white-space:nowrap;font-size:.8rem">${dt}</td><td>${cin}</td></tr>`;
    });
    html += '</tbody></table>';
    document.getElementById('conf-body').innerHTML = html;
  } catch { document.getElementById('conf-body').innerHTML = '<p style="color:var(--red);font-size:.9rem">Erro de conexão.</p>'; }
}
function closeConf() { document.getElementById('ov-conf').classList.remove('open'); }
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

/* ── CONFIRMAR PRESENÇA EM EVENTO (manual) ── */
let _confEvWppRaw = '', _confEvWppNum = '';
function openConfirmarEvento(wppRaw, wppNum, nome) {
  _confEvWppRaw = wppRaw; _confEvWppNum = wppNum;
  document.getElementById('conf-ev-nome').textContent = nome || '—';
  document.getElementById('conf-ev-wpp').textContent  = 'WhatsApp: ' + wppNum;
  document.getElementById('conf-ev-err').textContent    = '';
  document.getElementById('conf-ev-status').textContent = '';
  document.getElementById('conf-ev-status').className   = 'send-status';
  const btn = document.getElementById('btn-conf-ev-send');
  btn.disabled = false; btn.textContent = 'Enviar QR Code';
  const sel = document.getElementById('conf-ev-select');
  sel.innerHTML = '<option value="">— Selecione um evento —</option>';
  EVENTOS_DISP.forEach(ev => {
    const opt = document.createElement('option');
    opt.value = ev.id;
    const dt = ev.data_inicio ? new Date(ev.data_inicio + ':00').toLocaleDateString('pt-BR') : '';
    opt.textContent = ev.titulo + (dt ? ' · ' + dt : '');
    sel.appendChild(opt);
  });
  document.getElementById('ov-confirmar-evento').classList.add('open');
}
function closeConfirmarEvento() { document.getElementById('ov-confirmar-evento').classList.remove('open'); }

/* ── DISPAROS ── */
let _dispPollTimer = null;

function inserirTag(tag) {
  const ta = document.getElementById('disp-msg');
  const s = ta.selectionStart, e = ta.selectionEnd;
  ta.value = ta.value.slice(0, s) + tag + ta.value.slice(e);
  ta.selectionStart = ta.selectionEnd = s + tag.length;
  ta.focus();
}

function dispTrocarOrigem() {
  const origem = document.getElementById('disp-origem').value;
  document.getElementById('disp-origem-cadastros').style.display = origem === 'cadastros' ? '' : 'none';
  document.getElementById('disp-origem-evento').style.display    = origem === 'evento'    ? '' : 'none';
  dispAtualizar();
}

function dispGetContacts() {
  const origem = document.getElementById('disp-origem').value;
  if (origem === 'evento') {
    const evId = document.getElementById('disp-evento').value;
    if (!evId) return [];
    const ev = EVENTOS_CONF.find(e => e.id === evId);
    return (ev?.confs ?? []).map(c => ({ nome: c.nome, telefone: c.wpp }));
  }
  const cls = document.getElementById('disp-classificacao').value;
  return CADASTROS_DISP.filter(c => !cls || c.classificacao === cls)
                       .map(c => ({ nome: c.nome, telefone: c.wpp }));
}

function dispAtualizar() {
  const n = dispGetContacts().length;
  document.getElementById('disp-count').textContent = n + ' destinatário' + (n !== 1 ? 's' : '');
}

async function dispararMensagem() {
  const msg = document.getElementById('disp-msg').value.trim();
  if (!msg) { alert('Digite uma mensagem.'); return; }
  const contacts = dispGetContacts();
  if (contacts.length === 0) { alert('Nenhum contato para disparar.'); return; }

  const btn = document.getElementById('btn-disp-send');
  const progressEl  = document.getElementById('disp-progress');
  const progressBar = document.getElementById('disp-progress-bar');
  const progressTxt = document.getElementById('disp-progress-text');

  btn.disabled = true; btn.textContent = 'Enviando…';
  progressEl.style.display = 'block';
  progressBar.style.width = '0%';
  progressTxt.textContent = 'Enfileirando…';

  if (_dispPollTimer) { clearTimeout(_dispPollTimer); _dispPollTimer = null; }

  try {
    const r = await fetch('disparar.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ contacts: contacts.map(c => ({ nome: c.nome, telefone: c.wpp })), message: msg, channel: 'whatsapp' })
    });
    const d = await r.json();
    if (!d.token) throw new Error(d.error || 'Erro ao enfileirar');

    const token = d.token, total = d.total;
    progressTxt.textContent = `Aguardando… 0/${total}`;

    const poll = async () => {
      try {
        const sr = await fetch('disparar.php?status=' + token);
        const sd = await sr.json();
        const pct = total > 0 ? Math.round((sd.processed || 0) / total * 100) : 0;
        progressBar.style.width = pct + '%';
        progressTxt.textContent = `Enviando… ${sd.processed || 0}/${total}`;
        if (sd.status === 'done') {
          progressBar.style.width = '100%';
          const ok = sd.sent || 0, fail = sd.failed || 0;
          progressTxt.textContent = fail === 0
            ? `✓ Concluído — ${ok} enviado${ok !== 1 ? 's' : ''}`
            : `Concluído — ${ok} enviado${ok !== 1 ? 's' : ''}, ${fail} falha${fail !== 1 ? 's' : ''}`;
          btn.disabled = false; btn.textContent = 'Disparar via WhatsApp';
          document.getElementById('disp-msg').value = '';
          loadDispHistory();
        } else {
          _dispPollTimer = setTimeout(poll, 2000);
        }
      } catch { _dispPollTimer = setTimeout(poll, 3000); }
    };
    _dispPollTimer = setTimeout(poll, 1500);

  } catch(e) {
    btn.disabled = false; btn.textContent = 'Disparar via WhatsApp';
    progressTxt.textContent = 'Erro: ' + e.message;
  }
}

async function loadDispHistory() {
  const el = document.getElementById('disp-history');
  try {
    const r = await fetch('disparar.php?action=history');
    const list = await r.json();
    if (!Array.isArray(list) || list.length === 0) {
      el.innerHTML = '<p class="disp-empty">Nenhum disparo realizado ainda.</p>';
      return;
    }
    let html = '<div class="table-wrap"><table><thead><tr>'
      + '<th style="width:150px">Data</th><th style="width:55px">Total</th>'
      + '<th style="width:65px">Enviados</th><th style="width:55px">Falhas</th>'
      + '<th style="width:90px">Status</th>'
      + '</tr></thead><tbody>';
    list.forEach(d => {
      const dt = d.started_at ? new Date(d.started_at).toLocaleString('pt-BR') : '—';
      const stColor = d.status === 'done' ? 'var(--green)' : d.status === 'running' ? 'var(--gold)' : 'var(--text-muted)';
      const stLabel = {'done':'Concluído','running':'Processando','pending':'Aguardando'}[d.status] ?? d.status;
      html += `<tr>
        <td class="date-col">${dt}</td>
        <td>${d.total}</td>
        <td style="color:var(--green)">${d.sent}</td>
        <td style="color:${d.failed > 0 ? 'var(--red)' : 'var(--text-muted)'}">${d.failed}</td>
        <td><span style="font-size:.75rem;color:${stColor}">${stLabel}</span></td>
      </tr>`;
    });
    html += '</tbody></table></div>';
    el.innerHTML = html;
  } catch {
    el.innerHTML = '<p style="color:var(--red);font-size:.86rem">Erro ao carregar histórico.</p>';
  }
}

async function enviarConfirmacaoEvento() {
  const evento_id = document.getElementById('conf-ev-select').value;
  const errEl     = document.getElementById('conf-ev-err');
  const statusEl  = document.getElementById('conf-ev-status');
  if (!evento_id) { errEl.textContent = 'Selecione um evento.'; return; }
  const btn = document.getElementById('btn-conf-ev-send');
  btn.disabled = true; btn.textContent = 'Enviando…';
  errEl.textContent = ''; statusEl.textContent = ''; statusEl.className = 'send-status';
  try {
    const r = await fetch('dashboard.php?action=enviar_confirmacao', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ wpp: _confEvWppRaw, evento_id })
    });
    const d = await r.json();
    if (!d.ok) { errEl.textContent = d.error || 'Erro ao confirmar.'; btn.disabled = false; btn.textContent = 'Enviar QR Code'; return; }
    statusEl.className = 'send-status ok';
    statusEl.textContent = '✓ QR Code enviado com sucesso!';
    btn.textContent = 'Enviado!';
    setTimeout(closeConfirmarEvento, 2000);
  } catch {
    errEl.textContent = 'Erro de conexão.';
    btn.disabled = false; btn.textContent = 'Enviar QR Code';
  }
}
</script>
<?php endif; ?>
</body>
</html>

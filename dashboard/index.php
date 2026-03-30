<?php
session_start();

define('ROOT',       dirname(__DIR__));
define('USERS_FILE', __DIR__ . '/users.json');
define('INDEX_HTML', ROOT . '/index.html');

/* ── FUNÇÕES ────────────────────────────────────────────────── */
function loadUsers(): array {
    if (!file_exists(USERS_FILE)) return [];
    return json_decode(file_get_contents(USERS_FILE), true) ?: [];
}
function saveUsers(array $u): void {
    file_put_contents(USERS_FILE, json_encode($u, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
function me(): ?array  { return $_SESSION['dash_user'] ?? null; }
function isSA(): bool  { return (me()['role'] ?? '') === 'superadmin'; }
function canSee(string $s): bool {
    $u = me(); if (!$u) return false;
    return $u['role'] === 'superadmin' || in_array($s, $u['sistemas'] ?? []);
}
function isViewer(): bool { return (me()['role'] ?? '') === 'viewer'; }
function flash(string $t, string $m): void { $_SESSION['_flash'] = ['type'=>$t,'msg'=>$m]; }
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

/* ── AUTH ───────────────────────────────────────────────────── */
if (!empty($_GET['logout'])) { session_destroy(); header('Location: ./'); exit; }

if (!empty($_POST['acao']) && $_POST['acao'] === 'login') {
    $uInput = trim($_POST['usuario'] ?? '');
    $pInput = $_POST['senha'] ?? '';
    $found  = null;
    foreach (loadUsers() as $u) {
        if ($u['ativo'] && $u['usuario'] === $uInput && password_verify($pInput, $u['senha'])) {
            $found = $u; break;
        }
    }
    if ($found) {
        $_SESSION['dash_user'] = [
            'id'       => $found['id'],
            'nome'     => $found['nome'],
            'role'     => $found['role'],
            'sistemas' => $found['sistemas'],
        ];
        header('Location: ./'); exit;
    }
    $erro = 'Usuário ou senha incorretos.';
}

$auth = !empty($_SESSION['dash_user']);

/* ── AÇÕES (autenticado) ────────────────────────────────────── */
if ($auth) {

    // ── Adicionar usuário
    if (isSA() && ($_POST['acao']??'') === 'add_user') {
        $nome    = mb_substr(trim($_POST['nome']    ?? ''), 0, 100);
        $usuario = mb_substr(trim($_POST['usuario'] ?? ''), 0, 50);
        $senha   = $_POST['senha'] ?? '';
        $role    = in_array($_POST['role']??'', ['superadmin','admin','viewer']) ? $_POST['role'] : 'admin';
        $sis     = array_values(array_intersect($_POST['sistemas']??[], ['app','amigos','zap','site']));
        $ativo   = !empty($_POST['ativo']);
        if ($nome && $usuario && strlen($senha) >= 6) {
            $users = loadUsers();
            foreach ($users as $u) {
                if ($u['usuario'] === $usuario) { flash('error','Usuário já existe.'); header('Location: ./?sec=usuarios'); exit; }
            }
            $users[] = ['id'=>uniqid(),'nome'=>$nome,'usuario'=>$usuario,
                        'senha'=>password_hash($senha, PASSWORD_BCRYPT),
                        'role'=>$role,'sistemas'=>$sis,'ativo'=>$ativo,'criado_em'=>date('c')];
            saveUsers($users);
            flash('success', '"'.$nome.'" adicionado com sucesso.');
        } else {
            flash('error', 'Preencha nome, usuário e senha (mín. 6 caracteres).');
        }
        header('Location: ./?sec=usuarios'); exit;
    }

    // ── Editar usuário
    if (isSA() && ($_POST['acao']??'') === 'edit_user') {
        $id   = $_POST['id'] ?? '';
        $nome = mb_substr(trim($_POST['nome']    ?? ''), 0, 100);
        $usu  = mb_substr(trim($_POST['usuario'] ?? ''), 0, 50);
        $role = in_array($_POST['role']??'', ['superadmin','admin','viewer']) ? $_POST['role'] : 'admin';
        $sis  = array_values(array_intersect($_POST['sistemas']??[], ['app','amigos','zap','site']));
        $ativo = !empty($_POST['ativo']);
        $users = loadUsers();
        foreach ($users as &$u) {
            if ($u['id'] === $id) {
                if ($nome) $u['nome']    = $nome;
                if ($usu)  $u['usuario'] = $usu;
                if (!empty($_POST['senha']) && strlen($_POST['senha']) >= 6)
                    $u['senha'] = password_hash($_POST['senha'], PASSWORD_BCRYPT);
                $u['role']     = $role;
                $u['sistemas'] = $sis;
                $u['ativo']    = $ativo;
                break;
            }
        }
        unset($u);
        saveUsers($users);
        // Atualiza sessão se editou a si mesmo
        if ($id === me()['id']) {
            $_SESSION['dash_user']['nome']     = $nome ?: me()['nome'];
            $_SESSION['dash_user']['role']     = $role;
            $_SESSION['dash_user']['sistemas'] = $sis;
        }
        flash('success', 'Usuário atualizado.');
        header('Location: ./?sec=usuarios'); exit;
    }

    // ── Excluir usuário
    if (isSA() && ($_POST['acao']??'') === 'del_user') {
        $id = $_POST['id'] ?? '';
        if ($id === me()['id']) {
            flash('error', 'Você não pode excluir sua própria conta.');
        } else {
            saveUsers(array_values(array_filter(loadUsers(), fn($u) => $u['id'] !== $id)));
            flash('success', 'Usuário removido.');
        }
        header('Location: ./?sec=usuarios'); exit;
    }

    // ── Salvar index.html
    if (canSee('site') && !isViewer() && ($_POST['acao']??'') === 'save_index') {
        $html = $_POST['html_content'] ?? '';
        if (strlen($html) > 10) {
            file_put_contents(INDEX_HTML, $html);
            flash('success', 'index.html salvo com sucesso.');
        } else {
            flash('error', 'Conteúdo muito curto — não salvo.');
        }
        header('Location: ./?sec=editor'); exit;
    }
}

/* ── FLASH ──────────────────────────────────────────────────── */
$flash = $_SESSION['_flash'] ?? null;
unset($_SESSION['_flash']);

/* ── DATA ───────────────────────────────────────────────────── */
$totalAmigos = 0; $byClass = []; $recentCadastros = [];
$eventos = []; $proxEvento = null;
$zapTotal = 0; $totalOptouts = 0; $optoutsData = [];
$logs = []; $lastLog = null;
$indexHtml = '';

if ($auth) {
    if (canSee('amigos')) {
        $cf = ROOT . '/amigos/cadastros.json';
        if (file_exists($cf)) {
            $cadastros = json_decode(file_get_contents($cf), true) ?: [];
            $totalAmigos = count($cadastros);
            foreach ($cadastros as $c) { $cl = $c['classificacao']??'interessado'; $byClass[$cl] = ($byClass[$cl]??0)+1; }
            usort($cadastros, fn($a,$b)=>strcmp($b['at']??'',$a['at']??''));
            $recentCadastros = array_slice($cadastros, 0, 8);
        }
        $ef  = ROOT . '/amigos/eventos.json';
        $now = date('Y-m-d\TH:i');
        if (file_exists($ef)) {
            $eventos = json_decode(file_get_contents($ef), true) ?: [];
            foreach ($eventos as &$ev) {
                $cf2 = $ev['confirmacoes']??[];
                $ev['_nc'] = count($cf2);
                $ev['_nk'] = count(array_filter($cf2, fn($x)=>!empty($x['checked_in_at'])));
                $ini = $ev['data_inicio']??''; $fim = $ev['data_fim']??$ini;
                if ($ini>$now)       { $ev['_st']='future';  $ev['_lb']='Próximo'; }
                elseif ($fim>=$now)  { $ev['_st']='ongoing'; $ev['_lb']='Em curso'; }
                else                 { $ev['_st']='past';    $ev['_lb']='Encerrado'; }
            }
            unset($ev);
            usort($eventos, function($a,$b) use ($now) {
                $aF=($a['_st']??''!=='past'); $bF=($b['_st']??''!=='past');
                if ($aF!==$bF) return $bF?1:-1;
                return $aF ? strcmp($a['data_inicio']??'',$b['data_inicio']??'') : strcmp($b['data_inicio']??'',$a['data_inicio']??'');
            });
            $proxEvento = $eventos[0] ?? null;
        }
    }
    if (canSee('zap')) {
        $zf = ROOT . '/zap/novo-tempo.json';
        if (file_exists($zf)) $zapTotal = count(json_decode(file_get_contents($zf), true) ?: []);
        $of = ROOT . '/zap/optouts.json';
        if (file_exists($of)) { $optoutsData = json_decode(file_get_contents($of), true) ?: []; $totalOptouts = count($optoutsData); }
        $ld = ROOT . '/zap/.logs/';
        if (is_dir($ld)) {
            foreach (glob($ld.'*.json') as $f) { $l = json_decode(file_get_contents($f), true); if ($l) $logs[] = $l; }
            usort($logs, fn($a,$b)=>strcmp($b['started_at']??'',$a['started_at']??''));
        }
        $lastLog = $logs[0] ?? null;
    }
    if (canSee('site') && file_exists(INDEX_HTML)) {
        $indexHtml = file_get_contents(INDEX_HTML);
    }
}

/* ── HELPERS ────────────────────────────────────────────────── */
function fdt(string $s, string $f='d/m/Y H:i'): string {
    try { return (new DateTimeImmutable($s))->setTimezone(new DateTimeZone('America/Manaus'))->format($f); }
    catch (\Throwable) { return $s; }
}
function clLbl(string $c): string {
    return ['interessado'=>'Interessado','estudo_biblico'=>'Est. Bíblico','candidato'=>'Candidato','batizado'=>'Batizado'][$c]??ucfirst($c);
}
function rate(int $s, int $t): string { return $t>0?round($s/$t*100).'%':'—'; }
function roleLbl(string $r): string { return ['superadmin'=>'Superadmin','admin'=>'Admin','viewer'=>'Visualização'][$r]??$r; }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>Central — Comunidade Ser</title>
<link rel="stylesheet" href="/assets/ser.css">
<style>
.hidden{display:none!important}
body{margin:0;padding:0}
/* LAYOUT */
.layout{display:flex;min-height:100vh}
.sidebar{width:220px;flex-shrink:0;background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;bottom:0;left:0;z-index:100;transition:transform .28s cubic-bezier(.22,1,.36,1)}
.main{flex:1;margin-left:220px;padding:1.5rem;min-width:0;max-width:900px}
/* SIDEBAR */
.sb-brand{padding:1.3rem 1.2rem 1.1rem;border-bottom:1px solid var(--border)}
.sb-brand-name{font-family:'Playfair Display',serif;font-size:1.5rem;font-weight:700;color:var(--gold);line-height:1}
.sb-brand-sub{font-size:.6rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em;margin-top:3px}
.sb-user{padding:.6rem 1.2rem .5rem;font-size:.72rem;color:var(--text-muted)}
.sb-user strong{color:var(--text-dim);display:block;font-weight:500}
.sb-nav{flex:1;padding:.5rem;display:flex;flex-direction:column;gap:1px;overflow-y:auto}
.nav-lnk{display:flex;align-items:center;gap:.65rem;padding:.58rem .8rem;border-radius:9px;color:var(--text-dim);font-size:.855rem;font-weight:500;text-decoration:none;cursor:pointer;background:none;border:none;width:100%;text-align:left;font-family:'Outfit',sans-serif;transition:background .13s,color .13s}
.nav-lnk svg{width:16px;height:16px;flex-shrink:0}
.nav-lnk:hover{background:var(--surface2);color:var(--text)}
.nav-lnk.active{background:var(--gold-glow);color:var(--gold);font-weight:600}
.sb-sep{height:1px;background:var(--border);margin:.4rem .5rem}
.sb-footer{padding:.5rem}
/* MOBILE */
.mob-bar{display:none;align-items:center;gap:.75rem;padding:.875rem 1.25rem;margin:-1.5rem -1.5rem 1.5rem;border-bottom:1px solid var(--border);background:var(--surface);position:sticky;top:0;z-index:10}
.mob-bar-title{font-size:.95rem;font-weight:600;color:var(--text);flex:1;margin:0}
.ham{width:34px;height:34px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;cursor:pointer;color:var(--text-muted);display:flex;align-items:center;justify-content:center}
.ham svg{width:17px;height:17px}
.backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:90;backdrop-filter:blur(2px)}
@media(max-width:767px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main{margin-left:0}.mob-bar{display:flex}.backdrop.show{display:block}}
/* SECTIONS */
.section{display:none}.section.active{display:block}
/* PANEL */
.panel{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:1.1rem}
.panel:last-child{margin-bottom:0}
.panel-hd{padding:.875rem 1.2rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.panel-title{font-size:.68rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted)}
.panel-body{padding:1.2rem}
.count-badge{font-size:.7rem;font-weight:600;color:var(--gold);background:var(--gold-glow);border:1px solid rgba(201,168,76,.2);padding:2px 9px;border-radius:20px}
/* SYS CARDS */
.sys-grid{display:grid;grid-template-columns:1fr 1fr;gap:.875rem;margin-bottom:1.1rem}
.sys-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:1.1rem;display:flex;flex-direction:column;gap:.45rem;cursor:pointer;transition:border-color .18s;text-decoration:none}
.sys-card:hover{border-color:var(--border-active)}
.sys-icon{width:34px;height:34px;border-radius:8px;background:var(--gold-glow);border:1px solid rgba(201,168,76,.18);display:flex;align-items:center;justify-content:center}
.sys-icon svg{width:17px;height:17px;color:var(--gold)}
.sys-name{font-size:.875rem;font-weight:600;color:var(--text)}
.sys-desc{font-size:.73rem;color:var(--text-muted);line-height:1.45}
.sys-stat{font-size:1.45rem;font-weight:600;color:var(--gold);line-height:1.1;margin-top:auto}
.sys-lbl{font-size:.65rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em}
.sys-footer{display:flex;justify-content:flex-end;margin-top:.15rem}
/* LOG */
.log-row{display:flex;align-items:center;gap:.75rem;padding:.75rem 1.2rem;border-bottom:1px solid var(--border)}
.log-row:last-child{border-bottom:none}
.log-ch{width:30px;height:30px;border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.log-ch.wpp{background:rgba(46,204,113,.1);color:var(--green)}
.log-ch.eml{background:rgba(36,113,163,.1);color:var(--blue)}
.log-ch svg{width:15px;height:15px}
.log-dt{font-size:.8rem;font-weight:600;color:var(--text)}
.log-mt{font-size:.7rem;color:var(--text-muted);margin-top:1px}
.log-rt{text-align:right;flex-shrink:0;min-width:65px}
.log-sent{font-size:.83rem;font-weight:600;color:var(--green)}
.log-fail{font-size:.67rem;color:var(--red);display:block;margin-top:1px}
/* BADGES */
.badge{display:inline-flex;align-items:center;padding:2px 7px;border-radius:20px;font-size:.68rem;font-weight:600}
.bdg-future{background:rgba(36,113,163,.12);color:var(--blue)}
.bdg-ongoing{background:rgba(46,204,113,.12);color:var(--green)}
.bdg-past{background:rgba(255,255,255,.05);color:var(--text-muted)}
/* ROLE BADGES */
.rbdg{display:inline-flex;padding:2px 8px;border-radius:20px;font-size:.7rem;font-weight:600}
.rbdg-superadmin{background:rgba(201,168,76,.12);color:var(--gold)}
.rbdg-admin{background:rgba(36,113,163,.12);color:var(--blue)}
.rbdg-viewer{background:rgba(255,255,255,.06);color:var(--text-muted)}
/* SISTEMA CHIPS */
.schip{display:inline-flex;padding:1px 6px;border-radius:4px;font-size:.67rem;font-weight:600;margin-right:2px}
.schip-app{background:rgba(201,168,76,.1);color:var(--gold)}
.schip-amigos{background:rgba(46,204,113,.1);color:var(--green)}
.schip-zap{background:rgba(36,113,163,.1);color:var(--blue)}
.schip-site{background:rgba(255,255,255,.05);color:var(--text-muted)}
/* CLASS */
.cls-badge{display:inline-flex;padding:2px 8px;border-radius:20px;font-size:.7rem;font-weight:600}
.cls-interessado{background:rgba(255,255,255,.05);color:var(--text-dim)}
.cls-estudo_biblico{background:rgba(36,113,163,.12);color:var(--blue)}
.cls-candidato{background:rgba(201,168,76,.12);color:var(--gold)}
.cls-batizado{background:rgba(46,204,113,.12);color:var(--green)}
/* EV CARD */
.ev-card{background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.9rem 1rem;margin-bottom:.55rem}
.ev-card:last-child{margin-bottom:0}
.ev-top{display:flex;align-items:flex-start;justify-content:space-between;gap:.5rem;margin-bottom:.3rem}
.ev-title{font-size:.88rem;font-weight:600;color:var(--text)}
.ev-meta{font-size:.71rem;color:var(--text-muted);margin-bottom:.45rem;display:flex;gap:.5rem;flex-wrap:wrap}
.ev-stats{display:flex;gap:.65rem}
.ev-stat{font-size:.77rem;color:var(--text-dim)}
.ev-stat strong{color:var(--text);font-weight:600}
/* TABLE */
.tbl-wrap{overflow-x:auto}
.tbl{width:100%;border-collapse:collapse;font-size:.82rem}
.tbl th{text-align:left;padding:.42rem .7rem;font-size:.66rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);border-bottom:1px solid var(--border);white-space:nowrap}
.tbl td{padding:.48rem .7rem;border-bottom:1px solid var(--border);vertical-align:middle;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:200px}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:var(--surface2)}
/* LINK */
.link-ext{display:inline-flex;align-items:center;gap:.3rem;color:var(--gold);font-size:.75rem;font-weight:500;text-decoration:none;transition:opacity .15s}
.link-ext:hover{opacity:.72}
/* CL BAR */
.cl-bar-wrap{margin-bottom:.9rem}
.cl-bar-label{display:flex;justify-content:space-between;font-size:.74rem;margin-bottom:.28rem}
.cl-bar-label span:first-child{color:var(--text-dim)}
.cl-bar-label span:last-child{color:var(--text-muted)}
.cl-bar{height:4px;background:var(--border);border-radius:3px;overflow:hidden}
.cl-bar-fill{height:100%;border-radius:3px}
/* SITE CARD */
.site-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:1.1rem;margin-bottom:.75rem;display:flex;gap:.9rem;align-items:flex-start}
.site-card:last-child{margin-bottom:0}
.site-card-icon{width:38px;height:38px;border-radius:9px;background:var(--surface2);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.site-card-icon svg{width:18px;height:18px;color:var(--gold)}
.site-card-name{font-size:.875rem;font-weight:600;color:var(--text);margin-bottom:.18rem}
.site-card-desc{font-size:.76rem;color:var(--text-muted);line-height:1.5}
.site-card-links{display:flex;gap:.65rem;flex-wrap:wrap;margin-top:.5rem}
/* EDITOR */
.code-editor{width:100%;min-height:60vh;font-family:monospace;font-size:.8rem;line-height:1.6;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--text);padding:1rem;resize:vertical;outline:none;transition:border .2s;box-sizing:border-box}
.code-editor:focus{border-color:rgba(201,168,76,.4);box-shadow:0 0 0 3px var(--gold-glow)}
/* ALERT */
.alert{padding:.7rem 1rem;border-radius:var(--radius-sm);font-size:.82rem;margin-bottom:1rem}
.alert-success{background:rgba(46,204,113,.1);border:1px solid rgba(46,204,113,.22);color:var(--green)}
.alert-error{background:rgba(231,76,60,.1);border:1px solid rgba(231,76,60,.22);color:var(--red)}
/* FORM GRID */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}
.form-grid .c2{grid-column:span 2}
.f-row{margin-bottom:.7rem}
.f-row label{display:block;font-size:.72rem;font-weight:600;color:var(--gold);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.35rem}
.ckbox-group{display:flex;gap:.75rem;flex-wrap:wrap;padding:.5rem 0}
.ckbox-item{display:flex;align-items:center;gap:.35rem;font-size:.83rem;color:var(--text-dim);cursor:pointer}
.ckbox-item input{accent-color:var(--gold);width:14px;height:14px;cursor:pointer}
/* TOAST */
#toast{position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%) translateY(16px);opacity:0;z-index:300;pointer-events:none;transition:opacity .3s,transform .3s;background:var(--surface);border:1px solid rgba(255,255,255,.1);color:var(--text);padding:.8rem 1.3rem;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.4);font-size:.84rem;font-weight:500;white-space:nowrap;max-width:90vw}
#toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
#toast.t-success{border-color:rgba(46,204,113,.3);color:var(--green)}
#toast.t-error{border-color:rgba(231,76,60,.3);color:var(--red)}
/* LOGIN */
#login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem}
.login-card{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:2.5rem 2rem;width:100%;max-width:360px}
.login-card h1{font-size:1.4rem;margin-bottom:.25rem}
.login-card p{color:var(--text-dim);font-size:.88rem;margin-bottom:1.6rem}
.login-card label{display:block;font-size:.8rem;color:var(--text-dim);margin-bottom:.35rem;margin-top:.85rem}
.login-card input{width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:9px;padding:.7rem 1rem;color:var(--text);font-size:1rem;font-family:inherit;outline:none;transition:border .2s;box-sizing:border-box}
.login-card input:focus{border-color:var(--gold)}
.login-card button{margin-top:1.2rem;width:100%;padding:.8rem;background:var(--gold);color:#070C17;border:none;border-radius:9px;font-size:1rem;font-weight:600;cursor:pointer;font-family:inherit;transition:opacity .2s}
.login-card button:hover{opacity:.88}
.login-err{margin-top:.7rem;color:var(--red);font-size:.84rem;text-align:center}
.empty-msg{text-align:center;padding:2rem 1rem;color:var(--text-muted);font-size:.8rem;text-transform:uppercase;letter-spacing:.04em}
</style>
</head>
<body>

<?php if (!$auth): ?>
<!-- ── LOGIN ──────────────────────────────────────────────────── -->
<div id="login-wrap">
  <div class="login-card">
    <h1>Central SER</h1>
    <p>Painel de controle central da Comunidade Ser.</p>
    <form method="POST">
      <input type="hidden" name="acao" value="login">
      <label>Usuário</label>
      <input type="text" name="usuario" autofocus autocomplete="username" placeholder="admin">
      <label>Senha</label>
      <input type="password" name="senha" autocomplete="current-password" placeholder="••••••••">
      <button type="submit">Entrar</button>
      <?php if (!empty($erro)): ?><p class="login-err"><?= esc($erro) ?></p><?php endif; ?>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ── DASHBOARD ──────────────────────────────────────────────── -->
<div class="layout">

  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <div class="sb-brand">
      <div class="sb-brand-name">SER</div>
      <div class="sb-brand-sub">Central de Controle</div>
    </div>
    <div class="sb-user">
      <strong><?= esc(me()['nome'] ?? '') ?></strong>
      <span class="rbdg rbdg-<?= esc(me()['role']??'') ?>" style="margin-top:3px"><?= roleLbl(me()['role']??'') ?></span>
    </div>
    <nav class="sb-nav">
      <button class="nav-lnk active" id="nl-overview" onclick="showSec('overview')">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1" stroke-linecap="round"/><rect x="14" y="3" width="7" height="7" rx="1" stroke-linecap="round"/><rect x="3" y="14" width="7" height="7" rx="1" stroke-linecap="round"/><rect x="14" y="14" width="7" height="7" rx="1" stroke-linecap="round"/></svg>
        Visão Geral
      </button>
      <?php if (canSee('app')): ?>
      <button class="nav-lnk" id="nl-app" onclick="showSec('app')">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        App — Membros
      </button>
      <?php endif; ?>
      <?php if (canSee('amigos')): ?>
      <button class="nav-lnk" id="nl-amigos" onclick="showSec('amigos')">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
        Amigos
      </button>
      <?php endif; ?>
      <?php if (canSee('zap')): ?>
      <button class="nav-lnk" id="nl-zap" onclick="showSec('zap')">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/></svg>
        Zap
      </button>
      <?php endif; ?>
      <?php if (canSee('site')): ?>
      <button class="nav-lnk" id="nl-site" onclick="showSec('site')">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        Site
      </button>
      <?php endif; ?>
      <div class="sb-sep"></div>
      <?php if (isSA()): ?>
      <button class="nav-lnk" id="nl-usuarios" onclick="showSec('usuarios')">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
        Usuários
      </button>
      <?php endif; ?>
      <?php if (canSee('site') && !isViewer()): ?>
      <button class="nav-lnk" id="nl-editor" onclick="showSec('editor')">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
        Editor do Site
      </button>
      <?php endif; ?>
    </nav>
    <div class="sb-sep"></div>
    <div class="sb-footer">
      <a href="?logout=1" class="nav-lnk" style="color:var(--text-muted)">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
        Sair
      </a>
    </div>
  </aside>

  <div class="backdrop" id="backdrop" onclick="closeSb()"></div>

  <!-- MAIN -->
  <div class="main">
    <div class="mob-bar">
      <button class="ham" onclick="toggleSb()">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
      </button>
      <p class="mob-bar-title" id="mob-title">Visão Geral</p>
    </div>

    <!-- ═══════════════════════════════════════════ -->
    <!-- VISÃO GERAL                                  -->
    <!-- ═══════════════════════════════════════════ -->
    <div class="section active" id="sec-overview">
      <div class="stats" style="margin-bottom:1.1rem">
        <?php if (canSee('app')): ?>
        <div class="stat"><div class="stat-val" id="stat-app-ov">…</div><div class="stat-lbl">Membros (App)</div></div>
        <?php endif; ?>
        <?php if (canSee('amigos')): ?>
        <div class="stat"><div class="stat-val"><?= $totalAmigos ?></div><div class="stat-lbl">Cadastros (Amigos)</div></div>
        <?php endif; ?>
        <?php if (canSee('zap')): ?>
        <div class="stat"><div class="stat-val"><?= $zapTotal ?></div><div class="stat-lbl">Contatos (Zap)</div></div>
        <div class="stat"><div class="stat-val" style="<?= $totalOptouts>0?'color:var(--red)':'' ?>"><?= $totalOptouts ?></div><div class="stat-lbl">Opt-outs</div></div>
        <?php endif; ?>
      </div>

      <div class="sys-grid">
        <?php if (canSee('app')): ?>
        <div class="sys-card" onclick="showSec('app')">
          <div class="sys-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg></div>
          <div class="sys-name">App — Membros</div>
          <div class="sys-desc">Perfis, aniversários automáticos, comunicação WhatsApp e e-mail</div>
          <div><div class="sys-stat" id="sc-app">…</div><div class="sys-lbl">membros</div></div>
          <div class="sys-footer"><a href="/app/admin.html" class="link-ext" onclick="event.stopPropagation()" target="_blank">Admin ↗</a></div>
        </div>
        <?php endif; ?>
        <?php if (canSee('amigos')): ?>
        <div class="sys-card" onclick="showSec('amigos')">
          <div class="sys-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg></div>
          <div class="sys-name">Amigos</div>
          <div class="sys-desc">Cadastros, eventos, confirmações de presença e check-in</div>
          <div><div class="sys-stat"><?= $totalAmigos ?></div><div class="sys-lbl">cadastros</div></div>
          <div class="sys-footer"><a href="/amigos/dashboard.php" class="link-ext" onclick="event.stopPropagation()" target="_blank">Dashboard ↗</a></div>
        </div>
        <?php endif; ?>
        <?php if (canSee('zap')): ?>
        <div class="sys-card" onclick="showSec('zap')">
          <div class="sys-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/></svg></div>
          <div class="sys-name">Zap</div>
          <div class="sys-desc">Disparos em massa, opt-outs e histórico de transmissões</div>
          <div><div class="sys-stat"><?= $zapTotal ?></div><div class="sys-lbl">contatos</div></div>
          <div class="sys-footer"><a href="/zap/" class="link-ext" onclick="event.stopPropagation()" target="_blank">Transmissão ↗</a></div>
        </div>
        <?php endif; ?>
        <?php if (canSee('site')): ?>
        <div class="sys-card" onclick="showSec('site')">
          <div class="sys-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
          <div class="sys-name">Site</div>
          <div class="sys-desc">Página principal e landing pages da Comunidade Ser</div>
          <div><div class="sys-stat" style="font-size:1rem;margin-top:.4rem">comunidadeser.com</div><div class="sys-lbl">domínio</div></div>
          <div class="sys-footer"><a href="/" class="link-ext" onclick="event.stopPropagation()" target="_blank">Abrir ↗</a></div>
        </div>
        <?php endif; ?>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.875rem">
        <?php if (canSee('zap') && $lastLog): ?>
        <div class="panel">
          <div class="panel-hd"><span class="panel-title">Último Disparo</span><span class="badge <?= ($lastLog['status']??'')==='done'?'bdg-ongoing':'bdg-future' ?>"><?= ($lastLog['status']??'')==='done'?'Concluído':'Pendente' ?></span></div>
          <div style="padding:.875rem 1.1rem">
            <?php $ck=($lastLog['channel']??'')==='email'?'eml':'wpp'; ?>
            <div style="display:flex;align-items:center;gap:.65rem;margin-bottom:.7rem">
              <div class="log-ch <?= $ck ?>"><?php if($ck==='wpp'): ?><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" stroke-linecap="round" stroke-linejoin="round"/></svg><?php else: ?><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" stroke-linecap="round" stroke-linejoin="round"/></svg><?php endif; ?></div>
              <div><div class="log-dt"><?= strtoupper($lastLog['channel']??'—') ?></div><div class="log-mt"><?= fdt($lastLog['started_at']??'') ?></div></div>
            </div>
            <div class="stats" style="gap:.4rem">
              <div class="stat" style="padding:.5rem .75rem"><div class="stat-val" style="font-size:1.1rem"><?= $lastLog['total']??0 ?></div><div class="stat-lbl">Total</div></div>
              <div class="stat" style="padding:.5rem .75rem"><div class="stat-val" style="font-size:1.1rem;color:var(--green)"><?= $lastLog['sent']??0 ?></div><div class="stat-lbl">Enviados</div></div>
              <?php if(($lastLog['failed']??0)>0): ?><div class="stat" style="padding:.5rem .75rem"><div class="stat-val" style="font-size:1.1rem;color:var(--red)"><?= $lastLog['failed'] ?></div><div class="stat-lbl">Falhas</div></div><?php endif; ?>
            </div>
          </div>
        </div>
        <?php elseif(canSee('zap')): ?>
        <div class="panel"><div class="panel-hd"><span class="panel-title">Último Disparo</span></div><div class="empty-msg">Nenhum disparo registrado</div></div>
        <?php endif; ?>

        <?php if (canSee('amigos') && $proxEvento): ?>
        <div class="panel">
          <div class="panel-hd"><span class="panel-title">Evento em Destaque</span><span class="badge bdg-<?= $proxEvento['_st'] ?>"><?= $proxEvento['_lb'] ?></span></div>
          <div style="padding:.875rem 1.1rem">
            <div style="font-size:.88rem;font-weight:600;color:var(--text);margin-bottom:.3rem"><?= esc($proxEvento['titulo']??'') ?></div>
            <div style="font-size:.72rem;color:var(--text-muted);margin-bottom:.65rem"><?= fdt($proxEvento['data_inicio']??'') ?> → <?= $proxEvento['data_fim']?fdt($proxEvento['data_fim']):'—' ?></div>
            <div class="stats" style="gap:.4rem">
              <div class="stat" style="padding:.5rem .75rem"><div class="stat-val" style="font-size:1.1rem"><?= $proxEvento['_nc'] ?></div><div class="stat-lbl">Confirmados</div></div>
              <div class="stat" style="padding:.5rem .75rem"><div class="stat-val" style="font-size:1.1rem;color:var(--green)"><?= $proxEvento['_nk'] ?></div><div class="stat-lbl">Check-ins</div></div>
              <?php if($proxEvento['_nc']>0): ?><div class="stat" style="padding:.5rem .75rem"><div class="stat-val" style="font-size:1.1rem;color:var(--gold)"><?= rate($proxEvento['_nk'],$proxEvento['_nc']) ?></div><div class="stat-lbl">Taxa</div></div><?php endif; ?>
            </div>
          </div>
        </div>
        <?php elseif(canSee('amigos')): ?>
        <div class="panel"><div class="panel-hd"><span class="panel-title">Evento em Destaque</span></div><div class="empty-msg">Nenhum evento cadastrado</div></div>
        <?php endif; ?>
      </div>
    </div><!-- /overview -->


    <!-- ═══════════════════════════════════════════ -->
    <!-- APP — MEMBROS                               -->
    <!-- ═══════════════════════════════════════════ -->
    <?php if (canSee('app')): ?>
    <div class="section" id="sec-app">
      <div class="stats" style="margin-bottom:1.1rem">
        <div class="stat"><div class="stat-val" id="stat-app-m">…</div><div class="stat-lbl">Total membros</div></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.875rem">
        <div class="panel">
          <div class="panel-hd"><span class="panel-title">Painel Admin</span></div>
          <div class="panel-body" style="display:flex;flex-direction:column;gap:.6rem">
            <p style="font-size:.83rem;color:var(--text-dim);line-height:1.6;margin:0">Membros gerenciados via <strong style="color:var(--text)">Directus CMS</strong>. Perfis com foto, automações de aniversário e comunicação em massa.</p>
            <?php if (!isViewer()): ?>
            <a href="/app/admin.html" class="btn btn-gold" style="text-align:center;text-decoration:none" target="_blank">Abrir Admin ↗</a>
            <?php endif; ?>
            <a href="/app/" class="btn btn-outline" style="text-align:center;text-decoration:none" target="_blank">Portal do Membro ↗</a>
          </div>
        </div>
        <div class="panel">
          <div class="panel-hd"><span class="panel-title">Funcionalidades</span></div>
          <div class="panel-body">
            <ul style="margin:0;padding:0 0 0 1rem;display:flex;flex-direction:column;gap:.45rem">
              <?php foreach(['Perfis com foto (crop automático)','Aniversários automáticos (cron 08h)','Comunicados gerais — WhatsApp','Comunicados gerais — E-mail','Agendamento de mensagens','Logs de automação'] as $f): ?>
              <li style="font-size:.82rem;color:var(--text-dim)"><?= $f ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>


    <!-- ═══════════════════════════════════════════ -->
    <!-- AMIGOS                                       -->
    <!-- ═══════════════════════════════════════════ -->
    <?php if (canSee('amigos')): ?>
    <div class="section" id="sec-amigos">
      <div class="stats" style="margin-bottom:1.1rem">
        <div class="stat"><div class="stat-val"><?= $totalAmigos ?></div><div class="stat-lbl">Total cadastros</div></div>
        <?php foreach(['batizado','candidato','estudo_biblico','interessado'] as $cl): if(!empty($byClass[$cl])): ?>
        <div class="stat"><div class="stat-val" style="font-size:1.3rem"><?= $byClass[$cl] ?></div><div class="stat-lbl"><?= clLbl($cl) ?></div></div>
        <?php endif; endforeach; ?>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.875rem;margin-bottom:.875rem">
        <div class="panel">
          <div class="panel-hd"><span class="panel-title">Por classificação</span></div>
          <div class="panel-body">
            <?php $barColors=['batizado'=>'var(--green)','candidato'=>'var(--gold)','estudo_biblico'=>'var(--blue)','interessado'=>'var(--text-muted)'];
            foreach(['batizado','candidato','estudo_biblico','interessado'] as $cl):
              $n=$byClass[$cl]??0; if(!$n) continue; $pct=$totalAmigos>0?round($n/$totalAmigos*100):0; ?>
            <div class="cl-bar-wrap">
              <div class="cl-bar-label"><span><?= clLbl($cl) ?></span><span><?= $n ?> (<?= $pct ?>%)</span></div>
              <div class="cl-bar"><div class="cl-bar-fill" style="width:<?= $pct ?>%;background:<?= $barColors[$cl] ?>"></div></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="panel">
          <div class="panel-hd"><span class="panel-title">Acesso rápido</span></div>
          <div class="panel-body" style="display:flex;flex-direction:column;gap:.5rem">
            <?php if(!isViewer()): ?>
            <a href="/amigos/dashboard.php" class="btn btn-gold" style="text-align:center;text-decoration:none" target="_blank">Dashboard Amigos ↗</a>
            <?php endif; ?>
            <a href="/amigos/" class="btn btn-outline" style="text-align:center;text-decoration:none" target="_blank">Portal Público ↗</a>
            <a href="/amigos/checkin.php" class="btn btn-outline" style="text-align:center;text-decoration:none" target="_blank">Check-in ↗</a>
          </div>
        </div>
      </div>
      <div class="panel" style="margin-bottom:.875rem">
        <div class="panel-hd"><span class="panel-title">Eventos</span><span class="count-badge"><?= count($eventos) ?></span></div>
        <div class="panel-body">
          <?php if(empty($eventos)): ?><div class="empty-msg">Nenhum evento</div>
          <?php else: foreach($eventos as $ev): ?>
          <div class="ev-card">
            <div class="ev-top"><div class="ev-title"><?= esc($ev['titulo']??'') ?></div><span class="badge bdg-<?= $ev['_st'] ?>"><?= $ev['_lb'] ?></span></div>
            <div class="ev-meta">
              <span>📅 <?= fdt($ev['data_inicio']??'') ?></span>
              <?php if(!empty($ev['data_fim'])): ?><span>→ <?= fdt($ev['data_fim']) ?></span><?php endif; ?>
              <?php if(!empty($ev['local'])): ?><span>📍 <?= esc($ev['local']) ?></span><?php endif; ?>
            </div>
            <div class="ev-stats">
              <span class="ev-stat"><strong><?= $ev['_nc'] ?></strong> confirmados</span>
              <span class="ev-stat"><strong><?= $ev['_nk'] ?></strong> check-ins</span>
              <?php if($ev['_nc']>0): ?><span class="ev-stat" style="color:var(--gold)"><strong><?= rate($ev['_nk'],$ev['_nc']) ?></strong> taxa</span><?php endif; ?>
            </div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
      <div class="panel">
        <div class="panel-hd"><span class="panel-title">Adições recentes</span><span class="count-badge"><?= count($recentCadastros) ?></span></div>
        <?php if(empty($recentCadastros)): ?><div class="empty-msg">Nenhum cadastro</div>
        <?php else: ?>
        <div class="tbl-wrap"><table class="tbl">
          <thead><tr><th>Nome</th><th>WhatsApp</th><th>Classificação</th><th>Data</th></tr></thead>
          <tbody>
            <?php foreach($recentCadastros as $c): ?>
            <tr>
              <td><?= esc($c['nome']??'—') ?></td>
              <td style="color:var(--text-muted)"><?= esc($c['wpp']??'—') ?></td>
              <td><span class="cls-badge cls-<?= esc($c['classificacao']??'interessado') ?>"><?= clLbl($c['classificacao']??'interessado') ?></span></td>
              <td style="color:var(--text-muted);font-size:.73rem"><?= fdt($c['at']??'') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>


    <!-- ═══════════════════════════════════════════ -->
    <!-- ZAP                                          -->
    <!-- ═══════════════════════════════════════════ -->
    <?php if (canSee('zap')): ?>
    <div class="section" id="sec-zap">
      <div class="stats" style="margin-bottom:1.1rem">
        <div class="stat"><div class="stat-val"><?= $zapTotal ?></div><div class="stat-lbl">Contatos</div></div>
        <div class="stat"><div class="stat-val" style="<?= $totalOptouts>0?'color:var(--red)':'' ?>"><?= $totalOptouts ?></div><div class="stat-lbl">Opt-outs</div></div>
        <div class="stat"><div class="stat-val"><?= $zapTotal>0?round($totalOptouts/$zapTotal*100,1).'%':'—' ?></div><div class="stat-lbl">Taxa opt-out</div></div>
        <div class="stat"><div class="stat-val"><?= count($logs) ?></div><div class="stat-lbl">Disparos</div></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.875rem;margin-bottom:.875rem">
        <div class="panel">
          <div class="panel-hd"><span class="panel-title">Acesso rápido</span></div>
          <div class="panel-body" style="display:flex;flex-direction:column;gap:.5rem">
            <?php if(!isViewer()): ?>
            <a href="/zap/" class="btn btn-gold" style="text-align:center;text-decoration:none" target="_blank">Painel de Transmissão ↗</a>
            <?php endif; ?>
          </div>
        </div>
        <?php if(!empty($optoutsData)): ?>
        <div class="panel">
          <div class="panel-hd"><span class="panel-title">Opt-outs recentes</span><span class="count-badge"><?= $totalOptouts ?></span></div>
          <div class="panel-body" style="max-height:150px;overflow-y:auto;padding:.6rem 1rem">
            <?php foreach(array_slice(array_reverse($optoutsData,true),0,5,true) as $ph=>$o): ?>
            <div style="display:flex;justify-content:space-between;padding:.28rem 0;border-bottom:1px solid var(--border)">
              <span style="font-size:.76rem;color:var(--text-dim)"><?= esc($o['phone']??$ph) ?></span>
              <span style="font-size:.67rem;color:var(--text-muted)"><?= isset($o['date'])?(new DateTimeImmutable($o['date']))->format('d/m H:i'):'—' ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <div class="panel">
        <div class="panel-hd"><span class="panel-title">Histórico de disparos</span><span class="count-badge"><?= count($logs) ?></span></div>
        <?php if(empty($logs)): ?><div class="empty-msg">Nenhum disparo registrado</div>
        <?php else: foreach($logs as $lg): $ck=($lg['channel']??'')==='email'?'eml':'wpp'; ?>
        <div class="log-row">
          <div class="log-ch <?= $ck ?>"><?php if($ck==='wpp'): ?><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" stroke-linecap="round" stroke-linejoin="round"/></svg><?php else: ?><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" stroke-linecap="round" stroke-linejoin="round"/></svg><?php endif; ?></div>
          <div style="flex:1"><div class="log-dt"><?= strtoupper($lg['channel']??'—') ?></div><div class="log-mt"><?= fdt($lg['started_at']??'') ?></div></div>
          <div class="log-rt">
            <span class="log-sent"><?= $lg['sent']??0 ?>/<?= $lg['total']??0 ?></span>
            <span style="font-size:.67rem;color:var(--gold);display:block"><?= rate($lg['sent']??0,$lg['total']??0) ?></span>
            <?php if(($lg['failed']??0)>0): ?><span class="log-fail"><?= $lg['failed'] ?> falha(s)</span><?php endif; ?>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <?php endif; ?>


    <!-- ═══════════════════════════════════════════ -->
    <!-- SITE                                         -->
    <!-- ═══════════════════════════════════════════ -->
    <?php if (canSee('site')): ?>
    <div class="section" id="sec-site">
      <div class="panel">
        <div class="panel-hd"><span class="panel-title">Todos os sistemas</span></div>
        <div class="panel-body" style="padding:.75rem">
          <?php
          $sysCards = [
            ['icon'=>'globe','name'=>'Página Principal','desc'=>'Landing page da Comunidade Ser com links para todos os sistemas.','links'=>[['/','']]],
            ['icon'=>'users','name'=>'App — Membros','desc'=>'Portal dos membros com perfil, fotos e automações de aniversário. Backend via Directus CMS.','links'=>[['/app/','Portal'],['app/admin.html','Admin']]],
            ['icon'=>'heart','name'=>'Amigos','desc'=>'Cadastros classificados, eventos com confirmação via WhatsApp e check-in por QR Code.','links'=>[['/amigos/','Portal'],['/amigos/dashboard.php','Dashboard'],['/amigos/checkin.php','Check-in']]],
            ['icon'=>'wifi','name'=>'Zap','desc'=>'Disparos em massa WhatsApp e e-mail com controle de opt-out e histórico.','links'=>[['/zap/','Transmissão']]],
          ];
          $icons = [
            'globe'=>'<path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>',
            'users'=>'<path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>',
            'heart'=>'<path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>',
            'wifi'=>'<path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>',
          ];
          foreach($sysCards as $sc): ?>
          <div class="site-card">
            <div class="site-card-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><?= $icons[$sc['icon']] ?></svg></div>
            <div>
              <div class="site-card-name"><?= $sc['name'] ?></div>
              <div class="site-card-desc"><?= $sc['desc'] ?></div>
              <div class="site-card-links">
                <?php foreach($sc['links'] as [$href,$label]): ?>
                <a href="<?= $href ?>" class="link-ext" target="_blank"><?= $label ?: 'Abrir' ?> ↗</a>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>


    <!-- ═══════════════════════════════════════════ -->
    <!-- USUÁRIOS (superadmin)                        -->
    <!-- ═══════════════════════════════════════════ -->
    <?php if (isSA()): ?>
    <div class="section" id="sec-usuarios">
      <?php $allUsers = loadUsers(); ?>

      <div class="panel">
        <div class="panel-hd">
          <span class="panel-title">Usuários do sistema</span>
          <button class="btn btn-gold btn-sm" onclick="openUserForm()">+ Novo usuário</button>
        </div>
        <div class="tbl-wrap">
          <table class="tbl">
            <thead><tr><th>Nome</th><th>Usuário</th><th>Perfil</th><th>Sistemas</th><th>Status</th><th>Ações</th></tr></thead>
            <tbody>
              <?php foreach($allUsers as $u): ?>
              <tr>
                <td style="font-weight:500;color:var(--text)"><?= esc($u['nome']) ?></td>
                <td style="color:var(--text-muted);font-size:.78rem"><?= esc($u['usuario']) ?></td>
                <td><span class="rbdg rbdg-<?= esc($u['role']) ?>"><?= roleLbl($u['role']) ?></span></td>
                <td style="max-width:none">
                  <?php foreach($u['sistemas']??[] as $s): ?>
                  <span class="schip schip-<?= $s ?>"><?= $s ?></span>
                  <?php endforeach; ?>
                </td>
                <td><span style="font-size:.78rem;font-weight:500;color:<?= $u['ativo']?'var(--green)':'var(--red)' ?>"><?= $u['ativo']?'Ativo':'Inativo' ?></span></td>
                <td style="display:flex;gap:.3rem;max-width:none">
                  <button class="btn btn-outline btn-xs"
                    onclick="editUser(<?= esc(json_encode(['id'=>$u['id'],'nome'=>$u['nome'],'usuario'=>$u['usuario'],'role'=>$u['role'],'sistemas'=>$u['sistemas'],'ativo'=>$u['ativo']])) ?>)">
                    Editar
                  </button>
                  <?php if($u['id']!==(me()['id']??'')): ?>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Excluir <?= esc(addslashes($u['nome'])) ?>?')">
                    <input type="hidden" name="acao" value="del_user">
                    <input type="hidden" name="id" value="<?= esc($u['id']) ?>">
                    <button type="submit" class="btn btn-outline btn-xs" style="color:var(--red)">Excluir</button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Formulário add/edit -->
      <div class="panel" id="user-form-panel" style="display:none">
        <div class="panel-hd">
          <span class="panel-title" id="user-form-title">Novo usuário</span>
          <button class="btn btn-outline btn-sm" onclick="closeUserForm()">Cancelar</button>
        </div>
        <div class="panel-body">
          <form method="POST" id="user-form">
            <input type="hidden" name="acao" id="user-form-acao" value="add_user">
            <input type="hidden" name="id"   id="user-id">
            <div class="form-grid">
              <div class="f-row">
                <label>Nome completo</label>
                <input type="text" name="nome" id="user-nome" placeholder="Nome do usuário" required>
              </div>
              <div class="f-row">
                <label>Usuário (login)</label>
                <input type="text" name="usuario" id="user-usuario" placeholder="ex: joao.silva" autocomplete="off" required>
              </div>
              <div class="f-row">
                <label>Senha <span id="senha-hint" style="color:var(--text-muted);font-weight:400;text-transform:none">(mín. 6 caracteres)</span></label>
                <input type="password" name="senha" id="user-senha" placeholder="Mínimo 6 caracteres" autocomplete="new-password">
              </div>
              <div class="f-row">
                <label>Perfil de acesso</label>
                <select name="role" id="user-role">
                  <option value="admin">Admin</option>
                  <option value="viewer">Visualização (somente leitura)</option>
                  <option value="superadmin">Superadmin (acesso total)</option>
                </select>
              </div>
              <div class="f-row c2">
                <label>Sistemas permitidos</label>
                <div class="ckbox-group">
                  <?php foreach(['app'=>'App — Membros','amigos'=>'Amigos','zap'=>'Zap','site'=>'Site'] as $sv=>$sl): ?>
                  <label class="ckbox-item"><input type="checkbox" name="sistemas[]" value="<?= $sv ?>" id="sis-<?= $sv ?>"> <?= $sl ?></label>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="f-row">
                <label class="ckbox-item" style="gap:.5rem">
                  <input type="checkbox" name="ativo" id="user-ativo" value="1" checked> Usuário ativo
                </label>
              </div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:.5rem">
              <button type="button" class="btn btn-outline" onclick="closeUserForm()">Cancelar</button>
              <button type="submit" class="btn btn-gold">Salvar</button>
            </div>
          </form>
        </div>
      </div>

      <div class="panel" style="margin-top:.875rem">
        <div class="panel-hd"><span class="panel-title">Hierarquia de perfis</span></div>
        <div class="panel-body">
          <div style="display:flex;flex-direction:column;gap:.75rem">
            <?php foreach([
              ['superadmin','Superadmin','Acesso total: todos os sistemas, gestão de usuários, editor do site.'],
              ['admin','Admin','Acesso aos sistemas definidos. Pode operar (enviar, editar) dentro do escopo permitido.'],
              ['viewer','Visualização','Acesso somente leitura nos sistemas permitidos. Não pode realizar ações.'],
            ] as [$r,$l,$d]): ?>
            <div style="display:flex;align-items:flex-start;gap:.75rem">
              <span class="rbdg rbdg-<?= $r ?>" style="flex-shrink:0;margin-top:1px"><?= $l ?></span>
              <span style="font-size:.8rem;color:var(--text-dim)"><?= $d ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>


    <!-- ═══════════════════════════════════════════ -->
    <!-- EDITOR DO SITE                              -->
    <!-- ═══════════════════════════════════════════ -->
    <?php if (canSee('site') && !isViewer()): ?>
    <div class="section" id="sec-editor">
      <div class="panel">
        <div class="panel-hd">
          <span class="panel-title">Editor — index.html (raiz)</span>
          <a href="/" class="link-ext" target="_blank">Visualizar site ↗</a>
        </div>
        <div class="panel-body">
          <p style="font-size:.78rem;color:var(--text-muted);margin:0 0 .875rem">
            Edita diretamente o <code style="background:var(--surface2);padding:1px 5px;border-radius:4px;font-size:.76rem">/index.html</code> da raiz do site. Salve com cuidado.
          </p>
          <form method="POST">
            <input type="hidden" name="acao" value="save_index">
            <textarea name="html_content" class="code-editor" spellcheck="false"><?= esc($indexHtml) ?></textarea>
            <div style="display:flex;justify-content:flex-end;gap:.75rem;margin-top:.875rem">
              <a href="/" class="btn btn-outline" target="_blank">Visualizar ↗</a>
              <button type="submit" class="btn btn-gold" onclick="return confirm('Salvar alterações no index.html?')">Salvar alterações</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /main -->
</div><!-- /layout -->
<?php endif; ?>

<!-- TOAST -->
<div id="toast"></div>

<script>
const SECS   = ['overview','app','amigos','zap','site','usuarios','editor'];
const TITLES = {overview:'Visão Geral',app:'App — Membros',amigos:'Amigos',zap:'Zap',site:'Site',usuarios:'Usuários',editor:'Editor do Site'};

function showSec(id) {
    if (!document.getElementById('sec-' + id)) return;
    SECS.forEach(s => {
        document.getElementById('sec-'  + s)?.classList.toggle('active', s === id);
        document.getElementById('nl-'   + s)?.classList.toggle('active', s === id);
    });
    const t = document.getElementById('mob-title');
    if (t) t.textContent = TITLES[id] || id;
    closeSb();
    window.scrollTo(0, 0);
}

function toggleSb() { document.getElementById('sidebar')?.classList.toggle('open'); document.getElementById('backdrop')?.classList.toggle('show'); }
function closeSb()  { document.getElementById('sidebar')?.classList.remove('open'); document.getElementById('backdrop')?.classList.remove('show'); }

// Toast
function showToast(type, msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show t-' + type;
    clearTimeout(t._timer);
    t._timer = setTimeout(() => t.classList.remove('show'), 4000);
}

// Flash from server
<?php if ($flash): ?>
showToast('<?= esc($flash['type']) ?>', '<?= esc(addslashes($flash['msg'])) ?>');
<?php endif; ?>

// Membros App (async Directus)
async function loadAppMembers() {
    try {
        const r = await fetch('api.php?action=members');
        if (!r.ok) return;
        const d = await r.json();
        if (d.total != null) {
            ['stat-app-ov','stat-app-m','sc-app'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.textContent = d.total.toLocaleString('pt-BR');
            });
        }
    } catch {}
}
<?php if (canSee('app')): ?>loadAppMembers();<?php endif; ?>

// Seção inicial (após redirects POST)
const initSec = '<?= esc($_GET['sec'] ?? 'overview') ?>';
if (initSec !== 'overview') showSec(initSec);

// User form
function openUserForm() {
    document.getElementById('user-form-title').textContent = 'Novo usuário';
    document.getElementById('user-form-acao').value = 'add_user';
    document.getElementById('user-id').value = '';
    document.getElementById('user-form').reset();
    document.getElementById('user-ativo').checked = true;
    document.getElementById('senha-hint').textContent = '(mín. 6 caracteres)';
    document.getElementById('user-form-panel').style.display = 'block';
    document.getElementById('user-form-panel').scrollIntoView({behavior:'smooth',block:'start'});
}
function closeUserForm() { document.getElementById('user-form-panel').style.display = 'none'; }
function editUser(data) {
    document.getElementById('user-form-title').textContent = 'Editar usuário';
    document.getElementById('user-form-acao').value = 'edit_user';
    document.getElementById('user-id').value       = data.id;
    document.getElementById('user-nome').value     = data.nome;
    document.getElementById('user-usuario').value  = data.usuario;
    document.getElementById('user-senha').value    = '';
    document.getElementById('senha-hint').textContent = '(deixe em branco para manter)';
    document.getElementById('user-role').value     = data.role;
    ['app','amigos','zap','site'].forEach(s => {
        const cb = document.getElementById('sis-' + s);
        if (cb) cb.checked = (data.sistemas || []).includes(s);
    });
    document.getElementById('user-ativo').checked = !!data.ativo;
    document.getElementById('user-form-panel').style.display = 'block';
    document.getElementById('user-form-panel').scrollIntoView({behavior:'smooth',block:'start'});
}
</script>
</body>
</html>

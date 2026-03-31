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

// ── Funções Editor de Site
function getSiteLinks(): array {
    $html = file_get_contents(INDEX_HTML);
    $data = [];
    // Botões Principais
    $ids = ['offer'=>'btn-offer','join'=>'btn-join','member'=>'btn-member'];
    foreach($ids as $k=>$id){
        if (preg_match('/id="'.$id.'"[^>]*href="([^"]+)"[^>]*>([^<]+)<\/a>/is', $html, $m)){
            $data['link_'.$k.'_url']  = trim($m[1]);
            $data['link_'.$k.'_text'] = trim($m[2]);
        }
    }
    // Redes Sociais
    if (preg_match('/href="([^"]+)"[^>]*>Instagram<\/a>/is', $html, $m)) $data['social_instagram'] = trim($m[1]);
    if (preg_match('/href="([^"]+)"[^>]*>YouTube<\/a>/is', $html, $m))   $data['social_youtube']   = trim($m[1]);
    
    return $data;
}

function saveSiteLinks(array $d): bool {
    $html = file_get_contents(INDEX_HTML);
    
    // Botões Principais (IDs fixos)
    $ids = ['offer'=>'btn-offer','join'=>'btn-join','member'=>'btn-member'];
    foreach($ids as $k=>$id){
        $url  = htmlspecialchars(trim(strip_tags($d['link_'.$k.'_url']  ?? '')), ENT_QUOTES, 'UTF-8');
        $text = htmlspecialchars(trim(strip_tags($d['link_'.$k.'_text'] ?? '')), ENT_QUOTES, 'UTF-8');
        if (!$url || !$text) continue;
        $html = preg_replace('/(id="'.$id.'"[^>]*href=")[^"]*("[^>]*>)[^<]*(<\/a>)/is', '$1'.$url.'$2'.$text.'$3', $html);
    }
    
    // Redes Sociais (Texto fixo)
    if (!empty($d['social_instagram'])) {
        $insta = htmlspecialchars(trim(strip_tags($d['social_instagram'])), ENT_QUOTES, 'UTF-8');
        $html = preg_replace('/(href=")[^"]*("[^>]*>Instagram<\/a>)/is', '$1'.$insta.'$2', $html);
    }
    if (!empty($d['social_youtube'])) {
        $yt = htmlspecialchars(trim(strip_tags($d['social_youtube'])), ENT_QUOTES, 'UTF-8');
        $html = preg_replace('/(href=")[^"]*("[^>]*>YouTube<\/a>)/is', '$1'.$yt.'$2', $html);
    }

    // Também atualiza o JS interno (traduções) para o PT-BR (principal)
    $map = ['offer'=>'offer','join'=>'join','member'=>'member'];
    foreach($map as $k=>$key){
        $text = trim(strip_tags($d['link_'.$k.'_text'] ?? ''));
        if ($text) {
            $text = addslashes($text); // Protege contra quebra de string JS
            $html = preg_replace("/($key:\s*')[^']*(')/is", "$1".$text."$2", $html);
        }
    }

    return file_put_contents(INDEX_HTML, $html) !== false;
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
        $sis     = array_values(array_intersect($_POST['sistemas']??[], ['app','amigos','envios','site']));
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
        $sis  = array_values(array_intersect($_POST['sistemas']??[], ['app','amigos','envios','site']));
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

    // ── Salvar Links do Site
    if (canSee('site') && !isViewer() && ($_POST['acao']??'') === 'save_site_links') {
        if (saveSiteLinks($_POST)) {
            flash('success', 'Links do site atualizados com sucesso.');
        } else {
            flash('error', 'Falha ao salvar as alterações.');
        }
        header('Location: ./?sec=site'); exit;
    }

    // ── Salvar index.html (legado/backup)
    if (canSee('site') && isSA() && ($_POST['acao']??'') === 'save_index') {
        $html = $_POST['html_content'] ?? '';
        if (strlen($html) > 10) {
            file_put_contents(INDEX_HTML, $html);
            flash('success', 'index.html salvo com sucesso.');
        } else {
            flash('error', 'Conteúdo muito curto — não salvo.');
        }
        header('Location: ./?sec=site'); exit;
    }
}

/* ── FLASH ──────────────────────────────────────────────────── */
$flash = $_SESSION['_flash'] ?? null;
unset($_SESSION['_flash']);

/* ── DATA ───────────────────────────────────────────────────── */
$totalAmigos = 0; $byClass = []; $recentCadastros = [];
$eventos = []; $proxEvento = null;
$enviosTotal = 0; $totalOptouts = 0; $optoutsData = [];
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
    if (canSee('envios')) {
        $zf = ROOT . '/envios/novo-tempo.json';
        if (file_exists($zf)) $enviosTotal = count(json_decode(file_get_contents($zf), true) ?: []);
        $of = ROOT . '/envios/optouts.json';
        if (file_exists($of)) { $optoutsData = json_decode(file_get_contents($of), true) ?: []; $totalOptouts = count($optoutsData); }
        $ld = ROOT . '/envios/.logs/';
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
    return ['interessado'=>'Interessado','estudo_biblico'=>'Est. Bíblico','candidato'=>'Candidato','batizado'=>'Batizado','oracao'=>'Oração'][$c]??ucfirst($c);
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
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.js"></script>
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<style>
.hidden{display:none!important}
body{margin:0;padding:0}
/* LAYOUT */
.layout{display:flex;min-height:100vh}
.sidebar{width:220px;flex-shrink:0;background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;bottom:0;left:0;z-index:100;transition:transform .28s cubic-bezier(.22,1,.36,1)}
.main{flex:1;margin-left:220px;padding:2rem 2.5rem;min-width:0;max-width:1400px}
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
.schip-envios{background:rgba(36,113,163,.1);color:var(--blue)}
.schip-site{background:rgba(255,255,255,.05);color:var(--text-muted)}
/* CLASS */
.cls-badge{display:inline-flex;padding:2px 8px;border-radius:20px;font-size:.7rem;font-weight:600}
.cls-interessado{background:rgba(255,255,255,.05);color:var(--text-dim)}
.cls-estudo_biblico{background:rgba(36,113,163,.12);color:var(--blue)}
.cls-candidato{background:rgba(201,168,76,.12);color:var(--gold)}
.cls-batizado{background:rgba(46,204,113,.12);color:var(--green)}
.cls-oracao{background:rgba(155,89,182,.12);color:#a855f7}
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

/* ── SUB-TABS ── */
.sub-tabs{display:flex;gap:.5rem;margin-bottom:1.25rem;border-bottom:1px solid var(--border);padding-bottom:2px}
.sub-tab{background:none;border:none;border-bottom:2px solid transparent;color:var(--text-dim);padding:.6rem .85rem;font-size:.83rem;font-weight:500;cursor:pointer;transition:all .15s;font-family:inherit}
.sub-tab:hover{color:var(--text)}
.sub-tab.on{color:var(--gold);border-bottom-color:var(--gold)}

/* ── TABELAS ── */
/* Herdado de ser.css, mantendo apenas ajustes específicos se houver */
.actions{display:flex;gap:.4rem}
.btn-icon{background:var(--surface2);border:1px solid var(--border);border-radius:6px;width:28px;height:28px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text-dim);transition:all .15s}
.btn-icon:hover{border-color:var(--border-active);color:var(--text)}
.btn-icon.red:hover{border-color:var(--red);color:var(--red)}
.btn-icon.gold:hover{border-color:var(--gold);color:var(--gold)}

/* FILTRO RADIO BUTTONS */
.flt-btn{padding:4px 10px;border:1px solid var(--border);border-radius:6px;background:none;color:var(--text-muted);font-size:.75rem;font-weight:500;cursor:pointer;font-family:inherit;transition:all .15s}
.flt-btn:hover{border-color:var(--border-active);color:var(--text)}
.flt-btn.on{background:var(--gold-glow);border-color:rgba(201,168,76,.5);color:var(--gold);font-weight:600}
/* OVERLAY / MODAL */
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:200;align-items:center;justify-content:center;padding:1.5rem;backdrop-filter:blur(3px)}
.overlay.open{display:flex}
.modal{background:var(--surface);border:1px solid var(--border-active);border-radius:20px;padding:1.8rem 2rem;width:100%;max-width:480px;position:relative;max-height:94vh;overflow-y:auto}
.modal h2{font-size:1.15rem;font-weight:700;margin-bottom:1rem;color:var(--gold)}
.modal-close{position:absolute;top:1rem;right:1rem;background:none;border:none;color:var(--text-muted);font-size:1.4rem;cursor:pointer}
.form-row{margin-bottom:1rem}
.form-row label{display:block;font-size:.72rem;font-weight:600;color:var(--gold);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.35rem}
.form-row input, .form-row select, .form-row textarea{width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:9px;padding:.7rem .9rem;color:var(--text);font-size:.9rem;font-family:inherit;outline:none}
.form-row input:focus{border-color:var(--gold)}
.modal-actions{display:flex;gap:.75rem;margin-top:1.5rem;justify-content:flex-end}
/* ── Membros Admin ── */
.mb-row{display:flex;align-items:center;justify-content:space-between;padding:.75rem 1.2rem;border-bottom:1px solid var(--border);transition:background .15s}
.mb-row:last-child{border-bottom:none}
.mb-row:hover{background:var(--surface2)}
.mb-info{display:flex;align-items:center;gap:.75rem;flex:1;cursor:pointer;min-width:0}
.mb-avatar{width:40px;height:40px;border-radius:50%;object-fit:cover;background:var(--surface2);flex-shrink:0}
.mb-name{font-size:.85rem;font-weight:600;color:var(--text);text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px}
.mb-city{font-size:.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em}
.mb-act{display:flex;gap:.4rem;flex-shrink:0}
.mb-act-btn{width:32px;height:32px;border-radius:8px;border:1px solid var(--border);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s;background:var(--surface2);color:var(--text-muted)}
.mb-act-btn:hover{border-color:var(--gold);color:var(--gold)}
.mb-act-btn.wpp{background:rgba(46,204,113,.1);color:#2ECC71;border-color:rgba(46,204,113,.2)}
.mb-act-btn svg{width:15px;height:15px}
.mb-edit-layout{display:flex;flex-direction:column}
@media(min-width:640px){.mb-edit-layout{flex-direction:row;max-height:82vh}}
.mb-edit-side{padding:1.5rem;display:flex;flex-direction:column;align-items:center;gap:.65rem;background:var(--surface2);border-bottom:1px solid var(--border)}
@media(min-width:640px){.mb-edit-side{width:180px;flex-shrink:0;border-bottom:none;border-right:1px solid var(--border)}}
.mb-photo-ring{position:relative;cursor:pointer;display:inline-block}
.mb-photo-ring img{width:96px;height:96px;border-radius:50%;object-fit:cover;border:2px solid rgba(201,168,76,.3);padding:3px}
.mb-photo-ov{position:absolute;inset:3px;background:rgba(0,0,0,.45);border-radius:50%;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .2s}
.mb-photo-ring:hover .mb-photo-ov{opacity:1}
.mb-photo-ov svg{width:20px;height:20px;color:#fff}
.mb-edit-name{font-size:.82rem;font-weight:700;color:var(--text);text-transform:uppercase;text-align:center;line-height:1.3}
.mb-edit-form{flex:1;display:flex;flex-direction:column;min-height:0;overflow:hidden}
.mb-edit-body{flex:1;overflow-y:auto;padding:1.25rem}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}
.g2 .fr{margin-bottom:0}
.g2 .c2{grid-column:span 2}
.mb-tog{display:flex;padding:3px;background:var(--surface2);border-radius:10px;margin-bottom:1rem}
.mb-tog-opt{flex:1;padding:.5rem;background:none;border:none;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:600;color:var(--text-muted);border-radius:8px;transition:all .2s}
.mb-tog-opt.on{background:var(--surface);color:var(--text);box-shadow:0 2px 6px rgba(0,0,0,.25)}
.mb-sched{background:rgba(201,168,76,.05);border:1px solid rgba(201,168,76,.15);border-radius:9px;padding:.75rem 1rem;margin-top:.875rem}
.mb-sched-row{display:flex;justify-content:space-between;align-items:center}
.mb-sched-row label{font-size:.75rem;font-weight:600;color:var(--gold);text-transform:uppercase;letter-spacing:.04em;cursor:pointer}
.mb-sched-row input[type=checkbox]{width:16px;height:16px;accent-color:var(--gold)}
.mb-prog{background:var(--surface2);border:1px solid var(--border);border-radius:9px;padding:.75rem 1rem;margin-top:.875rem}
.mb-prog-head{display:flex;justify-content:space-between;font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--gold);margin-bottom:.5rem}
.mb-prog-bar{height:5px;background:var(--border);border-radius:10px;overflow:hidden}
.mb-prog-fill{height:100%;background:var(--gold);width:0;transition:width .3s}
#mb-crop-container{width:100%;height:290px;border-radius:12px;overflow:hidden;background:var(--surface2);margin-bottom:1.25rem}
.mb-fr{margin-bottom:.875rem}
.mb-fr:last-child{margin-bottom:0}
.mb-fr label{display:block;font-size:.68rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem}
.mb-fr input,.mb-fr textarea,.mb-fr select{width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:.55rem .8rem;color:var(--text);font-size:.88rem;font-family:inherit;outline:none}
.mb-fr input:focus,.mb-fr textarea:focus{border-color:var(--gold)}
.mb-log-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.mb-log-dot.ok{background:var(--green)}
.mb-log-dot.err{background:var(--red)}
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
      <img src="https://comunidadeser.com/wp-content/uploads/2025/01/logo_ser_branca-1-300x197.png" alt="Comunidade SER" style="max-width:110px;height:auto;display:block;margin-bottom:.35rem;opacity:.92">
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
        Membros
      </button>
      <?php endif; ?>
      <?php if (canSee('amigos')): ?>
      <button class="nav-lnk" id="nl-amigos" onclick="showSec('amigos')">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
        Amigos
      </button>
      <?php endif; ?>
      <?php if (canSee('amigos')): ?>
      <button class="nav-lnk" id="nl-eventos" onclick="showSec('eventos')">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" stroke-linecap="round"/><path stroke-linecap="round" d="M16 2v4M8 2v4M3 10h18"/></svg>
        Eventos
      </button>
      <?php endif; ?>
      <?php if (canSee('envios')): ?>
      <button class="nav-lnk" id="nl-envios" onclick="showSec('envios')">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/></svg>
        Envios
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
        <?php if (canSee('envios')): ?>
        <div class="stat"><div class="stat-val" id="ov-stat-envios-contatos" data-listas="<?= $enviosTotal + $totalAmigos ?>"><?= $enviosTotal + $totalAmigos ?></div><div class="stat-lbl" id="ov-stat-envios-contatos-lbl">Contatos</div></div>
        <div class="stat"><div class="stat-val" style="<?= $totalOptouts>0?'color:var(--red)':'' ?>"><?= $totalOptouts ?></div><div class="stat-lbl">Opt-outs</div></div>
        <?php endif; ?>
      </div>

      <div class="sys-grid">
        <?php if (canSee('app')): ?>
        <div class="sys-card" onclick="showSec('app')">
          <div class="sys-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg></div>
          <div class="sys-name">Membros</div>
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
        <?php if (canSee('envios')): ?>
        <div class="sys-card" onclick="showSec('envios')">
          <div class="sys-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/></svg></div>
          <div class="sys-name">Envios</div>
          <div class="sys-desc">Disparos em massa, opt-outs e histórico de transmissões</div>
          <div><div class="sys-stat" id="ov-sys-envios-contatos" data-listas="<?= $enviosTotal + $totalAmigos ?>"><?= $enviosTotal + $totalAmigos ?></div><div class="sys-lbl" id="ov-sys-envios-contatos-lbl">contatos</div></div>
          <div class="sys-footer"><a href="/envios/" class="link-ext" onclick="event.stopPropagation()" target="_blank">Transmissão ↗</a></div>
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
        <?php if (canSee('envios') && $lastLog): ?>
        <div class="panel">
          <div class="panel-hd"><span class="panel-title">Último Disparo</span><span class="badge <?= ($lastLog['status']??'')==='done'?'bdg-ongoing':'bdg-future' ?>"><?= ($lastLog['status']??'')==='done'?'Concluído':'Pendente' ?></span></div>
          <div style="padding:.875rem 1.1rem">
            <?php $ck=($lastLog['channel']??'')==='email'?'eml':'wpp'; ?>
            <div style="display:flex;align-items:center;gap:.65rem;margin-bottom:.7rem">
              <div class="log-ch <?= $ck ?>"><?php if($ck==='wpp'): ?><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" stroke-linecap="round" stroke-linejoin="round"/></svg><?php else: ?><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" stroke-linecap="round" stroke-linejoin="round"/></svg><?php endif; ?></div>
              <div>
                <div class="log-dt"><?= esc($lastLog['name'] ?? '') ?: strtoupper($lastLog['channel']??'—') ?></div>
                <div class="log-mt"><?= strtoupper($lastLog['channel']??'—') ?> · <?= fdt($lastLog['started_at']??'') ?></div>
              </div>
            </div>
            <div class="stats" style="gap:.4rem">
              <div class="stat" style="padding:.5rem .75rem"><div class="stat-val" style="font-size:1.1rem"><?= $lastLog['total']??0 ?></div><div class="stat-lbl">Total</div></div>
              <div class="stat" style="padding:.5rem .75rem"><div class="stat-val" style="font-size:1.1rem;color:var(--green)"><?= $lastLog['sent']??0 ?></div><div class="stat-lbl">Enviados</div></div>
              <?php if(($lastLog['failed']??0)>0): ?><div class="stat" style="padding:.5rem .75rem"><div class="stat-val" style="font-size:1.1rem;color:var(--red)"><?= $lastLog['failed'] ?></div><div class="stat-lbl">Falhas</div></div><?php endif; ?>
            </div>
          </div>
        </div>
        <?php elseif(canSee('envios')): ?>
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
      <div class="sub-tabs">
        <button class="sub-tab on" data-target="mb-overview" onclick="showSub(this)">Visão Geral</button>
        <?php if(!isViewer()): ?>
        <button class="sub-tab" data-target="mb-membros" onclick="showSub(this);mbEnsureData()">Membros</button>
        <button class="sub-tab" data-target="mb-logs" onclick="showSub(this);mbLoadLogs()">Logs</button>
        <button class="sub-tab" data-target="mb-ajustes" onclick="showSub(this);mbLoadConfigs()">Ajustes</button>
        <?php endif; ?>
      </div>

      <!-- Visão Geral -->
      <div id="mb-overview" class="sub-sec">
        <div class="stats" style="margin-bottom:1.1rem">
          <div class="stat"><div class="stat-val" id="mb-stat-total" style="font-size:1.6rem">…</div><div class="stat-lbl">Total membros</div></div>
          <div class="stat"><div class="stat-val" id="mb-stat-photo" style="font-size:1.6rem">…</div><div class="stat-lbl">Com foto</div></div>
          <div class="stat"><div class="stat-val" id="mb-stat-whats" style="font-size:1.6rem">…</div><div class="stat-lbl">WhatsApp</div></div>
          <div class="stat"><div class="stat-val" id="mb-stat-cep" style="font-size:1.6rem">…</div><div class="stat-lbl">Endereço</div></div>
        </div>
        <?php if(!isViewer()): ?>
        <div style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap">
          <button class="btn btn-gold btn-sm" onclick="mbOpenBroadcast()">Comunicar</button>
          <button class="btn btn-outline btn-sm" onclick="mbExportCSV()">Exportar CSV</button>
          <a href="/app/" class="btn btn-outline btn-sm" style="text-decoration:none" target="_blank">Portal do Membro ↗</a>
        </div>
        <?php endif; ?>
        <div class="panel">
          <div class="panel-hd"><span class="panel-title">Funcionalidades do App</span></div>
          <div class="panel-body">
            <ul style="margin:0;padding:0 0 0 1.2rem;display:flex;flex-direction:column;gap:.45rem">
              <?php foreach(['Perfis com foto (crop automático)','Aniversários automáticos (cron 08h)','Comunicados gerais — WhatsApp','Comunicados gerais — E-mail','Agendamento de mensagens','Logs de automação'] as $f): ?>
              <li style="font-size:.82rem;color:var(--text-dim)"><?= $f ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>

      <!-- Membros -->
      <?php if(!isViewer()): ?>
      <div id="mb-membros" class="sub-sec" style="display:none">
        <div class="panel">
          <div class="panel-hd">
            <span class="panel-title">Membros</span>
            <span class="count-badge" id="mb-list-count">…</span>
          </div>
          <div style="padding:.65rem 1rem;border-bottom:1px solid var(--border)">
            <input type="text" id="mb-search-input" placeholder="Nome, e-mail ou cidade…" oninput="mbFilterMembers(this.value)" style="width:100%;background:var(--surface2);border:1px solid var(--border);padding:.5rem .8rem;border-radius:6px;color:var(--text);font-size:.85rem;font-family:inherit;outline:none">
          </div>
          <div id="mb-members-list" style="max-height:560px;overflow-y:auto">
            <div class="empty-msg">Carregando…</div>
          </div>
        </div>
      </div>

      <!-- Logs -->
      <div id="mb-logs" class="sub-sec" style="display:none">
        <div class="panel">
          <div class="panel-hd">
            <span class="panel-title">Logs de Automação</span>
            <button class="btn btn-outline btn-sm" onclick="mbLoadLogs()">Atualizar</button>
          </div>
          <div id="mb-logs-list" style="max-height:480px;overflow-y:auto">
            <div class="empty-msg">Carregando…</div>
          </div>
        </div>
      </div>

      <!-- Ajustes -->
      <div id="mb-ajustes" class="sub-sec" style="display:none">
        <div class="panel">
          <div class="panel-hd"><span class="panel-title">Mensagens de Aniversário</span></div>
          <div class="panel-body">
            <div class="form-row">
              <label>WhatsApp</label>
              <textarea id="mb-msg-whats" rows="4" placeholder="Feliz aniversário, {nome}!"></textarea>
            </div>
            <hr style="border:none;border-top:1px solid var(--border);margin:1rem 0">
            <div class="form-row">
              <label>E-mail — Assunto</label>
              <input type="text" id="mb-msg-email-sub" placeholder="Feliz Aniversário!">
            </div>
            <div class="form-row" style="margin-top:.75rem">
              <label>E-mail — Corpo</label>
              <textarea id="mb-msg-email-body" rows="6" placeholder="Olá {nome}, desejamos um feliz aniversário!"></textarea>
            </div>
            <button class="btn btn-gold" style="margin-top:.5rem" onclick="mbSaveConfigs()">Salvar Ajustes</button>
          </div>
        </div>
      </div>
      <?php endif; ?>

    </div>
    <?php endif; ?>


    <!-- ═══════════════════════════════════════════ -->
    <!-- AMIGOS                                       -->
    <!-- ═══════════════════════════════════════════ -->
    <?php if (canSee('amigos')): ?>
    <div class="section" id="sec-amigos">
      <div class="sub-tabs">
        <button class="sub-tab on" data-target="ami-overview" onclick="showSub(this)">Visão Geral</button>
        <button class="sub-tab" data-target="ami-membros" onclick="showSub(this)">Amigos</button>
        <?php if (canSee('envios')): ?>
        <button class="sub-tab" data-target="ami-listas" onclick="showSub(this);if(!window._envLoaded)loadEnviosContatos()">Listas</button>
        <?php endif; ?>
      </div>

      <div id="ami-overview" class="sub-sec">
        <div class="stats" style="margin-bottom:1.1rem">
          <div class="stat"><div class="stat-val"><?= $totalAmigos ?></div><div class="stat-lbl">Total cadastros</div></div>
          <?php foreach(['batizado','candidato','estudo_biblico','interessado','oracao'] as $cl): if(!empty($byClass[$cl])): ?>
          <div class="stat"><div class="stat-val" style="font-size:1.3rem"><?= $byClass[$cl] ?></div><div class="stat-lbl"><?= clLbl($cl) ?></div></div>
          <?php endif; endforeach; ?>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.875rem;margin-bottom:.875rem">
          <div class="panel">
            <div class="panel-hd"><span class="panel-title">Por classificação</span></div>
            <div class="panel-body">
              <?php $barColors=['batizado'=>'var(--green)','candidato'=>'var(--gold)','estudo_biblico'=>'var(--blue)','interessado'=>'var(--text-muted)','oracao'=>'#a855f7'];
              foreach(['batizado','candidato','estudo_biblico','interessado','oracao'] as $cl):
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
              <button class="btn btn-gold" onclick="document.querySelector('[data-target=ami-membros]').click()">Gerenciar Amigos</button>
              <button class="btn btn-outline" onclick="document.querySelector('[data-target=ami-eventos]').click()">Gerenciar Eventos</button>
              <a href="/amigos/checkin.php" class="btn btn-outline" style="text-align:center;text-decoration:none" target="_blank">Check-in QR ↗</a>
            </div>
          </div>
        </div>
      </div>

      <div id="ami-membros" class="sub-sec" style="display:none">
        <div class="panel">
          <div class="panel-hd">
            <span class="panel-title">Lista de Amigos</span>
            <button class="btn btn-gold btn-sm" onclick="openAmiAdd()">+ Novo Cadastro</button>
          </div>
          <div style="padding:.75rem 1rem;border-bottom:1px solid var(--border);display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
            <input type="text" id="ami-search" placeholder="Buscar por nome, WhatsApp..." onkeyup="filterAmiContatos()" style="flex:1;min-width:160px;background:var(--surface2);border:1px solid var(--border);padding:.5rem .8rem;border-radius:6px;color:var(--text);font-size:.85rem">
            <div style="display:flex;gap:.3rem;flex-wrap:wrap">
              <button class="flt-btn on" id="ami-cl-all" onclick="setAmiClass('',this)">Todos</button>
              <button class="flt-btn" onclick="setAmiClass('batizado',this)">Batizados</button>
              <button class="flt-btn" onclick="setAmiClass('candidato',this)">Candidatos</button>
              <button class="flt-btn" onclick="setAmiClass('estudo_biblico',this)">Estudo Bíblico</button>
              <button class="flt-btn" onclick="setAmiClass('interessado',this)">Interessados</button>
              <button class="flt-btn" onclick="setAmiClass('oracao',this)">Oração</button>
            </div>
          </div>
          <div class="panel-body" style="padding:0">
            <div class="tbl-wrap" style="margin-top:0;border:none;border-radius:0">
              <table class="tbl">
                <thead><tr><th>Nome</th><th>WhatsApp</th><th>Classificação</th><th>Ações</th></tr></thead>
                <tbody id="ami-membros-list">
                  <tr><td colspan="4" class="empty-msg">Carregando...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <?php if (canSee('envios')): ?>
      <div id="ami-listas" class="sub-sec" style="display:none">
        <div class="panel">
          <div class="panel-hd">
            <span class="panel-title">Base de Contatos</span>
            <div style="display:flex;align-items:center;gap:.5rem">
              <div style="display:flex;gap:4px;flex-wrap:wrap" id="env-list-selector">
                <span style="font-size:.75rem;color:var(--text-muted)">Carregando listas…</span>
              </div>
              <?php if (!isViewer()): ?>
              <button class="btn btn-gold btn-sm" onclick="openImportModal()" style="white-space:nowrap">+ Importar via IA</button>
              <?php endif; ?>
            </div>
          </div>
          <div class="panel-body" style="padding:0">
            <div style="padding:.75rem 1rem;border-bottom:1px solid var(--border);display:flex;gap:.5rem">
              <input type="text" id="env-search" placeholder="Buscar por nome, bairro, religião..." onkeyup="filterEnvContatos()" style="flex:1;background:var(--surface2);border:1px solid var(--border);padding:.5rem .8rem;border-radius:6px;color:var(--text);font-size:.85rem">
            </div>
            <div class="tbl-wrap" style="margin-top:0;border:none;border-radius:0">
              <table class="tbl">
                <thead><tr><th>Nome</th><th>Bairro</th><th>Religião</th><th>WhatsApp</th><th>Status</th><th>Ações</th></tr></thead>
                <tbody id="env-contatos-list">
                  <tr><td colspan="6" class="empty-msg">Selecione uma lista para carregar…</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

    </div>
    <?php endif; ?>


    <!-- ═══════════════════════════════════════════ -->
    <!-- EVENTOS                                     -->
    <!-- ═══════════════════════════════════════════ -->
    <?php if (canSee('amigos')): ?>
    <div class="section" id="sec-eventos">
      <div class="stats" style="margin-bottom:1.1rem">
        <div class="stat"><div class="stat-val"><?= count($eventos) ?></div><div class="stat-lbl">Total eventos</div></div>
        <?php
          $nFuture = count(array_filter($eventos, fn($e) => $e['_st']==='future'));
          $nOngoing = count(array_filter($eventos, fn($e) => $e['_st']==='ongoing'));
          if ($nOngoing): ?><div class="stat"><div class="stat-val" style="color:var(--green)"><?= $nOngoing ?></div><div class="stat-lbl">Em curso</div></div><?php endif; ?>
        <?php if ($nFuture): ?><div class="stat"><div class="stat-val" style="color:var(--blue)"><?= $nFuture ?></div><div class="stat-lbl">Próximos</div></div><?php endif; ?>
      </div>
      <div class="panel">
        <div class="panel-hd">
          <span class="panel-title">Eventos</span>
          <?php if (!isViewer()): ?><button class="btn btn-gold btn-sm" onclick="openAmiEvAdd()">+ Novo Evento</button><?php endif; ?>
        </div>
        <div class="panel-body">
          <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:1rem">
            <button class="flt-btn on" id="ev-f-all" onclick="filterEventos('all',this)">Todos</button>
            <button class="flt-btn" id="ev-f-future" onclick="filterEventos('future',this)">Próximos</button>
            <button class="flt-btn" id="ev-f-ongoing" onclick="filterEventos('ongoing',this)">Em curso</button>
            <button class="flt-btn" id="ev-f-past" onclick="filterEventos('past',this)">Encerrados</button>
          </div>
          <div id="ev-list">
          <?php if(empty($eventos)): ?><div class="empty-msg">Nenhum evento cadastrado</div>
          <?php else: foreach($eventos as $ev): ?>
          <div class="ev-card" data-ev-st="<?= $ev['_st'] ?>" style="background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:1rem;margin-bottom:.75rem">
            <div style="display:flex;justify-content:space-between;margin-bottom:.4rem">
              <div style="font-size:.92rem;font-weight:600;color:var(--text)"><?= esc($ev['titulo']??'') ?></div>
              <span class="badge bdg-<?= $ev['_st'] ?>"><?= $ev['_lb'] ?></span>
            </div>
            <?php if (!empty($ev['local'])): ?><div style="font-size:.73rem;color:var(--text-muted);margin-bottom:.3rem">📍 <?= esc($ev['local']) ?></div><?php endif; ?>
            <div style="font-size:.78rem;color:var(--text-dim);margin-bottom:.6rem">
              📅 <?= fdt($ev['data_inicio']??'') ?><?php if(!empty($ev['data_fim'])): ?> → <?= fdt($ev['data_fim']) ?><?php endif; ?>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center">
              <div style="display:flex;gap:1rem;font-size:.8rem">
                <span>👥 <strong><?= $ev['_nc'] ?></strong> confirmados</span>
                <span>✅ <strong><?= $ev['_nk'] ?></strong> check-ins</span>
                <?php if($ev['_nc']>0): ?><span style="color:var(--gold)">📊 <?= rate($ev['_nk'],$ev['_nc']) ?></span><?php endif; ?>
              </div>
              <?php if (!isViewer()): ?>
              <div class="actions">
                <a href="/amigos/checkin.php?ev=<?= $ev['id'] ?>" class="btn-icon" title="Check-in" target="_blank" style="text-decoration:none">🔗</a>
                <button class="btn-icon" title="Editar" onclick="editAmiEvent(<?= htmlspecialchars(json_encode($ev, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>)">✏️</button>
                <button class="btn-icon red" title="Excluir" onclick="deleteAmiEvent('<?= $ev['id'] ?>')">🗑️</button>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>


    <!-- ═══════════════════════════════════════════ -->
    <!-- ENVIOS                                      -->
    <!-- ═══════════════════════════════════════════ -->
    <?php if (canSee('envios')): ?>
    <div class="section" id="sec-envios">
      <div class="sub-tabs">
        <button class="sub-tab on" data-target="env-overview" onclick="showSub(this)">Visão Geral</button>
        <?php if (!isViewer()): ?>
        <button class="sub-tab" data-target="env-disparo" onclick="showSub(this);if(!window._dispListasData.length)loadDispListasData()">Novo Disparo</button>
        <?php endif; ?>
        <button class="sub-tab" data-target="env-historico" onclick="showSub(this)">Histórico</button>
      </div>

      <div id="env-overview" class="sub-sec">
        <div class="stats" style="margin-bottom:1.1rem">
          <div class="stat"><div class="stat-val" id="env-stat-contatos" data-listas="<?= $enviosTotal + $totalAmigos ?>"><?= $enviosTotal + $totalAmigos ?></div><div class="stat-lbl" id="env-stat-contatos-lbl">Contatos</div></div>
          <div class="stat"><div class="stat-val" style="<?= $totalOptouts>0?'color:var(--red)':'' ?>"><?= $totalOptouts ?></div><div class="stat-lbl">Opt-outs</div></div>
          <div class="stat"><div class="stat-val"><?= $enviosTotal>0?round($totalOptouts/$enviosTotal*100,1).'%':'—' ?></div><div class="stat-lbl">Taxa opt-out</div></div>
          <div class="stat"><div class="stat-val"><?= count($logs) ?></div><div class="stat-lbl">Disparos</div></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.875rem;margin-bottom:.875rem">
          <div class="panel">
            <div class="panel-hd"><span class="panel-title">Ações rápidas</span></div>
            <div class="panel-body" style="display:flex;flex-direction:column;gap:.5rem">
              <?php if (!isViewer()): ?>
              <button class="btn btn-gold" onclick="document.querySelector('[data-target=env-disparo]')?.click()">Compor novo disparo</button>
              <?php endif; ?>
              <button class="btn btn-outline" onclick="showSec('amigos');document.querySelector('[data-target=ami-listas]')?.click()">Ver listas de contatos</button>
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
      </div>

      <?php if (!isViewer()): ?>
      <div id="env-disparo" class="sub-sec" style="display:none">
        <div style="display:grid;grid-template-columns:260px 1fr;gap:.875rem;align-items:start">

          <!-- PAINEL DE FILTROS -->
          <div class="panel">
            <div class="panel-hd"><span class="panel-title">Público Alvo</span></div>
            <div class="panel-body" style="display:flex;flex-direction:column;gap:.8rem">

              <div>
                <div style="font-size:.68rem;font-weight:600;color:var(--gold);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem">Fonte</div>
                <div style="display:flex;gap:.3rem;flex-wrap:wrap">
                  <button class="flt-btn on" id="disp-src-listas" onclick="setDispSource('listas')">Listas</button>
                  <button class="flt-btn" id="disp-src-amigos" onclick="setDispSource('amigos')">Amigos</button>
                  <?php if (canSee('app')): ?>
                  <button class="flt-btn" id="disp-src-membros" onclick="setDispSource('membros')">Membros</button>
                  <?php endif; ?>
                </div>
              </div>

              <!-- Filtros: Listas -->
              <div id="disp-listas-filters" style="display:flex;flex-direction:column;gap:.8rem">
                <div>
                  <div style="font-size:.68rem;font-weight:600;color:var(--gold);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem">Lista</div>
                  <div style="display:flex;gap:.3rem;flex-wrap:wrap" id="disp-lista-selector">
                    <span style="font-size:.75rem;color:var(--text-muted)">Carregando…</span>
                  </div>
                </div>
                <div>
                  <div style="font-size:.68rem;font-weight:600;color:var(--gold);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem">Bairro</div>
                  <select id="disp-f-bairro" onchange="applyEnvDispFilters()" style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:7px;padding:.5rem .7rem;color:var(--text);font-size:.82rem;font-family:inherit;outline:none">
                    <option value="">Todos</option>
                  </select>
                </div>
                <div>
                  <div style="font-size:.68rem;font-weight:600;color:var(--gold);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem">Religião</div>
                  <select id="disp-f-religiao" onchange="applyEnvDispFilters()" style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:7px;padding:.5rem .7rem;color:var(--text);font-size:.82rem;font-family:inherit;outline:none">
                    <option value="">Todas</option>
                  </select>
                </div>
                <div>
                  <div style="font-size:.68rem;font-weight:600;color:var(--gold);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem">Sexo</div>
                  <div style="display:flex;gap:.3rem">
                    <button class="flt-btn on" id="disp-sx-all" onclick="setDispSexo('')">Todos</button>
                    <button class="flt-btn" id="disp-sx-m" onclick="setDispSexo('Masculino')">Masc.</button>
                    <button class="flt-btn" id="disp-sx-f" onclick="setDispSexo('Feminino')">Fem.</button>
                  </div>
                </div>
                <div>
                  <div style="font-size:.68rem;font-weight:600;color:var(--gold);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem">VIP</div>
                  <div style="display:flex;gap:.3rem">
                    <button class="flt-btn on" id="disp-vip-all" onclick="setDispVip('')">Todos</button>
                    <button class="flt-btn" id="disp-vip-y" onclick="setDispVip('Sim')">Apenas VIP</button>
                  </div>
                </div>
              </div>

              <!-- Filtros: Amigos -->
              <div id="disp-amigos-filters" style="display:none;flex-direction:column;gap:.8rem">
                <div>
                  <div style="font-size:.68rem;font-weight:600;color:var(--gold);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem">Classificação</div>
                  <select id="disp-f-class" onchange="applyEnvDispFilters()" style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:7px;padding:.5rem .7rem;color:var(--text);font-size:.82rem;font-family:inherit;outline:none">
                    <option value="">Todas</option>
                    <option value="batizado">Batizados</option>
                    <option value="candidato">Candidatos</option>
                    <option value="estudo_biblico">Estudo Bíblico</option>
                    <option value="interessado">Interessados</option>
                    <option value="oracao">Oração</option>
                  </select>
                </div>
              </div>

              <!-- Filtros: Membros -->
              <?php if (canSee('app')): ?>
              <div id="disp-membros-filters" style="display:none;flex-direction:column;gap:.8rem">
                <div>
                  <div style="font-size:.68rem;font-weight:600;color:var(--gold);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem">Cidade</div>
                  <select id="disp-f-cidade" onchange="applyEnvDispFilters()" style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:7px;padding:.5rem .7rem;color:var(--text);font-size:.82rem;font-family:inherit;outline:none">
                    <option value="">Todas</option>
                  </select>
                </div>
                <div>
                  <div style="font-size:.68rem;font-weight:600;color:var(--gold);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem">Contato</div>
                  <div style="display:flex;gap:.3rem;flex-wrap:wrap">
                    <button class="flt-btn on" id="disp-mb-ch-all"   onclick="setDispMbContato('')">Todos</button>
                    <button class="flt-btn"    id="disp-mb-ch-wpp"   onclick="setDispMbContato('wpp')">Com WhatsApp</button>
                    <button class="flt-btn"    id="disp-mb-ch-email" onclick="setDispMbContato('email')">Com E-mail</button>
                  </div>
                </div>
              </div>
              <?php endif; ?>

              <div style="padding:.65rem;background:var(--surface2);border-radius:8px;border:1px solid var(--border);text-align:center">
                <div style="font-size:.68rem;color:var(--text-muted);margin-bottom:.15rem">Contatos selecionados</div>
                <div style="font-size:1.5rem;font-weight:700;color:var(--gold)" id="env-disp-total">…</div>
                <div style="font-size:.65rem;color:var(--text-muted)" id="env-disp-total-note">excluindo opt-outs</div>
              </div>

              <button class="btn btn-outline btn-sm" onclick="resetEnvFilters()">Limpar filtros</button>
            </div>
          </div>

          <!-- PAINEL DE COMPOSIÇÃO -->
          <div class="panel">
            <div class="panel-hd"><span class="panel-title">Compor Mensagem</span></div>
            <div class="panel-body">
              <div class="form-row">
                <label>Nome do disparo <span style="color:var(--red)">*</span></label>
                <input type="text" id="env-disp-name" placeholder="Ex: Culto de domingo, Convite para retiro...">
              </div>
              <div class="form-row">
                <label>Canal</label>
                <div style="display:flex;gap:.4rem;flex-wrap:wrap">
                  <button class="flt-btn on" id="ch-wpp"   onclick="setDispChannel('whatsapp')">WhatsApp</button>
                  <button class="flt-btn"    id="ch-email" onclick="setDispChannel('email')">E-mail</button>
                  <button class="flt-btn"    id="ch-both"  onclick="setDispChannel('both')">Ambos</button>
                </div>
                <div id="env-ch-info" style="font-size:.75rem;color:var(--text-muted);margin-top:.35rem"></div>
              </div>
              <div class="form-row" id="env-subject-row" style="display:none">
                <label>Assunto (E-mail)</label>
                <input type="text" id="env-disp-subject" placeholder="Assunto da mensagem">
              </div>
              <div class="form-row">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.35rem">
                  <label style="margin:0">Mensagem</label>
                  <div style="display:flex;gap:.3rem">
                    <button type="button" class="btn btn-outline btn-xs" onclick="insertTag('{nome}')" title="Inserir variável nome">{nome}</button>
                  </div>
                </div>
                <textarea id="env-disp-msg" style="min-height:160px" placeholder="Olá {nome}, tudo bem?&#10;&#10;..."></textarea>
              </div>
              <div style="margin-top:1rem;display:flex;gap:.75rem">
                <button class="btn btn-gold" style="padding:.7rem 2rem" onclick="startEnviosDisparo()">Iniciar Disparo</button>
              </div>
            </div>
          </div>

        </div>
      </div>
      <?php endif; ?>

      <div id="env-historico" class="sub-sec" style="display:none">
        <div class="panel">
          <div class="panel-hd"><span class="panel-title">Histórico de disparos</span><span class="count-badge"><?= count($logs) ?></span></div>
          <div class="panel-body">
            <?php if(empty($logs)): ?><div class="empty-msg">Nenhum disparo registrado</div>
            <?php else: foreach($logs as $lg): $ck=($lg['channel']??'')==='email'?'eml':'wpp'; ?>
            <div class="log-row">
              <div class="log-ch <?= $ck ?>"><?php if($ck==='wpp'): ?><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" stroke-linecap="round" stroke-linejoin="round"/></svg><?php else: ?><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" stroke-linecap="round" stroke-linejoin="round"/></svg><?php endif; ?></div>
              <div style="flex:1">
                <div class="log-dt"><?= esc($lg['name'] ?? '') ?: strtoupper($lg['channel']??'—') ?></div>
                <div class="log-mt"><?= strtoupper($lg['channel']??'—') ?> · <?= fdt($lg['started_at']??'') ?></div>
              </div>
              <div class="log-rt">
                <span class="log-sent"><?= $lg['sent']??0 ?>/<?= $lg['total']??0 ?></span>
                <span style="font-size:.67rem;color:var(--gold);display:block"><?= rate($lg['sent']??0,$lg['total']??0) ?></span>
                <?php if(($lg['failed']??0)>0): ?><span class="log-fail"><?= $lg['failed'] ?> falha(s)</span><?php endif; ?>
              </div>
            </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>


    <!-- ═══════════════════════════════════════════ -->
    <!-- SITE                                         -->
    <!-- ═══════════════════════════════════════════ -->
    <?php if (canSee('site')): ?>
    <div class="section" id="sec-site">
      <div class="sub-tabs">
        <button class="sub-tab on" data-target="site-overview" onclick="showSub(this)">Visão Geral</button>
        <?php if(!isViewer()): ?>
        <button class="sub-tab" data-target="site-botoes" onclick="showSub(this);loadSiteLinks()">Botões</button>
        <button class="sub-tab" data-target="site-social" onclick="showSub(this);loadSiteLinks()">Redes Sociais</button>
        <?php endif; ?>
      </div>

      <div id="site-overview" class="sub-sec">
        <div class="panel">
          <div class="panel-hd"><span class="panel-title">comunidadeser.com</span>
            <a href="/" class="btn btn-outline btn-sm" target="_blank" style="text-decoration:none">Abrir Site ↗</a>
          </div>
          <div class="panel-body" style="padding:.75rem">
            <?php foreach([['Membros','/app/'],['Amigos','/amigos/'],['Envios','/envios/']] as [$n,$p]): ?>
            <div style="display:flex;align-items:center;justify-content:space-between;padding:.65rem .5rem;border-bottom:1px solid var(--border)">
              <span style="font-size:.88rem"><?= $n ?></span>
              <a href="<?= $p ?>" target="_blank" style="font-size:.7rem;color:var(--gold);text-decoration:none">Acessar ↗</a>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <?php if(!isViewer()): ?>
      <div id="site-botoes" class="sub-sec" style="display:none">
        <div class="panel">
          <div class="panel-hd">
            <span class="panel-title">Botões da Página Principal</span>
            <button class="btn btn-gold btn-sm" onclick="openSiteLinkModal()">+ Novo Botão</button>
          </div>
          <div class="panel-body" style="padding:0">
            <div class="tbl-wrap">
              <table class="tbl">
                <thead><tr><th>Texto</th><th>URL</th><th>Destaque</th><th>Ações</th></tr></thead>
                <tbody id="site-btns-list"><tr><td colspan="4" class="empty-msg">Carregando...</td></tr></tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div id="site-social" class="sub-sec" style="display:none">
        <div class="panel">
          <div class="panel-hd"><span class="panel-title">Redes Sociais</span></div>
          <div class="panel-body">
            <div class="form-row">
              <label>Instagram</label>
              <input type="text" id="ed-social-insta" placeholder="https://instagram.com/...">
            </div>
            <div class="form-row">
              <label>YouTube</label>
              <input type="text" id="ed-social-yt" placeholder="https://youtube.com/...">
            </div>
            <div style="margin-top:1rem">
              <button class="btn btn-gold" style="padding:.65rem 2rem" onclick="saveSocialLinks()">Salvar</button>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (isSA()): ?>
    <div class="section" id="sec-usuarios">
      <div class="panel">
        <div class="panel-hd">
          <span class="panel-title">Gestão de Usuários</span>
          <button class="btn btn-gold btn-sm" onclick="openUserForm()">+ Novo Usuário</button>
        </div>
        <div class="panel-body">
          <div class="tbl-wrap" style="margin-top:0">
            <table class="tbl">
              <thead>
                <tr>
                  <th>Nome</th>
                  <th>Usuário</th>
                  <th>Role</th>
                  <th>Sistemas</th>
                  <th>Status</th>
                  <th>Ações</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (loadUsers() as $u): ?>
                <tr>
                  <td><strong><?= esc($u['nome']) ?></strong></td>
                  <td><?= esc($u['usuario']) ?></td>
                  <td><span class="rbdg rbdg-<?= esc($u['role']) ?>"><?= roleLbl($u['role']) ?></span></td>
                  <td>
                    <?php foreach ($u['sistemas'] ?? [] as $s): ?>
                    <span class="schip schip-<?= esc($s) ?>"><?= ucfirst($s) ?></span>
                    <?php endforeach; ?>
                  </td>
                  <td><?= $u['ativo'] ? '<span class="badge bdg-ongoing">Ativo</span>' : '<span class="badge bdg-past">Inativo</span>' ?></td>
                  <td>
                    <div class="actions">
                      <button class="btn-icon gold" onclick="editUser(<?= htmlspecialchars(json_encode($u, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>)" title="Editar">✏️</button>
                      <form method="POST" style="display:inline" onsubmit="return confirm('Excluir este usuário?')">
                        <input type="hidden" name="acao" value="del_user">
                        <input type="hidden" name="id" value="<?= esc($u['id']) ?>">
                        <button type="submit" class="btn-icon red" title="Excluir">🗑️</button>
                      </form>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div id="user-form-panel" class="panel" style="display:none;margin-top:1.5rem">
        <div class="panel-hd"><span class="panel-title" id="user-form-title">Novo usuário</span><button class="modal-close" onclick="closeUserForm()" style="position:static;font-size:1.1rem">✕</button></div>
        <div class="panel-body">
          <form method="POST" id="user-form">
            <input type="hidden" name="acao" id="user-form-acao" value="add_user">
            <input type="hidden" name="id" id="user-id">
            
            <div class="form-grid">
              <div class="f-row">
                <label>Nome Completo</label>
                <input type="text" name="nome" id="user-nome" required>
              </div>
              <div class="f-row">
                <label>Usuário (Login)</label>
                <input type="text" name="usuario" id="user-usuario" required>
              </div>
              <div class="f-row">
                <label>Senha <small id="senha-hint" style="text-transform:none;opacity:.7"></small></label>
                <input type="password" name="senha" id="user-senha">
              </div>
              <div class="f-row">
                <label>Nível de Acesso</label>
                <select name="role" id="user-role">
                  <option value="admin">Administrador</option>
                  <option value="superadmin">Super-Administrador</option>
                  <option value="viewer">Apenas Visualização</option>
                </select>
              </div>
              <div class="f-row c2">
                <label>Sistemas Permitidos</label>
                <div class="ckbox-group">
                  <label class="ckbox-item"><input type="checkbox" name="sistemas[]" value="app" id="sis-app"> App</label>
                  <label class="ckbox-item"><input type="checkbox" name="sistemas[]" value="amigos" id="sis-amigos"> Amigos</label>
                  <label class="ckbox-item"><input type="checkbox" name="sistemas[]" value="envios" id="sis-envios"> Envios</label>
                  <label class="ckbox-item"><input type="checkbox" name="sistemas[]" value="site" id="sis-site"> Site</label>
                </div>
              </div>
              <div class="f-row">
                <label class="ckbox-item" style="margin-top:1rem"><input type="checkbox" name="ativo" id="user-ativo" checked> Usuário Ativo</label>
              </div>
            </div>

            <div style="margin-top:1.5rem;display:flex;gap:.75rem;justify-content:flex-end">
              <button type="button" class="btn btn-outline" onclick="closeUserForm()">Cancelar</button>
              <button type="submit" class="btn btn-gold" style="padding-left:2rem;padding-right:2rem">Salvar Usuário</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /main -->
</div><!-- /layout -->
<?php endif; ?>

<!-- MODAL: AMIGOS (ADD/EDIT) -->
<div class="overlay" id="ov-amigos" onclick="if(event.target===this)closeAmiModal()">
  <div class="modal">
    <button class="modal-close" onclick="closeAmiModal()">✕</button>
    <h2 id="ami-modal-title">Novo cadastro</h2>
    <form id="ami-form">
      <input type="hidden" id="ami-wpp-orig">
      <div class="form-row">
        <label>Nome completo</label>
        <input type="text" id="ami-nome" required>
      </div>
      <div class="form-row">
        <label>E-mail</label>
        <input type="email" id="ami-email">
      </div>
      <div class="form-row">
        <label>WhatsApp (somente números)</label>
        <input type="tel" id="ami-wpp" required>
      </div>
      <div class="form-row">
        <label>Classificação</label>
        <select id="ami-classificacao">
          <option value="interessado">Interessado</option>
          <option value="estudo_biblico">Estudo Bíblico</option>
          <option value="candidato">Candidato</option>
          <option value="batizado">Batizado</option>
          <option value="oracao">Oração</option>
        </select>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-outline" onclick="closeAmiModal()">Cancelar</button>
        <button type="submit" class="btn btn-gold">Salvar</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: AMIGOS EVENTOS (ADD/EDIT) -->
<div class="overlay" id="ov-ami-event" onclick="if(event.target===this)closeAmiEvModal()">
  <div class="modal">
    <button class="modal-close" onclick="closeAmiEvModal()">✕</button>
    <h2 id="ami-ev-modal-title">Novo evento</h2>
    <form id="ami-ev-form">
      <input type="hidden" id="ami-ev-id">
      <div class="form-row">
        <label>Título do Evento</label>
        <input type="text" id="ami-ev-titulo" required>
      </div>
      <div class="form-row">
        <label>Descrição</label>
        <textarea id="ami-ev-descricao"></textarea>
      </div>
      <div class="form-grid">
        <div class="f-row">
          <label>Início</label>
          <input type="datetime-local" id="ami-ev-inicio" required>
        </div>
        <div class="f-row">
          <label>Fim</label>
          <input type="datetime-local" id="ami-ev-fim">
        </div>
      </div>
      <div class="form-row">
        <label>Local</label>
        <input type="text" id="ami-ev-local">
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-outline" onclick="closeAmiEvModal()">Cancelar</button>
        <button type="submit" class="btn btn-gold">Salvar Evento</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: SITE BOTÃO (ADD/EDIT) -->
<div class="overlay" id="ov-site-btn" onclick="if(event.target===this)closeSiteLinkModal()">
  <div class="modal">
    <button class="modal-close" onclick="closeSiteLinkModal()">✕</button>
    <h2 id="site-btn-title">Novo Botão</h2>
    <form id="site-btn-form">
      <input type="hidden" id="site-btn-id-orig">
      <div class="form-row">
        <label>ID (slug, sem espaços)</label>
        <input type="text" id="site-btn-id" placeholder="btn-custom" pattern="[a-z0-9_-]+" required>
      </div>
      <div class="form-row">
        <label>Texto do Botão</label>
        <input type="text" id="site-btn-text" required>
      </div>
      <div class="form-row">
        <label>URL</label>
        <input type="text" id="site-btn-url" placeholder="https:// ou /caminho" required>
      </div>
      <div class="form-row">
        <label class="ckbox-item" style="margin-top:.5rem">
          <input type="checkbox" id="site-btn-featured"> Botão em destaque
        </label>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-outline" onclick="closeSiteLinkModal()">Cancelar</button>
        <button type="submit" class="btn btn-gold">Salvar</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: IMPORTAR LISTA VIA IA -->
<div class="overlay" id="ov-import-lista" onclick="if(event.target===this)closeImportModal()">
  <div class="modal" style="max-width:720px;width:95%">

    <!-- Estágio 1: Upload -->
    <div id="import-stage-upload">
      <div class="modal-hd">
        <span class="modal-title">Importar Lista via IA</span>
        <button class="modal-close" onclick="closeImportModal()">×</button>
      </div>
      <div class="modal-body">
        <div id="import-drop-zone" onclick="document.getElementById('import-file-input').click()" ondragover="event.preventDefault();this.classList.add('drag-over')" ondragleave="this.classList.remove('drag-over')" ondrop="event.preventDefault();this.classList.remove('drag-over');handleImportDrop(event.dataTransfer.files[0])" style="border:2px dashed var(--border);border-radius:12px;padding:2.5rem 1.5rem;text-align:center;cursor:pointer;transition:border-color .2s,background .2s">
          <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="color:var(--text-muted);margin-bottom:.75rem"><path d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <p style="margin:.3rem 0;font-size:.95rem;color:var(--text)">Arraste um arquivo ou clique para selecionar</p>
          <p style="margin:0;font-size:.75rem;color:var(--text-muted)">PDF, TXT, CSV ou XLSX · máx. 10 MB</p>
          <input type="file" id="import-file-input" accept=".txt,.pdf,.csv,.xlsx" style="display:none" onchange="handleImportFile(this.files[0])">
        </div>
        <p style="margin:.875rem 0 0;font-size:.8rem;color:var(--text-muted);text-align:center">O Claude irá identificar e estruturar automaticamente todos os contatos do arquivo.</p>
      </div>
    </div>

    <!-- Estágio 2: Processando -->
    <div id="import-stage-loading" style="display:none">
      <div class="modal-body" style="text-align:center;padding:3rem 2rem">
        <div style="width:44px;height:44px;border:3px solid var(--border);border-top-color:var(--gold);border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 1.25rem"></div>
        <p style="font-size:1rem;font-weight:600;color:var(--text);margin:0 0 .4rem">Analisando com IA…</p>
        <p id="import-loading-filename" style="font-size:.8rem;color:var(--text-muted);margin:0"></p>
      </div>
    </div>

    <!-- Estágio 3: Preview -->
    <div id="import-stage-preview" style="display:none">
      <div class="modal-hd">
        <span class="modal-title">Revisar Contatos Extraídos</span>
        <button class="modal-close" onclick="closeImportModal()">×</button>
      </div>
      <div class="modal-body">
        <div class="form-row" style="margin-bottom:.875rem">
          <label>Nome da lista <span style="color:var(--red)">*</span></label>
          <input type="text" id="import-lista-nome" placeholder="Ex: Retiro 2026, Culto de Páscoa…">
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem">
          <span id="import-preview-count" style="font-size:.82rem;color:var(--text-muted)"></span>
          <button class="btn btn-outline btn-sm" onclick="document.getElementById('import-file-input').click()">Trocar arquivo</button>
        </div>
        <div class="tbl-wrap" style="max-height:300px;overflow-y:auto;border-radius:8px">
          <table class="tbl">
            <thead><tr><th>Nome</th><th>Telefone</th><th>E-mail</th><th>Bairro</th><th>Sexo</th></tr></thead>
            <tbody id="import-preview-list"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-ft" style="display:flex;justify-content:flex-end;gap:.5rem;padding:1rem 1.5rem;border-top:1px solid var(--border)">
        <button class="btn btn-outline" onclick="closeImportModal()">Cancelar</button>
        <button class="btn btn-gold" onclick="saveImportedList()" id="import-save-btn">Incluir no sistema</button>
      </div>
    </div>

    <!-- Estágio 4: Erro -->
    <div id="import-stage-error" style="display:none">
      <div class="modal-hd">
        <span class="modal-title">Erro na importação</span>
        <button class="modal-close" onclick="closeImportModal()">×</button>
      </div>
      <div class="modal-body" style="text-align:center;padding:2rem">
        <p style="color:var(--red);font-size:.95rem;margin:0 0 .5rem" id="import-error-msg"></p>
        <button class="btn btn-outline" onclick="importSetStage('upload')">Tentar novamente</button>
      </div>
    </div>

  </div>
</div>
<style>
#import-drop-zone.drag-over { border-color:var(--gold); background:var(--gold-glow); }
@keyframes spin { to { transform:rotate(360deg); } }
</style>

<!-- MODAL: ENVIOS STATUS -->
<div class="overlay" id="ov-env-status" onclick="if(event.target===this)closeEnvStatus()">
  <div class="modal">
    <h2 id="env-st-title">Disparo em andamento</h2>
    <div style="margin:1.5rem 0">
      <div style="display:flex;justify-content:space-between;margin-bottom:.5rem;font-size:.85rem">
        <span id="env-st-processed">0 / 0</span>
        <span id="env-st-pct">0%</span>
      </div>
      <div style="height:8px;background:var(--surface2);border-radius:10px;overflow:hidden;border:1px solid var(--border)">
        <div id="env-st-bar" style="height:100%;background:var(--gold);width:0%;transition:width .3s"></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-top:1rem">
        <div style="background:var(--surface2);padding:.6rem;border-radius:8px;text-align:center">
          <div style="font-size:.65rem;color:var(--text-muted);text-transform:uppercase">Enviados</div>
          <div id="env-st-sent" style="font-size:1.2rem;font-weight:600;color:var(--green)">0</div>
        </div>
        <div style="background:var(--surface2);padding:.6rem;border-radius:8px;text-align:center">
          <div style="font-size:.65rem;color:var(--text-muted);text-transform:uppercase">Falhas</div>
          <div id="env-st-failed" style="font-size:1.2rem;font-weight:600;color:var(--red)">0</div>
        </div>
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn btn-gold" id="btn-env-st-close" style="display:none" onclick="closeEnvStatus()">Concluir</button>
    </div>
  </div>
</div>

<!-- MODAL: ENVIOS CONTATO (EDITAR) -->
<div class="overlay" id="ov-env-contato" onclick="if(event.target===this)closeEnvContatoModal()">
  <div class="modal">
    <button class="modal-close" onclick="closeEnvContatoModal()">✕</button>
    <h2>Editar Contato</h2>
    <form id="env-contato-form">
      <input type="hidden" id="env-ct-idx">
      <div class="form-grid">
        <div class="f-row"><label>Nome</label><input type="text" id="env-ct-nome"></div>
        <div class="f-row"><label>Telefone</label><input type="text" id="env-ct-tel"></div>
        <div class="f-row"><label>Bairro</label><input type="text" id="env-ct-bairro"></div>
        <div class="f-row"><label>Religião</label><input type="text" id="env-ct-religiao"></div>
        <div class="f-row"><label>Sexo</label>
          <select id="env-ct-sexo" style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:9px;padding:.7rem .9rem;color:var(--text);font-size:.9rem;font-family:inherit;outline:none">
            <option value="">—</option>
            <option value="M">Masculino</option>
            <option value="F">Feminino</option>
          </select>
        </div>
        <div class="f-row"><label>Idade</label><input type="text" id="env-ct-idade"></div>
        <div class="f-row c2"><label>E-mail</label><input type="email" id="env-ct-email"></div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-outline" onclick="closeEnvContatoModal()">Cancelar</button>
        <button type="submit" class="btn btn-gold">Salvar</button>
      </div>
    </form>
  </div>
</div>

<!-- ── MEMBROS: EDITAR MEMBRO ─────────────────────────────── -->
<div class="overlay" id="ov-mb-edit" onclick="if(event.target===this)mbCloseEdit()">
  <div class="modal" style="max-width:680px;padding:0;overflow:hidden">
    <div class="mb-edit-layout">
      <div class="mb-edit-side">
        <div class="mb-photo-ring" onclick="document.getElementById('mb-file-input').click()">
          <img id="mb-edit-photo" src="" alt="">
          <div class="mb-photo-ov"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/></svg></div>
        </div>
        <div id="mb-edit-name" class="mb-edit-name">—</div>
        <div style="font-size:.62rem;color:var(--gold);text-transform:uppercase;letter-spacing:.08em">Painel de Edição</div>
        <input type="file" id="mb-file-input" style="display:none" accept="image/*">
        <button onclick="mbCloseEdit()" class="btn btn-outline btn-sm" style="margin-top:.5rem">Fechar</button>
      </div>
      <div class="mb-edit-form">
        <div class="mb-edit-body">
          <div style="font-size:.68rem;font-weight:600;color:var(--gold);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.75rem">Contato</div>
          <div class="g2">
            <div class="mb-fr fr"><label>E-mail</label><input id="mb-inp-email" type="email"></div>
            <div class="mb-fr fr"><label>WhatsApp</label><input id="mb-inp-whats" type="text" placeholder="DDD + Número"></div>
          </div>
          <hr style="border:none;border-top:1px solid var(--border);margin:.875rem 0">
          <div style="font-size:.68rem;font-weight:600;color:var(--gold);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.75rem">Localização</div>
          <div class="g2">
            <div class="mb-fr fr"><label>CEP</label><input id="mb-inp-cep" type="text" maxlength="9"></div>
            <div class="mb-fr fr c2"><label>Logradouro</label><input id="mb-inp-street" type="text"></div>
            <div class="mb-fr fr"><label>Número</label><input id="mb-inp-number" type="text"></div>
            <div class="mb-fr fr"><label>UF</label><input id="mb-inp-state" type="text" maxlength="2" style="text-transform:uppercase"></div>
            <div class="mb-fr fr c2"><label>Bairro</label><input id="mb-inp-nbhd" type="text"></div>
            <div class="mb-fr fr c2"><label>Cidade</label><input id="mb-inp-city" type="text"></div>
          </div>
        </div>
        <div style="padding:1rem 1.25rem;border-top:1px solid var(--border)">
          <button class="btn btn-gold" style="width:100%;padding:.75rem" onclick="mbSaveMember()">Salvar Alterações</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── MEMBROS: MENSAGEM DIRETA ───────────────────────────── -->
<div class="overlay" id="ov-mb-direct" onclick="if(event.target===this)mbCloseDirectMsg()">
  <div class="modal" style="max-width:440px">
    <h2 style="color:var(--green)">Mensagem Individual</h2>
    <div id="mb-direct-target" style="font-size:.82rem;color:var(--text-muted);margin-bottom:1rem">—</div>
    <button class="modal-close" onclick="mbCloseDirectMsg()">✕</button>
    <div class="form-row">
      <label>Mensagem</label>
      <textarea id="mb-direct-text" rows="5" placeholder="Digite a mensagem…"></textarea>
    </div>
    <button class="btn btn-gold" style="width:100%;margin-top:.5rem" onclick="mbSendDirectMsg()">Enviar Agora</button>
  </div>
</div>

<!-- ── MEMBROS: RECORTAR FOTO ─────────────────────────────── -->
<div class="overlay" id="ov-mb-crop">
  <div class="modal" style="max-width:480px">
    <h2 style="text-align:center">Ajustar Foto</h2>
    <div id="mb-crop-container"></div>
    <div style="display:flex;gap:.75rem">
      <button class="btn btn-outline" style="flex:1" onclick="mbCloseCrop()">Cancelar</button>
      <button class="btn btn-gold" style="flex:1" onclick="mbSaveCrop()">Salvar</button>
    </div>
  </div>
</div>

<!-- ── MEMBROS: COMUNICADO GERAL ──────────────────────────── -->
<div class="overlay" id="ov-mb-broadcast" onclick="if(event.target===this)mbCloseBroadcast()">
  <div class="modal" style="max-width:560px;max-height:90vh;overflow-y:auto">
    <h2>Comunicado Geral</h2>
    <button class="modal-close" onclick="mbCloseBroadcast()">✕</button>
    <div class="mb-tog">
      <button class="mb-tog-opt on" id="mb-tab-wpp" onclick="mbSwitchBroadcastTab('WHATSAPP')">WhatsApp</button>
      <button class="mb-tog-opt" id="mb-tab-email" onclick="mbSwitchBroadcastTab('EMAIL')">E-mail</button>
    </div>
    <div id="mb-email-fields" style="display:none">
      <div class="form-row"><label>Assunto</label><input type="text" id="mb-broadcast-subject"></div>
    </div>
    <div class="form-row">
      <label>Mensagem</label>
      <div style="margin-bottom:.4rem">
        <button type="button" onclick="mbInsertTag('mb-broadcast-message','{nome}')" class="btn-icon" style="width:auto;padding:2px 10px;font-size:.72rem">{nome}</button>
      </div>
      <textarea id="mb-broadcast-message" rows="5"></textarea>
    </div>
    <div class="mb-sched">
      <div class="mb-sched-row">
        <label for="mb-check-sched">Agendar envio?</label>
        <input type="checkbox" id="mb-check-sched" onchange="mbToggleSched()">
      </div>
      <div id="mb-sched-fields" style="display:none;margin-top:.75rem">
        <input type="datetime-local" id="mb-broadcast-date" style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:.55rem .8rem;color:var(--text);font-family:inherit;outline:none">
      </div>
    </div>
    <div class="mb-prog" id="mb-prog-wrap" style="display:none">
      <div class="mb-prog-head"><span>Enviando…</span><span id="mb-prog-text">0 / 0</span></div>
      <div class="mb-prog-bar"><div id="mb-prog-fill" class="mb-prog-fill"></div></div>
    </div>
    <button class="btn btn-gold" style="width:100%;margin-top:1rem;padding:.75rem" onclick="mbTriggerBroadcast()">Confirmar</button>
  </div>
</div>

<!-- TOAST -->
<div id="toast"></div>

<script>
// Lógica de Amigos: Cadastros
async function saveAmigo(e) {
    e.preventDefault();
    const action = document.getElementById('ami-wpp-orig').value ? 'amigos_edit' : 'amigos_add';
    const payload = {
        wpp_original: document.getElementById('ami-wpp-orig').value,
        nome: document.getElementById('ami-nome').value,
        email: document.getElementById('ami-email').value,
        wpp: document.getElementById('ami-wpp').value,
        classificacao: document.getElementById('ami-classificacao').value
    };
    const res = await fetch('api.php?action=' + action, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    });
    if (res.ok) {
        showToast('success', 'Cadastro salvo!');
        closeAmiModal();
        loadAmigosCadastros();
    }
}
document.getElementById('ami-form')?.addEventListener('submit', saveAmigo);

function openAmiAdd() {
    document.getElementById('ami-modal-title').textContent = 'Novo cadastro';
    document.getElementById('ami-wpp-orig').value = '';
    document.getElementById('ami-form').reset();
    document.getElementById('ov-amigos').classList.add('open');
}
function closeAmiModal() { document.getElementById('ov-amigos').classList.remove('open'); }

function editAmigoIdx(idx) {
    const data = window._amiData[idx];
    if (!data) return;
    document.getElementById('ami-modal-title').textContent = 'Editar cadastro';
    document.getElementById('ami-wpp-orig').value = data.wpp;
    document.getElementById('ami-nome').value = data.nome;
    document.getElementById('ami-email').value = data.email || '';
    document.getElementById('ami-wpp').value = data.wpp;
    document.getElementById('ami-classificacao').value = data.classificacao;
    document.getElementById('ov-amigos').classList.add('open');
}

async function deleteAmigo(wpp) {
    if (!confirm('Excluir este cadastro?')) return;
    const res = await fetch('api.php?action=amigos_delete', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({wpp})
    });
    if (res.ok) { showToast('success', 'Cadastro removido'); loadAmigosCadastros(); }
}

// Lógica de Amigos: Eventos
async function saveAmiEvent(e) {
    e.preventDefault();
    const payload = {
        id: document.getElementById('ami-ev-id').value,
        titulo: document.getElementById('ami-ev-titulo').value,
        descricao: document.getElementById('ami-ev-descricao').value,
        data_inicio: document.getElementById('ami-ev-inicio').value,
        data_fim: document.getElementById('ami-ev-fim').value,
        local: document.getElementById('ami-ev-local').value
    };
    const res = await fetch('api.php?action=amigos_eventos_save', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    });
    if (res.ok) {
        showToast('success', 'Evento salvo!');
        closeAmiEvModal();
        location.reload(); // Recarrega para atualizar a lista de eventos (que é via PHP)
    }
}
document.getElementById('ami-ev-form')?.addEventListener('submit', saveAmiEvent);

function openAmiEvAdd() {
    document.getElementById('ami-ev-modal-title').textContent = 'Novo evento';
    document.getElementById('ami-ev-id').value = '';
    document.getElementById('ami-ev-form').reset();
    document.getElementById('ov-ami-event').classList.add('open');
}
function closeAmiEvModal() { document.getElementById('ov-ami-event').classList.remove('open'); }

function editAmiEvent(data) {
    document.getElementById('ami-ev-modal-title').textContent = 'Editar evento';
    document.getElementById('ami-ev-id').value = data.id;
    document.getElementById('ami-ev-titulo').value = data.titulo;
    document.getElementById('ami-ev-descricao').value = data.descricao || '';
    document.getElementById('ami-ev-inicio').value = data.data_inicio;
    document.getElementById('ami-ev-fim').value = data.data_fim || '';
    document.getElementById('ami-ev-local').value = data.local || '';
    document.getElementById('ov-ami-event').classList.add('open');
}

async function deleteAmiEvent(id) {
    if (!confirm('Excluir este evento permanentemente?')) return;
    const res = await fetch('api.php?action=amigos_eventos_delete', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id})
    });
    if (res.ok) { showToast('success', 'Evento excluído'); location.reload(); }
}

// Memória global
window._amiData        = [];
window._envData        = [];
window._dispListasData = [];   // dados mesclados das listas selecionadas p/ disparo
window._dispListas     = [];   // slugs selecionados p/ disparo (multi)
window._currentEnvList = '';
window._amiClass       = '';
window._dispSource     = 'listas';

// Re-renderizar tabelas
async function loadAmigosCadastros() {
    const list = document.getElementById('ami-membros-list');
    if (!list) return;
    try {
        const r = await fetch('api.php?action=amigos_cadastros');
        const data = await r.json();
        window._amiData = data;
        window._amiLoaded = true;
        renderAmiContatos(data);
    } catch(e) { list.innerHTML = '<tr><td colspan="4" class="empty-msg" style="color:var(--red)">Erro ao carregar</td></tr>'; }
}

function renderAmiContatos(data) {
    const list = document.getElementById('ami-membros-list');
    if (!list) return;
    if (!data.length) { list.innerHTML = '<tr><td colspan="4" class="empty-msg">Nenhum cadastro</td></tr>'; return; }
    list.innerHTML = data.map((c) => {
        const i = window._amiData.indexOf(c);
        return `<tr>
            <td><strong>${c.nome || '—'}</strong></td>
            <td>${c.wpp || '—'}</td>
            <td><span class="badge" style="background:var(--surface2);border:1px solid var(--border)">${c.classificacao || '—'}</span></td>
            <td>
                <div class="actions">
                    <button class="btn-icon gold" onclick='editAmigoIdx(${i})' title="Editar">✏️</button>
                    <button class="btn-icon red" onclick="deleteAmigo('${c.wpp}')" title="Excluir">🗑️</button>
                </div>
            </td>
        </tr>`;
    }).join('');
}

function filterAmiContatos() {
    const q  = (document.getElementById('ami-search')?.value || '').toLowerCase();
    const cl = window._amiClass;
    const fil = window._amiData.filter(c => {
        if (cl && (c.classificacao || '') !== cl) return false;
        if (q && !(
            (c.nome||'').toLowerCase().includes(q) ||
            (c.wpp||'').toLowerCase().includes(q) ||
            (c.email||'').toLowerCase().includes(q)
        )) return false;
        return true;
    });
    renderAmiContatos(fil);
}

function setAmiClass(cl, btn) {
    window._amiClass = cl;
    document.querySelectorAll('#ami-membros .flt-btn').forEach(b => b.classList.remove('on'));
    if (btn) btn.classList.add('on');
    filterAmiContatos();
}

const SECS   = ['overview','app','amigos','eventos','envios','site','usuarios'];
const TITLES = {overview:'Visão Geral',app:'Membros',amigos:'Amigos',eventos:'Eventos',envios:'Envios',site:'Site',usuarios:'Usuários'};

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

    if (id === 'envios' && !window._envLoaded)       loadEnviosContatos();
    if (id === 'envios' && !window._dispListasData.length) loadDispListasData();
    if (id === 'amigos' && !window._amiLoaded) loadAmigosCadastros();
    if (id === 'app'    && !window._mbLoaded)  mbEnsureData();
}

function showSub(btn) {
    const parent = btn.closest('.section');
    const target = btn.dataset.target;
    parent.querySelectorAll('.sub-tab').forEach(b => b.classList.toggle('on', b === btn));
    parent.querySelectorAll('.sub-sec').forEach(s => s.style.display = s.id === target ? 'block' : 'none');
}

async function changeEnvList(list, btn) {
    window._currentEnvList = list;
    document.querySelectorAll('#env-list-selector .btn').forEach(b => b.classList.toggle('on', b === btn));
    window._envLoaded = false;
    loadEnviosContatos();
}

async function loadEnviosContatos() {
    const list = document.getElementById('env-contatos-list');
    if (!list) return;
    list.innerHTML = '<tr><td colspan="6" class="empty-msg">Carregando contatos...</td></tr>';
    try {
        const r = await fetch(`api.php?action=envios_contatos&file=${window._currentEnvList}`);
        const data = await r.json();
        window._envData = data.map((c, i) => ({...c, _idx: i}));
        window._envLoaded = true;
        renderEnvContatos(window._envData);
        populateEnvFilters();
    } catch(e) { list.innerHTML = '<tr><td colspan="6" class="empty-msg" style="color:var(--red)">Erro ao carregar contatos</td></tr>'; }
}

function renderEnvContatos(data) {
    const list = document.getElementById('env-contatos-list');
    if (!data.length) { list.innerHTML = '<tr><td colspan="6" class="empty-msg">Nenhum contato</td></tr>'; return; }

    list.innerHTML = data.slice(0, 100).map((c) => `
        <tr>
            <td><strong>${c.nome || '—'}</strong></td>
            <td>${c.bairro || '—'}</td>
            <td>${c.religiao || '—'}</td>
            <td><span style="color:var(--green)">${c.telefone || '—'}</span></td>
            <td>${c.opt_out ? '<span class="badge bdg-past">Opt-out</span>' : '<span class="badge bdg-ongoing">Ativo</span>'}</td>
            <td>
                <div class="actions">
                    <button class="btn-icon gold" title="Editar" onclick="editEnvContato(${c._idx})">✏️</button>
                    <button class="btn-icon${c.opt_out ? ' gold' : ''}" title="${c.opt_out ? 'Reativar' : 'Opt-out'}" onclick="toggleEnvOptout(${c._idx})">${c.opt_out ? '✅' : '🚫'}</button>
                </div>
            </td>
        </tr>
    `).join('') + (data.length > 100 ? `<tr><td colspan="6" style="text-align:center;padding:.5rem;font-size:.75rem;color:var(--text-muted)">+ ${data.length - 100} registros ocultos (use a busca)</td></tr>` : '');

}

function filterEnvContatos() {
    const q = document.getElementById('env-search').value.toLowerCase();
    const fil = !q ? window._envData : window._envData.filter(c =>
        (c.nome||'').toLowerCase().includes(q) ||
        (c.bairro||'').toLowerCase().includes(q) ||
        (c.religiao||'').toLowerCase().includes(q) ||
        (c.telefone||'').toLowerCase().includes(q)
    );
    renderEnvContatos(fil);
}

// Eventos: filtro por status
function filterEventos(st, btn) {
    document.querySelectorAll('#sec-eventos .flt-btn').forEach(b => b.classList.toggle('on', b === btn));
    document.querySelectorAll('#ev-list .ev-card').forEach(card => {
        card.style.display = (st === 'all' || card.dataset.evSt === st) ? 'block' : 'none';
    });
}

// Envios: filtros de disparo
window._dispSexo    = '';
window._dispVip     = '';
window._dispChannel = 'whatsapp';

function setDispChannel(ch) {
    window._dispChannel = ch;
    document.getElementById('ch-wpp')?.classList.toggle('on', ch === 'whatsapp');
    document.getElementById('ch-email')?.classList.toggle('on', ch === 'email');
    document.getElementById('ch-both')?.classList.toggle('on', ch === 'both');
    const subjectRow = document.getElementById('env-subject-row');
    if (subjectRow) subjectRow.style.display = (ch === 'email' || ch === 'both') ? '' : 'none';
    updateEnvChInfo();
}

function updateEnvChInfo() {
    const el = document.getElementById('env-ch-info');
    if (!el) return;
    const contacts = window._dispFiltered ?? [];
    if (!contacts.length) { el.textContent = ''; return; }
    const isMembros = window._dispSource === 'membros';
    const isAmigos  = window._dispSource === 'amigos';
    const wppField  = isAmigos ? 'wpp' : isMembros ? 'WHATS' : 'telefone';
    const emailField = isMembros ? 'EMAIL' : 'email';
    const withWpp   = contacts.filter(c => c[wppField]).length;
    const withEmail = contacts.filter(c => c[emailField]).length;
    const parts = [];
    if (window._dispChannel !== 'email') parts.push(`${withWpp} com WhatsApp`);
    if (window._dispChannel !== 'whatsapp') parts.push(`${withEmail} com e-mail`);
    el.textContent = parts.join(' · ');
}

function setDispSource(src) {
    window._dispSource = src;
    document.getElementById('disp-src-listas')?.classList.toggle('on', src === 'listas');
    document.getElementById('disp-src-amigos')?.classList.toggle('on', src === 'amigos');
    document.getElementById('disp-src-membros')?.classList.toggle('on', src === 'membros');
    const lf = document.getElementById('disp-listas-filters');
    const af = document.getElementById('disp-amigos-filters');
    const mf = document.getElementById('disp-membros-filters');
    if (lf) lf.style.display = src === 'listas'  ? 'flex' : 'none';
    if (af) af.style.display = src === 'amigos'  ? 'flex' : 'none';
    if (mf) mf.style.display = src === 'membros' ? 'flex' : 'none';
    const note = document.getElementById('env-disp-total-note');
    if (note) note.textContent = src === 'amigos' ? 'de amigos cadastrados' : src === 'membros' ? 'de membros do app' : 'excluindo opt-outs';
    if (src === 'amigos' && !window._amiLoaded) {
        loadAmigosCadastros().then(() => applyEnvDispFilters());
    } else if (src === 'membros' && !window._mbLoaded) {
        mbEnsureData().then(() => { populateMembrosFilters(); applyEnvDispFilters(); });
    } else if (src === 'listas' && !window._dispListasData.length) {
        loadDispListasData();
    } else {
        if (src === 'membros') populateMembrosFilters();
        applyEnvDispFilters();
    }
}

async function toggleDispLista(slug, btn) {
    const idx = window._dispListas.indexOf(slug);
    if (idx === -1) {
        window._dispListas.push(slug);
        btn.classList.add('on');
    } else {
        if (window._dispListas.length === 1) return; // mínimo 1 lista
        window._dispListas.splice(idx, 1);
        btn.classList.remove('on');
    }
    await loadDispListasData();
}

async function loadDispListasData() {
    if (!window._dispListas.length) return;
    const slugs = window._dispListas;

    // Busca todas as listas selecionadas em paralelo
    const results = await Promise.all(
        slugs.map(slug => fetch(`api.php?action=envios_contatos&file=${slug}`).then(r => r.json()).catch(() => []))
    );

    // Mescla e deduplica por telefone (mantém primeiro encontrado)
    const seen = new Set();
    const merged = [];
    results.flat().forEach((c, i) => {
        const key = (c.telefone || c.telefone2 || c.email || c.nome || i).toString().replace(/\D/g, '') || String(i);
        if (!seen.has(key)) { seen.add(key); merged.push({...c, _idx: merged.length}); }
    });
    window._dispListasData = merged;
    populateEnvFilters();
    applyEnvDispFilters();
}

function populateEnvFilters() {
    const base = window._dispListasData?.length ? window._dispListasData : (window._envData || []);
    if (!base.length) return;
    const bairros   = [...new Set(base.map(c => c.bairro).filter(Boolean))].sort();
    const religioes = [...new Set(base.map(c => c.religiao).filter(Boolean))].sort();
    const sel1 = document.getElementById('disp-f-bairro');
    const sel2 = document.getElementById('disp-f-religiao');
    if (sel1) { const cur = sel1.value; sel1.innerHTML = '<option value="">Todos</option>' + bairros.map(v => `<option value="${v}"${v===cur?' selected':''}>${v}</option>`).join(''); }
    if (sel2) { const cur = sel2.value; sel2.innerHTML = '<option value="">Todas</option>' + religioes.map(v => `<option value="${v}"${v===cur?' selected':''}>${v}</option>`).join(''); }
    applyEnvDispFilters();
}

function applyEnvDispFilters() {
    if (window._dispSource === 'amigos') {
        const cl = document.getElementById('disp-f-class')?.value || '';
        window._dispFiltered = (window._amiData || []).filter(c => {
            if (cl && (c.classificacao || '') !== cl) return false;
            return true;
        });
    } else if (window._dispSource === 'membros') {
        const cidade  = document.getElementById('disp-f-cidade')?.value || '';
        const contato = window._dispMbContato || '';
        window._dispFiltered = (mbMembers || []).filter(m => {
            if (cidade && (m.CITY || '') !== cidade) return false;
            if (contato === 'wpp'   && !m.WHATS) return false;
            if (contato === 'email' && !m.EMAIL) return false;
            return !!(m.WHATS || m.EMAIL);
        });
    } else {
        const bairro   = document.getElementById('disp-f-bairro')?.value   || '';
        const religiao = document.getElementById('disp-f-religiao')?.value || '';
        window._dispFiltered = (window._dispListasData || []).filter(c => {
            if (c.opt_out) return false;
            if (bairro   && (c.bairro   || '') !== bairro)   return false;
            if (religiao && (c.religiao || '') !== religiao) return false;
            if (window._dispSexo && (c.sexo || '') !== window._dispSexo) return false;
            if (window._dispVip  && (c.vip  || '') !== window._dispVip)  return false;
            return true;
        });
    }
    const el = document.getElementById('env-disp-total');
    if (el) el.textContent = window._dispFiltered.length;
    updateEnvChInfo();
}

function setDispSexo(v) {
    window._dispSexo = v;
    document.getElementById('disp-sx-all')?.classList.toggle('on', !v);
    document.getElementById('disp-sx-m')?.classList.toggle('on', v === 'Masculino');
    document.getElementById('disp-sx-f')?.classList.toggle('on', v === 'Feminino');
    applyEnvDispFilters();
}

function setDispVip(v) {
    window._dispVip = v;
    document.getElementById('disp-vip-all')?.classList.toggle('on', !v);
    document.getElementById('disp-vip-y')?.classList.toggle('on', !!v);
    applyEnvDispFilters();
}

window._dispMbContato = '';
function setDispMbContato(v) {
    window._dispMbContato = v;
    document.getElementById('disp-mb-ch-all')?.classList.toggle('on', !v);
    document.getElementById('disp-mb-ch-wpp')?.classList.toggle('on', v === 'wpp');
    document.getElementById('disp-mb-ch-email')?.classList.toggle('on', v === 'email');
    applyEnvDispFilters();
}

function populateMembrosFilters() {
    const cidades = [...new Set((mbMembers || []).map(m => m.CITY).filter(Boolean))].sort();
    const sel = document.getElementById('disp-f-cidade');
    if (sel) { const cur = sel.value; sel.innerHTML = '<option value="">Todas</option>' + cidades.map(v => `<option value="${v}"${v===cur?' selected':''}>${v}</option>`).join(''); }
}

function resetEnvFilters() {
    const s1 = document.getElementById('disp-f-bairro');
    const s2 = document.getElementById('disp-f-religiao');
    const s3 = document.getElementById('disp-f-class');
    const s4 = document.getElementById('disp-f-cidade');
    if (s1) s1.value = '';
    if (s2) s2.value = '';
    if (s3) s3.value = '';
    if (s4) s4.value = '';
    setDispSexo('');
    setDispVip('');
    setDispMbContato('');
}

async function startEnviosDisparo() {
    const name    = (document.getElementById('env-disp-name')?.value || '').trim();
    const msg     = document.getElementById('env-disp-msg').value;
    const subject = document.getElementById('env-disp-subject')?.value || '';
    const channel = window._dispChannel || 'whatsapp';
    const raw     = window._dispFiltered ?? (window._envData || []).filter(c => !c.opt_out);
    if (!name) return alert('Dê um nome para este disparo antes de enviar.');
    if (!msg)  return alert('Digite a mensagem.');
    if (!raw.length) return alert('Nenhum contato no público selecionado.');
    if (!confirm(`Iniciar disparo "${name}" para ${raw.length} contato(s) via ${channel}?`)) return;
    // Normaliza campos por fonte
    const contacts = raw.map(c => {
        if (window._dispSource === 'amigos')  return { nome: c.nome,  telefone: c.wpp,   email: c.email };
        if (window._dispSource === 'membros') return { nome: c.NAME,  telefone: c.WHATS, email: c.EMAIL };
        return c;
    });
    const res = await fetch('api.php?action=envios_disparar', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ name, message: msg, subject, contacts, channel })
    });
    if (res.ok) { const data = await res.json(); pollEnvStatus(data.token); }
    else alert('Erro ao iniciar disparo.');
}

let envPollTimer = null;
function pollEnvStatus(token) {
    document.getElementById('ov-env-status').classList.add('open');
    document.getElementById('btn-env-st-close').style.display = 'none';
    envPollTimer = setInterval(async () => {
        const res  = await fetch(`api.php?action=envios_status&token=${token}`);
        const data = await res.json();
        const pct  = data.total > 0 ? Math.round(data.processed / data.total * 100) : 0;
        document.getElementById('env-st-processed').textContent = `${data.processed} / ${data.total}`;
        document.getElementById('env-st-pct').textContent = `${pct}%`;
        document.getElementById('env-st-bar').style.width = `${pct}%`;
        document.getElementById('env-st-sent').textContent   = data.sent;
        document.getElementById('env-st-failed').textContent = data.failed;
        if (data.status === 'done' || data.processed >= data.total) {
            clearInterval(envPollTimer);
            document.getElementById('btn-env-st-close').style.display = 'block';
            document.getElementById('env-st-title').textContent = 'Disparo Concluído';
        }
    }, 2000);
}
function closeEnvStatus() { document.getElementById('ov-env-status').classList.remove('open'); if (envPollTimer) clearInterval(envPollTimer); }

function insertTag(tag) {
    const el = document.getElementById('env-disp-msg');
    const s = el.selectionStart, e = el.selectionEnd;
    el.value = el.value.substring(0, s) + tag + el.value.substring(e);
    el.focus();
}

// Lógica de Envios: Editar / Opt-out de contato
function editEnvContato(idx) {
    const c = window._envData[idx];
    if (!c) return;
    document.getElementById('env-ct-idx').value      = idx;
    document.getElementById('env-ct-nome').value     = c.nome      || '';
    document.getElementById('env-ct-tel').value      = c.telefone  || '';
    document.getElementById('env-ct-bairro').value   = c.bairro    || '';
    document.getElementById('env-ct-religiao').value = c.religiao  || '';
    document.getElementById('env-ct-sexo').value     = c.sexo      || '';
    document.getElementById('env-ct-idade').value    = c.idade     || '';
    document.getElementById('env-ct-email').value    = c.email     || '';
    document.getElementById('ov-env-contato').classList.add('open');
}
function closeEnvContatoModal() { document.getElementById('ov-env-contato').classList.remove('open'); }

async function saveEnvContato(e) {
    e.preventDefault();
    const idx = parseInt(document.getElementById('env-ct-idx').value);
    const payload = {
        idx,
        fields: {
            nome:     document.getElementById('env-ct-nome').value,
            telefone: document.getElementById('env-ct-tel').value,
            bairro:   document.getElementById('env-ct-bairro').value,
            religiao: document.getElementById('env-ct-religiao').value,
            sexo:     document.getElementById('env-ct-sexo').value,
            idade:    document.getElementById('env-ct-idade').value,
            email:    document.getElementById('env-ct-email').value,
        }
    };
    const res = await fetch('api.php?action=envios_update', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    });
    if (res.ok) {
        showToast('success', 'Contato atualizado!');
        closeEnvContatoModal();
        window._envLoaded = false;
        loadEnviosContatos();
    } else {
        showToast('error', 'Erro ao salvar contato.');
    }
}
document.getElementById('env-contato-form')?.addEventListener('submit', saveEnvContato);

async function toggleEnvOptout(idx) {
    const c = window._envData[idx];
    if (!c) return;
    const activate = !!c.opt_out;
    const msg = activate ? 'Reativar este contato?' : 'Aplicar opt-out a este contato?';
    if (!confirm(msg)) return;
    const res = await fetch('api.php?action=envios_optout', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({idx, activate})
    });
    if (res.ok) {
        showToast('success', activate ? 'Contato reativado.' : 'Opt-out aplicado.');
        window._envLoaded = false;
        loadEnviosContatos();
    } else {
        showToast('error', 'Erro ao atualizar opt-out.');
    }
}

// ── Site: botões CRUD ──
window._siteLinks = null;

async function loadSiteLinks() {
    try {
        const r = await fetch('api.php?action=site_links');
        const d = await r.json();
        window._siteLinks = d;
        renderSiteBtns(d.buttons || []);
        const insta = document.getElementById('ed-social-insta');
        const yt    = document.getElementById('ed-social-yt');
        if (insta) insta.value = d.social?.instagram || '';
        if (yt)    yt.value   = d.social?.youtube   || '';
    } catch(e) { console.error('Erro ao carregar links do site', e); }
}

function renderSiteBtns(buttons) {
    const tbody = document.getElementById('site-btns-list');
    if (!tbody) return;
    if (!buttons.length) { tbody.innerHTML = '<tr><td colspan="4" class="empty-msg">Nenhum botão cadastrado</td></tr>'; return; }
    tbody.innerHTML = buttons.map(b => `
        <tr>
            <td><strong>${b.text}</strong></td>
            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;font-size:.78rem;color:var(--text-muted)">${b.url}</td>
            <td>${b.featured ? '<span class="badge bdg-ongoing">Sim</span>' : '<span class="badge bdg-past">Não</span>'}</td>
            <td>
                <div class="actions">
                    <button class="btn-icon gold" onclick='openSiteLinkModal(${JSON.stringify(b)})' title="Editar">✏️</button>
                    <button class="btn-icon red" onclick="deleteSiteLink('${b.id}','${b.text}')" title="Excluir">🗑️</button>
                </div>
            </td>
        </tr>
    `).join('');
}

function openSiteLinkModal(data) {
    const isEdit = !!data;
    document.getElementById('site-btn-title').textContent = isEdit ? 'Editar Botão' : 'Novo Botão';
    document.getElementById('site-btn-id-orig').value   = isEdit ? data.id       : '';
    document.getElementById('site-btn-id').value        = isEdit ? data.id       : '';
    document.getElementById('site-btn-text').value      = isEdit ? data.text     : '';
    document.getElementById('site-btn-url').value       = isEdit ? data.url      : '';
    document.getElementById('site-btn-featured').checked = isEdit ? !!data.featured : false;
    document.getElementById('site-btn-id').readOnly     = isEdit;
    document.getElementById('ov-site-btn').classList.add('open');
}
function closeSiteLinkModal() { document.getElementById('ov-site-btn').classList.remove('open'); }

async function saveSiteLink(e) {
    e.preventDefault();
    const payload = {
        id:       document.getElementById('site-btn-id').value.trim(),
        text:     document.getElementById('site-btn-text').value.trim(),
        url:      document.getElementById('site-btn-url').value.trim(),
        featured: document.getElementById('site-btn-featured').checked,
    };
    const res = await fetch('api.php?action=site_link_save', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    });
    if (res.ok) {
        const d = await res.json();
        window._siteLinks = d;
        renderSiteBtns(d.buttons || []);
        closeSiteLinkModal();
        showToast('success', 'Botão salvo!');
    } else showToast('error', 'Erro ao salvar botão.');
}
document.getElementById('site-btn-form')?.addEventListener('submit', saveSiteLink);

async function deleteSiteLink(id, text) {
    if (!confirm(`Remover o botão "${text}" do site?`)) return;
    const res = await fetch('api.php?action=site_link_delete', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id})
    });
    if (res.ok) {
        const d = await res.json();
        window._siteLinks = d;
        renderSiteBtns(d.buttons || []);
        showToast('success', 'Botão removido.');
    } else showToast('error', 'Erro ao remover botão.');
}

async function saveSocialLinks() {
    const payload = {
        instagram: document.getElementById('ed-social-insta')?.value.trim() || '',
        youtube:   document.getElementById('ed-social-yt')?.value.trim()    || '',
    };
    const res = await fetch('api.php?action=site_social_save', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    });
    if (res.ok) showToast('success', 'Redes sociais atualizadas!');
    else        showToast('error',   'Erro ao salvar.');
}

function toggleSb() { document.getElementById('sidebar')?.classList.toggle('open'); document.getElementById('backdrop')?.classList.toggle('show'); }
function closeSb()  { document.getElementById('sidebar')?.classList.remove('open'); document.getElementById('backdrop')?.classList.remove('show'); }

function showToast(type, msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show t-' + type;
    clearTimeout(t._timer);
    t._timer = setTimeout(() => t.classList.remove('show'), 4000);
}

<?php if ($flash): ?>
showToast('<?= esc($flash['type']) ?>', '<?= esc(addslashes($flash['msg'])) ?>');
<?php endif; ?>

// ── Membros Admin ─────────────────────────────────────────────────────────────
const MB_API   = 'https://cms.osmota.org';
const MB_COL   = 'COMUNIDADE_SER';
const MB_CFGS  = 'CONFIGURACOES_AUTOMACOES';
const MB_LOGS  = 'LOGS_AUTOMACOES';
const MB_SCHED = 'COMUNICACOES_AGENDADAS';
const MB_EVO   = 'https://evolution.osmota.org';
const MB_EVO_K = '1E0C076ACE4B-4974-8450-E622B0129B6F';
const MB_EVO_I = 'ComunidadeSer';

let mbToken        = null;
let mbMembers      = [];
let mbEditingId    = null;
let mbDirectMember = null;
let mbCroppie      = null;
let mbBcastMode    = 'WHATSAPP';
window._mbLoaded   = false;

// Obtém token via sessão dashboard, depois carrega dados
async function mbEnsureData() {
    if (window._mbLoaded) return;
    if (!mbToken) {
        try {
            const r = await fetch('/app/token.php', { credentials: 'same-origin' });
            if (r.ok) {
                const d = await r.json();
                if (d.token) mbToken = d.token;
            }
        } catch {}
    }
    if (mbToken) await mbLoadData();
    else document.getElementById('mb-members-list').innerHTML = '<div class="empty-msg" style="color:var(--red)">Sem acesso ao Directus. Configure app/config.php.</div>';
}

async function mbLoadData() {
    const list = document.getElementById('mb-members-list');
    if (list) list.innerHTML = '<div class="empty-msg">Carregando…</div>';
    try {
        const r = await fetch(`${MB_API}/items/${MB_COL}?limit=-1&sort=NAME`, { headers: { 'Authorization': `Bearer ${mbToken}` } });
        const d = await r.json();
        mbMembers = d.data || [];
        window._mbLoaded = true;
        mbRenderMembers(mbMembers);
    } catch { if (list) list.innerHTML = '<div class="empty-msg" style="color:var(--red)">Erro de conexão com Directus.</div>'; }
}

function mbRenderMembers(members) {
    const el = document.getElementById('mb-stat-total');  if (el) el.textContent = mbMembers.length;
    const ep = document.getElementById('mb-stat-photo');  if (ep) ep.textContent = mbMembers.filter(m => m.PHOTO).length;
    const ew = document.getElementById('mb-stat-whats');  if (ew) ew.textContent = mbMembers.filter(m => m.WHATS).length;
    const ec = document.getElementById('mb-stat-cep');    if (ec) ec.textContent = mbMembers.filter(m => m.STREET).length;
    const lc = document.getElementById('mb-list-count');  if (lc) lc.textContent = members.length;
    const list = document.getElementById('mb-members-list');
    if (!list) return;
    if (!members.length) { list.innerHTML = '<div class="empty-msg">Nenhum membro encontrado.</div>'; return; }
    list.innerHTML = members.map(m => `
        <div class="mb-row">
            <div class="mb-info" onclick="mbOpenEdit(${m.id})">
                <img src="${m.PHOTO ? MB_API+'/assets/'+m.PHOTO+'?width=80&height=80&fit=cover' : 'https://ui-avatars.com/api/?name='+encodeURIComponent(m.NAME)+'&background=1e3a8a&color=fff&size=80'}" class="mb-avatar">
                <div style="min-width:0">
                    <div class="mb-name">${m.NAME || '—'}</div>
                    <div class="mb-city">${m.CITY || 'S/ Cidade'}</div>
                </div>
            </div>
            <div class="mb-act">
                ${m.WHATS ? `<button onclick="mbOpenDirectMsg(${m.id})" class="mb-act-btn wpp" title="Mensagem direta"><svg fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/></svg></button>` : ''}
                <button onclick="mbOpenEdit(${m.id})" class="mb-act-btn" title="Editar"><svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></svg></button>
            </div>
        </div>
    `).join('');
}

function mbFilterMembers(q) {
    const t = q.toLowerCase();
    const f = t ? mbMembers.filter(m =>
        (m.NAME  || '').toLowerCase().includes(t) ||
        (m.EMAIL || '').toLowerCase().includes(t) ||
        (m.CITY  || '').toLowerCase().includes(t)
    ) : mbMembers;
    mbRenderMembers(f);
}

// Edit modal
function mbOpenEdit(id) {
    const m = mbMembers.find(x => x.id === id);
    if (!m) return;
    mbEditingId = id;
    document.getElementById('mb-edit-name').textContent = m.NAME || '—';
    document.getElementById('mb-edit-photo').src = m.PHOTO
        ? `${MB_API}/assets/${m.PHOTO}?width=240&height=240&fit=cover`
        : `https://ui-avatars.com/api/?name=${encodeURIComponent(m.NAME)}&background=1e3a8a&color=fff&size=200`;
    document.getElementById('mb-inp-email').value  = m.EMAIL || '';
    let wpp = m.WHATS || '';
    if (wpp.startsWith('55')) wpp = wpp.substring(2);
    document.getElementById('mb-inp-whats').value  = wpp;
    document.getElementById('mb-inp-cep').value    = m.CEP    || '';
    document.getElementById('mb-inp-street').value = m.STREET || '';
    document.getElementById('mb-inp-number').value = m.NUMBER || '';
    document.getElementById('mb-inp-nbhd').value   = m.NEIGHBORHOOD || '';
    document.getElementById('mb-inp-city').value   = m.CITY   || '';
    document.getElementById('mb-inp-state').value  = m.STATE  || '';
    document.getElementById('ov-mb-edit').classList.add('open');
}
function mbCloseEdit() { document.getElementById('ov-mb-edit').classList.remove('open'); }

document.getElementById('mb-inp-cep')?.addEventListener('input', async function(e) {
    let v = e.target.value.replace(/\D/g, '');
    if (v.length > 5) v = v.substring(0, 5) + '-' + v.substring(5, 8);
    e.target.value = v;
    if (v.length === 9) {
        try {
            const r = await fetch(`https://viacep.com.br/ws/${v.replace('-','')}/json/`);
            const d = await r.json();
            if (!d.erro) {
                document.getElementById('mb-inp-street').value = d.logradouro || '';
                document.getElementById('mb-inp-nbhd').value   = d.bairro     || '';
                document.getElementById('mb-inp-city').value   = d.localidade || '';
                document.getElementById('mb-inp-state').value  = d.uf         || '';
                document.getElementById('mb-inp-number').focus();
            }
        } catch {}
    }
});

async function mbSaveMember() {
    if (!mbEditingId) return;
    let wpp = document.getElementById('mb-inp-whats').value.replace(/\D/g, '');
    if (wpp && !wpp.startsWith('55')) wpp = '55' + wpp;
    const p = {
        EMAIL: document.getElementById('mb-inp-email').value.trim(),
        WHATS: wpp,
        CEP: document.getElementById('mb-inp-cep').value.trim(),
        STREET: document.getElementById('mb-inp-street').value.trim(),
        NUMBER: document.getElementById('mb-inp-number').value.trim(),
        NEIGHBORHOOD: document.getElementById('mb-inp-nbhd').value.trim(),
        CITY: document.getElementById('mb-inp-city').value.trim(),
        STATE: document.getElementById('mb-inp-state').value.trim().toUpperCase(),
    };
    try {
        const r = await fetch(`${MB_API}/items/${MB_COL}/${mbEditingId}`, { method: 'PATCH', headers: { 'Authorization': `Bearer ${mbToken}`, 'Content-Type': 'application/json' }, body: JSON.stringify(p) });
        if (r.ok) {
            const i = mbMembers.findIndex(x => x.id === mbEditingId);
            mbMembers[i] = { ...mbMembers[i], ...p };
            mbRenderMembers(mbMembers);
            showToast('success', 'Dados salvos!');
            mbCloseEdit();
        }
    } catch { showToast('error', 'Erro ao salvar.'); }
}

// Photo upload
document.getElementById('mb-file-input')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) { const reader = new FileReader(); reader.onload = ev => mbOpenCrop(ev.target.result); reader.readAsDataURL(file); }
});
function mbOpenCrop(src) {
    document.getElementById('ov-mb-crop').classList.add('open');
    if (mbCroppie) mbCroppie.destroy();
    mbCroppie = new Croppie(document.getElementById('mb-crop-container'), {
        viewport: { width: 225, height: 300, type: 'square' },
        boundary: { width: '100%', height: 320 },
        showZoomer: true,
    });
    mbCroppie.bind({ url: src });
}
function mbCloseCrop() { document.getElementById('ov-mb-crop').classList.remove('open'); }
async function mbSaveCrop() {
    if (!mbCroppie) return;
    const blob = await mbCroppie.result({ type: 'blob', size: { width: 600, height: 800 }, format: 'jpeg', quality: 0.9 });
    mbCloseCrop();
    const fd = new FormData();
    fd.append('file', blob, 'avatar.jpg');
    try {
        const up = await fetch(`${MB_API}/files`, { method: 'POST', headers: { 'Authorization': `Bearer ${mbToken}` }, body: fd });
        const ud = await up.json();
        await fetch(`${MB_API}/items/${MB_COL}/${mbEditingId}`, { method: 'PATCH', headers: { 'Authorization': `Bearer ${mbToken}`, 'Content-Type': 'application/json' }, body: JSON.stringify({ PHOTO: ud.data.id }) });
        const i = mbMembers.findIndex(m => m.id === mbEditingId);
        mbMembers[i].PHOTO = ud.data.id;
        document.getElementById('mb-edit-photo').src = `${MB_API}/assets/${ud.data.id}?width=240&height=240&fit=cover`;
        mbRenderMembers(mbMembers);
        showToast('success', 'Foto atualizada!');
    } catch { showToast('error', 'Erro ao salvar foto.'); }
}

// Direct message
function mbOpenDirectMsg(id) {
    mbDirectMember = mbMembers.find(m => m.id === id);
    document.getElementById('mb-direct-target').textContent = mbDirectMember?.NAME || '—';
    document.getElementById('mb-direct-text').value = '';
    document.getElementById('ov-mb-direct').classList.add('open');
}
function mbCloseDirectMsg() { document.getElementById('ov-mb-direct').classList.remove('open'); }
async function mbSendDirectMsg() {
    const msg = document.getElementById('mb-direct-text').value.trim();
    if (!msg) return;
    try {
        let num = (mbDirectMember?.WHATS || '').replace(/\D/g, '');
        if (!num.startsWith('55')) num = '55' + num;
        const r = await fetch(`${MB_EVO}/message/sendText/${MB_EVO_I}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'apikey': MB_EVO_K },
            body: JSON.stringify({ number: num, text: msg })
        });
        if (r.ok) { showToast('success', 'Mensagem enviada!'); mbCloseDirectMsg(); }
        else showToast('error', 'Erro no envio.');
    } catch { showToast('error', 'Erro no envio.'); }
}

// Broadcast
function mbOpenBroadcast() { mbEnsureData().then(() => document.getElementById('ov-mb-broadcast').classList.add('open')); }
function mbCloseBroadcast() {
    document.getElementById('ov-mb-broadcast').classList.remove('open');
    document.getElementById('mb-prog-wrap').style.display = 'none';
}
function mbSwitchBroadcastTab(mode) {
    mbBcastMode = mode;
    document.getElementById('mb-tab-wpp')?.classList.toggle('on', mode === 'WHATSAPP');
    document.getElementById('mb-tab-email')?.classList.toggle('on', mode === 'EMAIL');
    document.getElementById('mb-email-fields').style.display = mode === 'EMAIL' ? '' : 'none';
}
function mbToggleSched() {
    const on = document.getElementById('mb-check-sched')?.checked;
    document.getElementById('mb-sched-fields').style.display = on ? '' : 'none';
}
async function mbTriggerBroadcast() {
    const message  = document.getElementById('mb-broadcast-message').value.trim();
    const subject  = document.getElementById('mb-broadcast-subject')?.value.trim() || '';
    const isScheduled = document.getElementById('mb-check-sched')?.checked;
    const schedDate   = document.getElementById('mb-broadcast-date')?.value;
    if (!message) return showToast('error', 'Escreva uma mensagem.');
    if (isScheduled && !schedDate) return showToast('error', 'Escolha a data.');
    if (isScheduled) {
        try {
            await fetch(`${MB_API}/items/${MB_SCHED}`, { method: 'POST', headers: { 'Authorization': `Bearer ${mbToken}`, 'Content-Type': 'application/json' }, body: JSON.stringify({ mensagem: message, assunto: subject, canal: mbBcastMode, data_agendamento: schedDate, status: 'pendente' }) });
            showToast('success', 'Agendado com sucesso!');
            mbCloseBroadcast();
        } catch { showToast('error', 'Erro no agendamento.'); }
        return;
    }
    const channel = mbBcastMode === 'WHATSAPP' ? 'whatsapp' : 'email';
    const targets = mbMembers.filter(m => channel === 'whatsapp' ? m.WHATS : m.EMAIL);
    if (!confirm(`Enviar para ${targets.length} membro(s) agora?`)) return;
    const contacts = targets.map(m => ({ nome: m.NAME, telefone: m.WHATS || '', email: m.EMAIL || '' }));
    document.getElementById('mb-prog-wrap').style.display = '';
    document.getElementById('mb-prog-text').textContent = `0 / ${targets.length}`;
    try {
        const r = await fetch('/app/disparar.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ contacts, message, subject, channel }) });
        const d = await r.json();
        if (!d.token) throw new Error(d.error || 'Erro ao enfileirar');
        const token = d.token, total = d.total;
        const poll = async () => {
            try {
                const sr = await fetch(`/app/disparar.php?status=${token}`);
                const sd = await sr.json();
                const pct = total > 0 ? Math.round((sd.processed || 0) / total * 100) : 0;
                document.getElementById('mb-prog-fill').style.width = `${pct}%`;
                document.getElementById('mb-prog-text').textContent = `${sd.processed || 0} / ${total}`;
                if (sd.status === 'done') showToast('success', `Concluído — ${sd.sent} enviado(s)`);
                else setTimeout(poll, 2000);
            } catch { setTimeout(poll, 3000); }
        };
        setTimeout(poll, 1500);
    } catch (e) { showToast('error', 'Erro: ' + e.message); }
}

// Logs
async function mbLoadLogs() {
    const list = document.getElementById('mb-logs-list');
    if (!list) return;
    if (!mbToken) { await mbEnsureData(); if (!mbToken) return; }
    list.innerHTML = '<div class="empty-msg">Carregando…</div>';
    try {
        const r = await fetch(`${MB_API}/items/${MB_LOGS}?sort=-data_envio&limit=30`, { headers: { 'Authorization': `Bearer ${mbToken}` } });
        const d = await r.json();
        if (d.data && d.data.length) {
            list.innerHTML = d.data.map(l => `
                <div style="display:flex;align-items:center;justify-content:space-between;padding:.75rem 1.2rem;border-bottom:1px solid var(--border)">
                    <div style="display:flex;align-items:center;gap:.6rem;flex:1">
                        <span class="mb-log-dot ${l.status==='sucesso'?'ok':'err'}"></span>
                        <div>
                            <div style="font-size:.85rem;font-weight:600;color:var(--text)">${l.membro_nome || '—'}</div>
                            <div style="font-size:.7rem;color:var(--text-muted)">${new Date(l.data_envio).toLocaleString('pt-BR')}</div>
                        </div>
                    </div>
                    <span style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:${l.status==='sucesso'?'var(--green)':'var(--red)'}">${l.status}</span>
                </div>
            `).join('');
        } else {
            list.innerHTML = '<div class="empty-msg">Nenhum log recente.</div>';
        }
    } catch { list.innerHTML = '<div class="empty-msg" style="color:var(--red)">Erro ao carregar logs.</div>'; }
}

// Configs
async function mbLoadConfigs() {
    if (!mbToken) { await mbEnsureData(); if (!mbToken) return; }
    try {
        const r = await fetch(`${MB_API}/items/${MB_CFGS}`, { headers: { 'Authorization': `Bearer ${mbToken}` } });
        const d = await r.json();
        d.data?.forEach(c => {
            if (c.chave === 'msg_aniversario_whats')  document.getElementById('mb-msg-whats').value = c.valor || '';
            if (c.chave === 'msg_aniversario_email') {
                document.getElementById('mb-msg-email-sub').value  = c.auxiliar || '';
                document.getElementById('mb-msg-email-body').value = c.valor    || '';
            }
        });
    } catch { showToast('error', 'Erro ao carregar ajustes.'); }
}

async function mbSaveConfigs() {
    if (!mbToken) return showToast('error', 'Sem token.');
    const whats     = document.getElementById('mb-msg-whats').value.trim();
    const emailSub  = document.getElementById('mb-msg-email-sub').value.trim();
    const emailBody = document.getElementById('mb-msg-email-body').value.trim();
    try {
        const r = await fetch(`${MB_API}/items/${MB_CFGS}`, { headers: { 'Authorization': `Bearer ${mbToken}` } });
        const d = await r.json();
        for (const c of (d.data || [])) {
            let payload = {};
            if (c.chave === 'msg_aniversario_whats') payload = { valor: whats };
            if (c.chave === 'msg_aniversario_email') payload = { valor: emailBody, auxiliar: emailSub };
            if (Object.keys(payload).length > 0)
                await fetch(`${MB_API}/items/${MB_CFGS}/${c.id}`, { method: 'PATCH', headers: { 'Authorization': `Bearer ${mbToken}`, 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        }
        showToast('success', 'Ajustes salvos!');
    } catch { showToast('error', 'Erro ao salvar ajustes.'); }
}

function mbExportCSV() {
    if (!mbMembers.length) return;
    let csv = 'Nome,Email,WhatsApp,Cidade,Estado\n';
    mbMembers.forEach(m => { csv += `"${m.NAME||''}","${m.EMAIL||''}","${m.WHATS||''}","${m.CITY||''}","${m.STATE||''}"\n`; });
    const b = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
    const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(b), download: 'membros_ser.csv' });
    a.click();
}

function mbInsertTag(id, tag) {
    const ta = document.getElementById(id);
    if (!ta) return;
    const s = ta.selectionStart, e = ta.selectionEnd;
    ta.value = ta.value.slice(0, s) + tag + ta.value.slice(e);
    ta.selectionStart = ta.selectionEnd = s + tag.length;
    ta.focus();
}

// Auto-load stats on page load via static token (overview only)
async function loadAppMembers() {
    try {
        const r = await fetch('api.php?action=members');
        if (!r.ok) return;
        const d = await r.json();
        if (d.total != null) {
            ['stat-app-ov','mb-stat-total'].forEach(id => {
                const el = document.getElementById(id);
                if (el && el.textContent === '…') el.textContent = d.total.toLocaleString('pt-BR');
            });
            <?php if (canSee('envios') && canSee('app')): ?>
            [
                ['env-stat-contatos',         'env-stat-contatos-lbl',         'Contatos (Amigos - Listas - Membros)'],
                ['ov-stat-envios-contatos',    'ov-stat-envios-contatos-lbl',   'Contatos (Amigos - Listas - Membros)'],
                ['ov-sys-envios-contatos',     'ov-sys-envios-contatos-lbl',    'contatos (Amigos - Listas - Membros)'],
            ].forEach(([valId, lblId, novoLbl]) => {
                const el  = document.getElementById(valId);
                const lbl = document.getElementById(lblId);
                if (el) { const listas = parseInt(el.dataset.listas) || 0; el.textContent = (listas + d.total).toLocaleString('pt-BR'); }
                if (lbl) lbl.textContent = novoLbl;
            });
            <?php endif; ?>
        }
    } catch {}
}
<?php if (canSee('app')): ?>loadAppMembers();<?php endif; ?>

// ── Listas dinâmicas ─────────────────────────────────────────────────────────
window._listasIndex = [];

async function loadListasIndex() {
    try {
        const r = await fetch('api.php?action=lista_index');
        window._listasIndex = await r.json();
        renderListSelectors();
        // Pré-carrega dados mesclados de disparo em background
        if (window._listasIndex.length) loadDispListasData();
    } catch {}
}

function renderListSelectors() {
    const listas = window._listasIndex;
    if (!listas.length) return;

    // Seletor de navegação (single-select)
    const sel = document.getElementById('env-list-selector');
    if (sel) {
        if (!window._currentEnvList || !listas.find(l => l.slug === window._currentEnvList))
            window._currentEnvList = listas[0].slug;
        sel.innerHTML = listas.map(l =>
            `<button class="btn btn-outline btn-xs${l.slug === window._currentEnvList ? ' on' : ''}" onclick="changeEnvList('${l.slug}', this)">${l.nome}</button>`
        ).join('');
    }

    // Seletor de disparo (multi-select — todos marcados por padrão)
    const dSel = document.getElementById('disp-lista-selector');
    if (dSel) {
        if (!window._dispListas.length)
            window._dispListas = listas.map(l => l.slug);
        dSel.innerHTML = listas.map(l =>
            `<button class="flt-btn${window._dispListas.includes(l.slug) ? ' on' : ''}" onclick="toggleDispLista('${l.slug}', this)">${l.nome} <span style="opacity:.55;font-size:.7em">(${l.total})</span></button>`
        ).join('');
    }
}

loadListasIndex();

// ── Importação via IA ─────────────────────────────────────────────────────────
let _importContacts = [];
let _importCurrentFile = null;

function openImportModal() {
    importSetStage('upload');
    document.getElementById('ov-import-lista').classList.add('open');
}
function closeImportModal() {
    document.getElementById('ov-import-lista').classList.remove('open');
    _importContacts = [];
    _importCurrentFile = null;
    document.getElementById('import-file-input').value = '';
    document.getElementById('import-lista-nome').value = '';
}
function importSetStage(stage) {
    ['upload','loading','preview','error'].forEach(s =>
        document.getElementById(`import-stage-${s}`).style.display = s === stage ? '' : 'none'
    );
}

function handleImportDrop(file) { if (file) handleImportFile(file); }

async function handleImportFile(file) {
    if (!file) return;
    _importCurrentFile = file;
    const ext = file.name.split('.').pop().toLowerCase();

    importSetStage('loading');
    document.getElementById('import-loading-filename').textContent = file.name;

    let sendFile = file;

    // XLSX → converte para CSV no browser antes de enviar
    if (ext === 'xlsx' || ext === 'xls') {
        try {
            const ab  = await file.arrayBuffer();
            const wb  = XLSX.read(ab, { type: 'array' });
            const ws  = wb.Sheets[wb.SheetNames[0]];
            const csv = XLSX.utils.sheet_to_csv(ws);
            sendFile  = new File([csv], file.name.replace(/\.xlsx?$/i, '.csv'), { type: 'text/csv' });
        } catch(e) {
            importSetStage('error');
            document.getElementById('import-error-msg').textContent = 'Erro ao ler o arquivo Excel: ' + e.message;
            return;
        }
    }

    const fd = new FormData();
    fd.append('arquivo', sendFile);

    try {
        const r    = await fetch('api.php?action=lista_extrair', { method: 'POST', body: fd });
        const data = await r.json();
        if (!r.ok || data.error) {
            importSetStage('error');
            document.getElementById('import-error-msg').textContent = data.error || 'Erro desconhecido';
            return;
        }
        _importContacts = data.contacts || [];
        renderImportPreview(_importContacts);
        importSetStage('preview');
    } catch(e) {
        importSetStage('error');
        document.getElementById('import-error-msg').textContent = 'Falha na comunicação com o servidor.';
    }
}

function renderImportPreview(contacts) {
    document.getElementById('import-preview-count').textContent =
        `${contacts.length} contato${contacts.length !== 1 ? 's' : ''} encontrado${contacts.length !== 1 ? 's' : ''}`;

    const tbody = document.getElementById('import-preview-list');
    if (!contacts.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="empty-msg">Nenhum contato encontrado no arquivo</td></tr>';
        return;
    }
    tbody.innerHTML = contacts.slice(0, 100).map(c => `
        <tr>
            <td><strong>${c.nome || '—'}</strong></td>
            <td style="font-size:.78rem;color:var(--green)">${c.telefone || '—'}</td>
            <td style="font-size:.78rem">${c.email || '—'}</td>
            <td style="font-size:.78rem">${c.bairro || '—'}</td>
            <td style="font-size:.78rem">${c.sexo || '—'}</td>
        </tr>
    `).join('') + (contacts.length > 100 ? `<tr><td colspan="5" style="text-align:center;font-size:.72rem;color:var(--text-muted);padding:.4rem">+ ${contacts.length - 100} contatos ocultos</td></tr>` : '');
}

async function saveImportedList() {
    const nome = (document.getElementById('import-lista-nome').value || '').trim();
    if (!nome) { alert('Dê um nome para a lista antes de salvar.'); return; }
    if (!_importContacts.length) { alert('Nenhum contato para salvar.'); return; }

    const btn = document.getElementById('import-save-btn');
    btn.disabled = true; btn.textContent = 'Salvando…';

    try {
        const r    = await fetch('api.php?action=lista_salvar', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ nome, contacts: _importContacts })
        });
        const data = await r.json();
        if (!r.ok || !data.ok) { alert(data.error || 'Erro ao salvar.'); btn.disabled = false; btn.textContent = 'Incluir no sistema'; return; }

        showToast('success', `Lista "${data.nome}" criada com ${data.total} contatos.`);
        closeImportModal();
        await loadListasIndex();
        // Seleciona a lista recém-criada
        const newBtn = document.querySelector(`#env-list-selector .btn[onclick*="${data.slug}"]`);
        if (newBtn) changeEnvList(data.slug, newBtn);
    } catch {
        alert('Erro ao salvar a lista.');
    }
    btn.disabled = false; btn.textContent = 'Incluir no sistema';
}

const initSec = '<?= esc($_GET['sec'] ?? 'overview') ?>';
if (initSec !== 'overview') showSec(initSec);

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
    ['app','amigos','envios','site'].forEach(s => {
        const cb = document.getElementById('sis-' + s);
        if (cb) cb.checked = (data.sistemas || []).includes(s);
    });    document.getElementById('user-ativo').checked = !!data.ativo;
    document.getElementById('user-form-panel').style.display = 'block';
    document.getElementById('user-form-panel').scrollIntoView({behavior:'smooth',block:'start'});
}
</script>
</body>
</html>

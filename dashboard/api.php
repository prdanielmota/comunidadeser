<?php
session_start();

// Define ROOT se não estiver definido (caso acessado diretamente)
if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__));
}

if (file_exists(ROOT . '/app/config.php')) require_once ROOT . '/app/config.php';

// O dashboard usa $_SESSION['dash_user'] para autenticação
if (empty($_SESSION['dash_user'])) { 
    http_response_code(401); 
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error'=>'Unauthorized']); 
    exit; 
}

header('Content-Type: application/json; charset=utf-8');
$action = $_GET['action'] ?? '';

// ── Membros App (Directus) ────────────────────────────────
if ($action === 'members') {
    $ch = curl_init('https://cms.osmota.org/items/COMUNIDADE_SER?limit=0&meta=total_count');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_HTTPHEADER      => ['Authorization: Bearer ' . DIRECTUS_TOKEN],
        CURLOPT_TIMEOUT         => 8,
        CURLOPT_CONNECTTIMEOUT  => 4,
        CURLOPT_SSL_VERIFYPEER  => false,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res ?: '{}', true);
    echo json_encode(['total' => $data['meta']['total_count'] ?? null]);
    exit;
}

// ── Envios (Contatos & Disparos) ──────────────────────────
if ($action === 'envios_contatos') {
    $slug  = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['file'] ?? 'novo-tempo'));
    $index = file_exists(ROOT . '/envios/listas.json')
           ? (json_decode(file_get_contents(ROOT . '/envios/listas.json'), true) ?: [])
           : [];
    if (isset($index[$slug])) {
        $file = ROOT . '/envios/' . $index[$slug]['arquivo'];
    } else {
        // Fallback para slugs legados sem índice
        $file = ROOT . '/envios/' . $slug . '.json';
    }
    if (!file_exists($file)) { echo json_encode([]); exit; }
    echo file_get_contents($file);
    exit;
}

if ($action === 'envios_disparar') {
    if ($_SESSION['dash_user']['role'] === 'viewer') { http_response_code(403); exit; }
    
    // Proxy para o script de disparo original
    $url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/envios/disparar.php';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => file_get_contents('php://input'),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    http_response_code($code);
    echo $res;
    exit;
}

if ($action === 'envios_status') {
    $token = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');
    $url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/envios/disparar.php?t=' . $token;
    echo file_get_contents($url);
    exit;
}

if ($action === 'envios_update' || $action === 'envios_optout') {
    if ($_SESSION['dash_user']['role'] === 'viewer') { http_response_code(403); exit; }
    $data = json_decode(file_get_contents('php://input'), true);
    $file = ROOT . '/envios/novo-tempo.json';
    $list = json_decode(file_get_contents($file), true) ?: [];
    $idx  = $data['idx'] ?? -1;

    if ($action === 'envios_update' && isset($list[$idx])) {
        foreach (['nome','bairro','religiao','sexo','idade','vip','email','telefone'] as $f) {
            if (isset($data['fields'][$f])) $list[$idx][$f] = $data['fields'][$f];
        }
    } elseif ($action === 'envios_optout' && isset($list[$idx])) {
        $list[$idx]['opt_out'] = !($data['activate'] ?? false);
        $list[$idx]['opt_out_date'] = date('c');
        $list[$idx]['opt_out_source'] = 'dashboard';
    }

    file_put_contents($file, json_encode(array_values($list), JSON_UNESCAPED_UNICODE));
    echo json_encode(['ok' => true]);
    exit;
}

// ── Amigos (Cadastros & Eventos) ──────────────────────────
if ($action === 'amigos_cadastros') {
    $file = ROOT . '/amigos/cadastros.json';
    if (!file_exists($file)) { echo json_encode([]); exit; }
    echo file_get_contents($file);
    exit;
}

if (in_array($action, ['amigos_add','amigos_edit','amigos_delete'])) {
    if ($_SESSION['dash_user']['role'] === 'viewer') { http_response_code(403); exit; }
    $data = json_decode(file_get_contents('php://input'), true);
    $file = ROOT . '/amigos/cadastros.json';
    $list = json_decode(file_get_contents($file), true) ?: [];

    if ($action === 'amigos_add') {
        $list[] = [
            'nome' => $data['nome'], 'email' => $data['email'], 'wpp' => $data['wpp'],
            'classificacao' => $data['classificacao'], 'at' => date('c')
        ];
    } elseif ($action === 'amigos_edit') {
        foreach ($list as &$r) {
            if ($r['wpp'] === $data['wpp_original']) {
                $r['nome'] = $data['nome']; $r['email'] = $data['email'];
                $r['wpp'] = $data['wpp']; $r['classificacao'] = $data['classificacao'];
                break;
            }
        }
    } elseif ($action === 'amigos_delete') {
        $list = array_values(array_filter($list, fn($r) => $r['wpp'] !== $data['wpp']));
    }

    file_put_contents($file, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo json_encode(['ok' => true]);
    exit;
}

if (in_array($action, ['amigos_eventos_save', 'amigos_eventos_delete'])) {
    if ($_SESSION['dash_user']['role'] === 'viewer') { http_response_code(403); exit; }
    $data = json_decode(file_get_contents('php://input'), true);
    $file = ROOT . '/amigos/eventos.json';
    $evs  = json_decode(file_get_contents($file), true) ?: [];

    if ($action === 'amigos_eventos_save') {
        $id = $data['id'] ?? '';
        $entry = [
            'id'          => $id ?: bin2hex(random_bytes(8)),
            'titulo'      => $data['titulo'],
            'descricao'   => $data['descricao'] ?? '',
            'data_inicio' => $data['data_inicio'],
            'data_fim'    => $data['data_fim'] ?? '',
            'local'       => $data['local'] ?? '',
            'updated_at'  => date('c')
        ];

        $found = false;
        if ($id) {
            foreach ($evs as &$ev) {
                if ($ev['id'] === $id) {
                    $ev = array_merge($ev, $entry);
                    $found = true; break;
                }
            }
        }
        if (!$found) {
            $entry['created_at'] = date('c');
            $entry['confirmacoes'] = [];
            $evs[] = $entry;
        }
    } elseif ($action === 'amigos_eventos_delete') {
        $evs = array_values(array_filter($evs, fn($ev) => $ev['id'] !== $data['id']));
    }

    file_put_contents($file, json_encode($evs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo json_encode(['ok' => true]);
    exit;
}

// ── Site (Links Editor) ───────────────────────────────────
function siteHtmlFile(): string { return ROOT . '/index.html'; }

function siteGetLinks(): array {
    $f = siteHtmlFile();
    if (!file_exists($f)) return ['buttons' => [], 'social' => []];
    $html = file_get_contents($f);
    $buttons = [];
    if (preg_match('/<div class="links-principais">(.*?)<\/div>/is', $html, $m)) {
        preg_match_all('/<a\s([^>]*)>\s*([^<]*?)\s*<\/a>/is', $m[1], $ms, PREG_SET_ORDER);
        foreach ($ms as $match) {
            $attrs = $match[1]; $text = trim($match[2]);
            preg_match('/\bid="([^"]+)"/', $attrs, $mid);
            preg_match('/\bhref="([^"]+)"/', $attrs, $mhref);
            if (empty($mid[1]) || empty($mhref[1])) continue;
            $buttons[] = [
                'id'       => $mid[1],
                'text'     => $text,
                'url'      => $mhref[1],
                'featured' => (bool)preg_match('/\bfeatured\b/', $attrs),
            ];
        }
    }
    $social = [];
    if (preg_match('/href="([^"]+)"[^>]*>Instagram<\/a>/is', $html, $m)) $social['instagram'] = trim($m[1]);
    if (preg_match('/href="([^"]+)"[^>]*>YouTube<\/a>/is',   $html, $m)) $social['youtube']   = trim($m[1]);
    return ['buttons' => $buttons, 'social' => $social];
}

if ($action === 'site_links') {
    echo json_encode(siteGetLinks());
    exit;
}

if ($action === 'site_link_save') {
    if ($_SESSION['dash_user']['role'] === 'viewer') { http_response_code(403); exit; }
    $d    = json_decode(file_get_contents('php://input'), true) ?? [];
    $f    = siteHtmlFile();
    $html = file_get_contents($f);
    $id   = preg_replace('/[^a-z0-9_-]/', '', strtolower($d['id'] ?? ''));
    $url  = $d['url']      ?? '';
    $text = $d['text']     ?? '';
    $feat = !empty($d['featured']);
    if (!$id || !$url || !$text) { http_response_code(400); echo json_encode(['error'=>'Missing fields']); exit; }
    $cls = 'btn-link' . ($feat ? ' featured' : '');
    if (preg_match('/id="' . preg_quote($id, '/') . '"/', $html)) {
        // Atualiza href e texto
        $html = preg_replace('/(id="'.preg_quote($id,'/').'")[^>]*(href=")[^"]*(")/is', '$1 class="'.$cls.'" $2'.$url.'$3', $html);
        $html = preg_replace('/(<a\s[^>]*id="'.preg_quote($id,'/').'")[^>]*(>)\s*[^<]*(<\/a>)/is', '$1$2'."\n                ".$text."\n            ".'$3', $html);
    } else {
        $newBtn = "\n            <a href=\"$url\" class=\"$cls\" id=\"$id\">\n                $text\n            </a>";
        $html = preg_replace('/(<div class="links-principais">)(.*?)(<\/div>)/is', '$1$2'.$newBtn."\n        ".'$3', $html);
    }
    file_put_contents($f, $html);
    echo json_encode(siteGetLinks());
    exit;
}

if ($action === 'site_link_delete') {
    if ($_SESSION['dash_user']['role'] === 'viewer') { http_response_code(403); exit; }
    $d  = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = preg_replace('/[^a-z0-9_-]/', '', strtolower($d['id'] ?? ''));
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
    $f    = siteHtmlFile();
    $html = file_get_contents($f);
    $html = preg_replace('/\s*<a\s[^>]*id="'.preg_quote($id,'/').'"\s*[^>]*>\s*[^<]*<\/a>/is', '', $html);
    file_put_contents($f, $html);
    echo json_encode(siteGetLinks());
    exit;
}

if ($action === 'site_social_save') {
    if ($_SESSION['dash_user']['role'] === 'viewer') { http_response_code(403); exit; }
    $d    = json_decode(file_get_contents('php://input'), true) ?? [];
    $f    = siteHtmlFile();
    $html = file_get_contents($f);
    if (!empty($d['instagram'])) $html = preg_replace('/(href=")[^"]*("[^>]*>Instagram<\/a>)/is', '$1'.$d['instagram'].'$2', $html);
    if (!empty($d['youtube']))   $html = preg_replace('/(href=")[^"]*("[^>]*>YouTube<\/a>)/is',   '$1'.$d['youtube'].'$2',   $html);
    file_put_contents($f, $html);
    echo json_encode(siteGetLinks());
    exit;
}

// ── Listas (Índice, Importar via IA, Salvar) ──────────────────
const LISTAS_INDEX = ROOT . '/envios/listas.json';

function loadListasIndex(): array {
    if (!file_exists(LISTAS_INDEX)) return [
        'novo-tempo'     => ['nome' => 'Novo Tempo',     'arquivo' => 'novo-tempo.json'],
        'conectados-run' => ['nome' => 'Conectados Run', 'arquivo' => 'conectados-run-data.json'],
    ];
    return json_decode(file_get_contents(LISTAS_INDEX), true) ?: [];
}

if ($action === 'lista_index') {
    $listas = loadListasIndex();
    $result = [];
    foreach ($listas as $slug => $meta) {
        $file  = ROOT . '/envios/' . $meta['arquivo'];
        $total = file_exists($file) ? count(json_decode(file_get_contents($file), true) ?: []) : 0;
        $result[] = ['slug' => $slug, 'nome' => $meta['nome'], 'arquivo' => $meta['arquivo'], 'total' => $total, 'criada_em' => $meta['criada_em'] ?? ''];
    }
    echo json_encode($result);
    exit;
}

if ($action === 'lista_extrair') {
    if ($_SESSION['dash_user']['role'] === 'viewer') { http_response_code(403); exit; }
    if (!defined('ANTHROPIC_KEY')) { http_response_code(500); echo json_encode(['error' => 'API key não configurada']); exit; }
    if (empty($_FILES['arquivo'])) { http_response_code(400); echo json_encode(['error' => 'Nenhum arquivo enviado']); exit; }

    $file = $_FILES['arquivo'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $tmp  = $file['tmp_name'];

    $allowed_exts = ['pdf', 'txt', 'csv', 'xlsx', 'xls'];
    if (!in_array($ext, $allowed_exts)) {
        http_response_code(400);
        echo json_encode(['error' => 'Tipo de arquivo não permitido (use PDF, TXT, CSV ou XLSX)']);
        exit;
    }

    if ($file['size'] > 10 * 1024 * 1024) { http_response_code(400); echo json_encode(['error' => 'Arquivo muito grande (máx. 10 MB)']); exit; }

    $prompt = "Analise o conteúdo deste arquivo e extraia todos os contatos/pessoas encontrados.\n\nRetorne SOMENTE um array JSON válido, sem texto adicional, comentários ou markdown. Cada objeto deve ter os campos disponíveis:\n- nome (string, obrigatório)\n- telefone (string: apenas dígitos, formato 55DDXXXXXXXXX — se tiver só DDD+número sem DDI, adicione 55; se for número de 8 dígitos sem DDD, tente inferir pelo contexto)\n- email (string)\n- bairro (string)\n- religiao (string)\n- sexo (\"Masculino\" ou \"Feminino\")\n- idade (string)\n- vip (\"Sim\" ou \"\")\n\nOmita campos ausentes no arquivo. Se nenhum contato for encontrado, retorne [].";

    $messages = [];
    if ($ext === 'pdf') {
        $b64 = base64_encode(file_get_contents($tmp));
        $messages[] = ['role' => 'user', 'content' => [
            ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $b64]],
            ['type' => 'text', 'text' => $prompt],
        ]];
    } else {
        $content    = file_get_contents($tmp);
        $messages[] = ['role' => 'user', 'content' => "Arquivo: {$file['name']}\n\nConteúdo:\n{$content}\n\n{$prompt}"];
    }

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_KEY,
            'anthropic-version: 2023-06-01',
            'anthropic-beta: pdfs-2024-09-25',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model'      => 'claude-sonnet-4-6',
            'max_tokens' => 8192,
            'messages'   => $messages,
        ]),
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) { http_response_code(502); echo json_encode(['error' => "Erro na API Claude (HTTP $code)"]); exit; }

    $data = json_decode($res, true);
    $text = $data['content'][0]['text'] ?? '';

    // Extrai o JSON do texto retornado
    if (preg_match('/\[[\s\S]*\]/u', $text, $m)) {
        $contacts = json_decode($m[0], true);
        if (is_array($contacts)) {
            echo json_encode(['ok' => true, 'contacts' => $contacts, 'total' => count($contacts)]);
            exit;
        }
    }

    echo json_encode(['error' => 'Não foi possível extrair contatos do arquivo', 'raw' => mb_substr($text, 0, 500)]);
    exit;
}

if ($action === 'lista_salvar') {
    if ($_SESSION['dash_user']['role'] === 'viewer') { http_response_code(403); exit; }

    $data     = json_decode(file_get_contents('php://input'), true);
    $nome     = trim($data['nome'] ?? '');
    $contacts = $data['contacts'] ?? [];
    if (!$nome || empty($contacts)) { http_response_code(400); echo json_encode(['error' => 'Nome e contatos são obrigatórios']); exit; }

    // Slug único
    $slug     = preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome)));
    $slug     = trim($slug, '-') ?: 'lista';
    $listas   = loadListasIndex();
    $base     = $slug; $i = 1;
    while (isset($listas[$slug]) || file_exists(ROOT . '/envios/' . $slug . '.json')) {
        $slug = $base . '-' . $i++;
    }
    $arquivo = $slug . '.json';

    // Normaliza e adiciona ids/timestamps
    $now = date('c');
    foreach ($contacts as &$c) {
        if (empty($c['id']))            $c['id']            = uniqid();
        if (empty($c['solicitacao_iso'])) $c['solicitacao_iso'] = $now;
    }
    unset($c);

    file_put_contents(ROOT . '/envios/' . $arquivo, json_encode(array_values($contacts), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $listas[$slug] = ['nome' => $nome, 'arquivo' => $arquivo, 'criada_em' => $now];
    file_put_contents(LISTAS_INDEX, json_encode($listas, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    echo json_encode(['ok' => true, 'slug' => $slug, 'nome' => $nome, 'arquivo' => $arquivo, 'total' => count($contacts)]);
    exit;
}

echo json_encode(['error' => 'Unknown action']);

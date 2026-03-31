<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

define('DATA_FILE',    __DIR__ . '/novo-tempo.json');
define('OPTOUTS_FILE', __DIR__ . '/optouts.json');
define('LOGS_DIR',     __DIR__ . '/.logs');

$action = $_GET['action'] ?? '';

match ($action) {
    'update'   => handle_update(),
    'optout'   => handle_optout(),
    'activate' => handle_activate(),
    'history'  => handle_history(),
    'log'      => handle_log(),
    default    => bad_req('Ação desconhecida'),
};

// ── helpers ───────────────────────────────────────────────────────────────────
function bad_req(string $msg): void {
    http_response_code(400);
    echo json_encode(['error' => $msg]);
}

function read_data(): array {
    if (!file_exists(DATA_FILE)) return [];
    return json_decode(file_get_contents(DATA_FILE), true) ?: [];
}

function write_data(array $data): void {
    $tmp = DATA_FILE . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode(array_values($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    rename($tmp, DATA_FILE);
}

function read_optouts(): array {
    if (!file_exists(OPTOUTS_FILE)) return [];
    return json_decode(file_get_contents(OPTOUTS_FILE), true) ?: [];
}

function write_optouts(array $oo): void {
    file_put_contents(OPTOUTS_FILE, json_encode($oo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function p11(string $phone): string {
    return substr(preg_replace('/\D/', '', $phone), -11);
}

// ── 1. Atualizar campos de um registro ───────────────────────────────────────
function handle_update(): void {
    $body   = json_decode(file_get_contents('php://input'), true);
    $idx    = isset($body['idx']) ? (int)$body['idx'] : -1;
    $fields = $body['fields'] ?? [];

    if ($idx < 0 || empty($fields)) { bad_req('Dados inválidos'); return; }

    $data = read_data();
    if (!array_key_exists($idx, $data)) { http_response_code(404); echo json_encode(['error' => 'Não encontrado']); return; }

    foreach (['nome','bairro','religiao','sexo','idade','vip','email','email2','telefone','telefone2'] as $f) {
        if (array_key_exists($f, $fields)) $data[$idx][$f] = $fields[$f];
    }

    write_data($data);
    echo json_encode(['ok' => true, 'record' => $data[$idx]]);
}

// ── 2. Opt-out (por índice ou por telefone) ───────────────────────────────────
function handle_optout(): void {
    $body   = json_decode(file_get_contents('php://input'), true);
    $idx    = isset($body['idx']) ? (int)$body['idx'] : -1;
    $phone  = $body['phone']  ?? null;
    $source = $body['source'] ?? 'manual';
    $now    = date('Y-m-d H:i:s');

    // Por índice direto (lista Transmissão)
    if ($idx >= 0) {
        $data = read_data();
        if (!array_key_exists($idx, $data)) { http_response_code(404); echo json_encode(['error' => 'Não encontrado']); return; }
        $data[$idx]['opt_out']        = true;
        $data[$idx]['opt_out_date']   = $now;
        $data[$idx]['opt_out_source'] = $source;
        write_data($data);
        // Grava também em optouts.json para cobrir listas externas
        $tel = $data[$idx]['telefone'] ?? $data[$idx]['telefone2'] ?? '';
        if ($tel) {
            $oo = read_optouts();
            $oo[p11($tel)] = ['date' => $now, 'source' => $source, 'phone' => $tel];
            write_optouts($oo);
        }
        echo json_encode(['ok' => true]);
        return;
    }

    // Por telefone (Novo Tempo / webhook)
    if ($phone) {
        $key   = p11($phone);
        $found = false;

        $data = read_data();
        foreach ($data as &$r) {
            $t1 = p11($r['telefone']  ?? '');
            $t2 = p11($r['telefone2'] ?? '');
            if (($t1 && $t1 === $key) || ($t2 && $t2 === $key)) {
                $r['opt_out']        = true;
                $r['opt_out_date']   = $now;
                $r['opt_out_source'] = $source;
                $found = true;
            }
        }
        unset($r);
        if ($found) write_data($data);

        $oo = read_optouts();
        $oo[$key] = ['date' => $now, 'source' => $source, 'phone' => $phone];
        write_optouts($oo);

        echo json_encode(['ok' => true, 'found_local' => $found]);
        return;
    }

    bad_req('Informe idx ou phone');
}

// ── 3. Reativar contato ────────────────────────────────────────────────────────
function handle_activate(): void {
    $body  = json_decode(file_get_contents('php://input'), true);
    $idx   = isset($body['idx']) ? (int)$body['idx'] : -1;
    $phone = $body['phone'] ?? null;

    if ($idx >= 0) {
        $data = read_data();
        if (!array_key_exists($idx, $data)) { http_response_code(404); echo json_encode(['error' => 'Não encontrado']); return; }
        $data[$idx]['opt_out']        = false;
        $data[$idx]['opt_out_date']   = null;
        $data[$idx]['opt_out_source'] = null;
        write_data($data);
        // Remove de optouts.json também
        $tel = $data[$idx]['telefone'] ?? $data[$idx]['telefone2'] ?? '';
        if ($tel) {
            $oo = read_optouts();
            unset($oo[p11($tel)]);
            write_optouts($oo);
        }
        echo json_encode(['ok' => true]);
        return;
    }

    if ($phone) {
        $oo = read_optouts();
        unset($oo[p11($phone)]);
        write_optouts($oo);
        echo json_encode(['ok' => true]);
        return;
    }

    bad_req('Informe idx ou phone');
}

// ── 4. Histórico de disparos ──────────────────────────────────────────────────
function handle_history(): void {
    if (!is_dir(LOGS_DIR)) { echo json_encode([]); return; }

    $files = array_filter(
        glob(LOGS_DIR . '/*.json') ?: [],
        fn($f) => basename($f) !== 'webhook.log'
    );

    $list = [];
    foreach ($files as $f) {
        $raw = @file_get_contents($f);
        if (!$raw) continue;
        $d = json_decode($raw, true);
        if (!$d || !isset($d['total'])) continue;
        $list[] = [
            'token'       => basename($f, '.json'),
            'status'      => $d['status']      ?? 'unknown',
            'total'       => (int)($d['total']     ?? 0),
            'sent'        => (int)($d['sent']      ?? 0),
            'failed'      => (int)($d['failed']    ?? 0),
            'processed'   => (int)($d['processed'] ?? 0),
            'channel'     => $d['channel']     ?? '—',
            'started_at'  => $d['started_at']  ?? null,
            'finished_at' => $d['finished_at'] ?? null,
        ];
    }

    usort($list, fn($a, $b) => strcmp($b['started_at'] ?? '', $a['started_at'] ?? ''));
    echo json_encode($list, JSON_UNESCAPED_UNICODE);
}

// ── 5. Detalhe de um log ──────────────────────────────────────────────────────
function handle_log(): void {
    $token = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');
    if (!$token) { bad_req('Token inválido'); return; }
    $f = LOGS_DIR . "/$token.json";
    if (!file_exists($f)) { http_response_code(404); echo json_encode(['error' => 'Não encontrado']); return; }
    readfile($f);
}

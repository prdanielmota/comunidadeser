<?php
// worker.php — processa disparo WhatsApp em background via CLI
// Uso: php worker.php <token>

// Carrega configurações globais
if (file_exists(__DIR__ . '/../app/config.php')) {
    require_once __DIR__ . '/../app/config.php';
}

const JOBS_DIR = __DIR__ . '/.jobs';
const LOGS_DIR = __DIR__ . '/.logs';

// ── Main ──────────────────────────────────────────────────────────────────────
$token = isset($argv[1]) ? preg_replace('/[^a-f0-9]/', '', $argv[1]) : '';
if (!$token) exit(1);

$jobFile = JOBS_DIR . "/$token.json";
if (!file_exists($jobFile)) exit(1);

$job = json_decode(file_get_contents($jobFile), true);
unlink($jobFile);

$contacts = $job['contacts'];
$message  = $job['message'];
$total    = count($contacts);

$log = [
    'token'      => $token,
    'started_at' => date('Y-m-d H:i:s'),
    'total'      => $total,
    'processed'  => 0,
    'sent'       => 0,
    'failed'     => 0,
    'status'     => 'running',
    'entries'    => [],
];
write_log($token, $log);

$sent = $failed = $waCount = 0;

foreach ($contacts as $i => $c) {
    $entry  = ['nome' => $c['nome'], 'whatsapp' => null, 'time' => date('H:i:s')];
    $msg_p  = str_replace('{nome}', explode(' ', trim($c['nome']))[0], $message);

    $wp = normalize_phone($c['wpp'] ?? ($c['telefone'] ?? ''));
    if ($wp) {
        // Seleciona par instância/chave de forma aleatória
        $creds = get_evo_credentials();

        // Simular "digitando..."
        send_presence($wp, 'composing', $creds['instance'], $creds['key']);

        // Aguarda o delay (tempo de digitação)
        usleep(rand(WA_DELAY_MIN, WA_DELAY_MAX) * 100000);

        $result = send_whatsapp($wp, $msg_p, $creds['instance'], $creds['key']);
        $entry['whatsapp'] = $result === true ? 'ok' : $result;
        if ($result === true) $sent++; else $failed++;
        $waCount++;

        if ($waCount % WA_BATCH_SIZE === 0) {
            sleep(WA_BATCH_WAIT);
        }
    } else {
        $entry['whatsapp'] = 'número inválido';
        $failed++;
    }

    $log['processed'] = $i + 1;
    $log['sent']      = $sent;
    $log['failed']    = $failed;
    $log['entries'][] = $entry;
    write_log($token, $log);
}

$log['status']      = 'done';
$log['finished_at'] = date('Y-m-d H:i:s');
write_log($token, $log);

// ── Escrita atômica do log ────────────────────────────────────────────────────
function write_log(string $token, array $data): void {
    if (!is_dir(LOGS_DIR)) mkdir(LOGS_DIR, 0750, true);
    $file = LOGS_DIR . "/$token.json";
    $tmp  = $file . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE));
    rename($tmp, $file);
}

// ── Simular presença (digitando...) ──────────────────────────────────────────
function send_presence(string $number, string $presence, string $instance, string $key): void {
    if (!$key) return;
    $url = EVO_URL . '/instance/setPresence/' . $instance;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'apikey: ' . $key,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'presence' => $presence,
        ]),
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ── Envio WhatsApp via Evolution API ─────────────────────────────────────────
function send_whatsapp(string $number, string $text, string $instance, string $key): bool|string {
    if (!$key) return "Chave da API não configurada";
    $url = EVO_URL . '/message/sendText/' . $instance;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'apikey: ' . $key,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'number' => $number,
            'text'   => $text,
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return "cURL: $err";
    if ($code >= 200 && $code < 300) return true;
    $r = json_decode($resp, true);
    return $r['message'] ?? "HTTP $code";
}

// ── Normalizar telefone ───────────────────────────────────────────────────────
function normalize_phone(?string $raw): ?string {
    if (!$raw) return null;
    $n = preg_replace('/\D/', '', $raw);
    if (strlen($n) < 10) return null;
    if (!str_starts_with($n, '55')) $n = '55' . $n;
    if (strlen($n) < 12) return null;
    $ddd   = substr($n, 2, 2);
    $local = substr($n, 4);
    if (strlen($local) === 9 && in_array($local[0], ['6','7','8','9'], true)) return '55'.$ddd.$local;
    if (strlen($local) === 8 && in_array($local[0], ['6','7','8','9'], true)) return '55'.$ddd.'9'.$local;
    return null;
}

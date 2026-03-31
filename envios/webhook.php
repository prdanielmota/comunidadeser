<?php
// webhook.php — recebe notificações do Evolution API e aplica opt-out automático
// Configure na Evolution API: POST para https://seusite.com/envios/webhook.php
// Eventos: MESSAGES_UPSERT

header('Content-Type: application/json; charset=utf-8');

define('DATA_FILE',    __DIR__ . '/novo-tempo.json');
define('OPTOUTS_FILE', __DIR__ . '/optouts.json');
define('WH_LOG',       __DIR__ . '/.logs/webhook.log');

const OPT_KEYWORDS = [
    'sair', 'stop', 'parar', 'cancelar', 'descadastrar',
    'remover', 'nao quero', 'nao quero mais', 'me remova',
    'me retire', 'nao envie', 'pare', '0',
];

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];

wh_log("RECV: " . substr($raw, 0, 400));

// Ignora mensagens enviadas pelo próprio bot
$fromMe = $body['data']['key']['fromMe'] ?? $body['data']['fromMe'] ?? false;
if ($fromMe) {
    echo json_encode(['ok' => true, 'action' => 'ignored_own']);
    exit;
}

$phone   = extract_phone($body);
$message = extract_message($body);

if (!$phone || !$message) {
    wh_log("IGNORED: no phone or message");
    echo json_encode(['ok' => true, 'action' => 'ignored']);
    exit;
}

$msg_norm  = normalize_text($message);
$is_optout = false;
foreach (OPT_KEYWORDS as $kw) {
    if ($msg_norm === $kw || str_contains($msg_norm, $kw)) {
        $is_optout = true;
        break;
    }
}

if (!$is_optout) {
    wh_log("IGNORED: keyword not matched — $phone: $msg_norm");
    echo json_encode(['ok' => true, 'action' => 'ignored', 'msg' => $msg_norm]);
    exit;
}

$found = apply_optout($phone);
wh_log("OPT-OUT: $phone — found_local:" . ($found ? 'yes' : 'no'));
echo json_encode(['ok' => true, 'action' => 'optout', 'phone' => $phone, 'found_local' => $found]);

// ── Helpers ───────────────────────────────────────────────────────────────────
function extract_phone(array $b): ?string {
    $jid = $b['data']['key']['remoteJid']
        ?? $b['data']['remoteJid']
        ?? $b['remoteJid']
        ?? null;
    if (!$jid) return null;
    $jid = preg_replace('/@.*/', '', $jid);
    $clean = preg_replace('/\D/', '', $jid);
    return $clean ?: null;
}

function extract_message(array $b): ?string {
    return $b['data']['message']['conversation']
        ?? $b['data']['message']['extendedTextMessage']['text']
        ?? $b['data']['body']
        ?? $b['body']
        ?? null;
}

function normalize_text(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    return strtr($s, [
        'ã'=>'a','á'=>'a','â'=>'a','à'=>'a','ä'=>'a',
        'é'=>'e','ê'=>'e','è'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','ï'=>'i',
        'ó'=>'o','õ'=>'o','ô'=>'o','ò'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n',
    ]);
}

function p11(string $phone): string {
    return substr(preg_replace('/\D/', '', $phone), -11);
}

function apply_optout(string $phone): bool {
    $key  = p11($phone);
    $now  = date('Y-m-d H:i:s');
    $found = false;

    // Marca em novo-tempo.json se o número for encontrado
    if (file_exists(DATA_FILE)) {
        $data = json_decode(file_get_contents(DATA_FILE), true) ?: [];
        foreach ($data as &$r) {
            $t1 = p11($r['telefone']  ?? '');
            $t2 = p11($r['telefone2'] ?? '');
            if (($t1 && $t1 === $key) || ($t2 && $t2 === $key)) {
                $r['opt_out']        = true;
                $r['opt_out_date']   = $now;
                $r['opt_out_source'] = 'whatsapp';
                $found = true;
            }
        }
        unset($r);
        if ($found) {
            $tmp = DATA_FILE . '.tmp.' . getmypid();
            file_put_contents($tmp, json_encode(array_values($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            rename($tmp, DATA_FILE);
        }
    }

    // Grava sempre em optouts.json (cobre Novo Tempo e futuras listas)
    $oo = file_exists(OPTOUTS_FILE) ? (json_decode(file_get_contents(OPTOUTS_FILE), true) ?: []) : [];
    $oo[$key] = ['date' => $now, 'source' => 'whatsapp', 'phone' => $phone];
    file_put_contents(OPTOUTS_FILE, json_encode($oo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    return $found;
}

function wh_log(string $msg): void {
    $dir = dirname(WH_LOG);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    @file_put_contents(WH_LOG, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

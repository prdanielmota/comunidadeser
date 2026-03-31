<?php
// worker.php — processa disparo em background via CLI
// Uso: php worker.php <token>

// Carrega variáveis de ambiente e configurações globais
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

$contacts = $job['contacts'] ?? [];
$channel  = $job['channel']  ?? 'both';
$subject  = $job['subject']  ?? 'Mensagem Comunidade Ser';
$message  = $job['message']  ?? '';
$name     = $job['name']     ?? '';
$sel_inst = $job['instance'] ?? 'random'; // Instância selecionada manualmente
$total    = count($contacts);

if (!$total) exit(0);

// Inicia log
$log = [
    'token'      => $token,
    'name'       => $name,
    'started_at' => date('Y-m-d H:i:s'),
    'total'      => $total,
    'processed'  => 0,
    'sent'       => 0,
    'failed'     => 0,
    'status'     => 'running',
    'channel'    => $channel,
    'entries'    => [],
];
write_log($token, $log);

$sent = $failed = $emailCount = $waCount = 0;

foreach ($contacts as $i => $c) {
    $entry  = ['nome' => $c['nome'], 'email' => null, 'whatsapp' => null, 'time' => date('H:i:s')];
    $anyOk  = false;

    $msg_p  = str_replace('{nome}', $c['nome'], $message);
    $subj_p = str_replace('{nome}', $c['nome'], $subject);

    // ── Email ──────────────────────────────────────────────────────────────
    if (in_array($channel, ['email', 'both'], true) && !empty($c['email'])) {
        $result = send_email($c['email'], $c['nome'], $subj_p, $msg_p);
        $entry['email'] = $result === true ? 'ok' : $result;
        if ($result === true) $anyOk = true;
        $emailCount++;

        if ($emailCount % EMAIL_BATCH_SIZE === 0) {
            sleep(EMAIL_BATCH_WAIT);
        } else {
            usleep(rand(EMAIL_DELAY_MIN, EMAIL_DELAY_MAX) * 10000);
        }
    }

    // ── WhatsApp ───────────────────────────────────────────────────────────
    if (in_array($channel, ['whatsapp', 'both'], true)) {
        $wp = normalize_phone($c['telefone'] ?? '') ?? normalize_phone($c['telefone2'] ?? '');
        if ($wp) {
            // Seleciona par instância/chave (manual ou aleatória)
            $creds = ($sel_inst === 'random') 
                ? get_evo_credentials() 
                : get_evo_credentials_by_instance($sel_inst);

            // Simular "digitando..."
            send_presence($wp, 'composing', $creds['instance'], $creds['key']);

            // Aguarda o delay (tempo de digitação)
            usleep(rand(WA_DELAY_MIN, WA_DELAY_MAX) * 100000);

            $result = send_whatsapp($wp, $msg_p, $creds['instance'], $creds['key']);
            $entry['whatsapp'] = $result === true ? 'ok' : $result;
            if ($result === true) $anyOk = true;
            $waCount++;

            if ($waCount % WA_BATCH_SIZE === 0) {
                sleep(WA_BATCH_WAIT);
            }
        }
    }

    // Contabiliza resultado
    $hasError = ($entry['email'] !== null && $entry['email'] !== 'ok')
             || ($entry['whatsapp'] !== null && $entry['whatsapp'] !== 'ok');
    
    if ($anyOk) {
        $sent++;
    } else {
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

// ── Envio de email via Gmail SMTP ─────────────────────────────────────────────
function send_email(string $to, string $name, string $subject, string $body): bool|string {
    if (!GMAIL_PASS) return "GMAIL_PASS não configurado";
    try {
        $smtp = new SmtpClient();
        $smtp->connect(GMAIL_HOST, GMAIL_PORT);
        $smtp->starttls();
        $smtp->auth(GMAIL_USER, GMAIL_PASS);
        $smtp->mail(GMAIL_USER, GMAIL_NAME, $to, $name, $subject, $body);
        $smtp->quit();
        return true;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
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
    if (strlen($n) < 12 || substr($n, 0, 2) !== '55') return null;
    $ddd   = substr($n, 2, 2);
    $local = substr($n, 4);
    if (strlen($local) === 9 && in_array($local[0], ['6','7','8','9'], true)) return '55'.$ddd.$local;
    if (strlen($local) === 8 && in_array($local[0], ['6','7','8','9'], true)) return '55'.$ddd.'9'.$local;
    return null;
}

// ── Cliente SMTP minimalista ──────────────────────────────────────────────────
class SmtpClient {
    private mixed $sock;

    public function connect(string $host, int $port): void {
        $this->sock = fsockopen($host, $port, $errno, $errstr, 15);
        if (!$this->sock) throw new RuntimeException("Conexão SMTP falhou: $errstr ($errno)");
        $this->read();
        $this->cmd('EHLO comunidadeser.com');
    }

    public function starttls(): void {
        $this->cmd('STARTTLS');
        if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException("Falha ao iniciar TLS.");
        }
        $this->cmd('EHLO comunidadeser.com');
    }

    public function auth(string $user, string $pass): void {
        $this->cmd('AUTH LOGIN');
        $this->cmd(base64_encode($user));
        $this->cmd(base64_encode($pass));
    }

    public function mail(
        string $fromEmail, string $fromName,
        string $toEmail,   string $toName,
        string $subject,   string $body
    ): void {
        $this->cmd("MAIL FROM:<$fromEmail>");
        $this->cmd("RCPT TO:<$toEmail>");
        $this->cmd('DATA');

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <$fromEmail>",
            "To: =?UTF-8?B?" . base64_encode($toName) . "?= <$toEmail>",
            "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
            "Date: " . date('r'),
        ];

        $content = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
        $this->cmd($content);
    }

    public function quit(): void {
        $this->cmd('QUIT');
        fclose($this->sock);
    }

    private function cmd(string $c): string {
        fwrite($this->sock, $c . "\r\n");
        return $this->read();
    }

    private function read(): string {
        $res = '';
        while ($str = fgets($this->sock, 512)) {
            $res .= $str;
            if (isset($str[3]) && $str[3] === ' ') break;
        }
        if (isset($res[0]) && (int)$res[0] >= 4) throw new RuntimeException("Erro SMTP: " . trim($res));
        return $res;
    }
}

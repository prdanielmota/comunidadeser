<?php
// worker.php — processa disparo em background via CLI
// Uso: php worker.php <token>

const GMAIL_USER  = 'contato@comunidadeser.com';
const GMAIL_PASS  = 'konznitsmttbfsuz';
const GMAIL_NAME  = 'Comunidade Ser';
const GMAIL_HOST  = 'smtp.gmail.com';
const GMAIL_PORT  = 587;

const EVO_URL     = 'https://evolution.osmota.org';
const EVO_KEY     = '1E0C076ACE4B-4974-8450-E622B0129B6F';
const EVO_INST    = 'ComunidadeSer';

const EMAIL_DELAY_MIN  = 50;
const EMAIL_DELAY_MAX  = 100;
const EMAIL_BATCH_SIZE = 15;
const EMAIL_BATCH_WAIT = 60;

const WA_DELAY_MIN     = 60;
const WA_DELAY_MAX     = 140;
const WA_BATCH_SIZE    = 10;
const WA_BATCH_WAIT    = 30;

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
$channel  = $job['channel']  ?? 'both';
$subject  = $job['subject']  ?? 'Mensagem Comunidade Ser';
$message  = $job['message'];
$total    = count($contacts);

// Inicia log
$log = [
    'token'      => $token,
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
            usleep(rand(EMAIL_DELAY_MIN, EMAIL_DELAY_MAX) * 100000);
        }
    }

    // ── WhatsApp ───────────────────────────────────────────────────────────
    if (in_array($channel, ['whatsapp', 'both'], true)) {
        $wp = normalize_phone($c['telefone'] ?? '') ?? normalize_phone($c['telefone2'] ?? '');
        if ($wp) {
            $result = send_whatsapp($wp, $msg_p);
            $entry['whatsapp'] = $result === true ? 'ok' : $result;
            if ($result === true) $anyOk = true;
            $waCount++;

            if ($waCount % WA_BATCH_SIZE === 0) {
                sleep(WA_BATCH_WAIT);
            } else {
                usleep(rand(WA_DELAY_MIN, WA_DELAY_MAX) * 100000);
            }
        }
    }

    // Contabiliza resultado
    $hasError = ($entry['email'] !== null && $entry['email'] !== 'ok')
             || ($entry['whatsapp'] !== null && $entry['whatsapp'] !== 'ok');
    if ($hasError) {
        $failed++;
    } elseif ($anyOk) {
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

// ── Envio WhatsApp via Evolution API ─────────────────────────────────────────
function send_whatsapp(string $number, string $text): bool|string {
    $url = EVO_URL . '/message/sendText/' . EVO_INST;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'apikey: ' . EVO_KEY,
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
        stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
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
        $msgId = '<' . uniqid('', true) . '@comunidadeser.com>';
        $date  = date('r');
        $enc   = fn(string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';

        $this->cmd("MAIL FROM:<$fromEmail>");
        $this->cmd("RCPT TO:<$toEmail>");
        $this->cmd('DATA');

        $msg  = "From: {$enc($fromName)} <$fromEmail>\r\n";
        $msg .= "To: {$enc($toName ?: $toEmail)} <$toEmail>\r\n";
        $msg .= "Subject: {$enc($subject)}\r\n";
        $msg .= "Date: $date\r\n";
        $msg .= "Message-ID: $msgId\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n";
        $msg .= "List-Unsubscribe: <mailto:" . GMAIL_USER . "?subject=unsubscribe>\r\n";
        $msg .= "\r\n";
        $msg .= chunk_split(base64_encode($body));
        $msg .= "\r\n.\r\n";

        fwrite($this->sock, $msg);
        $this->read();
    }

    public function quit(): void {
        $this->cmd('QUIT');
        fclose($this->sock);
    }

    private function cmd(string $cmd): string {
        fwrite($this->sock, "$cmd\r\n");
        return $this->read();
    }

    private function read(): string {
        $resp = '';
        while ($line = fgets($this->sock, 512)) {
            $resp .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        $code = (int) substr($resp, 0, 3);
        if ($code >= 400) throw new RuntimeException("SMTP: $resp");
        return $resp;
    }
}

<?php
// aniversario.php — disparo automático de mensagens de aniversário
// Cron (cPanel): 0 8 * * * curl -s "https://comunidadeser.com/app/aniversario.php?key=ser2026aniv" >> /dev/null

// ── Configuração ──────────────────────────────────────────────────────────────
const CRON_KEY = 'ser2026aniv';
const DIRECTUS_URL   = 'https://cms.osmota.org';
const DIRECTUS_TOKEN = 'I4b5pP8yFdURnn8mzYPJVvQlEk6LQZv4';

const COLECAO         = 'COMUNIDADE_SER';
const COLECAO_CONFIGS = 'CONFIGURACOES_AUTOMACOES';
const COLECAO_LOGS    = 'LOGS_AUTOMACOES';

require_once __DIR__ . '/config.php';

// ── Início ────────────────────────────────────────────────────────────────────
// ── Autenticação da chamada ───────────────────────────────────────────────────
$isCli = PHP_SAPI === 'cli';
$key   = $isCli ? CRON_KEY : ($_GET['key'] ?? '');
if (!hash_equals(CRON_KEY, $key)) {
    http_response_code(403);
    exit('Acesso negado.');
}
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    // Permite que o curl receba a resposta sem timeout do browser
    if (function_exists('fastcgi_finish_request')) {
        ob_start();
    }
}

$hoje   = date('Y-m-d');
$mes    = (int) date('n');
$dia    = (int) date('j');
$inicio = microtime(true);

log_cli("=== Aniversários " . date('d/m/Y') . " ===");

$token = DIRECTUS_TOKEN;

// ── 2. Buscar configurações de mensagens ──────────────────────────────────────
$configs  = directus_get($token, '/items/' . COLECAO_CONFIGS);
$msg_wa   = '';
$msg_sub  = 'Feliz Aniversário!';
$msg_mail = '';

foreach ($configs['data'] ?? [] as $c) {
    if ($c['chave'] === 'msg_aniversario_whats') $msg_wa = $c['valor'] ?? '';
    if ($c['chave'] === 'msg_aniversario_email') {
        $msg_mail = $c['valor']    ?? '';
        $msg_sub  = $c['auxiliar'] ?? $msg_sub;
    }
}

if (!$msg_wa && !$msg_mail) {
    log_cli("AVISO: Nenhuma mensagem de aniversário configurada. Configure em Ajustes.");
    exit(0);
}
log_cli("Mensagens carregadas. WA=" . (bool)$msg_wa . " Email=" . (bool)$msg_mail);

// ── 3. Buscar aniversariantes de hoje ─────────────────────────────────────────
$filter  = http_build_query([
    'filter' => [
        '_and' => [
            ['BIRTHDAY' => ['_month' => ['_eq' => $mes]]],
            ['BIRTHDAY' => ['_day'   => ['_eq' => $dia]]],
        ],
    ],
    'fields' => 'id,NAME,EMAIL,WHATS,BIRTHDAY',
    'limit'  => -1,
]);
$result = directus_get($token, '/items/' . COLECAO . '?' . $filter);
$members = $result['data'] ?? [];

log_cli(count($members) . " aniversariante(s) hoje.");

if (empty($members)) {
    log_cli("Nenhum aniversariante. Encerrando.");
    exit(0);
}

// ── 4. Disparar mensagens ─────────────────────────────────────────────────────
$total = $sent = $failed = 0;
$waCount = $emailCount = 0;

foreach ($members as $m) {
    $nome    = $m['NAME']  ?? 'Amigo(a)';
    $email   = $m['EMAIL'] ?? '';
    $whats   = $m['WHATS'] ?? '';
    $primeiro = explode(' ', trim($nome))[0];

    log_cli("Processando: $nome");
    $total++;
    $ok = false;

    // WhatsApp
    if ($msg_wa && $whats) {
        $num = normalize_phone($whats);
        if ($num) {
            // Seleciona par instância/chave de forma aleatória
            $creds = get_evo_credentials();

            $texto = str_replace('{nome}', $primeiro, $msg_wa);
            $res = send_whatsapp($num, $texto, $creds['instance'], $creds['key']);
            if ($res === true) {
                log_cli("  WA ok ({$creds['instance']}) → $num");
                $ok = true;
                $waCount++;
                directus_log($token, $m['id'], $nome, 'whatsapp', 'sucesso');
                // delay anti-spam
                if ($waCount % 10 === 0) sleep(30);
                else usleep(rand(60, 140) * 100000);
            } else {
                log_cli("  WA erro → $res");
                directus_log($token, $m['id'], $nome, 'whatsapp', 'erro');
            }
        } else {
            log_cli("  WA ignorado → número inválido ($whats)");
        }
    }

    // Email
    if ($msg_mail && $email) {
        $corpo    = str_replace('{nome}', $primeiro, $msg_mail);
        $assunto  = str_replace('{nome}', $primeiro, $msg_sub);
        $res = send_email($email, $nome, $assunto, $corpo);
        if ($res === true) {
            log_cli("  Email ok → $email");
            $ok = true;
            $emailCount++;
            directus_log($token, $m['id'], $nome, 'email', 'sucesso');
            if ($emailCount % 15 === 0) sleep(60);
            else usleep(rand(50, 100) * 100000);
        } else {
            log_cli("  Email erro → $res");
            directus_log($token, $m['id'], $nome, 'email', 'erro');
        }
    }

    if ($ok) $sent++; else $failed++;
}

$elapsed = round(microtime(true) - $inicio, 1);
log_cli("=== Concluído em {$elapsed}s — {$sent} enviados, {$failed} falhas ===");

// ── Funções auxiliares ────────────────────────────────────────────────────────

function log_cli(string $msg): void {
    echo '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
}

function directus_get(string $token, string $path): array {
    $url = DIRECTUS_URL . $path;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true) ?: [];
}

function directus_post_raw(string $path, array $body, string $token): array {
    $url = DIRECTUS_URL . $path;
    $ch  = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($token) $headers[] = "Authorization: Bearer $token";
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true) ?: [];
}

function directus_log(string $token, int $membro_id, string $nome, string $canal, string $status): void {
    directus_post_raw('/items/' . COLECAO_LOGS, [
        'membro_id'   => $membro_id,
        'membro_nome' => $nome,
        'canal'       => $canal,
        'status'      => $status,
        'data_envio'  => date('Y-m-d\TH:i:s'),
    ], $token);
}

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

function send_whatsapp(string $number, string $text, string $instance, string $key): bool|string {
    $ch = curl_init(EVO_URL . '/message/sendText/' . $instance);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . $key],
        CURLOPT_POSTFIELDS     => json_encode(['number' => $number, 'text' => $text], JSON_UNESCAPED_UNICODE),
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

class SmtpClient {
    private mixed $sock;
    public function connect(string $host, int $port): void {
        $this->sock = fsockopen($host, $port, $errno, $errstr, 15);
        if (!$this->sock) throw new RuntimeException("SMTP falhou: $errstr ($errno)");
        $this->read(); $this->cmd('EHLO comunidadeser.com');
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
    public function mail(string $fromEmail, string $fromName, string $toEmail, string $toName, string $subject, string $body): void {
        $enc = fn(string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
        $this->cmd("MAIL FROM:<$fromEmail>");
        $this->cmd("RCPT TO:<$toEmail>");
        $this->cmd('DATA');
        $msg  = "From: {$enc($fromName)} <$fromEmail>\r\nTo: {$enc($toName ?: $toEmail)} <$toEmail>\r\n";
        $msg .= "Subject: {$enc($subject)}\r\nDate: " . date('r') . "\r\nMessage-ID: <" . uniqid('',true) . "@comunidadeser.com>\r\n";
        $msg .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $msg .= chunk_split(base64_encode($body)) . "\r\n.\r\n";
        fwrite($this->sock, $msg); $this->read();
    }
    public function quit(): void { $this->cmd('QUIT'); fclose($this->sock); }
    private function cmd(string $cmd): string { fwrite($this->sock, "$cmd\r\n"); return $this->read(); }
    private function read(): string {
        $resp = '';
        while ($line = fgets($this->sock, 512)) { $resp .= $line; if (isset($line[3]) && $line[3] === ' ') break; }
        if ((int)substr($resp, 0, 3) >= 400) throw new RuntimeException("SMTP: $resp");
        return $resp;
    }
}

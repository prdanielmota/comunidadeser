<?php
// ── Configuração ──────────────────────────────────────────────────────────────
const JOBS_DIR = __DIR__ . '/.jobs';
const LOGS_DIR = __DIR__ . '/.logs';

// ── Roteador ──────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$token  = preg_replace('/[^a-f0-9]/', '', $_GET['status'] ?? $_GET['t'] ?? '');

if ($method === 'POST') {
    handle_queue();
} elseif ($method === 'GET' && $token) {
    handle_status($token);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Bad request']);
}

// ── 1. Enfileirar job e disparar worker em background ─────────────────────────
function handle_queue(): void {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $job = json_decode($raw, true);

    if (!$job || empty($job['contacts']) || empty($job['message'])) {
        http_response_code(422);
        echo json_encode(['error' => 'Dados inválidos']);
        exit;
    }

    if (!is_dir(JOBS_DIR)) mkdir(JOBS_DIR, 0750, true);
    if (!is_dir(LOGS_DIR)) mkdir(LOGS_DIR, 0750, true);

    $token = bin2hex(random_bytes(16));
    file_put_contents(JOBS_DIR . "/$token.json", json_encode($job));

    // Dispara worker PHP em background (independente do browser)
    $php    = PHP_BINARY ?: 'php';
    $worker = escapeshellarg(__DIR__ . '/worker.php');
    $tok    = escapeshellarg($token);
    exec("nohup $php $worker $tok > /dev/null 2>&1 &");

    echo json_encode([
        'token' => $token,
        'total' => count($job['contacts']),
    ]);
}

// ── 2. Consultar status pelo log ──────────────────────────────────────────────
function handle_status(string $token): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');
    $file = LOGS_DIR . "/$token.json";
    if (!file_exists($file)) {
        echo json_encode(['status' => 'pending', 'processed' => 0, 'sent' => 0, 'failed' => 0, 'total' => 0, 'entries' => []]);
        return;
    }
    readfile($file);
}

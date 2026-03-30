<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Method not allowed']); exit; }

$body = file_get_contents('php://input');
$data = json_decode($body, true);

$nome  = trim($data['nome']  ?? '');
$email = trim($data['email'] ?? '');
$wpp   = preg_replace('/\D/', '', $data['wpp'] ?? '');

if (($nome === '' && $email === '') || $wpp === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Dados inválidos']);
    exit;
}

$entry = [
    'nome'  => mb_substr($nome, 0, 120),
    'email' => mb_substr($email, 0, 200),
    'wpp'   => $wpp,
    'classificacao' => 'interessado',
    'at'    => date('c'),
];

$file = __DIR__ . '/cadastros.json';
$lock = fopen($file . '.lock', 'c');
if (flock($lock, LOCK_EX)) {
    $list = [];
    if (file_exists($file)) {
        $raw = file_get_contents($file);
        $list = json_decode($raw, true) ?: [];
    }
    // verificar duplicata pelo número
    foreach ($list as &$item) {
        if ($item['wpp'] === $entry['wpp']) {
            // se entrou com e-mail, atualiza o registro existente
            if ($email !== '') {
                $item['email'] = $entry['email'];
                file_put_contents($file, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
            $_SESSION['user'] = $item;
            flock($lock, LOCK_UN);
            fclose($lock);
            echo json_encode(['ok'=>true,'duplicate'=>true]);
            exit;
        }
    }
    unset($item);
    $list[] = $entry;
    file_put_contents($file, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    flock($lock, LOCK_UN);
}
fclose($lock);

$_SESSION['user'] = $entry;
echo json_encode(['ok'=>true]);

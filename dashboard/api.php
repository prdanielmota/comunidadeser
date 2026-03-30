<?php
session_start();
if (empty($_SESSION['dash_auth'])) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }

header('Content-Type: application/json; charset=utf-8');
$action = $_GET['action'] ?? '';

// ── Membros App (Directus) ────────────────────────────────
if ($action === 'members') {
    $ch = curl_init('https://cms.osmota.org/items/COMUNIDADE_SER?limit=0&meta=total_count');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_HTTPHEADER      => ['Authorization: Bearer I4b5pP8yFdURnn8mzYPJVvQlEk6LQZv4'],
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

echo json_encode(['error' => 'Unknown action']);

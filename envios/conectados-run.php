<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

$data_file    = __DIR__ . '/conectados-run-data.json';
$optouts_file = __DIR__ . '/optouts.json';

if (!file_exists($data_file)) {
    http_response_code(502);
    echo json_encode(['error' => 'Arquivo de dados não encontrado.']);
    exit;
}

$data = json_decode(file_get_contents($data_file), true) ?: [];

// Mescla opt-outs do optouts.json
$oo = file_exists($optouts_file) ? (json_decode(file_get_contents($optouts_file), true) ?: []) : [];

foreach ($data as &$r) {
    $p11 = substr(preg_replace('/\D/', '', $r['telefone']), -11);
    if ($p11 && isset($oo[$p11])) {
        $r['opt_out']        = true;
        $r['opt_out_date']   = $oo[$p11]['date']   ?? null;
        $r['opt_out_source'] = $oo[$p11]['source'] ?? 'manual';
    } else {
        $r['opt_out']        = false;
        $r['opt_out_date']   = null;
        $r['opt_out_source'] = null;
    }
}
unset($r);

echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

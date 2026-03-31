<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/app/config.php';

function send_whatsapp_test($number, $text, $instance, $key) {
    $url = EVO_URL . '/message/sendText/' . $instance;
    echo "  → URL: $url\n";
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
    curl_close($ch);
    return ($code >= 200 && $code < 300) ? true : $resp;
}

$number = '5592993446715';
$instances = EVO_INSTANCES;

echo "Iniciando teste de múltiplas instâncias...\n";

foreach ($instances as $inst) {
    $creds = get_evo_credentials_by_instance($inst);
    echo "\n--- Testando Instância: " . $creds['instance'] . " ---\n";
    
    $msg = "Teste de Rotação - Instância: " . $creds['instance'] . "\nHorário: " . date('H:i:s');
    $res = send_whatsapp_test($number, $msg, $creds['instance'], $creds['key']);

    if ($res === true) {
        echo "✅ Sucesso na instância " . $creds['instance'] . "\n";
    } else {
        echo "❌ Erro na instância " . $creds['instance'] . ": " . $res . "\n";
    }
}

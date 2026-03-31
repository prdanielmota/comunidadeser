<?php
/**
 * app/token.php
 * Retorna um access_token do Directus para usuários autenticados na dashboard.
 * Chamado por app/admin.html para eliminar o login duplo.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Só responde a usuários logados na dashboard com permissão de 'app'
$user = $_SESSION['dash_user'] ?? null;
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$sistemas = $user['sistemas'] ?? [];
$role     = $user['role']     ?? '';
if ($role !== 'superadmin' && !in_array('app', $sistemas, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/config.php';

// Se não há credenciais configuradas, retorna só o token estático
if (!DIRECTUS_EMAIL || !DIRECTUS_PASSWORD) {
    echo json_encode(['token' => DIRECTUS_TOKEN, 'mode' => 'static']);
    exit;
}

// Tenta obter um token de administrador via login no Directus
// Usa cache em sessão para não fazer login a cada request
$cached = $_SESSION['_directus_token']   ?? null;
$expiry = $_SESSION['_directus_expires'] ?? 0;

if ($cached && time() < $expiry) {
    echo json_encode(['token' => $cached, 'mode' => 'admin']);
    exit;
}

$ch = curl_init(DIRECTUS_URL . '/auth/login');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode([
        'email'    => DIRECTUS_EMAIL,
        'password' => DIRECTUS_PASSWORD,
    ]),
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200) {
    // Fallback: retorna token estático
    echo json_encode(['token' => DIRECTUS_TOKEN, 'mode' => 'static', 'warning' => 'Admin login failed']);
    exit;
}

$data  = json_decode($res, true);
$token = $data['data']['access_token'] ?? null;
$ttl   = $data['data']['expires']      ?? 900000; // ms → usa 15min por padrão

if (!$token) {
    echo json_encode(['token' => DIRECTUS_TOKEN, 'mode' => 'static']);
    exit;
}

// Guarda na sessão por ~14 minutos (antes de expirar)
$_SESSION['_directus_token']   = $token;
$_SESSION['_directus_expires'] = time() + intval($ttl / 1000) - 60;

echo json_encode(['token' => $token, 'mode' => 'admin']);

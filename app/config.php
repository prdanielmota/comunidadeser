<?php
/**
 * Global Configuration for Comunidade Ser
 * Centralizes credentials and shared settings.
 */

require_once __DIR__ . '/env_loader.php';

// --- WhatsApp (Evolution API) ---
if (!defined('EVO_URL'))  define('EVO_URL',  'https://evolution.osmota.org');

// Suporte a múltiplas instâncias e chaves (do .env)
$insts_env = $_ENV['EVO_INSTANCES'] ?? $_SERVER['EVO_INSTANCES'] ?? 'ComunidadeSer';
$keys_env  = $_ENV['EVO_KEYS']      ?? $_SERVER['EVO_KEYS']      ?? ($_ENV['EVO_KEY'] ?? '');

if (!defined('EVO_INSTANCES')) define('EVO_INSTANCES', explode(',', $insts_env));
if (!defined('EVO_KEYS'))      define('EVO_KEYS',      explode(',', $keys_env));

/**
 * Seleciona um par aleatório de instância e chave (pareados pelo índice)
 */
function get_evo_credentials(): array {
    $idx = array_rand(EVO_INSTANCES);
    return get_evo_credentials_by_index($idx);
}

/**
 * Retorna as credenciais de uma instância específica pelo nome
 */
function get_evo_credentials_by_instance(string $instanceName): array {
    $idx = array_search($instanceName, EVO_INSTANCES);
    if ($idx === false) return get_evo_credentials(); // Fallback se não encontrar
    return get_evo_credentials_by_index($idx);
}

/**
 * Helper interno para retornar credenciais pelo índice
 */
function get_evo_credentials_by_index(int $idx): array {
    return [
        'instance' => trim(EVO_INSTANCES[$idx]),
        'key'      => trim(EVO_KEYS[$idx] ?? EVO_KEYS[0])
    ];
}

// --- Email (SMTP Gmail) ---
if (!defined('GMAIL_USER')) define('GMAIL_USER', 'contato@comunidadeser.com');
if (!defined('GMAIL_NAME')) define('GMAIL_NAME', 'Comunidade Ser');
if (!defined('GMAIL_HOST')) define('GMAIL_HOST', 'smtp.gmail.com');
if (!defined('GMAIL_PORT')) define('GMAIL_PORT', 587);
// GMAIL_PASS should come from .env

// --- Delays & Anti-Ban (Shared Defaults) ---
const EMAIL_DELAY_MIN  = 50;
const EMAIL_DELAY_MAX  = 100;
const EMAIL_BATCH_SIZE = 15;
const EMAIL_BATCH_WAIT = 60;

const WA_DELAY_MIN     = 60;
const WA_DELAY_MAX     = 140;
const WA_BATCH_SIZE    = 10;
const WA_BATCH_WAIT    = 30;

<?php
/**
 * Global Configuration for Comunidade Ser
 * Centralizes credentials and shared settings.
 */

require_once __DIR__ . '/env_loader.php';

// --- WhatsApp (Evolution API) ---
if (!defined('EVO_URL'))  define('EVO_URL',  'https://evolution.osmota.org');
if (!defined('EVO_INST')) define('EVO_INST', 'ComunidadeSer');
// EVO_KEY should come from .env

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

<?php
// ── Configuração da Comunidade Ser ───────────────────────────────────────────
// Este arquivo NÃO deve ser versionado (está no .gitignore).

require_once __DIR__ . '/env_loader.php';

// Directus CMS
define('DIRECTUS_URL',      'https://cms.osmota.org');
define('DIRECTUS_EMAIL',    '');
define('DIRECTUS_PASSWORD', '');

// O DIRECTUS_TOKEN e ANTHROPIC_KEY agora são carregados via env_loader.php

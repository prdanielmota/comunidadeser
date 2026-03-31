<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

$sheet_id = '1bYdgwl40kzI1Bk8WgfPBLXo0XvenSrNEKrLIl2_rxFc';
$url = "https://docs.google.com/spreadsheets/d/{$sheet_id}/export?format=csv";

$ctx = stream_context_create([
    'http' => [
        'timeout' => 20,
        'follow_location' => true,
        'max_redirects' => 5,
        'header' => "User-Agent: Mozilla/5.0\r\n",
    ]
]);

$csv = @file_get_contents($url, false, $ctx);
if ($csv === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Não foi possível buscar a planilha. Verifique se ela está pública.']);
    exit;
}

// Remove BOM se houver
$csv = ltrim($csv, "\xEF\xBB\xBF");

$rows = array_map('str_getcsv', explode("\n", trim($csv)));
if (empty($rows)) {
    echo json_encode([]);
    exit;
}

// Cabeçalho: normaliza para minúsculas sem acentos
$raw_headers = array_shift($rows);
$headers = array_map(function($h) {
    $h = mb_strtolower(trim($h), 'UTF-8');
    // remove acentos simples para comparação
    $h = strtr($h, ['é'=>'e','ê'=>'e','è'=>'e','á'=>'a','ã'=>'a','â'=>'a','à'=>'a',
                     'í'=>'i','ó'=>'o','õ'=>'o','ô'=>'o','ú'=>'u','ç'=>'c']);
    return $h;
}, $raw_headers);

// Detecta índices das colunas Nome e Telefone (aceita variações)
$idx_nome = null;
$idx_tel  = null;
foreach ($headers as $i => $h) {
    if ($idx_nome === null && in_array($h, ['nome','name','nome completo'], true)) $idx_nome = $i;
    if ($idx_tel  === null && in_array($h, ['telefone','tel','phone','whatsapp','celular','fone','numero','número'], true)) $idx_tel = $i;
}

// Fallback: usa a primeira e segunda coluna
if ($idx_nome === null) $idx_nome = 0;
if ($idx_tel  === null) $idx_tel  = 1;

// Normaliza nome: primeira letra de cada palavra maiúscula, demais minúsculas
// Preposições/artigos portugueses ficam em minúsculas (exceto no início)
function nome_titlecase(string $nome): string {
    $minusculas = ['de','da','do','das','dos','e','em','na','no','nas','nos','a','o','as','os'];
    $palavras = explode(' ', mb_strtolower(trim($nome), 'UTF-8'));
    foreach ($palavras as $i => &$p) {
        if ($p === '') continue;
        if ($i === 0 || !in_array($p, $minusculas, true)) {
            $p = mb_strtoupper(mb_substr($p, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($p, 1, null, 'UTF-8');
        }
    }
    return implode(' ', $palavras);
}

$data = [];
foreach ($rows as $row) {
    if (empty($row) || (count($row) === 1 && trim($row[0]) === '')) continue;

    $nome     = nome_titlecase(trim($row[$idx_nome] ?? ''));
    $telefone = trim($row[$idx_tel]  ?? '');

    if ($nome === '') continue;

    // Normaliza telefone: mantém só dígitos e garante DDI 55
    $tel = preg_replace('/\D/', '', $telefone);
    if ($tel !== '' && !str_starts_with($tel, '55')) {
        $tel = '55' . $tel;
    }

    $data[] = [
        'nome'           => $nome,
        'bairro'         => '',
        'religiao'       => '',
        'sexo'           => 'Não Informado',
        'idade'          => '',
        'vip'            => 'Não',
        'solicitacao'    => '',
        'solicitacao_iso'=> '',
        'materiais'      => [],
        'email'          => '',
        'email2'         => '',
        'telefone'       => $tel !== '' ? $tel : $telefone,
        'telefone2'      => '',
        'endereco'       => '',
    ];
}

// Mescla opt-outs do optouts.json (cobre webhook e opt-outs manuais)
$optouts_file = __DIR__ . '/optouts.json';
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

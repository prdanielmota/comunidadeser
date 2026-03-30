<?php
// Gera og-preview.png com fundo escuro e logo centralizada
// Execute uma vez: php og-generate.php

$outFile = __DIR__ . '/og-preview.png';

// Canvas 1200x630 (tamanho ideal para OG)
$w = 1200;
$h = 630;

$img = imagecreatetruecolor($w, $h);

// Fundo escuro #0A0F1E
$bg = imagecolorallocate($img, 10, 15, 30);
imagefill($img, 0, 0, $bg);

// Gradiente sutil dourado no canto superior esquerdo
for ($i = 0; $i < 300; $i++) {
    $alpha = (int)(105 - ($i / 300) * 105); // 0..105 (0=opaco, 127=transparente no GD)
    $c = imagecolorallocatealpha($img, 201, 168, 76, $alpha);
    imagefilledellipse($img, -80, -80, $i * 4, $i * 4, $c);
}

// Logo: usa a versão grande e redimensiona proporcional
$logoSrc = dirname(__DIR__) . '/wp-content/uploads/2025/01/logo_ser_branca-1.png';
$logo    = imagecreatefrompng($logoSrc);

$logoW = imagesx($logo);
$logoH = imagesy($logo);

// Altura destino: 340px (maior) — posicionada à direita
$dstH = 340;
$dstW = (int)($logoW * $dstH / $logoH);
$dstX = $w - $dstW - 60; // 60px da borda direita
$dstY = (int)(($h - $dstH) / 2); // centralizada verticalmente

$logoResized = imagecreatetruecolor($dstW, $dstH);
imagealphablending($logoResized, false);
imagesavealpha($logoResized, true);
$transparent = imagecolorallocatealpha($logoResized, 0, 0, 0, 127);
imagefill($logoResized, 0, 0, $transparent);

imagecopyresampled($logoResized, $logo, 0, 0, 0, 0, $dstW, $dstH, $logoW, $logoH);
imagedestroy($logo);

// Cola logo no canvas
imagealphablending($img, true);
imagecopy($img, $logoResized, $dstX, $dstY, 0, 0, $dstW, $dstH);
imagedestroy($logoResized);

// Texto à esquerda: URL
$textColor = imagecolorallocatealpha($img, 201, 168, 76, 40);
$font = 5;
$text = 'comunidadeser.com';
imagestring($img, $font, 80, (int)(($h / 2) + 20), $text, $textColor);

// Salva
imagesavealpha($img, false);
imagepng($img, $outFile, 6); // compressão 6/9
imagedestroy($img);

echo "Gerado: $outFile\n";

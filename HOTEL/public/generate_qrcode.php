<?php
require __DIR__ . '/../vendor/autoload.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

$url = 'https://unpeppery-fastuously-clarissa.ngrok-free.dev';

$options = new QROptions([
    'version'    => 5,
    'outputType' => QRCode::OUTPUT_IMAGE_PNG,
    'eccLevel'   => QRCode::ECC_L,
    'scale'      => 10,
]);

$qrcode = new QRCode($options);
$qrcode->render($url, __DIR__ . '/qrcode.png');

echo "二维码已生成！<br>";
echo "<img src='/qrcode.png' width='300'><br>";
echo "链接: " . $url;

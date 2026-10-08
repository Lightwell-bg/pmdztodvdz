<?php
// usage: php pdftest.php <vendor-prefixed-dir> <out.pdf>
error_reporting(E_ALL);
require $argv[1] . '/autoload.php';

use Bginfo\PmzhDvzh\Vendor\Dompdf\Dompdf;
use Bginfo\PmzhDvzh\Vendor\Dompdf\Options;

$tmp = sys_get_temp_dir() . '/bginfo-dompdf-test';
@mkdir($tmp, 0777, true);

$opts = new Options();
$opts->set('defaultFont', 'DejaVu Sans');
$opts->set('isRemoteEnabled', false);
$opts->set('tempDir', $tmp);
$opts->set('fontCache', $tmp);
$opts->set('chroot', dirname($argv[1]));

$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>body{font-family:"DejaVu Sans";}
table{border-collapse:collapse;width:100%}td,th{border:1px solid #333;padding:4px}</style></head><body>
<h1>Проверка кириллицы — ДВЖ</h1>
<table><tr><th>Колонка 1</th><th>Колонка 2</th><th>Колонка 3</th></tr>
<tr><td>Документ</td><td>Срок</td><td>Статус</td></tr></table>
<p><a href="https://lex.bg/">https://lex.bg/</a></p></body></html>';

$d = new Dompdf($opts);
$d->loadHtml($html, 'UTF-8');
$d->setPaper('A4');
$d->render();
file_put_contents($argv[2], $d->output());
echo get_class($d), "\nOK ", filesize($argv[2]), " bytes\n";

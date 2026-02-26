<?php
$file = 'storage/logs/laravel.log';
$fp = fopen($file, 'r');
fseek($fp, -30000, SEEK_END);
$content = fread($fp, 30000);
fclose($fp);

preg_match_all('/Error in client update process.+?(?=\[\d{4}-\d{2}-\d{2}|\z)/s', $content, $matches);
if (!empty($matches[0])) {
    $lastErrors = array_slice($matches[0], -2);
    foreach ($lastErrors as $error) {
        echo "====================================\n";
        echo substr($error, 0, 1500) . "\n";
    }
} else {
    echo "No errors found.\n";
}

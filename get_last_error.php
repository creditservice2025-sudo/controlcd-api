<?php
$file = 'storage/logs/laravel.log';
$fp = fopen($file, 'r');
fseek($fp, -10000, SEEK_END);
$content = fread($fp, 10000);
fclose($fp);

// Get the last 3 exceptions
preg_match_all('/\[\d{4}-\d{2}-\d{2}.+?\] local\.ERROR: .+?(?=\[\d{4}-\d{2}-\d{2}|\z)/s', $content, $matches);
if (!empty($matches[0])) {
    $lastErrors = array_slice($matches[0], -3);
    foreach ($lastErrors as $error) {
        echo "====================================\n";
        echo substr($error, 0, 1500) . "\n";
    }
} else {
    echo "No errors found in the last 10000 bytes.\n";
    echo $content;
}

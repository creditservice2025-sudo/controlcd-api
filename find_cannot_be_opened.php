<?php
$vendorDir = __DIR__ . '/vendor';

$it = new RecursiveDirectoryIterator($vendorDir);
$it = new RecursiveIteratorIterator($it);

foreach ($it as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $content = file_get_contents($file->getRealPath());
        if (strpos($content, 'cannot be opened') !== false) {
            echo $file->getRealPath() . "\n";
            $lines = explode("\n", $content);
            foreach ($lines as $i => $line) {
                if (strpos($line, 'cannot be opened') !== false) {
                    echo "Line " . ($i+1) . ": " . trim($line) . "\n";
                }
            }
            echo "--------------------------\n";
        }
    }
}

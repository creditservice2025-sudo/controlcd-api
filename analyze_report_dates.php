<?php

$filename = 'affected_credits_report.csv';

if (!file_exists($filename)) {
    echo "Report file not found.\n";
    exit;
}

$handle = fopen($filename, 'r');
// Skip header
fgetcsv($handle);

$earliest = null;
$latest = null;
$dates = [];
$total = 0;

while (($data = fgetcsv($handle)) !== false) {
    // Column 3 is Fecha Creacion (0-indexed: 0=ID, 1=Client, 2=Seller, 3=Date, 4=Status, 5=Balance)
    if (isset($data[3])) {
        $dateStr = $data[3]; // Format: d/m/Y H:i
        $d = DateTime::createFromFormat('d/m/Y H:i', $dateStr);
        
        if ($d) {
            $ts = $d->getTimestamp();
            if ($earliest === null || $ts < $earliest) $earliest = $ts;
            if ($latest === null || $ts > $latest) $latest = $ts;
            
            $monthYear = $d->format('Y-m');
            if (!isset($dates[$monthYear])) $dates[$monthYear] = 0;
            $dates[$monthYear]++;
            $total++;
        }
    }
}

fclose($handle);

echo "Analysis of {$total} affected credits:\n";
if ($earliest && $latest) {
    echo "Oldest Credit Created: " . date('d/m/Y', $earliest) . "\n";
    echo "Newest Credit Created: " . date('d/m/Y', $latest) . "\n";
}

echo "\nDistribution by Publication Month:\n";
ksort($dates);
foreach ($dates as $ym => $count) {
    echo "$ym: $count credits\n";
}

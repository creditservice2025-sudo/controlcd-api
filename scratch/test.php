<?php
$service = app(App\Services\LiquidationService::class);
echo json_encode($service->getAccumulatedByCity('2026-04-01', '2026-04-30', 10));

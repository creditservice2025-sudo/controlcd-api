<?php

namespace App\Services;

use App\Models\ClientGeolocationHistory;
use Illuminate\Support\Facades\Log;

class GeolocationHistoryService
{
    public function record($clientId, $lat, $lng, $actionType, $description, $actionId = null, $address = null, $accuracy = null)
    {
        try {
            if (!$lat || !$lng) {
                return;
            }

            ClientGeolocationHistory::create([
                'client_id' => $clientId,
                'latitude' => $lat,
                'longitude' => $lng,
                'accuracy' => $accuracy,
                'address' => $address,
                'action_type' => $actionType,
                'action_id' => $actionId,
                'description' => $description,
                'recorded_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error("Error recording geolocation history: " . $e->getMessage());
        }
    }
}

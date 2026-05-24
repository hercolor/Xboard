<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrafficLogResource;
use App\Services\User\LegacyTrafficReadModel;
use Illuminate\Http\Request;

class StatController extends Controller
{
    public function getTrafficLog(Request $request, LegacyTrafficReadModel $readModel)
    {
        $records = $readModel->monthlyTrafficLogsForUser(
            (int) $request->user()->id,
            now()->startOfMonth()->timestamp
        );

        return $this->success(TrafficLogResource::collection($records));
    }
}

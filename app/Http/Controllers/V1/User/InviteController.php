<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\ComissionLogResource;
use App\Http\Resources\InviteCodeResource;
use App\Models\InviteCode;
use App\Services\User\LegacyInviteReadModel;
use App\Utils\Helper;
use Illuminate\Http\Request;

class InviteController extends Controller
{
    public function save(Request $request)
    {
        if (InviteCode::where('user_id', $request->user()->id)->where('status', 0)->count() >= admin_setting('invite_gen_limit', 5)) {
            return $this->fail([400,__('The maximum number of creations has been reached')]);
        }
        $inviteCode = new InviteCode();
        $inviteCode->user_id = $request->user()->id;
        $inviteCode->code = Helper::randomChar(8);
        return $this->success($inviteCode->save());
    }

    public function details(Request $request, LegacyInviteReadModel $readModel)
    {
        $details = $readModel->detailsForUser(
            (int) $request->user()->id,
            $request->input('current'),
            $request->input('page_size')
        );

        return response([
            'data' => ComissionLogResource::collection($details['data']),
            'total' => $details['total']
        ]);
    }

    public function fetch(Request $request, LegacyInviteReadModel $readModel)
    {
        $data = $readModel->fetchForUser($request->user());

        return $this->success([
            'codes' => InviteCodeResource::collection($data['codes']),
            'stat' => $data['stat']
        ]);
    }
}

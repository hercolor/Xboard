<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\User\LegacyNoticeReadModel;
use Illuminate\Http\Request;

class NoticeController extends Controller
{
    public function fetch(Request $request, LegacyNoticeReadModel $readModel)
    {
        return response($readModel->fetch($request->input('current')));
    }
}

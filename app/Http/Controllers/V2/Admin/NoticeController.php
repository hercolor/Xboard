<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NoticeDrop;
use App\Http\Requests\Admin\NoticeSave;
use App\Http\Requests\Admin\NoticeShow;
use App\Http\Requests\Admin\NoticeSort;
use App\Models\Notice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NoticeController extends Controller
{
    public function fetch(Request $request)
    {
        return $this->success(
            Notice::orderBy('sort', 'ASC')
                ->orderBy('id', 'DESC')
                ->get()
        );
    }

    public function save(NoticeSave $request)
    {
        $params = $request->validated();
        $noticeId = $request->integer('id');

        if (!$noticeId) {
            if (!Notice::create($params)) {
                return $this->fail([500, '保存失败']);
            }

            return $this->success(true);
        }

        $notice = Notice::find($noticeId);
        if (!$notice) {
            return $this->fail([400202, '公告不存在']);
        }

        try {
            if (!$notice->update($params)) {
                return $this->fail([500, '保存失败']);
            }
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    public function show(NoticeShow $request)
    {
        $notice = Notice::find($request->integer('id'));
        if (!$notice) {
            return $this->fail([400202, '公告不存在']);
        }

        $notice->show = !$notice->show;
        if (!$notice->save()) {
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    public function drop(NoticeDrop $request)
    {
        $notice = Notice::find($request->integer('id'));
        if (!$notice) {
            return $this->fail([400202, '公告不存在']);
        }
        if (!$notice->delete()) {
            return $this->fail([500, '删除失败']);
        }
        return $this->success(true);
    }

    public function sort(NoticeSort $request)
    {
        $noticeIds = $request->validated()['ids'];

        try {
            DB::transaction(function () use ($noticeIds): void {
                foreach ($noticeIds as $index => $noticeId) {
                    $notice = Notice::find($noticeId);

                    if (!$notice || !$notice->update(['sort' => $index + 1])) {
                        throw new \RuntimeException('排序更新失败');
                    }
                }
            });

            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '排序保存失败']);
        }
    }
}

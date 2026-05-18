<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\KnowledgeDrop;
use App\Http\Requests\Admin\KnowledgeSave;
use App\Http\Requests\Admin\KnowledgeShow;
use App\Http\Requests\Admin\KnowledgeSort;
use App\Models\Knowledge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class KnowledgeController extends Controller
{
    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $knowledge = Knowledge::find($request->input('id'));
            if (!$knowledge) {
                return $this->fail([400202, '知识不存在']);
            }

            return $this->success($knowledge->toArray());
        }

        $data = Knowledge::select(['title', 'id', 'updated_at', 'category', 'show'])
            ->orderBy('sort', 'ASC')
            ->get();

        return $this->success($data);
    }

    public function getCategory(Request $request)
    {
        return $this->success(array_keys(Knowledge::get()->groupBy('category')->toArray()));
    }

    public function save(KnowledgeSave $request)
    {
        $params = $request->validated();
        $knowledgeId = $request->integer('id');

        if (!$knowledgeId) {
            if (!Knowledge::create($params)) {
                return $this->fail([500, '创建失败']);
            }

            return $this->success(true);
        }

        $knowledge = Knowledge::find($knowledgeId);
        if (!$knowledge) {
            return $this->fail([400202, '知识不存在']);
        }

        try {
            if (!$knowledge->update($params)) {
                return $this->fail([500, '创建失败']);
            }
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '创建失败']);
        }

        return $this->success(true);
    }

    public function show(KnowledgeShow $request)
    {
        $knowledge = Knowledge::find($request->integer('id'));
        if (!$knowledge) {
            throw new ApiException('知识不存在');
        }

        $knowledge->show = !$knowledge->show;
        if (!$knowledge->save()) {
            throw new ApiException('保存失败');
        }

        return $this->success(true);
    }

    public function sort(KnowledgeSort $request)
    {
        $knowledgeIds = $request->validated()['ids'];

        try {
            DB::transaction(function () use ($knowledgeIds): void {
                foreach ($knowledgeIds as $index => $knowledgeId) {
                    $knowledge = Knowledge::find($knowledgeId);

                    if (!$knowledge) {
                        throw new \RuntimeException('知识不存在');
                    }

                    $knowledge->timestamps = false;
                    if (!$knowledge->update(['sort' => $index + 1])) {
                        throw new \RuntimeException('排序更新失败');
                    }
                }
            });
        } catch (\Exception $e) {
            Log::error($e);
            throw new ApiException('保存失败');
        }

        return $this->success(true);
    }

    public function drop(KnowledgeDrop $request)
    {
        $knowledge = Knowledge::find($request->integer('id'));
        if (!$knowledge) {
            return $this->fail([400202, '知识不存在']);
        }
        if (!$knowledge->delete()) {
            return $this->fail([500, '删除失败']);
        }

        return $this->success(true);
    }
}

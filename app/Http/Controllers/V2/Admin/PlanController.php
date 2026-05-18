<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanDrop;
use App\Http\Requests\Admin\PlanSave;
use App\Http\Requests\Admin\PlanSort;
use App\Http\Requests\Admin\PlanUpdate;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlanController extends Controller
{
    public function fetch(Request $request)
    {
        $plans = Plan::orderBy('sort', 'ASC')
            ->with([
                'group:id,name'
            ])
            ->withCount([
                'users',
                'users as active_users_count' => function ($query) {
                    $query->where(function ($q) {
                        $q->where('expired_at', '>', time())
                          ->orWhereNull('expired_at');
                    });
                }
            ])
            ->get();

        return $this->success($plans);
    }

    public function save(PlanSave $request)
    {
        $params = $request->validated();
        $planId = $request->integer('id');
        
        if ($planId) {
            $plan = Plan::find($planId);
            if (!$plan) {
                return $this->fail([400202, '该订阅不存在']);
            }

            try {
                DB::transaction(function () use ($request, $params, $plan): void {
                    if ($request->boolean('force_update')) {
                        User::where('plan_id', $plan->id)->update([
                            'group_id' => $params['group_id'] ?? null,
                            'transfer_enable' => $params['transfer_enable'] * 1073741824,
                            'speed_limit' => $params['speed_limit'] ?? null,
                            'device_limit' => $params['device_limit'] ?? null,
                        ]);
                    }

                    if (!$plan->update($params)) {
                        throw new \RuntimeException('更新订阅失败');
                    }
                });

                return $this->success(true);
            } catch (\Exception $e) {
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }
        }

        if (!Plan::create($params)) {
            return $this->fail([500, '创建失败']);
        }

        return $this->success(true);
    }

    public function drop(PlanDrop $request)
    {
        $planId = $request->integer('id');

        if (Order::where('plan_id', $planId)->exists()) {
            return $this->fail([400201, '该订阅下存在订单无法删除']);
        }

        if (User::where('plan_id', $planId)->exists()) {
            return $this->fail([400201, '该订阅下存在用户无法删除']);
        }
        
        $plan = Plan::find($planId);
        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }
        
        return $this->success($plan->delete());
    }

    public function update(PlanUpdate $request)
    {
        $updateData = $request->validated();
        $planId = $request->integer('id');

        $plan = Plan::find($planId);
        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }

        try {
            $plan->update($updateData);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    public function sort(PlanSort $request)
    {
        $planIds = $request->validated('ids');

        try {
            DB::transaction(function () use ($planIds): void {
                foreach ($planIds as $index => $planId) {
                    $plan = Plan::find($planId);

                    if (!$plan || !$plan->update(['sort' => $index + 1])) {
                        throw new \RuntimeException('排序更新失败');
                    }
                }
            });
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }
}

<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\OrderSave;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\CouponService;
use App\Services\OrderService;
use App\Services\PlanService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function fetch(Request $request)
    {
        $request->validate([
            'status' => 'nullable|integer|in:0,1,2,3',
        ]);
        $orders = Order::with('plan')
            ->where('user_id', $request->user()->id)
            ->when($request->input('status') !== null, function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->orderBy('created_at', 'DESC')
            ->get();

        return $this->success(OrderResource::collection($orders));
    }

    public function detail(Request $request)
    {
        $request->validate([
            'trade_no' => 'required|string',
        ]);
        $order = Order::with('plan')
            ->where('user_id', $request->user()->id)
            ->where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            return $this->fail([400, __('Order does not exist or has been paid')]);
        }
        $order['try_out_plan_id'] = (int) admin_setting('try_out_plan_id');
        return $this->success($order);
    }

    public function save(OrderSave $request)
    {
        $userService = new UserService();
        $planService = new PlanService($request->input('plan_id'));
        $user = User::find($request->user()->id);
        $plan = Plan::find($request->input('plan_id'));

        if (!$plan) {
            return $this->fail([400, __('Plan does not exist')]);
        }
        if (!$planService->hasPermission($user, $plan)) {
            return $this->fail([400, __('You do not have permission to purchase this plan')]);
        }

        $planService->setUser($user);
        $planService->setPlan($plan);
        if (!$planService->validatePeriod($request->input('period'))) {
            return $this->fail([400, __('This payment period cannot be purchased')]);
        }

        $couponService = new CouponService($request->input('coupon_code'));
        if ($request->input('coupon_code') && !$couponService->use($user, $plan)) {
            return $this->fail([400, __('Coupon is not available')]);
        }

        DB::beginTransaction();
        $order = new Order();
        $order->user_id = $user->id;
        $order->plan_id = $plan->id;
        $order->period = $request->input('period');
        $order->trade_no = Order::generateTradeNo();
        $order->total_amount = $planService->getTotalAmount($request->input('period'));

        if ($request->input('coupon_code')) {
            $couponService->setOrder($order);
            $order->discount_amount = $couponService->getDiscountAmount();
            $order->coupon_id = $couponService->getCoupon()->id;
        }
        $order->status = 0; // pending
        if (!$order->save()) {
            DB::rollBack();
            return $this->fail([400, __('Failed to create order')]);
        }
        DB::commit();
        return $this->success($order->trade_no);
    }

    public function checkout(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user()->id)
            ->where('status', 0)
            ->first();

        if (!$order) {
            return $this->fail([400, __('Order does not exist')]);
        }

        // Mark order as paid directly (no payment gateway)
        $orderService = new OrderService($order);
        if (!$orderService->paid($order->trade_no))
            return $this->fail([400, '操作失败']);
        return $this->success(true);
    }

    public function check(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$order) {
            return $this->fail([400, __('Order does not exist')]);
        }
        return $this->success($order->status);
    }

    public function getPaymentMethod()
    {
        return $this->success([]);
    }

    public function cancel(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$order) {
            return $this->fail([400, __('Order does not exist')]);
        }
        $order->status = 3; // cancelled
        if (!$order->save())
            return $this->fail([400, __('Failed to cancel order')]);
        return $this->success(true);
    }
}

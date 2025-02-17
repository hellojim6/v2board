<?php

namespace App\Console\Commands;

use App\Services\OrderService;
use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\User;
use App\Models\Plan;
use App\Utils\Helper;
use App\Models\Coupon;

class CheckOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:order';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '订单检查任务';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $orders = Order::get();
        foreach ($orders as $item) {
            switch ($item->status) {
                // cancel
                case 0:
                    if (strtotime($item->created_at) <= (time() - 1800)) {
                        $orderService = new OrderService($item);
                        $orderService->cancel();
                    }
                    break;
                case 1:
                    $this->orderHandle($item);
                    break;
            }

        }
    }

    private function orderHandle(Order $order)
    {
        $user = User::find($order->user_id);
        $plan = Plan::find($order->plan_id);
        if ((string)$order->cycle === 'onetime_price') {
            return $this->buyByOneTime($order, $user, $plan);
        }
        return $this->buyByCycle($order, $user, $plan);
    }

    private function buyByCycle(Order $order, User $user, Plan $plan)
    {
        // change plan process
        if ((int)$order->type === 3) {
            $user->expired_at = time();
        }
        if ($order->refund_amount) {
            $user->balance = $user->balance + $order->refund_amount;
        }
        $user->transfer_enable = $plan->transfer_enable * 1073741824;
        if ((int)config('v2board.renew_reset_traffic_enable', 1)) {
            $user->u = 0;
            $user->d = 0;
        }
        $user->plan_id = $plan->id;
        $user->group_id = $plan->group_id;
        $user->expired_at = $this->getTime($order->cycle, $user->expired_at);
        if ($user->save()) {
            $order->status = 3;
            $order->save();
        }
    }

    private function buyByOneTime(Order $order, User $user, Plan $plan)
    {
        if ($order->refund_amount) {
            $user->balance = $user->balance + $order->refund_amount;
        }
        $user->transfer_enable = $plan->transfer_enable * 1073741824;
        $user->u = 0;
        $user->d = 0;
        $user->plan_id = $plan->id;
        $user->group_id = $plan->group_id;
        $user->expired_at = NULL;
        if ($user->save()) {
            $order->status = 3;
            $order->save();
        }
    }

    private function getTime($str, $timestamp)
    {
        if ($timestamp < time()) {
            $timestamp = time();
        }
        switch ($str) {
            case 'month_price':
                return strtotime('+1 month', $timestamp);
            case 'quarter_price':
                return strtotime('+3 month', $timestamp);
            case 'half_year_price':
                return strtotime('+6 month', $timestamp);
            case 'year_price':
                return strtotime('+12 month', $timestamp);
        }
    }
}

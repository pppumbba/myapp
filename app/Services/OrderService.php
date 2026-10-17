<?php

namespace App\Services;

use App\Exceptions\ServiceException;
use App\Models\Order;
use Illuminate\Pagination\LengthAwarePaginator;

class OrderService
{
    public function paginateForUser(int $userId, int $perPage = 15): LengthAwarePaginator
    {
        return Order::where('user_id', $userId)
            ->latest()
            ->paginate($perPage);
    }

    public function create(int $userId, float $total): Order
    {
        return Order::create([
            'user_id' => $userId,
            'total'   => $total,
            'status'  => Order::STATUS_PENDING,
        ]);
    }

    public function markAsPaid(Order $order): Order
    {
        return $this->transitionTo($order, Order::STATUS_PAID, [Order::STATUS_PENDING]);
    }

    public function markAsShipped(Order $order): Order
    {
        return $this->transitionTo($order, Order::STATUS_SHIPPED, [Order::STATUS_PAID]);
    }

    public function cancel(Order $order): Order
    {
        return $this->transitionTo($order, Order::STATUS_CANCELLED, [Order::STATUS_PENDING, Order::STATUS_PAID]);
    }

    /**
     * Move an order to a new status only if the current status allows it.
     */
    private function transitionTo(Order $order, string $newStatus, array $allowedFrom): Order
    {
        if (! in_array($order->status, $allowedFrom, true)) {
            throw new ServiceException(
                "Cannot change order status from '{$order->status}' to '{$newStatus}'."
            );
        }

        $order->update(['status' => $newStatus]);

        return $order->refresh();
    }
}
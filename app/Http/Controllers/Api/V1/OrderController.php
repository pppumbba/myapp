<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private OrderService $orders) {}

    public function index(Request $request)
    {
        $orders = $this->orders->paginateForUser($request->user()->id);

        return OrderResource::collection($orders);
    }

    public function store(StoreOrderRequest $request)
    {
        $order = $this->orders->create(
            $request->user()->id,
            (float) $request->validated('total')
        );

        return (new OrderResource($order))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Order $order)
    {
        $this->authorizeOwner($order);

        return new OrderResource($order);
    }

    public function pay(Order $order)
    {
        $this->authorizeOwner($order);

        return new OrderResource($this->orders->markAsPaid($order));
    }

    public function cancel(Order $order)
    {
        $this->authorizeOwner($order);

        return new OrderResource($this->orders->cancel($order));
    }

    private function authorizeOwner(Order $order): void
    {
        abort_unless($order->user_id === request()->user()->id, 404);
    }
}
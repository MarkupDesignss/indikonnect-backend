<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\HdfcPaymentAttempt;
use App\Models\Order;
use App\Services\PaymentGateway\HdfcPaymentManager;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class HdfcPaymentController extends Controller
{
    public function __construct(private HdfcPaymentManager $manager) {}

    public function initiate(Request $request, Order $order): JsonResponse
    {
        try { return response()->json($this->manager->initiate($order, (int) $request->user()->id)); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { throw $e; }
        catch (\Throwable $e) {
            Log::error('HDFC initiation error', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            return response()->json(['success'=>false, 'message'=>'Could not safely start payment. Check status before retry.'], 503);
        }
    }

    public function status(Request $request, Order $order): JsonResponse
    {
        try { return response()->json($this->manager->latest($order, (int) $request->user()->id)); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { throw $e; }
        catch (\Throwable $e) { return response()->json(['success'=>false,'message'=>'Gateway verification pending'], 503); }
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        try {
            $data = $this->manager->cancel($order, (int) $request->user()->id);
            return response()->json($data, $data['success'] ? 202 : 409);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { throw $e; }
        catch (\Throwable $e) {
            Log::error('HDFC cancel error', ['order_id'=>$order->id, 'error'=>$e->getMessage()]);
            return response()->json(['success'=>false,'message'=>'Cancellation is not confirmed'], 503);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IndiaPostShipmentSyncService;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Admin-only managed one-parcel India Post shipping workflow.
 * Customer cannot supply sender/receiver identity, company or payment state.
 * Never call from public routes; require ['auth:sanctum','admin'] middleware.
 */
final class IndiaPostShippingFlowController extends Controller
{
    private function input(Request $request): array
    {
        return $request->validate([
            'warehouse_id' => 'required|integer|min:1',
            'weight_grams' => 'required|integer|between:1,35000',
            'length_cm' => 'required|numeric|between:14,150',
            'width_cm' => 'required|numeric|between:9,150',
            'height_cm' => 'required|numeric|between:1,150',
            'barcode_no' => 'sometimes|required|regex:/^[A-Z]{2}[0-9]{9}IN$/',
            'physical_stock_verified' => 'sometimes|boolean',
        ]);
    }

    private function readiness(int $orderId, array $params, bool $stockRequired): array
    {
        // Reuses the existing read-only checks: confirmed/paid, no refunds, PIN,
        // correct warehouse, package, existing Node shipments and server config.
        $query = Request::create('/', 'GET', $params);
        $response = app(IndiaPostDispatchReadinessController::class)->show($query, (string) $orderId);
        $result = $response->getData(true);
        if ($response->getStatusCode() !== 200 || empty($result['data'])) {
            throw new RuntimeException($result['message'] ?? 'Readiness failed.');
        }
        $data = $result['data'];
        if (empty($data['ready_for_preview'])) {
            $err = new RuntimeException('Not ready: ' . implode(' | ', $data['blockers'] ?? []));
            throw $err;
        }
        if ($stockRequired && count($data['warnings'] ?? []) && empty($params['physical_stock_verified'])) {
            throw new RuntimeException('Stock warning: verify warehouse stock physically, then submit physical_stock_verified=true as admin.');
        }
        return $data;
    }

    private function id(string $orderId): int
    {
        if (!ctype_digit($orderId) || (int)$orderId < 1) {
            throw new RuntimeException('Invalid order ID');
        }
        return (int)$orderId;
    }

    private function nodePath(): string
    {
        $url = rtrim((string) config('services.indiapost_node.url', ''), '/');
        if (!str_starts_with($url, 'https://')) throw new RuntimeException('India Post Node HTTPS URL is missing.');
        return $url;
    }

    private function node(string $method, string $path, array $payload = []): Response
    {
        $key = (string) config('services.indiapost_node.api_key', '');
        if (!(bool) config('services.indiapost_node.enabled', false) || $key === '') {
            throw new RuntimeException('India Post Node adapter is not enabled.');
        }
        $http = Http::acceptJson()->withHeaders(['x-internal-api-key' => $key])
            ->connectTimeout(4)->timeout(30);
        return $method === 'GET'
            ? $http->get($this->nodePath() . $path)
            : $http->post($this->nodePath() . $path, $payload);
    }

    private function normalizedMobile($value): string
    {
        $digits = preg_replace('/\D+/', '', (string)$value);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) $digits = substr($digits, 2);
        if (!preg_match('/^[6-9][0-9]{9}$/D', $digits)) throw new RuntimeException('Sender or recipient mobile is invalid.');
        return $digits;
    }

    private function article(int $orderId, array $params, array $ready, string $barcode): array
    {
        // Read identity from trusted DB only; never accept customer/sender data from client.
        $order = DB::table('orders')->where('id', $orderId)->whereNull('deleted_at')->first();
        if (!$order) throw new RuntimeException('Order not found.');
        $warehouse = DB::table('warehouses')->where('id', $params['warehouse_id'])
            ->where('is_active', 1)->whereNull('deleted_at')->first();
        $address = DB::table('addresses')->where('id', $order->delivery_address_id)
            ->where('user_id', $order->user_id)->whereNull('deleted_at')->first();
        if (!$warehouse || !$address) throw new RuntimeException('Warehouse or owned delivery address not found.');
        $office = (string)($ready['warehouse']['verified_office']['office_id'] ?? '');
        if (!preg_match('/^[0-9]{8}$/D', $office)) throw new RuntimeException('Valid India Post drop-off office ID required.');
        $sender = trim((string)($warehouse->contact_person ?? ''));
        $receiver = trim((string)($address->recipient_name ?? ''));
        if ($sender === '' || $receiver === '') throw new RuntimeException('Sender/receiver names missing.');
        $sourceState = trim((string)($ready['warehouse']['verified_office']['state'] ?? ''));
        $destState = trim((string)($ready['destination']['verified_office']['state'] ?? ''));
        if ($sourceState === '' || $destState === '') throw new RuntimeException('India Post state lookup missing.');
        // v1 uses DROPOFF. PICKUP needs separately confirmed pickup time & address.
        return [
            'barcode_no' => $barcode,
            'pickup_or_dropoff' => 'DROPOFF',
            'pickup_dropoff_office_id' => $office,
            'article_type' => 'BUSINESS_PARCEL',
            'physical_weight' => (int)$params['weight_grams'],
            'shape_of_article' => 'NROL',
            'length' => (float)$params['length_cm'],
            'breadth_diameter' => (float)$params['width_cm'],
            'height' => (float)$params['height_cm'],
            'sender_name' => mb_substr($sender, 0, 80),
            'sender_company' => mb_substr(trim((string)($warehouse->name ?? 'IndieKonnect')) ?: 'IndieKonnect', 0, 80),
            'sender_add_line_1' => mb_substr(trim((string)$warehouse->address_line_1), 0, 80),
            'sender_add_line_2' => mb_substr(trim((string)($warehouse->address_line_2 ?? '')), 0, 80),
            'sender_city' => mb_substr(trim((string)($warehouse->city ?? '')), 0, 80),
            'sender_state' => $sourceState,
            'sender_pincode' => (string)$warehouse->pincode,
            'sender_mobile_no' => $this->normalizedMobile($warehouse->contact_number),
            'receiver_name' => mb_substr($receiver, 0, 80),
            'receiver_company' => 'INDIVIDUAL',
            'receiver_add_line_1' => mb_substr(trim((string)$address->address_line_1), 0, 80),
            'receiver_add_line_2' => mb_substr(trim((string)($address->address_line_2 ?? '')), 0, 80),
            'receiver_city' => mb_substr(trim((string)$address->city), 0, 80),
            'receiver_state' => $destState,
            'receiver_pincode' => (string)$address->postcode,
            'receiver_mobile_no' => $this->normalizedMobile($address->contact_number),
            'alt_address_flag' => 'FALSE',
            'pickup_address_flag' => 'FALSE',
            'drop_off_pincode' => (string)$warehouse->pincode,
            'ack' => 'FALSE', 'reg' => 'FALSE', 'otp' => 'FALSE',
            'bulk_reference' => mb_substr((string)$order->order_reference, 0, 50),
        ];
    }

    private function fail(Throwable $e, int $orderId): JsonResponse
    {
        Log::warning('Managed India Post flow rejected', ['order_id' => $orderId, 'error_type' => get_class($e)]);
        return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
    }

    public function allocate(Request $request, string $orderId): JsonResponse
    {
        $id = (int)$orderId;
        try {
            $id = $this->id($orderId);
            $params = $this->input($request);
            $this->readiness($id, $params, true);
            $resp = $this->node('POST', '/api/indiapost/orders/' . $id . '/awb/allocate');
            $payload = $resp->json();
            if (!$resp->successful() || empty($payload['barcode'])) {
                return response()->json(['success' => false, 'message' => $payload['message'] ?? 'AWB allocation disabled or failed.'], $resp->status());
            }
            return response()->json(['success' => true, 'data' => ['order_id'=>$id, 'awb'=>$payload['barcode'], 'already_allocated'=>$payload['alreadyAllocated'] ?? false, 'booking_performed'=>false]]);
        } catch (Throwable $e) { return $this->fail($e, $id); }
    }

    public function preview(Request $request, string $orderId): JsonResponse
    {
        $id = (int)$orderId;
        try {
            $id = $this->id($orderId);
            $params = $this->input($request);
            $barcode = strtoupper((string)($params['barcode_no'] ?? ''));
            if (!$barcode) throw new RuntimeException('barcode_no required; allocate AWB first.');
            $ready = $this->readiness($id, $params, true);
            $article = $this->article($id, $params, $ready, $barcode);
            $resp = $this->node('POST', '/api/indiapost/booking/preview', ['orderId'=>$id,'articles'=>[$article]]);
            $json = $resp->json();
            if (!$resp->successful() || empty($json['ready'])) {
                return response()->json(['success'=>false,'message'=>$json['message'] ?? 'Node preview failed','details'=>$json['details'] ?? null], $resp->status());
            }
            return response()->json(['success'=>true,'data'=>['order_id'=>$id,'awb'=>$barcode,'ready_for_booking'=>true,
                'booking_enabled'=>$json['bookingEnabled'] ?? false, 'warnings'=>$ready['warnings'] ?? [],
                'article'=>$article, 'booking_performed'=>false]])->header('Cache-Control','private, no-store');
        } catch (Throwable $e) { return $this->fail($e, $id); }
    }

    public function book(Request $request, string $orderId): JsonResponse
    {
        $id=(int)$orderId;
        try {
            $id=$this->id($orderId);
            $params=$this->input($request);
            $barcode=strtoupper((string)($params['barcode_no'] ?? ''));
            if (!$barcode) throw new RuntimeException('barcode_no required.');
            $request->validate(['confirm_authorized_uat_booking'=>'required|accepted']);
            $ready=$this->readiness($id,$params,true);
            $article=$this->article($id,$params,$ready,$barcode);
            $body=['orderId'=>$id,'articles'=>[$article]];
            $preview=$this->node('POST','/api/indiapost/booking/preview',$body);
            if (!$preview->successful() || empty($preview->json()['ready'])) {
                return response()->json(['success'=>false,'message'=>$preview->json()['message'] ?? 'Node preview failed.'],422);
            }
            $health=$this->node('GET','/api/indiapost/health');
            if (!$health->successful() || empty($health->json()['bookingEnabled'])) {
                return response()->json(['success'=>false,'message'=>'Booking disabled. Do not enable before approved UAT.'],409);
            }
            // Unique order_id ensures a concurrent request cannot create two local attempts.
            DB::transaction(function () use ($id,$barcode,$params): void {
                $order=DB::table('orders')->where('id',$id)->lockForUpdate()->first();
                if (!$order || $order->status !== 'confirmed') throw new RuntimeException('Order is no longer confirmed.');
                if (DB::table('returns')->where('order_id',$id)->whereNull('deleted_at')->whereIn('status', ['pending','approved','partially_approved','received','completed'])->exists()
                    || DB::table('refunds')->where('order_id',$id)->whereIn('status',['initiated','completed'])->exists()
                    || DB::table('credit_notes')->where('order_id',$id)->exists()) {
                    throw new RuntimeException('Return/refund/credit note present. Never book.');
                }
                if (DB::table('indiapost_order_shipments')->where('order_id',$id)->exists()) throw new RuntimeException('Booking was already attempted. Reconcile; no automatic retry.');
                DB::table('indiapost_order_shipments')->insert([
                    'order_id'=>$id, 'warehouse_id'=>$params['warehouse_id'], 'barcode_no'=>$barcode,
                    'booking_status'=>'submitting','weight_grams'=>$params['weight_grams'],
                    'length_cm'=>$params['length_cm'],'width_cm'=>$params['width_cm'],'height_cm'=>$params['height_cm'],
                    'booking_attempted_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
                ]);
            });
            // A timeout is AMBIGUOUS: retain local attempt, never blindly retry.
            try {
                $resp=$this->node('POST','/api/indiapost/booking/submit-managed',$body);
                $json=$resp->json();
                $submitted=$resp->successful() && ($json['providerAcceptedRequest'] ?? false) === true
                    && in_array($barcode,$json['summary']['submitted'] ?? [],true);
                $status=$submitted?'submitted':($resp->status()===409 || in_array($barcode,$json['summary']['rejected'] ?? [],true) ? 'needs_reconciliation':'unknown');
                DB::table('indiapost_order_shipments')->where('order_id',$id)->update([
                    'booking_status'=>$status, 'provider_accepted'=>$submitted,
                    'provider_batch_id'=>isset($json['providerBatchId'])?(string)$json['providerBatchId']:null,
                    'updated_at'=>now(),
                ]);
                if (!$submitted) return response()->json(['success'=>false,'message'=>'Submission not confirmed. Reconcile the AWB in Node/provider before doing anything else.',
                    'order_id'=>$id,'awb'=>$barcode,'node_status'=>$resp->status(),'booking_status'=>$status],409);
                return response()->json(['success'=>true,'data'=>['order_id'=>$id,'awb'=>$barcode,'booking_status'=>'submitted',
                    'provider_batch_id'=>$json['providerBatchId'] ?? null,'physical_booking_confirmed'=>false,
                    'next_action'=>'Print verified label/manifest, hand parcel to courier, then sync actual India Post scan events.']],202);
            } catch (Throwable $upstreamError) {
                DB::table('indiapost_order_shipments')->where('order_id',$id)->update(['booking_status'=>'unknown','updated_at'=>now()]);
                Log::error('India Post booking outcome unknown; manual reconciliation required', ['order_id'=>$id, 'error_type'=>get_class($upstreamError)]);
                return response()->json(['success'=>false,'message'=>'Booking outcome unknown. Do not retry; reconcile with carrier and Node shipment record.','awb'=>$barcode],502);
            }
        } catch (Throwable $e) { return $this->fail($e, $id); }
    }

    public function shipments(Request $request, string $orderId): JsonResponse
    {
        $id=(int)$orderId;
        try {
            $id=$this->id($orderId);
            $local=DB::table('indiapost_order_shipments')->where('order_id',$id)->first();
            if (!$local) return response()->json(['success'=>true,'data'=>['order_id'=>$id,'shipments'=>[]]]);
            $remote=$this->node('GET','/api/indiapost/orders/' . $id . '/shipments');
            $data=$remote->json();
            if (!$remote->successful()) return response()->json(['success'=>false,'message'=>'Node shipments unavailable'],502);
            return response()->json(['success'=>true,'data'=>['order_id'=>$id,'local_status'=>$local->booking_status,
                'shipments'=>array_values(array_filter($data['shipments'] ?? [], fn($s)=>($s['barcode_no'] ?? '')===$local->barcode_no))]])
                ->header('Cache-Control','private, no-store');
        } catch (Throwable $e) {return $this->fail($e,$id);}
    }

    public function sync(Request $request, string $orderId, IndiaPostShipmentSyncService $service): JsonResponse
    {
        $id=(int)$orderId;
        try { $id=$this->id($orderId); return response()->json(['success'=>true,'data'=>$service->syncOne($id)]); }
        catch(Throwable $e) {return $this->fail($e,$id);}
    }
}

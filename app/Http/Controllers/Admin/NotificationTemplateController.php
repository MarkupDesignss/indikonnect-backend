<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use App\Services\NotificationTemplateService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class NotificationTemplateController extends Controller
{
    protected NotificationTemplateService $templateService;

    public function __construct(NotificationTemplateService $templateService)
    {
        $this->templateService = $templateService;
    }

    /**
     * Display a listing of templates with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = NotificationTemplate::with('updatedBy:id,name');

        if ($request->has('event_type')) {
            $query->where('event_type', $request->event_type);
        }

        if ($request->has('channel')) {
            $query->where('channel', $request->channel);
        }

        if ($request->has('is_active') && $request->is_active !== '') {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('event_type', 'LIKE', "%{$search}%")
                    ->orWhere('subject', 'LIKE', "%{$search}%")
                    ->orWhere('body', 'LIKE', "%{$search}%");
            });
        }

        $perPage = $request->input('per_page', 15);
        $templates = $query->orderBy('event_type')
            ->orderBy('channel')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $templates->items(),
            'meta' => [
                'current_page' => $templates->currentPage(),
                'per_page' => $templates->perPage(),
                'total' => $templates->total(),
                'last_page' => $templates->lastPage(),
            ],
            'filters' => [
                'event_types' => NotificationTemplate::distinct()->pluck('event_type')->values(),
                'channels' => ['email', 'sms', 'push', 'database'],
            ]
        ]);
    }

    /**
     * Store a newly created template.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->getValidationRules($request));

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        $existingActive = NotificationTemplate::where('event_type', $validated['event_type'])
            ->where('channel', $validated['channel'])
            ->where('is_active', true)
            ->first();

        $template = new NotificationTemplate();
        $template->fill($validated);
        $template->updated_by = Auth::id();
        $template->is_active = $request->input('is_active', false) && !$existingActive;
        $template->placeholders = $this->extractPlaceholders($validated['body']);
        $template->save();

        $this->templateService->clearCache($template->event_type, $template->channel);

        return response()->json([
            'success' => true,
            'message' => 'Template created successfully',
            'data' => $template->load('updatedBy:id,name')
        ], 201);
    }

    /**
     * Display the specified template.
     */
    public function show($id): JsonResponse
    {
        $template = NotificationTemplate::with('updatedBy:id,name')->find($id);

        if (!$template) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $template
        ]);
    }

    /**
     * Update the specified template.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $template = NotificationTemplate::find($id);

        if (!$template) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found'
            ], 404);
        }

        // Get validation rules for update (fields are optional)
        $validator = Validator::make($request->all(), $this->getValidationRules($request, $id));

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        // If template is being set as active, deactivate other templates for same event/channel
        if (isset($validated['is_active']) && $validated['is_active'] === true) {
            NotificationTemplate::where('event_type', $template->event_type)
                ->where('channel', $template->channel)
                ->where('id', '!=', $template->id)
                ->update(['is_active' => false]);
        }

        // Only update fields that are provided
        if (isset($validated['event_type'])) {
            $template->event_type = $validated['event_type'];
        }

        if (isset($validated['channel'])) {
            $template->channel = $validated['channel'];
        }

        if (isset($validated['subject'])) {
            $template->subject = $validated['subject'];
        }

        if (isset($validated['body'])) {
            $template->body = $validated['body'];
            $template->placeholders = $this->extractPlaceholders($validated['body']);
        }

        if (isset($validated['is_active'])) {
            $template->is_active = $validated['is_active'];
        }

        $template->updated_by = Auth::id();
        $template->save();

        $this->templateService->clearCache($template->event_type, $template->channel);

        return response()->json([
            'success' => true,
            'message' => 'Template updated successfully',
            'data' => $template->load('updatedBy:id,name')
        ]);
    }

    /**
     * Remove the specified template.
     */
    public function destroy($id): JsonResponse
    {
        $template = NotificationTemplate::find($id);

        if (!$template) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found'
            ], 404);
        }

        if ($template->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete an active template. Deactivate it first.'
            ], 422);
        }

        $template->delete();

        return response()->json([
            'success' => true,
            'message' => 'Template deleted successfully'
        ]);
    }

    /**
     * Activate a template.
     */
    public function activate($id): JsonResponse
    {
        $template = NotificationTemplate::find($id);

        if (!$template) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found'
            ], 404);
        }

        if ($template->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Template is already active'
            ], 422);
        }

        NotificationTemplate::where('event_type', $template->event_type)
            ->where('channel', $template->channel)
            ->where('id', '!=', $template->id)
            ->update(['is_active' => false]);

        $template->is_active = true;
        $template->save();

        $this->templateService->clearCache($template->event_type, $template->channel);

        return response()->json([
            'success' => true,
            'message' => 'Template activated successfully',
            'data' => $template->load('updatedBy:id,name')
        ]);
    }

    /**
     * Preview template with sample data.
     */
    public function preview(Request $request, $id): JsonResponse
    {
        $template = NotificationTemplate::find($id);

        if (!$template) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found'
            ], 404);
        }

        $sampleData = $request->input('sample_data', []);

        return response()->json([
            'success' => true,
            'data' => [
                'subject' => $template->renderSubject($sampleData),
                'body' => $template->renderBody($sampleData),
                'placeholders' => $template->placeholders,
                'provided_data' => $sampleData,
                'missing_placeholders' => array_diff($template->placeholders ?? [], array_keys($sampleData))
            ]
        ]);
    }

    /**
     * Get active template for a specific event and channel.
     */
    public function getActiveTemplate($eventType, $channel): JsonResponse
    {
        $template = $this->templateService->getTemplate($eventType, $channel);

        if (!$template) {
            return response()->json([
                'success' => false,
                'message' => "No active template found for {$eventType} on {$channel}"
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $template
        ]);
    }

    /**
     * Get all available event types.
     */
    public function eventTypes(): JsonResponse
    {
        $eventTypes = NotificationTemplate::distinct()->pluck('event_type')->values();
        $allEvents = $this->getEventTypes();

        return response()->json([
            'success' => true,
            'data' => [
                'used' => $eventTypes,
                'available' => $allEvents,
                'all' => array_values($allEvents)
            ]
        ]);
    }

    /**
     * Get all available channels.
     */
    public function channels(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['email', 'sms', 'push', 'database']
        ]);
    }

    /**
     * Get validation rules.
     */
    private function getValidationRules(Request $request, $excludeId = null): array
    {
        // For update operations, fields are optional
        $isUpdate = $excludeId !== null;

        $rules = [
            'event_type' => [
                $isUpdate ? 'sometimes' : 'required',
                'string',
                'max:100',
            ],
            'channel' => [
                $isUpdate ? 'sometimes' : 'required',
                'string',
                Rule::in(['email', 'sms', 'push', 'database'])
            ],
            'subject' => 'nullable|string|max:255',
            'body' => [
                $isUpdate ? 'sometimes' : 'required',
                'string'
            ],
            'is_active' => 'sometimes|boolean',
        ];

        // For email and push, subject is required (if provided)
        if ($request->has('channel') && in_array($request->input('channel'), ['email', 'push'])) {
            $rules['subject'] = $isUpdate ? 'sometimes|required|string|max:255' : 'required|string|max:255';
        }

        // Only check uniqueness when creating a new template OR when event_type or channel is being changed
        if (!$isUpdate) {
            // Create: always check uniqueness
            $rules['event_type'][] = Rule::unique('notification_templates')
                ->where(function ($query) use ($request) {
                    $query->where('event_type', $request->event_type)
                        ->where('channel', $request->channel);
                });
        } elseif ($request->has('event_type') || $request->has('channel')) {
            // Update: check uniqueness only if event_type or channel is being changed
            $rules['event_type'][] = Rule::unique('notification_templates')
                ->where(function ($query) use ($request, $excludeId) {
                    // Use the new values if provided, otherwise use existing
                    $eventType = $request->has('event_type') ? $request->event_type : null;
                    $channel = $request->has('channel') ? $request->channel : null;

                    if ($eventType && $channel) {
                        $query->where('event_type', $eventType)
                            ->where('channel', $channel);
                    }

                    // Ignore the current record
                    $query->where('id', '!=', $excludeId);
                });
        }

        return $rules;
    }

    /**
     * Extract placeholders from template body.
     */
    private function extractPlaceholders($body): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/', $body, $matches);
        return array_values(array_unique($matches[1]));
    }

    /**
     * Get available event types.
     */
    private function getEventTypes(): array
    {
        return [
            'order_confirmed' => 'Order Confirmed',
            'order_shipped' => 'Order Shipped',
            'order_delivered' => 'Order Delivered',
            'payment_received' => 'Payment Received',
            'payout_released' => 'Payout Released',
            'kyc_approved' => 'KYC Approved',
            'kyc_rejected' => 'KYC Rejected',
            'account_created' => 'Account Created',
            'password_reset' => 'Password Reset',
            'email_verification' => 'Email Verification',
            'invoice_generated' => 'Invoice Generated',
            'subscription_renewed' => 'Subscription Renewed',
            'subscription_expired' => 'Subscription Expired',
            'ticket_created' => 'Ticket Created',
            'ticket_updated' => 'Ticket Updated',
            'ticket_closed' => 'Ticket Closed',
        ];
    }
}

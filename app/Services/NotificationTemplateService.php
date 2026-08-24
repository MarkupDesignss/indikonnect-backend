<?php

namespace App\Services;

use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class NotificationTemplateService
{
    protected int $cacheDuration = 3600;

    /**
     * Get the active template for a specific event and channel.
     */
    public function getTemplate(string $eventType, string $channel): ?NotificationTemplate
    {
        $cacheKey = $this->getCacheKey($eventType, $channel);

        return Cache::remember($cacheKey, $this->cacheDuration, function () use ($eventType, $channel) {
            return NotificationTemplate::where('event_type', $eventType)
                ->where('channel', $channel)
                ->where('is_active', true)
                ->first();
        });
    }

    /**
     * Render a notification template with dynamic data.
     */
    public function render(string $eventType, string $channel, array $data = []): array
    {
        $template = $this->getTemplate($eventType, $channel);

        if (!$template) {
            throw new \Exception("No active template found for {$eventType} on {$channel} channel");
        }

        return [
            'subject' => $template->renderSubject($data),
            'body' => $template->renderBody($data),
            'template' => $template,
            'placeholders_used' => array_keys($data),
            'missing_placeholders' => $this->getMissingPlaceholders($template, $data)
        ];
    }

    /**
     * Render template safely without throwing exceptions.
     */
    public function renderSafe(string $eventType, string $channel, array $data = []): ?array
    {
        try {
            return $this->render($eventType, $channel, $data);
        } catch (\Exception $e) {
            Log::warning("Template rendering failed: {$e->getMessage()}", [
                'event_type' => $eventType,
                'channel' => $channel
            ]);
            return null;
        }
    }

    /**
     * Clear template cache.
     */
    public function clearCache(string $eventType, string $channel): void
    {
        $cacheKey = $this->getCacheKey($eventType, $channel);
        Cache::forget($cacheKey);
    }

    /**
     * Get missing placeholders for a template.
     */
    public function getMissingPlaceholders(NotificationTemplate $template, array $data): array
    {
        if (empty($template->placeholders)) {
            return [];
        }

        return array_diff($template->placeholders, array_keys($data));
    }

    /**
     * Validate if all required placeholders are provided.
     */
    public function validatePlaceholders(NotificationTemplate $template, array $data): bool
    {
        return empty($this->getMissingPlaceholders($template, $data));
    }

    /**
     * Get cache key for template.
     */
    protected function getCacheKey(string $eventType, string $channel): string
    {
        return "notification_template_{$eventType}_{$channel}";
    }

    /**
     * Get template stats.
     */
    public function getStats(): array
    {
        return [
            'total' => NotificationTemplate::count(),
            'active' => NotificationTemplate::where('is_active', true)->count(),
            'inactive' => NotificationTemplate::where('is_active', false)->count(),
            'by_event_type' => NotificationTemplate::select('event_type')
                ->selectRaw('count(*) as total, sum(case when is_active = 1 then 1 else 0 end) as active')
                ->groupBy('event_type')
                ->get()
                ->toArray(),
            'by_channel' => NotificationTemplate::select('channel')
                ->selectRaw('count(*) as total, sum(case when is_active = 1 then 1 else 0 end) as active')
                ->groupBy('channel')
                ->get()
                ->toArray()
        ];
    }
}

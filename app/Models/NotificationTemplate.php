<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NotificationTemplate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'event_type',
        'channel',
        'subject',
        'body',
        'placeholders',
        'is_active',
        'version',
        'updated_by'
    ];

    protected $casts = [
        'placeholders' => 'array',
        'is_active' => 'boolean',
        'version' => 'integer'
    ];

    // Relationships
    public function updatedBy()
    {
        return $this->belongsTo(Admin::class, 'updated_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByEvent($query, $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    public function scopeByChannel($query, $channel)
    {
        return $query->where('channel', $channel);
    }

    // Helper methods
    public function getPlaceholdersListAttribute()
    {
        return $this->placeholders ? implode(', ', $this->placeholders) : '';
    }

    public function renderBody(array $data = [])
    {
        $body = $this->body;
        foreach ($data as $key => $value) {
            $body = str_replace('{{' . $key . '}}', $value, $body);
        }
        return $body;
    }

    public function renderSubject(array $data = [])
    {
        if (!$this->subject) {
            return null;
        }
        $subject = $this->subject;
        foreach ($data as $key => $value) {
            $subject = str_replace('{{' . $key . '}}', $value, $subject);
        }
        return $subject;
    }

    public function createNewVersion(array $data)
    {
        $newVersion = $this->replicate();
        $newVersion->version = $this->version + 1;
        $newVersion->is_active = false;
        $newVersion->fill($data);
        $newVersion->save();

        return $newVersion;
    }

    public function activate()
    {
        // Deactivate all other versions for this event+channel
        self::where('event_type', $this->event_type)
            ->where('channel', $this->channel)
            ->where('id', '!=', $this->id)
            ->update(['is_active' => false]);

        $this->is_active = true;
        $this->save();

        return $this;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotificationSetting extends Model
{
    protected $table = 'user_notification_settings';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'email_notifications',
        'order_updates',
        'payment_alerts',
        'promotional_emails',
        'security_alerts',
    ];

    protected $casts = [
        'email_notifications' => 'boolean',
        'order_updates' => 'boolean',
        'payment_alerts' => 'boolean',
        'promotional_emails' => 'boolean',
        'security_alerts' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

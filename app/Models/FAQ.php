<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FAQ extends Model
{
    protected $table = 'faqs';

    protected $fillable = [
        'section_id',
        'question',
        'answer',
        'order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order'     => 'integer',
    ];

    // ---------- Relationships ----------

    public function section(): BelongsTo
    {
        return $this->belongsTo(FaqSection::class, 'section_id');
    }

    // ---------- Scopes ----------

    public function scopeSection($query, $sectionId)
    {
        if (empty($sectionId)) {
            return $query;
        }

        return is_array($sectionId)
            ? $query->whereIn('section_id', $sectionId)
            : $query->where('section_id', $sectionId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order', 'asc');
    }
}

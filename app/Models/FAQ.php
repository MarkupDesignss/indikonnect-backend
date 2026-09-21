<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FAQ extends Model
{
    protected $table = 'faqs';

    protected $fillable = [
        'section',
        'question',
        'answer',
        'order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order'     => 'integer',
    ];

    /**
     * Scope: filter by one or more sections.
     *
     * Usage:
     *   FAQ::section('Company Legality & Incorporation')->get();
     *   FAQ::section(['Section A', 'Section B'])->get();
     */
    public function scopeSection($query, $section)
    {
        if (empty($section)) {
            return $query;
        }

        return is_array($section)
            ? $query->whereIn('section', $section)
            : $query->where('section', $section);
    }

    /**
     * Scope: only active FAQs.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: default ordering — section ASC, then order ASC.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('section', 'asc')->orderBy('order', 'asc');
    }
}

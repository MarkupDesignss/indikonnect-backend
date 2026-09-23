<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class FaqSection extends Model
{
    protected $table = 'faq_sections';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order'     => 'integer',
    ];

    /**
     * Auto-generate slug from name if not provided.
     */
    protected static function booted(): void
    {
        static::creating(function (FaqSection $section) {
            if (empty($section->slug)) {
                $section->slug = static::generateUniqueSlug($section->name);
            }
        });
    }

    public static function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i    = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    // ---------- Relationships ----------

    public function faqs(): HasMany
    {
        return $this->hasMany(FAQ::class, 'section_id')->orderBy('order');
    }

    public function activeFaqs(): HasMany
    {
        return $this->hasMany(FAQ::class, 'section_id')
            ->where('is_active', true)
            ->orderBy('order');
    }

    // ---------- Scopes ----------

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order', 'asc')->orderBy('name', 'asc');
    }
}

<?php

namespace App\Domains\Admin\Models;

use Database\Factories\ContentPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A piece of app text staff edit (J01): help, support contact, terms, community guidelines.
 * Each save bumps the version, so "which terms did she see" has an answer later (Q-055).
 */
#[Fillable(['key', 'body_ar', 'body_en', 'version', 'updated_by'])]
class ContentPage extends Model
{
    /** @use HasFactory<ContentPageFactory> */
    use HasFactory;

    public const array KEYS = ['help', 'support_contact', 'terms', 'guidelines'];

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by');
    }
}

<?php

namespace App\Domains\Safety\Models;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Geo\Models\Corridor;
use Database\Factories\EscortWindowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['corridor_id', 'starts_at', 'ends_at', 'is_auto'])]
class EscortWindow extends Model
{
    /** @use HasFactory<EscortWindowFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'trips_covered' => 'integer',
            'is_auto' => 'boolean',
        ];
    }

    public function corridor(): BelongsTo
    {
        return $this->belongsTo(Corridor::class);
    }

    public function armedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'armed_by');
    }

    public function isActive(): bool
    {
        return now()->between($this->starts_at, $this->ends_at);
    }
}

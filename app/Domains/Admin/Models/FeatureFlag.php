<?php

namespace App\Domains\Admin\Models;

use Database\Factories\FeatureFlagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['flag_key', 'enabled', 'rollout_percentage', 'audience_filter'])]
class FeatureFlag extends Model
{
    /** @use HasFactory<FeatureFlagFactory> */
    use HasFactory;

    protected $primaryKey = 'flag_key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'rollout_percentage' => 'integer',
            'audience_filter' => 'array',
        ];
    }

    public function isEnabledFor(?string $seed = null): bool
    {
        if (! $this->enabled) {
            return false;
        }

        if ($this->rollout_percentage >= 100) {
            return true;
        }

        if ($seed === null) {
            return $this->rollout_percentage > 0;
        }

        return (crc32($seed) % 100) < $this->rollout_percentage;
    }
}

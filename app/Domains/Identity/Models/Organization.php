<?php

namespace App\Domains\Identity\Models;

use App\Domains\Identity\Enums\OrganizationType;
use App\Domains\Shared\Casts\SpatialPoint;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'name_ar', 'type', 'email_domain', 'city', 'district', 'location_point', 'is_verified'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'location_point' => SpatialPoint::class,
            'is_verified' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}

<?php

namespace App\Domains\Verification\Models;

use App\Domains\Verification\Enums\DocumentKind;
use App\Domains\Verification\Enums\VirusScanStatus;
use Database\Factories\IdentityDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `file_path` points at a private disk only — never a public URL (pitfall
 * #23). Access must always go through a short-lived presigned URL generated
 * by an authorized Action, never by exposing this column directly.
 */
#[Fillable(['user_verification_id', 'kind', 'file_path', 'file_hash', 'mime_type', 'size_bytes', 'expires_at', 'purge_after'])]
#[Hidden(['file_path', 'ocr_payload'])]
class IdentityDocument extends Model
{
    /** @use HasFactory<IdentityDocumentFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'kind' => DocumentKind::class,
            'size_bytes' => 'integer',
            'ocr_payload' => 'encrypted:array',
            'virus_scan_status' => VirusScanStatus::class,
            'expires_at' => 'datetime',
            'purge_after' => 'date',
        ];
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(UserVerification::class, 'user_verification_id');
    }
}

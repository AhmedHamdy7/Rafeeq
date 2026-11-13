<?php

namespace App\Http\Resources;

use App\Domains\Admin\Models\ContentPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One content text in both languages, with its version: a new version is published every
 * time staff save it, so the app can tell when the text it cached has changed.
 *
 * @mixin ContentPage
 */
final class ContentPageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            /** One of help, support_contact, terms, guidelines. */
            'key' => (string) $this->key,
            /** Goes up by one on every save. */
            'version' => (int) $this->version,
            /** Arabic text. Plain text with line breaks. */
            'ar' => (string) $this->body_ar,
            /** English text. Plain text with line breaks. */
            'en' => (string) $this->body_en,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}

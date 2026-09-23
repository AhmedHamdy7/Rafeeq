<?php

namespace App\Http\Requests\Driver;

use App\Domains\Driver\Enums\VehicleDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Vehicle documents (Chapter 3 §8).
 *
 * ---
 *
 * Maintainer note: `mimes:` inspects the file's real type from its contents,
 * not the declared Content-Type header. It is a first filter only — the bytes
 * are scanned and re-encoded before anything is stored, because an image header
 * says nothing about what follows it.
 */
final class UploadVehicleDocumentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Registration is required before an application can be submitted.
            // Insurance and inspection are accepted now and will become
            // required later.
            'type' => ['required', 'string', Rule::enum(VehicleDocumentType::class)],

            // A photo of the document. JPEG, PNG or WebP. Metadata including
            // any GPS location is stripped before storage.
            'file' => [
                'required', 'file',
                'mimes:jpeg,jpg,png,webp',
                'max:'.config('rafeeq.verification.max_document_size_kilobytes'),
            ],

            // When the document expires, if it says. Used to warn before a
            // registration lapses.
            'expiresAt' => ['nullable', 'date', 'date_format:Y-m-d', 'after:today'],
        ];
    }
}

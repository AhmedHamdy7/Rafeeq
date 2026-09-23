<?php

namespace App\Http\Requests\Account;

use App\Domains\Verification\Enums\DocumentKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Chapter 3 §5/§8.
 *
 * ---
 *
 * Maintainer notes (comments above the rules are published as the field
 * descriptions in the OpenAPI document, so they are written for the mobile
 * team — see binding standard #38):
 *
 * `mimes:` checks the file's real type via its contents, not the declared
 * `Content-Type` header, which the uploader controls. It is a first filter
 * only: the bytes are scanned and then re-encoded before anything is stored,
 * because a valid image header says nothing about what follows it.
 */
final class UploadVerificationDocumentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Which document this is. Must be one the step actually asks for —
            // `GET /v1/account/verifications` lists them per row.
            'kind' => ['required', Rule::enum(DocumentKind::class)],

            // A photo of the document. JPEG, PNG or WebP. Metadata including
            // any GPS location is stripped server-side before storage, and the
            // image is re-encoded as JPEG.
            'file' => [
                'required', 'file',
                'mimes:jpeg,jpg,png,webp',
                'max:'.config('rafeeq.verification.max_document_size_kilobytes'),
            ],
        ];
    }

    public function kind(): DocumentKind
    {
        return DocumentKind::from($this->validated('kind'));
    }
}

<?php

namespace App\Http\Requests\Safety;

use App\Domains\Safety\Enums\EvidenceKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Attaching a file to a report (Chapter 10, screen 32).
 *
 * ---
 *
 * Maintainer notes (comments above the rules are published as field descriptions in the OpenAPI
 * document, so they are written for the mobile team — binding standard #38):
 *
 * **Images only, and that is a limit worth stating plainly.** `EvidenceKind` also has `video`,
 * `audio` and `document`, and the schema has had room for them since Phase 1 — but the intake
 * pipeline strips metadata by re-encoding a raster image, and there is no equivalent for a video or
 * an audio file. Accepting one would mean either storing it unsanitised (handing over the GPS and
 * device identifiers in its container) or claiming a protection that is not there. Refusing it
 * honestly is the safer of the two, so the kinds are narrowed here rather than in the enum.
 */
final class AttachIncidentEvidenceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * What the file is. `photo` for a picture taken at the scene, `screenshot` for a capture
             * of messages or of the app. Video, audio and documents are not accepted yet — see the
             * note above.
             */
            'kind' => ['required', Rule::enum(EvidenceKind::class)->only([
                EvidenceKind::Photo,
                EvidenceKind::Screenshot,
            ])],

            /*
             * The file. JPEG, PNG or WebP.
             *
             * 🔒 Metadata including the GPS location the photograph was taken at is stripped
             * server-side before anything is stored, and the image is re-encoded as JPEG. Do not
             * strip it on the device and assume that is enough — but do not send a file you have
             * not told the person you are sending, either.
             */
            'file' => [
                'required', 'file',
                'mimes:jpeg,jpg,png,webp',
                'max:'.config('rafeeq.safety.max_evidence_size_kilobytes'),
            ],
        ];
    }

    public function kind(): EvidenceKind
    {
        return EvidenceKind::from($this->validated('kind'));
    }
}

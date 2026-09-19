<?php

namespace App\Domains\Safety\Enums;

enum EvidenceKind: string
{
    case Photo = 'photo';
    case Video = 'video';
    case Audio = 'audio';
    case Screenshot = 'screenshot';
    case Document = 'document';
}

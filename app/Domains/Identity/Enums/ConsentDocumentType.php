<?php

namespace App\Domains\Identity\Enums;

enum ConsentDocumentType: string
{
    case Terms = 'terms';
    case Privacy = 'privacy';
    case Marketing = 'marketing';
}

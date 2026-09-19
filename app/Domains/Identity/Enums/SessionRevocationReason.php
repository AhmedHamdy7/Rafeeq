<?php

namespace App\Domains\Identity\Enums;

enum SessionRevocationReason: string
{
    case UserLogout = 'user_logout';
    case TokenReuse = 'token_reuse';
    case Admin = 'admin';
    case StolenDevice = 'stolen_device';
}

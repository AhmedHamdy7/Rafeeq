<?php

namespace App\Domains\Notification\Enums;

enum NotificationChannel: string
{
    case Push = 'push';
    case Sms = 'sms';
    case InApp = 'in_app';
    case Email = 'email';
}

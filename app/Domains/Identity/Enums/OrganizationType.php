<?php

namespace App\Domains\Identity\Enums;

enum OrganizationType: string
{
    case Work = 'work';
    case University = 'university';
    case School = 'school';
}

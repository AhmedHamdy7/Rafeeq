<?php

namespace App\Domains\Identity\Enums;

enum OrganizationType: string
{
    case Work = 'work';
    case University = 'university';
    case School = 'school';

    /**
     * The same kind, expressed as the value `users.org_type` holds. The two
     * enums exist separately because one describes an organization and the
     * other describes a person's relationship to one — but every organization
     * type must be expressible on an account, or verifying against it could
     * not record where the person actually goes.
     */
    public function toOrgType(): OrgType
    {
        return match ($this) {
            self::Work => OrgType::Work,
            self::University => OrgType::University,
            self::School => OrgType::School,
        };
    }
}

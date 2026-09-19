<?php

namespace App\Domains\Admin\Enums;

/**
 * The RBAC role names Spatie Permission stores in the `roles` table (Bible
 * §Group 13). Kept as an enum so role names are never hand-typed as strings
 * scattered across Actions/Policies/seeders.
 */
enum AdminRole: string
{
    case SuperAdmin = 'super_admin';
    case Operations = 'operations';
    case Verification = 'verification';
    case Finance = 'finance';
    case Support = 'support';
    case SafetyLead = 'safety_lead';
}

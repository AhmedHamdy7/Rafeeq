<?php

namespace App\Domains\Identity\Enums;

/**
 * What kind of place a member commutes to, as recorded on their own account.
 *
 * `School` is not in the ERD's list for `users.org_type`, which names only
 * work and university — but the same ERD lets `organizations.type` be a
 * school, so without it a member of a school could not be given the type of
 * the organization they were just verified against. The alternatives were
 * both worse: mapping a school to `university` records something untrue about
 * a real person, and leaving the column null makes "not answered yet"
 * indistinguishable from "answered, we just had no word for it". The column
 * is a varchar, so this needs no migration.
 */
enum OrgType: string
{
    case Work = 'work';
    case University = 'university';
    case School = 'school';
}

<?php

namespace App\Domains\Safety\Enums;

enum SafetyEventType: string
{
    case Sos = 'sos';
    case DiscreetAlert = 'discreet_alert';
    case LiveShareStarted = 'live_share_started';
    case IncidentCreated = 'incident_created';
    case EscortArmed = 'escort_armed';
    case AdminIntervention = 'admin_intervention';

    /** The reporter appealed the decision on her report (HISTORY-10). */
    case IncidentAppealed = 'incident_appealed';
}

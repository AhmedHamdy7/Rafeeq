<?php

namespace App\Domains\Reliability\Enums;

/**
 * Which side of the car a reliability event is about.
 *
 * 🔴 One account is one person doing two things, and her two records must stay apart. A woman who
 * drives carelessly and rides impeccably has two different standings; merging them would have a
 * future driver refuse her a seat for something she did at the wheel, and would let a flawless
 * passenger record hide a driver's cancellations from the monthly review.
 *
 * The same separation `on_time_rate` already keeps — computed per role, never pooled.
 */
enum ReliabilityRole: string
{
    case Driver = 'driver';

    case Passenger = 'passenger';
}

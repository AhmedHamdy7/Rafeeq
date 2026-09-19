<?php

use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Matching\Models\MatchNotification;
use Illuminate\Database\QueryException;

it('rejects a duplicate (demand, offer) notification pair', function () {
    $demand = CommuteDemand::factory()->create();
    $notification = MatchNotification::factory()->for($demand, 'commuteDemand')->create();

    MatchNotification::factory()->create([
        'commute_demand_id' => $demand->id,
        'commute_offer_id' => $notification->commute_offer_id,
    ]);
})->throws(QueryException::class);

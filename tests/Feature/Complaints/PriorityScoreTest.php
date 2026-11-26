<?php

use App\Models\Category;

require_once __DIR__.'/helpers.php';

/*
| Rule version 1 (design §6.1): Urgency 0/15/30/45, safety risk +25, Affected Units 0/10/20,
| Location Importance 0/10/15, Essential Service outage +20; clamp 0–100;
| Low 0–24, Medium 25–49, High 50–74, Critical 75–100. Safety risk or an outage → at least High.
| Expected values below are worked by hand from those rules.
*/

it('scores the design document worked example as 100, Critical, with each input\'s points', function () {
    ['lift' => $lift] = complaintsSetup();

    submitComplaint(complaintBody([
        'location_id' => $lift->id, 'urgency' => 'high', 'safety_risk' => true,
        'affected_units' => 'several', 'essential_service_down' => true,
    ]))->assertCreated()
        ->assertJsonPath('data.priority_score', 100)
        ->assertJsonPath('data.priority', 'critical')
        ->assertJsonPath('data.priority_rule_version', 1)
        ->assertJsonPath('data.priority_breakdown', [
            'urgency' => 30, 'safety_risk' => 25, 'affected_units' => 10, 'location_importance' => 15, 'essential_service' => 20,
        ]);
});

it('maps scores to Priority bands', function (string $where, array $answers, int $score, string $priority) {
    $setup = complaintsSetup();

    submitComplaint(complaintBody(['location_id' => $setup[$where]->id, ...$answers]))->assertCreated()
        ->assertJsonPath('data.priority_score', $score)
        ->assertJsonPath('data.priority', $priority);
})->with([
    'nothing serious → 0, Low' => ['unit', [], 0, 'low'],
    'several units → 10, Low' => ['unit', ['affected_units' => 'several'], 10, 'low'],
    'medium + several → 25, Medium' => ['unit', ['urgency' => 'medium', 'affected_units' => 'several'], 25, 'medium'],
    'medium + several at an essential lift → 40, Medium' => ['lift', ['urgency' => 'medium', 'affected_units' => 'several'], 40, 'medium'],
    'high + many → 50, High' => ['unit', ['urgency' => 'high', 'affected_units' => 'many'], 50, 'high'],
    'critical + many → 65, High' => ['unit', ['urgency' => 'critical', 'affected_units' => 'many'], 65, 'high'],
    'critical + many at an essential lift → 80, Critical' => ['lift', ['urgency' => 'critical', 'affected_units' => 'many'], 80, 'critical'],
    'everything at once → 125 clamped to 100, Critical' => ['lift', ['urgency' => 'critical', 'safety_risk' => true, 'affected_units' => 'many', 'essential_service_down' => true], 100, 'critical'],
]);

it('raises safety risk or an Essential Service outage to at least High', function (array $answers, int $score) {
    ['pool' => $pool] = complaintsSetup();

    submitComplaint(complaintBody(['location_id' => $pool->id, ...$answers]))->assertCreated()
        ->assertJsonPath('data.priority_score', $score)
        ->assertJsonPath('data.priority', 'high');
})->with([
    'safety risk alone scores 25, Medium' => [['safety_risk' => true], 25],
    'an outage alone scores 20, Low' => [['essential_service_down' => true], 20],
]);

it('never applies the Category default priority as a minimum', function () {
    ['unit' => $unit] = complaintsSetup();
    $plumbing = Category::where('key', 'plumbing')->sole();   // defaults to High

    submitComplaint(complaintBody(['location_id' => $unit->id, 'category_id' => $plumbing->id, 'title' => 'Dripping tap']))
        ->assertCreated()
        ->assertJsonPath('data.category.name', 'Plumbing & Water Supply')
        ->assertJsonPath('data.priority_score', 0)
        ->assertJsonPath('data.priority', 'low');
});

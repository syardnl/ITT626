<?php

use App\Enums\LocationType;
use App\Enums\Role;
use App\Models\Category;
use App\Models\Complaint;

require_once __DIR__.'/helpers.php';

it('lets a Resident report a problem at a Common Area', function () {
    ['lift' => $lift] = complaintsSetup();

    submitComplaint(complaintBody(['location_id' => $lift->id]))
        ->assertCreated()
        ->assertJsonPath('data.reference_no', 'CMP-'.now('Asia/Kuala_Lumpur')->year.'-0001')
        ->assertJsonPath('data.status', 'reported')
        ->assertJsonPath('data.title', 'Lift not operating')
        ->assertJsonPath('data.location.code', 'A-LIFT-1');
});

it('files "My unit" against the Resident\'s linked Unit', function () {
    complaintsSetup();

    submitComplaint(complaintBody(['my_unit' => true]))
        ->assertCreated()
        ->assertJsonPath('data.location.code', 'A-01-01');
});

it('also accepts the Resident\'s own Unit given by id', function () {
    ['unit' => $unit] = complaintsSetup();

    submitComplaint(complaintBody(['location_id' => $unit->id]))->assertCreated()->assertJsonPath('data.location.code', 'A-01-01');
});

it('accepts any active Common Area, including Shared Areas', function () {
    ['pool' => $pool] = complaintsSetup();

    submitComplaint(complaintBody(['location_id' => $pool->id]))->assertCreated()->assertJsonPath('data.location.code', 'POOL');
});

it('rejects Locations a Resident may not report against', function (Closure $location) {
    $setup = complaintsSetup();

    submitComplaint(complaintBody(['location_id' => $location($setup)]))
        ->assertStatus(422)->assertJsonValidationErrors('location_id');
    expect(Complaint::count())->toBe(0);
})->with([
    'someone else\'s Unit' => [fn ($s) => $s['neighbour']->id],
    'a Block' => [fn ($s) => $s['block']->id],
    'a Floor' => [fn ($s) => $s['floor']->id],
    'an inactive Common Area' => [fn ($s) => place(LocationType::CommonArea, $s['block'], 'A-OLD', active: false)->id],
    'a Location that does not exist' => [fn ($s) => 999999],
]);

it('needs exactly one of "My unit" or a Location', function (array $choice) {
    ['lift' => $lift] = complaintsSetup();
    $choice = array_map(fn ($v) => $v === 'lift' ? $lift->id : $v, $choice);

    submitComplaint([...complaintBody(), ...$choice])->assertStatus(422);
})->with([
    'neither' => [[]],
    'both' => [['my_unit' => true, 'location_id' => 'lift']],
]);

it('refuses the Resident\'s Unit while it is deactivated', function () {
    ['unit' => $unit] = complaintsSetup();
    $unit->update(['active' => false]);

    submitComplaint(complaintBody(['my_unit' => true]))->assertStatus(422)->assertJsonValidationErrors('my_unit');
    submitComplaint(complaintBody(['location_id' => $unit->id]))->assertStatus(422)->assertJsonValidationErrors('location_id');
    expect(Complaint::count())->toBe(0);
});

it('accepts a report without a Category, left for the office to classify', function () {
    ['lift' => $lift] = complaintsSetup();

    submitComplaint(complaintBody(['location_id' => $lift->id]))->assertCreated()->assertJsonPath('data.category', null);
    submitComplaint(complaintBody(['location_id' => $lift->id, 'category_id' => null]))->assertCreated()->assertJsonPath('data.category', null);
});

it('lets only Residents submit', function (Role $role) {
    ['lift' => $lift] = complaintsSetup();

    $this->actingAs(account($role));
    submitComplaint(complaintBody(['location_id' => $lift->id]))->assertForbidden();
})->with([Role::Manager, Role::Technician, Role::Administrator]);

it('validates each answer', function (array $override, string $field) {
    ['lift' => $lift] = complaintsSetup();

    submitComplaint(complaintBody(['location_id' => $lift->id, ...$override]))
        ->assertStatus(422)->assertJsonValidationErrors($field);
    expect(Complaint::count())->toBe(0);
})->with([
    'title too short' => [['title' => 'Lift'], 'title'],
    'title too long' => [['title' => str_repeat('a', 151)], 'title'],
    'description too short' => [['description' => 'Broken'], 'description'],
    'description too long' => [['description' => str_repeat('a', 3001)], 'description'],
    'unknown urgency' => [['urgency' => 'extreme'], 'urgency'],
    'unknown affected units' => [['affected_units' => 'all'], 'affected_units'],
    'missing safety risk' => [['safety_risk' => null], 'safety_risk'],
    'missing essential service answer' => [['essential_service_down' => null], 'essential_service_down'],
    'unknown Category' => [['category_id' => 999999], 'category_id'],
]);

it('rejects an inactive Category', function () {
    ['lift' => $lift] = complaintsSetup();
    $liftCategory = Category::where('key', 'lift')->sole();
    $liftCategory->update(['active' => false]);

    submitComplaint(complaintBody(['location_id' => $lift->id, 'category_id' => $liftCategory->id]))
        ->assertStatus(422)->assertJsonValidationErrors('category_id');
});

it('records each submission in the audit log', function () {
    ['lift' => $lift, 'rina' => $rina] = complaintsSetup();
    $id = submitComplaint(complaintBody(['location_id' => $lift->id]))->assertCreated()->json('data.id');

    $this->actingAs(account(Role::Administrator))
        ->getJson('/api/v1/admin/audit-logs?target_type=complaint')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.action', 'created')
        ->assertJsonPath('data.0.target_id', $id)
        ->assertJsonPath('data.0.actor.id', $rina->id);
});

<?php

use App\Enums\ComplaintStatus;
use App\Enums\Role;

require_once __DIR__.'/helpers.php';

/** The candidates' names in ranked order. */
function rankedNames(int $complaintId): array
{
    return array_column(array_column(recommendationsFor($complaintId)->assertOk()->json('data'), 'technician'), 'name');
}

it('ranks Available before Busy, then matching Skill, then lowest load, then home Block, then id', function () {
    ['complaint' => $complaint, 'block' => $blockA, 'blockB' => $blockB] = $setup = assignmentSetup();
    technicianProfile('Ben Busy specialist', $blockA->id, ['availability' => 'busy']);
    technicianProfile('Cara Electrician', $blockA->id, ['skills' => ['electrical']]);
    $dan = technicianProfile('Dan Loaded', $blockA->id, ['max_tasks' => 2]);
    technicianProfile('Amy Block B', $blockB->id);
    technicianProfile('Eve Block A', $blockA->id);
    technicianProfile('Hal Block A', $blockA->id);
    // Dan has one Active Task out of two (ratio 0.5); everyone else has none.
    assign(classifiedComplaint($setup), ['technician_id' => $dan['id']])->assertOk();

    expect(rankedNames($complaint))->toBe([
        'Eve Block A',          // Available, Lift Skill, 0/5, same Block, lower id than Hal
        'Hal Block A',
        'Amy Block B',          // as Eve but another Block
        'Dan Loaded',           // 1 of 2 Active Tasks
        'Cara Electrician',     // Available but no Lift Skill
        'Ben Busy specialist',  // Busy ranks last whatever the Skill
    ]);
});

it('compares load as a ratio of Active Tasks to maximum tasks', function () {
    ['complaint' => $complaint, 'block' => $block] = $setup = assignmentSetup();
    $small = technicianProfile('Sid 1 of 2', $block->id, ['max_tasks' => 2]);
    $large = technicianProfile('Lia 2 of 10', $block->id, ['max_tasks' => 10]);
    assign(classifiedComplaint($setup), ['technician_id' => $small['id']])->assertOk();
    assign(classifiedComplaint($setup), ['technician_id' => $large['id']])->assertOk();
    assign(classifiedComplaint($setup), ['technician_id' => $large['id']])->assertOk();

    expect(rankedNames($complaint))->toBe(['Lia 2 of 10', 'Sid 1 of 2']);
});

it('gives each candidate the reasons for their rank', function () {
    ['complaint' => $complaint, 'block' => $block, 'blockB' => $blockB] = $setup = assignmentSetup();
    $tariq = technicianProfile('Tariq Technician', $block->id, ['max_tasks' => 3]);
    technicianProfile('Bella Busy', $blockB->id, ['availability' => 'busy', 'skills' => ['electrical']]);
    assign(classifiedComplaint($setup), ['technician_id' => $tariq['id']])->assertOk();

    $candidates = recommendationsFor($complaint)->assertOk()->json('data');

    expect($candidates[0])->toMatchArray([
        'rank' => 1,
        'technician' => ['id' => $tariq['id'], 'name' => 'Tariq Technician', 'type' => 'in_house', 'company_name' => null],
        'availability' => 'available', 'skill_match' => true, 'active_tasks' => 1, 'max_tasks' => 3,
        'at_capacity' => false, 'same_block' => true, 'current' => false,
        'home_block' => ['id' => $block->id, 'code' => 'A', 'name' => 'A'],
        'reasons' => ['Available', 'Has the Lift Skill', '1 of 3 Active Tasks', 'Home Block A, same as the Complaint'],
        'warnings' => [],
    ])->and($candidates[1])->toMatchArray([
        'rank' => 2, 'availability' => 'busy', 'skill_match' => false, 'same_block' => false,
        'reasons' => ['Busy', 'No Lift Skill', '0 of 5 Active Tasks', 'Home Block B'],
        'warnings' => ['Busy: already loaded with other work.'],
    ]);
});

it('flags a Technician at maximum tasks', function () {
    ['complaint' => $complaint, 'block' => $block] = $setup = assignmentSetup();
    $tariq = technicianProfile('Tariq Technician', $block->id, ['max_tasks' => 1]);
    assign(classifiedComplaint($setup), ['technician_id' => $tariq['id']])->assertOk();

    expect(recommendationsFor($complaint)->json('data.0'))->toMatchArray([
        'active_tasks' => 1, 'at_capacity' => true,
        'warnings' => ['At maximum tasks (1 of 1): assigning needs an override with a reason.'],
    ]);
});

it('leaves out Technicians On leave or with a deactivated account', function () {
    ['complaint' => $complaint, 'block' => $block, 'manager' => $manager] = assignmentSetup();
    technicianProfile('Olga On leave', $block->id, ['availability' => 'on_leave']);
    $ivan = technicianProfile('Ivan Inactive', $block->id);
    technicianProfile('Tariq Technician', $block->id);
    $this->actingAs(account(Role::Administrator))->patchJson("/api/v1/admin/users/{$ivan['user']->id}", ['active' => false])->assertOk();
    $this->actingAs($manager);

    expect(rankedNames($complaint))->toBe(['Tariq Technician']);
});

it('counts only Assigned, In Progress and Returned Complaints as Active Tasks', function () {
    ['complaint' => $complaint, 'block' => $block] = $setup = assignmentSetup();
    $tariq = technicianProfile('Tariq Technician', $block->id);
    foreach (ComplaintStatus::cases() as $status) {
        $id = classifiedComplaint($setup);
        assign($id, ['technician_id' => $tariq['id']])->assertOk();
        forceStatus($id, $status);
    }

    expect(recommendationsFor($complaint)->json('data.0.active_tasks'))->toBe(3);
});

it('ranks nobody as the same Block for a Complaint at a Shared Area', function () {
    ['block' => $block, 'pool' => $pool, 'rina' => $rina, 'manager' => $manager] = $setup = assignmentSetup();
    technicianProfile('Tariq Technician', $block->id);
    $this->actingAs($rina);
    $id = submitComplaint(complaintBody(['location_id' => $pool->id]))->json('data.id');
    $this->actingAs($manager);
    $this->postJson("/api/v1/complaints/{$id}/review", ['version' => loadedVersion($id)])->assertOk();
    $this->patchJson("/api/v1/complaints/{$id}/classification", ['category_id' => $setup['liftCategory']->id, 'priority' => 'low', 'version' => loadedVersion($id)])->assertOk();

    expect(recommendationsFor($id)->json('data.0'))->toMatchArray(['same_block' => false])
        ->and(recommendationsFor($id)->json('data.0.reasons.3'))->toBe('Home Block A');
});

it('marks the current assignee', function () {
    ['complaint' => $complaint, 'block' => $block] = assignmentSetup();
    $tariq = technicianProfile('Tariq Technician', $block->id);
    technicianProfile('Una Other', $block->id);
    assign($complaint, ['technician_id' => $tariq['id']])->assertOk();

    $current = collect(recommendationsFor($complaint)->json('data'))->keyBy('technician.name');
    expect($current['Tariq Technician']['current'])->toBeTrue()
        ->and($current['Una Other']['current'])->toBeFalse();
});

it('asks for a Category before recommending anyone', function () {
    $setup = complaintsSetup();
    $id = submitComplaint(complaintBody(['location_id' => $setup['lift']->id]))->json('data.id');
    $this->actingAs(account(Role::Manager));
    $this->postJson("/api/v1/complaints/{$id}/review", ['version' => loadedVersion($id)])->assertOk();

    recommendationsFor($id)->assertConflict()
        ->assertJsonPath('message', 'Classify this Complaint before assigning a Technician.');
});

it('conceals a Complaint that does not exist', function () {
    assignmentSetup();

    recommendationsFor(999999)->assertNotFound();
});

it('recommends only to Managers', function (Role $role) {
    ['complaint' => $complaint] = assignmentSetup();
    $this->actingAs(account($role));

    recommendationsFor($complaint)->assertForbidden();
})->with([Role::Resident, Role::Technician, Role::Administrator]);

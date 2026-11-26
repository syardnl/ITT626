<?php

use App\Enums\Role;
use App\Models\Category;

require_once __DIR__.'/helpers.php';

it('requires a company name for a Contractor and keeps it off In-house profiles', function () {
    ['technician' => $tariq, 'blockA' => $blockA, 'plumbing' => $plumbing] = techniciansSetup();
    $body = fn (array $overrides) => profileBody($tariq, $blockA['id'], [$plumbing], $overrides);

    createProfile($body(['type' => 'contractor']))
        ->assertStatus(422)->assertJsonValidationErrors(['company_name' => 'A Contractor needs a company name.']);
    createProfile($body(['type' => 'contractor', 'company_name' => '   ']))
        ->assertStatus(422)->assertJsonValidationErrors('company_name');
    createProfile($body(['company_name' => 'Lift Co']))
        ->assertStatus(422)->assertJsonValidationErrors(['company_name' => 'Only Contractors have a company name.']);
    createProfile($body(['type' => 'freelancer']))
        ->assertStatus(422)->assertJsonValidationErrors('type');

    createProfile($body(['type' => 'contractor', 'company_name' => 'Lift Co Sdn Bhd']))->assertCreated()
        ->assertJsonPath('data.type', 'contractor')
        ->assertJsonPath('data.company_name', 'Lift Co Sdn Bhd');
});

it('needs one or more active Skills', function () {
    ['technician' => $tariq, 'blockA' => $blockA, 'plumbing' => $plumbing, 'electrical' => $electrical, 'actor' => $manager] = techniciansSetup();
    $lift = Category::where('key', 'lift')->firstOrFail();
    $this->actingAs(account(Role::Administrator))
        ->patchJson("/api/v1/admin/categories/{$lift->id}", ['active' => false])->assertOk();
    $this->actingAs($manager);

    createProfile(profileBody($tariq, $blockA['id'], []))
        ->assertStatus(422)->assertJsonValidationErrors(['skill_ids' => 'Choose at least one Skill.']);
    createProfile(profileBody($tariq, $blockA['id'], [$plumbing, $lift->skill->id]))
        ->assertStatus(422)->assertJsonValidationErrors(['skill_ids.1' => 'Choose an active Skill.']);
    createProfile(profileBody($tariq, $blockA['id'], [999999]))
        ->assertStatus(422)->assertJsonValidationErrors('skill_ids.0');
    createProfile(profileBody($tariq, $blockA['id'], [$plumbing, $plumbing]))
        ->assertStatus(422)->assertJsonValidationErrors('skill_ids.0');

    createProfile(profileBody($tariq, $blockA['id'], [$electrical]))->assertCreated();
});

it('needs a positive whole number of maximum tasks', function (mixed $maxTasks) {
    ['technician' => $tariq, 'blockA' => $blockA, 'plumbing' => $plumbing] = techniciansSetup();

    createProfile(profileBody($tariq, $blockA['id'], [$plumbing], ['max_tasks' => $maxTasks]))
        ->assertStatus(422)->assertJsonValidationErrors('max_tasks');
})->with([0, -1, 101, 2.5, 'many', null]);

it('needs an active Block as home Block', function () {
    ['technician' => $tariq, 'blockA' => $blockA, 'blockB' => $blockB, 'floor' => $floor, 'plumbing' => $plumbing, 'actor' => $manager] = techniciansSetup();
    $this->actingAs(account(Role::Administrator))
        ->patchJson("/api/v1/admin/locations/{$blockB['id']}", ['active' => false])->assertOk();
    $this->actingAs($manager);

    createProfile(profileBody($tariq, $floor['id'], [$plumbing]))
        ->assertStatus(422)->assertJsonValidationErrors(['home_block_id' => 'Choose an active Block.']);
    createProfile(profileBody($tariq, $blockB['id'], [$plumbing]))
        ->assertStatus(422)->assertJsonValidationErrors('home_block_id');

    createProfile(profileBody($tariq, $blockA['id'], [$plumbing]))->assertCreated();
});

it('accepts only Available, Busy or On leave as Availability', function () {
    ['technician' => $tariq, 'blockA' => $blockA, 'plumbing' => $plumbing] = techniciansSetup();

    createProfile(profileBody($tariq, $blockA['id'], [$plumbing], ['availability' => 'sick']))
        ->assertStatus(422)->assertJsonValidationErrors('availability');
    createProfile(profileBody($tariq, $blockA['id'], [$plumbing], ['availability' => 'on_leave']))
        ->assertCreated()->assertJsonPath('data.availability', 'on_leave');
});

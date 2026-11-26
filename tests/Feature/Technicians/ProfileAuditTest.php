<?php

use App\Enums\Role;

require_once __DIR__.'/helpers.php';

function technicianAuditEntries(): array
{
    return test()->actingAs(account(Role::Administrator))
        ->getJson('/api/v1/admin/audit-logs?target_type=technician')->assertOk()->json('data');
}

it('records who created a profile and with what, Skills included', function () {
    ['technician' => $tariq, 'blockA' => $a, 'plumbing' => $plumbing, 'electrical' => $electrical, 'actor' => $manager] = techniciansSetup();
    $id = createProfile(profileBody($tariq, $a['id'], [$plumbing, $electrical]))->json('data.id');

    [$entry] = technicianAuditEntries();
    expect($entry)
        ->action->toBe('created')
        ->target_id->toBe($id)
        ->and($entry['actor']['id'])->toBe($manager->id)
        ->and($entry['changes']['user_id'])->toBe(['from' => null, 'to' => $tariq->id])
        ->and($entry['changes']['availability'])->toBe(['from' => null, 'to' => 'available'])
        ->and($entry['changes']['max_tasks'])->toBe(['from' => null, 'to' => 5])
        ->and($entry['changes']['skill_ids'])->toBe(['from' => null, 'to' => collect([$plumbing, $electrical])->sort()->values()->all()]);
});

it('records each edit with only the fields that changed', function () {
    ['technician' => $tariq, 'blockA' => $a, 'plumbing' => $plumbing, 'electrical' => $electrical] = techniciansSetup();
    $id = createProfile(profileBody($tariq, $a['id'], [$plumbing]))->json('data.id');

    $this->patchJson("/api/v1/technicians/{$id}", ['availability' => 'on_leave', 'max_tasks' => 5])->assertOk();
    $this->patchJson("/api/v1/technicians/{$id}", ['skill_ids' => [$electrical]])->assertOk();
    $this->patchJson("/api/v1/technicians/{$id}", ['type' => 'contractor', 'company_name' => 'Volt Sdn Bhd'])->assertOk();
    $this->patchJson("/api/v1/technicians/{$id}", ['skill_ids' => [$electrical]])->assertOk();

    [$company, $skills, $availability] = technicianAuditEntries();
    expect($availability)->action->toBe('updated')->changes->toBe(['availability' => ['from' => 'available', 'to' => 'on_leave']])
        ->and($skills['changes'])->toBe(['skill_ids' => ['from' => [$plumbing], 'to' => [$electrical]]])
        ->and($company['changes'])->toBe([
            'type' => ['from' => 'in_house', 'to' => 'contractor'],
            'company_name' => ['from' => null, 'to' => 'Volt Sdn Bhd'],
        ])
        ->and(technicianAuditEntries())->toHaveCount(4);
});

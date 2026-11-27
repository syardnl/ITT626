<?php

use App\Enums\ComplaintStatus;
use App\Enums\Role;
use App\Models\Complaint;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('evidence'));

function demoAccount(string $email): User
{
    return User::where('email', $email)->firstOrFail();
}

/** Every Complaint the office can see, keyed by Location code. */
function demoComplaintsByLocation(): array
{
    test()->actingAs(demoAccount('manager@demo.test'));

    return collect(test()->getJson('/api/v1/complaints?per_page=100')->assertOk()->json('data'))
        ->keyBy('location.code')->all();
}

it('creates no Complaints from the reference seed', function () {
    $this->seed(ReferenceSeeder::class);
    $this->actingAs(account(Role::Manager));

    $this->getJson('/api/v1/complaints')->assertOk()->assertJsonPath('meta.total', 0);
});

it('adds a second demo Resident in Block A who can sign in', function () {
    $this->seed(DemoSeeder::class);

    $this->postJson('/api/v1/auth/login', ['email' => 'resident2@demo.test', 'password' => DemoSeeder::PASSWORD])
        ->assertOk()->assertJsonPath('data.role', 'resident');
    $this->getJson('/api/v1/me')->assertJsonPath('data.unit.code', fn (string $code) => str_starts_with($code, 'A-'))
        ->assertJsonPath('data.unit.code', fn (string $code) => $code !== 'A-01-01');
});

it('seeds three sample Complaints by the demo Resident: on their Unit, a Block A lift and a Shared Area', function () {
    $this->seed(DemoSeeder::class);

    $byLocation = demoComplaintsByLocation();
    expect(array_keys($byLocation))->toEqualCanonicalizing(['A-01-01', 'A-LIFT-1', 'POOL']);

    $this->actingAs(demoAccount('resident@demo.test'));
    $mine = collect($this->getJson('/api/v1/complaints')->assertOk()->json('data'));
    expect($mine)->toHaveCount(3)
        ->and($mine->pluck('reference_no')->every(fn ($ref) => preg_match('/^CMP-\d{4}-\d{4,}$/', $ref)))->toBeTrue()
        ->and($mine->pluck('reference_no')->unique())->toHaveCount(3)
        ->and($mine->pluck('status')->unique()->all())->toBe(['reported']);
});

it('gives each sample Complaint a photo the reporter can open', function () {
    $this->seed(DemoSeeder::class);
    $this->actingAs(demoAccount('resident@demo.test'));

    foreach ($this->getJson('/api/v1/complaints')->json('data') as $complaint) {
        $detail = $this->getJson("/api/v1/complaints/{$complaint['id']}")->assertOk()->json('data');
        expect($detail['evidence'])->not->toBeEmpty()
            ->and($detail['timeline'][0])->toMatchArray(['type' => 'status', 'to_status' => 'reported']);

        $this->get($detail['evidence'][0]['url'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }
});

it('has the second demo Resident following the Block A lift Complaint', function () {
    $this->seed(DemoSeeder::class);
    $lift = demoComplaintsByLocation()['A-LIFT-1'];

    $this->actingAs(demoAccount('resident2@demo.test'));
    $following = $this->getJson('/api/v1/complaints?scope=following')->assertOk()->json('data');

    expect(collect($following)->pluck('id')->all())->toBe([$lift['id']])
        ->and($following[0]['following'])->toBeTrue();
});

it('can be re-run without duplicating the sample Complaints, photos or followers', function () {
    $this->seed(DemoSeeder::class);
    $first = demoComplaintsByLocation();

    $this->seed(DemoSeeder::class);

    expect(demoComplaintsByLocation())->toBe($first)
        ->and(Storage::disk('evidence')->allFiles())->toHaveCount(3);
    $this->actingAs(demoAccount('resident2@demo.test'));
    $this->getJson('/api/v1/complaints?scope=following')->assertJsonPath('meta.total', 1);
});

it('can be re-run after the followed lift Complaint is Closed', function () {
    $this->seed(DemoSeeder::class);
    // No endpoint closes a Complaint until Phase 3, so the status is set directly.
    Complaint::findOrFail(demoComplaintsByLocation()['A-LIFT-1']['id'])->update(['status' => ComplaintStatus::Closed]);

    $this->seed(DemoSeeder::class);

    expect(demoComplaintsByLocation())->toHaveCount(3);
});

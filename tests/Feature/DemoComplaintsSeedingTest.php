<?php

use App\Enums\ComplaintStatus;
use App\Enums\Role;
use App\Models\Complaint;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Support\Facades\DB;
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

    $reported = collect(demoComplaintsByLocation())->where('status', 'reported');
    expect($reported->keys()->all())->toEqualCanonicalizing(['A-01-01', 'A-LIFT-1', 'POOL']);

    $this->actingAs(demoAccount('resident@demo.test'));
    $mine = collect($this->getJson('/api/v1/complaints?per_page=100')->assertOk()->json('data'))->where('status', 'reported');
    expect($mine)->toHaveCount(3)
        ->and($mine->pluck('reference_no')->every(fn ($ref) => preg_match('/^CMP-\d{4}-\d{4,}$/', $ref)))->toBeTrue()
        ->and($mine->pluck('reference_no')->unique())->toHaveCount(3);
});

it('gives each sample Complaint a photo the reporter can open', function () {
    $this->seed(DemoSeeder::class);
    $this->actingAs(demoAccount('resident@demo.test'));

    foreach ($this->getJson('/api/v1/complaints?per_page=100')->json('data') as $complaint) {
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
        // 8 report photos, and a Before and an After photo for each of the 3 completed repairs.
        ->and(Storage::disk('evidence')->allFiles())->toHaveCount(14);
    $this->actingAs(demoAccount('resident2@demo.test'));
    $this->getJson('/api/v1/complaints?scope=following')->assertJsonPath('meta.total', 1);
});

it('can be re-run after the followed lift Complaint is Closed', function () {
    $this->seed(DemoSeeder::class);
    // No endpoint closes a Complaint until Phase 3, so the status is set directly.
    Complaint::findOrFail(demoComplaintsByLocation()['A-LIFT-1']['id'])->update(['status' => ComplaintStatus::Closed]);

    $this->seed(DemoSeeder::class);

    expect(demoComplaintsByLocation())->toHaveCount(8);
});

it('seeds a Complaint at each Phase 3 stage, moved there through the workflow', function () {
    $this->seed(DemoSeeder::class);
    $byLocation = demoComplaintsByLocation();

    expect(collect($byLocation)->map(fn (array $complaint) => $complaint['status'])->only(['A-01-CORRIDOR', 'A-TANK', 'A-LOBBY', 'A-STAIR', 'CARPARK'])->all())->toEqual([
        'A-01-CORRIDOR' => 'under_review', 'A-TANK' => 'in_progress', 'A-LOBBY' => 'pending_verification', 'A-STAIR' => 'resolved', 'CARPARK' => 'closed',
    ]);

    $closed = $this->getJson("/api/v1/complaints/{$byLocation['CARPARK']['id']}")->assertOk()->json('data');
    expect(collect($closed['timeline'])->where('type', 'status')->pluck('to_status')->all())
        ->toBe(['reported', 'under_review', 'assigned', 'in_progress', 'pending_verification', 'resolved', 'closed'])
        ->and($closed['rating'])->toMatchArray(['score' => 5])
        ->and($closed['maintenance_records'][0])->toMatchArray(['attempt' => 1, 'verification_outcome' => 'approved', 'expense_total' => '45.00'])
        ->and(collect($closed['evidence'])->pluck('type')->all())->toBe(['report_photo', 'before_photo', 'after_photo']);
});

it('shows the demo Technician their tasks and the demo Resident a repair to confirm', function () {
    $this->seed(DemoSeeder::class);
    $byLocation = demoComplaintsByLocation();

    $this->actingAs(demoAccount('technician@demo.test'));
    $tasks = $this->getJson('/api/v1/technician/tasks')->assertOk()->json('data');
    expect(array_column($tasks['in_progress'], 'id'))->toBe([$byLocation['A-TANK']['id']])
        ->and(array_column($tasks['completed'], 'id'))->toEqualCanonicalizing([$byLocation['A-LOBBY']['id'], $byLocation['A-STAIR']['id'], $byLocation['CARPARK']['id']]);

    $this->actingAs(demoAccount('resident@demo.test'));
    $this->getJson("/api/v1/complaints/{$byLocation['A-STAIR']['id']}")->assertOk()->assertJsonPath('data.can_confirm_resolution', true);
});

it('adds nothing when re-run after the workflow stages are seeded', function () {
    $this->seed(DemoSeeder::class);
    $counts = fn () => [
        DB::table('complaints')->count(), DB::table('complaint_status_history')->count(), DB::table('complaint_assignments')->count(),
        DB::table('maintenance_records')->count(), DB::table('ratings')->count(), DB::table('complaint_comments')->count(), DB::table('audit_logs')->count(),
    ];
    $first = $counts();

    $this->seed(DemoSeeder::class);

    expect($counts())->toBe($first);
});

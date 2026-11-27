<?php

namespace Database\Seeders;

use App\Enums\AffectedUnits;
use App\Enums\Urgency;
use App\Models\Category;
use App\Models\Complaint;
use App\Models\Location;
use App\Models\User;
use App\Services\Complaints\ComplaintIntake;
use App\Services\Complaints\Following;
use BackedEnum;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * Three sample Complaints for the demo, each with a photo, submitted through ComplaintIntake like a
 * real one: on the demo Resident's Unit, on a Block A lift (followed by the second demo Resident)
 * and at a Shared Area. Each uses a fixed Idempotency-Key, so a re-run returns the Complaint it
 * made the first time instead of filing it again. Called by DemoSeeder after the accounts exist.
 */
class DemoComplaintSeeder extends Seeder
{
    public function run(ComplaintIntake $intake, Following $following): void
    {
        $reporter = User::where('email', 'resident@demo.test')->firstOrFail();
        $neighbour = User::where('email', 'resident2@demo.test')->firstOrFail();

        $this->submit($intake, $reporter, 'demo-sample-unit', 'A-01-01', 'plumbing', [
            'title' => 'Kitchen sink leaking under the cabinet',
            'description' => 'Water drips from the pipe joint under the kitchen sink whenever the tap runs. The cabinet floor is soaked.',
            'urgency' => Urgency::Medium, 'safety_risk' => false, 'affected_units' => AffectedUnits::One, 'essential_service_down' => false,
        ], [37, 99, 235]);

        $lift = $this->submit($intake, $reporter, 'demo-sample-lift', 'A-LIFT-1', 'lift', [
            'title' => 'Lift 1 stuck on the ground floor',
            'description' => 'Block A lift 1 has not moved since this morning. The doors stay shut and the call button does nothing.',
            'urgency' => Urgency::High, 'safety_risk' => false, 'affected_units' => AffectedUnits::Many, 'essential_service_down' => true,
        ], [220, 38, 38]);
        // Once the lift Complaint is no longer open it cannot be followed; a re-run then leaves it as it is.
        if ($lift->status->isOpen()) {
            $following->follow($neighbour, $lift->id);
        }

        $this->submit($intake, $reporter, 'demo-sample-pool', 'POOL', 'facilities', [
            'title' => 'Swimming pool water is cloudy and green',
            'description' => 'The pool water turned green over the weekend and the filter seems to be off. Nobody can swim in it.',
            'urgency' => Urgency::Low, 'safety_risk' => false, 'affected_units' => AffectedUnits::Many, 'essential_service_down' => false,
        ], [22, 163, 74]);
    }

    /**
     * @param  array<string, mixed>  $answers
     * @param  array{int, int, int}  $colour  the sample photo's colour, so the three are easy to tell apart
     */
    private function submit(ComplaintIntake $intake, User $reporter, string $key, string $locationCode, string $categoryKey, array $answers, array $colour): Complaint
    {
        $answers = [
            ...array_map(fn ($value) => $value instanceof BackedEnum ? $value->value : $value, $answers),
            'location_id' => Location::where('code', $locationCode)->firstOrFail()->id,
            'category_id' => Category::where('key', $categoryKey)->firstOrFail()->id,
        ];

        return $intake->submit($reporter, $answers, [$this->samplePhoto($answers['title'], $colour)], $key);
    }

    /** A plain coloured 1200×900 JPEG captioned with the Complaint's title: a stand-in for a phone photo. */
    private function samplePhoto(string $caption, array $colour): UploadedFile
    {
        $image = imagecreatetruecolor(1200, 900);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$colour));

        // GD's built-in font is tiny: write the caption small, then scale it up onto the photo.
        $label = imagecreatetruecolor(300, 30);
        imagefill($label, 0, 0, imagecolorallocate($label, ...$colour));
        imagestring($label, 5, 6, 7, 'SAMPLE PHOTO', imagecolorallocate($label, 255, 255, 255));
        imagecopyresized($image, $label, 0, 60, 0, 0, 1200, 120, 300, 30);
        imagestring($image, 5, 40, 820, $caption, imagecolorallocate($image, 255, 255, 255));

        $path = tempnam(sys_get_temp_dir(), 'demo-photo');
        imagejpeg($image, $path, 85);

        return new UploadedFile($path, 'sample.jpg', 'image/jpeg', null, test: true);
    }
}

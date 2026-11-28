<?php

namespace Database\Seeders\Concerns;

use App\Models\Category;
use App\Models\Complaint;
use App\Models\Location;
use App\Models\User;
use App\Services\Complaints\ComplaintIntake;
use BackedEnum;
use Illuminate\Http\UploadedFile;

/** Demo Complaints submitted through ComplaintIntake like real ones, each with a generated sample photo. */
trait SubmitsSampleComplaints
{
    /**
     * @param  array<string, mixed>  $answers
     * @param  array{int, int, int}  $colour  the sample photo's colour, so the samples are easy to tell apart
     */
    protected function submit(ComplaintIntake $intake, User $reporter, string $key, string $locationCode, string $categoryKey, array $answers, array $colour): Complaint
    {
        $answers = [
            ...array_map(fn ($value) => $value instanceof BackedEnum ? $value->value : $value, $answers),
            'location_id' => Location::where('code', $locationCode)->firstOrFail()->id,
            'category_id' => Category::where('key', $categoryKey)->firstOrFail()->id,
        ];

        $photo = $this->samplePhoto($answers['title'], $colour);
        try {
            return $intake->submit($reporter, $answers, [$photo], $key);
        } finally {
            @unlink($photo->getRealPath());   // the intake has stored its own re-encoded copy
        }
    }

    /** A plain coloured 1200×900 JPEG captioned with the Complaint's title: a stand-in for a phone photo. */
    protected function samplePhoto(string $caption, array $colour, string $badge = 'SAMPLE PHOTO'): UploadedFile
    {
        $image = imagecreatetruecolor(1200, 900);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$colour));

        // GD's built-in font is tiny: write the caption small, then scale it up onto the photo.
        $label = imagecreatetruecolor(300, 30);
        imagefill($label, 0, 0, imagecolorallocate($label, ...$colour));
        imagestring($label, 5, 6, 7, $badge, imagecolorallocate($label, 255, 255, 255));
        imagecopyresized($image, $label, 0, 60, 0, 0, 1200, 120, 300, 30);
        imagestring($image, 5, 40, 820, $caption, imagecolorallocate($image, 255, 255, 255));

        $path = tempnam(sys_get_temp_dir(), 'demo-photo');
        imagejpeg($image, $path, 85);

        return new UploadedFile($path, 'sample.jpg', 'image/jpeg', null, test: true);
    }
}

<?php

use App\Enums\Role;
use App\Models\Complaint;
use App\Models\Evidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Storage::fake('evidence');
    $this->places = complaintsSetup();   // Rina signed in
});

/** A submission at the Block A lift with these photos attached. */
function submitWithPhotos(array $photos)
{
    $body = complaintBody(['location_id' => test()->places['lift']->id, 'photos' => $photos]);

    return test()->post('/api/v1/complaints', $body, ['Accept' => 'application/json']);
}

/** The bytes GET /attachments/{id} serves. */
function evidenceBytes(int $id): string
{
    return test()->get("/api/v1/attachments/{$id}")->assertOk()->assertHeader('Content-Type', 'image/jpeg')->streamedContent();
}

it('stores up to five photos as evidence on the new Complaint', function () {
    $photos = array_map(fn (int $n) => UploadedFile::fake()->image("photo-{$n}.png", 800, 600), range(1, 5));

    $evidence = submitWithPhotos($photos)->assertCreated()->json('data.evidence');

    expect($evidence)->toHaveCount(5)
        ->and($evidence[0])->toMatchArray(['type' => 'report_photo', 'mime' => 'image/jpeg', 'width' => 800, 'height' => 600]);
    expect(getimagesizefromstring(evidenceBytes($evidence[0]['id']))[2])->toBe(IMAGETYPE_JPEG);
    expect(Storage::disk('evidence')->allFiles())->toHaveCount(5);
});

/** A real JPEG carrying an EXIF block with a GPS marker, as a phone camera writes one. */
function photoWithGps(): UploadedFile
{
    $canvas = imagecreatetruecolor(400, 300);
    ob_start();
    imagejpeg($canvas);
    $jpeg = ob_get_clean();
    $exif = "Exif\0\0II*\0".str_repeat("\0", 8).'GPSLatitude 3.1390 N, GPSLongitude 101.6869 E';
    $withExif = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);

    $path = tempnam(sys_get_temp_dir(), 'gps');
    file_put_contents($path, $withExif);

    return new UploadedFile($path, 'IMG_0001.jpg', 'image/jpeg', null, true);
}

function expectNothingSaved(): void
{
    expect(Complaint::count())->toBe(0)
        ->and(Storage::disk('evidence')->allFiles())->toBe([]);
}

it('rejects a sixth photo and saves nothing', function () {
    $photos = array_map(fn (int $n) => UploadedFile::fake()->image("photo-{$n}.jpg"), range(1, 6));

    submitWithPhotos($photos)->assertUnprocessable()->assertJsonValidationErrors(['photos' => 'Attach at most five photos.']);
    expectNothingSaved();
});

it('rejects anything that is not a real JPEG, PNG or WebP image and saves nothing', function (Closure $file) {
    submitWithPhotos([UploadedFile::fake()->image('good.jpg'), $file()])
        ->assertUnprocessable()->assertJsonValidationErrors('photos.1');
    expectNothingSaved();
})->with([
    'a text file named .jpg' => fn () => UploadedFile::fake()->createWithContent('notes.jpg', 'just some text'),
    'a GIF' => fn () => UploadedFile::fake()->image('anim.gif'),
    'a JPEG cut off after its header' => fn () => UploadedFile::fake()->createWithContent(
        'broken.jpg', substr(UploadedFile::fake()->image('whole.jpg', 400, 300)->getContent(), 0, 200),
    ),
    // Its header reads as a valid PNG; only decoding the pixel data shows it is broken.
    'a PNG cut off halfway through its pixels' => fn () => UploadedFile::fake()->createWithContent('half.png', halfAPng()),
]);

function halfAPng(): string
{
    $canvas = imagecreatetruecolor(400, 300);
    mt_srand(626);
    for ($i = 0; $i < 3000; $i++) {
        imagesetpixel($canvas, mt_rand(0, 399), mt_rand(0, 299), mt_rand(0, 0xFFFFFF));
    }
    ob_start();
    imagepng($canvas);
    $png = ob_get_clean();

    return substr($png, 0, intdiv(strlen($png), 2));
}

it('saves no photos when another answer is invalid', function (Closure $location) {
    $body = complaintBody(['location_id' => $location($this->places), 'photos' => [UploadedFile::fake()->image('p.jpg')]]);

    $this->post('/api/v1/complaints', $body, ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('location_id');
    expectNothingSaved();
})->with([
    'a Location that does not exist' => fn () => 999999,
    'someone else\'s Unit' => fn (array $places) => $places['neighbour']->id,
]);

it('rejects photos sent as named fields instead of a list', function () {
    submitWithPhotos(['front' => UploadedFile::fake()->image('p.jpg')])
        ->assertUnprocessable()->assertJsonValidationErrors('photos');
    expectNothingSaved();
});

it('rejects a photo over 10 MB and saves nothing', function () {
    submitWithPhotos([UploadedFile::fake()->image('huge.jpg')->size(10 * 1024 + 1)])
        ->assertUnprocessable()->assertJsonValidationErrors(['photos.0' => 'Each photo must be 10 MB or smaller.']);
    expectNothingSaved();
});

it('shrinks a large photo to 1600 px on its longest edge', function () {
    $evidence = submitWithPhotos([UploadedFile::fake()->image('wide.png', 3200, 2000)])->assertCreated()->json('data.evidence.0');

    expect($evidence)->toMatchArray(['width' => 1600, 'height' => 1000]);
    $stored = getimagesizefromstring(evidenceBytes($evidence['id']));
    expect([$stored[0], $stored[1]])->toBe([1600, 1000]);
});

it('keeps a small photo at its own size', function () {
    $evidence = submitWithPhotos([UploadedFile::fake()->image('small.webp', 640, 480)])->assertCreated()->json('data.evidence.0');

    expect($evidence)->toMatchArray(['width' => 640, 'height' => 480, 'mime' => 'image/jpeg']);
});

it('strips EXIF and GPS metadata from stored photos', function () {
    $upload = photoWithGps();
    expect($upload->getContent())->toContain('GPSLatitude');   // the test photo really carries it

    $id = submitWithPhotos([$upload])->assertCreated()->json('data.evidence.0.id');

    expect(evidenceBytes($id))->not->toContain('Exif')->not->toContain('GPSLatitude');
});

it('stores photos under a random name, not the uploaded one', function () {
    submitWithPhotos([photoWithGps()])->assertCreated();

    $files = Storage::disk('evidence')->allFiles();
    expect($files)->toHaveCount(1)
        ->and($files[0])->toMatch('/^[A-Za-z0-9]{40}\.jpg$/')
        ->not->toContain('IMG_0001');
});

it('records the size and uploader of each photo on the Complaint detail', function () {
    $evidence = submitWithPhotos([UploadedFile::fake()->image('p.jpg', 800, 600)])->assertCreated()->json('data.evidence.0');

    expect($evidence['uploaded_by'])->toBe(['id' => $this->places['rina']->id, 'name' => 'Rina Resident'])
        ->and($evidence['size_bytes'])->toBe(strlen(evidenceBytes($evidence['id'])));
});

it('deletes photos already written when saving the Complaint fails part-way', function () {
    $this->withoutExceptionHandling();
    $saved = 0;
    // Stand-in for any failure after the first file is on disk (a full disk, a lost connection).
    Evidence::creating(function () use (&$saved) {
        if (++$saved === 2) {
            throw new RuntimeException('storage failed');
        }
    });

    expect(fn () => submitWithPhotos([UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]))
        ->toThrow(RuntimeException::class, 'storage failed');
    expectNothingSaved();
});

it('serves photos to the reporter, Managers and Administrators', function () {
    $evidence = submitWithPhotos([UploadedFile::fake()->image('p.jpg')])->json('data.evidence.0');

    evidenceBytes($evidence['id']);
    foreach ([Role::Manager, Role::Administrator] as $role) {
        $this->actingAs(account($role));
        evidenceBytes($evidence['id']);
        $this->getJson('/api/v1/complaints/'.Complaint::value('id'))->assertJsonCount(1, 'data.evidence');
    }
});

it('answers not found to anyone else asking for a photo, Followers included', function () {
    ['neighbour' => $neighbour] = $this->places;
    $complaint = submitWithPhotos([UploadedFile::fake()->image('p.jpg')])->json('data');
    $photoId = $complaint['evidence'][0]['id'];

    $follower = account(Role::Resident, ['unit_id' => $neighbour->id]);
    $this->actingAs($follower)->postJson("/api/v1/complaints/{$complaint['id']}/follow")->assertNoContent();
    $this->get("/api/v1/attachments/{$photoId}")->assertNotFound();
    $this->getJson("/api/v1/complaints/{$complaint['id']}")->assertJsonMissingPath('data.evidence');

    $this->actingAs(account(Role::Resident, ['unit_id' => $neighbour->id]))->get("/api/v1/attachments/{$photoId}")->assertNotFound();
    $this->actingAs(account(Role::Technician))->get("/api/v1/attachments/{$photoId}")->assertNotFound();
    $this->actingAs(account(Role::Manager))->get('/api/v1/attachments/999999')->assertNotFound();
});

it('offers no way to delete evidence or add photos after submission', function () {
    $complaint = submitWithPhotos([UploadedFile::fake()->image('p.jpg')])->json('data');
    $this->actingAs(account(Role::Manager));

    expect($this->deleteJson("/api/v1/attachments/{$complaint['evidence'][0]['id']}")->status())->toBeIn([404, 405]);
    $this->actingAs($this->places['rina']);
    expect($this->postJson("/api/v1/complaints/{$complaint['id']}/attachments")->status())->toBeIn([404, 405]);
    expect(Storage::disk('evidence')->allFiles())->toHaveCount(1);
});

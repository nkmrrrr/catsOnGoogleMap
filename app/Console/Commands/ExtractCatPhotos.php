<?php

namespace App\Console\Commands;

use Google\Cloud\Vision\V1\Client\ImageAnnotatorClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Http, Storage};
use Intervention\Image\ImageManagerStatic as Image;

class ExtractCatPhotos extends Command
{
    /** @var string コマンド実行名 */
    protected $signature = 'cat:extract {place_id} {--limit=50}';

    /** @var string 説明 (php artisan list に出る) */
    protected $description = 'Download photos from Google Maps and crop cats';

    public function handle(): int
    {
        $placeId = $this->argument('place_id');
        $limit   = (int) $this->option('limit');
        $mapsKey = config('services.google.maps_key');

        $vision = new ImageAnnotatorClient();
        $photos = Http::get(
            'https://maps.googleapis.com/maps/api/place/details/json',
            ['place_id' => $placeId, 'fields' => 'photo', 'key' => $mapsKey]
        )->json('result.photos', []);

        if (empty($photos)) {
            $this->error('No photos found.');
            return 1;
        }

        foreach (array_slice($photos, 0, $limit) as $photo) {
            $imgData = Http::get(
                'https://maps.googleapis.com/maps/api/place/photo',
                ['photoreference' => $photo['photo_reference'], 'maxwidth' => 1600, 'key' => $mapsKey]
            )->body();

            foreach ($vision->objectLocalization($imgData)->getLocalizedObjectAnnotations() as $obj) {
                if (strtolower($obj->getName()) !== 'cat') continue;

                $v = $obj->getBoundingPoly()->getNormalizedVertices();
                if (count($v) < 4) continue;

                $im = Image::make($imgData);
                $w  = $im->width();
                $h = $im->height();
                $crop = $im->crop(
                    ($v[2]->getX() - $v[0]->getX()) * $w,
                    ($v[2]->getY() - $v[0]->getY()) * $h,
                    $v[0]->getX() * $w,
                    $v[0]->getY() * $h
                );

                $path = 'cats/' . $placeId . '_' . uniqid() . '.jpg';
                Storage::disk('public')->put($path, (string) $crop->encode('jpg', 90));
                $this->info("Saved: $path");
            }
        }

        $vision->close();
        $this->info('Finished!');
        return 0;
    }
}

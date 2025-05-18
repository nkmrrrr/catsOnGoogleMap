<?php

namespace App\Console\Commands;

use Google\Cloud\Vision\V1\Client\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\AnnotateImageRequest;
use Google\Cloud\Vision\V1\BatchAnnotateImagesRequest;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\Image as VisionImage;
use Google\Cloud\Vision\V1\Feature\Type;
use Google\Cloud\Vision\V1\ImageSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Http, Storage};
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class ExtractCatPhotos extends Command
{
    /** @var string コマンド実行名 */
    protected $signature = 'cat:extract {--place_id=} {--limit=50}';

    /** @var string 説明 (php artisan list に出る) */
    protected $description = 'Download photos from Google Maps and crop cats';

    public function handle(): int
    {
        $placeId = $this->option('place_id');
        $limit   = (int) $this->option('limit');
        $mapsKey = config('services.google.maps_key');

        $vision = new ImageAnnotatorClient();
        $photos = [];

        if (empty($placeId)) {
            $this->info('No place_id provided. Searching for random cat photos around the world...');
            $photos = $this->getRandomCatPhotos($mapsKey, $limit);
        } else {
            $this->info("Searching for cat photos at place_id: $placeId");
            $photos = Http::get(
                'https://maps.googleapis.com/maps/api/place/details/json',
                ['place_id' => $placeId, 'fields' => 'photo', 'key' => $mapsKey]
            )->json('result.photos', []);
        }

        if (empty($photos)) {
            $this->error('No photos found.');
            return 1;
        }

        // プレースIDが指定されていない場合、ランダム検索したプレースIDを使用
        $currentPlaceId = $placeId ?: ('random_' . time());

        foreach (array_slice($photos, 0, $limit) as $photo) {
            if (!isset($photo['photo_reference'])) {
                $this->warn('Photo reference missing, skipping...');
                continue;
            }

            $imgData = Http::get(
                'https://maps.googleapis.com/maps/api/place/photo',
                ['photoreference' => $photo['photo_reference'], 'maxwidth' => 1600, 'key' => $mapsKey]
            )->body();

            // Vision APIリクエストの作成
            $visionImage = new VisionImage();
            $visionImage->setContent($imgData);

            $feature = new Feature();
            $feature->setType(Type::OBJECT_LOCALIZATION);

            $imageRequest = new AnnotateImageRequest();
            $imageRequest->setImage($visionImage);
            $imageRequest->setFeatures([$feature]);

            // バッチリクエストを作成
            $batchRequest = new BatchAnnotateImagesRequest();
            $batchRequest->setRequests([$imageRequest]);

            // 画像アノテーションの実行
            $response = $vision->batchAnnotateImages($batchRequest);
            $annotationResponse = $response->getResponses()[0];
            $objects = $annotationResponse->getLocalizedObjectAnnotations();

            foreach ($objects as $obj) {
                if (strtolower($obj->getName()) !== 'cat') continue;

                $v = $obj->getBoundingPoly()->getNormalizedVertices();
                if (count($v) < 4) continue;

                // Intervention Image 3.x用の設定
                $manager = new ImageManager(new Driver());
                $im = $manager->read($imgData);
                $w  = $im->width();
                $h = $im->height();

                // 座標計算
                $cropWidth = ($v[2]->getX() - $v[0]->getX()) * $w;
                $cropHeight = ($v[2]->getY() - $v[0]->getY()) * $h;
                $cropX = $v[0]->getX() * $w;
                $cropY = $v[0]->getY() * $h;

                // Intervention Image 3.x用のcropsの呼び方
                $crop = $im->crop((int)$cropWidth, (int)$cropHeight, (int)$cropX, (int)$cropY);

                $path = 'cats/' . $currentPlaceId . '_' . uniqid() . '.jpg';
                // Intervention Image 3.x用のエンコード方法
                $encodedImage = $crop->toJpeg(90);
                Storage::disk('public')->put($path, $encodedImage);
                $this->info("Saved: $path");
            }
        }

        $vision->close();
        $this->info('Finished!');
        return 0;
    }

    /**
     * 世界中からランダムに猫の写真を検索して取得
     *
     * @param string $apiKey Google Maps APIキー
     * @param int $limit 取得する写真の最大数
     * @return array 写真のリスト
     */
    protected function getRandomCatPhotos(string $apiKey, int $limit): array
    {
        // 猫に関連する検索キーワード
        $catKeywords = [
            'cat cafe',
            'cat park',
            'cat shelter',
            'cat sanctuary',
            'cat rescue',
            'pet store',
            'animal shelter',
            'veterinary clinic',
            'cat statue',
            'cat museum'
        ];

        // ランダムに都市を選ぶ（世界中の主要な都市）
        $cities = [
            'Tokyo',
            'New York',
            'London',
            'Paris',
            'Sydney',
            'Berlin',
            'Rome',
            'Madrid',
            'Bangkok',
            'Singapore',
            'Istanbul',
            'Cairo',
            'Rio de Janeiro',
            'Mexico City',
            'Cape Town',
            'Moscow',
            'Seoul',
            'Beijing',
            'Toronto'
        ];

        $randomCity = $cities[array_rand($cities)];
        $randomKeyword = $catKeywords[array_rand($catKeywords)];

        $this->info("Searching for '{$randomKeyword}' in {$randomCity}");

        // 検索キーワードと都市名で場所を検索
        $searchResponse = Http::get(
            'https://maps.googleapis.com/maps/api/place/textsearch/json',
            [
                'query' => $randomKeyword . ' in ' . $randomCity,
                'key' => $apiKey
            ]
        )->json('results', []);

        if (empty($searchResponse)) {
            $this->warn("No places found for '{$randomKeyword}' in {$randomCity}. Trying another search...");
            return $this->getRandomCatPhotos($apiKey, $limit); // 再帰的に別の検索を試みる
        }

        // ランダムに場所を選択
        $randomPlace = $searchResponse[array_rand($searchResponse)];
        $randomPlaceId = $randomPlace['place_id'] ?? '';
        $randomPlaceName = $randomPlace['name'] ?? 'Unknown place';

        if (empty($randomPlaceId)) {
            $this->warn("No valid place found. Trying another search...");
            return $this->getRandomCatPhotos($apiKey, $limit);
        }

        $this->info("Selected place: {$randomPlaceName} (ID: {$randomPlaceId})");

        // 選ばれた場所の写真を取得
        $placeDetails = Http::get(
            'https://maps.googleapis.com/maps/api/place/details/json',
            ['place_id' => $randomPlaceId, 'fields' => 'photo', 'key' => $apiKey]
        )->json('result.photos', []);

        if (empty($placeDetails)) {
            $this->warn("No photos found at selected place. Trying another place...");
            return $this->getRandomCatPhotos($apiKey, $limit);
        }

        return $placeDetails;
    }
}

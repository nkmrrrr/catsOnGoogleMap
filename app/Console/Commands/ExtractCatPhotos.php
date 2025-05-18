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
        try {
            $placeId = $this->option('place_id');
            $limit   = (int) $this->option('limit');
            $mapsKey = $this->getMapsApiKey();

            // Vision APIクライアントの作成
            $vision = $this->createVisionClient();
            $photos = [];

            // 写真の取得（place_idの指定有無で分岐）
            if (empty($placeId)) {
                $this->info('No place_id provided. Searching for random cat photos around the world...');
                $photos = $this->getRandomCatPhotos($mapsKey, $limit);
            } else {
                $this->info("Searching for cat photos at place_id: $placeId");
                $photos = $this->getPhotosForPlace($placeId, $mapsKey);
            }

            if (empty($photos)) {
                $this->error('No photos found.');
                return 1;
            }

            // プレースIDが指定されていない場合、ランダム検索したプレースIDを使用
            $currentPlaceId = $placeId ?: ('random_' . time());

            // 写真の処理
            $processedCount = $this->processPhotos($photos, $limit, $mapsKey, $vision, $currentPlaceId);

            $vision->close();
            $this->info("Finished! Processed $processedCount photos.");
            return 0;
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }

    /**
     * 写真を処理して猫を検出・切り抜く
     * 
     * @param array $photos 写真データの配列
     * @param int $limit 処理する最大枚数
     * @param string $mapsKey Google Maps APIキー
     * @param ImageAnnotatorClient $vision Vision APIクライアント
     * @param string $currentPlaceId 現在の場所ID
     * @return int 処理した写真の枚数
     */
    protected function processPhotos(array $photos, int $limit, string $mapsKey, ImageAnnotatorClient $vision, string $currentPlaceId): int
    {
        $processedCount = 0;

        foreach (array_slice($photos, 0, $limit) as $photo) {
            if (!isset($photo['photo_reference'])) {
                $this->warn('Photo reference missing, skipping...');
                continue;
            }

            try {
                $imgData = $this->downloadPhoto($photo['photo_reference'], $mapsKey);

                // 猫オブジェクトの検出
                $objects = $this->detectObjects($imgData, $vision);

                // 猫が見つかったかどうかのフラグ
                $catFound = false;

                // 検出された猫を処理
                foreach ($objects as $obj) {
                    if (strtolower($obj->getName()) !== 'cat') continue;

                    // 猫を検出＆切り抜き
                    if ($this->cropAndSaveCat($obj, $imgData, $currentPlaceId)) {
                        $catFound = true;
                        $processedCount++;
                    }
                }

                if (!$catFound) {
                    $this->info('No cats detected in this photo.');
                }
            } catch (\Exception $e) {
                $this->warn('Error processing photo: ' . $e->getMessage());
            }
        }

        return $processedCount;
    }

    /**
     * 画像内のオブジェクトを検出
     * 
     * @param string $imgData 画像データ
     * @param ImageAnnotatorClient $vision Vision APIクライアント
     * @return iterable 検出されたオブジェクト
     */
    protected function detectObjects(string $imgData, ImageAnnotatorClient $vision): iterable
    {
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
        // RepeatedFieldをそのまま返す（iterableとして扱う）
        return $annotationResponse->getLocalizedObjectAnnotations();
    }

    /**
     * 検出された猫を切り取って保存
     * 
     * @param object $obj 検出されたオブジェクト
     * @param string $imgData 元の画像データ
     * @param string $currentPlaceId 現在の場所ID
     * @return bool 保存に成功したかどうか
     */
    protected function cropAndSaveCat(object $obj, string $imgData, string $currentPlaceId): bool
    {
        $v = $obj->getBoundingPoly()->getNormalizedVertices();
        if (count($v) < 4) return false;

        try {
            // Intervention Image 3.x用の設定
            $manager = new ImageManager(new Driver());
            $im = $manager->read($imgData);
            $w = $im->width();
            $h = $im->height();

            // 座標計算
            $cropWidth = ($v[2]->getX() - $v[0]->getX()) * $w;
            $cropHeight = ($v[2]->getY() - $v[0]->getY()) * $h;
            $cropX = $v[0]->getX() * $w;
            $cropY = $v[0]->getY() * $h;

            // サイズチェック（小さすぎる検出は無視）
            if ($cropWidth < 20 || $cropHeight < 20) {
                $this->warn('Cat detection too small, skipping...');
                return false;
            }

            // Intervention Image 3.x用のcropsの呼び方
            $crop = $im->crop((int)$cropWidth, (int)$cropHeight, (int)$cropX, (int)$cropY);

            // ファイル名の生成と保存
            $path = $this->saveCatImage($crop, $currentPlaceId);
            return true;
        } catch (\Exception $e) {
            $this->warn('Failed to crop cat: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 世界中からランダムに猫の写真を検索して取得
     *
     * @param string $apiKey Google Maps APIキー
     * @param int $limit 取得する写真の最大数
     * @return array 写真のリスト
     */
    /**
     * Maps APIキーを取得
     * 
     * @return string APIキー
     * @throws \Exception キーが設定されていない場合
     */
    protected function getMapsApiKey(): string
    {
        $key = config('services.google.maps_key');
        if (empty($key)) {
            throw new \Exception('Google Maps API key is not configured.');
        }
        return $key;
    }

    /**
     * 猫画像を保存
     * 
     * @param object $crop 切り抜いた画像
     * @param string $placeId 場所ID
     * @return string 保存パス
     */
    protected function saveCatImage(object $crop, string $placeId): string
    {
        $path = 'cats/' . $placeId . '_' . uniqid() . '.jpg';
        // Intervention Image 3.x用のエンコード方法
        $encodedImage = $crop->toJpeg(90);
        Storage::disk('public')->put($path, $encodedImage);
        $this->info("Saved: $path");
        return $path;
    }

    /**
     * Vision APIクライアントを作成
     * 
     * @return ImageAnnotatorClient
     */
    protected function createVisionClient(): ImageAnnotatorClient
    {
        return new ImageAnnotatorClient();
    }

    /**
     * 特定の場所の写真を取得
     * 
     * @param string $placeId 場所ID
     * @param string $apiKey Google Maps APIキー
     * @return array 写真のリスト
     */
    protected function getPhotosForPlace(string $placeId, string $apiKey): array
    {
        return Http::get(
            'https://maps.googleapis.com/maps/api/place/details/json',
            ['place_id' => $placeId, 'fields' => 'photo', 'key' => $apiKey]
        )->json('result.photos', []);
    }

    /**
     * 写真をダウンロード
     * 
     * @param string $photoReference 写真のリファレンスID
     * @param string $apiKey Google Maps APIキー
     * @return string 画像データ
     */
    protected function downloadPhoto(string $photoReference, string $apiKey): string
    {
        return Http::get(
            'https://maps.googleapis.com/maps/api/place/photo',
            ['photoreference' => $photoReference, 'maxwidth' => 1600, 'key' => $apiKey]
        )->body();
    }

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

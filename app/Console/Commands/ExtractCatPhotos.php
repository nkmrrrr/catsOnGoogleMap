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
    protected $description = 'Download photos from Google Maps locations';

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
     * 写真を処理して猫を検出・保存
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

                // 猫の検出を行う
                $objects = $this->detectObjects($imgData, $vision);
                $containsCat = $this->containsCat($objects);

                if ($containsCat) {
                    // 猫が含まれている場合は写真をそのまま保存
                    $path = $this->saveImage($imgData, $currentPlaceId);
                    $this->info("Cat detected! Saved full photo: $path");
                    $processedCount++;
                } else {
                    $this->info("No cats detected in photo, skipping...");
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
     * @return iterable 検出結果
     */
    protected function detectObjects(string $imgData, ImageAnnotatorClient $vision): iterable
    {
        try {
            // 検出リクエストの作成
            $image = new VisionImage();
            $image->setContent($imgData);

            // オブジェクト検出の特徴を指定
            $feature = new Feature();
            $feature->setType(Type::OBJECT_LOCALIZATION);
            $feature->setMaxResults(50);

            // アノテーションリクエストの作成
            $request = new AnnotateImageRequest();
            $request->setImage($image);
            $request->setFeatures([$feature]);

            // バッチリクエストの作成と送信
            $batchRequest = new BatchAnnotateImagesRequest();
            $batchRequest->setRequests([$request]);

            // リクエストを送信して結果を取得
            $response = $vision->batchAnnotateImages($batchRequest);
            $results = $response->getResponses()[0];

            return $results->getLocalizedObjectAnnotations();
        } catch (\Exception $e) {
            $this->warn('Error detecting objects: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 検出されたオブジェクトの中に猫が含まれているかを判定
     * 
     * @param iterable $objects 検出されたオブジェクト
     * @return bool 猫が含まれているかどうか
     */
    protected function containsCat(iterable $objects): bool
    {
        foreach ($objects as $object) {
            $name = $object->getName();
            // 'Cat'というラベルがあれば猫が含まれていると判断
            if (strcasecmp($name, 'Cat') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * 画像を保存
     * 
     * @param string $imgData 画像データ
     * @param string $placeId 場所ID
     * @return string 保存パス
     */
    protected function saveImage(string $imgData, string $placeId): string
    {
        try {
            // ファイルパスを生成
            $path = 'cats/' . $placeId . '_' . uniqid() . '.jpg';

            // 画像データをそのまま保存
            Storage::disk('public')->put($path, $imgData);

            return $path;
        } catch (\Exception $e) {
            $this->warn('Failed to save image: ' . $e->getMessage());
            throw $e;
        }
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
        // 検索キーワード（猫だけでなく、一般的にも興味深い場所も含む）
        $locationKeywords = [
            'cat cafe',
            'pet store',
            'animal shelter',
            'park',
            'famous landmark',
            'tourist attraction',
            'scenic view',
            'museum',
            'waterfall',
            'historic site'
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
        $randomKeyword = $locationKeywords[array_rand($locationKeywords)];

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
}

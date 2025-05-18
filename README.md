# Google Maps 猫写真検索アプリ

Google Maps から取得した写真から猫を検出し、コレクションとして表示する Laravel アプリケーションです。

## 機能概要

- Google Maps から様々な場所の写真を自動収集
- Google Cloud Vision API を使用して写真内の猫を検出
- 猫が写っている写真のみを保存（クロップなし、全体画像）
- 保存された写真を Web インターフェースでギャラリー表示

## 技術スタック

- Laravel (PHP フレームワーク)
- Google Maps Place API (写真収集用)
- Google Cloud Vision API (猫検出用)
- Tailwind CSS (UI 用)

## インストール方法

### 前提条件

- PHP 8.0 以上
- Composer
- Google Cloud Platform のアカウントと API キー

### 手順

1. リポジトリをクローンする
```
git clone <リポジトリURL>
cd catsOnGooglemap
```

2. 依存パッケージをインストール
```
composer install
```

3. 環境変数の設定
`.env` ファイルを作成し、以下の API キーを設定します：
```
GOOGLE_MAPS_API_KEY=your_google_maps_api_key
GOOGLE_APPLICATION_CREDENTIALS=/path/to/your/service-account-credentials.json
```

4. ストレージのシンボリックリンクを作成
```
php artisan storage:link
```

5. データベースのセットアップ（必要な場合）
```
php artisan migrate
```

6. アプリケーションを起動
```
php artisan serve
```

## 使い方

1. ブラウザで `http://localhost:8000` にアクセスします。
2. 「新しい猫写真を検索」ボタンをクリックして、写真の収集を開始します。
3. 収集された猫の写真がギャラリーに表示されます。

## コマンドライン機能

特定の場所の写真を検索するには、コマンドラインから以下を実行します：

```
php artisan cat:extract --place_id=<GoogleマップのPlaceID> --limit=<取得する写真の最大数>
```

引数なしで実行すると、世界中のランダムな場所から写真を検索します：

```
php artisan cat:extract
```

## 実装の詳細

### 猫の検出プロセス

1. Google Maps API を使用して様々な場所から写真を収集
2. Google Cloud Vision API の `localizeObjects` 機能を使用して、画像内のオブジェクトを検出
3. 検出されたオブジェクトに「Cat」が含まれる場合のみ、その写真を保存
4. 写真はクロップせずに元のサイズのまま保存

### 技術的ポイント

- Laravel コマンド機能を活用したバッチ処理の実装
- Google Cloud Vision API を使用した画像認識
- ストレージとシンボリックリンクを使用した画像の効率的な管理
- Tailwind CSS を使用したレスポンシブな UI デザイン

## ライセンス

このプロジェクトは [MIT ライセンス](https://opensource.org/licenses/MIT) のもとで公開されています。

## 注意事項

- Google Maps API と Google Cloud Vision API は有料のサービスです。使用量に応じて課金される可能性があります。
- アプリケーションは、写真内の猫を検出して保存します。検出精度は Vision API の性能に依存します。

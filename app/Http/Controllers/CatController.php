<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CatController extends Controller
{
    /**
     * 保存された猫の写真一覧を表示
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        // storage/app/public/cats ディレクトリの全てのファイルを取得
        $files = Storage::disk('public')->files('cats');

        // ファイルを最新順に並べ替え
        usort($files, function ($a, $b) {
            return filemtime(Storage::disk('public')->path($b)) - filemtime(Storage::disk('public')->path($a));
        });

        // ファイルのURLとメタデータを生成
        $cats = [];
        $baseUrl = url('/'); // アプリケーションのベースURLを取得
        foreach ($files as $file) {
            $filename = basename($file);
            // ファイル名からplace_idとその他の情報を抽出
            $parts = explode('_', $filename);
            $placeId = $parts[0] ?? 'unknown';

            // 正しいURLを生成 (url()関数を使ってベースURLを考慮)
            $fileUrl = asset('storage/' . $file);

            $cats[] = [
                'url' => $fileUrl,
                'filename' => $filename,
                'place_id' => $placeId,
                'created_at' => date('Y-m-d H:i:s', filemtime(Storage::disk('public')->path($file)))
            ];
        }

        return view('cats.index', ['cats' => $cats]);
    }
}

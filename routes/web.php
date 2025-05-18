<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CatController;
use Illuminate\Support\Facades\Artisan;

// 猫写真のギャラリーページ表示
Route::get('/', [CatController::class, 'index']);

// 猫写真抽出コマンドを実行するためのルート
Route::get('/extract-cats', function () {
    Artisan::call('cat:extract', ['--limit' => 50]);
    session()->flash('message', '猫写真の検索と保存が完了しました');
    return redirect('/');
});

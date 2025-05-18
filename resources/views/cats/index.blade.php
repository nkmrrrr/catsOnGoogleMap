<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>猫写真ギャラリー</title>
    <!-- Tailwind CSSを使用 -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 16px;
        }
        .cat-card {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s;
        }
        .cat-card:hover {
            transform: translateY(-5px);
        }
        .cat-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <header class="mb-8 text-center">
            <h1 class="text-3xl font-bold text-gray-800 mb-2">Google Maps上の猫写真ギャラリー</h1>
            <p class="text-gray-600">Google Maps上で見つかった猫の写真ギャラリー</p>
        </header>
        
        @if(session('message'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-6" role="alert">
                <span class="block sm:inline">{{ session('message') }}</span>
            </div>
        @endif
        
        <div class="bg-white p-6 rounded-lg shadow-md mb-8">
            <p class="mb-4">
                <strong>総写真数:</strong> {{ count($cats) }}枚
            </p>
            <p>
                <a href="{{ url('/extract-cats') }}" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded">
                    新しい猫写真を検索
                </a>
            </p>
        </div>

        @if(count($cats) > 0)
            <div class="gallery">
                @foreach($cats as $cat)
                    <div class="cat-card bg-white">
                        <img src="{{ $cat['url'] }}" alt="Cat image" class="cat-image">
                        <div class="p-4">
                            <p class="text-sm text-gray-500">場所ID: {{ $cat['place_id'] }}</p>
                            <p class="text-sm text-gray-500">撮影日: {{ $cat['created_at'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="text-center py-12">
                <p class="text-xl text-gray-600">猫の写真がまだありません。「新しい猫写真を検索」をクリックして写真を収集してください。</p>
            </div>
        @endif
    </div>
</body>
</html>

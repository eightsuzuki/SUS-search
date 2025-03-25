<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once 'LocalAlignment.php';

use Elastic\Elasticsearch\ClientBuilder;

// --- ユーティリティ関数 ---
function sanitize($data) {
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            $data[$k] = sanitize($v);
        }
        return $data;
    }
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

function get_wakachi($keyword) {
    return preg_replace('/\s+/', ' ', $keyword);
}

function _get_wakachi($word) {
    $wakachi = get_wakachi($word);
    $wakachi_array = explode(" ", $wakachi);
    if (count($wakachi_array) < 1) {
        echo "input keyword\n";
        exit;
    }
    return array_unique($wakachi_array);
}

// --- JSON データ読み込み ---
$jsonData = file_get_contents('products.json');
$products = json_decode($jsonData, true);

// GET パラメータの初期処理
if (isset($_GET['word']) && $_GET['word'] == mb_convert_encoding('キーワード', 'UTF-8', 'EUC-JP')) {
    unset($_GET['word']);
}
if ((!isset($_GET['word']) || trim($_GET['word']) === '') && isset($_GET['word_box'])) {
    $_GET['word'] = $_GET['word_box'];
}

// 入力された検索ワードの前処理（基本変換のみ）
$keyword = isset($_GET['word']) ? trim($_GET['word']) : '';
if ($keyword !== '') {
    $keyword = mb_convert_kana($keyword, 'Krn');
    $keyword = str_replace(mb_convert_encoding('―','UTF-8','EUC-JP'), '-', $keyword);
    $keyword = str_replace(mb_convert_encoding('‐','UTF-8','EUC-JP'), '-', $keyword);
    $keyword = str_replace(mb_convert_encoding('　','UTF-8','EUC-JP'), ' ', $keyword);
    $keyword = str_replace(mb_convert_encoding('Φ','UTF-8','EUC-JP'), mb_convert_encoding('φ','UTF-8','EUC-JP'), $keyword);
}
$_GET = sanitize($_GET);

// 検索処理
$results = [];

// モード切替："normal"（通常検索）、"fuzzy"（LocalAlignment）、"es"（Elasticsearch）
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'normal';

if ($keyword !== '') {
    if ($mode === 'es') {
        $client = ClientBuilder::create()->build();
        $indexName = 'products';
        
        // Elasticsearch 検索クエリ
        // 入力はそのまま送信し、ja_analyzer の正規化・変換に任せる
        $params = [
            'index' => $indexName,
            'body'  => [
                'query' => [
                    'multi_match' => [
                        'query'     => $keyword,
                        'fields'    => ['name^3', 'forindex', 'ItemNo', 'unit_catalog1'],
                        'fuzziness' => 'AUTO'
                    ]
                ]
            ]
        ];
        
        $response = $client->search($params);
        $results = [];
        if (isset($response['hits']['hits'])) {
            foreach ($response['hits']['hits'] as $hit) {
                $results[] = $hit['_source'];
            }
        }
    }
    else if ($mode === 'normal') {
        // 通常検索（既存の処理）
        $wakachi_tokens = _get_wakachi($keyword);
        $name = $keyword;
        $name7 = substr($name, 0, 7);
        foreach ($products as $product) {
            $match = false;
            if (isset($product['forindex'])) {
                $allTokensFound = true;
                foreach ($wakachi_tokens as $token) {
                    if (stripos($product['forindex'], $token) === false) {
                        $allTokensFound = false;
                        break;
                    }
                }
                if ($allTokensFound) {
                    $match = true;
                }
            }
            if (!$match && stripos($product['name'], $name) !== false) {
                $match = true;
            }
            if (!$match && isset($product['unit_catalog1']) && $product['unit_catalog1'] === $name) {
                $match = true;
            }
            if (!$match && stripos($product['ItemNo'], $name7) !== false) {
                $match = true;
            }
            if ($match) {
                $results[] = $product;
            }
        }
    }
    else if ($mode === 'fuzzy') {
        // 曖昧検索：LocalAlignment を利用
        $config = newLocalAlignmentConfig(3, 10, 10, [
            'の' => 100,
            ' '  => 0,
            '・' => 0,
        ]);
        $thresholdRatio = 0.8;
        foreach ($products as $product) {
            $match = false;
            $fields = [];
            if (isset($product['name'])) {
                $fields[] = $product['name'];
            }
            if (isset($product['forindex'])) {
                $fields[] = $product['forindex'];
            }
            if (isset($product['ItemNo'])) {
                $fields[] = $product['ItemNo'];
            }
            if (isset($product['unit_catalog1'])) {
                $fields[] = $product['unit_catalog1'];
            }
            foreach ($fields as $field) {
                $aligned = getLocalAlignment($field, $keyword, $config);
                if (mb_strlen($keyword) > 0 && (mb_strlen($aligned) / mb_strlen($keyword)) >= $thresholdRatio) {
                    $match = true;
                    break;
                }
            }
            if ($match) {
                $results[] = $product;
            }
        }
    }
} else {
    $results = $products;
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <title>商品検索システム - JSON版・通常/曖昧/Elasticsearch検索切替</title>
</head>

<body>
    <h1>商品検索</h1>
    <form method="GET" action="index.php">
        <input type="text" name="word"
            value="<?php echo isset($keyword) ? htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') : ''; ?>"
            placeholder="キーワードで検索">
        <br>
        <!-- 検索モード選択 -->
        <label>
            <input type="radio" name="mode" value="normal" <?php echo ($mode === 'normal' ? 'checked' : ''); ?>>
            通常検索
        </label>
        <label>
            <input type="radio" name="mode" value="fuzzy" <?php echo ($mode === 'fuzzy' ? 'checked' : ''); ?>>
            曖昧検索（LocalAlignment）
        </label>
        <label>
            <input type="radio" name="mode" value="es" <?php echo ($mode === 'es' ? 'checked' : ''); ?>>
            Elasticsearch検索（日本語対応）
        </label>
        <br>
        <input type="submit" value="検索">
    </form>
    <hr>
    <h2>検索結果 (<?php echo count($results); ?>件)</h2>
    <?php if (count($results) > 0): ?>
    <ul>
        <?php foreach ($results as $product): ?>
        <li>
            <strong><?php echo htmlspecialchars($product['ItemNo'], ENT_QUOTES, 'UTF-8'); ?></strong> :
            <?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?>
            (シリーズ: <?php echo htmlspecialchars($product['series'], ENT_QUOTES, 'UTF-8'); ?>, タイプ:
            <?php echo htmlspecialchars($product['type1'], ENT_QUOTES, 'UTF-8'); ?>)
        </li>
        <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p>該当する商品は見つかりませんでした。</p>
    <?php endif; ?>
</body>

</html>
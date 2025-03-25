<?php
// index.php

// -------------------------
// ダミーの sanitize 関数（XSS 対策の簡易版）
function sanitize($data) {
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            $data[$k] = sanitize($v);
        }
        return $data;
    }
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

// -------------------------
// 簡易版 get_wakachi() 関数（実際には形態素解析などが必要ですが、ここではスペース正規化のみ）
function get_wakachi($keyword) {
    // 余分なスペースを除去して返す
    return preg_replace('/\s+/', ' ', $keyword);
}

// _get_wakachi() は元コードの処理を再現（※実際の hex 変換は検索には使っていません）
function _get_wakachi($word) {
    // ここではエンコーディング変換は不要（UTF-8 前提）
    $wakachi = get_wakachi($word);
    $wakachi_array = explode(" ", $wakachi);
    if (count($wakachi_array) < 1) {
        echo "input keyword\n";
        exit;
    }
    // オリジナルでは array_unique して各トークンを "+{hex}*" に変換していましたが、
    // ここでは検索用にトークン配列そのまま返す
    $wakachi_array = array_unique($wakachi_array);
    return $wakachi_array;
}

// -------------------------
// サンプルの JSON データ（実際は products.json などから読み込みます）
$jsonData = file_get_contents('products.json');

$products = json_decode($jsonData, true);

// -------------------------
// GET パラメータの初期処理
// （「キーワード」という初期表示値の場合は未入力扱いにする）
if (isset($_GET['word']) && $_GET['word'] == mb_convert_encoding('キーワード', 'UTF-8', 'EUC-JP')) {
    unset($_GET['word']);
}
if ((!isset($_GET['word']) || trim($_GET['word']) === '') && isset($_GET['word_box'])) {
    $_GET['word'] = $_GET['word_box'];
}

// -------------------------
// 入力された検索ワードに対して各種文字変換を実施
$keyword = isset($_GET['word']) ? trim($_GET['word']) : '';
if ($keyword !== '') {
    // 半角英数字は半角、半角カナは全角カナへ（オプション 'Krn'）
    $keyword = mb_convert_kana($keyword, 'Krn');
    // 各種ハイフンを半角に変換
    $keyword = str_replace(mb_convert_encoding('―','UTF-8','EUC-JP'), '-', $keyword);
    $keyword = str_replace(mb_convert_encoding('‐','UTF-8','EUC-JP'), '-', $keyword);
    // 全角スペースを半角スペースへ
    $keyword = str_replace(mb_convert_encoding('　','UTF-8','EUC-JP'), ' ', $keyword);
    // 'Φ' を 'φ' に変換
    $keyword = str_replace(mb_convert_encoding('Φ','UTF-8','EUC-JP'), mb_convert_encoding('φ','UTF-8','EUC-JP'), $keyword);
}
$_GET = sanitize($_GET);

// -------------------------
// 検索処理
$results = [];
if ($keyword !== '') {
    // ここでオリジナルの _get_wakachi() を呼び出し、トークン配列を取得
    $wakachi_tokens = _get_wakachi($keyword);
    
    // $name は元コードのように扱い、先頭7文字も取得
    $name = $keyword;
    $name7 = substr($name, 0, 7);
    
    // 各商品について、複数条件による検索を実施
    foreach ($products as $product) {
        $match = false;
        
        // ① 「forindex」フィールドに、すべての入力トークンが含まれるか（大文字小文字不問）
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
        // ② 商品名（name）にキーワードが含まれるか
        if (!$match && stripos($product['name'], $name) !== false) {
            $match = true;
        }
        // ③ unit_catalog1 が存在し、キーワードと完全一致するか
        if (!$match && isset($product['unit_catalog1']) && $product['unit_catalog1'] === $name) {
            $match = true;
        }
        // ④ ItemNo に、キーワードの先頭7文字が含まれるか
        if (!$match && stripos($product['ItemNo'], $name7) !== false) {
            $match = true;
        }
        
        if ($match) {
            $results[] = $product;
        }
    }
} else {
    // キーワード未入力の場合は全件表示
    $results = $products;
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <title>商品検索システム - JSON版・検索アルゴリズム再現</title>
</head>

<body>
    <h1>商品検索</h1>
    <form method="GET" action="index.php">
        <input type="text" name="word"
            value="<?php echo isset($keyword) ? htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') : ''; ?>"
            placeholder="キーワードで検索">
        <input type="submit" value="検索">
    </form>
    <hr>
    <h2>検索結果</h2>
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
<?php
/**
 * indexer.php
 *
 * Elasticsearch の「products」インデックスを作成し、products.json の商品データを Bulk API で投入するスクリプトです。
 *
 * マッピング設定では、日本語向けのカスタムアナライザーを定義しています。
 * このアナライザーは、ICU Normalizer（nfkc_cf）と ICU Transform（ひらがな→カタカナ変換）を char_filter として使用し、
 * kuromoji_tokenizer と kuromoji 系フィルターを組み合わせて日本語テキストを正規化・解析します。
 */

require_once __DIR__ . '/vendor/autoload.php';
use Elastic\Elasticsearch\ClientBuilder;

// Elasticsearch クライアントの生成
$client = ClientBuilder::create()->build();
$indexName = 'products';

// カスタムマッピング・設定
$mapping = [
    'settings' => [
        'analysis' => [
            'tokenizer' => [
                // カスタムトークナイザー：kuromoji_tokenizer を search モードで利用
                'my_kuro_tk' => [
                    'type' => 'kuromoji_tokenizer',
                    'mode' => 'search'
                ]
            ],
            'char_filter' => [
                // ICU Normalizer：全角文字→半角、英字大文字→小文字などの正規化を実施
                'icu_normalizer' => [
                    'type' => 'icu_normalizer',
                    'name' => 'nfkc_cf',
                    'mode' => 'compose'
                ],
                // kuromoji_iteration_mark：反復記号の展開（例：「々」など）
                'kuromoji_iteration_mark' => [
                    'type' => 'kuromoji_iteration_mark'
                ],
                // html_strip：HTML タグの除去
                'html_strip' => [
                    'type' => 'html_strip'
                ]
            ],
            'filter' => [
                // hiragana_2_katakana：ひらがなをカタカナに変換する ICU Transform
                'hiragana_2_katakana' => [
                    'type' => 'icu_transform',
                    'id'   => 'Hiragana-Katakana'
                ],
                // e_ngram_filter：エッジ NGram フィルター（検索補完等に利用）
                'e_ngram_filter' => [
                    'type'     => 'edge_ngram',
                    'min_gram' => 1,
                    'max_gram' => 10
                ]
            ],
            'analyzer' => [
                // my_ja-default_anlz: 日本語のデフォルト解析用アナライザー
                'my_ja-default_anlz' => [
                    'type'        => 'custom',
                    'tokenizer'   => 'my_kuro_tk',
                    'char_filter' => ['icu_normalizer', 'kuromoji_iteration_mark', 'html_strip'],
                    'filter'      => [
                        'kuromoji_baseform',
                        'kuromoji_part_of_speech',
                        'ja_stop',
                        'lowercase',
                        'kuromoji_number',
                        'kuromoji_stemmer'
                    ]
                ],
                // my_ja-readingform_x_e-ngram_anlz: 読みフィールド用の解析アナライザー（エッジ NGram を利用）
                'my_ja-readingform_x_e-ngram_anlz' => [
                    'type'        => 'custom',
                    'tokenizer'   => 'my_kuro_tk',
                    'char_filter' => ['icu_normalizer', 'html_strip'],
                    'filter'      => [
                        'kuromoji_readingform',
                        'lowercase',
                        'hiragana_2_katakana',
                        'e_ngram_filter'
                    ]
                ],
                // my_almost_noop: トークン化せず、ひらがな→カタカナ変換のみを行う検索用アナライザー
                'my_almost_noop' => [
                    'type'      => 'custom',
                    'tokenizer' => 'keyword',
                    'filter'    => ['hiragana_2_katakana']
                ]
            ]
        ]
    ],
    'mappings' => [
        'properties' => [
            'ItemNo' => [ 'type' => 'keyword' ],
            // name フィールド：デフォルト解析器は my_ja-default_anlz、サブフィールド rf_eng には my_almost_noop を使用
            'name'   => [
                'type'            => 'text',
                'analyzer'        => 'my_ja-default_anlz',
                'search_analyzer' => 'my_ja-default_anlz',
                'fields' => [
                    'rf_eng' => [
                        'type'     => 'text',
                        'analyzer' => 'my_almost_noop'
                    ]
                ]
            ],
            'series' => [
                'type'            => 'text',
                'analyzer'        => 'my_ja-default_anlz',
                'search_analyzer' => 'my_ja-default_anlz'
            ],
            'type1' => [
                'type'            => 'text',
                'analyzer'        => 'my_ja-default_anlz',
                'search_analyzer' => 'my_ja-default_anlz'
            ],
            'forindex' => [
                'type'            => 'text',
                'analyzer'        => 'my_ja-default_anlz',
                'search_analyzer' => 'my_ja-default_anlz'
            ],
            'unit_catalog1' => [
                'type'            => 'text',
                'analyzer'        => 'my_ja-default_anlz',
                'search_analyzer' => 'my_ja-default_anlz'
            ]
        ]
    ]
];

$params = ['index' => $indexName];
if (!$client->indices()->exists($params)) {
    $params['body'] = $mapping;
    $response = $client->indices()->create($params);
    echo "Index created:\n" . json_encode($response, JSON_PRETTY_PRINT) . "\n";
} else {
    echo "Index '{$indexName}' already exists.\n";
}

// products.json から商品データを読み込み
$jsonFile = 'products.json';
if (!file_exists($jsonFile)) {
    die("File not found: $jsonFile\n");
}
$jsonData = file_get_contents($jsonFile);
$products = json_decode($jsonData, true);

// Bulk API 用パラメータ作成：各商品をインデックスに登録
$bulkParams = ['body' => []];
foreach ($products as $product) {
    $bulkParams['body'][] = [
        'index' => [
            '_index' => $indexName,
            '_id'    => $product['ItemNo']
        ]
    ];
    $bulkParams['body'][] = $product;
}

if (!empty($bulkParams['body'])) {
    $bulkResponse = $client->bulk($bulkParams);
    if (isset($bulkResponse['errors']) && $bulkResponse['errors'] === true) {
        echo "Bulk indexing encountered errors:\n";
        print_r($bulkResponse);
    } else {
        echo "Bulk indexing completed successfully.\n";
    }
} else {
    echo "No data to index.\n";
}
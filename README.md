# 商品検索システム

このリポジトリは、Elasticsearch を利用した日本語対応の全文検索システムのサンプル実装です。  
Elasticsearch のカスタムアナライザー（icu_normalizer、icu_transform を利用）によって、全角/半角、ひらがな/カタカナ、英字の大文字/小文字の表記揺れを吸収した検索が実現されています。

## 構成

- **indexer.php**  
  Elasticsearch の「products」インデックスを作成し、`products.json` の商品データを Bulk API を利用して登録するスクリプト。

- **index.php**  
  登録されたデータを用いて、通常検索、曖昧検索（LocalAlignment を利用）、および Elasticsearch 検索（日本語対応）の3種類の検索モードで商品検索を行うWeb画面。

- **LocalAlignment.php**  
  曖昧検索モード用に、局所整列（Local Alignment）アルゴリズムを実装したファイル（検索モード「fuzzy」で利用）。

- **products.json**  
  商品データが JSON 形式で記述されたファイル。

- **composer.json / composer.lock / vendor/**  
  Elasticsearch PHP クライアント等の依存パッケージ管理用ファイル。  
  ※`vendor/` は Git 管理対象から除外します。

## 要件

- PHP 8.1 以上
- Composer
- Elasticsearch 8.x（Elasticsearch がローカルまたは接続可能な状態で稼働していること）

## セットアップ

1. **リポジトリのクローン**

   ```bash
   git clone https://github.com/your-username/your-repository.git
   cd your-repository
   ```

2. **Composer のインストール**

   Composer がインストールされていない場合は [Composer の公式サイト](https://getcomposer.org/) を参照してください。

3. **依存パッケージのインストール**

   ```bash
   composer install
   ```

4. **Elasticsearch のセットアップ**

   Elasticsearch 8.x を起動してください。  
   ブラウザで [http://localhost:9200](http://localhost:9200) にアクセスし、Elasticsearch のクラスタ情報が表示されることを確認してください。

## インデックス作成とデータ投入

1. インデックス作成とデータ投入を実行するには、以下のコマンドを実行してください。

   ```bash
   php indexer.php
   ```

   ※ インデックスのマッピングには、icu_normalizer と icu_transform（ひらがな→カタカナ変換）を使用しています。  
   ※ マッピング変更を行った場合は、既存のインデックスを削除してから再実行してください。  
   例：  
   ```bash
   curl -X DELETE "localhost:9200/products?pretty"
   php indexer.php
   ```

## 検索

Web ブラウザで `index.php` を実行すると、検索画面が表示されます。  
以下の URL 例のように、検索ワードとモードを指定して検索できます。

- 通常検索  
  ```
  http://localhost:8000/index.php?word=ぶらっく&mode=normal
  ```

- 曖昧検索（LocalAlignment）  
  ```
  http://localhost:8000/index.php?word=ぶらっく&mode=fuzzy
  ```

- Elasticsearch 検索（日本語対応）  
  ```
  http://localhost:8000/index.php?word=ぶらっく&mode=es
  ```

※ Elasticsearch モードでは、登録時にカスタムアナライザーが適用されるため、検索クエリの「ぶらっく」も正規化され、文書側の「ブラック」と一致することが期待されます。

## トークンの確認

Elasticsearch の [/_analyze API](https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-analyze.html) を使って、ja_analyzer による解析結果を確認できます。

例：
```bash
curl -X GET "localhost:9200/products/_analyze?pretty" -H 'Content-Type: application/json' -d'
{
  "analyzer": "ja_analyzer",
  "text": "ぶらっく"
}
'
```

## 注意点

- マッピング設定変更後は、既存のインデックスを削除して再作成してください。
- このサンプルはあくまで動作確認用の簡易実装です。実運用向けにセキュリティやパフォーマンス面の調整が必要です。

## 参照サイト

下記の記事も参考にしてみてください:

[Elasticsearchのローカル環境構築手順](https://www.gluegent.com/blog/2023/09/elasticsearch-local-env.html)

記事では、ローカル環境でのElasticsearchのセットアップ方法について詳しく解説しています。

[Elasticsearch を Homebrew で macOS 上に構築](https://qiita.com/sugasaki/items/2cdefa3787e962095d19)

# CanMatch アーキテクチャ概要

## 目的

CanMatch は、自販機と商品の情報を蓄積し、写真の読み取りと自然言語の質問応答を組み合わせて利用する Web アプリケーションです。

この文書は、アプリケーション全体の構成と責務を整理し、設計上の流れと実装状況を区別して説明することを目的とします。

関連文書:
- [RAG詳細設計](rag-design.md)
- [共通データ仕様](data-contract.md)

## 1. システム構成

### Laravel アプリケーション
Laravel アプリケーションは、画面・サービス・設定の中心です。

確認できる主要コンポーネントは次のとおりです。

- `App\Services\VendingMachineReader`
  - 画像を受け取り、Gemini に渡して商品候補や温冷を読み取る。
  - 画像の取得には `Storage::disk(...)` を利用する。
- `App\Services\Rag\EmbeddingService`
  - 文章をベクトルへ変換する。
  - Cloudflare Workers AI の埋め込み API を呼ぶ。
- `App\Services\Rag\VectorIndex`
  - Vectorize の登録・検索・削除を行う。
  - `upsert()`, `query()`, `delete()` を提供する。
- `App\Services\Rag\AnswerGenerator`
  - 生成モデルを呼び出し、`citations` の妥当性を検証し、最終回答を組み立てる。

### MySQL
MySQL は、アプリの永続データを保持する本体です。

主要なテーブルとして、既存の仕様書に以下が示されています。
- `users`
- `vending_machines`
- `products`
- `machine_products`
- `point_logs`

詳細は [共通データ仕様](data-contract.md) を参照する。

### Cloudflare Workers AI と Vectorize
RAG の埋め込み・検索部分は Cloudflare の API を利用する構成です。

- `EmbeddingService` は Workers AI の埋め込み API を呼ぶ。
- `VectorIndex` は Vectorize のインデックスへの登録・検索・削除を行う。
- 現在の設定では `@cf/baai/bge-m3` を既定値として使う構成が確認できる。

### R2
写真の保存先として R2 を使える構成が確認できます。

- `config/filesystems.php` に `r2` ディスクが定義されている。
- `PHOTOS_DISK` で `local` と `r2` を切り替える設���になっている。
- 実装済みのコードは `Storage::disk(...)` を利用する方式で、R2 への保存・読み取りに対応している。
- ただし `temporaryUrl()` を使った画面表示の実装はこの時点では確認できない。

### Gemini / AI Gateway
Gemini による写真解析は `VendingMachineReader` で実装されています。

- `config/services.php` に `gemini` 設定があり、`base_url` と `model` を設定経由で切り替えられる。
- `GEMINI_BASE_URL` を変えることで AI Gateway 経由を使う設計が想定されている。
- ただし、RAG の回答生成そのものは Cloudflare 側の生成モデルを直接使う構成であるため、Gemini と RAG の生成経路は分離している。

## 2. 高レベルな処理フロー

### 2.1 写真投稿と AI 読み取り
設計上の流れは次のとおりです。

1. 画像が保存される。
2. `VendingMachineReader::read()` が写真パスとディスク名を受け取る。
3. `Storage::disk($disk)->get($path)` で画像を読み込む。
4. Gemini へ画像と指示文を送る。
5. 画像解析の結果として、商品候補、価格、温冷、`is_vending_machine` などの配列を返す。
6. 返ってきた結果は `normalize()` により、アプリで扱いやすい形に整形される。

この経路は実装済みです。ただし、ここから DB への反映や、ユーザー確認画面への接続は別の実装条件に依存する。

### 2.2 自販機と商品の登録
自販機・商品・中間テーブルの設計は既存のデータ契約に整理されています。

重要な点は次の 2 つです。
- `products` と `machine_products` の構造は定義済み
- ただし、商品保存時に Vectorize に自動登録するフックは main 上で確認できない

つまり、商品登録の完成と「検索インデックスへの同期」は別の作業として扱う必要がある。

### 2.3 地図表示
地図表示は `resources/views/map.blade.php` などで提供される。

- 地図表示の基本機能は確認できる
- 地図に使うデータの取得と表示は、DB や UI の接続が別作業として進められている

## 3. RAG の高レベルフロー
RAG の詳細は [RAG詳細設計](rag-design.md) を参照する。

### 3.1 索引の作成
設計上は、商品1件を1つの文章として埋め込みに変換し、Vectorize に登録する方式です。

設計上の前提:
- インデックス名: `canmatch-products`
- 埋め込みモデル: `@cf/baai/bge-m3`（現在の既定値）
- ベクトル ID: 商品 ID
- 検索対象: 商品ごとの文書

ここで重要なのは、個別サービスが実装されていても、商品保存時に自動で Vectorize に `upsert` する統合処理が未接続である点です。

### 3.2 質問と検索
質問の流れは設計上次のように整理される。
1. 質問を埋め込みへ変換する
2. Vectorize で近い商品候補を検索する
3. 候補のスコアを判定し、しきい値未満なら回答を返さない
4. 上位候補を根拠候補として扱う

この処理の個別コンポーネントとして、`EmbeddingService::embedOne()` と `VectorIndex::query()` が実装済みである。

### 3.3 SQL による根拠取得
設計上は、Vectorize の結果に基づいて SQL で `machine_products` などを結合し、次のような「根拠行」を取り出す。
- `machine_id`
- `product_id`
- `product_name`
- `volume_ml`
- `price`
- `temperature`
- `recorded_at`
- `days_ago`
- `description`

この一覧の詳細は [RAG詳細設計](rag-design.md) を参照する。

### 3.4 回答生成
`AnswerGenerator` は、質問と根拠行の一覧を受け取り、生成モデルへ送る役割を持つ。

実装上のポイント:
- `generate()` は生成モデルの呼び出しを担当する。
- `validateCitations()` は `citations` が根拠行と完全一致しているかを検証する。
- `buildAnswer()` は、モデルの文章ではなく、サーバー側で固定の前置きと根拠行から回答文を組み立てる。

この実装は設計と整合している。実装においては、AI の文章そのものは回答に使わず、`citations` だけを使ってサーバー側が回答を構成する。

### 3.5 実装済みと未実装の区別
個別サービスは実装済みだが、次の統合処理は未実装または未接続である。
- 商品保存時の自動 Vectorize 同期
- 質問→検索→根拠取得→生成までの一連のオーケストレーション
- 投稿写真の `temporaryUrl()` を使った表示

## 4. データ契約との関係
RAG の根拠行の詳細は [RAG詳細設計](rag-design.md) にある。

本書では、DB スキーマの大枠とどこで何が管理されているかを記すに留める。特に次の内容はここで重複定義しない。
- `description` の DB 追加に関する詳細
- `question_logs` の正式スキーマ
- RAG の検証パラメータ（しきい値、topK 等）

## 5. エラー時の挙動

### AI 読み取り失敗
`VendingMachineReader` は `AiReadFailedException` を使い、429・404・タイムアウト・JSON 解析失敗などを分けて扱う実装になっている。

### RAG の生成失敗
`AnswerGenerator` は `RagGenerationFailedException` を使い、次の失敗を扱う。
- `no_evidence`
- `invalid_response`
- `unsupported_citation`
- `rate_limit`
- `request_failed`

### フォールバック方針
設計上は次のように扱う。
- 根拠がなければ、回答を返さずに「登録されたデータからは条件に合う商品を確認できません。」を返す
- 生成が失敗した場合は、根拠一覧だけを表示してエラーを出す
- 主な考慮は、AI の自由な文章ではなく、根拠となる情報の妥当性をサーバーが検証すること

## 6. セキュリティと運用上の考慮事項
- API キーや画像本体はログへ残さない設計が見られる。
- Cloudflare と Gemini の API 設定は `config/services.php` に集約されている。
- R2 は非公開保存が想定されているが、公開可否と保持期間は運用上の未確定事項である。
- `question_logs` の具体的な保存方式と保持期間は未確定であり、正式な DB 定義はここに書かない。

## 7. 実装状況の整理

### 実装済み
- `EmbeddingService`
- `VectorIndex`
- `AnswerGenerator`
- `VendingMachineReader`
- R2 のディスク設定
- 地図表示の基本機能

### 設計のみ存在
- 商品保存時に自動で Vectorize に登録するフロー
- 質問から検索・根拠取得・生成までの統合処理
- `question_logs` の正式スキーマ
- 写真の公開可否と `temporaryUrl()` を使った表示の本番運用

### 未確定事項
- 埋め込みモデルの最終評価: `@cf/baai/bge-m3` は現時点の既定値だが、最終評価は未完了
- `topK` の最終値: 現在の既定値 10
- 類似度のしきい値: 設計上の暫定値 0.45
- `question_logs` の保存形式と保持期間
- レート制限の具体値

## 8. 今後の作業
- 商品保存時の自動 Vectorize 同期の追加
- 質問から回答までの統合処理の実装
- `temporaryUrl()` を使った画像表示の実装
- RAG の運用パラメータの最終評価と文書更新

これらは個別の Issue と実装で扱うのが適切であり、Issue #60 の文書修正では本書の範囲に留める。

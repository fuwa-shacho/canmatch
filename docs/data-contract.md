# CanMatch 共通データ仕様 v1.0

作成日: 2026-10-17（日本時間）
更新日: 2026-10-24（日本時間）

## 位置付けと根拠

- 原典: ユーザー提供「自販機マップ 仕様書とIssue一覧」2026年10月11日版全文。
- 追加決定: 4人分担を維持する。B担当は#25を優先し、C不参加時にも採用できる精度で#21〜24の補完を準備する。
- 追加決定: 2026-10-17、ユーザーが前のカラム案に基づく下書き作成を承認。原典未記載の細部は正常動作を優先して補完し、理由を明記する。
- リポジトリ: https://github.com/fuwa-shacho/canmatch
- 直近確認したmain: `bfd364cbf24bd3650b29518194e7d4bc5db388e5`（2026-10-24確認）。これは確認時点の記録であり、今後の作業前に更新確認する。
- 本書は#03の成果物で、mainへマージされた時点でチームの共通仕様とする。担当間で受け渡すデータの形は本書だけで分かることを目指す。
- #20〜25のマイグレーションとモデル、#32の地図用JSON(PR #108)、#45の登録処理(PR #123)、容量違いの登録(PR #135)はmainに統合済み。
- まだOpen PRの内容は「レビュー中」と明記する。

## 記載状態

- **確定事項**: 原典、mainへ統合済みの実装、またはチームで確認済みの決定。
- **レビュー中**: Open PRの内容。マージ前なので確定事項として依存しない。
- **未確定事項**: 依存Issueの決定やチーム確認が必要な内容。
- **検討候補**: 正常動作のための提案。レビューで承認されるまで既定事項にしない。

## RAG設計への参照

- RAGの検索・回答生成に関する詳細仕様は [RAG詳細設計](rag-design.md) を参照する。
- システム全体の構成と各コンポーネントの責務は [アーキテクチャ概要](architecture.md) を参照する。
- RAGの根拠行の形式や検索条件はRAG詳細設計を正とし、本書では重複定義しない。
- `description` はRAG設計で使用する。`products` テーブルへの追加は#62でmainに統合済み(#22の節を参照)。
- `question_logs`(質問の記録と1日の質問上限)はPR #134でレビュー中。マージ後にRAG詳細設計とあわせて本書へ確定事項として追記する。

## 共通規則

開発DBはSailのMySQL 8系。IDはLaravelの`id()`と対応するunsigned BIGINT、外部キーは`foreignId()`を使用する。価格は整数の円、容量は整数のml。NULLは不明または該当なしを表し、0や推測値で代用しない。任意文字列の空文字はNULLに正規化する。

日時はUTCで保存し、画面表示と週間ランキングの月曜0時の境界はAsia/Tokyoで計算する。週境界をUTCに変換してDBを検索する。この時刻規則は原典未記載の実装補完である。

DB制約で外部キー・必須・値の範囲を守り、フォームでも同じ検証を行う。MySQL固有のCHECK制約はSQLiteのテストでは確認できないため、MySQL環境で確認する。

## #20 users

既存スターターキットのテーブルを使用し、`name`に一意制約を追加する。email/passwordの保存方式を変更しない。既存DBに重複nameがあれば、移行を停止して対象を報告する。自動改名・削除はしない。登録・プロフィール変更の入力検証は#29の既存PRと整合させる。

## #21 vending_machines

| カラム | DB定義 | 意味・規則 |
|---|---|---|
| id | unsigned BIGINT primary | 自販機ID |
| latitude | DECIMAL(10,7) NOT NULL | -90〜90、入力の小数は最大7桁 |
| longitude | DECIMAL(10,7) NOT NULL | -180〜180、入力の小数は最大7桁 |
| maker | VARCHAR(255) NULL | メーカーの識別子。不明はNULL(下記) |
| photo_path | VARCHAR(1024) NOT NULL | 保存先ディスク内の相対パス。期限付きURLを保存しない |
| user_id | unsigned BIGINT NULL FK users.id | 登録者。ユーザー削除時SET NULL |
| last_confirmed_at | DATETIME NOT NULL | 確認して確定登録した日時 |
| created_at / updated_at | Laravel標準timestamps | 作成・更新日時 |

新規登録時のuser_idは認証済みユーザーのIDをサーバーで設定し、リクエスト値を信用しない。NULL許可は退会後も自販機情報を残すためで、匿名投稿の許可ではない。退会後の表示は「退会済みユーザー」とする。更新者で登録者を書き換えない。

写真パスは設定済みの写真ディスクで解決する。ディスク変更時は既存写真の移行も伴わせる。読めることを確認した保存パスを使い、ダミーのパスで本番登録を成立させない。

makerは投稿画面の選択肢の識別子(`dydo` / `suntory` / `cocacola` / `asahi` / `kirin` / `itoen` / `other`)で保存する。「不明」(`unknown`)はNULLで保存する。現在は地図JSONと詳細画面に識別子のまま出るため、表示名への変換は後続Issueで扱う(既知の差分)。

非表示フラグと報告履歴は#10で追加する。撤去報告で物理削除しない。

## #22 products

| カラム | DB定義 | 規則 |
|---|---|---|
| id | unsigned BIGINT primary | 商品ID |
| name | VARCHAR(255) NOT NULL | 空白のみは不可。前後空白を除く |
| maker | VARCHAR(255) NULL | 不明はNULL |
| volume_ml | unsigned INTEGER NULL | 既知なら1以上。不明はNULL |
| category | VARCHAR(255) NULL | 不明はNULL |
| description | TEXT NULL | 商品の説明(甘さ、炭酸の有無、カフェイン、味の系統)。RAGの検索用の文章に使う。投稿で追加された商品は空でよい(#62) |
| created_at / updated_at | Laravel標準timestamps | 作成・更新日時 |

nameに検索用インデックスを設ける。ただし前方ワイルドカードの部分一致が高速化されるとは保証しない。name単独の一意制約は付けない。容量違いは別商品とする。メーカー・カテゴリをNULL可とするのは、読み取りで商品名しか得られない場合に不明情報を推測せず保存するため。

商品名の照合(#44、`ProductMatcher`)は次の通り。照合前に全角英数・全角スペースを半角に、半角カナを全角に揃え、前後と連続の空白を整える。

- 完全一致が1件: その商品へ自動で紐づける。
- 完全一致が複数(容量違いなど): 先頭の1件を自動選択せず、候補から選ばせる。
- 完全一致なし: 2文字以上なら部分一致を最大5件まで候補として示す。
- 確認画面の「商品マスタから探す」は部分一致で最大8件を名前・容量の順に返す。選んだ商品はサーバー側の検索結果と照らして確認してから紐づける。

紐づけのない行は登録時(#45、`VendingMachineRegistrar`)に、正規化した名前と容量が同じ商品があればそれを使い、なければ新しい商品を作る。容量は任意入力で、空なら容量不明(NULL)の商品として扱う(PR #135)。外から容量が分からない自販機が多いためである。同じ送信の中で同じ新商品が複数行あっても商品は1つだけ作る。

表記ゆれ(例: 「コカコーラ」と「コカ・コーラ」)は同じ商品として扱わない。統合が必要な場合は商品マスタ側で整理する。

descriptionは#62で追加した。検索用の文章は「商品名 / メーカー / カテゴリ / 容量ml / 説明」をつなげて作り、空の項目は飛ばす(`Product::searchText()`)。価格と自販機は変わるため文章に入れない。説明の根拠(確認した公式ページ、確認した事実とRAG用の判断の区別)は docs/product-master-sources.md の「商品説明(description)の根拠」に記載する。

## #23 machine_products

| カラム | DB定義 | 規則 |
|---|---|---|
| id | unsigned BIGINT primary | 紐づけ行ID |
| vending_machine_id | unsigned BIGINT NOT NULL FK | 自販機物理削除時CASCADE |
| product_id | unsigned BIGINT NOT NULL FK | 紐づけがある商品の物理削除はRESTRICT |
| price | unsigned INTEGER NOT NULL | 円。0以上。未知を0に置き換えない |
| temperature | VARCHAR(16) NULL | hot / cold / NULL |
| recorded_at | DATETIME NOT NULL | この価格・温冷を確認した日時 |

created_at/updated_atは設けず、記録日時はrecorded_atに一本化する。DBでtemperatureの許容値も検証する。自販機IDと商品IDにインデックスを設ける。

現在の商品構成を持つ。新規登録(#45)は自販機・商品・価格を1つのトランザクションで保存し、途中で失敗したら全部取り消す。#46では対象自販機の行をトランザクション内で入れ替える。過去の読み取り・根拠は#08の記録と分ける。価格が不明な下書きは許容するが、確定登録前に入力を求める。温冷が不明ならNULLで保存し、画面では「不明」と表示する。

同じ商品のhot/coldを両方保存できるため、自販機ID＋商品IDだけの一意制約を付けない。同一の自販機・商品・温冷の重複は登録処理で拒否し(#45で実装済み。重複があれば送信全体を取り消してエラーを返す)、更新時は対象自販機行のロックを取って同時更新を直列化する。NULLも同一の温冷状態として比較する。温冷ごとに価格が異なる場合も各1行で保存する。

Eloquent標準の`sync()`/`updateExistingPivot()`は商品ID単位で操作するため、hot/coldが共存する書き込みには使用しない。入れ替えは対象自販機の行を削除して確認済みの全行を挿入し、個別操作が必要なら中間行IDで指定する。これは同じ商品を温冷両方で売る場合の消失・上書きを防ぐ実装補完である。

商品数とレア判定の台数はそれぞれDISTINCTの商品ID・自販機IDを数え、温冷2行を2商品・2台と数えない。

レア商品(#52、PR #128)は、販売している自販機が`config('canmatch.rare_product_max_machines')`(既定3)台以下の商品とする。100mlあたりの単価(#51)は`price × 100 ÷ volume_ml`を小数1桁に丸めた値で、容量不明の商品はNULL(表示は「不明」)とする。

## #24 point_logs

| カラム | DB定義 | 規則 |
|---|---|---|
| id | unsigned BIGINT primary | ポイント履歴ID |
| user_id | unsigned BIGINT NOT NULL FK users.id | ユーザー削除時CASCADE |
| type | VARCHAR(32) NOT NULL | registration / update / rare_bonus |
| points | INTEGER NOT NULL | 付与ポイント。点数は#53・#54の設定値から取得 |
| vending_machine_id | unsigned BIGINT NULL FK | 自販機物理削除時SET NULL |
| created_at | DATETIME NOT NULL | 付与日時。UTC |

updated_atは持たない。モデルはcreated_atのみ自動設定する。user_id、created_atの個別インデックスと(user_id, created_at)の複合インデックスを設ける。#55の履歴検索用に(vending_machine_id, created_at)も設ける。typeの許容値をDBでも検証する。

退会に伴い当該ユーザーの履歴は削除し、自販機が物理削除されても残存ユーザーの合計ポイントを変えない。通常の撤去は非表示なので外部キーは維持される。履歴を通常操作で書き換えない。

付与の二重実行防止、登録・更新とポイントの同一トランザクション、7日判定、点数確定は#53〜55で実装する(2026-10-24時点で未統合)。本書だけで二重付与防止が完了したとは扱わない。

総合ランキング(#56、PR #129)は`point_logs.points`をユーザーごとに合計し、合計の降順・名前順・ID順に上位20人を返す。同点は同順位とする。ポイント付与が未統合の間は表示対象が0人になる。

## #25 モデルとリレーション

| モデル | メソッド | 関係 |
|---|---|---|
| User | vendingMachines() | hasMany VendingMachine |
| User | pointLogs() | hasMany PointLog |
| VendingMachine | user() | belongsTo User、退会後NULL |
| VendingMachine | products() | belongsToMany Product |
| VendingMachine | pointLogs() | hasMany PointLog |
| Product | vendingMachines() | belongsToMany VendingMachine |
| PointLog | user() | belongsTo User |
| PointLog | vendingMachine() | belongsTo VendingMachine、物理削除後NULL |

多対多は`machine_products`と両外部キー名を明示する。両方向で`withPivot(['id', 'price', 'temperature', 'recorded_at'])`を指定し、`withTimestamps()`は使わない。中間テーブルにupdated_atがないためである。

座標はdecimal:7、最終確認日時はdatetime、容量・価格・ポイントはintegerとして扱う。中間データの価格・記録日時も型を揃える必要があるため、内部補助の`MachineProduct extends Pivot`を使用し、priceをinteger、recorded_atをdatetimeにcastする。idは自動採番、timestampsはfalse。この補助クラスの追加は正常な型変換のための実装詳細で、独立した機能追加ではない。

取得例（モデルとテーブルの実装後に確認する）:

```php
VendingMachine::findOrFail($machineId)->products;
Product::findOrFail($productId)->vendingMachines;
$product->pivot->price;
$product->pivot->temperature;
$product->pivot->recorded_at;
```

同一商品がhot/coldで2行返ることは正常。表示には中間行IDをキーとして使う。書き込みは#23の規則を守る。

## AI読み取りJSON

### Geminiが返すJSON（確定事項: #17）

#17で決定したモデル`gemini-3.5-flash-lite`・指示文`vending_read_v1`(`.env.example`の既定値)は次の形を返す。精度は読み取り評価(#7、PR #127)で確認した。

```json
{
  "is_vending_machine": true,
  "maker": "メーカー名",
  "products": [
    {
      "name": "商品名",
      "price": 160,
      "temperature": "cold"
    }
  ]
}
```

| 項目 | 型 | 規則 |
|---|---|---|
| is_vending_machine | boolean | 自販機が写っている場合だけtrue |
| maker | string / null | 読めない場合はnull |
| products | array | 自販機でない場合は空配列 |
| products[].name | string / null | 空文字はnullに正規化 |
| products[].price | integer / null | 円。読めない場合はnull |
| products[].temperature | hot / cold / null | 想定外の値はnullに正規化 |

名前と価格が両方nullの商品行は破棄する。AIが返した値は確定データではなく、#43の確認・修正を経てから登録する。読めない値を推測で埋めない。

### アプリ内部の読み取り結果（確定事項: mainの現行実装）

`VendingMachineReader`は、正規化した`result`に加えて監査用情報を返す。

```json
{
  "result": {
    "is_vending_machine": true,
    "maker": "メーカー名",
    "products": []
  },
  "raw": "AIの生の応答",
  "model": "使用モデル",
  "prompt_version": "指示文の版",
  "gateway_log_id": "AI GatewayのログIDまたはnull"
}
```

`raw`やログIDの永続化方法は#08で決める。APIキー、画像のbase64、認証情報は保存・ログ出力しない。

## 地図用JSON（確定事項: #32 / #49 / #50）

エンドポイントは`GET /api/vending-machines`。webルートのauthグループに属し、ログインしていない場合は401(JSON)またはログイン画面へのリダイレクトになる。レスポンスの最上位は配列で、0件なら`[]`を返す。

```json
[
  {
    "id": 1,
    "latitude": 33.5903547,
    "longitude": 130.4017155,
    "maker": "メーカー名",
    "product_count": 3,
    "last_confirmed_at": "2026-10-18T06:00:00.000000Z"
  }
]
```

| 項目 | JSON型 | 規則 |
|---|---|---|
| id | integer | 自販機ID |
| latitude | number | 緯度。DBの小数7桁を数値で返す |
| longitude | number | 経度。DBの小数7桁を数値で返す |
| maker | string / null | 不明はnull |
| product_count | integer | `DISTINCT product_id`の件数。hot/coldの重複行を二重計上しない |
| last_confirmed_at | string | UTCのISO 8601形式 |

ID昇順で返す。

### 商品名での絞り込み（#49 / #50）

`?product_name=綾鷹`(100文字以内)を付けると、商品名の部分一致でその商品を売っている自販機だけを返し、各要素に`matched_products`を加える。空文字・空白だけなら絞り込まない。

```json
"matched_products": [
  {
    "machine_product_id": 12,
    "product_id": 3,
    "product_name": "綾鷹",
    "volume_ml": 525,
    "price": 140,
    "temperature": "cold",
    "recorded_at": "2026-10-23T03:00:00.000000Z",
    "days_ago": 1
  }
]
```

| 項目 | JSON型 | 規則 |
|---|---|---|
| machine_product_id | integer | 中間行ID。hot/coldの2行を区別するキー |
| product_id | integer | 商品ID |
| product_name | string | 商品名 |
| volume_ml | integer / null | 不明はnull |
| price | integer | 円 |
| temperature | hot / cold / null | 不明はnull |
| recorded_at | string | UTCのISO 8601形式 |
| days_ago | integer | 記録日からの経過日数。0以上 |

`matched_products`は価格の安い順(同額は中間行ID順)に並ぶ。`days_ago`は現在アプリのタイムゾーン(UTC)の日付で数えており、共通規則の「表示は日本時間」とずれる(既知の差分。日本時間の0〜9時に1日ずれる)。

## 画面の実装方式（確定事項: 現行の実装）

入力、検証、送信、状態変化を伴う画面はVolt(Livewire)で実装する。表示だけの画面はコントローラとBlade、JSON APIはコントローラを使う。既存画面を方式統一だけのために書き換えない。

| 画面・API | 方式 | ルート |
|---|---|---|
| 写真投稿・確認・登録 | Volt `posts.create` | `/posts/create` |
| 地図 | Blade(地図JSONを読み込む) | `/map` |
| 地図用JSON | `Api\VendingMachineController` | `/api/vending-machines` |
| 自販機詳細 | `VendingMachineController` | `/vending-machines/{id}` |
| レア商品 | `RareProductController` | `/rare-products` |
| 総合ランキング | `RankingController` | `/ranking` |
| 質問(RAG) | Volt `questions.ask`(レビュー中: PR #133) | `/ask` |

すべてログイン必須。

## 開発環境（確定事項）

- ローカル開発はDocker上のLaravel Sailを使用する。
- 開発DBはSailのMySQL 8系を使用する。
- DBの確認には`http://localhost:8080`のphpMyAdminを使用する。
- `.env`はコミットしない。共有可能なキー名と安全な初期値だけを`.env.example`へ記載する。
- `.env.example`のMySQL接続値の統一と全員のマイグレーション確認は#14で行う。

## 仕様変更の手順

テーブル、AI JSON、地図JSONなど、複数担当間の受け渡し形式を変える場合は、実装より先にこのファイルの変更PRを作る。変更PRには次を記載する。

1. 変更対象
2. 変更前
3. 変更後案
4. 変更理由
5. 影響範囲

未確定事項や検討候補は、レビューで合意されるまで確定事項へ移さない。

## ダミーデータと実データへの差し替え

#27のデータは開発用。架空の自販機・価格・ポイントを実在情報として公開しない。開発用シーダーを明示実行する形にし、本番で自動実行しない。実データ投入前に、開発用DBを分離するか、投入履歴で特定したダミーだけを除去する。実データを巻き込む一括削除・本番migrate:freshは禁止する。

#26の商品マスタは実商品を調査したデータとして出典を記録する。調査未了の値をダミーで埋めた場合は、その項目と差し替え対象を一覧に残す。#79のデモアカウントと#27のユーザー作成が重複しないよう、既存データを確認する。

## 受入確認と実装順序

1. 作業直前にmainと既存PRを再確認し、本書と重なる変更を確認する。
2. 本書(v1.0)のマージをもって#03のチーム採用とする。以後の変更は「仕様変更の手順」に従う。
3. #20〜25・#32はmainに統合済み。重複するマイグレーションやモデルを作らない。
4. MySQL 8でマイグレーション、外部キー、NULL、CHECK、削除動作、座標7桁の保存を確認する。
5. 同一自販機の同一商品hot/coldに異なる価格を保存し、両方向から価格・温冷・記録日時を取得する。
6. ユーザーから登録自販機・ポイント履歴を取得し、ユーザー別合計を確認する。退会後の自販機維持も確認する。
7. 既存の認証・プロフィール・AI読み取りのテストを通し、既存機能への影響を確認する。

本書は実装状況を記録するが、各Issueの完了やOpen PRの採用を宣言する文書ではない。レビュー中の内容はマージ後にmainとの一致を再確認する。

## 保留・将来Issue

- #08: 読み取りの出どころと修正履歴。過去価格の完全な監査方式は本書で確定しない。
- #10: 非表示状態・撤去報告、各検索からの除外、#46での復帰。
- #53〜55: 点数・付与の重複防止・7日判定。総合ランキングはこれが入るまで空になる。
- `question_logs`: PR #134のマージ後に追記する。
- 既知の差分: makerの表示名変換、`days_ago`の日本時間化、商品名の表記ゆれの整理。
- #69: 投稿写真の他利用者への公開可否。非公開保存と期限付きURLだけで公開可否の承認済みとは扱わない。

既定事項を変える必要が生じた場合は、変更対象／変更前／変更後案／変更理由／影響範囲を示す。未記載の実装細部の補完と、確定事項の変更を混同しない。

## 修正履歴

- v0.1（2026-10-17）: #20〜25を中心とした下書きを作成。
- v0.2（2026-10-19）: mainへ統合済みの#21〜24を反映。AI読み取りJSON、PR #108の地図JSON、画面方式の検討候補、開発環境、仕様変更手順を追加。確定事項・レビュー中・未確定事項・検討候補を区別。
- v0.2（2026-10-20）: PR #105のマージを反映し、#25のモデルをレビュー中からmain統合済みへ更新。確認したmainのコミットを更新。
- v1.0（2026-10-24）: main `bfd364c`の実装に合わせて確定版とした。#17のAI JSON、#32の地図JSONと#49・#50の絞り込み、#44・#45の照合と登録、PR #135の容量任意化、#51単価、#52レア基準、#56総合ランキング、画面方式の一覧を確定事項として反映。makerの識別子保存と`days_ago`のUTC計算を既知の差分として記載。

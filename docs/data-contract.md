# CanMatch 共通データ仕様 下書き v0.1

作成日: 2026-10-17（日本時間）

## 位置付けと根拠

- 原典: ユーザー提供「自販機マップ 仕様書とIssue一覧」2026年10月11日版全文。
- 追加決定: 4人分担を維持する。B担当は#25を優先し、C不参加時にも採用できる精度で#21〜24の補完を準備する。
- 追加決定: 2026-10-17、ユーザーが前のカラム案に基づく下書き作成を承認。原典未記載の細部は正常動作を優先して補完し、理由を明記する。
- リポジトリ: https://github.com/fuwa-shacho/canmatch
- 直近確認したmain: `a7e328b9d8d846d85640a017dd54f156138cdd27`（2026-10-18確認）。これは確認時点の記録であり、今後の作業前に更新確認する。
- 本書は#03のうち#20〜25に関係する部分の下書き。チームで採用・マージ済みの正典ではない。AI JSON、地図JSON、画面構成まで含む#03全体の完了は意味しない。
- #20の一意制約はPR #79でmainに統合済み。重複するマイグレーションを作らず、後続実装では実際の内容と整合させる。
- PR #82（#31）はレビュー待ちとして扱い、本書の作成は変更・マージの許可を意味しない。

## RAG設計への参照

- RAGの検索・回答生成に関する詳細仕様は [RAG詳細設計](rag-design.md) を参照する。
- システム全体の構成と各コンポーネントの責務は [アーキテクチャ概要](architecture.md) を参照する。
- RAGの根拠行の形式や検索条件はRAG詳細設計を正とし、本書では重複定義しない。
- `description` はRAG設計で使用するが、`products` テーブルへの追加はIssue #62で扱う。
- `question_logs` の正式なDBスキーマとログ保持期間は未確定であり、本書では定義しない。

## 共通規則

開発DBはSailのMySQL 8系。IDはLaravelの`id()`と対応するunsigned BIGINT、外部キーは`foreignId()`を使用する。価格は整数の円、容量は整数のml。NULLは不明または該当なしを表し、0や推測値で代用しない。任意文字列の空文字はNULLに正規化する。

日時はUTCで保存し、画面表示と週間ランキングの月曜0時の境界はAsia/Tokyoで計算する。週境界をUTCに変換してDBを検索する。この時刻規則は原典未記載の実装補完である。

DB制約で外部キー・必須・値の範囲を守り、フォームでも同じ検証を行う。ここに書かれた検証は実装時の受入条件であり、まだ検証済みではない。

## #20 users

既存スターターキットのテーブルを使用し、`name`に一意制約を追加する。email/passwordの保存方式を変更しない。既存DBに重複nameがあれば、移行を停止して対象を報告する。自動改名・削除はしない。登録・プロフィール変更の入力検証は#29の既存PRと整合させる。

## #21 vending_machines

| カラム | DB定義 | 意味・規則 |
|---|---|---|
| id | unsigned BIGINT primary | 自販機ID |
| latitude | DECIMAL(10,7) NOT NULL | -90〜90、入力の小数は最大7桁 |
| longitude | DECIMAL(10,7) NOT NULL | -180〜180、入力の小数は最大7桁 |
| maker | VARCHAR(255) NULL | メーカー。不明はNULL |
| photo_path | VARCHAR(1024) NOT NULL | 保存先ディスク内の相対パス。期限付きURLを保存しない |
| user_id | unsigned BIGINT NULL FK users.id | 登録者。ユーザー削除時SET NULL |
| last_confirmed_at | DATETIME NOT NULL | 確認して確定登録した日時 |
| created_at / updated_at | Laravel標準timestamps | 作成・更新日時 |

新規登録時のuser_idは認証済みユーザーのIDをサーバーで設定し、リクエスト値を信用しない。NULL許可は退会後も自販機情報を残すためで、匿名投稿の許可ではない。退会後の表示は「退会済みユーザー」とする。更新者で登録者を書き換えない。

写真パスは設定済みの写真ディスクで解決する。ディスク変更時は既存写真の移行も伴わせる。読めることを確認した保存パスを使い、ダミーのパスで本番登録を成立させない。

非表示フラグと報告履歴は#10で追加する。撤去報告で物理削除しない。

## #22 products

| カラム | DB定義 | 規則 |
|---|---|---|
| id | unsigned BIGINT primary | 商品ID |
| name | VARCHAR(255) NOT NULL | 空白のみは不可。前後空白を除く |
| maker | VARCHAR(255) NULL | 不明はNULL |
| volume_ml | unsigned INTEGER NULL | 既知なら1以上。不明はNULL |
| category | VARCHAR(255) NULL | 不明はNULL |
| created_at / updated_at | Laravel標準timestamps | 作成・更新日時 |

nameに検索用インデックスを設ける。ただし前方ワイルドカードの部分一致が高速化されるとは保証しない。name単独の一意制約は付けない。容量違いは別商品とする。メーカー・カテゴリをNULL可とするのは、読み取りで商品名しか得られない場合に不明情報を推測せず保存するため。

商品名が完全一致しても、容量違いなどで複数候補があるときは先頭の1件を自動選択しない。#44ではユーザーに候補選択を求める。これは「容量違いは別商品」と「完全一致は自動紐づけ」を両立させる補完である。照合の詳細は#44で実装する。

descriptionの追加は#62で扱う。

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

現在の商品構成を持つ。#46では対象自販機の行をトランザクション内で入れ替える。過去の読み取り・根拠は#08の記録と分ける。価格が不明な下書きは許容するが、確定登録前に入力を求める。温冷が不明ならNULLで保存し、画面では「不明」と表示する。

同じ商品のhot/coldを両方保存できるため、自販機ID＋商品IDだけの一意制約を付けない。同一の自販機・商品・温冷の重複は登録処理で拒否し、更新時は対象自販機行のロックを取って同時更新を直列化する。NULLも同一の温冷状態として比較する。温冷ごとに価格が異なる場合も各1行で保存する。

Eloquent標準の`sync()`/`updateExistingPivot()`は商品ID単位で操作するため、hot/coldが共存する書き込みには使用しない。入れ替えは対象自販機の行を削除して確認済みの全行を挿入し、個別操作が必要なら中間行IDで指定する。これは同じ商品を温冷両方で売る場合の消失・上書きを防ぐ実装補完である。

商品数とレア判定の台数はそれぞれDISTINCTの商品ID・自販機IDを数え、温冷2行を2商品・2台と数えない。

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

付与の二重実行防止、登録・更新とポイントの同一トランザクション、7日判定、点数確定は#45・#53〜55で実装する。本書だけで二重付与防止が完了したとは扱わない。

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

## ダミーデータと実データへの差し替え

#27のデータは開発用。架空の自販機・価格・ポイントを実在情報として公開しない。開発用シーダーを明示実行する形にし、本番で自動実行しない。実データ投入前に、開発用DBを分離するか、投入履歴で特定したダミーだけを除去する。実データを巻き込む一括削除・本番migrate:freshは禁止する。

#26の商品マスタは実商品を調査したデータとして出典を記録する。調査未了の値をダミーで埋めた場合は、その項目と差し替え対象を一覧に残す。#79のデモアカウントと#27のユーザー作成が重複しないよう、既存データを確認する。

## 受入確認と実装順序

1. 作業直前にmainと既存PRを再確認し、本書と重なる変更を確認する。
2. #03の本書対象部分を先にレビュー可能にする。GitHubでのチーム採用は未完了。
3. #20はPR #79でmainに統合済みのため重複作成しない。#21、#22を先に、次に#23、#24、最後に#25を実装する。Issueごとの変更範囲を保つ。
4. MySQL 8でマイグレーション、外部キー、NULL、CHECK、削除動作、座標7桁の保存を確認する。
5. 同一自販機の同一商品hot/coldに異なる価格を保存し、両方向から価格・温冷・記録日時を取得する。
6. ユーザーから登録自販機・ポイント履歴を取得し、ユーザー別合計を確認する。退会後の自販機維持も確認する。
7. 既存の認証・プロフィール・AI読み取りのテストを通し、既存機能への影響を確認する。

本書作成時点ではコード変更、DB移行、テスト実行、GitHubへの書き込みは行っていない。#21〜25の完了を宣言する文書ではない。

## 保留・将来Issue

- #08: 読み取りの出どころと修正履歴。過去価格の完全な監査方式は本書で確定しない。
- #10: 非表示状態・撤去報告、各検索からの除外、#46での復帰。
- #17: AIのJSON・モデル・精度の確定。DBのNULL規則をAIの推測許可にしない。
- #32: 地図JSONの具体的な契約。小数のJSON表現はここで決める。
- #52〜55: レア基準・点数・付与の重複防止。
- #62: 商品説明。
- #69: 投稿写真の他利用者への公開可否。非公開保存と期限付きURLだけで公開可否の承認済みとは扱わない。

既定事項を変える必要が生じた場合は、変更対象／変更前／変更後案／変更理由／影響範囲を示す。未記載の実装細部の補完と、確定事項の変更を混同しない。

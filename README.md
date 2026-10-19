# canmatch

街の商品発見・情報検証プラットフォーム

## 開発環境の起動

開発環境にはLaravel Sail（Docker）を使用します。WindowsではDocker DesktopとWSL2を用意し、リポジトリはWSLのホームディレクトリ配下へcloneしてください。Cドライブを`/mnt/c`経由で使う配置は、ファイルアクセスが遅くなるため避けます。

### 初回セットアップ

リポジトリをcloneしたディレクトリで、次の順に実行します。

```bash
cp .env.example .env

docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php85-composer:latest \
    composer install --ignore-platform-reqs

./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm install
./vendor/bin/sail npm run dev
```

`sail npm run dev`は開発中、そのまま起動しておきます。別のターミナルで次を確認してください。

- アプリ: <http://localhost>
- phpMyAdmin: <http://localhost:8080>

phpMyAdminには`.env`の`DB_USERNAME`と`DB_PASSWORD`でログインします。APIキーなどの秘密情報を設定した`.env`はコミットしません。

### 2回目以降

```bash
./vendor/bin/sail up -d
./vendor/bin/sail npm run dev
```

終了するときは、開発サーバーを`Ctrl+C`で止めてから次を実行します。

```bash
./vendor/bin/sail down
```

依存パッケージやマイグレーションが追加された場合は、必要に応じて次も実行します。

```bash
./vendor/bin/sail composer install
./vendor/bin/sail npm install
./vendor/bin/sail artisan migrate
```

## 開発ルール

### ブランチ

- `main`へ直接pushしない。
- 作業前に最新の`main`を取り込んでから、Issueごとのブランチを作る。
- 機能追加は`feature/<Issue番号>-<短い説明>`、不具合修正は`fix/<Issue番号>-<短い説明>`とする。
- 例: `feature/24-point-logs`、`fix/28-nav-structure`

### Issueとプルリクエスト

- 原則として、1つのIssueに対して1つの小さなプルリクエストを作る。
- 依存するプルリクエストが未マージの場合は、変更を重ねず、依存関係と取り込み順を本文に書く。
- Issueの完了条件をすべて満たす場合だけ、本文に`Closes #Issue番号`を書く。
- Issueの一部だけを扱う場合や、後続作業が残る場合は`Refs #Issue番号`と書く。
- GitHub Actionsの成功と1人以上のレビューを確認してからマージする。
- Actionsが使えない場合でも、実行した確認と結果をプルリクエスト本文に残す。

### 変更内容の確認

- AIが生成したコードも、プルリクエストを出す本人が差分を読み、動作とテスト結果を確認する。
- 関係のない整形やリファクタリングを同じプルリクエストへ混ぜない。
- 未確認事項、実機でだけ確認できる項目、後続Issueへ残す項目を明記する。
- ダミーデータを使う場合は開発用と明記し、本番用の実データへ差し替えられる状態にする。

### ラベルとマイルストーン

- Issueには作業内容に対応するラベルを付ける。使用する分類は`team`、`setup`、`db`、`ui`、`map`、`post`、`ai`、`search`、`ranking`、`rag`、`release`とする。
- 計画上のマイルストーンは`M0`〜`M8`と`MT`を使用する。
- ラベルやマイルストーンを追加・変更する場合は、既存Issueへの影響を確認してチームへ共有する。

### 変更してはいけないもの

- `.env`、APIキー、パスワードなどの秘密情報をコミットしない。
- 本番データを開発作業で変更・削除しない。
- `compose.yaml`の変更は全員の開発環境に影響するため、変更理由と移行手順をプルリクエストに書く。

詳しいAI作業ルールと確認コマンドはIssue #4で`AGENTS.md`に追加する。起動手順はIssue #12、完成版のプロジェクト説明とデプロイ手順はIssue #73で整備する。

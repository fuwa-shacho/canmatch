# 公開環境とデプロイ手順

Issue #18 で決めた公開環境と、公開(デプロイ)・バックアップ・元に戻す手順をまとめる。

## 公開環境

| 項目 | 内容 |
| --- | --- |
| 公開先 | Laravel Cloud(リージョン:東京) |
| アプリ / 環境 | `canmatch` / `production` |
| URL | チーム内でだけ共有する(READMEやIssueには書かない) |
| PHP | Laravel Cloud の Settings → General で選んだバージョン(**要確認**:開発環境の Sail は 8.5、CI は 8.4) |
| データベース | Laravel MySQL(Flex 512MiB、ストレージ 5GB、使われていない間は休止) |
| バックアップ | 自動バックアップ(毎日)、保持期間 7日 |
| 写真の保存先 | Cloudflare R2 の本番用バケット `canmatch-prod` |
| 使用上限 | 月 $10(上限に達したらアプリを停止する設定) |

Sail は開発用なので、本番には使わない。

## 公開を判断する人

- **公開を判断し、Deploy ボタンを押す人:annhwiseong**(Laravel Cloud のアカウントを管理しているため)
- main へのマージと公開は別の操作にする。Laravel Cloud の「Push to deploy」はオフにしてあり、main にマージしただけでは公開されない。
- 公開したいプルリクエストがあるときは、チームのチャットで公開を判断する人に依頼する。

## 本番の環境変数

Laravel Cloud の `production` 環境 → Settings → Environment variables で設定する。値はチャットやIssueに貼らない。

| 種類 | 変数 | 本番の値 |
| --- | --- | --- |
| アプリ | `APP_ENV` | `production` |
| | `APP_DEBUG` | `false` |
| | `APP_URL` | 公開URL |
| データベース | `DB_*` | Laravel Cloud が自動で入れる(手で設定しない) |
| 写真の保存 | `PHOTOS_DISK` | `r2` |
| | `R2_ACCESS_KEY_ID` / `R2_SECRET_ACCESS_KEY` | 本番専用のトークン(`canmatch-prod` だけを読み書きできる) |
| | `R2_BUCKET` | `canmatch-prod`(開発用の `canmatch-dev` を使わない) |
| | `R2_ENDPOINT` | R2 のエンドポイント |
| 自販機の読み取り | `GEMINI_API_KEY` ほか `GEMINI_*` | `.env.example` と同じ名前で設定する |
| RAG | `CLOUDFLARE_ACCOUNT_ID` / `CLOUDFLARE_API_TOKEN` ほか `CF_*` | `.env.example` と同じ名前で設定する |

- 環境変数を変えたら、**Deploy し直す**(設定はビルド時に読み込まれるため、Deploy しないと反映されない)。
- 秘密情報が読み込まれているかは、値を表示せずに確認する(例:Commands で `php artisan tinker --execute="var_dump(filled(config('services.gemini.key')));"`)。

## 公開の手順

1. 公開するプルリクエストが main にマージされ、GitHub Actions が成功していることを確認する。
2. 公開するプルリクエストに `database/migrations` の変更があるかを確認する。
   - ある場合は、先に「[マイグレーションの前にバックアップを取る](#マイグレーションの前にバックアップを取る)」を行う。
3. Laravel Cloud → `canmatch` → `production` を開き、**Deploy** を押す。
4. Deployments 画面で、Build と Deploy がどちらも成功したことを確認する。
   - ビルドで実行すること:`composer install --no-dev`、`npm ci`、`npm run build`、`php artisan optimize`
   - デプロイで実行すること:`php artisan migrate --force`
5. スマホで公開URLを開き、次を確認する。
   - トップページが HTTPS で開ける
   - 地図が表示され、「現在地」ボタンが動く
   - 今回の変更に関係する画面が動く
6. 結果をチームのチャットに書く(公開したコミット、確認したこと)。

### 初めて公開したときだけ行うこと

本番の商品マスタを入れる。

```bash
php artisan db:seed --class=ProductSeeder --force
```

- `--class=ProductSeeder` を必ず付ける。付けずに `db:seed` を実行すると、パスワードが `password` のデモ用アカウントが本番に入ってしまう。
- 本番のデータベースにダミーデータを入れない。

RAG を使う場合は、商品を登録したあとに検索用の索引を作る(商品を変えたときも実行する)。

```bash
php artisan rag:reindex
```

コマンドは Laravel Cloud の `production` → Commands から実行する。

## マイグレーションの前にバックアップを取る

自動バックアップは毎日取られるが、公開の直前の状態に戻せるように、マイグレーションを含む公開の前には手動でバックアップを取る。

1. Laravel Cloud → Organization → Resources → Databases を開き、本番のデータベースクラスターを選ぶ。
2. Backups の **New backup** を押す。
3. 名前を付けて **Create backup** を押す。
   - 名前の例:`before-pr-123-20261020`(プルリクエスト番号と日付)
4. 一覧に作ったバックアップが表示され、完了していることを確認してから Deploy する。

バックアップの保存にも料金がかかる(東京リージョンは $0.12/GB・月)。不要になった手動バックアップは削除する。

## 元に戻す手順

### コードだけを戻す(マイグレーションがない場合)

1. GitHub で、問題のあったプルリクエストの **Revert** を押し、元に戻すプルリクエストを作る。
2. レビューを受けて main にマージする。
3. Laravel Cloud で **Deploy** し、スマホで動きを確認する。

### マイグレーションも戻す

マイグレーションが列やテーブルを追加しただけで、データが消えない場合は、直前のマイグレーションを取り消してからコードを戻す。

1. Laravel Cloud の Commands で、取り消すマイグレーションを確認する。
   ```bash
   php artisan migrate:status
   ```
2. 直前のマイグレーションを取り消す。
   ```bash
   php artisan migrate:rollback --step=1 --force
   ```
   `--step` には、今回の公開で増えたマイグレーションの数を指定する。
3. 「コードだけを戻す」の手順でコードを戻して Deploy する。
   - 順番に気を付ける。マイグレーションを取り消さずにコードだけ戻しても、追加した列やテーブルは本番に残る。
   - 取り消してから Deploy するまでの間は、新しいコードが消えた列を使ってエラーになることがあるので、続けて作業する。

### バックアップからデータベースを戻す

データが消えた、壊れたなど、マイグレーションの取り消しでは直らない場合に行う。Laravel Cloud のバックアップは、今のデータベースを上書きせず、**新しいデータベースクラスターとして復元される**。

1. Laravel Cloud → Organization → Resources → Databases → 本番のクラスターを開く。
2. Backups で戻したいバックアップ(公開前に取った手動バックアップ)の **…** → **Restore** を押す。
3. 新しいクラスターの名前を付けて **Restore backup** を押す。
   - 名前の例:`canmatch-restore-20261020`
4. 復元が終わったら、`production` 環境の画面で、今のデータベースを外し、復元したクラスターのデータベースを付け直す。
5. **Deploy** し直す(データベースの接続先は Deploy しないと切り替わらない)。
6. スマホで動きを確認する。
7. 問題がなければ、元のクラスターは削除する(残すと2つ分の料金がかかり、使用上限 $10 に近づく)。
   - 元のクラスターから必要なデータを取り出したい場合は、削除する前に行う。

## 費用と注意

- 使用上限は $10(クレジットを差し引く前の金額)。上限に達するとアプリが停止する。
- データベースは5分使われないと休止し、次のアクセスで自動で起きる(最初の表示が少し遅くなる)。
- 本番の URL はチーム内だけで共有する。
- 撮影した写真は Gemini の無料枠で読み取るため、顔やナンバープレートが写らないようにする。

# 商品マスタの出典

Issue #26の商品マスタは、架空の開発用ダミーデータではなく、メーカーの公式情報を2026年10月18日に確認して作成した実商品データです。

価格、温冷、自販機ごとの在庫は商品マスタに含めません。それらは実際の自販機を確認した後、`machine_products`へ登録します。商品名や容量がメーカー側で変更された場合は、この出典を再確認してシーダーを更新します。

## 確認した公式情報

- アサヒ飲料「自販機投入可能商品」: https://www.asahiinryo.co.jp/products/vending/
  三ツ矢、ウィルキンソン、十六茶、ワンダ各商品の商品名と容量を確認。
- 日本コカ・コーラ「コカ・コーラ対象製品」: https://www.coca-cola.com/jp/ja/brands/coca-cola/campaign/2026summer
  コカ・コーラ、コカ・コーラ ゼロの500ml製品を確認。
- 日本コカ・コーラ「綾鷹」: https://www.coca-cola.com/jp/ja/brands/ayataka
  綾鷹650ml製品を確認。
- 日本コカ・コーラ「100%リサイクルPETボトル対象製品」: https://www.coca-cola.com/jp/ja/media-center/news-20210513-10
  アクエリアス500ml、い・ろ・は・す天然水560mlを確認。
- サントリー「GREEN DA・KA・RA」: https://www.suntory.co.jp/softdrink/greendakara/
  GREEN DA・KA・RA 600ml、やさしい麦茶680mlを確認。
- サントリー「緑茶 伊右衛門 600mlペット」: https://products.suntory.co.jp/d/4901777300446/
  商品名と容量を確認。
- キリン「原材料名・栄養成分等一覧」: https://products.kirin.co.jp/softdrink/nutrition/softdrink/
  午後の紅茶、生茶、生茶カフェインフリーの商品名と容量を確認。
- 伊藤園「健康ミネラルむぎ茶 PET 600ml」: https://www.itoen.jp/products/41162/
  商品名と容量を確認。

## 更新時の注意

- 実在確認できない値を推測で追加しない。
- 同じ商品名でも容量が違う場合は別の商品として登録する。
- `ProductSeeder`は実商品マスタ専用とし、#27の架空の自販機・価格・ポイントは別の開発用シーダーに置く。
- 本番データを消す処理や`migrate:fresh`はシーダーに含めない。

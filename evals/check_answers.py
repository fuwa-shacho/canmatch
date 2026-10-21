"""evals/answers.json の形式と、写真との対応を確認する

問題なしなら終了コード0、エラーまたは正解未記入の写真があれば終了コード1。
"""
import json
import sys
from pathlib import Path

root = Path(__file__).parent
data = json.loads((root / "answers.json").read_text(encoding="utf-8"))
photos_dir = root / "photos"
errors = []
covered = set()
for m in data["machines"]:
    mid = m["id"]
    seen = set()
    total = 0
    for p in m["products"]:
        key = (p.get("name"), p.get("price"), p.get("temperature"))
        if key in seen:
            errors.append(f"[{mid}] 同じ商品・価格・温冷が重複しています(count にまとめる): {key}")
        seen.add(key)
        if not isinstance(p.get("price"), int) or p["price"] <= 0:
            errors.append(f"[{mid}] 価格が正の整数ではありません: {p}")
        if p.get("temperature") not in ("hot", "cold", None):
            errors.append(f"[{mid}] temperature が hot / cold / null ではありません: {p}")
        count = p.get("count", 1)
        if not isinstance(count, int) or count < 1:
            errors.append(f"[{mid}] count が1以上の整数ではありません: {p}")
        else:
            total += count
    if total != m["slots"]:
        errors.append(f"[{mid}] count の合計 {total} が slots {m['slots']} と一致しません")
    for photo in m["photos"]:
        name = photo["file"]
        if name in covered:
            errors.append(f"写真が複数の自販機に書かれています: {name}")
        covered.add(name)
        if not (photos_dir / name).exists():
            errors.append(f"写真がありません: evals/photos/{name}")
actual = {f.name for f in photos_dir.glob("*.jpg")}
pending = sorted(actual - covered)
for e in errors:
    print("NG:", e)
if pending:
    print("NG: 正解が未記入の写真:", ", ".join(pending))
summary = f"自販機 {len(data['machines'])} 台、写真 {len(covered)} 枚を確認"
if errors or pending:
    print(f"{summary}(エラー {len(errors)} 件、正解未記入の写真 {len(pending)} 枚)")
    sys.exit(1)
print(f"{summary}(問題なし)")

<?php

namespace App\Console\Commands;

use App\Exceptions\AiReadFailedException;
use App\Services\Evals\ReaderScorer;
use App\Services\VendingMachineReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * evals/ の写真をすべて AI で読み取り、正解と比べて正解率を出す(Issue 07)
 *
 * 例:
 *   ./vendor/bin/sail artisan eval:reader
 *   ./vendor/bin/sail artisan eval:reader --photo=09_suntory.jpg --photo=10_dydo.jpg
 *   ./vendor/bin/sail artisan eval:reader --model=gemini-3.5-flash --dir=evals/photos-2000
 */
class EvalReaderCommand extends Command
{
    protected $signature = 'eval:reader
        {--answers=evals/answers.json : 正解ファイル(プロジェクトからの相対パス)}
        {--dir=evals/photos : 写真のフォルダ(縮小サイズを比べるときに変える)}
        {--photo=* : 指定した写真だけを読む(ファイル名。複数指定できる)}
        {--model= : 使うモデル(省略時は GEMINI_MODEL)}
        {--prompt= : 使う指示文(省略時は GEMINI_PROMPT)}
        {--sleep=5 : 写真ごとの待ち時間(秒)。無料枠の1分あたりの上限を超えないため}
        {--save= : 結果の JSON の保存先(省略時は storage/app/evals/ に日時の名前で保存)}
        {--details : 外れた正解の行と、正解にない読み取りを写真ごとに表示する}';

    protected $description = '評価用の写真を AI で読み取り、商品名・価格・温冷の正解率を出す';

    public function handle(VendingMachineReader $reader, ReaderScorer $scorer): int
    {
        if ($this->option('model')) {
            config(['services.gemini.model' => $this->option('model')]);
        }
        if ($this->option('prompt')) {
            config(['services.gemini.prompt' => $this->option('prompt')]);
        }

        $config = config('services.gemini');
        if (empty($config['key']) || empty($config['model'])) {
            $this->error('GEMINI_API_KEY と GEMINI_MODEL を .env に設定してください。');

            return self::FAILURE;
        }
        if (! File::exists(resource_path("prompts/{$config['prompt']}.md"))) {
            $this->error("指示文がありません: resources/prompts/{$config['prompt']}.md");

            return self::FAILURE;
        }

        $answersPath = base_path($this->option('answers'));
        $photoDir = base_path($this->option('dir'));
        if (! File::exists($answersPath)) {
            $this->error("正解ファイルがありません: {$this->option('answers')}");

            return self::FAILURE;
        }

        $answers = json_decode(File::get($answersPath), true);
        if (! is_array($answers) || ! is_array($answers['machines'] ?? null)) {
            $this->error('正解ファイルの形が想定と違います(machines がありません)。');

            return self::FAILURE;
        }

        $targets = $this->targets($answers['machines'], $this->option('photo'));
        if ($targets === []) {
            $this->error('読む写真がありません。--photo のファイル名を確認してください。');

            return self::FAILURE;
        }

        // 写真のフォルダを、読み取りサービスが使えるディスクとして登録する
        config(['filesystems.disks.eval_photos' => ['driver' => 'local', 'root' => $photoDir]]);

        $this->info(sprintf(
            'モデル: %s / 指示文: %s / 写真: %d 枚(%s)',
            $config['model'], $config['prompt'], count($targets), $this->option('dir'),
        ));
        $this->line('1枚につき1リクエスト(429 のときだけ1回再試行)を使います。');

        $sleep = max(0, (int) $this->option('sleep'));
        $results = [];

        foreach ($targets as $i => [$machine, $file]) {
            if ($i > 0 && $sleep > 0) {
                sleep($sleep);
            }

            $this->output->write("[{$file}] 読み取り中… ");
            $started = microtime(true);
            $entry = ['file' => $file, 'machine' => $machine['id']];

            if (! File::exists("{$photoDir}/{$file}")) {
                $entry['error'] = 'photo_not_found';
                $this->warn('写真がありません');
                $results[] = $entry;

                continue;
            }

            try {
                $read = $reader->read($file, 'eval_photos');
                $entry['seconds'] = round(microtime(true) - $started, 1);
                $entry['is_vending_machine'] = $read['result']['is_vending_machine'];
                $entry['maker'] = $read['result']['maker'];
                $entry['products'] = $read['result']['products'];
                $entry['score'] = $scorer->score($machine['products'], $read['result']['products']);
                $this->line("{$entry['seconds']} 秒");
            } catch (AiReadFailedException $e) {
                $entry['seconds'] = round(microtime(true) - $started, 1);
                $entry['error'] = $e->reason;
                $this->warn("失敗({$e->reason})");
            }

            $results[] = $entry;
        }

        $this->newLine();
        $this->printTable($results);
        $total = $this->printTotal($results);

        if ($this->option('details')) {
            $this->printDetails($results);
        }

        $savePath = $this->save($config, $results, $total);
        $this->newLine();
        $this->line("結果を保存しました: {$savePath}");

        $succeeded = count(array_filter($results, fn (array $r): bool => isset($r['score'])));

        return $succeeded > 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 読む写真と、その写真の正解(自販機)の組を作る
     *
     * @return array<int, array{0: array, 1: string}>
     */
    private function targets(array $machines, array $onlyPhotos): array
    {
        $targets = [];
        foreach ($machines as $machine) {
            foreach ($machine['photos'] ?? [] as $photo) {
                $file = $photo['file'] ?? null;
                if ($file === null || ($onlyPhotos !== [] && ! in_array($file, $onlyPhotos, true))) {
                    continue;
                }
                $targets[] = [$machine, $file];
            }
        }

        return $targets;
    }

    private function printTable(array $results): void
    {
        $rows = [];
        foreach ($results as $r) {
            if (! isset($r['score'])) {
                $rows[] = [$r['file'], $r['machine'], '失敗: '.$r['error'], '', '', '', '', '', ''];

                continue;
            }

            $s = $r['score']['strict'];
            $l = $r['score']['loose'];
            $rows[] = [
                $r['file'],
                $r['machine'],
                $this->rate($s['name'], $s['rows']).' / '.$this->rate($l['name'], $l['rows']),
                $this->rate($s['price'], $s['rows']).' / '.$this->rate($l['price'], $l['rows']),
                $this->rate($s['temperature'], $s['temperature_rows']).' / '.$this->rate($l['temperature'], $l['temperature_rows']),
                $this->rate($s['all'], $s['rows']).' / '.$this->rate($l['all'], $l['rows']),
                "{$s['extra']} / {$l['extra']}",
                "{$r['score']['null_price']} / {$r['score']['predicted']}",
                $r['seconds'],
            ];
        }

        $this->table(
            ['写真', '自販機', '商品名', '価格', '温冷', 'すべて一致', '正解にない読み取り', '価格を空にした数', '秒'],
            $rows,
        );
        $this->line('各列は「厳しめ / ゆるめ」。価格・温冷・すべて一致は、商品名が合った行の中で数えている。');
    }

    /**
     * 全写真の合計(正解の行の数で重み付けした正解率)を表示する
     */
    private function printTotal(array $results): array
    {
        $total = ['photos' => count($results), 'failed' => 0, 'predicted' => 0, 'null_price' => 0, 'null_temperature' => 0];
        foreach (ReaderScorer::MODES as $mode) {
            $total[$mode] = ['rows' => 0, 'name' => 0, 'price' => 0, 'temperature_rows' => 0, 'temperature' => 0, 'all' => 0, 'extra' => 0];
        }

        foreach ($results as $r) {
            if (! isset($r['score'])) {
                $total['failed']++;

                continue;
            }
            foreach (['predicted', 'null_price', 'null_temperature'] as $key) {
                $total[$key] += $r['score'][$key];
            }
            foreach (ReaderScorer::MODES as $mode) {
                foreach (array_keys($total[$mode]) as $key) {
                    $total[$mode][$key] += $r['score'][$mode][$key];
                }
            }
        }

        $this->newLine();
        $this->info('合計(読み取りに成功した写真の、正解の行すべて)');
        $this->table(['', '厳しめ', 'ゆるめ'], [
            ['商品名の正解率', $this->rate($total['strict']['name'], $total['strict']['rows']), $this->rate($total['loose']['name'], $total['loose']['rows'])],
            ['価格の正解率', $this->rate($total['strict']['price'], $total['strict']['rows']), $this->rate($total['loose']['price'], $total['loose']['rows'])],
            ['温冷の正解率', $this->rate($total['strict']['temperature'], $total['strict']['temperature_rows']), $this->rate($total['loose']['temperature'], $total['loose']['temperature_rows'])],
            ['すべて一致', $this->rate($total['strict']['all'], $total['strict']['rows']), $this->rate($total['loose']['all'], $total['loose']['rows'])],
            ['正解にない読み取り', (string) $total['strict']['extra'], (string) $total['loose']['extra']],
        ]);
        $this->line(sprintf(
            '写真 %d 枚(失敗 %d)/ 読み取った商品 %d 行 / 価格を空にした %d 行 / 温冷を空にした %d 行',
            $total['photos'], $total['failed'], $total['predicted'], $total['null_price'], $total['null_temperature'],
        ));

        return $total;
    }

    private function printDetails(array $results): void
    {
        foreach ($results as $r) {
            if (! isset($r['score'])) {
                continue;
            }
            $this->newLine();
            $this->info("[{$r['file']}] ゆるめで外れた正解の行");
            foreach ($r['score']['loose']['missed_rows'] ?: ['なし'] as $line) {
                $this->line("  - {$line}");
            }
            $this->info("[{$r['file']}] 正解にない読み取り(ゆるめ)");
            foreach ($r['score']['loose']['extra_rows'] ?: ['なし'] as $line) {
                $this->line("  - {$line}");
            }
        }
    }

    private function save(array $config, array $results, array $total): string
    {
        $path = $this->option('save')
            ? base_path($this->option('save'))
            : storage_path('app/evals/reader-'.now()->format('Ymd-His').'.json');

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'ran_at' => now()->toIso8601String(),
            'model' => $config['model'],
            'prompt' => $config['prompt'],
            'thinking_level' => $config['thinking_level'] ?? null,
            'photo_dir' => $this->option('dir'),
            'total' => $total,
            'results' => $results,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $path;
    }

    private function rate(int $hit, int $all): string
    {
        return $all === 0 ? '-' : sprintf('%d%%(%d/%d)', (int) round($hit / $all * 100), $hit, $all);
    }
}

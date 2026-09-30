<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * README の設定例に書いた「既定値」が、config/ の実際の既定値と食い違わないことを強制する Architecture テスト。
 *
 * README の設定例(```dotenv ブロック)は次の形で書いている。
 *
 *     # 受講生 1 人あたりの 1 日の送信上限（既定 5）
 *     AI_CHAT_DAILY_MESSAGE_LIMIT=5
 *
 * config/ai-chat.php の既定値だけが 20 → 5 に下げられ(decisions #247)、README が「既定 20」「=20」のまま
 * 残っていた(2026-09-28 の T-A-04 のレビューで判明、2026-10-01 に修正)。README の例をそのまま写すと
 * 上限が 20 に戻り、Gemini の無料枠(プロジェクト全体・モデルごとに 1 日 20 回)を受講生 1 人で使い切れてしまう。
 * 既定値を変えるのは config 側なので、README の更新漏れはレビューでしか気づけない。ここで機械的に止める。
 *
 * 検査すること:
 * 1. 「（既定 X）」で終わるコメント行の直後にある KEY=VALUE について、X が config/*.php の env('KEY', 既定値) と一致する
 * 2. VALUE が X と一致する(例の値が既定値と違うと、写した人が気づかず既定から外れる)
 * 3. README 全体で「既定」と書いた `#` コメント(見出しの `##` 以降は除く)が、すべて 1 の形で拾えている
 *    (半角かっこ・空行・字下げ・引用 `>` の中・`~~~` や別の言語名の囲みなど、どの表記ゆれでも件数が合わずに落ちる)
 * 4. config 側の既定値が 1 つに決まる(同じ KEY を複数の config が違う既定値で使う、
 *    または既定値が関数呼び出しなどのリテラルでない場合は、照合できないので失敗にする)
 *
 * 書式のきまり: 設定例は ```dotenv の囲みに書く。「（既定 X）」はコメント行の末尾に置き、後ろに文を続けない。
 * 値を示さない「既定」は `#` コメントに書かない。KEY=VALUE の行の後ろにコメントを書かない。
 * どれも検査 2 か 3 で落ちる。
 */
class ReadmeEnvDefaultArchitectureTest extends TestCase
{
    public function test_readme_env_defaults_match_config_defaults(): void
    {
        // Arrange: README の ```dotenv ブロックだけを取り出す(本文中の「既定」は対象外)
        $readme = (string) file_get_contents(base_path('README.md'));
        preg_match_all('/^[ \t]*```dotenv[^\n]*\R(.*?)^[ \t]*```/msu', $readme, $blocks);
        $dotenv = implode("\n", $blocks[1]);

        // 「（既定 X）」コメント + 直後の KEY=VALUE の組を拾う
        preg_match_all('/^[ \t]*# .*（既定 ([^）\n]+)）\R[ \t]*([A-Z0-9_]+)=(.*)$/mu', $dotenv, $pairs, PREG_SET_ORDER);

        // 1 件も拾えないと、書式が変わっただけで何も検査せずに通ってしまうので先に止める
        $this->assertNotEmpty($pairs, 'README の dotenv ブロックから「（既定 X）」+ KEY=VALUE の組を 1 件も拾えませんでした。書式が変わっていないか確認してください。');

        // config/*.php の env('KEY', 既定値) を集める。
        // $anyDefault: 既定値付きで呼ばれた回数 / $literalDefaults: そのうちリテラルの既定値(引用符は外す)
        $anyDefault = [];
        $literalDefaults = [];
        foreach (glob(config_path('*.php')) ?: [] as $file) {
            $source = (string) file_get_contents($file);

            preg_match_all("/env\\('([A-Z0-9_]+)'\\s*,/", $source, $calls);
            foreach ($calls[1] as $key) {
                $anyDefault[$key] = ($anyDefault[$key] ?? 0) + 1;
            }

            preg_match_all("/env\\('([A-Z0-9_]+)'\\s*,\\s*('[^']*'|\"[^\"]*\"|-?\\d+(?:\\.\\d+)?|true|false|null)\\s*\\)/", $source, $literals, PREG_SET_ORDER);
            foreach ($literals as [, $key, $default]) {
                $literalDefaults[$key][] = trim($default, "'\"");
            }
        }
        $violations = [];

        // Act
        $documentedComments = preg_match_all('/^[ \t>]*#(?!#).*既定/mu', $readme);
        if ($documentedComments !== count($pairs)) {
            $violations[] = "README の「既定」を含む # コメント {$documentedComments} 件のうち、照合できる形は ".count($pairs).' 件です。```dotenv の囲みの中で、「# 説明（既定 X）」の直後の行に KEY=VALUE を書いてください';
        }

        foreach ($pairs as [, $documented, $key, $example]) {
            $defaults = $literalDefaults[$key] ?? [];

            if (! isset($anyDefault[$key])) {
                $violations[] = "{$key}: config/ に env('{$key}', 既定値) が見つかりません";

                continue;
            }

            if (count($defaults) !== $anyDefault[$key] || count(array_unique($defaults)) !== 1) {
                $violations[] = "{$key}: config の既定値が 1 つに決まりません(複数の既定値、またはリテラルでない既定値があります)";

                continue;
            }

            if ($documented !== $defaults[0]) {
                $violations[] = "{$key}: README は「既定 {$documented}」、config の既定値は {$defaults[0]}";
            }

            // dotenv では値を引用符で囲んでもよいので、外してから比べる
            if (trim($example, " \t\"'") !== $documented) {
                $violations[] = "{$key}: README の設定例 {$key}={$example} が、書いてある既定値 {$documented} と違います";
            }
        }

        // Assert
        $this->assertEmpty(
            $violations,
            "README の設定例の既定値が config と食い違っています。config の既定値を変えたら README も直してください。\n"
            .implode("\n", $violations),
        );
    }
}

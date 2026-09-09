<?php

declare(strict_types=1);

namespace Database\Factories\Support;

/**
 * Factory 用の日本語ダミー文言を供給するヘルパ。
 *
 * faker の `ja_JP` ロケールには文章プロバイダ(Lorem)が無く、`fake()->sentence()` /
 * `paragraph()` / `realText()` は `faker_locale=ja_JP` でも英文を返す。そのため
 * 画面・証跡に英文が出ていた。ここでは用途ごとの文例プールを持ち、そこから選ぶ。
 *
 * 乱数は `fake()->randomElement()` を通す(seed 指定時の再現性を faker 側に任せるため)。
 * 文例は LMS の文脈(IT 資格学習)に合わせてあり、**用途ごとにメソッドを分ける**。
 * 汎用の「日本語の文」を1つ用意すると、設問と面談メモが同じ文面になって不自然になる。
 */
final class JaText
{
    /** 教材・資格・模試などの見出しに使う語 */
    private const TOPICS = [
        'データベース設計', 'ネットワークの基礎', 'セキュリティ対策', 'アルゴリズムとデータ構造',
        'システム開発工程', 'プロジェクト管理', 'クラウドサービスの活用', '要件定義の進め方',
        'テスト技法', '運用と保守', '情報倫理と法務', 'ハードウェアの基礎',
    ];

    /** 説明文・概要に使う文 */
    private const DESCRIPTIONS = [
        '試験範囲を体系的に整理し、頻出テーマを重点的に学習します。',
        '基礎から応用まで段階的に進められる構成になっています。',
        '実務でよくある場面を題材に、考え方の型を身につけます。',
        '過去問の傾向を踏まえ、つまずきやすい箇所を丁寧に解説します。',
        '短時間でも学習を継続できるよう、単元を細かく区切っています。',
        '演習と解説を交互に配置し、理解の定着を図ります。',
    ];

    /** 設問文の骨格(前半は TOPICS から差し込む) */
    private const QUESTION_FORMATS = [
        '%sに関する記述として、最も適切なものはどれか。',
        '%sの説明として正しいものはどれか。',
        '%sを進めるうえで、最初に行うべきことはどれか。',
        '%sの利点として適切なものはどれか。',
    ];

    /** 選択肢の本文 */
    private const OPTIONS = [
        '処理の重複を減らし、保守しやすい構成にする',
        '利用者の操作を記録し、後から追跡できるようにする',
        '想定される負荷に応じて資源を割り当てる',
        '関係者の合意を得たうえで次の工程へ進む',
        '影響範囲を限定してから変更を適用する',
        '例外的な条件を洗い出し、事前に対処方針を決める',
    ];

    /** 解説文 */
    private const EXPLANATIONS = [
        '選択肢のうち、目的と手段が対応しているのは1つだけです。ほかは手順の順序が入れ替わっています。',
        '定義そのものを問う設問です。用語の意味を正確に覚えているかが分かれ目になります。',
        '実務では例外処理の設計が抜けやすい箇所です。前提条件から確認してください。',
        '似た用語との違いを押さえておくと、応用問題でも判断できます。',
    ];

    /** チャットの発言 */
    private const CHAT_MESSAGES = [
        'お疲れさまです。先週の範囲を復習していて、正規化の手順で分からない箇所がありました。',
        'ご質問ありがとうございます。まずは第3正規形までの流れを図に書き出してみましょう。',
        '演習問題の3問目でつまずいています。考え方のヒントをいただけますか。',
        '承知しました。次回の面談までに該当章をもう一度読み込んでおきます。',
        '進捗の共有です。今週は目標としていた2章分を終えられました。',
        'よい進み方だと思います。この調子で来週は演習に時間を割いてみてください。',
    ];

    /** 面談の議題 */
    private const MEETING_TOPICS = [
        '学習計画の見直しと今後の進め方について',
        '模試の結果をふまえた弱点の整理',
        '受験日までのスケジュール確認',
        'つまずいている単元の質問と補足説明',
        '演習の進め方と時間配分の相談',
    ];

    /** 面談メモの本文 */
    private const MEETING_MEMOS = [
        '学習の進捗を確認しました。予定より少し遅れているため、章立ての優先順位を組み替えます。',
        '模試の誤答傾向を一緒に確認しました。次回までに該当単元の演習を進めてもらいます。',
        '受験日から逆算して残りの学習計画を調整しました。週あたりの学習時間も見直しています。',
        '理解が進んでいる単元と不安が残る単元を切り分けました。次回は後者を重点的に扱います。',
    ];

    /** 画像ファイル名(拡張子を除く)。ファイル名は ASCII に保つ */
    private const IMAGE_NAMES = [
        'er-diagram', 'network-topology', 'sequence-flow', 'screen-layout', 'class-diagram',
    ];

    /** 見出しに使う語を1つ返す（例: データベース設計） */
    public static function topic(): string
    {
        return fake()->randomElement(self::TOPICS);
    }

    /** 教材・模試などの短い見出し（例: データベース設計の基本） */
    public static function title(): string
    {
        return self::topic().fake()->randomElement(['の基本', 'の実践', 'を理解する', 'の要点整理', '入門']);
    }

    /** 説明文1文 */
    public static function description(): string
    {
        return fake()->randomElement(self::DESCRIPTIONS);
    }

    /** 説明文を複数つないだ段落 */
    public static function paragraph(int $sentences = 3): string
    {
        return implode('', fake()->randomElements(self::DESCRIPTIONS, min($sentences, count(self::DESCRIPTIONS))));
    }

    /** 段落を改行2つで区切って複数返す */
    public static function paragraphs(int $count = 2): string
    {
        $parts = [];
        for ($i = 0; $i < $count; $i++) {
            $parts[] = self::paragraph(2);
        }

        return implode("\n\n", $parts);
    }

    /** 設問文（例: ネットワークの基礎に関する記述として、最も適切なものはどれか。） */
    public static function questionBody(): string
    {
        return sprintf(fake()->randomElement(self::QUESTION_FORMATS), self::topic());
    }

    /** 選択肢の本文 */
    public static function optionBody(): string
    {
        return fake()->randomElement(self::OPTIONS);
    }

    /** 設問の解説 */
    public static function explanation(): string
    {
        return fake()->randomElement(self::EXPLANATIONS);
    }

    /** チャットの発言 */
    public static function chatMessage(): string
    {
        return fake()->randomElement(self::CHAT_MESSAGES);
    }

    /** 面談の議題 */
    public static function meetingTopic(): string
    {
        return fake()->randomElement(self::MEETING_TOPICS);
    }

    /** 面談メモの本文 */
    public static function meetingMemo(): string
    {
        return fake()->randomElement(self::MEETING_MEMOS);
    }

    /** 画像ファイル名（拡張子なし・ASCII） */
    public static function imageName(): string
    {
        return fake()->randomElement(self::IMAGE_NAMES);
    }
}

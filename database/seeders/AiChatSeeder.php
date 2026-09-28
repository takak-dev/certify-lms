<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * 開発用 AI 相談シーダー(S-A-02)。
 *
 * 原典 初期データが要求する 3 つを満たす。
 *   ① 固定の受講生に**複数の会話**を投入する（過去の相談の再開・履歴表示を確認）
 *   ② 会話の中に、**AI 応答がエラー状態のもの**を含める（エラー表示・同じ内容の再送を確認）
 *   ③ **教材から始めた会話・教材によらない会話の両方**を含める
 *
 * 固定の受講生は student@certify-lms.test(UserSeeder:93)。ChatSeeder:79 と同じ選び方。
 *
 * ⚠️ last_message_at を散らしてあるのは飾りではない。支給 Blade
 *    resources/views/ai-chat/show.blade.php:16-22 が履歴サイドバーを
 *    「今日 / 過去 7 日 / 過去 30 日」に振り分けるので、3 つの区分が埋まる日時を置く。
 *
 * ⚠️ Gemini は呼ばない。応答本文は固定の文字列で、実際の API 費用は発生しない。
 *
 * 依存順序: `UserSeeder` → `EnrollmentSeeder` → `ContentSeeder`(Section) → 本 Seeder。
 */
final class AiChatSeeder extends Seeder
{
    public function run(): void
    {
        $student = User::query()->where('email', 'student@certify-lms.test')->first();

        if ($student === null) {
            $this->command?->warn('AiChatSeeder: 固定受講生が存在しません。先に UserSeeder を実行してください。');

            return;
        }

        $enrollment = Enrollment::query()->where('user_id', $student->id)->first();

        // ③-前半: 教材から始めた会話。その教材が属する資格の受講登録に紐づける
        $section = $this->findSectionFor($enrollment);
        $this->seedSectionConversation($student, $enrollment, $section);

        // ③-後半: 教材によらない会話(資格の文脈だけ)
        $this->seedCertificationConversation($student, $enrollment);

        // ②: AI 応答がエラー状態の会話
        $this->seedFailedConversation($student, $enrollment);

        // ①: 履歴サイドバーの「過去 30 日」区分を埋める古い会話
        $this->seedOldConversation($student);
    }

    /**
     * 受講登録の資格に属する Section を 1 件。無ければ任意の 1 件でも良い(教材文脈の確認が目的)。
     *
     * ⚠️ studentVisible() で探す。ここを素の query() にすると、**受講生が開けない教材**に
     *    紐づいた会話が初期データに入り、画面には 📚 バッジが出るのに AI には文脈が渡らない
     *    (AiChatContextService::visibleSection() が送信時に落とすため)という食い違いが生まれる。
     */
    private function findSectionFor(?Enrollment $enrollment): ?Section
    {
        if ($enrollment !== null) {
            $section = Section::studentVisible()
                ->whereHas('chapter.part', fn ($q) => $q->where('certification_id', $enrollment->certification_id))
                ->first();

            if ($section !== null) {
                return $section;
            }
        }

        return Section::studentVisible()->first();
    }

    private function seedSectionConversation(User $student, ?Enrollment $enrollment, ?Section $section): void
    {
        if ($section === null) {
            $this->command?->warn('AiChatSeeder: Section が存在しないため教材つきの会話を作れません。');

            return;
        }

        $conversation = $student->aiChatConversations()->create([
            'enrollment_id' => $enrollment?->id,
            'section_id' => $section->id,
            'title' => '教材の要点整理',
            'last_message_at' => now()->subMinutes(20),
        ]);

        $this->exchange($conversation, [
            ['このセクションの要点を 3 行でまとめて', now()->subMinutes(24)],
            ["このセクションの要点は次の 3 つです。\n\n1. 用語の定義をそろえる\n2. 例を 1 つ自分で作る\n3. 過去問で出題形式を確かめる", now()->subMinutes(23)],
            ['2 番目がうまくできません。例はどう作ればいいですか', now()->subMinutes(21)],
            ['身近な場面に置き換えるのが近道です。まず登場人物を 2 人に絞り、やり取りを 1 往復だけ書いてみてください。', now()->subMinutes(20)],
        ]);
    }

    private function seedCertificationConversation(User $student, ?Enrollment $enrollment): void
    {
        $conversation = $student->aiChatConversations()->create([
            'enrollment_id' => $enrollment?->id,
            'section_id' => null,
            'title' => '学習計画の立て方',
            'last_message_at' => now()->subDays(3),
        ]);

        $this->exchange($conversation, [
            ['平日 1 時間しか取れません。どう進めるのが良いですか', now()->subDays(3)->subMinutes(5)],
            ['平日は「復習 20 分 + 新規 40 分」に分けるのがおすすめです。週末にまとめて演習の時間を取ると定着しやすくなります。', now()->subDays(3)],
        ]);
    }

    /**
     * ②: AI の応答が失敗した会話。
     *
     * ⚠️ 受講生の質問は残り、AI の応答だけが error になっている状態を作る ——
     *    これが原典「AI 応答に失敗しても受講生の質問は残り、**同じ内容を送り直して再質問できる**」
     *    を画面で確認できる唯一の形。
     *    error_detail に HTTP ステータス番号を入れるのも必須で、支給 Blade
     *    resources/views/ai-chat/_partials/message-bubble.blade.php:55-56 が
     *    その番号で受講生向けの文言を出し分ける。
     */
    private function seedFailedConversation(User $student, ?Enrollment $enrollment): void
    {
        $conversation = $student->aiChatConversations()->create([
            'enrollment_id' => $enrollment?->id,
            'section_id' => null,
            'title' => '模試の復習のしかた',
            'last_message_at' => now()->subDay(),
        ]);

        $this->message($conversation, AiChatMessageRole::User, '間違えた問題はどこから手を付けるべきですか', now()->subDay()->subMinute());

        $this->message(
            $conversation,
            AiChatMessageRole::Assistant,
            '',
            now()->subDay(),
            status: AiChatMessageStatus::Error,
            errorDetail: 'Gemini API error: HTTP 503 Service Unavailable',
        );
    }

    /**
     * ①: 履歴サイドバーの「過去 30 日」区分を埋めるための古い会話。
     * 教材にも資格にも紐づかない「全般相談」なので、コンテキストバッジの 3 種類目も埋まる。
     */
    private function seedOldConversation(User $student): void
    {
        $conversation = $student->aiChatConversations()->create([
            'enrollment_id' => null,
            'section_id' => null,
            'title' => '勉強の習慣づけ',
            'last_message_at' => now()->subDays(20),
        ]);

        $this->exchange($conversation, [
            ['毎日続けるコツはありますか', now()->subDays(20)->subMinutes(2)],
            ['時間ではなく「開く」ことを目標にすると続きます。机に向かったら教材を開くところまでを 1 回分と数えてみてください。', now()->subDays(20)],
        ]);
    }

    /**
     * 受講生 → AI → 受講生 → AI … の往復をまとめて作る。
     *
     * @param array<int, array{0: string, 1: Carbon}> $turns 本文と時刻の組(古い順)
     */
    private function exchange(AiChatConversation $conversation, array $turns): void
    {
        foreach ($turns as $i => [$content, $at]) {
            $this->message(
                $conversation,
                $i % 2 === 0 ? AiChatMessageRole::User : AiChatMessageRole::Assistant,
                $content,
                $at,
            );
        }
    }

    /**
     * 発言を 1 件。
     *
     * ⚠️ created_at / updated_at は create() の配列に混ぜず、インスタンスに直接代入してから save() する。
     *    配列で渡して効くのは Model::unguarded() の中だけで、db:seed はたまたまその中で走る
     *    (Illuminate\Database\Console\Seeds\SeedCommand が unguarded() で包んでいる)。
     *    実測(2026-09-23): 通常の create() は created_at を無視して「今」になり、
     *    unguarded 内の create() は指定した日時が入った。
     *    暗黙の前提に乗らず、どこから呼ばれても効く書き方にしておく。
     *
     * ⭐ 日時が効くと、AiChatMessage の booted() が親の last_message_at を
     *    「その行の created_at」で更新するので、古い会話が「今日」に化けない。
     *    これが履歴サイドバーの「今日 / 過去 7 日 / 過去 30 日」の振り分けを成立させている。
     */
    private function message(
        AiChatConversation $conversation,
        AiChatMessageRole $role,
        string $content,
        Carbon $at,
        AiChatMessageStatus $status = AiChatMessageStatus::Completed,
        ?string $errorDetail = null,
    ): void {
        $message = new AiChatMessage([
            'ai_chat_conversation_id' => $conversation->id,
            'role' => $role,
            'content' => $content,
            'status' => $status,
            'error_detail' => $errorDetail,
        ]);
        $message->created_at = $at;
        $message->updated_at = $at;
        $message->save();
    }
}

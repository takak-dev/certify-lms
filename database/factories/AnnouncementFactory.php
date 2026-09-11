<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AnnouncementTargetType;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use Database\Factories\Support\JaText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    /**
     * 既定は「全受講生あて」。3 タイプのうち追加の入力が要らない唯一のタイプで、
     * 画面でも既定で選ばれている(target-fields.blade.php:9)。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // タイトルと本文は対で取る。別々に選ぶと見出しと中身が食い違う
        $text = JaText::announcement();

        return [
            'title' => $text['title'],
            'body' => $text['body'],
            'target_type' => AnnouncementTargetType::AllStudents->value,
            'target_certification_id' => null,
            'target_user_id' => null,
            // 配信実績は「実際に送った人数」のスナップショット。
            // Factory は実際の配信を行わないため 0 を既定にする(整合しない数を置かない)。
            // 実数が要るテスト / Seeder は配信処理を通すか、明示的に上書きする
            'dispatched_count' => 0,
            'dispatched_at' => now(),
            'created_by_user_id' => User::factory()->admin(),
        ];
    }

    /**
     * 資格指定の配信。target_type と対象資格は必ずセットで変わるため 1 つの state にまとめる
     * (手本: QaThreadFactory::resolved())。
     */
    public function forCertification(Certification $certification): static
    {
        return $this->state(fn () => [
            'target_type' => AnnouncementTargetType::Certification->value,
            'target_certification_id' => $certification->id,
            'target_user_id' => null,
        ]);
    }

    /**
     * ユーザー指定の配信。受講中の受講生しか選べない(decisions #48)が、
     * その検証は FormRequest と配信処理の担当で、Factory は渡された相手をそのまま入れる。
     */
    public function forUser(User $user): static
    {
        return $this->state(fn () => [
            'target_type' => AnnouncementTargetType::User->value,
            'target_user_id' => $user->id,
            'target_certification_id' => null,
        ]);
    }

    /** 配信した管理者を指定する */
    public function createdBy(User $admin): static
    {
        return $this->state(fn () => [
            'created_by_user_id' => $admin->id,
        ]);
    }

    /** 配信実績(件数と時刻)を指定する。一覧の並び順を検証するテストで使う */
    public function dispatched(int $count, ?string $at = null): static
    {
        return $this->state(function () use ($count, $at): array {
            $state = ['dispatched_count' => $count];

            // 時刻を渡さなかった場合は definition() の now() をそのまま残す
            if ($at !== null) {
                $state['dispatched_at'] = $at;
            }

            return $state;
        });
    }
}

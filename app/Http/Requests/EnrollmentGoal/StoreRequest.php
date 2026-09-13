<?php

declare(strict_types=1);

namespace App\Http\Requests\EnrollmentGoal;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 個人学習目標の追加リクエスト。受講登録詳細画面に埋め込まれたフォームから届く。
 *
 * 認可は `EnrollmentGoalPolicy::create()` に委ね、親の受講登録を第2引数で渡す
 * (支給 Blade も同じ形で判定している: enrollment-goal/_form.blade.php:13)。
 * まだ目標の行が無いので、判定対象は目標ではなく親になる。
 *
 * 入力の上限は画面の属性に合わせる(enrollment-goal/_form.blade.php:22,38)。
 * 手本: app/Http/Requests/QaReply/StoreRequest.php(親を route() から取る形)。
 */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        // route('enrollment') はルート定義の {enrollment} と対応する。
        // モデルバインディングで解決済みの Enrollment が入っている
        $enrollment = $this->route('enrollment');

        return $enrollment instanceof Enrollment
            && ($this->user()?->can('create', [EnrollmentGoal::class, $enrollment]) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
            // 期日は任意。過去日も許す——既存の目標受験日(Enrollment/StoreRequest.php:35)は
            // after:today だが、目標は「期日を過ぎても残る記録」で性質が違う。
            // after:today を付けると、期日を過ぎた目標のタイトルすら直せなくなる
            'target_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * エラーメッセージに出す項目名。画面のラベルと同じ言葉にする
     * (enrollment-goal/_form.blade.php:18,27,34)。
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => '目標',
            'target_date' => '目標期日',
            'description' => '詳細',
        ];
    }
}

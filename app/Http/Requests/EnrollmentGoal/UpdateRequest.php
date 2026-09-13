<?php

declare(strict_types=1);

namespace App\Http\Requests\EnrollmentGoal;

use App\Models\EnrollmentGoal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 個人学習目標の更新リクエスト。専用の編集ページ(enrollment-goal/edit.blade.php)から届く。
 *
 * 認可は `EnrollmentGoalPolicy::update()` に委ねる。こちらは目標の行が既にあるので、
 * 判定対象は目標そのもの。
 *
 * ⚠️ 達成状態(achieved_at)はこのフォームでは変えない。達成マーク / 解除は専用の
 *    エンドポイントが担当する。Model の $fillable にも含めていないため、
 *    リクエストに achieved_at を紛れ込ませても書き込まれない(二重の防御)。
 *
 * ルールは Store と同じ内容を持たせる。既存の Section/UpdateRequest も共通化せず
 * 両方に書いているため、それに揃えた。
 */
class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // route('goal') はルート定義の {goal} と対応する
        $goal = $this->route('goal');

        return $goal instanceof EnrollmentGoal
            && ($this->user()?->can('update', $goal) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
            'target_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
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

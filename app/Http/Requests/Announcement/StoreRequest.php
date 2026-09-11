<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * お知らせの新規配信リクエスト。操作できるのは admin のみ(`AnnouncementPolicy::create()`)。
 *
 * 上限値は支給 Blade の属性に合わせている(`announcement/management/create.blade.php:33` の maxlength="200"、
 * 同 `:43` の :maxlength="5000")。
 *
 * ⭐ 配信対象タイプに対応しない欄の値はエラーにせず捨てる(decisions #88)。
 * 支給フォームは対象を絞る 2 つのセレクトを常時表示し、表示切替の JS を持たない
 * (`announcement/management/_partials/target-fields.blade.php:4,43-44`)。「資格指定 → 全受講生」とラジオを変えても
 * 資格の選択は残るため、それをエラーにすると正しい操作の邪魔になる。
 * 捨てる処理は prepareForValidation() が行う。
 */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Announcement::class) ?? false;
    }

    /**
     * 検証の前に、選んだ配信対象タイプで使わない欄を null に落とす。
     *
     * ここで消しておくと rules() 以降は「対応する欄だけが入っている」状態を前提にでき、
     * Action へ渡る $validated にも余計な値が混ざらない。
     */
    protected function prepareForValidation(): void
    {
        // ⚠️ prepareForValidation() は passesAuthorization() より先に走る
        // (vendor/laravel/framework/src/Illuminate/Validation/ValidatesWhenResolvedTrait.php:19-22)。
        // ここへ来る値はまだ Policy を通っていないので型を信用しない。
        // 文字列以外(例: target_type[]=... の配列)をそのまま (string) にすると
        // 「Array to string conversion」の警告が ErrorException になり、422 ではなく 500 で落ちる。
        // 手本: MarkAsReadAction.php:32 の `! is_string($url)`
        $raw = $this->input('target_type');
        $type = is_string($raw) ? AnnouncementTargetType::tryFrom($raw) : null;

        $this->merge([
            'target_certification_id' => $type === AnnouncementTargetType::Certification
                ? $this->input('target_certification_id')
                : null,
            'target_user_id' => $type === AnnouncementTargetType::User
                ? $this->input('target_user_id')
                : null,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'target_type' => ['required', Rule::enum(AnnouncementTargetType::class)],
            'target_certification_id' => [
                // prepareForValidation() で対応しないタイプの値は消えているため、
                // ここに値が残っているのは「資格指定」を選んだときだけ
                'required_if:target_type,'.AnnouncementTargetType::Certification->value,
                'nullable',
                'ulid',
                // 資格は公開状態で絞らない。配信対象を決めるのは受講登録であって資格の公開状態ではない。
                // certifications は論理削除を使っていない(deleted_at 列が無い)ので exists だけでよい
                Rule::exists('certifications', 'id'),
            ],
            'target_user_id' => [
                'required_if:target_type,'.AnnouncementTargetType::User->value,
                'nullable',
                'ulid',
                // 受講中でない受講生・コーチ・管理者は選べない(decisions #48)。
                // whereNull('deleted_at') が要るのは、Rule::exists が素のクエリビルダで動き
                // User の SoftDeletes グローバルスコープを通らないため
                // (手本: SectionQuestionAnswer/StoreRequest.php:59-61)。
                //
                // ⚠️ ここの条件は User::scopeInProgressStudents() と同じ集合でなければならない。
                // 配信集合の解決(AnnouncementRecipientService)はスコープ側を使うため、ずれると
                // 「フォームで選べるのに配信されない」が起きる。
                // StoreTest::test_user_target_validation_matches_the_recipient_scope が両者の一致を検査する
                Rule::exists('users', 'id')
                    ->where('role', UserRole::Student->value)
                    ->where('status', UserStatus::InProgress->value)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'タイトル',
            'body' => '本文',
            'target_type' => '配信対象',
            'target_certification_id' => '対象資格',
            'target_user_id' => '対象受講生',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_certification_id.required_if' => '資格指定の配信では対象資格を選択してください。',
            'target_user_id.required_if' => 'ユーザー指定の配信では対象受講生を選択してください。',
            'target_user_id.exists' => '選択された受講生は配信対象にできません。受講中の受講生から選んでください。',
        ];
    }
}

/**
 * トップバーの通知ポップオーバー(S-A-05)。
 *
 * - ベルクリックでパネルを開閉。外側クリック / Esc でも閉じる
 * - 開くたびに通知 JSON API から最新分を取り直して行を描く(リアルタイム受信はしない。原典スコープ外)
 * - 未読件数(ベルのバッジ・未読タブの件数)を API が返した値で書き換える
 * - 全件 / 未読タブの切り替え。未読タブは API に ?tab=unread を付けて取り直す(decisions #260)
 * - 行クリックで既読化 API を叩き、返ってきた URL へ移動する
 * - 全件既読ボタンで自分の未読をまとめて既読にし、件数と行の目印を書き換える
 *
 * 対象の DOM と API の URL は Blade の data-* 属性で受け取る:
 *   resources/views/layouts/_partials/topbar.blade.php:56-81(ベル・バッジ・一覧 API の URL)
 *   resources/views/notifications/_partials/notification-popover.blade.php(パネル本体)
 *
 * 管理者の画面にはパネルが描画されないので(decisions #259)、見つからなければ何もしない。
 */

import { getJson, postJson } from '../utils/fetch-json';

// パネルのフェードにかかる時間。Blade 側の `duration-150`(notification-popover.blade.php:11)と揃える
const TRANSITION_MS = 150;

// 取得に失敗したときに空状態の欄へ出す文言
const LOAD_ERROR_MESSAGE = '通知を読み込めませんでした。時間をおいて開き直してください。';

export function initNotificationPopover() {
    const root = document.querySelector('[data-notification-popover-root]');
    const trigger = root?.querySelector('[data-notification-popover-trigger]');
    const panel = root?.querySelector('[data-notification-popover-panel]');
    // 通知 JSON API の URL は Blade が route() で埋め込む(answer-autosave.js:20 など多くの既存 JS と同じ受け取り方)。
    // ルート名を変えると Blade の描画で気づける。既読化・全件既読は routes/api.php で同じ prefix の下に並べている
    const indexUrl = root?.dataset.notificationPopoverIndexUrl;
    // 管理者はパネル自体が無い → ベルを押しても何も起きない(decisions #214)
    if (!root || !trigger || !panel || !indexUrl) return;
    const readUrl = (id) => `${indexUrl}/${encodeURIComponent(id)}/read`;
    const readAllUrl = `${indexUrl}/read-all`;

    const badge = root.querySelector('[data-notification-popover-badge]');
    const unreadCountEl = panel.querySelector('[data-notification-popover-unread-count]');
    const loadingEl = panel.querySelector('[data-notification-popover-loading]');
    const emptyEl = panel.querySelector('[data-notification-popover-empty]');
    const itemsEl = panel.querySelector('[data-notification-popover-items]');
    const rowTemplate = panel.querySelector('[data-notification-popover-row-template]');
    const tabs = [...panel.querySelectorAll('[data-notification-popover-tab]')];
    const markAllButton = panel.querySelector('[data-notification-popover-mark-all]');
    // 空状態の欄は取得失敗の表示にも使うので、元の文言(「通知はありません。」)を控えておく
    const emptyText = emptyEl.textContent.trim();
    // 既読化 API が失敗したときの逃げ道。フッターの「すべての通知を見る」と同じ行き先(notification-popover.blade.php:61)
    const fallbackUrl = panel.querySelector('[data-notification-popover-footer-link]')?.href ?? null;

    // 閉じるアニメーションの途中で開き直されたときに、遅れて走る「隠す」処理を取り消すための控え
    let hideTimer = null;
    // 何回目の取得か。素早く開閉を繰り返したとき、古い返事が後から届いて新しい表示を上書きしないように使う
    let loadSeq = 0;
    // いま選ばれているタブ('all' / 'unread')。閉じて開き直しても、最後に選んだタブのまま取り直す
    let currentTab = 'all';

    const isOpen = () => trigger.getAttribute('aria-expanded') === 'true';

    // いまの未読件数。初期値はサーバーが描いたベルの読み上げ文から取る(バッジは 99+ と丸められることがあるため)
    let unreadCount = Number(trigger.getAttribute('aria-label')?.match(/\d+/)?.[0] ?? 0);
    // 既読化の返事を待っている間は、ほかの行のクリックを受け付けない(二度押しで 2 回叩かないように)
    let reading = false;

    /**
     * 未読件数を 3 か所(ベルのバッジ / ベルの読み上げ文 / 未読タブの件数)に反映する。
     * ページを開いた時点の値はサーバーが Blade で描いている(topbar.blade.php:63-74)。JS はその後の増減だけを担う。
     */
    function setUnreadCount(count) {
        unreadCount = count;
        // バッジの見せ方は Blade と揃える: 0 以下なら隠す / 100 以上は「99+」(topbar.blade.php:71,74)
        badge.textContent = count > 99 ? '99+' : String(count);
        badge.classList.toggle('hidden', count <= 0);
        trigger.setAttribute('aria-label', `通知 (${count} 件未読)`);
        unreadCountEl.textContent = String(count);
    }

    /**
     * 通知 1 件分の行を、支給の行テンプレート(notification-popover.blade.php:70-85)を複製して作る。
     */
    function buildRow(notification) {
        // <template> の中身は画面に出ない「型」。content.cloneNode(true) で中身ごと複製して使う
        const li = rowTemplate.content.firstElementChild.cloneNode(true);
        const link = li.querySelector('[data-notification-popover-row]');

        // 文字は必ず textContent で入れる。innerHTML だと、通知のタイトルや本文に <script> などが
        // 含まれていたとき HTML として実行されてしまう(XSS)。textContent はただの文字として入る
        li.querySelector('[data-notification-popover-row-title]').textContent = notification.title;
        li.querySelector('[data-notification-popover-row-message]').textContent = notification.message;
        li.querySelector('[data-notification-popover-row-time]').textContent = notification.created_at_human;

        // 既読化 API を叩くときに使う ID を行に持たせておく
        link.dataset.notificationId = notification.id;

        // 未読行の背景を強調する。Blade の `aria-[data-unread=true]:bg-primary-50/30` は Tailwind の書き方で、
        // 実際に効く条件は `aria-data-unread="true"` という属性(ビルド後の CSS で確認済み)。
        // dataset.unread と書くと data-unread になり、色が付かない
        link.setAttribute('aria-data-unread', notification.is_unread ? 'true' : 'false');
        // 既読なら「未読の目印」の点を隠す
        li.querySelector('[data-notification-popover-row-dot]').classList.toggle('hidden', !notification.is_unread);

        return li;
    }

    /**
     * 読み込み中 / 空状態 / 行リストのうち、どれを見せるかを切り替える。
     */
    function showState(state, message = emptyText) {
        loadingEl.classList.toggle('hidden', state !== 'loading');
        emptyEl.classList.toggle('hidden', state !== 'empty');
        emptyEl.textContent = message;
        itemsEl.classList.toggle('hidden', state !== 'items');
    }

    /**
     * 一覧 API を叩いて行を描き直す。開くたびに呼ぶ(キャッシュはしない。原典スコープ外)。
     */
    async function load() {
        const seq = ++loadSeq;
        itemsEl.replaceChildren();
        showState('loading');

        try {
            // getJson は fetch に CSRF トークンと Cookie を付けるラッパー(utils/fetch-json.js)。
            // 返り値の形: { data: [ {id, title, message, created_at_human, is_unread}, ... ], unread_count }
            // 未読タブは ?tab=unread で未読だけを取り直す。手元の 20 件を JS で絞ると、
            // 21 件目より古い未読が出ず、未読タブの件数と行数が食い違うため(decisions #260)
            const payload = await getJson(`${indexUrl}?tab=${encodeURIComponent(currentTab)}`);
            // この返事を待つ間に開き直されていたら、もっと新しい取得が走っているので捨てる
            if (seq !== loadSeq) return;

            itemsEl.replaceChildren(...payload.data.map(buildRow));
            setUnreadCount(payload.unread_count);
            showState(payload.data.length === 0 ? 'empty' : 'items');
        } catch (error) {
            if (seq !== loadSeq) return;
            // 401(ログインが切れた)なども含めて、画面を壊さず文言で知らせる。バッジは元の値のまま触らない
            console.error('[notification-popover] 一覧の取得に失敗しました', error);
            showState('empty', LOAD_ERROR_MESSAGE);
        }
    }

    function open() {
        clearTimeout(hideTimer);

        // パネルは 3 段で隠されている(notification-popover.blade.php:11-12)。
        // ① hidden クラス ② style="display: none;" ③ opacity-0 と -translate-y-1(透明 + 少し上にずらす)
        // ①② を外して場所を取らせる。`flex` を足すのは、Blade に縦並びの flex-col はあるが flex 本体が無いため
        panel.classList.remove('hidden');
        panel.classList.add('flex');
        panel.style.display = '';

        // ③ を外す前に、ブラウザに「表示された・まだ透明」の状態を一度計算させる。
        // offsetWidth を読むと、ブラウザはその場でレイアウトを確定させる(読むだけで値は使わない)。
        // これが無いと ①②③ の変更がまとめて 1 回で反映され、「透明だった状態」が存在しないことになり
        // transition(ふわっと出る動き)が効かない
        void panel.offsetWidth;
        panel.classList.remove('opacity-0', '-translate-y-1');

        trigger.setAttribute('aria-expanded', 'true');

        load();
    }

    function close({ returnFocus = false } = {}) {
        // 先に ③ を戻して、透明になっていく動きを見せる
        panel.classList.add('opacity-0', '-translate-y-1');

        // 動きが終わってから ①② を戻して場所を空ける
        clearTimeout(hideTimer);
        hideTimer = setTimeout(() => {
            panel.classList.add('hidden');
            panel.classList.remove('flex');
            panel.style.display = 'none';
        }, TRANSITION_MS);

        trigger.setAttribute('aria-expanded', 'false');
        // Esc で閉じたときはベルにフォーカスを戻す(キーボード操作の人が迷子にならないように。dropdown.js:39 と同じ)
        if (returnFocus) trigger.focus();
    }

    /**
     * タブの見た目を切り替える。
     * クラスは触らず aria-selected を書き換えるだけでよい。Blade の `aria-selected:bg-white` などは
     * Tailwind の書き方で、実際には `[aria-selected=true]` のときに効く(ビルド後の CSS で確認済み)。
     * 読み上げソフトにも「選ばれているタブ」が伝わる
     */
    function selectTab(tab) {
        currentTab = tab;
        tabs.forEach((button) => {
            button.setAttribute('aria-selected', button.dataset.notificationPopoverTab === tab ? 'true' : 'false');
        });
    }

    /**
     * 行クリック: 既読化 API を叩き、返ってきた URL へ移動する。
     *
     * 行(<a href="#">)は JS が後から足すので、1 行ずつではなく親の <ul> で受ける(イベント委譲)。
     * 行を描き直しても登録し直さなくてよい
     */
    itemsEl.addEventListener('click', async (event) => {
        const link = event.target.closest('[data-notification-popover-row]');
        if (!link) return;

        // href="#" のままだとページ先頭へ飛んで URL に # が付く。ブラウザ既定の動きを取り消す
        event.preventDefault();
        if (reading) return;
        reading = true;

        try {
            // 返り値の形: { url } — 遷移先は MarkAsReadAction が決める(外部 URL は弾く / url の無い通知は詳細ページ)
            const payload = await postJson(readUrl(link.dataset.notificationId));

            // 未読だった行なら、移動する前にバッジと未読タブの件数を 1 減らし、行の目印も消す(原典「通知行のクリック…に応じて
            // バッジ・タブの件数を動的に増減」)。サーバーが既読にしたことを確かめた後なので、先回りの表示(楽観更新)ではない
            if (link.getAttribute('aria-data-unread') === 'true') {
                setUnreadCount(Math.max(0, unreadCount - 1));
                link.setAttribute('aria-data-unread', 'false');
                link.querySelector('[data-notification-popover-row-dot]').classList.add('hidden');
            }

            // fetch はリダイレクトを画面に反映しないので、返ってきた URL へ自分で移動する
            window.location.href = payload.url;
        } catch (error) {
            // 通知が消えていた(404)などで既読化できなかった。行き止まりにせず、全通知のページへ案内する
            console.error('[notification-popover] 既読化に失敗しました', error);
            reading = false;
            if (fallbackUrl) window.location.href = fallbackUrl;
        }
    });

    /**
     * 全件既読ボタン: 自分の未読をまとめて既読にする。
     */
    markAllButton.addEventListener('click', async () => {
        // 返事を待つ間の二度押しを防ぐ。disabled にするとボタンが押せなくなる
        if (markAllButton.disabled) return;
        markAllButton.disabled = true;

        // 読み込み中に押されたときは、その取得の返事を捨てさせる(番号を進めると load() が古い返事と判断する)。
        // 捨てないと、全件既読の後に「押す前の一覧」が届き、件数と目印を元に戻してしまう
        const interruptedLoad = !loadingEl.classList.contains('hidden');
        loadSeq++;

        try {
            // 返り値の形: { unread_count } — 処理後の未読件数。0 と決め打ちしないのは、
            // 押してから処理が終わるまでに新しい通知が届くことがあり、正しい数はサーバーにしか分からないため
            const payload = await postJson(readAllUrl);
            setUnreadCount(payload.unread_count);

            if (currentTab === 'unread' || interruptedLoad) {
                // 未読タブの行はすべて既読になり、このタブに居る理由が無くなった。
                // 決め打ちで空にせず取り直す(その間に届いた新しい未読があれば、それだけが並ぶ)。
                // 読み込みを中断させた場合も、手元に行が無いので取り直す
                load();
            } else {
                // 全件タブは行を残したまま、未読の目印(背景と点)だけを消す
                itemsEl.querySelectorAll('[data-notification-popover-row]').forEach((link) => {
                    link.setAttribute('aria-data-unread', 'false');
                    link.querySelector('[data-notification-popover-row-dot]').classList.add('hidden');
                });
            }
        } catch (error) {
            // 失敗したら何も書き換えない(件数も目印も元のまま)。もう一度押せるように戻す
            console.error('[notification-popover] 全件既読に失敗しました', error);
            // ただし読み込みを中断させていた場合は、その返事を捨てたので「読み込み中」のまま止まっている。取り直す
            if (interruptedLoad) load();
        } finally {
            markAllButton.disabled = false;
        }
    });

    tabs.forEach((button) => {
        button.addEventListener('click', () => {
            const tab = button.dataset.notificationPopoverTab;
            // 選択中のタブをもう一度押したときは取り直さない
            if (tab === currentTab) return;
            selectTab(tab);
            load();
        });
    });

    // 未読タブの件数は支給 Blade では「0」で描かれている(notification-popover.blade.php:30)。
    // ベルと同じサーバーの値に揃えておく。これが無いと、開いて取得が終わるまで(失敗したらずっと)「0」のまま残る
    setUnreadCount(unreadCount);

    trigger.addEventListener('click', () => {
        if (isOpen()) {
            close();
        } else {
            open();
        }
    });

    // ブラウザの「戻る」「進む」で、ページが JS の状態ごと復元されたとき(bfcache)の後始末。
    // 行クリックで移動する直前は reading = true のままなので、そのまま復元されると行クリックが効かなくなる。
    // 件数も移動前の古い値のままなので、パネルを閉じて、次に開いたときに取り直させる。
    // event.persisted は「状態ごと復元された」ときだけ true(通常の読み込みでは false)
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        reading = false;
        if (isOpen()) close();
    });

    // 外側クリックで閉じる(dropdown.js:44-45 と同じ考え方)。
    // ベルやパネルの中のクリックは root の内側なので対象外。
    // stopPropagation() で止める書き方(dropdown.js:28)にしないのは、止めると
    // ベルを押したときに、開いているユーザーメニュー(x-dropdown)が閉じなくなるため
    document.addEventListener('click', (event) => {
        if (isOpen() && !root.contains(event.target)) close();
    });

    // Esc で閉じる
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && isOpen()) close({ returnFocus: true });
    });
}

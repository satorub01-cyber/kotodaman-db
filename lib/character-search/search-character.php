<?php
/*
Template Name: キャラクター一覧表
*/

// ▼▼▼ 追加・修正ポイント ▼▼▼
// 検索経由で来た場合、メインクエリ ($wp_query) には既に検索結果が入っています。
// それを優先して利用するようにします。
global $wp_query;
$the_query = $wp_query; // デフォルトはメインクエリ

// もし検索じゃなくて普通に一覧ページを開いたときは、独自クエリを作る準備
if (!is_search() && !isset($_GET['tx_attr'])) {
    // 必要であればここで初期化
}
// ▲▲▲ 追加・修正ポイント終わり ▲▲▲
?>
<?php
// Add 'is-search-page' class to body if it's a character search result page
if (is_search() && get_query_var('post_type') === 'character') {
    add_filter('body_class', function($classes) {
        return array_merge($classes, ['is-search-page']);
    });
}
get_header();
?>
<div class="koto-archive-container">

    <header class="archive-header">
        <h1 class="archive-title">検索結果</h1>
    </header>

    <div class="character-search-box">
        <?php get_search_form(); ?>
    </div>
    <?php
    // ★設定読み込み (chara-list-functions.php で定義)
    $config = koto_get_column_config();

    // ▼▼▼ 差し替え：JS用ソートリンク生成ヘルパー ▼▼▼
    $get_sort_link = function ($key, $label) {
        // 初期状態は薄い下向き矢印
        $arrow_html = '<span class="sort-arrow faint">▼</span>';
        $safe_key = esc_attr($key);

        // href="#" でページ遷移を防ぎ、JS操作用のクラス「js-sort-link」と、ソート対象の「data-sort-key」を付与
        return "<a href='#' class='sort-link js-sort-link' data-sort-key='{$safe_key}'>{$label}{$arrow_html}</a>";
    };
    // ▲▲▲ 差し替えここまで ▲▲▲
    ?>

    <details class="column-config-box">
        <summary class="config-summary">表示列の設定</summary>
        <div class="config-content">
            <?php foreach ($config as $key => $col): ?>
                <?php
                // アイコンと名前は非表示にできないようにスキップ
                if ($key === 'icon' || $key === 'name') continue;

                // checkboxのラベル (HTMLタグを除去したテキスト優先、なければlabel)
                $txt_label = $col['txt_label'] ?? strip_tags($col['label']);

                // デフォルト表示状態
                $is_checked = (!empty($col['show']) && $col['show']) ? 'checked' : '';
                ?>
                <label>
                    <input type="checkbox" class="col-toggle"
                        data-target="<?php echo esc_attr($col['class']); ?>"
                        <?php echo $is_checked; ?>>
                    <?php echo esc_html($txt_label); ?>
                </label>
            <?php endforeach; ?>
        </div>
    </details>

    <div class="search-hit-info" style="margin-bottom: 10px; font-weight: bold;">
        該当件数: <span id="hit-count-num">0</span> 件
    </div>

    <p class="scroll-hint-text">表は横にスクロールできます ➡</p>

    <div class="koto-table-wrapper">
        <table class="koto-chara-table">
            <thead>
                <tr>
                    <?php foreach ($config as $key => $col): ?>
                        <th class="<?php echo esc_attr(($col['header_class'] ?? '') . ' ' . $col['class']); ?>">
                            <?php
                            if (!empty($col['sort'])) {
                                // ソート可能な列
                                echo $get_sort_link($col['sort'], $col['label']);
                            } else {
                                // ソート不可の列
                                echo $col['label'];
                            }
                            ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody id="chara-list-body">
            </tbody>
        </table>
    </div>

    <div id="koto-load-more-wrap" class="koto-load-more-wrap is-hidden">
        <div id="koto-load-more-sentinel" class="koto-load-more-sentinel" aria-hidden="true"></div>
        <div class="koto-load-more-actions">
            <button type="button" id="koto-load-more-btn" class="koto-load-more-btn">さらに表示</button>
            <button type="button" id="koto-show-all-btn" class="koto-show-all-btn">すべて表示</button>
        </div>
    </div>

    <div id="loading-spinner" style="display:none; text-align:center; padding: 20px;">
        <span class="loading-dots">読み込み中</span>
    </div>
</div>
<?php get_footer(); ?>
<?php
$js_path = get_stylesheet_directory() . '/lib/character-search/search-engine.js';
$version = file_exists($js_path) ? (@filemtime($js_path) ?: '1.0') : '1.0';
$json_path = get_stylesheet_directory() . '/lib/character-search/all_characters_search.json';
$json_ver = file_exists($json_path) ? (@filemtime($json_path) ?: $version) : $version;
$base_json_url = function_exists('koto_json_generation_output_file_url')
    ? koto_json_generation_output_file_url('all_characters_search.json')
    : get_stylesheet_directory_uri() . '/lib/character-search/all_characters_search.json';
$search_json_url = add_query_arg('v', $json_ver, $base_json_url);
$grp_map = koto_get_group_map();
$event_map = koto_get_event_map();
$suitable_quest_map = koto_get_suitable_quest_map();
$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<script>
    window.KOTO_SEARCH_JSON_URL = <?php echo wp_json_encode($search_json_url, $json_flags); ?>;
    window.KOTO_GROUP_MAP = <?php echo wp_json_encode($grp_map, $json_flags); ?>;
    window.KOTO_EVENT_MAP = <?php echo wp_json_encode($event_map, $json_flags); ?>;
    window.KOTO_SUITABLE_QUEST_MAP = <?php echo wp_json_encode($suitable_quest_map, $json_flags); ?>;
    var KOTO_GROUP_MAP = window.KOTO_GROUP_MAP;
    var KOTO_EVENT_MAP = window.KOTO_EVENT_MAP;
    var KOTO_SUITABLE_QUEST_MAP = window.KOTO_SUITABLE_QUEST_MAP;
</script>
<script src="<?php echo esc_url(get_stylesheet_directory_uri() . '/lib/character-search/search-engine.js?v=' . $version); ?>"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const table = document.querySelector('.koto-chara-table');
        const checkboxes = document.querySelectorAll('.col-toggle');
        const storageKey = 'koto_column_settings';

        // 指定クラスの列を表示/非表示にする関数（テーブル1要素のCSSクラス切替）
        const toggleColumn = (targetClass, isVisible) => {
            if (!table || !targetClass) return;
            table.classList.toggle('hide-' + targetClass, !isVisible);
        };

        // ★修正ポイント：search-engine.js からも呼び出せるように window オブジェクトに入れる
        window.applyCurrentColumnSettings = () => {
            checkboxes.forEach(cb => {
                toggleColumn(cb.dataset.target, cb.checked);
            });
        };

        // 設定読み込み
        const loadSettings = () => {
            const saved = localStorage.getItem(storageKey);
            if (saved) {
                try {
                    const hiddenCols = JSON.parse(saved);
                    if (Array.isArray(hiddenCols)) {
                        checkboxes.forEach(cb => {
                            const target = cb.dataset.target;
                            if (hiddenCols.includes(target)) {
                                cb.checked = false;
                                toggleColumn(target, false);
                            } else {
                                cb.checked = true;
                                toggleColumn(target, true);
                            }
                        });
                        return;
                    }
                } catch (e) {
                    localStorage.removeItem(storageKey);
                }
            }
            checkboxes.forEach(cb => {
                toggleColumn(cb.dataset.target, cb.checked);
            });
        };

        // 設定保存
        const saveSettings = () => {
            const hiddenCols = [];
            checkboxes.forEach(cb => {
                if (!cb.checked) hiddenCols.push(cb.dataset.target);
            });
            localStorage.setItem(storageKey, JSON.stringify(hiddenCols));
        };

        // イベントリスナー登録
        checkboxes.forEach(cb => {
            cb.addEventListener('change', function() {
                toggleColumn(this.dataset.target, this.checked);
                saveSettings();
            });
        });

        // 初期実行
        loadSettings();

    });
</script>

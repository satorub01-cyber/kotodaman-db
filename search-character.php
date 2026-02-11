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

get_header(); ?>
<div class="koto-archive-container">

    <header class="archive-header">
        <h1 class="archive-title">検索結果</h1>
    </header>

    <div class="character-search-box">
        <?php get_search_form(); ?>
    </div>
    <?php
    // ▼▼▼ 修正: 検索時はメインクエリをそのまま使う ▼▼▼
    if (is_search()) {
        global $wp_query;
        $the_query = $wp_query;
    } else {
        // 固定ページとして使う場合のみ、独自のクエリを発行
        $paged = (get_query_var('paged')) ? get_query_var('paged') : ((get_query_var('page')) ? get_query_var('page') : 1);
        $args = get_koto_character_args($_GET, $paged);
        $the_query = new WP_Query($args);
    }

    // ★設定読み込み (chara-list-functions.php で定義)
    $config = koto_get_column_config();

    // ソートリンク生成用変数
    $sort_key   = $_GET['orderby'] ?? 'name_ruby';
    $sort_order = $_GET['order'] ?? 'ASC';

    // ソートリンク生成ヘルパー
    $get_sort_link = function ($key, $label) use ($sort_key, $sort_order) {
        $new_order = 'DESC';
        if ($key === 'name_ruby') $new_order = 'ASC';

        $arrow_html = '<span class="sort-arrow faint">▼</span>';
        $active_class = '';

        if ($sort_key === $key) {
            $new_order = ($sort_order === 'ASC') ? 'DESC' : 'ASC';
            $active_class = 'is-active';
            $arrow_char = ($sort_order === 'ASC') ? '▲' : '▼';
            $arrow_html = '<span class="sort-arrow active">' . $arrow_char . '</span>';
        }

        $url = add_query_arg(['orderby' => $key, 'order' => $new_order]);
        return "<a href='{$url}' class='sort-link {$active_class}'>{$label}{$arrow_html}</a>";
    };
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
                <?php if ($the_query->have_posts()) : ?>
                    <?php while ($the_query->have_posts()) : $the_query->the_post(); ?>

                        <?php echo get_koto_character_row_html(get_the_ID()); ?>

                    <?php endwhile; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="100%" class="no-data">キャラクターが見つかりませんでした。</td>
                    </tr>
                <?php endif; ?>
                <?php wp_reset_postdata(); ?>
            </tbody>
        </table>
    </div>

    <div id="loading-spinner" style="display:none; text-align:center; padding: 20px;">
        <span class="loading-dots">読み込み中</span>
    </div>
    <div id="scroll-observer" style="height: 20px;"></div>
    <input type="hidden" id="current-page" value="<?php echo max(1, $paged); ?>">
    <input type="hidden" id="max-pages" value="<?php echo $the_query->max_num_pages; ?>">
</div>

<?php
// 本番環境、かつ検索パラメータが何か1つでもある場合のみ出力 (GA4連携)
if ($_SERVER['HTTP_HOST'] === 'www.kotodaman-db.com'):

    // ▼ ヘルパー関数
    function my_ga4_param_str($key)
    {
        if (empty($_GET[$key])) return '';
        $val = $_GET[$key];
        return is_array($val) ? implode(',', $val) : $val;
    }

    $p_term    = $_GET['s'] ?? '';
    $p_attr    = my_ga4_param_str('tx_attr');
    $p_species = my_ga4_param_str('tx_species');
    $p_group   = my_ga4_param_str('tx_group');
    $p_gimmick = my_ga4_param_str('tx_gimmick');
    $p_event   = my_ga4_param_str('tx_event');
?>
    <script>
        gtag('event', 'character_search', {
            'event_category': 'search',
            'event_label': 'custom_search_bar',
            'search_term': '<?php echo esc_js($p_term); ?>',
            'filter_attr': '<?php echo esc_js($p_attr); ?>',
            'filter_species': '<?php echo esc_js($p_species); ?>',
            'filter_group': '<?php echo esc_js($p_group); ?>',
            'filter_gimmick': '<?php echo esc_js($p_gimmick); ?>',
            'filter_event': '<?php echo esc_js($p_event); ?>'
        });
    </script>
<?php endif; ?>
<?php get_footer(); ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const checkboxes = document.querySelectorAll('.col-toggle');
        const storageKey = 'koto_column_settings';

        // 指定クラスの列を表示/非表示にする関数
        const toggleColumn = (targetClass, isVisible) => {
            // targetClass を持つ要素 (th と td の両方についているはず)
            const cells = document.querySelectorAll('.' + targetClass);
            cells.forEach(cell => {
                if (isVisible) {
                    cell.classList.remove('col-hidden');
                } else {
                    cell.classList.add('col-hidden');
                }
            });
        };

        // 設定読み込み
        const loadSettings = () => {
            const saved = localStorage.getItem(storageKey);
            if (saved) {
                const hiddenCols = JSON.parse(saved);
                checkboxes.forEach(cb => {
                    const target = cb.dataset.target;
                    // 保存データに含まれている＝非表示(チェック外す)
                    if (hiddenCols.includes(target)) {
                        cb.checked = false;
                        toggleColumn(target, false);
                    } else {
                        cb.checked = true;
                        toggleColumn(target, true);
                    }
                });
            } else {
                // 初回はHTMLのchecked属性(PHP側で制御)に従う
                checkboxes.forEach(cb => {
                    toggleColumn(cb.dataset.target, cb.checked);
                });
            }
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

        // --- 無限スクロール ---
        const observerTarget = document.getElementById('scroll-observer');
        const loadingSpinner = document.getElementById('loading-spinner');
        const tableBody = document.getElementById('chara-list-body');
        const pageInput = document.getElementById('current-page');
        const maxPageInput = document.getElementById('max-pages');
        let isLoading = false;

        // Ajaxで読み込んだ後にも列設定を適用する必要がある
        const applyCurrentColumnSettings = () => {
            checkboxes.forEach(cb => {
                toggleColumn(cb.dataset.target, cb.checked);
            });
        };

        const observer = new IntersectionObserver((entries) => {
            if (entries[0].isIntersecting && !isLoading) {
                loadNextPage();
            }
        }, {
            rootMargin: '200px'
        });

        if (observerTarget) {
            observer.observe(observerTarget);
        }

        function loadNextPage() {
            let currentPage = parseInt(pageInput.value);
            let maxPages = parseInt(maxPageInput.value);
            if (currentPage >= maxPages) return;

            isLoading = true;
            loadingSpinner.style.display = 'block';

            let nextPage = currentPage + 1;
            const params = new URLSearchParams();
            params.append('action', 'load_more_characters');
            params.append('paged', nextPage);

            const urlParams = new URLSearchParams(window.location.search);
            urlParams.forEach((value, key) => {
                params.append(key, value);
            });

            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    body: params
                })
                .then(response => response.text())
                .then(data => {
                    if (data.trim() !== '') {
                        tableBody.insertAdjacentHTML('beforeend', data);
                        pageInput.value = nextPage;
                        // 追加されたDOMに対して表示設定を適用
                        applyCurrentColumnSettings();
                    } else {
                        observer.disconnect();
                    }
                })
                .catch(err => console.error('Error:', err))
                .finally(() => {
                    isLoading = false;
                    loadingSpinner.style.display = 'none';
                });
        }
    });
</script>
<?php
/*
Template Name: キャラクター一覧表
*/

// ▼▼▼ 追加・修正ポイント ▼▼▼
// 検索経由で来た場合、メインクエリ ($wp_query) には既に検索結果が入っています。
// それを優先して利用するようにします。
global $wp_query;
$the_query = $wp_query; // デフォルトはメインクエリ

// もし検索じゃなくて普通に一覧ページを開いたときは、全件取得などの独自クエリを作る
if (!is_search() && !isset($_GET['tx_attr'])) {
    // ここに既存の「初期表示用クエリ」があればそれを使う
    // $args = get_koto_character_args(...);
    // $the_query = new WP_Query($args);
}
// ▲▲▲ 追加・修正ポイント終わり ▲▲▲

get_header(); ?>
<div class="koto-archive-container">

    <header class="archive-header">
        <h1 class="archive-title"><?php the_title(); ?></h1>
    </header>

    <div class="character-search-box">
        <?php get_search_form(); ?>
    </div>
    <?php
    $paged = (get_query_var('paged')) ? get_query_var('paged') : ((get_query_var('page')) ? get_query_var('page') : 1);
    $args = get_koto_character_args($_GET, $paged);
    $the_query = new WP_Query($args);

    $sort_key   = $_GET['orderby'] ?? 'name_ruby';
    $sort_order = $_GET['order'] ?? 'ASC';

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
        $url = add_query_arg(['orderby' => $key, 'order'   => $new_order]);
        return "<a href='{$url}' class='sort-link {$active_class}'>{$label}{$arrow_html}</a>";
    };

    $get_term_display_html = function ($slug, $taxonomy, $type, $is_small = false, $prefix = '') {
        if (!$slug) return '';
        $term = get_term_by('slug', $slug, $taxonomy);
        if (!$term || is_wp_error($term)) return '';
        $class_name = 'koto-icon';
        if ($type === 'attr') $class_name = 'attr-icon-img';
        if ($type === 'species') $class_name = 'species-icon-img';
        if ($is_small) $class_name .= ' koto-icon-small';
        $html_content = function_exists('get_term_icon_html') ? get_term_icon_html($term, $class_name) : $term->name;
        if (strpos($html_content, '<img') === false) {
            if ($type === 'gimmick') $html_content = "<span class='badge-gimmick'>{$term->name}</span>";
            elseif ($type === 'attr') $html_content = "<span class='attr-text attr-{$slug}'>{$prefix}{$term->name}</span>";
        } else {
            if ($prefix) $html_content = "<span class='icon-prefix'>{$prefix}</span>" . $html_content;
        }
        return "<a href='" . get_term_link($term) . "' class='term-link-wrapper'>{$html_content}</a>";
    };
    ?>

    <details class="column-config-box">
        <summary class="config-summary">表示列の設定</summary>
        <div class="config-content">
            <label><input type="checkbox" class="col-toggle" data-target="col-cv"> CV</label>
            <label><input type="checkbox" class="col-toggle" data-target="col-acq"> 入手</label>
            <label><input type="checkbox" class="col-toggle" data-target="col-attr"> 属性</label>
            <label><input type="checkbox" class="col-toggle" data-target="col-species" checked> 種族</label>
            <label><input type="checkbox" class="col-toggle" data-target="col-moji" checked> 文字</label>

            <label><input type="checkbox" class="col-toggle" data-target="col-hp99" checked> HP(99)</label>
            <label><input type="checkbox" class="col-toggle" data-target="col-atk99" checked> ATK(99)</label>
            <label><input type="checkbox" class="col-toggle" data-target="col-hp120"> HP(120)</label>
            <label><input type="checkbox" class="col-toggle" data-target="col-atk120"> ATK(120)</label>

            <label><input type="checkbox" class="col-toggle" data-target="col-ls-hp"> L HP</label>
            <label><input type="checkbox" class="col-toggle" data-target="col-ls-atk"> L ATK</label>
            <label><input type="checkbox" class="col-toggle" data-target="col-buff"> バフ</label>
            <label><input type="checkbox" class="col-toggle" data-target="col-debuff"> デバフ</label>
            <label><input type="checkbox" class="col-toggle" data-target="col-gimmick" checked> ギミック</label>
            <label><input type="checkbox" class="col-toggle" data-target="col-date" checked> 実装日</label>
            <label><input type="checkbox" class="col-toggle" data-target="col-power"> 火力指数</label>
        </div>
    </details>

    <p class="scroll-hint-text">表は横にスクロールできます ➡</p>

    <div class="koto-table-wrapper">
        <table class="koto-chara-table">
            <thead>
                <tr>
                    <th class="th-icon">アイコン</th>
                    <th class="th-name"><?php echo $get_sort_link('name_ruby', 'キャラ名'); ?></th>
                    <th class="th-moji col-moji">文字</th>
                    <th class="th-attr col-attr"><?php echo $get_sort_link('attr', '属性'); ?></th>
                    <th class="th-species col-species"><?php echo $get_sort_link('species', '種族'); ?></th>

                    <th class="th-stat col-hp99"><?php echo $get_sort_link('hp99', 'HP<span class="th-sub">99</span>'); ?></th>
                    <th class="th-stat col-atk99"><?php echo $get_sort_link('atk99', 'ATK<span class="th-sub">99</span>'); ?></th>
                    <th class="th-stat th-120 col-hp120"><?php echo $get_sort_link('hp120', 'HP<span class="th-sub">120</span>'); ?></th>
                    <th class="th-stat th-120 col-atk120"><?php echo $get_sort_link('atk120', 'ATK<span class="th-sub">120</span>'); ?></th>

                    <th class="th-stat col-ls-hp"><?php echo '最大' . $get_sort_link('ls_hp', 'L HP'); ?></th>
                    <th class="th-stat col-ls-atk"><?php echo '最大' . $get_sort_link('ls_atk', 'L ATK'); ?></th>
                    <th class="th-buff col-buff"><?php echo $get_sort_link('buff', 'バフ'); ?></th>
                    <th class="th-debuff col-debuff"><?php echo $get_sort_link('debuff', 'デバフ'); ?></th>
                    <th class="th-gimmick col-gimmick">ギミック</th>
                    <th class="th-cv col-cv">CV</th>
                    <th class="th-acq col-acq">入手</th>
                    <th class="th-date col-date"><?php echo $get_sort_link('date', '実装日'); ?></th>
                    <th class="th-power col-power"><?php echo $get_sort_link('power', '火力指数'); ?></th>
                </tr>
            </thead>
            <tbody id="chara-list-body">
                <?php if ($the_query->have_posts()) : ?>
                    <?php while ($the_query->have_posts()) : $the_query->the_post();
                        $json = get_post_meta(get_the_ID(), '_spec_json', true);
                        $spec = $json ? json_decode($json, true) : [];
                        $loop = 0;
                        while (is_string($spec) && $loop < 3) {
                            $spec = json_decode($spec, true);
                            $loop++;
                        }
                        if (!is_array($spec) || empty($spec)) continue;

                        $attr_html = $get_term_display_html($spec['attribute'] ?? '', 'attribute', 'attr');
                        $sub_attrs_html = [];
                        if (!empty($spec['sub_attributes'])) {
                            foreach ($spec['sub_attributes'] as $s) $sub_attrs_html[] = $get_term_display_html($s, 'attribute', 'attr', true);
                        }
                        $species_html = $get_term_display_html($spec['species'] ?? '', 'species', 'species');

                        $chars_html = [];
                        if (!empty($spec['chars'])) {
                            foreach ($spec['chars'] as $c) {
                                $val = esc_html($c['val']);
                                $suffix = ($c['unlock'] === 'super_copy') ? '<span class="char-suffix">(Sコ)</span>' : (($c['unlock'] === 'super_change') ? '<span class="char-suffix">(Sチ)</span>' : '');
                                $attr_slug = !empty($c['attr']) ? esc_attr($c['attr']) : 'none';
                                $link = '#';
                                if (!empty($c['slug'])) {
                                    $ct = get_term_by('slug', $c['slug'], 'available_moji');
                                    if ($ct) $link = get_term_link($ct);
                                }
                                $chars_html[] = "<a href='{$link}' class='char-link-item'><span class='char-font attr-{$attr_slug}'>{$val}</span>{$suffix}</a>";
                            }
                        }

                        $gimmick_htmls = [];
                        if (!empty($spec['traits'])) {
                            foreach ($spec['traits'] as $t) {
                                if ($t['type'] === 'gimmick' && !empty($t['gimmick_slug'])) {
                                    $gimmick_htmls[] = $get_term_display_html($t['gimmick_slug'], 'gimmick', 'gimmick');
                                }
                            }
                        }
                        $gimmick_htmls = array_unique($gimmick_htmls);

                        $b_min = $spec['buff_counts'][0] ?? 0;
                        $b_max = $spec['buff_counts'][5] ?? 0;
                        $d_min = $spec['debuff_counts'][0] ?? 0;
                        $d_max = $spec['debuff_counts'][5] ?? 0;
                        $buff_disp = ($b_max == 0) ? '<span class="text-muted">0</span>' : (($b_min == $b_max) ? "<span class='bd-val'>{$b_max}</span>" : "<span class='bd-val'>{$b_min}➡{$b_max}</span>");
                        $debuff_disp = ($d_max == 0) ? '<span class="text-muted">0</span>' : (($d_min == $d_max) ? "<span class='bd-val'>{$d_max}</span>" : "<span class='bd-val'>{$d_min}➡{$d_max}</span>");

                        $firepower = $spec['firepower_index'] ?? 0;
                        $fp_disp = ($firepower > 0) ? number_format($firepower) : '<span class="text-muted">-</span>';
                        $date = $spec['release_date'] ?? '-';

                        // ★Lv99とLv120のデータをそれぞれ取得
                        $hp99  = get_post_meta(get_the_ID(), '99_hp', true);
                        $atk99 = get_post_meta(get_the_ID(), '99_atk', true);
                        $hp120  = get_post_meta(get_the_ID(), '120_hp', true);
                        $atk120 = get_post_meta(get_the_ID(), '120_atk', true);

                        $val_hp99  = $hp99 ? number_format($hp99) : '<span class="text-muted">-</span>';
                        $val_atk99 = $atk99 ? number_format($atk99) : '<span class="text-muted">-</span>';
                        $val_hp120  = $hp120 ? number_format($hp120) : '<span class="text-muted">-</span>';
                        $val_atk120 = $atk120 ? number_format($atk120) : '<span class="text-muted">-</span>';

                        $ls_hp = !empty($spec['max_ls_hp']) ? number_format($spec['max_ls_hp']) . '%' : '<span class="text-muted">-</span>';
                        $ls_atk = !empty($spec['max_ls_atk']) ? number_format($spec['max_ls_atk']) . '%' : '<span class="text-muted">-</span>';
                        $cv = esc_html($spec['cv'] ?? '-');
                        $acq = esc_html($spec['acquisition'] ?? '-');
                        $thumb = has_post_thumbnail() ? get_the_post_thumbnail(get_the_ID(), 'thumbnail', ['class' => 'chara-thumb']) : '<div class="no-img"></div>';
                        $full_name = $spec['name'] ?? get_the_title();
                        $parts = explode('・', $full_name, 2);
                        $d_name = (count($parts) > 1) ? $parts[1] : $full_name;
                        $link = get_permalink();
                    ?>
                        <tr>
                            <td class="td-icon"><a href="<?php echo $link; ?>"><?php echo $thumb; ?></a></td>
                            <td class="td-name"><a href="<?php echo $link; ?>" class="chara-link"><?php echo esc_html($d_name); ?></a></td>
                            <td class="td-moji col-moji">
                                <div class="char-list"><?php echo implode('', $chars_html); ?></div>
                            </td>
                            <td class="td-attr col-attr">
                                <div class="attr-box-row"><?php echo $attr_html;
                                                            if ($sub_attrs_html) echo implode('', $sub_attrs_html); ?></div>
                            </td>
                            <td class="td-species col-species"><?php echo $species_html; ?></td>

                            <td class="td-stat hp-val col-hp99"><?php echo $val_hp99; ?></td>
                            <td class="td-stat atk-val col-atk99"><?php echo $val_atk99; ?></td>

                            <td class="td-stat hp-val-120 col-hp120"><?php echo $val_hp120; ?></td>
                            <td class="td-stat atk-val-120 col-atk120"><?php echo $val_atk120; ?></td>

                            <td class="td-stat ls-val col-ls-hp"><?php echo $ls_hp; ?></td>
                            <td class="td-stat ls-val col-ls-atk"><?php echo $ls_atk; ?></td>
                            <td class="td-buff buff-cell col-buff"><?php echo $buff_disp; ?></td>
                            <td class="td-debuff debuff-cell col-debuff"><?php echo $debuff_disp; ?></td>
                            <td class="td-gimmick col-gimmick">
                                <div class="gimmick-list"><?php echo implode('', $gimmick_htmls); ?></div>
                            </td>
                            <td class="td-cv col-cv"><?php echo $cv; ?></td>
                            <td class="td-acq col-acq"><?php echo $acq; ?></td>
                            <td class="td-date col-date"><?php echo $date; ?></td>
                            <td class="td-power col-power"><?php echo $fp_disp; ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="18" class="no-data">キャラクターが見つかりませんでした。</td>
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

<?php get_footer(); ?>

<script>
    // (JavaScriptは既存のまま変更なし)
    document.addEventListener('DOMContentLoaded', function() {
        const checkboxes = document.querySelectorAll('.col-toggle');
        const storageKey = 'koto_column_settings';

        const toggleColumn = (targetClass, isVisible) => {
            const cells = document.querySelectorAll('.' + targetClass);
            cells.forEach(cell => {
                if (isVisible) {
                    cell.classList.remove('col-hidden');
                } else {
                    cell.classList.add('col-hidden');
                }
            });
        };

        const loadSettings = () => {
            const saved = localStorage.getItem(storageKey);
            if (saved) {
                const hiddenCols = JSON.parse(saved);
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
            } else {
                checkboxes.forEach(cb => {
                    toggleColumn(cb.dataset.target, cb.checked);
                });
            }
        };

        const saveSettings = () => {
            const hiddenCols = [];
            checkboxes.forEach(cb => {
                if (!cb.checked) hiddenCols.push(cb.dataset.target);
            });
            localStorage.setItem(storageKey, JSON.stringify(hiddenCols));
        };

        checkboxes.forEach(cb => {
            cb.addEventListener('change', function() {
                toggleColumn(this.dataset.target, this.checked);
                saveSettings();
            });
        });

        loadSettings();

        const observerTarget = document.getElementById('scroll-observer');
        const loadingSpinner = document.getElementById('loading-spinner');
        const tableBody = document.getElementById('chara-list-body');
        const pageInput = document.getElementById('current-page');
        const maxPageInput = document.getElementById('max-pages');
        let isLoading = false;

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
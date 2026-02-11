<?php

/**
 * Template Name: 未入力記事リスト（詳細版）
 */

get_header();
$x_account_id = 'kotodamanDB';

/**
 * 判定用関数：指定された技データの配列内に、
 * type='attack' かつ valueが未入力(0 or 空)のものがあるかチェック
 * * @param array $move_data _spec_json内の [waza] や [sugowaza] の配列
 * @param bool $is_estimate 推定フラグ
 * @return boolean 未入力があれば true
 */
function is_attack_value_missing($move_data, $is_estimate = false)
{
    if ($is_estimate) return true;

    // データがない、または変な形式なら除外
    if (empty($move_data) || !is_array($move_data)) return false;
    if (empty($move_data['variations']) || !is_array($move_data['variations'])) return false;

    foreach ($move_data['variations'] as $variation) {
        if (empty($variation['timeline']) || !is_array($variation['timeline'])) continue;

        foreach ($variation['timeline'] as $action) {
            // typeキーがあり、かつ 'attack' を含む（attack, all_attack, random_attack等）
            if (isset($action['type']) && strpos($action['type'], 'attack') !== false) {
                // valueが 0, "0", "", null の場合は未入力とみなす
                if (empty($action['value'])) {
                    return true;
                }
            }
        }
    }
    return false;
}
?>

<div id="content" class="content">
    <main id="main" class="main">
        <article class="article">
            <header class="article-header">
                <h1 class="entry-title">【協力募集中】攻撃倍率が未調査のコトダマン一覧</h1>
            </header>

            <div class="entry-content">
                <p>以下のキャラクターは、データベース上の「攻撃倍率」が未調査の状態です。<br>
                    ゲーム内情報をお持ちの方は、各記事のコメントやX等で情報提供いただけるとありがたいです！<br>
                    倍率そのものの情報でも、できるだけシンプルな環境でのスクリーンショットでも大歓迎です！
                </p>
                <p>もし倍率を計算していただける方は、下記ツールをお使いください！</p>
                <ul>
                    <li><a href="https://kotodaman-db.com/magnification-calc/" target="_blank">倍率計算ツール</a></li>
                    <li><a href="https://youtu.be/xYLQZyMQngw?si=A6J5Z1Yv9ekMWedZ" target="_blank">倍率計算のチュートリアル動画</a></li>
                    <li><a href="https://x.com/Flare_kotodaman" target="_blank">⬆️提供者様のXアカウント</a></li>

                    <table class="unfilled-table">
                        <thead>
                            <tr>
                                <th>キャラクター名</th>
                                <th class="col-missing">未調査項目</th>
                                <th class="col-link">リンク</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // IDだけを取得して高速化
                            $args = array(
                                'post_type'      => 'character',
                                'post_status'    => 'publish',
                                'posts_per_page' => -1,
                                'fields'         => 'ids',
                            );

                            $the_query = new WP_Query($args);
                            $has_missing_data = false; // 1件でも見つかったかどうかのフラグ

                            if ($the_query->have_posts()) :
                                while ($the_query->have_posts()) : $the_query->the_post();
                                    $post_id = get_the_ID();

                                    // データ取得
                                    $raw_data = get_post_meta($post_id, '_spec_json', true);

                                    // 【修正点】データが文字列（JSON）ならデコード、配列ならそのまま使う
                                    if (is_string($raw_data)) {
                                        $data = json_decode($raw_data, true);
                                    } elseif (is_array($raw_data)) {
                                        $data = $raw_data;
                                    } else {
                                        $data = null;
                                    }

                                    $is_estimate = !empty($data['is_estimate']);
                                    $is_koto_estimate = !empty($data['is_koto_estimate']);

                                    // 各要素をチェック
                                    $missing_parts = [];

                                    // わざ
                                    if (isset($data['waza']) && is_attack_value_missing($data['waza'], $is_estimate)) {
                                        $missing_parts[] = 'わざ';
                                    }
                                    // すごわざ
                                    if (isset($data['sugowaza']) && is_attack_value_missing($data['sugowaza'], $is_estimate)) {
                                        $missing_parts[] = 'すごわざ';
                                    }
                                    // 3. ことわざ (配列構造に対応)
                                    if (isset($data['kotowaza']) && is_array($data['kotowaza'])) {
                                        // ことわざは [0], [1], [2]... とレベルごとに分かれているためループで確認
                                        foreach ($data['kotowaza'] as $k_level) {
                                            if (is_attack_value_missing($k_level, $is_koto_estimate)) {
                                                $missing_parts[] = 'ことわざ';
                                                break; // 1つでも未入力レベルがあれば「ことわざ」としてマークしてループを抜ける
                                            }
                                        }
                                    }

                                    // 何か一つでも欠けていれば表示
                                    if (!empty($missing_parts)) :
                                        $has_missing_data = true;
                                        // ★X投稿用のURLを作成
                                        $char_name = get_the_title($post_id);
                                        $tweet_text = "@" . $x_account_id . " 【情報提供】" . $char_name . "のデータについて\n";
                                        $x_url = 'https://twitter.com/intent/tweet?text=' . urlencode($tweet_text);
                            ?>
                                        <tr>
                                            <td><?php echo esc_html($char_name); ?></td>
                                            <td>
                                                <?php foreach ($missing_parts as $part): ?>
                                                    <span class="missing-tag"><?php echo esc_html($part); ?></span>
                                                <?php endforeach; ?>
                                            </td>
                                            <td style="white-space: nowrap; text-align: center;">
                                                <a href="<?php echo get_permalink($post_id); ?>" target="_blank" class="check-btn">確認</a>

                                                <a href="<?php echo esc_url($x_url); ?>" target="_blank" class="btn-x">Xで連絡</a>
                                            </td>
                                        </tr>
                            <?php
                                    endif;
                                endwhile;
                            endif;
                            wp_reset_postdata();
                            ?>
                        </tbody>
                    </table>

                    <?php if (!$has_missing_data) : ?>
                        <p class="all-clear-msg">現在、未調査のデータはありません。ご協力ありがとうございます！</p>
                    <?php endif; ?>
            </div>
        </article>
    </main>
</div>

<?php get_sidebar(); ?>
<?php get_footer(); ?>

<style>
    /* 未入力リストテーブルのスタイル */
    .unfilled-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
        font-size: 0.95em;
    }

    .unfilled-table th,
    .unfilled-table td {
        border: 1px solid #ddd;
        padding: 12px;
        text-align: left;
        vertical-align: middle;
    }

    .unfilled-table th {
        background-color: #f4f4f4;
        font-weight: bold;
        color: #333;
    }

    .unfilled-table tr:nth-child(even) {
        background-color: #fafafa;
    }

    .unfilled-table tr:hover {
        background-color: #f0f8ff;
    }

    /* カラム幅の調整 */
    .unfilled-table .col-missing {
        width: 30%;
    }

    .unfilled-table .col-link {
        width: 140px;
        /* ボタン2つ入るように少し広げました */
        text-align: center;
    }

    /* 未入力項目のタグ */
    .missing-tag {
        display: inline-block;
        background-color: #ffebee;
        color: #c62828;
        border: 1px solid #ffcdd2;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.85em;
        margin-right: 4px;
        margin-bottom: 4px;
    }

    /* 確認ボタン */
    .check-btn {
        display: inline-block;
        padding: 6px 10px;
        background-color: #7f8c8d;
        /* グレーに変更（メインじゃないので） */
        color: #fff;
        text-decoration: none;
        border-radius: 4px;
        font-size: 0.85em;
        margin: 2px;
        text-align: center;
    }

    .check-btn:hover {
        opacity: 0.8;
        color: #fff;
    }

    /* ★X連携用のボタン（追加） */
    .btn-x {
        display: inline-block;
        padding: 6px 10px;
        background-color: #000;
        /* Xの黒 */
        color: #fff;
        text-decoration: none;
        border-radius: 4px;
        font-size: 0.85em;
        margin: 2px;
        font-weight: bold;
        text-align: center;
    }

    .btn-x:hover {
        background-color: #333;
        color: #fff;
    }

    /* 全て完了時のメッセージ */
    .all-clear-msg {
        text-align: center;
        padding: 3em;
        font-weight: bold;
        color: #27ae60;
    }

    /* スマホ表示調整 */
    @media screen and (max-width: 600px) {

        .check-btn,
        .btn-x {
            display: block;
            margin: 4px auto;
            width: 80%;
        }
    }
</style>
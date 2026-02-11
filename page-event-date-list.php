<?php
/*
 * Template Name: イベント・キャラ一覧ページ
 */

get_header(); // 必要に応じて

// フィールドの取得
$events = get_field('event_loop');
if ($events) {
    echo '<div class="event-history-container">';

    $last_date_text = '';
    $last_event_name = ''; // ついでにイベント名用も定義しておくと安全です
    $is_section_open = false;

    // セクション開閉管理フラグ
    $is_section_open = false;

    foreach ($events as $event) {
        // 各フィールドの値を取得
        $main_name     = $event['event_name'];       // 大見出し
        $detail_name   = $event['event_detail_name']; // 小見出し（ガチャ名など）
        $date_raw      = $event['event_date'];       // 日付
        $characters    = $event['character_name_loop']; // キャラ名の繰り返しフィールド

        /* --- 日付の引き継ぎロジック (テキスト版) --- */
        // 入力があれば更新、空なら直前のテキストを使う
        if (! empty($date_input)) {
            $last_date_text = $date_input;
        } else {
            $date_input = $last_date_text;
        }

        // 表示用: 必要なら / を . に置換するなど整形しても良いですが、
        // 今回は入力されたテキストをそのまま表示します。
        $date_display = $date_input;

        // 表示用日付フォーマット作成 (d/m/Y -> Y.m.d)
        $date_display = '';
        if ($date_raw) {
            $date_obj = DateTime::createFromFormat('d/m/Y', $date_raw);
            if ($date_obj) {
                $date_display = $date_obj->format('Y.m.d');
            } else {
                // 万が一フォーマットが合わない場合はそのまま表示
                $date_display = $date_raw;
            }
        }

        /* --- 大見出し（セクション）の切り替わりロジック --- */
        // 大見出しが入力されている場合、新しいセクションを開始
        if (! empty($main_name)) {
            // 前のセクションが開いていれば閉じる
            if ($is_section_open) {
                echo '</div>'; // .event-group close
            }

            echo '<div class="event-group">';
            echo '<h2 class="event-main-title">' . esc_html($main_name) . '</h2>';

            $is_section_open = true;
        }
        // まだセクションが開いていない（最初の行が空の場合など）の安全策
        elseif (! $is_section_open) {
            echo '<div class="event-group">';
            $is_section_open = true;
        }

        /* --- 小見出し・日付・キャラリストの出力 --- */
        echo '<div class="event-row">';

        // ヘッダー部分（小見出し + 日付）
        echo '<div class="event-row-header">';
        echo '<span class="sub-name">' . esc_html($detail_name) . '</span>';
        if ($date_display) {
            echo '<span class="event-date">' . esc_html($date_display) . '</span>';
        }
        echo '</div>';

        // キャラクターリスト部分
        if ($characters) {
            echo '<ul class="character-list">';
            foreach ($characters as $char_item) {
                $char_name = $char_item['character_name'];
                if ($char_name) {
                    // 検索リンクにする場合は <a> タグなどをここに入れます
                    echo '<li class="character-tag">' . esc_html($char_name) . '</li>';
                }
            }
            echo '</ul>';
        }

        echo '</div>'; // .event-row close
    }

    // 最後のセクションを閉じる
    if ($is_section_open) {
        echo '</div>';
    }

    echo '</div>'; // .event-history-container close
}

get_footer(); // 必要に応じて
?>

<style>
    /* 全体のコンテナ */
    .event-history-container {
        max-width: 800px;
        margin: 30px auto;
        padding: 0 15px;
        font-family: "Helvetica Neue", Arial, sans-serif;
    }

    /* --- イベントグループ（大見出し単位） --- */
    .event-group {
        margin-bottom: 40px;
        background: #fff;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
    }

    /* 大見出し (event_name) */
    .event-main-title {
        background-color: #333;
        color: #fff;
        font-size: 1.2rem;
        padding: 12px 20px;
        margin: 0;
    }

    /* --- 各行（小見出し＋キャラ） --- */
    .event-row {
        padding: 20px;
        border-bottom: 1px solid #eee;
    }

    .event-row:last-child {
        border-bottom: none;
    }

    /* 行のヘッダー（小見出し ＋ 日付） */
    .event-row-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
        flex-wrap: wrap;
        gap: 10px;
    }

    .event-row-header .sub-name {
        font-size: 1.1rem;
        font-weight: bold;
        color: #444;
        border-left: 4px solid #d32f2f;
        /* アクセントカラー */
        padding-left: 10px;
    }

    .event-row-header .event-date {
        font-size: 0.9rem;
        color: #888;
        background: #f5f5f5;
        padding: 4px 10px;
        border-radius: 4px;
    }

    /* --- キャラクターリスト --- */
    .character-list {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    /* キャラクター名のバッジ */
    .character-tag {
        background-color: #e3f2fd;
        /* 薄い青 */
        color: #1565c0;
        /* 濃い青文字 */
        font-size: 0.9rem;
        padding: 6px 12px;
        border-radius: 20px;
        border: 1px solid #bbdefb;
    }
</style>
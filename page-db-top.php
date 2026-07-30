<?php
/*
Template Name: コトダマンDBトップ
*/
get_header();
?>

<div class="db-top-container">

    <div class="character-search-box">
        <?php get_search_form(); ?>
    </div>

    <section class="db-section main-links">
        <h2>データベース・ツール</h2>
        <div class="link-grid">
            <a href="<?php echo esc_url(home_url('/character/')); ?>" class="grid-item">
                <span class="icon">📊</span>
                <span class="text">全キャラ一覧<br></span>
            </a>
            <a href="<?php echo esc_url(home_url('/magnification-calc/')); ?>" class="grid-item">
                <span class="icon">⚔️</span>
                <span class="text">簡易ダメージ、倍率計算機</span>
            </a>
            <a href="<?php echo esc_url(home_url('/mgn-blank-charas/')); ?>" class="grid-item">
                <span class="icon">📋</span>
                <span class="text">未入力リスト<br><small>情報提供のご協力お願いします！</small></span>
            </a>
            <a href="https://discord.gg/cmjGCXe6u5" class="grid-item">
                <span class="icon">🗨️</span>
                <span class="text">運営discord<br><small>ご協力くださる方はぜひ！</small></span>
            </a>
        </div>

        <section class="db-section pickup-characters">
            <h2>ピックアップ</h2>

            <div class="new-char-grid">
                <?php
                // 1. 表示したい記事のIDをリスト（配列）で指定します
                // WordPress管理画面でキャラの編集画面を開いたときのURLにある「post=123」の数字です
                $pickup_ids = get_field('pickup_chara');

                // 2. クエリ作成
                $args = array(
                    'post_type'      => 'character', // カスタム投稿タイプ名
                    'post__in'       => $pickup_ids, // 指定したIDのみ取得
                    'orderby'        => 'post__in',  // 指定したIDの順番通りに並べる
                    'posts_per_page' => 9,           // 件数（配列の数と同じにしておけばOK）
                );
                $pickup_query = new WP_Query($args);
                ?>

                <?php if ($pickup_query->have_posts()) : ?>
                    <?php while ($pickup_query->have_posts()) : $pickup_query->the_post(); ?>

                        <a href="<?php the_permalink(); ?>" class="char-card">
                            <div class="char-icon-box">
                                <?php if (has_post_thumbnail()) : ?>
                                    <?php the_post_thumbnail('medium', array('class' => 'char-img')); ?>
                                <?php else : ?>
                                    <div class="no-img">No Image</div>
                                <?php endif; ?>
                            </div>
                            <div class="char-name">
                                <?php the_title(); ?>
                            </div>
                        </a>

                    <?php endwhile; ?>
                    <?php wp_reset_postdata(); ?>
                <?php else : ?>
                    <p>ピックアップ設定中...</p>
                <?php endif; ?>
            </div>
        </section>
        <section class="db-section main-links">
            <section class="db-section tax-links">
                <h2>属性で探す</h2>
                <div class="tax-grid">
                    <a href="<?php echo home_url('/?post_type=character&s=&search_char=&tx_attr%5B%5D=fire&scope_skill%5B%5D=waza&scope_skill%5B%5D=sugo&scope_skill%5B%5D=kotowaza&scope_trait%5B%5D=t1&scope_trait%5B%5D=t2&scope_trait%5B%5D=blessing'); ?>" class="tax-btn fire">火</a>
                    <a href="<?php echo home_url('/?post_type=character&s=&search_char=&tx_attr%5B%5D=water&scope_skill%5B%5D=waza&scope_skill%5B%5D=sugo&scope_skill%5B%5D=kotowaza&scope_trait%5B%5D=t1&scope_trait%5B%5D=t2&scope_trait%5B%5D=blessing'); ?>" class="tax-btn water">水</a>
                    <a href="<?php echo home_url('/?post_type=character&s=&search_char=&tx_attr%5B%5D=wood&scope_skill%5B%5D=waza&scope_skill%5B%5D=sugo&scope_skill%5B%5D=kotowaza&scope_trait%5B%5D=t1&scope_trait%5B%5D=t2&scope_trait%5B%5D=blessing'); ?>" class="tax-btn wood">木</a>
                    <a href="<?php echo home_url('/?post_type=character&s=&search_char=&tx_attr%5B%5D=light&scope_skill%5B%5D=waza&scope_skill%5B%5D=sugo&scope_skill%5B%5D=kotowaza&scope_trait%5B%5D=t1&scope_trait%5B%5D=t2&scope_trait%5B%5D=blessing'); ?>" class="tax-btn light">光</a>
                    <a href="<?php echo home_url('/?post_type=character&s=&search_char=&tx_attr%5B%5D=dark&scope_skill%5B%5D=waza&scope_skill%5B%5D=sugo&scope_skill%5B%5D=kotowaza&scope_trait%5B%5D=t1&scope_trait%5B%5D=t2&scope_trait%5B%5D=blessing'); ?>" class="tax-btn dark">闇</a>
                    <a href="<?php echo home_url('/?post_type=character&s=&search_char=&tx_attr%5B%5D=heaven&scope_skill%5B%5D=waza&scope_skill%5B%5D=sugo&scope_skill%5B%5D=kotowaza&scope_trait%5B%5D=t1&scope_trait%5B%5D=t2&scope_trait%5B%5D=blessing'); ?>" class="tax-btn heaven">天</a>
                    <a href="<?php echo home_url('/?post_type=character&s=&search_char=&tx_attr%5B%5D=void&scope_skill%5B%5D=waza&scope_skill%5B%5D=sugo&scope_skill%5B%5D=kotowaza&scope_trait%5B%5D=t1&scope_trait%5B%5D=t2&scope_trait%5B%5D=blessing'); ?>" class="tax-btn void">冥</a>
                </div>
            </section>

            <section class="db-section tax-links">
                <h2>種族で探す</h2>
                <div class="tax-grid">
                    <a href="<?php echo home_url('/?post_type=character&s=&search_char=&tx_species%5B%5D=god'); ?>" class="tax-btn spe">神</a>
                    <a href="<?php echo home_url('/?post_type=character&s=&search_char=&tx_species%5B%5D=demon'); ?>" class="tax-btn spe">魔</a>
                    <a href="<?php echo home_url('/?post_type=character&s=&search_char=&tx_species%5B%5D=hero'); ?>" class="tax-btn spe">英</a>
                    <a href="<?php echo home_url('/?post_type=character&s=&search_char=&tx_species%5B%5D=dragon'); ?>" class="tax-btn spe">龍</a>
                    <a href="<?php echo home_url('/?post_type=character&s=&search_char=&tx_species%5B%5D=beast'); ?>" class="tax-btn spe">獣</a>
                    <a href="<?php echo home_url('/?post_type=character&s=&search_char=&tx_species%5B%5D=spirit'); ?>" class="tax-btn spe">霊</a>
                    <a href="<?php echo home_url('/?post_type=character&s=&search_char=&tx_species%5B%5D=artifact'); ?>" class="tax-btn spe">物</a>
                    <a href="<?php echo home_url('/?post_type=character&s=&search_char=&tx_species%5B%5D=yokai'); ?>" class="tax-btn spe">妖</a>
                </div>
            </section>

            <section class="db-section tax-links rarity">
                <h2>レアリティで探す</h2>
                <div class="tax-grid">
                    <a href="<?php echo home_url('/?post_type=character&s=&search_char=&tx_rarity%5B%5D=grand'); ?>" class="tax-btn grand">グランド</a>
                    <a href="<?php echo home_url('/?post_type=character&s=&search_char=&tx_rarity%5B%5D=legend'); ?>" class="tax-btn legend">レジェンド</a>
                    <a href="<?php echo home_url('/?post_type=character&s=&search_char=&tx_rarity%5B%5D=dream'); ?>" class="tax-btn dream">ドリーム</a>
                    <a href="<?php echo home_url('/?post_type=character&s=&search_char=&tx_rarity%5B%5D=miracle'); ?>" class="tax-btn miracle">ミラクル</a>
                    <a href="<?php echo home_url('/?post_type=character&s=&search_char=&tx_rarity%5B%5D=special'); ?>" class="tax-btn special">スペシャル</a>
                    <a href="<?php echo home_url('/?post_type=character&s=&search_char=&tx_rarity%5B%5D=none'); ?>" class="tax-btn none">なし</a>
                </div>
            </section>

            <section class="db-section tax-links">
                <h2>ギミックで探す</h2>
                <div class="tax-grid gimmick-grid">
                    <?php
                    $gimmicks = [
                        'wall'      => 'ウォール',
                        'copy'      => 'コピー',
                        'shield'    => 'シールド',
                        'smash'     => 'スマッシュ',
                        'change'    => 'チェンジ',
                        'needle'    => 'トゲ',
                        'balloon'   => 'バルーン',
                        'healing'   => 'ヒール',
                        'shock'     => 'ビリビリ',
                        'freezing'  => 'フリーズ',
                        'landmine'  => '地雷',
                        'weakening' => '弱体',
                    ];

                    foreach ($gimmicks as $slug => $label) :
                        $normal_url = home_url("/?post_type=character&s=&search_char=&tx_gimmick%5B%5D=" . $slug);
                        // ご提示の規則性に基づき、スーパーのスラッグは "super_" + 通常スラッグ とする
                        $super_url  = home_url('/?post_type=character&s=&search_char=&tx_gimmick%5B%5D=super_' . $slug);
                    ?>
                        <div class="split-btn-wrapper">
                            <a href="<?php echo esc_url($normal_url); ?>" class="tax-btn gim-main">
                                <?php echo esc_html($label); ?>
                            </a>
                            <a href="<?php echo esc_url($super_url); ?>" class="tax-btn gim-sub" title="スーパー<?php echo esc_attr($label); ?>">
                                S
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <section class="db-section tax-links">
                <h2>その他分類で探す</h2>
                <div class="tax-grid">
                    <a href="<?php echo home_url('/affiliation-list/'); ?>" class="tax-btn other">
                        グループ
                    </a>

                    <a href="<?php echo home_url('/event-list/'); ?>" class="tax-btn other">
                        イベント
                    </a>

                </div>
            </section>
            <section class="db-section new-arrivals">
                <h2>🆕 新着キャラクター</h2>

                <div class="new-char-grid">
                    <?php
                    // 1. データベースへのクエリ（PythonでいうSQL発行）
                    // 最新の投稿を6件取得する設定
                    $args = array(
                        'post_type'      => 'character', // ※カスタム投稿タイプを使っている場合は 'character' 等に変更
                        'posts_per_page' => 12,      // 取得件数
                        'orderby'        => 'date', // 日付順
                        'order'          => 'DESC', // 新しい順
                    );
                    $the_query = new WP_Query($args);
                    ?>

                    <?php if ($the_query->have_posts()) : ?>
                        <?php while ($the_query->have_posts()) : $the_query->the_post(); ?>

                            <a href="<?php the_permalink(); ?>" class="char-card">
                                <div class="char-icon-box">
                                    <?php if (has_post_thumbnail()) : ?>
                                        <?php the_post_thumbnail('medium', array('class' => 'char-img')); ?>
                                    <?php else : ?>
                                        <div class="no-img">No Image</div>
                                    <?php endif; ?>
                                </div>
                                <div class="char-name">
                                    <?php the_title(); ?>
                                </div>
                            </a>

                        <?php endwhile; ?>
                        <?php wp_reset_postdata(); // おまじない（クエリのリセット） 
                        ?>

                    <?php else : ?>
                        <p>記事がまだありません。</p>
                    <?php endif; ?>
                </div>
            </section>

            <div class="site-credits">
                <div class="credit-group">
                    <h3>画像提供者</h3>
                    <ul>
                        <li><a href="https://x.com/hiromaru_desu" target="_blank" rel="noopener">ひろまる</a></li>
                        <li><a href="https://youtube.com/@pirokkio?si=4ZZrnY1ln3oK44b5" target="_blank" rel="noopener">ENGINE</a></li>
                    </ul>
                </div>

                <div class="credit-group">
                    <h3>協力者（一部）</h3>
                    <ul>
                        <li><a href="https://x.com/flare_kotodaman?s=21" target="_blank" rel="noopener">フレア</a></li>
                        <li><a href="https://x.com/seseragi_ryu?s=21&t=C2_yXfPCs-K36PW-bK_3vQ" target="_blank" rel="noopener">せせらぎ</a></li>
                        <li><a href="https://www.youtube.com/@ろうこと" target="_blank" rel="noopener">さめことば</a></li>
                    </ul>
                </div>

                <div class="credit-group">
                    <h3>参考資料</h3>
                    <ul>
                        <li><a href="https://note.com/tenboss/n/na4d4cb959700" target="_blank">コトダマン ダメージ計算｜コトダマン コトワリ攻略</a></li>
                        <li><a href="https://gist.github.com/uwi/bac443c170a965af561d787f6b6b5227" target="_blank">コトダマン ダメージ計算</a></li>
                        <li><a href="https://sigurekotodaman.hatenablog.com/entry/2024/04/17/010102" target="_blank">中級コトダマーのコトダマン性能解説｜昇華によるステータス上昇について【コトダマン】</a></li>
                    </ul>
                </div>
            </div>
</div>

<style>
    /* 全体レイアウト */
    .db-top-container {
        max-width: 1000px;
        margin: 0 auto;
        padding: 20px;
    }

    .db-section {
        margin-bottom: 40px;
    }

    .db-section h2 {
        border-left: 6px solid #444;
        padding-left: 12px;
        margin-bottom: 20px;
        font-size: 1.4em;
        font-weight: bold;
        margin-top: 20px;
    }

    /* 検索バー */
    .search-form {
        display: flex;
        gap: 8px;
        max-width: 600px;
        margin: 0 auto 40px;
    }

    .search-field {
        flex: 1;
        padding: 12px;
        font-size: 16px;
        border: 2px solid #ddd;
        border-radius: 4px;
    }

    .search-submit {
        padding: 0 25px;
        background: #333;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-weight: bold;
    }

    /* グリッドリンク（ここがキモ！） */
    .link-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
        /* 画面幅に合わせて自動折り返し */
        gap: 15px;
    }

    .grid-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        background: #fff;
        border: 1px solid #e5e5e5;
        padding: 20px 10px;
        text-decoration: none;
        color: #333;
        border-radius: 8px;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        transition: transform 0.2s, box-shadow 0.2s;
        text-align: center;
        height: 100%;
        /* 高さを揃える */
    }

    .grid-item:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        background: #fdfdfd;
    }

    .grid-item .icon {
        font-size: 2.2em;
        margin-bottom: 8px;
        display: block;
    }

    .grid-item .text {
        font-weight: bold;
        font-size: 0.95em;
        line-height: 1.4;
    }

    .grid-item .text small {
        color: #888;
        font-size: 0.8em;
        font-weight: normal;
    }

    /* 属性ボタン */
    .tax-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        justify-content: flex-start;
    }

    .tax-btn {
        padding: 8px 24px;
        background: #f0f0f0;
        text-decoration: none;
        color: #333;
        border-radius: 50px;
        font-weight: bold;
        transition: 0.2s;
        border: 1px solid #ddd;
    }

    .tax-btn:hover {
        background: #e0e0e0;
        transform: scale(1.05)translateY(-2px);
    }

    .tax-btn.grand {
        border-bottom: 4px solid #db0000;
        color: #e9b125;
        /* 少しだけ文字を光らせる演出（お好みで消してもOK） */
        text-shadow: 0 1px 0 rgba(255, 255, 255, 0.8);
    }

    .tax-btn.legend {
        border-bottom: 4px solid #66b5ffff;
        color: #e9b125;
        /* 少しだけ文字を光らせる演出（お好みで消してもOK） */
        text-shadow: 0 1px 0 rgba(255, 255, 255, 0.8);
    }

    .tax-btn.special {
        border-bottom: 4px solid #66b5ffff;
    }

    .tax-btn.dream {
        border-bottom: 4px solid rgb(232 225 106);
    }
    
    .tax-btn.miracle {
        border-bottom: 4px solid rgb(106, 232, 226);
    }

    .tax-btn.spe {
        border-bottom: 4px solid #d6a054ff;
    }

    /* 属性ごとの色分け */
    .tax-btn.fire {
        border-bottom: 4px solid #ff4d4d;
    }

    .tax-btn.water {
        border-bottom: 4px solid #4da6ff;
    }

    .tax-btn.wood {
        border-bottom: 4px solid #4dff88;
    }

    .tax-btn.light {
        border-bottom: 4px solid #ffff4d;
    }

    .tax-btn.dark {
        border-bottom: 4px solid #a64dff;
    }

    .tax-btn.heaven {
        border-bottom: 4px solid #c1feffff;
    }

    .tax-btn.void {
        border-bottom: 4px solid #6d2273ff;
    }

    /* その他分類の色（シックなグレー系にしていますが、好きな色に変えてOKです） */
    .tax-btn.other {
        border-bottom: 4px solid #607d8b;
    }

    /* 新着キャラのグリッド表示 */
    .new-char-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
        /* スマホでも見やすいサイズ */
        gap: 15px;
    }

    /* キャラカード */
    .char-card {
        display: block;
        text-decoration: none;
        color: #333;
        text-align: center;
        transition: 0.2s;
    }

    .char-card:hover {
        transform: translateY(-3px);
        opacity: 0.8;
    }

    /* アイコン枠（立ち絵をトリミングする窓） */
    .char-icon-box {
        width: 100%;
        aspect-ratio: 1 / 1;
        /* 正方形にする */
        border-radius: 10px;
        /* 角丸 */
        overflow: hidden;
        /* はみ出した部分をカット */
        border: 2px solid #eee;
        background: #f0f0f0;
        margin-bottom: 5px;
    }

    /* 画像本体 */
    .char-img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        object-position: center 26%;
        transform: scale(1.2);
        transform-origin: 50%;
    }

    /* 画像なしの場合 */
    .no-img {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #aaa;
        font-size: 0.8em;
    }

    /* キャラ名 */
    .char-name {
        font-size: 0.85em;
        font-weight: bold;
        line-height: 1.3;
        /* 2行以上は省略する処理 */
        display: -webkit-box;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    /* クレジットセクション全体の枠 */
    .site-credits {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        margin-top: 50px;
        padding: 30px;
        background-color: #f7f7f7;
        border-top: 2px solid #ddd;
    }

    /* 各グループ（画像提供者など）の塊 */
    .credit-group {
        flex: 1;
        min-width: 200px;
    }

    /* 見出しのデザイン */
    .credit-group h3 {
        font-size: 1.1rem;
        font-weight: bold;
        margin-bottom: 10px;
        padding-bottom: 5px;
        border-bottom: 1px solid #ccc;
        color: #333;
    }

    /* リストの余白調整 */
    .credit-group ul {
        margin: 0;
        padding-left: 0;
        list-style: none;
    }

    .credit-group li {
        font-size: 0.9rem;
        margin-bottom: 5px;
        color: #555;
    }

    /* グリッドレイアウトの調整（既存のtax-gridの設定によるが、念のため） */
    .gimmick-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    /* ラッパー：2つのボタンをくっつけて表示 */
    .split-btn-wrapper {
        display: flex;
        align-items: stretch;
        /* 高さを揃える */
    }

    /* メインボタン（左側） */
    .gim-main {
        border-radius: 4px 0 0 4px;
        /* 右側の角を丸めない */
        padding: 10px 15px;
        background: #f0f0f0;
        /* 既存の色に合わせてください */
        color: #000;
        text-decoration: none;
        flex-grow: 1;
        text-align: center;
    }

    /* サブボタン（右側・スーパー用） */
    .gim-sub {
        border-radius: 0 4px 4px 0;
        /* 左側の角を丸めない */
        padding: 10px;
        background: #ffbd59;
        /* スーパーっぽい赤色など */
        color: #fff;
        text-decoration: none;
        font-weight: bold;
        font-size: 0.8em;
        display: flex;
        align-items: center;
        border-left: 1px solid rgba(255, 255, 255, 0.3);
        /* 区切り線 */
    }

    /* ホバー時の挙動 */
    .gim-main:hover {
        opacity: 0.8;
    }

    .gim-sub:hover {
        background: #f4b658ff
    }
</style>


<?php get_footer(); ?>

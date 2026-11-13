<?php
// モーダル表示用コード（フッターに出力）
function add_contribution_modal_script()
{
    //ログイン中の管理者には表示しない（作業の邪魔になるため）
    if (is_user_logged_in()) {
        return;
    }
?>

    <div id="collab-modal" class="collab-modal-overlay" style="display:none;">
        <div class="collab-modal-content">
            <span class="collab-modal-close">&times;</span>

            <h3 class="collab-title">🙏 情報提供のお願い</h3>
            <p>コトダマンDBの充実にご協力ください！<br>
                現在、攻撃/回復倍率が未入力のキャラが多数います。<br>
                その他作業をしてくださる協力者も随時募集しています！</p>

            <div class="collab-buttons">
                <a href="/mgn-blank-charas/" class="collab-btn btn-check">
                    📋 未入力リストを見る
                </a>

                <?php
                $collab_page_url = function_exists('koto_mcp_collaborate_page_url')
                    ? koto_mcp_collaborate_page_url()
                    : home_url('/collaborate/'); // //要チェック 本番の協力案内ページURL確認
                ?>
                <a href="<?php echo esc_url($collab_page_url); ?>" class="collab-btn btn-register">
                    📝 協力者登録・AI連携ガイドを見る
                </a>

                <a href="https://discord.gg/cmjGCXe6u5" target="_blank" class="collab-btn btn-discord">
                    💬 Discordに参加する
                </a>
            </div>

            <p class="collab-note">画面外をクリックすると閉じます。</p>
        </div>
    </div>

    <style>
        /* 画面全体を覆うオーバーレイ */
        .collab-modal-overlay {
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            /* 半透明の黒背景 */
            display: flex;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(2px);
            /* 背景を少しぼかす */
            animation: fadeIn 0.3s ease;
        }

        /* モーダル本体 */
        .collab-modal-content {
            background-color: #fff;
            padding: 25px;
            padding-bottom: 10px;
            border-radius: 12px;
            width: 85%;
            max-width: 450px;
            text-align: center;
            position: relative;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s ease;
        }

        /* タイトル */
        .collab-title {
            margin-top: 0;
            color: #333;
            font-size: 1.4em;
        }

        /* ボタンエリア */
        .collab-buttons {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin: 20px 0;
        }

        /* 共通ボタンデザイン */
        .collab-btn {
            display: block;
            padding: 12px;
            text-decoration: none;
            color: #fff;
            border-radius: 6px;
            font-weight: bold;
            transition: transform 0.1s;
        }

        .collab-btn:hover {
            transform: translateY(-2px);
            color: #fff;
        }

        /* リストボタン色 */
        .btn-check {
            background-color: #e67e22;
        }

        /* 登録・AI連携ガイドボタン色 */
        .btn-register {
            background-color: #135e96;
        }

        /* Discordボタン色 */
        .btn-discord {
            background-color: #5865F2;
            /* Discord公式カラー */
        }

        /* 閉じるボタン（×） */
        .collab-modal-close {
            position: absolute;
            top: 10px;
            right: 15px;
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .collab-modal-close:hover {
            color: #000;
        }

        /* 注釈 */
        .collab-note {
            font-size: 0.8em;
            color: #888;
            margin-bottom: 0;
        }

        /* アニメーション定義 */
        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes slideUp {
            from {
                transform: translateY(20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // ★表示確率の設定（%）
            // 例: 30なら30%の確率で表示
            const SHOW_PROBABILITY = 30;

            const modal = document.getElementById('collab-modal');
            const closeBtn = document.querySelector('.collab-modal-close');

            // 確率判定 (100分の1〜100 の乱数が 設定値以下なら表示)
            const randomVal = Math.floor(Math.random() * 100) + 1;

            // 開発者ツールなどで確認しやすいようログを出力（本番では消してもOK）
            // console.log('Modal check:', randomVal, '<=', SHOW_PROBABILITY);

            if (randomVal <= SHOW_PROBABILITY) {
                modal.style.display = 'flex';
            }

            // 1. ×ボタンで閉じる
            closeBtn.onclick = function() {
                modal.style.display = "none";
            }

            // 2. モーダルの外側（背景）をクリックしたら閉じる（ストレス軽減）
            window.onclick = function(event) {
                if (event.target == modal) {
                    modal.style.display = "none";
                }
            }
        });
    </script>
<?php
}
add_action('wp_footer', 'add_contribution_modal_script');

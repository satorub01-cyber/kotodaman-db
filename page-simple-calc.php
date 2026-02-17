<?php
/*
 * Template Name: ダメージ計算・倍率逆算ツール
 */
get_header();
?>

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
    /* 基本レイアウト */
    .calc-container {
        padding: 15px;
        max-width: 600px;
        margin: 0 auto;
        font-family: sans-serif;
    }

    .calc-group {
        margin-bottom: 12px;
        border-bottom: 1px solid #eee;
        padding-bottom: 8px;
    }

    .calc-group label {
        display: block;
        font-weight: bold;
        margin-bottom: 4px;
        font-size: 14px;
    }

    .calc-group input,
    .calc-group select {
        width: 100%;
        padding: 8px;
        font-size: 16px;
        box-sizing: border-box;
        border: 1px solid #ccc;
        border-radius: 4px;
    }

    .input-row {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    .input-row>div {
        flex: 1;
    }

    .intro-text {
        font-size: 14px;
        line-height: 1.6;
        background: #fffbe6;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        border: 1px solid #ffe58f;
    }

    /* 見た目の調整 */
    .dynamic-row {
        display: flex;
        gap: 5px;
        margin-top: 5px;
        align-items: center;
    }

    .dynamic-label {
        font-size: 12px;
        width: 60px;
        color: #555;
    }

    .hidden {
        display: none !important;
    }

    /* ボタン */
    .btn-calc {
        width: 100%;
        padding: 12px;
        background-color: #e9a242;
        color: white;
        font-size: 16px;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: bold;
        margin-bottom: 20px;
    }

    .btn-verify {
        width: 100%;
        padding: 12px;
        background-color: #f8b862;
        color: white;
        font-size: 16px;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: bold;
    }

    /* 結果エリア */
    .result-box {
        margin: 20px 0;
        padding: 15px;
        background-color: #f0f0f1;
        border-left: 5px solid #e9a242;
        text-align: center;
    }

    .result-val {
        font-size: 32px;
        font-weight: bold;
        color: #d63638;
        margin: 10px 0;
    }

    /* 検算エリア */
    .verify-area {
        margin-top: 30px;
        padding: 15px;
        background-color: #f7ede3;
        border: 2px dashed #d67b00;
        border-radius: 8px;
    }

    .verify-title {
        font-weight: bold;
        margin-bottom: 10px;
        display: block;
        text-align: center;
        color: #e9a242;
    }

    .small-note {
        font-size: 11px;
        color: #666;
        margin-top: 2px;
    }

    /* 参考リンク・倍率表エリア */
    .footer-info {
        margin-top: 40px;
        border-top: 1px solid #ddd;
        padding-top: 20px;
    }

    .footer-info h4 {
        margin-bottom: 10px;
        color: #333;
    }

    .ref-list {
        font-size: 14px;
        margin-bottom: 20px;
        padding-left: 20px;
    }

    .ref-list li {
        margin-bottom: 5px;
    }

    .ref-list a {
        color: #0073aa;
        text-decoration: underline;
    }

    /* 画像表示ボックス */
    .chart-box {
        background-color: #fff;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 15px;
        text-align: center;
        margin-top: 20px;
    }

    .chart-img {
        max-width: 100%;
        /* スマホからはみ出さないように */
        height: auto;
        border: 1px solid #eee;
        margin-bottom: 5px;
        border-radius: 4px;
    }

    .source-cite {
        font-size: 11px;
        color: #888;
        text-align: right;
        /* 右寄せで出典っぽく */
        margin: 0;
        line-height: 1.4;
    }

    .source-cite a {
        color: #666;
        text-decoration: underline;
    }

    /* 更新予定バッジのデザイン */
    .status-badge {
        font-size: 14px;
        color: #666;
        padding-left: 10px;

    }

    .buff_input {
        width: 50%;
    }
</style>

<div class="calc-container">
    <h2>倍率逆算 & 検算ツール</h2>
    <div class="intro-text">
        <strong>【使い方】</strong><br>
        ① <strong>倍率を知りたい時:</strong> 実際のダメージと補正値を入力して「逆算」ボタンを押してください。<br>
        ② <strong>ダメージを知りたい時:</strong> わざ倍率を入力して「検算（シミュレーション）」ボタンを押してください。<br>
        ③ <strong>キラーについて:</strong> とくせい、おまもり、コトワリなどの各種キラー、ダメージ倍率をすべて加算して記入してください。
    </div>
    <form id="dmgForm">
        <div class="calc-group">
            <label>画面のダメージ値 <span style="font-weight:normal; font-size:12px;">(※逆算時のみ必須)</span></label>
            <input type="number" id="actual_damage" placeholder="例: 154308">
        </div>

        <div class="calc-group">
            <label>基礎パラメータ</label>
            <div class="input-row">
                <div>
                    <input type="number" id="base_atk" placeholder="元ATK" value="1000">
                    <div class="small-note">元ATK</div>
                </div>
                <div>
                    <input type="number" id="add_atk" placeholder="加算" value="0">
                    <div class="small-note">固定値補正</div>
                </div>
                <div>
                    <input type="number" id="parcentage_atk" placeholder="加算" value="0">
                    <div class="small-note">%補正</div>
                </div>
            </div>
        </div>

        <div class="calc-group">
            <div class="input-row" style="justify-content: space-between;">
                <label>リーダー倍率 (%)</label>
                <div class=small-note>丸数字で分けられてる倍率は分けて記入</div>
                <select id="leader_count_selector" style="width:auto; padding:4px;" onchange="toggleLeaderInputs()">
                    <option value="1">1枠</option>
                    <option value="2">2枠</option>
                    <option value="3">3枠</option>
                    <option value="4">4枠</option>
                    <option value="5">5枠</option>
                </select>
            </div>

            <div id="leader_inputs_area">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <div class="dynamic-row <?php if ($i > 1) echo 'hidden'; ?>" id="leader_row_<?php echo $i; ?>">
                        <span class="dynamic-label">枠<?php echo $i; ?></span>
                        <input type="number" id="leader_buff_<?php echo $i; ?>" placeholder="%" value="0">
                    </div>
                <?php endfor; ?>
            </div>
        </div>

        <div class="calc-group">
            <label>コンボ数</label>
            <input type="number" id="combo_count" step="1" value="1" placeholder="1">
        </div>

        <div class="calc-group">
            <label>バフ・デバフ数</label>
            <div class="input-row">
                <label class='buff_input'>バフ数</label>
                <input type="number" id="buff_count" step="1" value="0" placeholder="0">
                <label class='buff_input'>デバフ数</label>
                <input type="number" id="debuff_count" step="1" value="0" placeholder="0">
            </div>
        </div>

        <div class="calc-group">
            <label>キラー・フィールド補正 (%) <span style="font-weight:normal; font-size:11px;">※全て合算</span></label>
            <div class="input-row">
                <div>
                    <input type="number" id="killer_percent_main" step="1" value="0" placeholder="キラー">
                    <div class="small-note">各種補正</div>
                </div>
                <div>
                    <input type="number" id="killer_percent_17" step="1" value="17">
                    <div class="small-note">満福ロード(相手が英獣霊以外16)</div>
                </div>
                <div>
                    <input type="number" id="killer_percent_4" step="1" value="4">
                    <div class="small-note">メモリー補正(コラボなどは+5)</div>
                </div>
            </div>
            <div style="margin-top:8px;">
                <input type="number" id="field_percent" step="1" value="0" placeholder="10">
                <div class="small-note">フィールド(%) ※各種補正と足し算されます</div>
            </div>
        </div>

        <div class="calc-group">
            <label>その他補正 (属性・倍率)</label>
            <div class="input-row">
                <div>
                    <select id="elem_mult">
                        <option value="2.0">有利(2倍)</option>
                        <option value="1.0">等倍</option>
                        <option value="0.5">不利(1/2倍)</option>
                    </select>
                </div>
                <div>
                    <select id="other_mult_extra">
                        <option value="1.0">等倍</option>
                        <option value="1.5">クリティカル</option>
                        <option value="1.5">言気ハツラツ</option>
                        <option value="2.25">言気ハツラツかつクリティカル</option>
                        <option value="3.0">塊心の一撃</option>
                    </select>
                </div>
            </div>
        </div>

        <button type="button" class="btn-calc" onclick="calcReverse()">① 倍率を逆算する</button>

        <div id="reverseResultArea" class="result-box" style="display:none;">
            <div>推定わざ倍率</div>
            <div id="calcResult" class="result-val">---</div>
            <div class="small-note">※繰り上げの影響で微細な誤差が出ます</div>
        </div>

        <div class="verify-area">
            <span class="verify-title">▼ 検算・ダメージ計算 ▼</span>
            <div class="calc-group">
                <label>わざ倍率</label>
                <input type="number" id="verify_rate" step="0.01" placeholder="例: 5.5">
            </div>
            <button type="button" class="btn-verify" onclick="calcVerify()">② ダメージ計算</button>
            <div id="verifyResultArea" style="margin-top:10px; display:none; text-align:center;">
                計算上のダメージ:
                <div id="verifyResultVal" class="result-val" style="color:#0073aa; font-size:24px;">0</div>
                <div id="verifyDiff" style="font-weight:bold;"></div>
            </div>
        </div>
    </form>
    <h4>
        倍率早見表
        <span class="status-badge">※調査でき次第、貼り替え予定</span>
    </h4>
    <div class="chart-box">
        <img src="https://www.kotodaman-db.com/wp-content/uploads/2025/12/kotodaman-magnification-pre.webp" alt="倍率早見表" class="chart-img">

        <p class="source-cite">
            画像出典: <a href="https://note.com/tenboss/n/na4d4cb959700" target="_blank">コトダマン ダメージ計算｜コトダマン コトワリ攻略 様より</a>
        </p>
    </div>

</div>
<div class="footer-info">

    <h4>参考サイト・情報元</h4>
    <ul class="ref-list">
        <li><a href="https://note.com/tenboss/n/na4d4cb959700" target="_blank">コトダマン ダメージ計算｜コトダマン コトワリ攻略</a></li>
        <li><a href="https://gist.github.com/uwi/bac443c170a965af561d787f6b6b5227" target="_blank">コトダマン ダメージ計算</a></li>
    </ul>

</div>
</div>

<script>
    // リーダー枠の表示切替
    function toggleLeaderInputs() {
        const count = parseInt(document.getElementById('leader_count_selector').value);
        for (let i = 1; i <= 5; i++) {
            const row = document.getElementById('leader_row_' + i);
            if (i <= count) {
                row.classList.remove('hidden');
            } else {
                row.classList.add('hidden');
            }
        }
    }

    // パラメータ取得
    function getParams() {
        // リーダー (配列)
        const leaderCount = parseInt(document.getElementById('leader_count_selector').value);
        let leaderBuffs = [];
        for (let i = 1; i <= leaderCount; i++) {
            let val = parseFloat(document.getElementById('leader_buff_' + i).value) || 0;
            leaderBuffs.push(val / 100);
        }

        // キラー + フィールド (全て加算)
        const kMain = parseFloat(document.getElementById('killer_percent_main').value) || 0;
        const k17 = parseFloat(document.getElementById('killer_percent_17').value) || 0; // 初期値17
        const k4 = parseFloat(document.getElementById('killer_percent_4').value) || 0; // 初期値4
        const fieldP = parseFloat(document.getElementById('field_percent').value) || 0;

        // 合計％を計算
        const totalPercent = kMain + k17 + k4 + fieldP;

        // 倍率に変換 (100%UP = 2.0倍)
        const correctionMult = 1 + (totalPercent / 100);

        return {
            dmg: parseFloat(document.getElementById('actual_damage').value) || 0,
            baseAtk: parseFloat(document.getElementById('base_atk').value) || 0,
            addAtk: parseFloat(document.getElementById('add_atk').value) || 0,
            parcentage_atk: parseFloat(document.getElementById('parcentage_atk').value) || 0,

            leaders: leaderBuffs,

            comboCount: parseFloat(document.getElementById('combo_count').value) || 1.0,
            buffCount: parseInt(document.getElementById('buff_count').value) || 0,
            debuffCount: parseInt(document.getElementById('debuff_count').value) || 0,

            elemMult: parseFloat(document.getElementById('elem_mult').value) || 1.0,

            correctionMult: correctionMult, // キラーとフィールドを合算した倍率

            extraMult: parseFloat(document.getElementById('other_mult_extra').value) || 1.0
        };
    }

    // 計算ロジック
    function calculateDamageFlow(p, rate) {
        // 1. 基礎ATK (リーダーごとの切り上げ加算)
        let totalLeaderBonus = 0;
        for (let i = 0; i < p.leaders.length; i++) {
            totalLeaderBonus += Math.ceil(p.baseAtk * p.leaders[i]);
        }

        let step1_Atk = p.baseAtk + totalLeaderBonus + p.addAtk + (p.parcentage_atk * p.baseAtk / 100);
        let step2_Combo = Math.ceil(step1_Atk * (1+(p.comboCount-1)/10));

        let buffMult = 1 + (p.buffCount * 0.25);
        let debuffMult = 1 + (p.debuffCount * 0.10);

        // 全て乗算 (correctionMultにキラーとフィールドが含まれている)
        let totalOtherMult = p.elemMult * p.correctionMult * p.extraMult * buffMult * debuffMult;

        // 最終ダメージ
        let finalDmg = Math.ceil(step2_Combo * rate * totalOtherMult);

        return {
            step2_val: step2_Combo,
            total_other: totalOtherMult,
            final_dmg: finalDmg
        };
    }

    // ① 逆算機能
    function calcReverse() {
        const p = getParams();
        if (!p.dmg) {
            alert("ダメージ値を入力してください");
            return;
        }

        const dummy = calculateDamageFlow(p, 1.0);
        const denominator = dummy.step2_val * dummy.total_other;

        if (denominator === 0) {
            alert("計算エラー: 0除算");
            return;
        }

        const resultRate = p.dmg / denominator;

        document.getElementById('calcResult').innerText = resultRate.toFixed(4);
        document.getElementById('reverseResultArea').style.display = 'block';
        document.getElementById('reverseResultArea').scrollIntoView({
            behavior: "smooth",
            block: "center"
        });
    }

    // ② 検算機能
    // ② 検算・シミュレーション機能
    function calcVerify() {
        const p = getParams();
        const rate = parseFloat(document.getElementById('verify_rate').value);

        if (!rate) {
            alert("倍率を入力してください");
            return;
        }

        const res = calculateDamageFlow(p, rate);

        document.getElementById('verifyResultVal').innerText = res.final_dmg;
        document.getElementById('verifyResultArea').style.display = 'block';

        // 実数値が入力されている場合のみ誤差を表示
        if (p.dmg > 0) {
            let diff = res.final_dmg - p.dmg;
            let diffText = diff === 0 ?
                "<span style='color:green;'>★ 完全一致！</span>" :
                "<span style='color:red;'>実数値との差: " + (diff > 0 ? "+" : "") + diff + "</span>";
            document.getElementById('verifyDiff').innerHTML = diffText;
        } else {
            // 実数値がない場合はシミュレーション結果のみ表示
            document.getElementById('verifyDiff').innerHTML = "";
        }
    }

    // 全てのinputにおいて、ダブルクリックで空白にする
    document.querySelectorAll('input').forEach(function(input) {
        // PC用: ダブルクリック
        input.addEventListener('dblclick', function() {
            this.value = '';
        });

        // スマホ用: ダブルタップ判定
        let lastTap = 0;
        input.addEventListener('touchend', function(e) {
            const currentTime = new Date().getTime();
            const tapLength = currentTime - lastTap;
            if (tapLength < 350 && tapLength > 0) {
                this.value = '';
                e.preventDefault(); // ズームやテキスト選択をキャンセル
            }
            lastTap = currentTime;
        });
    });

    // Ctrl+Enterで計算実行 (.btn-calcをクリック)
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'Enter') {
            const btn = document.querySelector('.btn-calc');
            if (btn) {
                e.preventDefault();
                btn.click();
            }
        }
    });
</script>

<?php get_footer(); ?>
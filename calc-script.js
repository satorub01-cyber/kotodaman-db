(function($) {
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof kotoCalcObj === 'undefined' || !kotoCalcObj.specs) return;
        const data = kotoCalcObj.specs;
        
        const els = {
            skillSel: document.getElementById('calc-skill-selector'),
            scenarioBox: document.getElementById('calc-scenario-box'),
            scenarioSel: document.getElementById('calc-scenario-selector'),
            buffAtk: document.getElementById('input-buff-atk'),
            debuffDef: document.getElementById('input-debuff-def'),
            valBuff: document.getElementById('val-buff-atk'),
            valDebuff: document.getElementById('val-debuff-def'),
            checkArea: document.getElementById('calc-checkbox-area'),
            baseAtk: document.getElementById('calc-base-atk'),
            totalCorr: document.getElementById('calc-total-correction'),
            finalDmg: document.getElementById('calc-final-damage'),
            manualRate: document.getElementById('input-manual-rate'),
            manualCorr: document.getElementById('input-manual-correction')
        };

        if(!els.skillSel) return;

        // 初期表示
        els.baseAtk.textContent = data.atk;

        // スキル選択肢構築
        if (data.kotowaza && data.kotowaza.length > 0) {
            data.kotowaza.forEach((k, idx) => {
                const opt = document.createElement('option');
                opt.value = 'kotowaza_' + idx;
                opt.text = `コトワザ (${k.level}凸)`;
                els.skillSel.appendChild(opt);
            });
        }

        renderCheckboxes();

        // イベントリスナー
        els.skillSel.addEventListener('change', updateScenarios);
        els.scenarioSel.addEventListener('change', () => {
            updateManualRatePlaceholder();
            calculate();
        });
        
        els.buffAtk.addEventListener('input', (e) => { 
            els.valBuff.textContent = e.target.value; 
            calculate(); 
        });
        els.debuffDef.addEventListener('input', (e) => { 
            els.valDebuff.textContent = e.target.value; 
            calculate(); 
        });

        // 手動入力イベント
        if(els.manualRate) els.manualRate.addEventListener('input', calculate);
        if(els.manualCorr) els.manualCorr.addEventListener('input', calculate);

        // 初期実行
        updateScenarios();

        /* ========================================================
         * 関数定義
         * ======================================================== */

        function renderCheckboxes() {
            els.checkArea.innerHTML = '';
            if (!data.corrections || !data.corrections.details) return;

            data.corrections.details.forEach((item, idx) => {
                const wrap = document.createElement('label');
                wrap.className = 'calc-chk-label';
                
                const chk = document.createElement('input');
                chk.type = 'checkbox';
                chk.value = item.value;
                chk.checked = item.default;
                chk.dataset.category = item.category || 'damage';
                chk.addEventListener('change', calculate);

                const condStr = (item.cond && item.cond !== '常時' && item.cond !== '無条件') 
                                ? `<span class="cond-note">(${item.cond})</span>` 
                                : '';
                
                const span = document.createElement('span');
                span.innerHTML = `${item.label}${condStr} <span style="color:#888; font-size:0.85em;">(+${item.value}%)</span>`;

                wrap.appendChild(chk);
                wrap.appendChild(span);
                els.checkArea.appendChild(wrap);
            });
        }

        function updateScenarios() {
            const skillKey = els.skillSel.value;
            let timeline = getTimeline(skillKey);
            els.scenarioSel.innerHTML = '';
            
            const scenarios = new Map();
            scenarios.set('AUTO_MAX', { label: '最大火力 (自動判定)', cond: null });

            timeline.forEach(action => {
                if (action.cond) {
                    const key = JSON.stringify(action.cond);
                    if (!scenarios.has(key)) {
                        let label = '条件あり';
                        const c = action.cond;
                        
                        if (c.condition_type === 'char' || c.type === 'char') label = `文字: ${c.val}`;
                        else if (c.condition_type === 'comb' || c.type === 'comb') label = `${c.val}コンボ`;
                        else if (c.condition_type === 'theme' || c.type === 'theme') label = `テーマ: ${c.val}`;
                        else if (c.condition_type === 'moji_count' || c.type === 'moji_count') label = `${c.val}文字`;
                        
                        scenarios.set(key, { label: `条件: ${label}`, cond: action.cond });
                    }
                }
            });

            scenarios.forEach((val, key) => {
                const opt = document.createElement('option');
                opt.value = key;
                opt.text = val.label;
                els.scenarioSel.appendChild(opt);
            });
            
            els.scenarioBox.style.display = (scenarios.size > 1) ? 'block' : 'none';
            
            updateManualRatePlaceholder();
            calculate();
        }

        function updateManualRatePlaceholder() {
            if (!els.manualRate) return;
            
            const skillKey = els.skillSel.value;
            const timeline = getTimeline(skillKey);
            const selectedScenarioKey = els.scenarioSel.value;
            
            let targetAction = null;

            for (const action of timeline) {
                if (action.type.includes('attack') || action.type === 'command') {
                    let isMatch = false;
                    if (selectedScenarioKey === 'AUTO_MAX') {
                        isMatch = true;
                    } else if (!action.cond) {
                        isMatch = true; 
                    } else {
                        const actionKey = JSON.stringify(action.cond);
                        if (actionKey === selectedScenarioKey) isMatch = true;
                    }

                    if (isMatch) {
                        targetAction = action;
                        break;
                    }
                }
            }

            if (targetAction) {
                let totalMag = parseFloat(targetAction.value) * (targetAction.hit_count || 1);
                if (targetAction.value_last && (targetAction.hit_count > 1)) {
                    totalMag = (parseFloat(targetAction.value) * ((targetAction.hit_count || 1) - 1)) + parseFloat(targetAction.value_last);
                }
                els.manualRate.value = totalMag;
            } else {
                els.manualRate.value = 0;
            }
        }

        function calculate() {
            const skillKey = els.skillSel.value;
            const timeline = getTimeline(skillKey);
            
            // 1. 補正値集計
            let sumAtkCorrection = 0;
            let sumDamageCorrection = 0;

            const checks = els.checkArea.querySelectorAll('input[type="checkbox"]:checked');
            checks.forEach(c => { 
                const val = parseFloat(c.value);
                const cat = c.dataset.category; 
                if (cat === 'atk') sumAtkCorrection += val;
                else sumDamageCorrection += val;
            });

            // 手動補正加算
            if (els.manualCorr && els.manualCorr.value) {
                sumDamageCorrection += parseFloat(els.manualCorr.value);
            }

            const dispTotal = (1 + sumAtkCorrection/100) * (1 + sumDamageCorrection/100);
            els.totalCorr.textContent = dispTotal.toFixed(2);

            // 2. ダメージ計算
            const selectedScenarioKey = els.scenarioSel.value;
            let finalDamage = 0;

            const manualRateVal = els.manualRate ? parseFloat(els.manualRate.value) : 0;
            const useManualRate = !isNaN(manualRateVal) && manualRateVal > 0;

            if (useManualRate) {
                // 手動倍率モード
                const currentBuff = parseInt(els.buffAtk.value);
                const currentDebuff = parseInt(els.debuffDef.value);
                
                const baseAtk = parseFloat(data.atk);
                const adjustedBaseAtk = baseAtk * (1 + sumAtkCorrection / 100);
                const damageCorrectionRate = 1 + (sumDamageCorrection / 100);
                const buffRate = 1 + (currentBuff * 0.25);
                const debuffRate = 1 + (currentDebuff * 0.1);

                finalDamage = adjustedBaseAtk * buffRate * debuffRate * damageCorrectionRate * manualRateVal;

            } else {
                // タイムラインモード
                if (selectedScenarioKey === 'AUTO_MAX') {
                    let maxDmg = calcTimelineDamage('DUMMY_KEY', timeline, sumAtkCorrection, sumDamageCorrection); 
                    const uniqueCondKeys = new Set();
                    timeline.forEach(a => { if (a.cond) uniqueCondKeys.add(JSON.stringify(a.cond)); });
                    
                    uniqueCondKeys.forEach(key => {
                        const dmg = calcTimelineDamage(key, timeline, sumAtkCorrection, sumDamageCorrection);
                        if (dmg > maxDmg) maxDmg = dmg;
                    });
                    finalDamage = maxDmg;
                } else {
                    finalDamage = calcTimelineDamage(selectedScenarioKey, timeline, sumAtkCorrection, sumDamageCorrection);
                }
            }

            els.finalDmg.textContent = Math.floor(finalDamage).toLocaleString();
        }

        function calcTimelineDamage(targetCondKey, timeline, sumAtkCorrection, sumDamageCorrection) {
            let totalDmg = 0;
            let currentBuff = parseInt(els.buffAtk.value);
            let currentDebuff = parseInt(els.debuffDef.value);

            const baseAtk = parseFloat(data.atk);
            const adjustedBaseAtk = baseAtk * (1 + sumAtkCorrection / 100);
            const damageCorrectionRate = 1 + (sumDamageCorrection / 100);

            timeline.forEach(action => {
                let isActive = false;
                if (!action.cond) {
                    isActive = true; 
                } else {
                    const actionKey = JSON.stringify(action.cond);
                    if (actionKey === targetCondKey) isActive = true;
                }
                if (!isActive) return;

                if (action.type.includes('buff') || action.type === 'battle_field') {
                    if ((action.type.includes('atk_buff') || action.type === 'battle_field') && !isTargetEnemy(action.target)) {
                        currentBuff += (action.amount || 0);
                    }
                    if (action.type.includes('def_debuff')) {
                        currentDebuff += (action.amount || 0);
                    }
                }

                if (action.type.includes('attack') || action.type === 'command') {
                    let totalMag = parseFloat(action.value) * (action.hit_count || 1);
                    if (action.value_last && (action.hit_count > 1)) {
                        totalMag = (parseFloat(action.value) * ((action.hit_count || 1) - 1)) + parseFloat(action.value_last);
                    }

                    const buffRate = 1 + (currentBuff * 0.25);
                    const debuffRate = 1 + (currentDebuff * 0.1);

                    const stepDmg = adjustedBaseAtk * buffRate * debuffRate * damageCorrectionRate * totalMag;
                    totalDmg += stepDmg;
                }
            });
            return totalDmg;
        }

        function getTimeline(key) {
            if (key === 'sugowaza') return data.sugowaza ? data.sugowaza.timeline : [];
            if (key === 'waza') return data.waza ? data.waza.timeline : [];
            if (key.startsWith('kotowaza_')) {
                const idx = parseInt(key.split('_')[1]);
                return data.kotowaza[idx] ? data.kotowaza[idx].timeline : [];
            }
            return [];
        }

        function isTargetEnemy(target) {
            return target && target.includes('oppo');
        }
    });
})(jQuery);
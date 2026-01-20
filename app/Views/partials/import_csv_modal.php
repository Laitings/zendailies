<?php

/**
 * Partial: Resolve CSV Import Modal
 * Expects: $feedback (array|null) — session payload with:
 *   - counts: ['succeeded'=>int,'missing'=>int]
 *   - unmatched: [ ['row'=>int,'csv_name'=>string|null,'reason'=>string], ... ]
 *   - succeeded: [ ['csv_name'=>string,'clip_uuid'=>string], ... ]
 */
$fb = $feedback ?? null;
?>
<?php if ($fb): ?>
    <style>
        /* --- Import Result Modal (dark theme + dark scrollbars) --- */
        .zd-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            display: none;
            z-index: 2000;
        }

        .zd-modal {
            position: fixed;
            top: 6vh;
            left: 50%;
            transform: translateX(-50%);
            width: min(1000px, 92vw);
            max-height: 88vh;
            background: #111318;
            border: 1px solid #1f2430;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.6);
            display: none;
            z-index: 2001;
        }

        .zd-modal__head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 14px;
            border-bottom: 1px solid #1f2430;
        }

        .zd-modal__title {
            font-size: 16px;
            font-weight: 600;
            color: #e9eef3;
        }

        .zd-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid #1f2430;
            border-radius: 999px;
            padding: 2px 10px;
            font-size: 12px;
        }

        .zd-chip--ok {
            color: #9fe29f;
            border-color: #204d2a;
            background: #0c1a10;
        }

        .zd-chip--err {
            color: #ffb1b1;
            border-color: #4d2020;
            background: #1a0c0c;
        }

        .zd-modal__body {
            padding: 12px;
            display: flex;
            gap: 12px;
        }

        .zd-col {
            flex: 1 1 0;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }

        .zd-col h3 {
            margin: 0 0 8px;
            font-size: 14px;
            color: #e9eef3;
        }

        /* list with dark scrollbars */
        .zd-list {
            border: 1px solid #1f2430;
            border-radius: 8px;
            background: #0b0c10;
            padding: 8px;
            overflow: auto;
            max-height: 60vh;
            scrollbar-width: thin;
            /* Firefox */
            scrollbar-color: #2a313f #0b0c10;
            /* thumb / track */
        }

        /* WebKit scrollbars */
        .zd-list::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        .zd-list::-webkit-scrollbar-track {
            background: #0b0c10;
        }

        .zd-list::-webkit-scrollbar-thumb {
            background: #2a313f;
            border-radius: 8px;
        }

        .zd-list::-webkit-scrollbar-thumb:hover {
            background: #394253;
        }

        .zd-item {
            padding: 8px 4px;
            border-bottom: 1px solid #1f2430;
        }

        .zd-item:last-child {
            border-bottom: none;
        }

        .zd-item--ok {
            color: #9fe29f;
        }

        .zd-item--err {
            color: #ffb1b1;
        }

        .zd-item__name {
            font-size: 13px;
            line-height: 1.3;
        }

        .zd-item__sub {
            font-size: 12px;
            color: #9aa7b2;
            margin-top: 3px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
        }

        .zd-modal__foot {
            padding: 10px 12px;
            border-top: 1px solid #1f2430;
            display: flex;
            justify-content: flex-end;
        }

        .zd-btn-secondary {
            background: #0f1218;
            color: #e9eef3;
            border: 1px solid #1f2430;
            border-radius: 8px;
            padding: 8px 12px;
            cursor: pointer;
        }

        .zd-close-x {
            background: transparent;
            border: none;
            color: #9aa7b2;
            font-size: 18px;
            cursor: pointer;
        }
    </style>

    <div id="zdImportBackdrop" class="zd-modal-backdrop"></div>
    <div id="zdImportModal" class="zd-modal" role="dialog" aria-modal="true" aria-labelledby="zdImportTitle">
        <div class="zd-modal__head">
            <div class="zd-modal__title" id="zdImportTitle">Resolve CSV import</div>
            <div style="display:flex; gap:8px; align-items:center;">
                <span id="zdCountOk" class="zd-chip zd-chip--ok">Succeeded: 0</span>
                <span id="zdCountErr" class="zd-chip zd-chip--err">Missing: 0</span>
                <button type="button" class="zd-close-x" aria-label="Close" onclick="zdCloseImportModal()">✕</button>
            </div>
        </div>
        <div class="zd-modal__body">
            <div class="zd-col">
                <h3>Missing in this day</h3>
                <div id="zdListErr" class="zd-list"></div>
            </div>
            <div class="zd-col">
                <h3>Succeeded</h3>
                <div id="zdListOk" class="zd-list"></div>
            </div>
        </div>
        <div class="zd-modal__foot">
            <button type="button" class="zd-btn-secondary" onclick="zdCloseImportModal()">Close</button>
        </div>
    </div>

    <script>
        (function() {
            // Data from PHP
            const fb = <?= json_encode($fb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || null;
            if (!fb) return;

            // Elements
            const backdrop = document.getElementById('zdImportBackdrop');
            const modal = document.getElementById('zdImportModal');
            const listOk = document.getElementById('zdListOk');
            const listErr = document.getElementById('zdListErr');
            const countOk = document.getElementById('zdCountOk');
            const countErr = document.getElementById('zdCountErr');

            if (!backdrop || !modal || !listOk || !listErr || !countOk || !countErr) return;

            // Normalize arrays safely
            const succeededArr = Array.isArray(fb.succeeded) ? fb.succeeded : [];
            const unmatchedArr = Array.isArray(fb.unmatched) ? fb.unmatched : [];

            // Robust counts: prefer numeric counts, fallback to array lengths
            let okCount = 0;
            if (fb.counts && typeof fb.counts.succeeded === 'number') okCount = fb.counts.succeeded;
            else if (fb.counts && typeof fb.counts.applied === 'number') okCount = fb.counts.applied;
            else okCount = succeededArr.length;

            let errCount = 0;
            if (fb.counts && typeof fb.counts.missing === 'number') errCount = fb.counts.missing;
            else errCount = unmatchedArr.length;

            // Set chip labels
            countOk.textContent = 'Succeeded: ' + okCount;
            countErr.textContent = 'Missing: ' + errCount;

            // Clear lists (in case of hot reload / partial reuse)
            listOk.innerHTML = '';
            listErr.innerHTML = '';

            // Render list items
            function addOk(name, uuid) {
                const wrap = document.createElement('div');
                wrap.className = 'zd-item zd-item--ok';

                const title = document.createElement('div');
                title.className = 'zd-item__name';
                title.textContent = name || '';
                wrap.appendChild(title);

                if (uuid) {
                    const sub = document.createElement('div');
                    sub.className = 'zd-item__sub';
                    sub.textContent = uuid;
                    wrap.appendChild(sub);
                }

                listOk.appendChild(wrap);
            }

            function addErr(label, reason) {
                const wrap = document.createElement('div');
                wrap.className = 'zd-item zd-item--err';

                const title = document.createElement('div');
                title.className = 'zd-item__name';
                title.textContent = label || '(no filename)';
                wrap.appendChild(title);

                if (reason) {
                    const sub = document.createElement('div');
                    sub.className = 'zd-item__sub';
                    sub.textContent = reason;
                    wrap.appendChild(sub);
                }

                listErr.appendChild(wrap);
            }

            unmatchedArr.forEach(function(um) {
                const label = um && um.csv_name ? um.csv_name : '(no filename)';
                const reason = um && um.reason ? um.reason : '';
                addErr(label, reason);
            });

            succeededArr.forEach(function(s) {
                const name = s && s.csv_name ? s.csv_name : '';
                const uuid = s && s.clip_uuid ? s.clip_uuid : '';
                addOk(name, uuid);
            });

            // Show modal
            backdrop.style.display = 'block';
            modal.style.display = 'block';

            // Close handlers
            function close() {
                backdrop.style.display = 'none';
                modal.style.display = 'none';
            }

            // expose for the ✕ button / Close button (your HTML calls zdCloseImportModal())
            window.zdCloseImportModal = close;

            backdrop.addEventListener('click', close);
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') close();
            });
        })();
    </script>

<?php endif; ?>
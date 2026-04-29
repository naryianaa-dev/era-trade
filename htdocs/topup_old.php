<?php
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'db.php';
date_default_timezone_set('Europe/Moscow');

if (empty($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Получаем баланс
$stmt = $pdo->prepare("SELECT username, balance, user_status FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// История пополнений
$stmt_h = $pdo->prepare(
    "SELECT amount, payment_method, status, created_at, confirmed_at
     FROM balance_topups WHERE user_id = ? ORDER BY id DESC LIMIT 10"
);
$stmt_h->execute([$user_id]);
$topups = $stmt_h->fetchAll(PDO::FETCH_ASSOC);

$status_colors  = ['pending' => '#f59e0b', 'confirmed' => '#4ade80', 'rejected' => '#f87171'];
$status_labels  = ['pending' => '⏳ Ожидает', 'confirmed' => '✅ Зачислено', 'rejected' => '❌ Отклонено'];
$method_labels  = ['qr' => '📱 QR / СБП', 'receipt' => '🧾 Квитанция'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Пополнение баланса — Форсаж</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            background: #0f172a; color: #fff; font-family: sans-serif;
            min-height: 100vh; margin: 0; padding: 24px 16px 40px;
            display: flex; flex-direction: column; align-items: center;
        }
        .page { width: 100%; max-width: 480px; }
        h2 { font-size: 20px; font-weight: 900; margin: 0 0 20px; text-align: center; }

        .balance-card {
            background: linear-gradient(135deg, #1e3a5f, #0f172a);
            border: 1px solid #3b82f6; border-radius: 20px;
            padding: 24px; text-align: center; margin-bottom: 20px;
        }
        .balance-card .bl { font-size: 13px; color: #94a3b8; margin-bottom: 6px; }
        .balance-card .bv { font-size: 48px; font-weight: 900; color: #4ade80; }
        .balance-card .bu { font-size: 13px; color: #64748b; margin-top: 4px; }

        .section { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 20px; margin-bottom: 16px; }
        .section-title { font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 16px; }

        /* Суммы быстрого выбора */
        .amounts { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 14px; }
        .amount-btn {
            padding: 12px 8px; border: 1.5px solid #334155; border-radius: 10px;
            background: #0f172a; color: #fff; font-weight: bold; font-size: 14px;
            cursor: pointer; text-align: center; transition: border-color 0.2s, background 0.2s;
        }
        .amount-btn:hover    { border-color: #3b82f6; }
        .amount-btn.selected { border-color: #3b82f6; background: #1e3a5f; color: #60a5fa; }

        .field {
            width: 100%; padding: 13px; border-radius: 10px;
            background: #0f172a; border: 1.5px solid #334155;
            color: #fff; font-size: 16px; text-align: center;
            margin-bottom: 12px; outline: none;
        }
        .field:focus { border-color: #3b82f6; }

        /* Способы оплаты */
        .pay-opts { display: flex; flex-direction: column; gap: 8px; margin-bottom: 14px; }
        .pay-opt {
            display: flex; align-items: center; gap: 12px;
            background: #0f172a; border: 1.5px solid #334155; border-radius: 12px;
            padding: 14px; cursor: pointer; transition: border-color 0.2s, background 0.2s;
        }
        .pay-opt:hover    { border-color: #64748b; }
        .pay-opt.selected { border-color: #3b82f6; background: #1e3a5f; }
        .pay-opt .po-icon { font-size: 24px; }
        .pay-opt .po-name { font-weight: bold; font-size: 14px; }
        .pay-opt .po-desc { font-size: 12px; color: #64748b; margin-top: 2px; }

        .btn-submit {
            width: 100%; padding: 16px; border: none; border-radius: 12px;
            background: #3b82f6; color: #fff; font-weight: 900; font-size: 16px;
            cursor: pointer; transition: background 0.2s;
        }
        .btn-submit:hover:not(:disabled) { background: #2563eb; }
        .btn-submit:disabled { background: #334155; color: #64748b; cursor: not-allowed; }

        #topup-msg { min-height: 20px; text-align: center; font-size: 13px; font-weight: bold; margin-bottom: 10px; }

        /* QR модалка */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); z-index: 100; justify-content: center; align-items: center; padding: 16px; }
        .modal-overlay.open { display: flex; }
        .modal-box { background: #1e293b; border: 1px solid #334155; border-radius: 20px; padding: 28px; width: 100%; max-width: 380px; text-align: center; }
        .modal-box h3 { margin: 0 0 8px; }
        .qr-ph { width: 180px; height: 180px; background: #fff; border-radius: 12px; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center; color: #0f172a; font-weight: bold; font-size: 12px; }
        .modal-close { width: 100%; padding: 13px; background: #334155; border: none; border-radius: 12px; color: #94a3b8; font-size: 14px; cursor: pointer; }

        /* История */
        .topup-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #334155; font-size: 13px; }
        .topup-row:last-child { border-bottom: none; }
        .topup-row .tr-left .tr-method { color: #94a3b8; font-size: 12px; margin-top: 2px; }
        .topup-row .tr-right { text-align: right; }
        .topup-row .tr-amount { font-weight: bold; font-size: 16px; color: #4ade80; }

        .back-link { display: block; text-align: center; margin-top: 16px; color: #475569; font-size: 12px; text-decoration: none; }
        .back-link:hover { color: #94a3b8; }
    </style>
</head>
<body>
<div class="page">
    <h2>💰 Пополнение баланса</h2>

    <!-- Текущий баланс -->
    <div class="balance-card">
        <div class="bl">Текущий баланс</div>
        <div class="bv"><?= number_format((int)$user['balance'], 0, '.', "\u{00A0}") ?>&nbsp;₽</div>
        <div class="bu"><?= htmlspecialchars($user['username']) ?> · <?= ucfirst($user['user_status'] ?? 'base') ?></div>
    </div>

    <!-- Форма пополнения -->
    <div class="section">
        <div class="section-title">Сумма пополнения</div>

        <div class="amounts">
            <?php foreach ([1000, 3000, 5000, 10000, 25000, 50000] as $a): ?>
            <div class="amount-btn" onclick="selectAmount(<?= $a ?>)">
                <?= number_format($a, 0, '.', "\u{00A0}") ?>&nbsp;₽
            </div>
            <?php endforeach; ?>
        </div>

        <input class="field" type="number" id="amount-input"
               placeholder="Или введите сумму" min="100" step="100"
               oninput="deselectAmounts()">

        <div class="section-title" style="margin-top:8px;">Способ оплаты</div>
        <div class="pay-opts">
            <div class="pay-opt selected" id="pay-qr" onclick="selectPay('qr')">
                <span class="po-icon">📱</span>
                <div>
                    <div class="po-name">QR-код / СБП</div>
                    <div class="po-desc">Мгновенно · любой банк</div>
                </div>
            </div>
            <div class="pay-opt" id="pay-receipt" onclick="selectPay('receipt')">
                <span class="po-icon">🧾</span>
                <div>
                    <div class="po-name">Квитанция / платёжка</div>
                    <div class="po-desc">Банковский перевод · 1-3 часа</div>
                </div>
            </div>
        </div>

        <div id="topup-msg"></div>

        <button class="btn-submit" id="topup-btn" onclick="submitTopup()">
            Пополнить
        </button>
    </div>

    <!-- История пополнений -->
    <?php if ($topups): ?>
    <div class="section">
        <div class="section-title">История пополнений</div>
        <?php foreach ($topups as $t): ?>
        <div class="topup-row">
            <div class="tr-left">
                <div><?= $status_labels[$t['status']] ?? $t['status'] ?></div>
                <div class="tr-method"><?= $method_labels[$t['payment_method']] ?? $t['payment_method'] ?> · <?= date('d.m.y H:i', strtotime($t['created_at'])) ?></div>
            </div>
            <div class="tr-right">
                <div class="tr-amount" style="color:<?= $status_colors[$t['status']] ?? '#fff' ?>">
                    +<?= number_format((int)$t['amount'], 0, '.', "\u{00A0}") ?>&nbsp;₽
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <a class="back-link" href="reestr.php">← Вернуться в реестр</a>
</div>

<!-- QR Модалка -->
<div class="modal-overlay" id="modal-qr" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal-box">
        <h3>📱 Оплата по QR-коду</h3>
        <p style="color:#94a3b8;font-size:13px;margin:0 0 16px;">Отсканируйте код камерой или через приложение банка</p>
        <div class="qr-ph">QR-код</div>
        <p style="font-size:13px;margin:0 0 20px;">Сумма: <b id="qr-sum" style="color:#4ade80;"></b></p>
        <p style="font-size:12px;color:#64748b;margin:0 0 20px;">После оплаты средства будут зачислены автоматически в течение нескольких минут</p>
        <button class="modal-close" onclick="document.getElementById('modal-qr').classList.remove('open')">Закрыть</button>
    </div>
</div>

<!-- Квитанция модалка -->
<div class="modal-overlay" id="modal-receipt" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal-box">
        <h3>🧾 Реквизиты для перевода</h3>
        <div style="background:#0f172a;border-radius:10px;padding:14px;text-align:left;margin-bottom:16px;font-size:13px;line-height:2.2;">
            <div>Получатель: <b>ООО «Форсаж»</b></div>
            <div>ИНН: <b>1234567890</b></div>
            <div>Банк: <b>АО «Тинькофф Банк»</b></div>
            <div>БИК: <b>044525974</b></div>
            <div>Счёт: <b>40702810000000000000</b></div>
            <div>Назначение: <b>Пополнение баланса ID<?= $user_id ?></b></div>
            <div>Сумма: <b id="rec-sum" style="color:#f59e0b;"></b></div>
        </div>
        <p style="font-size:12px;color:#64748b;margin:0 0 20px;">Укажите точное назначение платежа. После поступления средств баланс будет зачислен в течение 1-3 часов.</p>
        <button class="modal-close" onclick="document.getElementById('modal-receipt').classList.remove('open')">Закрыть</button>
    </div>
</div>

<script>
let selectedAmount = 0;
let selectedPay    = 'qr';

const AMOUNTS = [1000, 3000, 5000, 10000, 25000, 50000];

function selectAmount(val) {
    selectedAmount = val;
    document.getElementById('amount-input').value = '';
    document.querySelectorAll('.amount-btn').forEach((btn, i) => {
        btn.classList.toggle('selected', AMOUNTS[i] === val);
    });
}

function deselectAmounts() {
    selectedAmount = 0;
    document.querySelectorAll('.amount-btn').forEach(b => b.classList.remove('selected'));
}

function selectPay(method) {
    selectedPay = method;
    ['qr', 'receipt'].forEach(m => {
        document.getElementById('pay-' + m).classList.toggle('selected', m === method);
    });
}

function setMsg(text, color) {
    const m = document.getElementById('topup-msg');
    m.textContent = text; m.style.color = color || '#ef4444';
}

function submitTopup() {
    const inputVal = parseInt(document.getElementById('amount-input').value, 10);
    const amount   = selectedAmount || inputVal;

    if (!amount || amount < 100) { setMsg('Введите сумму от 100 ₽'); return; }

    const btn = document.getElementById('topup-btn');
    btn.disabled = true; btn.textContent = 'Обработка…';
    setMsg('', '');

    const fd = new FormData();
    fd.append('action',         'topup');
    fd.append('amount',         amount);
    fd.append('payment_method', selectedPay);

    fetch('topup_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                const sum = amount.toLocaleString('ru-RU') + ' ₽';
                if (selectedPay === 'qr') {
                    document.getElementById('qr-sum').textContent = sum;
                    document.getElementById('modal-qr').classList.add('open');
                } else {
                    document.getElementById('rec-sum').textContent = sum;
                    document.getElementById('modal-receipt').classList.add('open');
                }
                setMsg('Заявка создана', '#4ade80');
            } else {
                setMsg(d.msg || 'Ошибка');
            }
            btn.disabled = false; btn.textContent = 'Пополнить';
        })
        .catch(() => {
            setMsg('Ошибка связи'); btn.disabled = false; btn.textContent = 'Пополнить';
        });
}
</script>
</body>
</html>

<?php
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';
date_default_timezone_set('Europe/Moscow');

if (empty($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$msg = '';
$msg_color = '#22c55e';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title        = trim($_POST['title']        ?? '');
    $price        = (int)($_POST['price']       ?? 0);
    $deposit      = (int)($_POST['deposit']     ?? 0);
    $auction_type = in_array($_POST['auction_type'] ?? '', ['classic','scandinavian'])
                    ? $_POST['auction_type'] : 'classic';
    $bid_step     = max(100, (int)($_POST['bid_step']    ?? 1000));
    $max_duration = isset($_POST['max_duration']) && (int)$_POST['max_duration'] > 0
                    ? (int)$_POST['max_duration'] : 0;
    $timer_start  = max(60,  (int)($_POST['timer_start'] ?? 240));
    $timer_add    = max(60,  (int)($_POST['timer_add']   ?? 240));
    $duration     = max(1,   (int)($_POST['duration']    ?? 24));
    $description  = trim($_POST['description']  ?? 'Описание скоро будет...');
    
    // НОВОЕ: Временные метки
    $start_at     = !empty($_POST['start_at']) ? $_POST['start_at'] : null;
    $time_before_start = max(0, (int)($_POST['time_before_start'] ?? 0));

    if (!$title || $price <= 0) {
        $msg = 'Заполните название и начальную цену';
        $msg_color = '#ef4444';
    } else {
        // При наличие start_at, основываемся от неё, иначе от текущего времени
        $base_time = $start_at ? strtotime($start_at) : time();
        $end_time = date("Y-m-d H:i:s", $base_time + ($duration * 3600));

        try {
            $max_end_time = $max_duration > 0
                            ? date("Y-m-d H:i:s", $base_time + ($max_duration * 3600))
                            : null;
            
            $started_at = $start_at ? date("Y-m-d H:i:s", strtotime($start_at)) : null;

            $sql = "INSERT INTO lots
                        (title, start_price, price, deposit, is_paid_bids,
                         end_time, owner_id, description,
                         auction_type, bid_step, timer_start, timer_add, max_end_time,
                         started_at, time_before_start)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $title,
                $price,
                $price,
                $deposit,
                $auction_type === 'scandinavian' ? 1 : 0,
                $end_time,
                $user_id,
                $description,
                $auction_type,
                $bid_step,
                $timer_start,
                $timer_add,
                $max_end_time,
                $started_at,
                $time_before_start,
            ]);

            $new_id = $pdo->lastInsertId();
            $redirect = $auction_type === 'scandinavian'
                ? "lot_scandinavian.php?id={$new_id}"
                : "lot_details.php?id={$new_id}";

            header("Location: {$redirect}");
            exit;

        } catch (Exception $e) {
            $msg = 'Ошибка БД: ' . $e->getMessage();
            $msg_color = '#ef4444';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Создание лота — Форсаж</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            background: #0f172a; color: #fff;
            font-family: sans-serif; min-height: 100vh;
            margin: 0; padding: 32px 16px;
            display: flex; justify-content: center;
        }
        .card {
            background: #1e293b; border: 1px solid #334155;
            border-radius: 24px; padding: 36px;
            width: 100%; max-width: 540px; height: fit-content;
        }
        h2 { margin: 0 0 28px; font-size: 22px; text-align: center; }

        .field-group { margin-bottom: 18px; }
        .field-group label {
            display: block; font-size: 12px; color: #64748b;
            text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px;
        }
        input[type=text], input[type=number], input[type=datetime-local], textarea, select {
            width: 100%; padding: 13px 16px;
            border-radius: 10px; background: #0f172a;
            border: 1.5px solid #334155; color: #fff;
            font-size: 15px; outline: none;
            transition: border-color 0.2s;
        }
        input:focus, textarea:focus, select:focus { border-color: #3b82f6; }
        select option { background: #0f172a; }
        textarea { resize: vertical; min-height: 80px; }

        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

        /* Тип аукциона */
        .type-row { display: flex; gap: 12px; margin-bottom: 18px; }
        .type-opt {
            flex: 1; padding: 14px; border: 1.5px solid #334155;
            border-radius: 12px; background: #0f172a; color: #fff;
            cursor: pointer; text-align: center; transition: border-color 0.2s, background 0.2s;
        }
        .type-opt.selected { border-color: #3b82f6; background: #1e3a5f; }
        .type-opt .to-icon { font-size: 24px; margin-bottom: 4px; }
        .type-opt .to-name { font-weight: bold; font-size: 14px; }
        .type-opt .to-desc { font-size: 11px; color: #64748b; margin-top: 2px; }

        /* Раздел времени */
        .time-section {
            background: #0f172a; border: 1px solid #334155;
            border-radius: 14px; padding: 18px; margin-bottom: 18px;
        }
        .time-section h4 { margin: 0 0 14px; font-size: 13px; color: #3b82f6; }

        /* Скандинавские настройки */
        #scand-opts {
            background: #0f172a; border: 1px solid #334155;
            border-radius: 14px; padding: 18px; margin-bottom: 18px;
            display: none;
        }
        #scand-opts.show { display: block; }
        #scand-opts h4 { margin: 0 0 14px; font-size: 13px; color: #f59e0b; }

        .btn-submit {
            width: 100%; padding: 16px; border: none;
            border-radius: 12px; background: #3b82f6; color: #fff;
            font-weight: bold; font-size: 16px; cursor: pointer;
            transition: background 0.2s;
        }
        .btn-submit:hover { background: #2563eb; }

        .msg { padding: 12px; border-radius: 10px; margin-bottom: 16px;
               font-size: 14px; font-weight: bold; text-align: center; }

        .back-link {
            display: block; text-align: center; margin-top: 16px;
            color: #475569; font-size: 13px; text-decoration: none;
        }
        .back-link:hover { color: #94a3b8; }

        @media (max-width: 768px) {
            body {
                padding: 16px 8px;
            }
            .card {
                padding: 24px 20px;
                max-width: 100%;
            }
            h2 {
                font-size: 20px;
            }
            .grid-2 {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .type-row {
                flex-direction: column;
                gap: 8px;
            }
            .time-section, #scand-opts {
                padding: 14px;
            }
            .btn-submit {
                padding: 14px;
                font-size: 15px;
            }
        }

        @media (max-width: 480px) {
            .card {
                padding: 20px 16px;
            }
            h2 {
                font-size: 18px;
            }
            input[type=text], input[type=number], input[type=datetime-local], textarea, select {
                font-size: 14px;
                padding: 12px 14px;
            }
            .field-group label {
                font-size: 11px;
            }
            .type-opt {
                padding: 12px;
            }
            .type-opt .to-icon {
                font-size: 20px;
            }
            .type-opt .to-name {
                font-size: 13px;
            }
            .type-opt .to-desc {
                font-size: 10px;
            }
        }
    </style>
</head>
<body>
<div class="card">
    <h2>📦 Создание лота</h2>

    <?php if ($msg): ?>
    <div class="msg" style="color:<?= $msg_color ?>;background:<?= $msg_color ?>22;">
        <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="auction_type" id="auction_type_input" value="classic">

        <div class="field-group">
            <label>Тип аукциона</label>
            <div class="type-row">
                <div class="type-opt selected" id="opt-classic" onclick="setType('classic')">
                    <div class="to-icon">🔨</div>
                    <div class="to-name">Классический</div>
                    <div class="to-desc">Цена растёт, побеждает максимальная ставка</div>
                </div>
                <div class="type-opt" id="opt-scandinavian" onclick="setType('scandinavian')">
                    <div class="to-icon">🔥</div>
                    <div class="to-name">Скандинавский</div>
                    <div class="to-desc">Платные ставки, победитель — последний</div>
                </div>
            </div>
        </div>

        <div class="field-group">
            <label>Название лота</label>
            <input type="text" name="title" placeholder="Например: Tesla Model S 2023" required>
        </div>

        <div class="grid-2">
            <div class="field-group">
                <label>Начальная цена (₽)</label>
                <input type="number" name="price" placeholder="50000" min="0" required>
            </div>
            <div class="field-group">
                <label>Задаток (₽)</label>
                <input type="number" name="deposit" placeholder="5000" min="0">
            </div>
        </div>

        <!-- НОВОЕ: ПОЛЯ ВРЕМЕННЫХ МЕТОК -->
        <div class="time-section">
            <h4>⏰ ПАРАМЕТРЫ ВРЕМЕНИ ТОРГОВ</h4>
            
            <div class="field-group">
                <label>Начало торгов (опционально)</label>
                <input type="datetime-local" name="start_at" 
                       placeholder="Оставьте пусто для немедленного начала">
                <div style="font-size:11px;color:#64748b;margin-top:4px;">
                    Если не заполнено — торги начнутся сразу после публикации
                </div>
            </div>
            
            <div class="grid-2">
                <div class="field-group">
                    <label>Длительность торгов (часов)</label>
                    <input type="number" name="duration" value="24" min="1" max="720">
                </div>
                <div class="field-group">
                    <label>Минут перед началом</label>
                    <input type="number" name="time_before_start" value="0" min="0" max="60">
                    <div style="font-size:10px;color:#64748b;margin-top:4px;">
                        Показывается как "Начинается через N минут"
                    </div>
                </div>
            </div>

            <div class="field-group" id="max-dur-group" style="display:none;">
                <label>Макс. продолжительность (часов)
                    <span style="color:#64748b;font-size:10px;font-weight:normal;">необязательно</span>
                </label>
                <input type="number" name="max_duration" placeholder="Не ограничена" min="1" max="720">
                <div style="font-size:11px;color:#64748b;margin-top:4px;">
                    После истечения — жёсткое закрытие
                </div>
            </div>
        </div>

        <div class="field-group">
            <label>Описание</label>
            <textarea name="description" placeholder="Описание лота..."></textarea>
        </div>

        <!-- Настройки скандинавского аукциона -->
        <div id="scand-opts">
            <h4>🔥 Параметры скандинавского аукциона</h4>
            <div class="grid-2">
                <div class="field-group">
                    <label>Шаг аукциона</label>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <input type="range" name="bid_step_pct" id="bid_step_pct"
                               min="0.5" max="5" step="0.5" value="2"
                               style="flex:1;accent-color:#3b82f6;"
                               oninput="updateStep()">
                        <span id="step-pct-label" style="font-size:16px;font-weight:bold;color:#f59e0b;white-space:nowrap;">2%</span>
                    </div>
                    <input type="hidden" name="bid_step" id="bid_step" value="1000">
                    <div id="step-rub-label" style="font-size:12px;color:#64748b;margin-top:6px;">
                        = <b id="step-rub-val" style="color:#fff;">—</b> ₽
                        <span style="color:#475569;">от начальной цены</span>
                    </div>
                </div>
                <div class="field-group">
                    <label>Начальный таймер (сек)</label>
                    <input type="number" name="timer_start" value="240" min="60" step="30">
                </div>
            </div>
            <div class="field-group">
                <label>Продление при ставке (сек)</label>
                <input type="number" name="timer_add" value="240" min="60" step="30">
                <div style="font-size:11px;color:#64748b;margin-top:4px;">
                    240 = +4 минуты при каждой ставке
                </div>
            </div>
            <div style="background:#1e293b;border-radius:8px;padding:12px;font-size:12px;color:#94a3b8;">
                💡 Итоговая цена за каждую ставку:<br>
                Уважаемый: <b id="hint-resp" style="color:#fff;">—</b> (шаг + 2 490 ₽)<br>
                Ответственный: <b id="hint-resp2" style="color:#fff;">—</b> (шаг + 1 890 ₽)
            </div>
        </div>

        <button type="submit" class="btn-submit">ОПУБЛИКОВАТЬ ЛОТ</button>
    </form>

    <a class="back-link" href="reestr.php">← Вернуться в реестр</a>
</div>

<script>
function setType(type) {
    document.getElementById('auction_type_input').value = type;
    document.getElementById('opt-classic').classList.toggle('selected',      type === 'classic');
    document.getElementById('opt-scandinavian').classList.toggle('selected', type === 'scandinavian');
    
    const scandOpts = document.getElementById('scand-opts');
    const maxDurGrp = document.getElementById('max-dur-group');
    
    if (type === 'scandinavian') {
        scandOpts.classList.add('show');
        maxDurGrp.style.display = 'block';
    } else {
        scandOpts.classList.remove('show');
        maxDurGrp.style.display = 'none';
    }
}

function updateStep() {
    const pct = document.getElementById('bid_step_pct').value;
    const price = parseFloat(document.querySelector('input[name="price"]').value) || 100000;
    const rub = Math.round((price * pct) / 100);
    
    document.getElementById('step-pct-label').textContent = pct + '%';
    document.getElementById('step-rub-val').textContent = rub.toLocaleString('ru-RU');
    document.getElementById('bid_step').value = rub;
    
    // Подсказка по цене ставок
    const respRub = rub + 2490;
    const resp2Rub = rub + 1890;
    document.getElementById('hint-resp').textContent = respRub.toLocaleString('ru-RU') + ' ₽';
    document.getElementById('hint-resp2').textContent = resp2Rub.toLocaleString('ru-RU') + ' ₽';
}

// Инициализация
document.addEventListener('DOMContentLoaded', function() {
    updateStep();
    document.querySelector('input[name="price"]').addEventListener('change', updateStep);
});
</script>
</body>
</html>

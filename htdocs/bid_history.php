<?php
include 'db.php'; 
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$stmt = $pdo->prepare("SELECT * FROM lots WHERE id = ?");
$stmt->execute([$id]);
$lot = $stmt->fetch();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Протокол торгов</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f7f6; display: flex; justify-content: center; padding: 40px 20px; }
        .box { background: #fff; width: 100%; max-width: 600px; padding: 30px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        h2 { text-align: center; margin: 0 0 5px; color: #1a1a1a; }
        .lot-num { text-align: center; color: #888; margin-bottom: 30px; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; color: #bbb; font-size: 12px; text-transform: uppercase; padding: 10px; border-bottom: 1px solid #eee; }
        td { padding: 15px 10px; border-bottom: 1px solid #f9f9f9; color: #333; font-size: 15px; }
        .price { font-weight: bold; color: #1a1a1a; text-align: right; }
        .btn-wrapper { text-align: center; margin-top: 40px; }
        .btn { background: #000; color: #fff; padding: 15px 35px; text-decoration: none; border-radius: 12px; font-weight: bold; font-size: 14px; transition: 0.3s; display: inline-block; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
    </style>
</head>
<body>

<div class="box">
    <a href="lot_details.php?id=<?php echo $id; ?>" style="color:#ccc; text-decoration:none; font-size:13px;">← Назад</a>
    <h2>Протокол торгов</h2>
    <div class="lot-num">Лот #<?php echo $id; ?></div>

    <table>
        <thead>
            <tr>
                <th>Участник</th>
                <th>Время</th>
                <th style="text-align:right">Ставка</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $bids_stmt = $pdo->prepare("SELECT * FROM bids WHERE lot_id = ? ORDER BY bid_amount DESC");
            $bids_stmt->execute([$id]);
            while($row = $bids_stmt->fetch()): 
                // Ищем имя юзера в разных колонках, если user_name пуст
                $name = $row['user_name'] ?? $row['username'] ?? $row['user'] ?? $row['name'] ?? ("ID " . ($row['user_id'] ?? '??'));
            ?>
            <tr>
                <td><b><?php echo htmlspecialchars($name); ?></b></td>
                <td style="color:#999; font-size: 13px;"><?php echo $row['bid_time']; ?></td>
                <td class="price"><?php echo number_format($row['bid_amount'], 0, '', ' '); ?> ₽</td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>

    <div class="btn-wrapper">
        <a href="download_protocol.php?lot_id=<?php echo $id; ?>" class="btn">СКАЧАТЬ ПРОТОКОЛ (.TXT)</a>
    </div>
</div>

</body>
</html>
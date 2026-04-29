<?php
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $uploadDir = 'uploads/docs/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    
    // Логика сохранения файлов...
    if (!empty($_FILES['u_docs']['name'][0])) {
        foreach ($_FILES['u_docs']['name'] as $k => $v) {
            move_uploaded_file($_FILES['u_docs']['tmp_name'][$k], $uploadDir . time() . "_" . $_FILES['u_docs']['name'][$k]);
        }
    }
    echo "Заявка отправлена! Проверка займет до 24 часов.";
}
?>
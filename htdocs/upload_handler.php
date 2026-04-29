<?php
if(isset($_FILES['chek'])){
    move_uploaded_file($_FILES['chek']['tmp_name'], 'cheks/'.$_FILES['chek']['name']);
    echo "Чек загружен! Ожидайте проверки.";
}
?>
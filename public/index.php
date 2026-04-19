<?php
$target = 'homepage.php';
if (!empty($_SERVER['QUERY_STRING'])) {
    $target .= '?' . $_SERVER['QUERY_STRING'];
}
header('Location: ' . $target);
exit;


<?php
session_start();
require_once __DIR__ . '/../Ho_common.php';

HoClearHotelAdminSession();
header('Location: hotel_admin_login.php');
exit;

<?php
if (!defined('__TYPECHO_ADMIN__')) {
    exit;
}

// 强制以 CID 标签打开管理页
$_GET['tab'] = 'cid';
require_once __DIR__ . '/manage.php';



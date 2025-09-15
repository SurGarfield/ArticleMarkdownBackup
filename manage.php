<?php
if (!defined('__TYPECHO_ADMIN__')) {
    exit;
}

// 引入头部文件
include 'header.php';
include 'menu.php';

// 获取文章列表
$db = Typecho_Db::get();
// 获取文章列表总数并分页
$pageSize = 20;
$currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$totalCount = (int) $db->fetchObject($db->select(["COUNT(*)" => 'num'])->from('table.contents')->where('type = ?', 'post'))->num;
$totalPages = (int) ceil($totalCount / $pageSize);
$offset = ($currentPage - 1) * $pageSize;
$articles = $db->fetchAll(
    $db->select()
        ->from('table.contents')
        ->where('type = ?', 'post')
        ->order('created', Typecho_Db::SORT_DESC)
        ->offset($offset)
        ->limit($pageSize)
);

// 获取备份文件列表
$backupFiles = [];
$backupDir = __DIR__ . '/backups/';
if (is_dir($backupDir)) {
    $files = glob($backupDir . 'AMD_backup_*.json');
    foreach ($files as $file) {
        $backupFiles[] = basename($file);
    }
    // 按时间排序，最新的在前面
    usort($backupFiles, function($a, $b) use ($backupDir) {
        return filemtime($backupDir . $b) - filemtime($backupDir . $a);
    });
}
// 分页链接基础路径
$panelBaseUrl = '/admin/extending.php?panel=ArticleMarkdownBackup%2Fmanage.php';

// 页面切换: 文章备份/转换 与 CID 管理
$activeTab = (isset($_GET['tab']) && $_GET['tab'] === 'cid') ? 'cid' : 'backup';

// CID 管理指标（最大有效CID按有效内容类型统计）
$rowsAllForMax = $db->fetchAll(
    $db->select('cid', 'type')->from('table.contents')
);
$validTypesForMax = ['post', 'post_draft', 'page', 'page_draft', 'revision'];
$maxValidCid = 0;
foreach ($rowsAllForMax as $r) {
    if (in_array($r['type'], $validTypesForMax, true)) {
        $cidVal = (int)$r['cid'];
        if ($cidVal > $maxValidCid) $maxValidCid = $cidVal;
    }
}

// 读取插件策略(安全获取, 无配置时默认 ignore)
$pluginStrategy = 'ignore';
$enableStrategyFlag = '0';
$isStrategyEnabled = false;
try {
    $optWidget = Typecho_Widget::widget('Widget_Options');
    $pl = $optWidget->plugin('ArticleMarkdownBackup');
    if (!empty($pl) && isset($pl->cidStrategy)) {
        $allowedStrategies = ['skip','ignore','grow_skip','grow_ignore'];
        $candidate = $pl->cidStrategy;
        $pluginStrategy = in_array($candidate, $allowedStrategies, true) ? $candidate : 'ignore';
    }
    if (!empty($pl) && isset($pl->enableStrategy)) {
        $enableStrategyFlag = (string)$pl->enableStrategy;
        $isStrategyEnabled = ($enableStrategyFlag === '1');
    }
} catch (Exception $e) {
    $pluginStrategy = 'ignore';
}

// 计算“策略建议的CID”：根据策略选择起点与是否将附件视为占用
$rowsAll = $db->fetchAll(
    $db->select('cid', 'type')->from('table.contents')->order('cid', Typecho_Db::SORT_ASC)
);
$occupiedAll = [];
$occupiedValid = [];
$validTypesForOccupy = ['post', 'post_draft', 'page', 'page_draft', 'revision'];
foreach ($rowsAll as $r) {
    $cidVal = (int)$r['cid'];
    $occupiedAll[$cidVal] = true;
    if (in_array($r['type'], $validTypesForOccupy, true)) {
        $occupiedValid[$cidVal] = true;
    }
}
$allCidUsedCount = count($occupiedAll);
$useAll = ($pluginStrategy === 'skip' || $pluginStrategy === 'grow_skip');
$occupied = $useAll ? $occupiedAll : $occupiedValid;
$start = ($pluginStrategy === 'grow_skip' || $pluginStrategy === 'grow_ignore') ? max(1, $maxValidCid + 1) : 1;
$recommendedNextCid = $start;
while (isset($occupied[$recommendedNextCid])) {
    $recommendedNextCid++;
}

// 附件统计
$attachmentCountObj = $db->fetchObject(
    $db->select(['COUNT(cid)' => 'num'])->from('table.contents')->where('type = ?', 'attachment')
);
$attachmentCount = $attachmentCountObj ? (int)$attachmentCountObj->num : 0;

// 附件分页（5个一组）
$attachPageSize = 5;
$attachCurrentPage = isset($_GET['attPage']) ? max(1, intval($_GET['attPage'])) : 1;
$attachTotalPages = (int) ceil(($attachmentCount > 0 ? $attachmentCount : 0) / $attachPageSize);
if ($attachTotalPages === 0) { $attachTotalPages = 1; }
$attachOffset = ($attachCurrentPage - 1) * $attachPageSize;
$attachments = $db->fetchAll(
    $db->select('cid', 'title', 'created', 'parent', 'text')
        ->from('table.contents')
        ->where('type = ?', 'attachment')
        ->order('cid', Typecho_Db::SORT_ASC)
        ->offset($attachOffset)
        ->limit($attachPageSize)
);

// 最近内容模块已移除

// 日志尾部
$logFile = __DIR__ . '/logs/cid-sync.log';
$logTail = '';
if (is_file($logFile)) {
    $content = @file($logFile);
    if (is_array($content)) {
        $tailLines = array_slice($content, -100);
        $logTail = htmlspecialchars(implode('', $tailLines));
    }
}
?>

<style>
/* 统一功能区域样式 */
.typecho-page-options.widget {
    background: #fff;
    border: 1px solid #d9d9d9;
    margin-bottom: 15px;
    padding: 15px;
    border-radius: 4px;
    box-shadow: 0 1px 1px rgba(0,0,0,0.05);
    box-sizing: border-box;
    max-width: 100%;
}

.typecho-page-options.widget h3 {
    margin: 0 0 10px;
    padding: 0 0 10px;
    border-bottom: 1px solid #eee;
    font-size: 16px;
    font-weight: bold;
    text-align: center;
}

.typecho-page-options.widget .typecho-option {
    margin: 0;
    padding: 0;
}

.typecho-page-options.widget .typecho-option li {
    margin-bottom: 10px;
    list-style: none;
}

.typecho-page-options.widget .typecho-option li:last-child {
    margin-bottom: 0;
}

/* 禁用按钮样式 */
.btn-disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* 文章列表样式优化 */
.typecho-list-table {
    table-layout: fixed;
    width: 100%;
    border-collapse: collapse;
    max-width: 100%;
}

.typecho-list-table th, .typecho-list-table td {
    white-space: nowrap;
    text-align: center;
    padding: 8px;
}

/* 为普通单元格保留省略号效果，但排除复选框列 */
.typecho-list-table .title-col,
.typecho-list-table .author-col {
    overflow: hidden;
    text-overflow: ellipsis;
}

.typecho-list-table .status {
    font-size: 12px;
    color: #999;
    margin-left: 5px;
}

/* 修复复选框显示问题 */
.typecho-list-table td:first-child {
    text-align: center;
    width: 20px;
    overflow: visible; /* 确保复选框不会被裁剪 */
}

.typecho-list-table td:first-child input[type="checkbox"] {
    display: inline-block !important;
    position: relative !important;
    opacity: 1 !important;
    visibility: visible !important;
    margin: 0 auto;
}

/* 按钮样式统一 */
.btn-block {
    display: block;
    width: 100%;
    text-align: center;
    box-sizing: border-box;
}

/* 50%布局优化 */
.col-tb-6 {
    width: 50%;
    float: left;
    box-sizing: border-box;
    padding: 0 15px;
}

/* 内容居中容器 - 不占满全部空间 */
.content-wrapper {
    max-width: 100%;
    margin: 0 auto;
    box-sizing: border-box;
}

/* 表格容器 */
.table-container {
    max-width: 100%;
    overflow-x: hidden;
    box-sizing: border-box;
}

/* 功能区域容器 */
.widget-container {
    max-width: 100%;
    margin: 0 auto;
    box-sizing: border-box;
}

/* 居中显示容器 */
.content-container {
    max-width: 100%;
    margin: 0 auto;

    box-sizing: border-box;
}

.typecho-table-wrap {
    overflow-x: hidden;
    box-sizing: border-box;
    max-width: 100%;
}

.typecho-widget-list {
    box-sizing: border-box;
    max-width: 100%;
}

/* 分页样式 */
.pagination .page-navigator {
    display: inline-block;
    padding: 4px 8px;
    border: 1px solid #d9d9d9;
    margin: 0 2px;
    border-radius: 3px;
    text-decoration: none;
    color: #333;
}
.pagination .page-navigator:hover {
    background: #f5f5f5;
}
.pagination .current {
    background: #1890ff;
    color: #fff;
    border-color: #1890ff;
}

/* 响应式优化 */
@media (max-width: 768px) {
    .col-tb-6 {
        width: 100%;
        float: none;
        padding: 0;
    }
    
    .content-container {
        padding: 0 10px;
    }
    
    .content-wrapper,
    .widget-container {
        max-width: 100%;
    }
    
    .typecho-list-table .title-col {
        width: 70%;
    }
    
    .typecho-list-table .author-col {
        width: 30%;
    }
    
    /* 移动端复选框优化 */
    .typecho-list-table td:first-child {
        width: 30px;
    }
}

/* 确保页面不超出屏幕 */
.main {
    overflow-x: hidden;
    box-sizing: border-box;
}

.body {
    box-sizing: border-box;
    max-width: 100%;
    margin: 0 auto;
}

.container {
    box-sizing: border-box;
    max-width: 70%;
    padding: 0 10px;
    margin: 0 auto;
}

.typecho-page-main {
    overflow-x: hidden;
    box-sizing: border-box;
    max-width: 100%;
}

.typecho-list {
    box-sizing: border-box;
    max-width: 100%;
}

.row {
    box-sizing: border-box;
    max-width: 100%;
    margin: 5px auto;
}

/* 清除浮动 */
.row::after {
    content: "";
    display: table;
    clear: both;
}

/* 与插件功能区左边框对齐 */
.typecho-page-title {
    margin-left: 35px;
}
.typecho-option-tabs {
    margin-left: 25px;
}
</style>

<div class="main">
    <div class="body container">
        <?php include 'page-title.php'; ?>
        <div class="row typecho-page-main manage-metas" style="margin-top:10px;">
            <div class="col-mb-12">
                <ul class="typecho-option-tabs clearfix">
                    <li class="<?php echo $activeTab==='backup'?'current':''; ?>">
                        <a href="<?php echo $panelBaseUrl; ?>&tab=backup">文章备份与转换</a>
                    </li>
                    <li class="<?php echo $activeTab==='cid'?'current':''; ?>">
                        <a href="<?php echo $panelBaseUrl; ?>&tab=cid">CID 连贯管理</a>
                    </li>
                </ul>
            </div>
        </div>
        <div class="content-container">
            <div class="row typecho-page-main manage-metas" role="main">
                <div class="col-mb-12 typecho-list<?php echo $activeTab==='cid' ? ' cid-page' : ''; ?>">
                    <?php if ($activeTab === 'backup'): ?>
                    <div class="row">
                        <!-- 左侧文章列表（仅备份/转换页显示） -->
                        <div class="col-tb-6" role="main">
                            <div class="content-wrapper">
                                    <div class="table-container">
                                        <div class="typecho-table-wrap">
                                            <form method="post" name="manage_posts" class="operate-form">
                                            <table class="typecho-list-table">
                                                <colgroup>
                                                    <col width="20"/>
                                                    <col width="60%"/>
                                                    <col width="40%"/>
                                                </colgroup>
                                                <thead>
                                                    <tr>
                                                        <th>
                                                            <label><i class="sr-only"><?php _e('全选'); ?></i><input type="checkbox" class="typecho-table-select-all" /></label>
                                                        </th>
                                                        <th class="title-col"><?php _e('标题'); ?></th>
                                                        <th class="author-col"><?php _e('作者'); ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (!empty($articles)): ?>
                                                    <?php foreach ($articles as $article): ?>
                                                    <tr id="post-<?php echo isset($article['cid']) ? $article['cid'] : ''; ?>">
                                                        <td><input type="checkbox" value="<?php echo isset($article['cid']) ? $article['cid'] : ''; ?>" name="cid[]"/></td>
                                                        <td class="title-col">
                                                            <?php 
                                                            // 修复标题显示问题
                                                            $title = isset($article['title']) ? $article['title'] : '';
                                                            echo htmlspecialchars($title);
                                                            ?>
                                                        </td>
                                                        <td class="author-col">
                                                            <?php 
                                                            if (isset($article['authorId'])) {
                                                                // 查询昵称
                                                                $user = $db->fetchRow($db->select('screenName')->from('table.users')->where('uid = ?', $article['authorId']));
                                                                echo $user ? htmlspecialchars($user['screenName']) : $article['authorId'];
                                                            }
                                                            ?>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                    <?php else: ?>
                                                    <tr>
                                                        <td colspan="3"><h6 class="typecho-list-table-title"><?php _e('没有任何文章'); ?></h6></td>
                                                    </tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                            <?php if ($totalPages > 1): ?>
                                                <div class="pagination" style="text-align:center;margin:10px 0;">
                                                    <?php if ($currentPage > 1): ?>
                                                        <a class="page-navigator" href="<?php echo $panelBaseUrl . '&page=' . ($currentPage-1); ?>">&laquo; <?php _e('上一页'); ?></a>
                                                    <?php endif; ?>
                                                    <?php
                                                        $maxPagesToShow = 5;
                                                        $startPage = max(1, $currentPage - floor($maxPagesToShow/2));
                                                        $endPage = min($totalPages, $startPage + $maxPagesToShow - 1);
                                                        if ($endPage - $startPage + 1 < $maxPagesToShow) {
                                                            $startPage = max(1, $endPage - $maxPagesToShow + 1);
                                                        }
                                                    ?>
                                                    <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                                                        <?php if ($p == $currentPage): ?>
                                                            <span class="page-navigator current" style="margin:0 4px;font-weight:bold;"><?php echo $p; ?></span>
                                                        <?php else: ?>
                                                            <a class="page-navigator" href="<?php echo $panelBaseUrl . '&page=' . $p; ?>" style="margin:0 4px;"><?php echo $p; ?></a>
                                                        <?php endif; ?>
                                                    <?php endfor; ?>
                                                    <?php if ($currentPage < $totalPages): ?>
                                                        <a class="page-navigator" href="<?php echo $panelBaseUrl . '&page=' . ($currentPage+1); ?>"><?php _e('下一页'); ?> &raquo;</a>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            </form><!-- 确保表单正确闭合 -->
                                        </div>
                                    </div>
                            </div>
                        </div>
                    <?php endif; ?>
                        
                        <!-- 右侧功能面板 -->
                        <div class="col-tb-6" role="form" <?php if ($activeTab === 'cid') echo 'style="width:100%;float:none;max-width:100%;"'; ?>>
                            <div class="widget-container">
                                <div class="typecho-widget-list">
                                    <?php if ($activeTab === 'backup'): ?>
                                        <!-- 主要功能操作 -->
                                        <section class="typecho-page-options widget">
                                            <h3><?php _e('主要功能'); ?></h3>
                                            <ul class="typecho-option">
                                                <li>
                                                    <button id="backup-all-btn" class="btn btn-s primary btn-block"><?php _e('备份所有文章'); ?></button>
                                                </li>
                                                <li>
                                                    <button id="backup-selected-btn" class="btn btn-s btn-block"><?php _e('备份勾选文章'); ?></button>
                                                </li>
                                                <li>
                                                    <button id="convert-all-btn" class="btn btn-s btn-block"><?php _e('全部转为MD格式'); ?></button>
                                                </li>
                                                <li>
                                                    <button id="convert-selected-btn" class="btn btn-s btn-block"><?php _e('勾选转为MD格式'); ?></button>
                                                </li>
                                            </ul>
                                        </section>
                                        
                                        <!-- 上传备份文件 -->
                                        <section class="typecho-page-options widget">
                                            <h3><?php _e('上传备份文件'); ?></h3>
                                            <form method="post" enctype="multipart/form-data" action="<?php echo $security->index('/action/article_markdown_backup?do=uploadBackup'); ?>">
                                                <ul class="typecho-option">
                                                    <li>
                                                        <input type="file" name="backup_file" accept=".json" required />
                                                    </li>
                                                    <li>
                                                        <button type="submit" class="btn btn-s btn-block"><?php _e('上传'); ?></button>
                                                    </li>
                                                </ul>
                                            </form>
                                        </section>
                                        
                                        <!-- 恢复文章数据 -->
                                        <section class="typecho-page-options widget">
                                            <h3><?php _e('恢复文章数据'); ?></h3>
                                            <form method="post" action="<?php echo $security->index('/action/article_markdown_backup?do=restore'); ?>">
                                                <ul class="typecho-option">
                                                    <li>
                                                        <select name="backup_file" class="w-100">
                                                            <option value=""><?php _e('使用最新备份'); ?></option>
                                                            <?php foreach ($backupFiles as $file): ?>
                                                            <option value="<?php echo htmlspecialchars($file); ?>"><?php echo htmlspecialchars($file); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </li>
                                                    <li>
                                                        <button type="submit" class="btn btn-s btn-block" onclick="return confirm('<?php _e('确定要恢复文章数据吗？'); ?>')"><?php _e('恢复'); ?></button>
                                                    </li>
                                                </ul>
                                            </form>
                                        </section>
                                        
                                        <!-- 备份文件列表 -->
                                        <?php if (!empty($backupFiles)): ?>
                                        <section class="typecho-page-options widget">
                                            <h3><?php _e('备份文件列表'); ?></h3>
                                            <ul class="typecho-option">
                                                <?php 
                                                $count = 0;
                                                foreach ($backupFiles as $file): 
                                                    if ($count >= 5) break; // 只显示最新的5个备份文件
                                                ?>
                                                <li>
                                                    <span class="mono"><?php echo htmlspecialchars($file); ?></span>
                                                </li>
                                                <?php 
                                                    $count++;
                                                    endforeach; 
                                                ?>
                                                <?php if (count($backupFiles) > 5): ?>
                                                <li>
                                                    <span>...</span>
                                                </li>
                                                <?php endif; ?>
                                            </ul>
                                        </section>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <!-- CID 管理面板：第一行（附件管理/调试与日志），第二行（策略管理/CID 状态） -->
                                        <div class="row">
                                            <div class="col-tb-6">
                                                <section class="typecho-page-options widget">
                                                    <h3><?php _e('附件管理'); ?></h3>
                                                    <ul class="typecho-option">
                                                        <li>
                                                            <form method="post" action="<?php echo $security->index('/action/article_markdown_backup?do=deleteAllAttachments'); ?>" onsubmit="return confirm('确定删除所有附件吗？该操作不可恢复！');">
                                                                <button type="submit" class="btn btn-s btn-block danger">删除全部附件</button>
                                                            </form>
                                                        </li>
                                                        <li>
                                                            <form method="post" action="<?php echo $security->index('/action/article_markdown_backup?do=deleteSelectedAttachments'); ?>" onsubmit="return confirm('确定删除勾选的附件吗？');">
                                                                <div style="max-height:220px;overflow:auto;border:1px solid #eee;padding:8px;">
                                                                    <?php if (!empty($attachments)) : ?>
                                                                        <?php foreach ($attachments as $att): ?>
                                                                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                                                                <input type="checkbox" name="cid[]" value="<?php echo (int)$att['cid']; ?>" />
                                                                                <span>#<?php echo (int)$att['cid']; ?></span>
                                                                                <span class="mono" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                                                                    <?php echo htmlspecialchars($att['title'] ?: '[无标题附件]'); ?>
                                                                                </span>
                                                                                <span style="color:#999;">parent=<?php echo (int)$att['parent']; ?></span>
                                                                            </div>
                                                                        <?php endforeach; ?>
                                                                    <?php else: ?>
                                                                        <div style="color:#999;">暂无附件</div>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <div style="margin-top:8px;display:flex;justify-content:space-between;align-items:center;">
                                                                    <div>
                                                                        <?php if ($attachCurrentPage > 1): ?>
                                                                            <a class="page-navigator" href="<?php echo $panelBaseUrl . '&tab=cid&attPage=' . ($attachCurrentPage-1); ?>">&laquo; 上一组</a>
                                                                        <?php endif; ?>
                                                                        <span class="page-navigator current" style="margin:0 4px;">第 <?php echo $attachCurrentPage; ?> / <?php echo $attachTotalPages; ?> 组</span>
                                                                        <?php if ($attachCurrentPage < $attachTotalPages): ?>
                                                                            <a class="page-navigator" href="<?php echo $panelBaseUrl . '&tab=cid&attPage=' . ($attachCurrentPage+1); ?>">下一组 &raquo;</a>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <div>
                                                                        <button type="submit" class="btn btn-s">删除勾选附件</button>
                                                                    </div>
                                                                </div>
                                                            </form>
                                                        </li>
                                                    </ul>
                                                </section>
                                            </div>
                                            <div class="col-tb-6">
                                                <section class="typecho-page-options widget">
                                                    <h3><?php _e('调试与日志'); ?></h3>
                                                    <ul class="typecho-option">
                                                        <li>
                                                            <div style="margin-bottom:8px; display:flex; gap:8px; justify-content:flex-end;">
                                                                <a class="btn btn-s danger" onclick="return confirm('确定清空日志吗？');" href="<?php echo $security->index('/action/article_markdown_backup?do=clearLog'); ?>">清空日志</a>
                                                            </div>
                                                            <textarea readonly style="width:100%;height:140px;white-space:pre;"><?php echo $logTail !== '' ? $logTail : '（暂无日志）'; ?></textarea>
                                                            <div style="margin-top:8px; display:flex; gap:8px; justify-content:space-between; align-items:center;">
                                                                <?php $statusText = $isStrategyEnabled ? '已生效' : '未生效'; $statusColor = $isStrategyEnabled ? '#0a0' : '#f00'; ?>
                                                                <strong style="color:<?php echo $statusColor; ?>;">策略<?php echo $statusText; ?></strong>
                                                                <a class="btn btn-s ab-refresh-log" href="<?php echo $panelBaseUrl . '&tab=cid'; ?>">刷新日志</a>
                                                            </div>
                                                        </li>
                                                    </ul>
                                                </section>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-tb-6">
                                                <section class="typecho-page-options widget">
                                                    <h3><?php _e('策略管理'); ?></h3>
                                                    <ul class="typecho-option">
                                                        <li>
                                                            <form method="post" action="<?php echo $security->index('/action/article_markdown_backup?do=setStrategy'); ?>">
                                                                <div style="display:block;">
                                                                    <label style="display:block;margin-bottom:6px;"><input type="radio" name="cidStrategy" value="skip" <?php echo $pluginStrategy==='skip'?'checked':''; ?> /> 按最小可用位（跳过附件）【不建议】</label>
                                                                    <label style="display:block;margin-bottom:6px;"><input type="radio" name="cidStrategy" value="ignore" <?php echo $pluginStrategy==='ignore'?'checked':''; ?> /> 按最小可用位（忽略附件，遇附件则删除）【不建议】</label>
                                                                    <label style="display:block;margin-bottom:6px;"><input type="radio" name="cidStrategy" value="grow_skip" <?php echo $pluginStrategy==='grow_skip'?'checked':''; ?> /> 按新增可用位（从现有最大CID开始）</label>
                                                                    <label style="display:block;margin-bottom:0;"><input type="radio" name="cidStrategy" value="grow_ignore" <?php echo $pluginStrategy==='grow_ignore'?'checked':''; ?> /> 按新增可用位（忽略附件，遇附件则删除）</label>
                                                                
                                                                </div>
                                                                <div style="text-align:center;margin-top:10px;">
                                                                    <button type="submit" class="btn btn-s primary">保存策略</button>
                                                                </div>
                                                            </form>
                                                        </li>
                                                    </ul>
                                                </section>
                                            </div>
                                            <div class="col-tb-6">
                                                <section class="typecho-page-options widget">
                                                    <h3><?php _e('CID 状态'); ?></h3>
                                                    <ul class="typecho-option">
                                                        <li>目前最大的CID：<strong><?php echo (int)$maxValidCid; ?></strong></li>
                                                        <li>建议下一个CID：<strong><?php echo (int)$recommendedNextCid; ?></strong></li>
                                                        <li>所有CID使用数：<strong><?php echo (int)$allCidUsedCount; ?></strong> 个</li>
                                                        <li>
                                                            <?php 
                                                                $strategyLabelMap = [
                                                                    'skip' => '按最小可用位（跳过附件）',
                                                                    'ignore' => '按最小可用位（忽略附件，遇附件则删除）',
                                                                    'grow_skip' => '按新增可用位（从现有最大CID开始）',
                                                                    'grow_ignore' => '按新增可用位（忽略附件，遇附件则删除）',
                                                                ];
                                                                $strategyLabel = isset($strategyLabelMap[$pluginStrategy]) ? $strategyLabelMap[$pluginStrategy] : $pluginStrategy;
                                                            ?>
                                                            <strong style="color:#ff0000;">当前策略：<?php echo htmlspecialchars($strategyLabel); ?></strong>
                                                        </li>
                                                        <li>建议策略：<strong>按新增可用位（从现有最大CID开始）</strong></li>
                                                    </ul>
                                                </section>
                                            </div>
                                        </div>
                                        
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
include 'copyright.php';
include 'common-js.php';
include 'table-js.php';
?>

<script>
$(document).ready(function() {
    $('a.page-navigator[href*="ArticleMarkdownBackup%2Fmanage.php&tab="]').removeAttr('target').removeAttr('rel');
    $(document).on('click', 'a.page-navigator[href*="ArticleMarkdownBackup%2Fmanage.php&tab="]', function(e){ e.preventDefault(); window.location.href = $(this).attr('href'); });
    $('.typecho-option-tabs a').removeAttr('target').removeAttr('rel');
    $(document).on('click', '.typecho-option-tabs a', function(e){ e.preventDefault(); window.location.href = $(this).attr('href'); });
    $(document).on('click', 'a.ab-refresh-log', function(e){ e.preventDefault(); window.location.href = $(this).attr('href'); });

    var isBackupTab = <?php echo ($activeTab === 'backup') ? 'true' : 'false'; ?>;

    if (isBackupTab) {
        $('#backup-all-btn').on('click', function(){ if (confirm('<?php _e('确定要备份所有文章吗？'); ?>')) { window.location.href = '<?php echo $security->index('/action/article_markdown_backup?do=backupAll'); ?>'; } });
        $('#backup-selected-btn').on('click', function(){
            var ids = [];
            $('.typecho-list-table input[name="cid[]"]:checked').each(function(){ ids.push($(this).val()); });
            if (!ids.length) { alert('请先勾选要备份的文章'); return; }
            if (confirm('确定要备份勾选的文章吗？')) {
                var form = $('<form action="/index.php/action/article_markdown_backup?do=backupSelected" method="post"></form>');
                ids.forEach(function(id){ form.append('<input type="hidden" name="cid[]" value="'+id+'">'); });
                $('body').append(form);
                form.submit();
            }
        });
        $('#convert-all-btn').on('click', function(){ if (confirm('<?php _e('确定要将所有HTML文章转为MD格式吗？此操作不可逆！'); ?>')) { window.location.href = '<?php echo $security->index('/action/article_markdown_backup?do=convertAll'); ?>'; } });

        function updateConvertSelectedState(){
            var anyChecked = $('.typecho-list-table input[name="cid[]"]:checked').length > 0;
            $('#convert-selected-btn').prop('disabled', !anyChecked).toggleClass('btn-disabled', !anyChecked);
        }
        $(document).on('change', 'form[name="manage_posts"] input[type="checkbox"], .typecho-table-select-all', function(){
            if ($(this).hasClass('typecho-table-select-all')) {
                var s = $(this).prop('checked');
                $('form[name="manage_posts"] input[name="cid[]"]').prop('checked', s);
            }
            updateConvertSelectedState();
        });
        $('#convert-selected-btn').on('click', function(){
            var ids = [];
            $('form[name="manage_posts"] input[name="cid[]"]:checked').each(function(){ ids.push($(this).val()); });
            if (!ids.length) { alert('<?php _e('请至少选择一篇文章'); ?>'); return; }
            if (!confirm('确定要将选中的'+ids.length+'篇文章转为MD格式吗？此操作不可逆！')) { return; }
            var form = $('<form action="<?php echo $security->index("/action/article_markdown_backup?do=convertSelected"); ?>" method="post"></form>');
            ids.forEach(function(id){ form.append('<input type="hidden" name="cid[]" value="'+id+'">'); });
            $('body').append(form);
            form.submit();
        });
        updateConvertSelectedState();
    }
});
</script>
<script>
$(document).ready(function() {
    $('.pagination a.page-navigator').removeAttr('target').removeAttr('rel');
});
</script>
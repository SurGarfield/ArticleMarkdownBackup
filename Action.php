<?php
class ArticleMarkdownBackup_Action extends Typecho_Widget implements Widget\ActionInterface
{
    /**
     * 判断文章是否已经是 Markdown
     * 规则：以 <!--markdown--> 开头，且去掉标记后不再包含 HTML 标签
     * @param string $text
     * @return bool
     */
    private function isMarkdownText($text)
    {
        if (preg_match('/^\\s*<!--markdown-->/', $text) !== 1) {
            return false;
        }
        $contentWithoutMarker = preg_replace('/^\\s*<!--markdown-->/', '', $text, 1);
        // 若去掉标记后仍含 HTML 标签，则仍需转换
        return preg_match('/<\\s*[a-zA-Z]+[^>]*>/', $contentWithoutMarker) !== 1;
    }

    /**
     * 生成备份文件名 AMD_backup_YYYYMMDD_XX.json
     * XX 为当天递增的两位序号
     * @return string
     */
    private function generateBackupFilename()
    {
        $dateStr = date('Ymd');
        $pattern = __DIR__ . '/backups/AMD_backup_' . $dateStr . '_*.json';
        $existingFiles = glob($pattern);
        $sequence = count($existingFiles) + 1;
        $sequenceStr = str_pad($sequence, 2, '0', STR_PAD_LEFT);
        return 'AMD_backup_' . $dateStr . '_' . $sequenceStr . '.json';
    }

    /**
     * 初始化函数
     */
    public function execute()
    {
        // 验证用户权限
        $user = Typecho_Widget::widget('Widget_User');
        if (!$user->pass('administrator')) {
            throw new Typecho_Widget_Exception(_t('禁止访问'), 403);
        }
    }
    
    /**
     * 备份所有文章
     */
    public function backupAll($internal = false)
    {
        try {
            // 获取数据库实例
            $db = Typecho_Db::get();
            
            // 获取所有文章
            $articles = $db->fetchAll($db->select()->from('table.contents')->where('type = ?', 'post'));
            
            // 获取所有评论
            $comments = $db->fetchAll($db->select()->from('table.comments'));
            
            // 创建备份数据
            $backupData = array(
                'articles' => $articles,
                'comments' => $comments,
                'timestamp' => time(),
                'version' => '1.0'
            );
            
            // 生成备份文件名
            $filename = $this->generateBackupFilename();
            $filepath = __DIR__ . '/backups/' . $filename;
            
            // 确保备份目录存在
            if (!is_dir(__DIR__ . '/backups')) {
                mkdir(__DIR__ . '/backups', 0755, true);
            }
            
            // 写入备份文件
            file_put_contents($filepath, json_encode($backupData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            
            // 设置成功消息
            $this->widget('Widget_Notice')->set(_t('文章备份成功，文件保存在: %s', $filename), 'success');
        } catch (Exception $e) {
            // 记录错误日志
            error_log('ArticleMarkdownBackup backupAll error: ' . $e->getMessage());
            // 设置错误消息
            $this->widget('Widget_Notice')->set(_t('备份失败: %s', $e->getMessage()), 'error');
        }
        
        // 重定向回管理页面
        // 重定向回管理页面
        if (!$internal) {
            // 仅在外部调用时执行重定向，内部调用避免中断后续逻辑
            $this->response->redirect(Typecho_Common::url('extending.php?panel=ArticleMarkdownBackup/manage.php', $this->widget('Widget_Options')->adminUrl));
        }
    }
    
    /**
     * 备份选中的文章
     */
    public function backupSelected($internal = false)
    {
        try {
            // 获取选中的文章ID - 支持两种提交方式
            $cids = $this->request->get('cids');
            $cidArray = [];
            
            // 检查是否通过表单数组提交
            $formCids = $this->request->getArray('cid');
            if (!empty($formCids)) {
                $cidArray = array_map('intval', $formCids);
            }
            // 如果没有通过表单提交，则尝试从URL参数获取
            else if (!empty($cids)) {
                $cidArray = explode(',', $cids);
                $cidArray = array_map('intval', $cidArray);
            }
            
            // 检查是否有选中的文章
            if (empty($cidArray)) {
                throw new Exception('未选择任何文章');
            }
            
            // 获取数据库实例
            $db = Typecho_Db::get();
            
            // 获取选中的文章
            $articles = [];
            foreach ($cidArray as $cid) {
                $article = $db->fetchRow($db->select()->from('table.contents')->where('cid = ?', $cid)->where('type = ?', 'post'));
                if ($article) {
                    $articles[] = $article;
                }
            }
            
            // 获取相关的评论
            $comments = [];
            foreach ($cidArray as $cid) {
                $articleComments = $db->fetchAll($db->select()->from('table.comments')->where('cid = ?', $cid));
                $comments = array_merge($comments, $articleComments);
            }
            
            // 创建备份数据
            $backupData = array(
                'articles' => $articles,
                'comments' => $comments,
                'timestamp' => time(),
                'version' => '1.0'
            );
            
            // 生成备份文件名
            $filename = $this->generateBackupFilename();
            $filepath = __DIR__ . '/backups/' . $filename;
            
            // 确保备份目录存在
            if (!is_dir(__DIR__ . '/backups')) {
                mkdir(__DIR__ . '/backups', 0755, true);
            }
            
            // 写入备份文件
            file_put_contents($filepath, json_encode($backupData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            
            // 设置成功消息
            $this->widget('Widget_Notice')->set(_t('选中的文章备份成功，文件保存在: %s', $filename), 'success');
        } catch (Exception $e) {
            // 记录错误日志
            error_log('ArticleMarkdownBackup backupSelected error: ' . $e->getMessage());
            // 设置错误消息
            $this->widget('Widget_Notice')->set(_t('备份失败: %s', $e->getMessage()), 'error');
        }
        
        // 重定向回管理页面
        // 重定向回管理页面
        if (!$internal) {
            // 仅在外部调用时执行重定向，内部调用避免中断后续逻辑
            $this->response->redirect(Typecho_Common::url('extending.php?panel=ArticleMarkdownBackup/manage.php', $this->widget('Widget_Options')->adminUrl));
        }
    }
    
    /**
     * 上传备份文件
     */
    public function uploadBackup()
    {
        try {
            // 检查是否有上传文件
            if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] != UPLOAD_ERR_OK) {
                throw new Exception('上传文件失败');
            }
            
            // 检查文件类型
            $fileInfo = pathinfo($_FILES['backup_file']['name']);
            if (strtolower($fileInfo['extension']) != 'json') {
                throw new Exception('只支持JSON格式的备份文件');
            }
            
            // 生成目标文件名
            $filename = $this->generateBackupFilename();
            $filepath = __DIR__ . '/backups/' . $filename;
            
            // 确保备份目录存在
            if (!is_dir(__DIR__ . '/backups')) {
                mkdir(__DIR__ . '/backups', 0755, true);
            }
            
            // 移动上传文件
            if (!move_uploaded_file($_FILES['backup_file']['tmp_name'], $filepath)) {
                throw new Exception('文件保存失败');
            }
            
            // 设置成功消息
            $this->widget('Widget_Notice')->set(_t('备份文件上传成功: %s', $filename), 'success');
        } catch (Exception $e) {
            // 记录错误日志
            error_log('ArticleMarkdownBackup uploadBackup error: ' . $e->getMessage());
            // 设置错误消息
            $this->widget('Widget_Notice')->set(_t('上传失败: %s', $e->getMessage()), 'error');
        }
        
        // 重定向回管理页面
        $this->response->redirect(Typecho_Common::url('extending.php?panel=ArticleMarkdownBackup/manage.php', $this->widget('Widget_Options')->adminUrl));
    }
    
    /**
     * 恢复文章数据
     */
    public function restore()
    {
        try {
            $backupFile = $this->request->get('backup_file');
            
            // 如果指定了备份文件，则使用指定的文件
            if (!empty($backupFile)) {
                $filepath = __DIR__ . '/backups/' . $backupFile;
                if (!file_exists($filepath)) {
                    throw new Exception('指定的备份文件不存在');
                }
            } else {
                // 查找最新的备份文件
                $backupDir = __DIR__ . '/backups/';
                if (!is_dir($backupDir)) {
                    throw new Exception('备份目录不存在');
                }
                
                $backupFiles = glob($backupDir . 'AMD_backup_*.json');
                if (empty($backupFiles)) {
                    throw new Exception('未找到备份文件');
                }
                
                // 按时间排序，获取最新的备份文件
                usort($backupFiles, function($a, $b) {
                    return filemtime($b) - filemtime($a);
                });
                
                $filepath = $backupFiles[0];
            }
            
            $backupData = json_decode(file_get_contents($filepath), true);
            
            if (!$backupData) {
                throw new Exception('备份文件格式错误');
            }
            
            // 获取数据库实例
            $db = Typecho_Db::get();
            
            // 恢复文章数据
            foreach ($backupData['articles'] as $article) {
                // 检查文章是否已存在
                $existing = $db->fetchRow($db->select()->from('table.contents')->where('cid = ?', $article['cid']));
                if ($existing) {
                    // 更新现有文章，排除cid字段
                    $updateData = $article;
                    unset($updateData['cid']);
                    $db->query($db->update('table.contents')->rows($updateData)->where('cid = ?', $article['cid']));
                } else {
                    // 插入新文章
                    $db->query($db->insert('table.contents')->rows($article));
                }
            }
            
            // 恢复评论数据
            foreach ($backupData['comments'] as $comment) {
                // 检查评论是否已存在
                $existing = $db->fetchRow($db->select()->from('table.comments')->where('coid = ?', $comment['coid']));
                if ($existing) {
                    // 更新现有评论，排除coid字段
                    $updateData = $comment;
                    unset($updateData['coid']);
                    $db->query($db->update('table.comments')->rows($updateData)->where('coid = ?', $comment['coid']));
                } else {
                    // 插入新评论
                    $db->query($db->insert('table.comments')->rows($comment));
                }
            }
            
            // 设置成功消息
            $this->widget('Widget_Notice')->set(_t('文章数据恢复成功'), 'success');
        } catch (Exception $e) {
            // 记录错误日志
            error_log('ArticleMarkdownBackup restore error: ' . $e->getMessage());
            // 设置错误消息
            $this->widget('Widget_Notice')->set(_t('恢复失败: %s', $e->getMessage()), 'error');
        }
        
        // 重定向回管理页面
        $this->response->redirect(Typecho_Common::url('extending.php?panel=ArticleMarkdownBackup/manage.php', $this->widget('Widget_Options')->adminUrl));
    }
    
    /**
     * 将所有HTML文章转换为Markdown格式
     */
    public function convertAll()
    {
        try {
            // 获取数据库实例
            $db = Typecho_Db::get();
            
            // 获取所有文章（type=post）
            $articles = $db->fetchAll($db->select('cid', 'title', 'text')->from('table.contents')
                ->where('type = ?', 'post'));

            // 过滤需要转换的文章：
            // 1. 未以 <!--markdown--> 开头（尚未转换）
            // 2. 仍然包含 HTML 标签
            $filteredArticles = [];
            foreach ($articles as $article) {
                $text = isset($article['text']) ? $article['text'] : '';
                $alreadyMarkdown = $this->isMarkdownText($text);
                if (!$alreadyMarkdown) {
                    $filteredArticles[] = $article;
                }
            }
            
            $convertedCount = 0;
            
            // 加载Markdown转换库
            if (!class_exists('MarkdownConverter')) {
                require_once __DIR__ . '/MarkdownConverter.php';
            }
            
            foreach ($filteredArticles as $article) {
                // 检查文章是否有有效的cid
                if (!isset($article['cid']) || empty($article['cid'])) {
                    continue;
                }
                
                $htmlContent = $article['text'];
                
                // 如果内容以<!--markdown-->开头，移除这个标记以获取原始HTML内容
                if (strpos($htmlContent, '<!--markdown-->') === 0) {
                    $htmlContent = substr($htmlContent, 15); // 移除<!--markdown-->标记
                }
                
                // 转换HTML到Markdown
                $markdown = new MarkdownConverter($htmlContent);
                $markdownContent = $markdown->convert();
                
                // 添加Markdown格式标记
                $markdownText = '<!--markdown-->' . $markdownContent;
                
                // 更新数据库，只更新text字段
                try {
                    $db->query($db->update('table.contents')
                        ->rows(['text' => $markdownText])
                        ->where('cid = ?', $article['cid']));
                    
                    // Typecho 会自动处理缓存刷新，这里不再手动删除文章或评论记录，避免误删数据
                } catch (Exception $updateError) {
                    error_log('ArticleMarkdownBackup convertSelected update error: ' . $updateError->getMessage() . ' for cid: ' . $article['cid']);
                    continue;
                }
                
                $convertedCount++;
            }
            
            // 设置成功消息
            $this->widget('Widget_Notice')->set(_t('成功将 %d 篇文章转换为Markdown格式', $convertedCount), 'success');
        } catch (Exception $e) {
            // 记录错误日志
            error_log('ArticleMarkdownBackup convertAll error: ' . $e->getMessage());
            // 设置错误消息
            $this->widget('Widget_Notice')->set(_t('转换失败: %s', $e->getMessage()), 'error');
        }
        
        // 重定向回管理页面
        $this->response->redirect(Typecho_Common::url('extending.php?panel=ArticleMarkdownBackup/manage.php', $this->widget('Widget_Options')->adminUrl));
    }
    
    /**
     * 将选中的HTML文章转换为Markdown格式
     */
    public function convertSelected()
    {
        try {
            // 引入MarkdownConverter类
            if (!class_exists('MarkdownConverter')) {
                require_once __DIR__ . '/MarkdownConverter.php';
            }
            
            // 获取选中的文章ID - 支持两种提交方式
            $cids = $this->request->get('cids');
            $cidArray = [];
            
            // 检查是否通过表单数组提交
            $formCids = $this->request->getArray('cid');
            if (!empty($formCids)) {
                $cidArray = array_map('intval', $formCids);
            }
            // 如果没有通过表单提交，则尝试从URL参数获取
            else if (!empty($cids)) {
                $cidArray = explode(',', $cids);
                $cidArray = array_map('intval', $cidArray);
            }
            
            // 检查是否有选中的文章
            if (empty($cidArray)) {
                throw new Exception('未选择任何文章');
            }
            
            // 获取数据库实例
            $db = Typecho_Db::get();
            
            // 获取选中的文章
            $articles = [];
            foreach ($cidArray as $cid) {
                $article = $db->fetchRow($db->select('cid', 'title', 'text')->from('table.contents')->where('cid = ?', $cid)->where('type = ?', 'post'));
                // 检查文章内容是否包含HTML标签
                if (!$article) {
                    continue;
                }
                $htmlContent = $article['text'];
                // 如果内容以<!--markdown-->开头，移除这个标记以获取原始HTML内容
                if (strpos($htmlContent, '<!--markdown-->') === 0) {
                    $htmlContent = substr($htmlContent, 15); // 移除<!--markdown-->标记
                }
                // 转换HTML到Markdown
                $markdown = new MarkdownConverter($htmlContent);
                $markdownContent = $markdown->convert();
                // 添加Markdown格式标记
                $markdownText = '<!--markdown-->' . $markdownContent;
                // 更新数据库，只更新text字段
                try {
                    $db->query($db->update('table.contents')
                        ->rows(['text' => $markdownText])
                        ->where('cid = ?', $article['cid']));
                } catch (Exception $updateError) {
                    error_log('ArticleMarkdownBackup convertSelected update error: ' . $updateError->getMessage() . ' for cid: ' . $article['cid']);
                    continue;
                }
            }
            // 设置成功消息
            $this->widget('Widget_Notice')->set(_t('成功将 %d 篇选中的文章转换为Markdown格式', count($cidArray)), 'success');
        } catch (Exception $e) {
            // 记录错误日志
            error_log('ArticleMarkdownBackup convertSelected error: ' . $e->getMessage());
            // 设置错误消息
            $this->widget('Widget_Notice')->set(_t('转换失败: %s', $e->getMessage()), 'error');
        }
        // 重定向回管理页面
        $this->response->redirect(Typecho_Common::url('extending.php?panel=ArticleMarkdownBackup/manage.php', $this->widget('Widget_Options')->adminUrl));
    }
    
    /**
     * 动作分发函数
     */
    public function action()
    {
        $this->execute();
        
        $do = $this->request->get('do');
        
        switch ($do) {
            case 'backupAll':
                $this->backupAll();
                break;
            case 'backupSelected':
                $this->backupSelected();
                break;
            case 'uploadBackup':
                $this->uploadBackup();
                break;
            case 'restore':
                $this->restore();
                break;
            case 'convertAll':
                $this->convertAll();
                break;
            case 'convertSelected':
                $this->convertSelected();
                break;
            default:
                $this->response->redirect($this->widget('Widget_Options')->adminUrl);
                break;
        }
    }
}
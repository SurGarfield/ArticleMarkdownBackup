<?php
class ArticleMarkdownBackup_Action extends Typecho_Widget implements Widget\ActionInterface
{
    /**
     * 记录日志到插件目录 logs/cid-sync.log
     */
    private function logLine($message)
    {
        try {
            $dir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $file = $dir . DIRECTORY_SEPARATOR . 'cid-sync.log';
            $date = date('Y-m-d H:i:s');
            @file_put_contents($file, "[{$date}] " . $message . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $t) {
            // ignore
        }
    }

	/**
	 * 获取数据库驱动: mysql/pgsql/sqlite
	 */
	private function getDbDriver($db)
	{
		try {
			$adapter = $db->getAdapter();
			$driver = method_exists($adapter, 'getDriver') ? $adapter->getDriver() : '';
			if (stripos($driver, 'pgsql') !== false) {
				return 'pgsql';
			}
			if (stripos($driver, 'sqlite') !== false) {
				return 'sqlite';
			}
			return 'mysql';
		} catch (Exception $e) {
			return 'mysql';
		}
	}

	/**
	 * 获取指定表现有列名（小写）
	 */
	private function getTableColumns($db, $table)
	{
		$columns = [];
		$driver = $this->getDbDriver($db);
		try {
			if ($driver === 'mysql') {
				$rows = $db->fetchAll("SHOW COLUMNS FROM `{$table}`");
				foreach ($rows as $r) {
					if (isset($r['Field'])) {
						$columns[strtolower($r['Field'])] = true;
					}
				}
			} elseif ($driver === 'sqlite') {
				$rows = $db->fetchAll("PRAGMA table_info({$table})");
				foreach ($rows as $r) {
					if (isset($r['name'])) {
						$columns[strtolower($r['name'])] = true;
					}
				}
			} else { // pgsql
				// 尽量不指定 schema，默认搜索路径处理
				$rows = $db->fetchAll("SELECT column_name FROM information_schema.columns WHERE table_name = '{$table}'");
				foreach ($rows as $r) {
					if (isset($r['column_name'])) {
						$columns[strtolower($r['column_name'])] = true;
					}
				}
			}
		} catch (Exception $e) {
			// ignore, 返回已收集的
		}
		return $columns;
	}

	/**
	 * 基于备份数据推断列类型
	 */
	private function inferColumnTypeFromValues($driver, $values)
	{
		$sample = null;
		foreach ($values as $v) {
			if ($v === null) { continue; }
			$sample = $v; break;
		}
		if (is_bool($sample)) {
			return $driver === 'mysql' ? 'TINYINT(1)' : ($driver === 'pgsql' ? 'SMALLINT' : 'INTEGER');
		}
		if (is_int($sample) || (is_string($sample) && preg_match('/^-?\\d+$/', $sample))) {
			return $driver === 'mysql' ? 'INT' : 'INTEGER';
		}
		if (is_float($sample) || (is_string($sample) && is_numeric($sample))) {
			return $driver === 'mysql' ? 'DOUBLE' : ($driver === 'pgsql' ? 'DOUBLE PRECISION' : 'REAL');
		}
		// 默认文本，避免长度截断
		return 'TEXT';
	}

	/**
	 * 确保表包含备份数据中的所有列（若不存在则新增）
	 * @param string $base 表基名，如 contents/comments（不含前缀）
	 * @param array $rows  备份记录数组
	 */
	private function ensureColumnsFromData($db, $base, array $rows)
	{
		if (empty($rows)) { return; }
		$prefix = $db->getPrefix();
		$table = $prefix . $base;
		$driver = $this->getDbDriver($db);
		$existing = $this->getTableColumns($db, $table);

		// 聚合候选列
		$candidateKeys = [];
		$valuesPerKey = [];
		$limit = 100; // 采样最多100条
		$count = 0;
		foreach ($rows as $r) {
			if (!is_array($r)) { continue; }
			foreach ($r as $k => $v) {
				$lk = strtolower($k);
				$candidateKeys[$lk] = $k; // 保留原始大小写名
				if (!isset($valuesPerKey[$lk])) { $valuesPerKey[$lk] = []; }
				$valuesPerKey[$lk][] = $v;
			}
			$count++;
			if ($count >= $limit) break;
		}

		// 不处理主键列，避免冲突
		if ($base === 'contents') {
			unset($candidateKeys['cid']);
		} elseif ($base === 'comments') {
			unset($candidateKeys['coid']);
		}

		// 逐列新增缺失字段
		foreach ($candidateKeys as $lk => $origName) {
			if (isset($existing[$lk])) { continue; }
			$type = $this->inferColumnTypeFromValues($driver, isset($valuesPerKey[$lk]) ? $valuesPerKey[$lk] : []);
			try {
				if ($driver === 'mysql') {
					$sql = "ALTER TABLE `{$table}` ADD COLUMN `{$origName}` {$type} NULL";
				} else {
					$sql = "ALTER TABLE {$table} ADD COLUMN {$origName} {$type}";
				}
				$db->query($sql);
			} catch (Exception $e) {
				// 忽略新增失败，继续后续字段
			}
		}
	}
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

            // 恢复前，针对极端环境：确保表包含备份中的字段
            try {
                if (isset($backupData['articles']) && is_array($backupData['articles'])) {
                    $this->ensureColumnsFromData($db, 'contents', $backupData['articles']);
                }
                if (isset($backupData['comments']) && is_array($backupData['comments'])) {
                    $this->ensureColumnsFromData($db, 'comments', $backupData['comments']);
                }
            } catch (Exception $e) {
                // 忽略结构兼容异常，不阻断恢复
            }
            
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
     * 删除勾选的附件
     */
    public function deleteSelectedAttachments()
    {
        try {
            $cids = $this->request->getArray('cid');
            if (empty($cids)) {
                throw new Exception('未选择任何附件');
            }
            $db = Typecho_Db::get();
            $ids = array_map('intval', $cids);
            foreach ($ids as $id) {
                $db->query($db->delete('table.contents')->where('cid = ? AND type = ?', $id, 'attachment'));
            }
            $this->widget('Widget_Notice')->set(_t('已删除 %d 个附件', count($ids)), 'success');
        } catch (Exception $e) {
            error_log('ArticleMarkdownBackup deleteSelectedAttachments error: ' . $e->getMessage());
            $this->widget('Widget_Notice')->set(_t('删除失败: %s', $e->getMessage()), 'error');
        }
        $this->response->redirect(Typecho_Common::url('extending.php?panel=ArticleMarkdownBackup/manage.php&tab=cid', $this->widget('Widget_Options')->adminUrl));
    }
    
    /**
     * 设置 contents 表自增ID/序列
     */
    public function setAutoIncrement()
    {
        try {
            $value = intval($this->request->get('value'));
            if ($value <= 0) {
                throw new Exception('无效的自增值');
            }

            $db = Typecho_Db::get();
            $adapter = $db->getAdapter();
            $driver = method_exists($adapter, 'getDriver') ? $adapter->getDriver() : '';
            $prefix = $db->getPrefix();

            if (stripos($driver, 'pgsql') !== false) {
                $seq = $prefix . 'contents_seq';
                $db->query("SELECT setval('{$seq}', {$value}, true)");
            } elseif (stripos($driver, 'sqlite') !== false) {
                $table = $prefix . 'contents';
                // sqlite_sequence 可能不存在
                @$db->query("UPDATE sqlite_sequence SET seq = {$value} WHERE name = '{$table}'");
            } else {
                $table = $prefix . 'contents';
                $db->query("ALTER TABLE `{$table}` AUTO_INCREMENT = {$value}");
            }

            $this->widget('Widget_Notice')->set(_t('已设置自增ID为 %d', $value), 'success');
        } catch (Exception $e) {
            error_log('ArticleMarkdownBackup setAutoIncrement error: ' . $e->getMessage());
            $this->widget('Widget_Notice')->set(_t('设置失败: %s', $e->getMessage()), 'error');
        }

        $this->response->redirect(Typecho_Common::url('extending.php?panel=ArticleMarkdownBackup/manage.php&tab=cid', $this->widget('Widget_Options')->adminUrl));
    }

    /**
     * 一键删除所有附件
     */
    public function deleteAllAttachments()
    {
        try {
            $db = Typecho_Db::get();
            $countObj = $db->fetchObject($db->select(['COUNT(cid)' => 'num'])->from('table.contents')->where('type = ?', 'attachment'));
            $db->query($db->delete('table.contents')->where('type = ?', 'attachment'));
            $count = $countObj ? (int)$countObj->num : 0;
            $this->widget('Widget_Notice')->set(_t('已删除 %d 个附件', $count), 'success');
        } catch (Exception $e) {
            error_log('ArticleMarkdownBackup deleteAllAttachments error: ' . $e->getMessage());
            $this->widget('Widget_Notice')->set(_t('删除失败: %s', $e->getMessage()), 'error');
        }

        $this->response->redirect(Typecho_Common::url('extending.php?panel=ArticleMarkdownBackup/manage.php&tab=cid', $this->widget('Widget_Options')->adminUrl));
    }
    
    /**
     * 动作分发函数
     */
    public function action()
    {
        $this->execute();
        
        $do = $this->request->get('do');
        
        switch ($do) {
			case 'restoreInit':
				$this->restoreInitAjax();
				break;
			case 'restoreStep':
				$this->restoreStepAjax();
				break;
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
            case 'setAutoIncrement':
                $this->setAutoIncrement();
                break;
            case 'deleteAllAttachments':
                $this->deleteAllAttachments();
                break;
            case 'deleteSelectedAttachments':
                $this->deleteSelectedAttachments();
                break;
            case 'reorderCids':
                $this->reorderCids();
                break;
            case 'setStrategy':
                $this->setStrategy();
                break;
            case 'clearLog':
                $this->clearLogAction();
                break;
            default:
                $this->response->redirect($this->widget('Widget_Options')->adminUrl);
                break;
        }
    }

	/**
	 * 生成并返回一个JSON响应后立即退出
	 */
	private function jsonResponse($data)
	{
		@header('Content-Type: application/json; charset=utf-8');
		echo json_encode($data, JSON_UNESCAPED_UNICODE);
		exit;
	}

	/**
	 * 计算还原进度状态文件路径
	 */
	private function getRestoreStatePath($jobId)
	{
		$stateDir = __DIR__ . DIRECTORY_SEPARATOR . 'backups';
		if (!is_dir($stateDir)) {
			@mkdir($stateDir, 0755, true);
		}
		return $stateDir . DIRECTORY_SEPARATOR . ('restore_state_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $jobId) . '.json');
	}

	/**
	 * 恢复初始化（AJAX）：返回任务ID与总数
	 */
	public function restoreInitAjax()
	{
		try {
			$backupFile = $this->request->get('backup_file');
			if (!empty($backupFile)) {
				$filepath = __DIR__ . '/backups/' . $backupFile;
				if (!file_exists($filepath)) {
					return $this->jsonResponse(['success' => false, 'message' => '指定的备份文件不存在']);
				}
			} else {
				$backupDir = __DIR__ . '/backups/';
				if (!is_dir($backupDir)) {
					return $this->jsonResponse(['success' => false, 'message' => '备份目录不存在']);
				}
				$backupFiles = glob($backupDir . 'AMD_backup_*.json');
				if (empty($backupFiles)) {
					return $this->jsonResponse(['success' => false, 'message' => '未找到备份文件']);
				}
				usort($backupFiles, function($a, $b) { return filemtime($b) - filemtime($a); });
				$filepath = $backupFiles[0];
			}

			$content = @file_get_contents($filepath);
			$data = $content !== false ? json_decode($content, true) : null;
			if (!$data || !isset($data['articles']) || !isset($data['comments'])) {
				return $this->jsonResponse(['success' => false, 'message' => '备份文件格式错误']);
			}

			$articlesTotal = is_array($data['articles']) ? count($data['articles']) : 0;
			$commentsTotal = is_array($data['comments']) ? count($data['comments']) : 0;
			$total = $articlesTotal + $commentsTotal;
			$jobId = bin2hex(random_bytes(6));
			$state = [
				'filepath' => $filepath,
				'articlesTotal' => $articlesTotal,
				'commentsTotal' => $commentsTotal,
				'aIndex' => 0,
				'cIndex' => 0,
				'ensureDone' => false,
				'createdAt' => time()
			];
			@file_put_contents($this->getRestoreStatePath($jobId), json_encode($state));

			return $this->jsonResponse([
				'success' => true,
				'jobId' => $jobId,
				'articlesTotal' => $articlesTotal,
				'commentsTotal' => $commentsTotal,
				'total' => $total
			]);
		} catch (Exception $e) {
			return $this->jsonResponse(['success' => false, 'message' => '初始化失败: ' . $e->getMessage()]);
		}
	}

	/**
	 * 执行一步恢复（AJAX）：按批处理并返回当前进度
	 */
	public function restoreStepAjax()
	{
		@set_time_limit(0);
		$jobId = $this->request->get('jobId');
		$batchSize = intval($this->request->get('batchSize'));
		if ($batchSize <= 0) { $batchSize = 200; }
		$statePath = $this->getRestoreStatePath($jobId);
		if (!$jobId || !is_file($statePath)) {
			return $this->jsonResponse(['success' => false, 'message' => '任务不存在或已过期']);
		}

		$state = json_decode(@file_get_contents($statePath), true);
		if (!is_array($state) || empty($state['filepath'])) {
			return $this->jsonResponse(['success' => false, 'message' => '任务状态损坏']);
		}

		$content = @file_get_contents($state['filepath']);
		$data = $content !== false ? json_decode($content, true) : null;
		if (!$data || !isset($data['articles']) || !isset($data['comments'])) {
			return $this->jsonResponse(['success' => false, 'message' => '备份文件无法读取']);
		}

		$articles = is_array($data['articles']) ? $data['articles'] : [];
		$comments = is_array($data['comments']) ? $data['comments'] : [];
		$articlesTotal = intval($state['articlesTotal']);
		$commentsTotal = intval($state['commentsTotal']);
		$aIndex = intval($state['aIndex']);
		$cIndex = intval($state['cIndex']);

		$db = Typecho_Db::get();
		$adapter = $db->getAdapter();
		$driver = method_exists($adapter, 'getDriver') ? $adapter->getDriver() : '';

		// 首步确保字段
		if (empty($state['ensureDone'])) {
			try {
				if ($articlesTotal > 0) { $this->ensureColumnsFromData($db, 'contents', $articles); }
				if ($commentsTotal > 0) { $this->ensureColumnsFromData($db, 'comments', $comments); }
				$state['ensureDone'] = true;
				@file_put_contents($statePath, json_encode($state));
			} catch (Exception $e) {
				// 忽略结构异常
			}
		}

		$processed = 0;
		try {
			// 可选关闭外键检查以提升性能
			if (stripos($driver, 'mysql') !== false) {
				try { $db->query('SET FOREIGN_KEY_CHECKS=0'); } catch (Exception $e) {}
			}
			// 开启事务
			try { $db->query('START TRANSACTION'); } catch (Exception $e) {}

			// 先处理文章，再处理评论
			while ($processed < $batchSize && $aIndex < $articlesTotal) {
				$article = $articles[$aIndex];
				$existing = $db->fetchRow($db->select()->from('table.contents')->where('cid = ?', $article['cid']));
				if ($existing) {
					$updateData = $article; unset($updateData['cid']);
					$db->query($db->update('table.contents')->rows($updateData)->where('cid = ?', $article['cid']));
				} else {
					$db->query($db->insert('table.contents')->rows($article));
				}
				$aIndex++; $processed++;
			}
			while ($processed < $batchSize && $cIndex < $commentsTotal) {
				$comment = $comments[$cIndex];
				$existing = $db->fetchRow($db->select()->from('table.comments')->where('coid = ?', $comment['coid']));
				if ($existing) {
					$updateData = $comment; unset($updateData['coid']);
					$db->query($db->update('table.comments')->rows($updateData)->where('coid = ?', $comment['coid']));
				} else {
					$db->query($db->insert('table.comments')->rows($comment));
				}
				$cIndex++; $processed++;
			}

			try { $db->query('COMMIT'); } catch (Exception $e) {}
		} catch (Exception $e) {
			try { $db->query('ROLLBACK'); } catch (Exception $e2) {}
			return $this->jsonResponse(['success' => false, 'message' => '执行失败: ' . $e->getMessage()]);
		}

		$state['aIndex'] = $aIndex;
		$state['cIndex'] = $cIndex;
		@file_put_contents($statePath, json_encode($state));

		$done = ($aIndex >= $articlesTotal) && ($cIndex >= $commentsTotal);
		$completed = $aIndex + $cIndex;
		$total = $articlesTotal + $commentsTotal;
		$percent = $total > 0 ? floor($completed * 100 / $total) : 100;

		if ($done) { @unlink($statePath); }

		return $this->jsonResponse([
			'success' => true,
			'done' => $done,
			'processed' => $completed,
			'total' => $total,
			'percent' => $percent,
			'articles' => ['done' => $aIndex, 'total' => $articlesTotal],
			'comments' => ['done' => $cIndex, 'total' => $commentsTotal]
		]);
	}

    /**
     * 清空插件日志动作
     */
    private function clearLogAction()
    {
        try {
            $logFile = __DIR__ . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'cid-sync.log';
            if (file_exists($logFile)) {
                @file_put_contents($logFile, '');
            }
            $this->widget('Widget_Notice')->set(_t('日志已清空'), 'success');
        } catch (Exception $e) {
            error_log('ArticleMarkdownBackup clearLog error: ' . $e->getMessage());
            $this->widget('Widget_Notice')->set(_t('清空失败: %s', $e->getMessage()), 'error');
        }
        $this->response->redirect(Typecho_Common::url('extending.php?panel=ArticleMarkdownBackup/manage.php&tab=cid', $this->widget('Widget_Options')->adminUrl));
    }

    /**
     * 设置 CID 连贯策略（集成在 CID 页面）
     */
    private function setStrategy()
    {
        try {
            $allow = ['skip', 'ignore', 'grow_skip', 'grow_ignore'];
            $strategy = $this->request->get('cidStrategy');
            if (!in_array($strategy, $allow, true)) {
                throw new Exception('无效的策略');
            }

            $db = Typecho_Db::get();
            $name = 'plugin:ArticleMarkdownBackup';
            $row = $db->fetchRow($db->select('value')->from('table.options')->where('name = ?', $name)->where('user = ?', 0));
            $value = ['cidStrategy' => $strategy];
            if ($row && !empty($row['value'])) {
                $decoded = json_decode($row['value'], true);
                if (is_array($decoded)) {
                    $decoded['cidStrategy'] = $strategy;
                    $value = $decoded;
                }
            }

            if ($row) {
                $db->query($db->update('table.options')->rows(['value' => json_encode($value)])->where('name = ?', $name)->where('user = ?', 0));
            } else {
                $db->query($db->insert('table.options')->rows(['name' => $name, 'user' => 0, 'value' => json_encode($value)]));
            }

            $this->widget('Widget_Notice')->set(_t('策略已更新为 %s', $strategy), 'success');
        } catch (Exception $e) {
            error_log('ArticleMarkdownBackup setStrategy error: ' . $e->getMessage());
            $this->widget('Widget_Notice')->set(_t('保存失败: %s', $e->getMessage()), 'error');
        }

        $this->response->redirect(Typecho_Common::url('extending.php?panel=ArticleMarkdownBackup/manage.php&tab=cid', $this->widget('Widget_Options')->adminUrl));
    }

    /**
     * 重排全站内容CID：
     * - 先备份
     * - 删除所有附件释放CID
     * - 将非附件内容按旧CID升序映射为从1开始的连续新CID
     * - 更新 comments/fields/relationships/parent 引用
     * - 使用“偏移两阶段”方式避免主键冲突
     * - 重置自增
     */
    public function reorderCids()
    {
        try {
            // 必须开启策略管理才允许执行
            try {
                $opt = Typecho_Widget::widget('Widget_Options')->plugin('ArticleMarkdownBackup');
                $enabled = isset($opt->enableStrategy) && (string)$opt->enableStrategy === '1';
                if (!$enabled) {
                    $this->logLine('重排被拒绝：策略管理未开启');
                    $this->widget('Widget_Notice')->set(_t('请先在“策略管理”中开启策略后再执行重排'), 'error');
                    $this->response->redirect(Typecho_Common::url('options-plugin.php?config=ArticleMarkdownBackup', $this->widget('Widget_Options')->adminUrl));
                    return;
                }
            } catch (Exception $e) {
                $this->logLine('重排前检查异常：' . $e->getMessage());
                $this->widget('Widget_Notice')->set(_t('无法确认策略开关状态，已取消执行'), 'error');
                $this->response->redirect(Typecho_Common::url('options-plugin.php?config=ArticleMarkdownBackup', $this->widget('Widget_Options')->adminUrl));
                return;
            }

            @set_time_limit(0);
            $this->logLine('重排开始');

            // 先做一次备份
            try {
                $this->backupAll(true);
                $this->logLine('已完成自动备份');
            } catch (Exception $e) {
                $this->logLine('自动备份失败: ' . $e->getMessage());
            }

            $db = Typecho_Db::get();
            $adapter = $db->getAdapter();
            $driver = method_exists($adapter, 'getDriver') ? $adapter->getDriver() : '';
            $prefix = $db->getPrefix();

            // 开启事务
            try {
                if (stripos($driver, 'pgsql') !== false) {
                    $db->query('BEGIN');
                } else {
                    $db->query('START TRANSACTION');
                }
            } catch (Exception $e) {
                // 某些环境不支持显式事务命令，忽略
            }

            // 收集并删除所有附件，顺带清理其附属引用
            $attachmentRows = $db->fetchAll(
                $db->select('cid')->from('table.contents')->where('type = ?', 'attachment')
            );
            $attachmentCids = [];
            foreach ($attachmentRows as $ar) {
                $attachmentCids[] = (int)$ar['cid'];
            }
            $deletedAtt = 0;
            if (!empty($attachmentCids)) {
                foreach ($attachmentCids as $attCid) {
                    // 删除与附件相关的 comments/fields/relationships（通常很少，但为安全清理）
                    $db->query($db->delete('table.comments')->where('cid = ?', $attCid));
                    $db->query($db->delete('table.fields')->where('cid = ?', $attCid));
                    $db->query($db->delete('table.relationships')->where('cid = ?', $attCid));
                    // 删除附件本身
                    $db->query($db->delete('table.contents')->where('cid = ? AND type = ?', $attCid, 'attachment'));
                    $deletedAtt++;
                }
                $this->logLine('已删除附件数量: ' . $deletedAtt);
            }

            // 读取非附件内容，按旧CID升序
            $rows = $db->fetchAll(
                $db->select('cid', 'type', 'parent')->from('table.contents')->where('type <> ?', 'attachment')->order('cid', Typecho_Db::SORT_ASC)
            );
            if (empty($rows)) {
                $this->logLine('无非附件内容，无需重排');
                try {
                    if (stripos($driver, 'pgsql') !== false) { $db->query('COMMIT'); } else { $db->query('COMMIT'); }
                } catch (Exception $e) {}
                $this->widget('Widget_Notice')->set(_t('无内容需要重排'), 'success');
                $this->response->redirect(Typecho_Common::url('options-plugin.php?config=ArticleMarkdownBackup', $this->widget('Widget_Options')->adminUrl));
                return;
            }

            // 构建映射 oldCid => newCid
            $mapping = [];
            $new = 1;
            foreach ($rows as $r) {
                $old = (int)$r['cid'];
                $mapping[$old] = $new;
                $new++;
            }

            // 预计算偏移，避免主键冲突
            $maxObj = $db->fetchObject($db->select(['MAX(cid)' => 'maxcid'])->from('table.contents'));
            $maxCid = $maxObj && isset($maxObj->maxcid) ? (int)$maxObj->maxcid : 0;
            $offset = $maxCid + 100000;

            // 先更新引用表到“最终新CID”
            foreach ($mapping as $oldCid => $newCid) {
                if ($oldCid === $newCid) {
                    // 即使相同也统一更新，保证一致性
                }
                $db->query($db->update('table.comments')->rows(['cid' => $newCid])->where('cid = ?', $oldCid));
                $db->query($db->update('table.fields')->rows(['cid' => $newCid])->where('cid = ?', $oldCid));
                $db->query($db->update('table.relationships')->rows(['cid' => $newCid])->where('cid = ?', $oldCid));
            }

            // 更新 contents.parent 到“最终新CID”
            foreach ($mapping as $oldCid => $newCid) {
                $db->query($db->update('table.contents')->rows(['parent' => $newCid])->where('parent = ?', $oldCid));
            }

            // 第一阶段：将内容CID写入临时不冲突区间（newCid + offset）
            foreach ($mapping as $oldCid => $newCid) {
                $tmpCid = $newCid + $offset;
                $db->query($db->update('table.contents')->rows(['cid' => $tmpCid])->where('cid = ?', $oldCid));
            }

            // 第二阶段：将临时区间的CID回写为最终新CID
            foreach ($mapping as $oldCid => $newCid) {
                $tmpCid = $newCid + $offset;
                $db->query($db->update('table.contents')->rows(['cid' => $newCid])->where('cid = ?', $tmpCid));
            }

            // 设置自增/序列为下一个可用值
            $next = count($mapping) + 1;
            try {
                if (stripos($driver, 'pgsql') !== false) {
                    $seq = $prefix . 'contents_seq';
                    $db->query("SELECT setval('{$seq}', {$next}, true)");
                } elseif (stripos($driver, 'sqlite') !== false) {
                    $table = $prefix . 'contents';
                    @$db->query("UPDATE sqlite_sequence SET seq = {$next} WHERE name = '{$table}'");
                } else {
                    $table = $prefix . 'contents';
                    $db->query("ALTER TABLE `{$table}` AUTO_INCREMENT = {$next}");
                }
            } catch (Exception $e) {
                $this->logLine('设置自增失败: ' . $e->getMessage());
            }

            // 提交事务
            try {
                $db->query('COMMIT');
            } catch (Exception $e) {}

            $this->logLine(sprintf('重排完成：非附件内容 %d 条，删除附件 %d 个，下一个CID=%d', count($mapping), $deletedAtt, $next));
            $this->widget('Widget_Notice')->set(_t('重排完成：内容 %d 条，删除附件 %d 个', count($mapping), $deletedAtt), 'success');
        } catch (Exception $e) {
            // 回滚
            try { $this->logLine('重排异常: ' . $e->getMessage()); $db = Typecho_Db::get(); $db->query('ROLLBACK'); } catch (Exception $e2) {}
            $this->widget('Widget_Notice')->set(_t('重排失败: %s', $e->getMessage()), 'error');
        }

        // 返回到插件配置页
        $this->response->redirect(Typecho_Common::url('options-plugin.php?config=ArticleMarkdownBackup', $this->widget('Widget_Options')->adminUrl));
    }
}
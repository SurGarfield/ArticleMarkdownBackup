<?php
/**
 * 文章备份与Markdown转换插件，增加CID策略管理
 * 
 * @package Article Markdown Backup
 * @author 森木志
 * @version 1.2.3
 * @link https://oxxx.cn
 */
class ArticleMarkdownBackup_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件
     */
    public static function activate()
    {
        
        Helper::addAction('article_markdown_backup', 'ArticleMarkdownBackup_Action');
        
        
        Helper::addPanel(1, 'ArticleMarkdownBackup/manage.php', _t('文章备份与转换'), _t('文章备份与转换'), 'administrator');
        
        Helper::addPanel(1, 'ArticleMarkdownBackup/manage_cid.php', _t('CID策略管理'), _t('CID策略管理'), 'administrator');

        
        try {
            $db = Typecho_Db::get();
            $exists = $db->fetchRow(
                $db->select('name')->from('table.options')->where('name = ?', 'plugin:ArticleMarkdownBackup')->where('user = ?', 0)
            );
            if (!$exists) {
                $db->query($db->insert('table.options')->rows([
                    'name' => 'plugin:ArticleMarkdownBackup',
                    'user' => 0,
                    'value' => json_encode(['cidStrategy' => 'ignore', 'enableStrategy' => '0'])
                ]));
            }
        } catch (Exception $e) {
            
        }

        
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->write = array('ArticleMarkdownBackup_Plugin', 'filterWriteAssignCid');
        Typecho_Plugin::factory('Widget_Contents_Page_Edit')->write = array('ArticleMarkdownBackup_Plugin', 'filterWriteAssignCid');

        
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishSave = array('ArticleMarkdownBackup_Plugin', 'onFinish');
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('ArticleMarkdownBackup_Plugin', 'onFinish');
        Typecho_Plugin::factory('Widget_Contents_Page_Edit')->finishSave = array('ArticleMarkdownBackup_Plugin', 'onFinish');
        Typecho_Plugin::factory('Widget_Contents_Page_Edit')->finishPublish = array('ArticleMarkdownBackup_Plugin', 'onFinish');
        
        return _t('文章备份与转换插件已激活');
    }

    /**
     * 禁用插件
     */
    public static function deactivate()
    {
        
        Helper::removeAction('article_markdown_backup');
        
        
        Helper::removePanel(1, 'ArticleMarkdownBackup/manage.php');
        Helper::removePanel(1, 'ArticleMarkdownBackup/manage_cid.php');
        
        return _t('文章备份与转换插件已禁用');
    }

    /**
     * 插件配置面板
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        
        try {
            $db = Typecho_Db::get();
            $exists = $db->fetchRow(
                $db->select('name')->from('table.options')->where('name = ?', 'plugin:ArticleMarkdownBackup')->where('user = ?', 0)
            );
            if (!$exists) {
                $db->query($db->insert('table.options')->rows([
                    'name' => 'plugin:ArticleMarkdownBackup',
                    'user' => 0,
                    'value' => json_encode(['cidStrategy' => 'ignore', 'enableStrategy' => '0'])
                ]));
            }
        } catch (Exception $e) {
            
        }

        // 策略管理开关（默认关闭）
        $enableStrategy = new Typecho_Widget_Helper_Form_Element_Radio(
            'enableStrategy',
            [
                '0' => _t('关闭'),
                '1' => _t('开启')
            ],
            '0',
            _t('策略管理'),
            _t('启用后，将在新建与发布内容时应用CID策略与同步逻辑')
        );
        $form->addInput($enableStrategy);

        
        $cidStrategyDefault = 'ignore';
        try {
            $opt = Typecho_Widget::widget('Widget_Options')->plugin('ArticleMarkdownBackup');
            if (isset($opt->cidStrategy)) {
                $cidStrategyDefault = (string)$opt->cidStrategy;
            }
        } catch (Exception $e) {}
        
        if (class_exists('Typecho_Widget_Helper_Form_Element_Hidden')) {
            $cidStrategyHidden = new Typecho_Widget_Helper_Form_Element_Hidden('cidStrategy', null, $cidStrategyDefault, _t('CID 连贯策略'));
        } else {
            
            $cidStrategyHidden = new Typecho_Widget_Helper_Form_Element_Text('cidStrategy', null, $cidStrategyDefault, _t('CID 连贯策略'));
            $cidStrategyHidden->input->setAttribute('type', 'hidden');
        }
        $form->addInput($cidStrategyHidden);

        
        echo '<p class="description">' . _t('策略修改请前往“CID 连贯管理”页操作。') . '</p>';

        
        echo '<style>#typecho-option-item-enableStrategy label, #typecho-option-item-cidStrategy label { display:block; margin-bottom:6px; }</style>';

        
        $actionUrl = Typecho_Common::url('/index.php/action/article_markdown_backup?do=reorderCids', Helper::options()->siteUrl);
        try {
            $securityWidget = Typecho_Widget::widget('Widget_Security');
            if ($securityWidget && method_exists($securityWidget, 'index')) {
                
                ob_start();
                $ret = $securityWidget->index('/action/article_markdown_backup?do=reorderCids');
                $buf = ob_get_clean();
                if (is_string($ret) && $ret !== '') {
                    $actionUrl = $ret;
                } elseif (is_string($buf) && $buf !== '') {
                    $actionUrl = $buf;
                }
            }
        } catch (Exception $e) {
            
        }
        
        $enabledStrategyFlag = '0';
        try {
            $optObj = Typecho_Widget::widget('Widget_Options')->plugin('ArticleMarkdownBackup');
            if (isset($optObj->enableStrategy)) {
                $enabledStrategyFlag = (string)$optObj->enableStrategy;
            }
        } catch (Exception $e) {}
        $disabledAttr = ($enabledStrategyFlag === '1') ? '' : ' disabled';

        echo '<div class="typecho-page-options widget" style="margin-top:12px;">';
        echo '<h3>' . _t('风险策略') . '</h3>';
        echo '<ul class="typecho-option"><li>';
        echo '<form method="post" action="' . htmlspecialchars($actionUrl) . '" onsubmit="return window.confirm(\'确认执行重排Cid策略？该操作将删除所有附件并重排所有内容CID，且不可逆！请确保已备份。\');" style="display:inline;">';
        echo '<button type="submit" class="btn btn-s danger' . ($disabledAttr ? ' btn-disabled' : '') . '"' . $disabledAttr . '>' . _t('重排Cid策略') . '</button>';
        echo '</form>';
        echo '<p class="description" style="margin-top:8px;color:#b00;">' . _t('将删除全部附件并为所有文章/页面重排连续的CID，可能会导致文章链接不是原来的内容等问题，请您知悉本操作的功能并知悉功能带来的后果后再使用。强烈建议先备份。') . '</p>';
        if ($disabledAttr) {
            echo '<p class="description" style="color:#c00;">' . _t('当前“策略管理”未开启，开启后方可执行重排。') . '</p>';
        }
        echo '</li></ul>';
        echo '</div>';
        
    }

    /**
     * 个人用户配置面板
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
        
    }
    
    /**
     * 插件实现方法
     */
    public static function render()
    {
        
    }

    /**
     * 写入过滤器: 为新内容分配连续的CID
     * @param array $contents
     * @param mixed $widget
     * @return array
     */
    public static function filterWriteAssignCid($contents, $widget)
    {
        try {
            
            if (!self::isStrategyEnabled()) {
                return $contents;
            }
            
            if (method_exists($widget, 'have') && $widget->have()) {
                return $contents;
            }

            
            $db = Typecho_Db::get();

            $strategy = self::getStrategy();

            
            if ($strategy === 'grow_skip' || $strategy === 'grow_ignore') {
                $candidate = max(1, self::getMaxValidCid($db) + 1);
            } else {
                $candidate = 1;
            }
            $bumped = 0;
            while (true) {
                $row = $db->fetchRow($db->select('cid', 'type')->from('table.contents')->where('cid = ?', $candidate));
                if (empty($row)) {
                    
                    break;
                }
                if (isset($row['type']) && $row['type'] === 'attachment') {
                    if ($strategy === 'ignore' || $strategy === 'grow_ignore') {
                        
                        $db->query($db->delete('table.contents')->where('cid = ?', $candidate));
                        self::log(sprintf('删除占用CID的附件: cid=%d', $candidate));
                        
                        try { Typecho_Widget::widget('Widget_Notice')->set(_t('删除附件占用，已分配CID %d', $candidate), 'success'); } catch (Exception $e) {}
                        break;
                    } else {
                        
                        $bumped++;
                        $candidate++;
                        continue;
                    }
                }
                
                $bumped++;
                $candidate++;
            }

            
            $contents['cid'] = $candidate;
            if ($bumped > 0) {
                self::log(sprintf('CID顺延: 递增%d次，最终cid=%d', $bumped, $candidate));
                try { Typecho_Widget::widget('Widget_Notice')->set(_t('CID已自动顺延至 %d', $candidate), 'notice'); } catch (Exception $e) {}
            } else {
                self::log(sprintf('分配连续CID: cid=%d', $candidate));
            }
        } catch (Exception $e) {
            self::log('filterWriteAssignCid 异常: ' . $e->getMessage());
        }

        return $contents;
    }

    /**
     * 写入/发布完成后: 对AUTO_INCREMENT进行纠正并提示
     * @param array $contents
     * @param mixed $widget
     */
    public static function onFinish($contents, $widget)
    {
        try {
            
            if (!self::isStrategyEnabled()) {
                return;
            }
            $db = Typecho_Db::get();
            $next = self::getRecommendedNextCid($db);

            
            $currentCid = isset($widget->cid) ? intval($widget->cid) : 0;
            $strategy = self::getStrategy();
            $isMinStrategy = ($strategy === 'skip' || $strategy === 'ignore');
            if ($isMinStrategy && $currentCid > 0 && $currentCid > $next) {
                $allowDeleteAttachment = ($strategy === 'ignore');
                $migratedTo = self::migrateContentCidIfPossible($db, $currentCid, $next, $allowDeleteAttachment);
                if ($migratedTo && $migratedTo != $currentCid) {
                    $next = self::getRecommendedNextCid($db);
                    try {
                        if (method_exists('Widget_Notice', 'alloc')) {
                            Widget_Notice::alloc()->set(_t('已自动同步CID %d → %d', $currentCid, $migratedTo), 'success');
                        } else {
                            Typecho_Widget::widget('Widget_Notice')->set(_t('已自动同步CID %d → %d', $currentCid, $migratedTo), 'success');
                        }
                    } catch (Exception $e) {}
                    self::log(sprintf('auto-sync: %d -> %d', $currentCid, $migratedTo));
                }
            }

            self::ensureAutoIncrement($db, $next);

            if (method_exists('Widget_Notice', 'alloc')) {
                Widget_Notice::alloc()->set(_t('建议下一个CID为 %d', $next), 'success');
            } elseif (class_exists('Widget_Notice')) {
                Typecho_Widget::widget('Widget_Notice')->set(_t('建议下一个CID为 %d', $next), 'success');
            }
            self::log(sprintf('onFinish: next(min-free)=%d', $next));
        } catch (Exception $e) {
            self::log('onFinish 异常: ' . $e->getMessage());
        }
    }

    /**
     
     */
    private static function getMaxValidCid($db)
    {
        $validTypes = ['post', 'post_draft', 'page', 'page_draft', 'revision'];
        $placeholders = implode(',', array_fill(0, count($validTypes), '?'));
        $select = $db->select(['MAX(cid)' => 'maxcid'])->from('table.contents')->where("type IN ($placeholders)", ...$validTypes);
        $obj = $db->fetchObject($select);
        return $obj && isset($obj->maxcid) && $obj->maxcid ? (int)$obj->maxcid : 0;
    }

    /**
     
     */
    private static function getRecommendedNextCid($db)
    {
        $strategy = self::getStrategy();
        $rows = $db->fetchAll($db->select('cid', 'type')->from('table.contents')->order('cid', Typecho_Db::SORT_ASC));
        $occupiedAll = [];
        $occupiedValid = [];
        $validTypes = ['post', 'post_draft', 'page', 'page_draft', 'revision'];
        $maxValid = 0;
        foreach ($rows as $r) {
            $cid = (int)$r['cid'];
            $occupiedAll[$cid] = true;
            if (in_array($r['type'], $validTypes, true)) {
                $occupiedValid[$cid] = true;
                if ($cid > $maxValid) $maxValid = $cid;
            }
        }

        // 起点
        $start = ($strategy === 'grow_skip' || $strategy === 'grow_ignore') ? max(1, $maxValid + 1) : 1;

        // 占用视角
        $useAll = ($strategy === 'skip' || $strategy === 'grow_skip');
        $occupied = $useAll ? $occupiedAll : $occupiedValid;

        $n = $start;
        while (isset($occupied[$n])) {
            $n++;
        }
        return $n;
    }

    /**
     
     */
    private static function getStrategy()
    {
        try {
            $options = Typecho_Widget::widget('Widget_Options')->plugin('ArticleMarkdownBackup');
            $strategy = isset($options->cidStrategy) ? (string)$options->cidStrategy : 'ignore';
            $allowed = ['skip','ignore','grow_skip','grow_ignore'];
            return in_array($strategy, $allowed, true) ? $strategy : 'ignore';
        } catch (Exception $e) {
            return 'ignore';
        }
    }

    /**
     
     */
    private static function isStrategyEnabled()
    {
        try {
            $options = Typecho_Widget::widget('Widget_Options')->plugin('ArticleMarkdownBackup');
            if (!isset($options->enableStrategy)) {
                return false;
            }
            return (string)$options->enableStrategy === '1';
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     
     */
    private static function migrateContentCidIfPossible($db, int $currentCid, int $targetStart, bool $allowDeleteAttachment)
    {
        try {
            $candidate = max(1, $targetStart);
            while ($candidate < $currentCid) {
                $row = $db->fetchRow($db->select('cid', 'type')->from('table.contents')->where('cid = ?', $candidate));
                if (empty($row)) {
                    break;
                }
                if ($row['type'] === 'attachment') {
                    if ($allowDeleteAttachment) {
                        $db->query($db->delete('table.contents')->where('cid = ?', $candidate));
                        self::log(sprintf('auto-sync clear attachment: cid=%d', $candidate));
                        break;
                    } else {
                        
                        $candidate++;
                        continue;
                    }
                }
                $candidate++;
            }

            if ($candidate >= $currentCid) {
                return $currentCid;
            }

            
            
            $db->query($db->update('table.comments')->rows(['cid' => $candidate])->where('cid = ?', $currentCid));
            // fields
            $db->query($db->update('table.fields')->rows(['cid' => $candidate])->where('cid = ?', $currentCid));
            // relationships
            $db->query($db->update('table.relationships')->rows(['cid' => $candidate])->where('cid = ?', $currentCid));
            // 附件parent
            $db->query($db->update('table.contents')->rows(['parent' => $candidate])->where('parent = ?', $currentCid));
            // 修订记录parent
            $db->query($db->update('table.contents')->rows(['parent' => $candidate])->where('parent = ? AND type = ?', $currentCid, 'revision'));

            // 最后更新内容主键cid
            $db->query($db->update('table.contents')->rows(['cid' => $candidate])->where('cid = ?', $currentCid));

            return $candidate;
        } catch (Exception $e) {
            self::log('migrateContentCidIfPossible 异常: ' . $e->getMessage());
            return $currentCid;
        }
    }

    /**
     * 确保AUTO_INCREMENT/序列不小于next
     */
    private static function ensureAutoIncrement($db, $next)
    {
        try {
            $adapter = $db->getAdapter();
            $driver = method_exists($adapter, 'getDriver') ? $adapter->getDriver() : '';
            $prefix = $db->getPrefix();
            if (stripos($driver, 'pgsql') !== false) {
                $seq = $prefix . 'contents_seq';
                // 设置为 next (或保持更大值)
                $db->query("SELECT setval('{$seq}', GREATEST((SELECT MAX(cid) FROM {$prefix}contents)+1, {$next}), true)");
            } elseif (stripos($driver, 'sqlite') !== false) {
                // sqlite_sequence 可能不存在, 仅在曾有自增后存在
                $table = $prefix . 'contents';
                $row = @$db->fetchRow($db->select('seq')->from('sqlite_sequence')->where('name = ?', $table));
                $current = isset($row['seq']) ? (int)$row['seq'] : 0;
                if ($current < $next) {
                    // 直接更新sqlite_sequence
                    @$db->query("UPDATE sqlite_sequence SET seq = {$next} WHERE name = '{$table}'");
                }
            } else {
                // MySQL
                $table = $prefix . 'contents';
                $db->query("ALTER TABLE `{$table}` AUTO_INCREMENT = {$next}");
            }
        } catch (Exception $e) {
            self::log('ensureAutoIncrement 异常: ' . $e->getMessage());
        }
    }

    /**
     * 记录日志到插件目录 logs/cid-sync.log
     */
    private static function log($message)
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
}
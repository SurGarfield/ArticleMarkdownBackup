<?php
/**
 * 文章备份与Markdown转换插件，增加CID策略管理
 * 
 * @package Article Markdown Backup
 * @author 森木志
 * @version 1.2.0
 * @link https://oxxx.cn
 */
class ArticleMarkdownBackup_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件
     */
    public static function activate()
    {
        // 注册action
        Helper::addAction('article_markdown_backup', 'ArticleMarkdownBackup_Action');
        
        // 添加管理页面
        Helper::addPanel(1, 'ArticleMarkdownBackup/manage.php', _t('文章备份与转换'), _t('文章备份与转换'), 'administrator');
        // 单独注册策略管理面板，避免在路径中使用查询字符串
        Helper::addPanel(1, 'ArticleMarkdownBackup/manage_cid.php', _t('CID策略管理'), _t('CID策略管理'), 'administrator');

        // 确保插件配置项存在，避免首次打开配置页抛出“配置信息没有找到”
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
            // 忽略初始化失败
        }

        // 挂载到文章/页面编辑的写入过滤器, 用于分配连续CID
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->write = array('ArticleMarkdownBackup_Plugin', 'filterWriteAssignCid');
        Typecho_Plugin::factory('Widget_Contents_Page_Edit')->write = array('ArticleMarkdownBackup_Plugin', 'filterWriteAssignCid');

        // 写入完成与发布完成时记录日志与提示
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
        // 移除action
        Helper::removeAction('article_markdown_backup');
        
        // 移除管理页面
        Helper::removePanel(1, 'ArticleMarkdownBackup/manage.php');
        Helper::removePanel(1, 'ArticleMarkdownBackup/manage_cid.php');
        
        return _t('文章备份与转换插件已禁用');
    }

    /**
     * 插件配置面板
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // 确保配置行存在（避免首次打开配置页抛出异常）
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
            // ignore
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

        // CID 连贯策略配置（自定义无 span 渲染）
        $cidStrategyDefault = 'ignore';
        try {
            $opt = Typecho_Widget::widget('Widget_Options')->plugin('ArticleMarkdownBackup');
            if (isset($opt->cidStrategy)) {
                $cidStrategyDefault = (string)$opt->cidStrategy;
            }
        } catch (Exception $e) {}
        // 用隐藏项承接保存，避免 Typecho 默认 Radio 生成 span
        if (class_exists('Typecho_Widget_Helper_Form_Element_Hidden')) {
            $cidStrategyHidden = new Typecho_Widget_Helper_Form_Element_Hidden('cidStrategy', null, $cidStrategyDefault, _t('CID 连贯策略'));
        } else {
            // 兼容性兜底：使用文本项并隐藏
            $cidStrategyHidden = new Typecho_Widget_Helper_Form_Element_Text('cidStrategy', null, $cidStrategyDefault, _t('CID 连贯策略'));
            $cidStrategyHidden->input->setAttribute('type', 'hidden');
        }
        $form->addInput($cidStrategyHidden);

        // 自定义单选渲染（不使用 span）
        echo '<div id="amb-cid-strategy-render">';
        echo '<label class="typecho-label">' . _t('CID 连贯策略') . '</label>';
        echo '<label style="display:block;margin-bottom:6px;"><input type="radio" name="cidStrategy_visual" value="skip"' . ($cidStrategyDefault==='skip'?' checked':'') . '> ' . _t('按最小可用位（跳过附件）') . '</label>';
        echo '<label style="display:block;margin-bottom:6px;"><input type="radio" name="cidStrategy_visual" value="ignore"' . ($cidStrategyDefault==='ignore'?' checked':'') . '> ' . _t('按最小可用位（忽略附件，遇附件则删除）') . '</label>';
        echo '<label style="display:block;margin-bottom:6px;"><input type="radio" name="cidStrategy_visual" value="grow_skip"' . ($cidStrategyDefault==='grow_skip'?' checked':'') . '> ' . _t('按新增可用位（从现有最大CID开始）') . '</label>';
        echo '<label style="display:block;margin-bottom:6px;"><input type="radio" name="cidStrategy_visual" value="grow_ignore"' . ($cidStrategyDefault==='grow_ignore'?' checked':'') . '> ' . _t('按新增可用位（忽略附件，遇附件则删除）') . '</label>';
        echo '<p class="description">' . _t('选择建议与自动同步时对附件的处理方式') . '</p>';
        echo '</div>';

        echo '<script>(function(){\n'
            . 'document.addEventListener("DOMContentLoaded", function(){\n'
            . '  var hidden = document.querySelector("input[name=cidStrategy]");\n'
            . '  var radios = document.querySelectorAll("input[name=cidStrategy_visual]");\n'
            . '  function sync(){\n'
            . '    var v = hidden && hidden.value ? hidden.value : "";\n'
            . '    radios.forEach(function(r){ if(r.value===v){ r.checked=true; } });\n'
            . '  }\n'
            . '  function hook(){\n'
            . '    radios.forEach(function(r){\n'
            . '      r.addEventListener("change", function(){ if(hidden){ hidden.value = r.value; } });\n'
            . '    });\n'
            . '  }\n'
            . '  sync(); hook();\n'
            . '});\n'
            . '})();</script>';

        // 让本配置页的两个单选项“逐行显示”
        echo '<style>#typecho-option-item-enableStrategy label, #typecho-option-item-cidStrategy label { display:block; margin-bottom:6px; }</style>';
        // 将默认生成的 span 包裹移除，改为 label 单独成行
        echo '<script>(function(){\n'
            . 'document.addEventListener("DOMContentLoaded", function(){\n'
            . '  function rewriteRadios(groupId){\n'
            . '    var root = document.getElementById(groupId);\n'
            . '    if(!root) return;\n'
            . '    var spans = Array.prototype.slice.call(root.querySelectorAll("span"));\n'
            . '    spans.forEach(function(sp){\n'
            . '      var input = sp.querySelector("input");\n'
            . '      var lbl = sp.querySelector("label");\n'
            . '      if(!input) { sp.parentNode.removeChild(sp); return; }\n'
            . '      var newLabel = document.createElement("label");\n'
            . '      newLabel.style.display = "block";\n'
            . '      newLabel.style.marginBottom = "6px";\n'
            . '      newLabel.appendChild(input);\n'
            . '      var text = lbl ? lbl.textContent : "";\n'
            . '      newLabel.appendChild(document.createTextNode(" " + text));\n'
            . '      sp.parentNode.insertBefore(newLabel, sp);\n'
            . '      sp.parentNode.removeChild(sp);\n'
            . '    });\n'
            . '  }\n'
            . '  rewriteRadios("typecho-option-item-cidStrategy");\n'
            . '  rewriteRadios("typecho-option-item-enableStrategy");\n'
            . '});\n'
            . '})();</script>';
    }

    /**
     * 个人用户配置面板
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
        // 个人配置选项
    }
    
    /**
     * 插件实现方法
     */
    public static function render()
    {
        // 空实现，避免出现回调错误
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
            // 未开启策略管理则不处理
            if (!self::isStrategyEnabled()) {
                return $contents;
            }
            // 已有内容编辑时不干预
            if (method_exists($widget, 'have') && $widget->have()) {
                return $contents;
            }

            // 仅在新建内容/草稿时处理
            $db = Typecho_Db::get();

            $strategy = self::getStrategy();

            // 选择起点: 最小可用位(从1) 或 新增可用位(从 maxValidCid+1)
            if ($strategy === 'grow_skip' || $strategy === 'grow_ignore') {
                $candidate = max(1, self::getMaxValidCid($db) + 1);
            } else {
                $candidate = 1;
            }
            $bumped = 0;
            while (true) {
                $row = $db->fetchRow($db->select('cid', 'type')->from('table.contents')->where('cid = ?', $candidate));
                if (empty($row)) {
                    // 空位，直接使用
                    break;
                }
                if (isset($row['type']) && $row['type'] === 'attachment') {
                    if ($strategy === 'ignore' || $strategy === 'grow_ignore') {
                        // 删除附件占位
                        $db->query($db->delete('table.contents')->where('cid = ?', $candidate));
                        self::log(sprintf('删除占用CID的附件: cid=%d', $candidate));
                        // 提示
                        try { Typecho_Widget::widget('Widget_Notice')->set(_t('删除附件占用，已分配CID %d', $candidate), 'success'); } catch (Exception $e) {}
                        break;
                    } else {
                        // 跳过附件，不删除
                        $bumped++;
                        $candidate++;
                        continue;
                    }
                }
                // 被有效内容占用, 递增
                $bumped++;
                $candidate++;
            }

            // 将候选CID写入, 由Typecho自带insert逻辑尊重cid
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
            // 未开启策略管理则跳过
            if (!self::isStrategyEnabled()) {
                return;
            }
            $db = Typecho_Db::get();
            $next = self::getRecommendedNextCid($db);

            // 自动同步: 仅在“最小可用位”策略下进行向下迁移
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
     * 获取最大有效CID(排除附件)
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
     * 计算建议的下一个最小可用CID(忽略附件占用)
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
     * 获取策略: skip(跳过附件) / ignore(忽略附件并可删除)
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
     * 是否开启策略管理
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
     * 将当前内容迁移到不大于当前cid的最小可用cid(忽略附件占用, 附件可被删除)
     */
    private static function migrateContentCidIfPossible($db, int $currentCid, int $targetStart, bool $allowDeleteAttachment)
    {
        try {
            $candidate = max(1, $targetStart);
            while ($candidate < $currentCid) {
                $row = $db->fetchRow($db->select('cid', 'type')->from('table.contents')->where('cid = ?', $candidate));
                if (empty($row)) {
                    break; // 空位
                }
                if ($row['type'] === 'attachment') {
                    if ($allowDeleteAttachment) {
                        $db->query($db->delete('table.contents')->where('cid = ?', $candidate));
                        self::log(sprintf('auto-sync 清理附件: cid=%d', $candidate));
                        break;
                    } else {
                        // 不允许删除附件，继续寻找
                        $candidate++;
                        continue;
                    }
                }
                $candidate++;
            }

            if ($candidate >= $currentCid) {
                return $currentCid; // 没有更小可用位
            }

            // 更新引用到新cid
            // comments
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
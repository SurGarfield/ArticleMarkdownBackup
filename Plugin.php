<?php
/**
 * 文章备份与Markdown转换插件
 * 
 * @package Article Markdown Backup
 * @author 森木志
 * @version 1.1.0
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
        
        return _t('文章备份与转换插件已禁用');
    }

    /**
     * 插件配置面板
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // 插件配置选项
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
}
<?php
/**
 * 简单的HTML到Markdown转换器
 */
class MarkdownConverter
{
    private $html;
    
    public function __construct($html)
    {
        $this->html = $html;
    }
    
    public function convert()
    {
        // 如果输入为空，直接返回空字符串
        if (empty($this->html)) {
            return '';
        }
        
        $markdown = $this->html;
        
        // 保存原始的引用和代码块内容
        $preserves = [];
        $counter = 0;
        
        // 保护<pre>标签中的内容
        $markdown = preg_replace_callback('/<pre[^>]*>(.*?)<\/pre>/is', function($matches) use (&$preserves, &$counter) {
            $key = "{{{preserve_" . ($counter++) . "}}}";
            $preserves[$key] = $matches[0]; // 保留原始HTML
            return $key;
        }, $markdown);
        
        // 保护<code>标签中的内容
        $markdown = preg_replace_callback('/<code[^>]*>(.*?)<\/code>/is', function($matches) use (&$preserves, &$counter) {
            $key = "{{{preserve_" . ($counter++) . "}}}";
            $preserves[$key] = $matches[0]; // 保留原始HTML
            return $key;
        }, $markdown);
        
        // 移除多余的空白行
        $markdown = preg_replace('/\n\s*\n/', "\n\n", $markdown);
        
        // 转换标题
        $markdown = preg_replace('/<h1[^>]*>(.*?)<\/h1>/i', "\n# $1\n", $markdown);
        $markdown = preg_replace('/<h2[^>]*>(.*?)<\/h2>/i', "\n## $1\n", $markdown);
        $markdown = preg_replace('/<h3[^>]*>(.*?)<\/h3>/i', "\n### $1\n", $markdown);
        $markdown = preg_replace('/<h4[^>]*>(.*?)<\/h4>/i', "\n#### $1\n", $markdown);
        $markdown = preg_replace('/<h5[^>]*>(.*?)<\/h5>/i', "\n##### $1\n", $markdown);
        $markdown = preg_replace('/<h6[^>]*>(.*?)<\/h6>/i', "\n###### $1\n", $markdown);
        
        // 转换粗体
        $markdown = preg_replace('/<(strong|b)(\s[^>]*)?>(.*?)<\/(strong|b)>/i', '**$3**', $markdown);
        
        // 转换斜体
        $markdown = preg_replace('/<(em|i)(\s[^>]*)?>(.*?)<\/(em|i)>/i', '*$3*', $markdown);
        
        // 转换删除线
        $markdown = preg_replace('/<del(\s[^>]*)?>(.*?)<\/del>/i', '~~$2~~', $markdown);
        $markdown = preg_replace('/<strike(\s[^>]*)?>(.*?)<\/strike>/i', '~~$2~~', $markdown);
        
        // 转换链接 - 修复格式为[文本](链接)
        $markdown = preg_replace('/<a\s+href=["\'](.*?)["\'][^>]*>(.*?)<\/a>/i', '[$2]($1)', $markdown);
        
        // 转换图片
        $markdown = preg_replace('/<img\s+src=["\'](.*?)["\'](\s+alt=["\'](.*?)["\'])?[^>]*>/i', '![$3]($1)', $markdown);
        
        // 转换无序列表
        $markdown = preg_replace_callback('/<ul(\s[^>]*)?>(.*?)<\/ul>/is', function($matches) {
            // 提取列表内容
            $content = $matches[2];
            
            // 将内容按<li>标签分割，保留所有<li>标签
            $pattern = '/(<li(\s[^>]*)?>(.*?)<\/li>)/is';
            preg_match_all($pattern, $content, $items);
            
            // 为每个项目添加无序列表标记
            $result = "\n";
            foreach ($items[0] as $item) {
                // 提取<li>标签中的内容
                $itemContent = preg_replace('/<li(\s[^>]*)?>(.*?)<\/li>/is', '$2', $item);
                // 移除内部可能存在的HTML标签
                $itemContent = strip_tags($itemContent);
                $result .= "- " . trim($itemContent) . "\n";
            }
            return $result . "\n";
        }, $markdown);
        
        // 转换有序列表
        $markdown = preg_replace_callback('/<ol(\s[^>]*)?>(.*?)<\/ol>/is', function($matches) {
            // 提取列表内容
            $content = $matches[2];
            
            // 将内容按<li>标签分割，保留所有<li>标签
            $pattern = '/(<li(\s[^>]*)?>(.*?)<\/li>)/is';
            preg_match_all($pattern, $content, $items);
            
            // 为每个项目添加序号
            $result = "\n";
            for ($i = 0; $i < count($items[0]); $i++) {
                // 提取<li>标签中的内容
                $itemContent = preg_replace('/<li(\s[^>]*)?>(.*?)<\/li>/is', '$2', $items[0][$i]);
                // 移除内部可能存在的HTML标签
                $itemContent = strip_tags($itemContent);
                $result .= ($i + 1) . ". " . trim($itemContent) . "\n";
            }
            return $result . "\n";
        }, $markdown);
        
        // 转换引用块 - 修复blockquote的处理
        $markdown = preg_replace_callback('/<blockquote[^>]*>(.*?)<\/blockquote>/is', function($matches) {
            // 先移除内部的HTML标签，保留文本内容
            $content = strip_tags($matches[1]);
            $content = trim($content);
            
            // 将内容按行分割并为每行添加>前缀
            $lines = preg_split('/\r\n|\r|\n/', $content);
            $result = "\n";
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) {
                    $result .= ">\n";
                } else {
                    $result .= "> " . $line . "\n";
                }
            }
            return $result . "\n";
        }, $markdown);
        
        // 转换段落 - 优化处理
        $markdown = preg_replace_callback('/<p(\s[^>]*)?>(.*?)<\/p>/is', function($matches) {
            // 保留段落内容，但移除内部可能存在的HTML标签
            $content = trim(strip_tags($matches[2]));
            return "\n\n" . $content . "\n\n";
        }, $markdown);
        
        // 转换表格 - 简单处理，保留表格内容
        $markdown = preg_replace_callback('/<table[^>]*>(.*?)<\/table>/is', function($matches) {
            $tableContent = strip_tags($matches[1], '<tr><td><th>');
            $tableContent = preg_replace('/<tr[^>]*>(.*?)<\/tr>/is', "\n$1\n", $tableContent);
            $tableContent = preg_replace('/<(td|th)[^>]*>(.*?)<\/(td|th)>/is', "| $2 ", $tableContent);
            return "\n\n" . $tableContent . "|\n\n";
        }, $markdown);
        
        // 转换换行 - 优化处理
        $markdown = preg_replace('/<br\s*\/?>/i', "\n", $markdown);
        $markdown = preg_replace('/<br>/i', "\n", $markdown);
        
        // 恢复保护的内容，将pre和code标签转换为Markdown代码块
        foreach ($preserves as $key => $value) {
            if (strpos($value, '<pre') !== false) {
                // 提取pre标签中的内容
                $content = preg_replace('/<pre[^>]*>(.*?)<\/pre>/is', '$1', $value);
                // 移除可能存在的code标签
                $content = preg_replace('/<\/?code[^>]*>/i', '', $content);
                // 转换为Markdown代码块
                $markdown = str_replace($key, "\n```\n" . $content . "\n```\n", $markdown);
            } else if (strpos($value, '<code') !== false) {
                // 提取code标签中的内容
                $content = preg_replace('/<code[^>]*>(.*?)<\/code>/is', '$1', $value);
                // 转换为Markdown行内代码
                $markdown = str_replace($key, "`" . $content . "`", $markdown);
            } else {
                // 其他保留内容直接替换
                $markdown = str_replace($key, $value, $markdown);
            }
        }
        
        // 移除剩余的HTML标签
        $markdown = strip_tags($markdown);
        
        // 清理多余的空行
        $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);
        
        // 去除首尾空白
        $markdown = trim($markdown);
        
        return $markdown;
    }
}
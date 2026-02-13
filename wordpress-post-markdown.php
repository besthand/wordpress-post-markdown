<?php
/**
 * Plugin Name: WordPress Post Markdown
 * Description: 當 client 請求可接受 markdown 格式時，將 wordpress 文章轉換為 markdown 格式輸出。
 * Version: 0.1.0
 * Author: 手哥
 * Author URI: https://handbro.pro
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WordPress_Post_Markdown {
    public static function init(): void {
        add_action('template_redirect', array(__CLASS__, 'maybe_render_markdown'), 0);
    }

    public static function maybe_render_markdown(): void {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }

        if (!self::wants_markdown()) {
            return;
        }

        if (!is_singular()) {
            return;
        }

        $post = get_queried_object();

        if (!($post instanceof WP_Post)) {
            return;
        }

        if ('publish' !== $post->post_status && !current_user_can('read_post', $post->ID)) {
            return;
        }

        $markdown = self::build_markdown($post);

        nocache_headers();
        header('Vary: Accept', false);
        header('Content-Type: text/markdown; charset=' . get_bloginfo('charset'));
        status_header(200);

        echo $markdown;
        exit;
    }

    private static function wants_markdown(): bool {
        $accept = isset($_SERVER['HTTP_ACCEPT']) ? (string) $_SERVER['HTTP_ACCEPT'] : '';

        if ('' === $accept) {
            return false;
        }

        return false !== stripos($accept, 'text/markdown');
    }

    private static function build_markdown(WP_Post $post): string {
        $title = 'title: ' . self::clean_inline_text(get_the_title($post));
        $meta_sections = array();

        if (has_excerpt($post)) {
            $excerpt_html = wp_kses_post(get_the_excerpt($post));
            $excerpt_md   = trim(self::html_to_markdown($excerpt_html));

            if ('' !== $excerpt_md) {
                $meta_sections[] = "**摘要**\n\n" . $excerpt_md;
            }
        }

        $category_lines = self::taxonomy_lines($post->ID, 'category');
        $meta_sections[] = "**Category**\n\n" . (empty($category_lines) ? '_（無）_' : implode("\n", $category_lines));

        $custom_taxonomy_section = self::custom_taxonomy_section($post);
        if ('' !== $custom_taxonomy_section) {
            $meta_sections[] = $custom_taxonomy_section;
        }

        $tag_lines = self::taxonomy_lines($post->ID, 'post_tag');
        if (!empty($tag_lines)) {
            $meta_sections[] = "**Tag**\n\n" . implode("\n", $tag_lines);
        }

        $content_html = apply_filters('the_content', $post->post_content);
        $image_lines = self::image_lines($post, $content_html);
        if (!empty($image_lines)) {
            $meta_sections[] = "**圖片清單**\n\n" . implode("\n", $image_lines);
        }

        $content_md = trim(self::html_to_markdown($content_html));
        $body = '' !== $content_md ? $content_md : '_（無內容）_';

        $sections = array($title);

        if (!empty($meta_sections)) {
            $sections[] = '---';
            $sections[] = implode("\n\n", $meta_sections);
            $sections[] = '---';
        }

        $sections[] = $body;

        return implode("\n\n", $sections) . "\n";
    }

    private static function taxonomy_lines(int $post_id, string $taxonomy): array {
        $terms = get_the_terms($post_id, $taxonomy);

        if (empty($terms) || is_wp_error($terms)) {
            return array();
        }

        $lines = array();

        foreach ($terms as $term) {
            $url = get_term_link($term);

            if (is_wp_error($url)) {
                continue;
            }

            $lines[] = '- [' . self::clean_inline_text($term->name) . '](' . esc_url_raw($url) . ')';
        }

        return $lines;
    }

    private static function custom_taxonomy_section(WP_Post $post): string {
        $taxonomy_objects = get_object_taxonomies($post->post_type, 'objects');

        if (empty($taxonomy_objects)) {
            return '';
        }

        $chunks = array();

        foreach ($taxonomy_objects as $taxonomy => $taxonomy_obj) {
            if (in_array($taxonomy, array('category', 'post_tag'), true)) {
                continue;
            }

            $terms = get_the_terms($post->ID, $taxonomy);

            if (empty($terms) || is_wp_error($terms)) {
                continue;
            }

            $label = isset($taxonomy_obj->labels->singular_name) && '' !== $taxonomy_obj->labels->singular_name
                ? $taxonomy_obj->labels->singular_name
                : $taxonomy;

            $term_lines = array();

            foreach ($terms as $term) {
                $url = get_term_link($term);

                if (is_wp_error($url)) {
                    continue;
                }

                $term_lines[] = '- [' . self::clean_inline_text($term->name) . '](' . esc_url_raw($url) . ')';
            }

            if (empty($term_lines)) {
                continue;
            }

            $chunks[] = '**' . self::clean_inline_text($label) . ' (`' . $taxonomy . "`)**\n\n" . implode("\n", $term_lines);
        }

        if (empty($chunks)) {
            return '';
        }

        return "**自訂 Taxonomy**\n\n" . implode("\n\n", $chunks);
    }

    private static function image_lines(WP_Post $post, string $content_html): array {
        $images = array();

        if (has_post_thumbnail($post)) {
            $thumb_id = (int) get_post_thumbnail_id($post);
            $thumb_url = wp_get_attachment_image_url($thumb_id, 'full');

            if ($thumb_url) {
                $thumb_alt = trim((string) get_post_meta($thumb_id, '_wp_attachment_image_alt', true));
                $thumb_title = trim((string) get_the_title($thumb_id));
                self::add_image_meta($images, $thumb_url, $thumb_alt, $thumb_title);
            }
        }

        if (class_exists('DOMDocument')) {
            $doc = new DOMDocument();
            $wrapped_html = '<!DOCTYPE html><html><body>' . $content_html . '</body></html>';

            libxml_use_internal_errors(true);
            $loaded = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $wrapped_html);
            libxml_clear_errors();

            if ($loaded) {
                $image_nodes = $doc->getElementsByTagName('img');

                foreach ($image_nodes as $image_node) {
                    $src = trim((string) $image_node->getAttribute('src'));

                    if ('' === $src) {
                        continue;
                    }

                    $alt = trim((string) $image_node->getAttribute('alt'));
                    $title = trim((string) $image_node->getAttribute('title'));
                    self::add_image_meta($images, $src, $alt, $title);
                }
            }
        }

        $lines = array();

        foreach ($images as $url => $meta) {
            $lines[] = '- ' . self::build_image_markdown($url, $meta['alt'], $meta['title']);
        }

        return $lines;
    }

    private static function html_to_markdown(string $html): string {
        if ('' === trim($html)) {
            return '';
        }

        if (!class_exists('DOMDocument')) {
            return trim(wp_strip_all_tags($html));
        }

        $doc = new DOMDocument();
        $wrapped_html = '<!DOCTYPE html><html><body><div id="wpmd-root">' . $html . '</div></body></html>';

        libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $wrapped_html);
        libxml_clear_errors();

        if (!$loaded) {
            return trim(wp_strip_all_tags($html));
        }

        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query('//*[@id="wpmd-root"]');

        if (!$nodes || 0 === $nodes->length) {
            return trim(wp_strip_all_tags($html));
        }

        $output = trim(self::convert_children_to_markdown($nodes->item(0), 0));
        $output = preg_replace("/\n{3,}/", "\n\n", $output);

        return trim((string) $output);
    }

    private static function convert_children_to_markdown(DOMNode $node, int $depth): string {
        $content = '';

        foreach ($node->childNodes as $child) {
            $content .= self::convert_node_to_markdown($child, $depth);
        }

        return $content;
    }

    private static function convert_node_to_markdown(DOMNode $node, int $depth): string {
        if (XML_TEXT_NODE === $node->nodeType) {
            return self::normalize_text($node->nodeValue);
        }

        if (XML_ELEMENT_NODE !== $node->nodeType) {
            return '';
        }

        $tag = strtolower($node->nodeName);

        if (in_array($tag, array('script', 'style'), true)) {
            return '';
        }

        if (preg_match('/^h([1-6])$/', $tag, $matches)) {
            $level = (int) $matches[1];
            $text  = trim(self::convert_children_to_markdown($node, $depth));

            return '' === $text ? '' : "\n\n" . str_repeat('#', $level) . ' ' . $text . "\n\n";
        }

        switch ($tag) {
            case 'p':
                $text = trim(self::convert_children_to_markdown($node, $depth));
                return '' === $text ? '' : "\n\n" . $text . "\n\n";
            case 'br':
                return "  \n";
            case 'strong':
            case 'b':
                $text = trim(self::convert_children_to_markdown($node, $depth));
                return '' === $text ? '' : '**' . $text . '**';
            case 'em':
            case 'i':
                $text = trim(self::convert_children_to_markdown($node, $depth));
                return '' === $text ? '' : '*' . $text . '*';
            case 'code':
                $text = trim((string) $node->textContent);
                $text = str_replace('`', '\\`', $text);
                return '' === $text ? '' : '`' . $text . '`';
            case 'pre':
                $text = rtrim((string) $node->textContent);
                return '' === $text ? '' : "\n\n```\n" . $text . "\n```\n\n";
            case 'a':
                $href = '';
                if ($node instanceof DOMElement && $node->hasAttribute('href')) {
                    $href = trim((string) $node->getAttribute('href'));
                }
                $text = trim(self::convert_children_to_markdown($node, $depth));
                if ('' === $text) {
                    $text = $href;
                }
                if ('' === $href) {
                    return $text;
                }
                return '[' . $text . '](' . esc_url_raw($href) . ')';
            case 'img':
                $src = '';
                $alt = '';
                $title = '';
                if ($node instanceof DOMElement) {
                    $src = trim((string) $node->getAttribute('src'));
                    $alt = trim((string) $node->getAttribute('alt'));
                    $title = trim((string) $node->getAttribute('title'));
                }
                if ('' === $src) {
                    return '';
                }
                return self::build_image_markdown($src, $alt, $title);
            case 'ul':
                return self::convert_list_to_markdown($node, false, $depth);
            case 'ol':
                return self::convert_list_to_markdown($node, true, $depth);
            case 'blockquote':
                $text = trim(self::convert_children_to_markdown($node, $depth));
                if ('' === $text) {
                    return '';
                }
                $lines = preg_split('/\R/', $text);
                $prefixed = array();
                foreach ($lines as $line) {
                    $trimmed = trim((string) $line);
                    if ('' === $trimmed) {
                        continue;
                    }
                    $prefixed[] = '> ' . $trimmed;
                }
                return empty($prefixed) ? '' : "\n\n" . implode("\n", $prefixed) . "\n\n";
            case 'hr':
                return "\n\n---\n\n";
            case 'table':
                return self::convert_table_to_markdown($node);
            default:
                return self::convert_children_to_markdown($node, $depth);
        }
    }

    private static function convert_list_to_markdown(DOMNode $node, bool $ordered, int $depth): string {
        $lines = array();
        $index = 1;

        foreach ($node->childNodes as $child) {
            if (XML_ELEMENT_NODE !== $child->nodeType || 'li' !== strtolower($child->nodeName)) {
                continue;
            }

            $item = trim(self::convert_children_to_markdown($child, $depth + 1));
            if ('' === $item) {
                continue;
            }

            $item = preg_replace('/\n{2,}/', "\n", $item);
            $parts = preg_split('/\R/', (string) $item);

            if (empty($parts)) {
                continue;
            }

            $indent = str_repeat('  ', $depth);
            $marker = $ordered ? $index . '.' : '-';

            $first_line = $indent . $marker . ' ' . trim((string) array_shift($parts));
            $lines[] = $first_line;

            foreach ($parts as $part) {
                $trimmed = trim((string) $part);
                if ('' === $trimmed) {
                    continue;
                }
                $lines[] = $indent . '  ' . $trimmed;
            }

            $index++;
        }

        if (empty($lines)) {
            return '';
        }

        return "\n\n" . implode("\n", $lines) . "\n\n";
    }

    private static function normalize_text(?string $text): string {
        if (null === $text || '' === $text) {
            return '';
        }

        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = preg_replace('/\s+/u', ' ', $decoded);

        return (string) $decoded;
    }

    private static function clean_inline_text(string $text): string {
        $text = trim((string) $text);
        $text = str_replace(array("\r", "\n"), ' ', $text);
        return preg_replace('/\s+/u', ' ', $text);
    }

    private static function convert_table_to_markdown(DOMNode $table): string {
        if (!($table instanceof DOMElement)) {
            return '';
        }

        $rows = self::extract_table_rows($table);
        if (empty($rows)) {
            return '';
        }

        $max_cols = 0;
        foreach ($rows as $row) {
            $max_cols = max($max_cols, count($row['cells']));
        }

        if (0 === $max_cols) {
            return '';
        }

        foreach ($rows as &$row) {
            while (count($row['cells']) < $max_cols) {
                $row['cells'][] = '';
            }
        }
        unset($row);

        $keep_cols = array();
        for ($i = 0; $i < $max_cols; $i++) {
            foreach ($rows as $row) {
                if ('' !== $row['cells'][$i]) {
                    $keep_cols[] = $i;
                    break;
                }
            }
        }

        if (empty($keep_cols)) {
            return '';
        }

        foreach ($rows as &$row) {
            $filtered = array();
            foreach ($keep_cols as $col_index) {
                $filtered[] = $row['cells'][$col_index];
            }
            $row['cells'] = $filtered;
        }
        unset($row);

        $header_index = 0;
        foreach ($rows as $index => $row) {
            if ($row['from_thead']) {
                $header_index = $index;
                break;
            }
            if ($row['has_th']) {
                $header_index = $index;
                break;
            }
        }

        $header_cells = $rows[$header_index]['cells'];
        $body_rows = array();
        foreach ($rows as $index => $row) {
            if ($index === $header_index) {
                continue;
            }
            $body_rows[] = $row['cells'];
        }

        $column_count = count($header_cells);
        if (0 === $column_count) {
            return '';
        }

        $lines = array();
        $lines[] = '| ' . implode(' | ', array_map(array(__CLASS__, 'escape_table_cell'), $header_cells)) . ' |';
        $lines[] = '| ' . implode(' | ', array_fill(0, $column_count, '---')) . ' |';

        foreach ($body_rows as $body_row) {
            $lines[] = '| ' . implode(' | ', array_map(array(__CLASS__, 'escape_table_cell'), $body_row)) . ' |';
        }

        return "\n\n" . implode("\n", $lines) . "\n\n";
    }

    private static function extract_table_rows(DOMElement $table): array {
        $rows = array();
        $tr_nodes = $table->getElementsByTagName('tr');

        foreach ($tr_nodes as $tr_node) {
            if (!($tr_node instanceof DOMElement)) {
                continue;
            }

            if (!self::is_row_in_table($tr_node, $table)) {
                continue;
            }

            $row_cells = array();
            $has_th = false;

            foreach ($tr_node->childNodes as $cell_node) {
                if (!($cell_node instanceof DOMElement)) {
                    continue;
                }

                $tag = strtolower($cell_node->tagName);
                if ('th' !== $tag && 'td' !== $tag) {
                    continue;
                }

                $has_th = $has_th || ('th' === $tag);
                $text = trim(self::convert_children_to_markdown($cell_node, 0));
                $text = self::normalize_table_cell($text);

                $colspan = 1;
                if ($cell_node->hasAttribute('colspan')) {
                    $colspan = max(1, (int) $cell_node->getAttribute('colspan'));
                }

                $row_cells[] = $text;

                for ($i = 1; $i < $colspan; $i++) {
                    $row_cells[] = '';
                }
            }

            if (empty($row_cells)) {
                continue;
            }

            $rows[] = array(
                'cells' => $row_cells,
                'has_th' => $has_th,
                'from_thead' => self::is_row_in_section($tr_node, 'thead', $table),
            );
        }

        return $rows;
    }

    private static function is_row_in_table(DOMElement $row, DOMElement $table): bool {
        $node = $row->parentNode;

        while ($node instanceof DOMNode) {
            if ($node instanceof DOMElement && 'table' === strtolower($node->tagName)) {
                return $node->isSameNode($table);
            }

            $node = $node->parentNode;
        }

        return false;
    }

    private static function is_row_in_section(DOMElement $row, string $section_tag, DOMElement $table): bool {
        $section_tag = strtolower($section_tag);
        $node = $row->parentNode;

        while ($node instanceof DOMNode) {
            if ($node instanceof DOMElement && 'table' === strtolower($node->tagName)) {
                return false;
            }

            if ($node instanceof DOMElement && $section_tag === strtolower($node->tagName)) {
                return true;
            }

            if ($node instanceof DOMElement && $node->isSameNode($table)) {
                return false;
            }

            $node = $node->parentNode;
        }

        return false;
    }

    private static function normalize_table_cell(string $text): string {
        if ('' === $text) {
            return '';
        }

        $text = str_replace(array("\r\n", "\r"), "\n", $text);
        $text = preg_replace('/\n{2,}/', "\n", $text);
        $text = trim($text);
        $text = preg_replace('/\s*\n\s*/', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim((string) $text);
    }

    private static function escape_table_cell(string $text): string {
        $text = self::clean_inline_text($text);
        return str_replace('|', '\|', $text);
    }

    private static function add_image_meta(array &$images, string $url, string $alt, string $title): void {
        $url = trim($url);
        if ('' === $url) {
            return;
        }

        if (!isset($images[$url])) {
            $images[$url] = array(
                'alt' => '',
                'title' => '',
            );
        }

        $clean_alt = self::clean_inline_text($alt);
        $clean_title = self::clean_inline_text($title);

        if ('' !== $clean_alt && '' === $images[$url]['alt']) {
            $images[$url]['alt'] = $clean_alt;
        }

        if ('' !== $clean_title && '' === $images[$url]['title']) {
            $images[$url]['title'] = $clean_title;
        }
    }

    private static function build_image_markdown(string $url, string $alt, string $title): string {
        $clean_url = esc_url_raw($url);
        $clean_alt = self::clean_inline_text($alt);
        $clean_title = self::clean_inline_text($title);

        if ('' === $clean_alt && '' !== $clean_title) {
            $clean_alt = $clean_title;
        }

        if ('' === $clean_alt) {
            $clean_alt = 'image';
        }

        if ('' === $clean_title) {
            return '![' . $clean_alt . '](' . $clean_url . ')';
        }

        return '![' . $clean_alt . '](' . $clean_url . ' "' . str_replace('"', '\"', $clean_title) . '")';
    }
}

WordPress_Post_Markdown::init();

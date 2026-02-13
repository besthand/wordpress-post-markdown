=== Handbro Post Markdown ===
Contributors: handbro
Tags: markdown, content-negotiation
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

當 Client 的 Accept Header 包含 text/markdown 時，將文章或頁面內容輸出為 Markdown。

== Description ==

此外掛會在前台單篇內容頁（文章/頁面）偵測 HTTP `Accept` Header。
當包含 `text/markdown` 時，改以 Markdown 輸出內容。

== Installation ==

1. 將 `wordpress-post-markdown.php` 上傳到：
`/wp-content/plugins/wordpress-post-markdown/`
2. 在 WordPress 後台啟用 `Handbro Post Markdown` 外掛。
3. 以含有 `Accept: text/markdown` 的請求存取文章頁面。

== Changelog ==

= 0.1.0 =
* First release.

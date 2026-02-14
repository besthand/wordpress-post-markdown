# WordPress Post Markdown

## 安裝方式

1. 將 `wordpress-post-markdown.php` 放到 WordPress 的外掛目錄：
   - `wp-content/plugins/wordpress-post-markdown/wordpress-post-markdown.php`
2. 到 WordPress 後台 `外掛 > 已安裝外掛`。
3. 啟用 `WordPress Post Markdown`。

## 驗證安裝

1. 開啟任一篇文章或頁面網址（前台）。
2. 用 HTTP Header `Accept: text/markdown` 發送請求，例如：

```bash
curl -H "Accept: text/markdown" https://your-site.com/your-post
```

3. 若安裝成功，回看到網站以 Markdown 格式顯示內容 。

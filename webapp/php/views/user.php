<div class="isu-user">
  <div><span class="isu-user-account-name"><?= escape_html($user['account_name']) ?>さん</span>のページ</div>
  <div>投稿数 <span class="isu-post-count"><?= escape_html($post_count) ?></span></div>
  <div>コメント数 <span class="isu-comment-count"><?= escape_html($comment_count) ?></span></div>
  <div>被コメント数 <span class="isu-commented-count"><?= escape_html($commented_count) ?></span></div>
</div>

<div class="isu-posts">
  <?php foreach ($posts as $post): ?>
    <?php require __DIR__ . '/post.php' ?>
  <?php endforeach ?>
</div>

<div class="isu-user">
  <div><span class="isu-user-account-name"><?= escape_html($user['account_name']) ?>さん</span>のページ</div>
  <div>投稿数 <span class="isu-post-count"><?= escape_html($post_count) ?></span></div>
  <div>コメント数 <span class="isu-comment-count"><?= escape_html($comment_count) ?></span></div>
  <div>被コメント数 <span class="isu-commented-count"><?= escape_html($commented_count) ?></span></div>
</div>

<div class="isu-posts">
  <?php foreach ($posts as $post): ?>
    <?php $post_user_name = escape_html($post['user']['account_name']); $post_user_url = escape_html(rawurlencode($post['user']['account_name'])); ?>
    <div class="isu-post" id="pid_<?= $post['id'] ?>" data-created-at="<?= escape_html($post['created_at']) ?>">
      <div class="isu-post-header">
        <a href="/@<?= $post_user_url ?>" class="isu-post-account-name"><?= $post_user_name ?></a>
        <a href="/posts/<?= $post['id'] ?>" class="isu-post-permalink">
          <time class="timeago" datetime="<?= escape_html($post['created_at']) ?>"></time>
        </a>
      </div>
      <div class="isu-post-image">
        <img src="<?= escape_html(image_url($post)) ?>" class="isu-image">
      </div>
      <div class="isu-post-text">
        <a href="/@<?= $post_user_url ?>" class="isu-post-account-name"><?= $post_user_name ?></a>
        <?= escape_html(nl2br($post['body'])) ?>
      </div>
      <div class="isu-post-comment">
        <div class="isu-post-comment-count">
          comments: <b><?= escape_html($post['comment_count']) ?></b>
        </div>

        <?php foreach ($post['comments'] as $comment): ?>
        <div class="isu-comment">
          <a href="/@<?= escape_html(rawurlencode($comment['user']['account_name'])) ?>" class="isu-comment-account-name"><?= escape_html($comment['user']['account_name']) ?></a>
          <span class="isu-comment-text"><?= escape_html($comment['comment']) ?></span>
        </div>
        <?php endforeach ?>
        <div class="isu-comment-form">
          <form method="post" action="/comment">
            <input type="text" name="comment">
            <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= escape_html($_SESSION['csrf_token'] ?? '') ?>">
            <input type="submit" name="submit" value="submit">
          </form>
        </div>
      </div>
    </div>
  <?php endforeach ?>
</div>

-- idx-basic-01: canonical indexes for fresh MySQL volumes.
-- Docker's MySQL entrypoint runs this after dump.sql.bz2 on first initialization.

USE `isuconp`;

CREATE INDEX `idx_created_at` ON `posts` (`created_at`);
CREATE INDEX `idx_user_id` ON `posts` (`user_id`);
CREATE INDEX `idx_post_id_created_at` ON `comments` (`post_id`, `created_at`);
CREATE INDEX `idx_user_id` ON `comments` (`user_id`);

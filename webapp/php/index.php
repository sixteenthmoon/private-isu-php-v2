<?php
use Psr\Http\Message\ResponseInterface;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use Slim\Factory\AppFactory;
use DI\Container;

require 'vendor/autoload.php';

$_SERVER += ['PATH_INFO' => $_SERVER['REQUEST_URI']];
$_SERVER['SCRIPT_NAME'] = '/' . basename($_SERVER['SCRIPT_FILENAME']);

const POSTS_PER_PAGE = 20;
const UPLOAD_LIMIT = 10 * 1024 * 1024;

// GET /image/{id}.{ext} と GET /posts は $_SESSION を一切参照しないため、
// session fileのopen/read/lockをスキップする。
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$is_posts_list = $request_uri === '/posts' || strncmp($request_uri, '/posts?', 7) === 0;
if (strncmp($request_uri, '/image/', 7) !== 0 && !$is_posts_list) {
    session_start();
}

// dependency
$container = new Container();
$container->set('settings', function() {
    return [
        'public_folder' => dirname(dirname(__FILE__)) . '/public',
        'db' => [
            'host' => $_SERVER['ISUCONP_DB_HOST'] ?? 'localhost',
            'port' => $_SERVER['ISUCONP_DB_PORT'] ?? 3306,
            'socket' => $_SERVER['ISUCONP_DB_SOCKET'] ?? null,
            'username' => $_SERVER['ISUCONP_DB_USER'] ?? 'root',
            'password' => $_SERVER['ISUCONP_DB_PASSWORD'] ?? null,
            'database' => $_SERVER['ISUCONP_DB_NAME'] ?? 'isuconp',
        ],
        'memcached' => [
            'address' => $_SERVER['ISUCONP_MEMCACHED_ADDRESS'] ?? 'localhost:11211',
            'socket' => $_SERVER['ISUCONP_MEMCACHED_SOCKET'] ?? null,
        ],
    ];
});
$container->set('db', function ($c) {
    $config = $c->get('settings');
    $socket = $config['db']['socket'];
    $dsn = is_string($socket) && $socket !== '' && is_readable($socket)
        ? "mysql:dbname={$config['db']['database']};unix_socket={$socket};charset=utf8mb4"
        : "mysql:dbname={$config['db']['database']};host={$config['db']['host']};port={$config['db']['port']};charset=utf8mb4";
    return new PDO(
        $dsn,
        $config['db']['username'],
        $config['db']['password'],
        [
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
});

$container->set('memcached', function ($c) {
    $config = $c->get('settings');
    $m = new Memcached('pool');
    // cache値は数百byte程度のPHP配列のみのため、zlib圧縮はCPUの純損失。
    $m->setOption(Memcached::OPT_COMPRESSION, false);
    if (!count($m->getServerList())) {
        $socket = $config['memcached']['socket'];
        if (is_string($socket) && $socket !== '' && is_readable($socket)) {
            $m->addServer($socket, 0);
        } else {
            [$host, $port] = explode(':', $config['memcached']['address']);
            $m->addServer($host, (int)$port);
        }
    }
    return $m;
});

$container->set('view', function ($c) {
    return new class(__DIR__ . '/views/') extends \Slim\Views\PhpRenderer {
        public function render(\Psr\Http\Message\ResponseInterface $response, string $template, array $data = []): ResponseInterface {
            $data += ['view' => $template];
            return parent::render($response, 'layout.php', $data);
        }
    };
});

$container->set('flash', function () {
    return new \Slim\Flash\Messages;
});

$container->set('helper', function ($c) {
    return new class($c) {
        public PDO $db;
        public Memcached $mc;

        public function __construct($c) {
            $this->db = $c->get('db');
            $this->mc = $c->get('memcached');
        }

        public function db() {
            return $this->db;
        }

        public function mc() {
            return $this->mc;
        }

        public function db_initialize() {
            $session_dir = ini_get('session.save_path');
            if (is_string($session_dir) && $session_dir !== '') {
                foreach (glob(rtrim($session_dir, '/') . '/sess_*') ?: [] as $session_file) {
                    @unlink($session_file);
                }
            }

            $db = $this->db();
            $sql = [];
            $sql[] = 'DELETE FROM users WHERE id > 1000';
            $sql[] = 'DELETE FROM posts WHERE id > 10000';
            $sql[] = 'DELETE FROM comments WHERE id > 100000';
            $sql[] = 'UPDATE users SET del_flg = 0';
            $sql[] = 'UPDATE users SET del_flg = 1 WHERE id % 50 = 0';
            foreach($sql as $s) {
                $db->query($s);
            }
        }

        public function fetch_first($query, ...$params) {
            $db = $this->db();
            $ps = $db->prepare($query);
            $ps->execute($params);
            $result = $ps->fetch(PDO::FETCH_ASSOC);
            $ps->closeCursor();
            return $result;
        }

        public function try_login($account_name, $password) {
            $user = $this->fetch_user_by_account_name($account_name);
            if ($user && calculate_passhash($user['account_name'], $password) == $user['passhash']) {
                return $user;
            }
            return null;
        }

        // account_nameは登録後不変・passhashも変更手段が無いため、ban以外でこの行の
        // 対応関係は変わらない。GET /@{account_name}とtry_login()で共有するcache-aside。
        // キーはanx:(account-name-lookup-memcached-cache-01の旧an:とはvalue形状が異なる
        // ため、デプロイをまたいで生存する旧形式エントリと衝突しないよう別名にしている)。
        public function fetch_user_by_account_name($account_name) {
            $key = 'anx:' . $account_name;
            $mc = $this->mc();
            $user = $mc->get($key);
            if ($user === false) {
                $user = $this->fetch_first('SELECT `id`, `account_name`, `passhash` FROM users WHERE account_name = ? AND del_flg = 0', $account_name);
                if ($user) {
                    $mc->set($key, $user, 3600);
                }
            }
            return $user ?: null;
        }

        // posts.user_idは投稿後不変(所有者変更経路が存在しない)ため、
        // po:{post_id}は能動的invalidation不要の永続cache-aside。
        public function fetch_post_owner_id($post_id) {
            $key = 'po:' . $post_id;
            $mc = $this->mc();
            $owner_id = $mc->get($key);
            if ($owner_id === false) {
                $owner = $this->fetch_first('SELECT `user_id` FROM `posts` WHERE `id` = ?', $post_id);
                $owner_id = $owner ? $owner['user_id'] : null;
                if ($owner_id !== null) {
                    $mc->set($key, $owner_id, 3600);
                }
            }
            return $owner_id;
        }

        public function get_session_user() {
            if (!isset($_SESSION['user'], $_SESSION['user']['id'])) {
                return null;
            }

            $id = $_SESSION['user']['id'];
            $key = 'u:' . $id;
            $cached = $this->mc()->get($key);
            if ($cached !== false) {
                return $cached;
            }

            $user = $this->fetch_first('SELECT `id`, `account_name`, `authority`, `del_flg` FROM `users` WHERE `id` = ?', $id);
            if ($user) {
                $this->mc()->set($key, $user, 3600);
            }

            return $user ?: null;
        }

        public function make_posts(array $results, $options = []) {
            $options += ['all_comments' => false];
            $all_comments = $options['all_comments'];

            if (empty($results)) {
                return [];
            }

            $db = $this->db();
            $post_ids = array_column($results, 'id');
            $in = implode(',', array_fill(0, count($post_ids), '?'));

            $comment_counts = [];
            $comments_by_post = [];

            if ($all_comments) {
                $ps = $db->prepare("SELECT `c`.`id`, `c`.`post_id`, `c`.`user_id`, `c`.`comment`, `c`.`created_at`,
                                           `cu`.`account_name` AS `comment_user_account_name`, `cu`.`del_flg` AS `comment_user_del_flg`
                                    FROM `comments` `c` JOIN `users` `cu` ON `cu`.`id` = `c`.`user_id`
                                    WHERE `c`.`post_id` IN ($in) ORDER BY `c`.`post_id`, `c`.`created_at` DESC");
                $ps->execute($post_ids);
                while ($comment = $ps->fetch(PDO::FETCH_ASSOC)) {
                    $comment['user'] = [
                        'id' => $comment['user_id'],
                        'account_name' => $comment['comment_user_account_name'],
                        'del_flg' => $comment['comment_user_del_flg'],
                    ];
                    unset($comment['comment_user_account_name'], $comment['comment_user_del_flg']);
                    $comments_by_post[$comment['post_id']][] = $comment;
                }
                foreach ($post_ids as $post_id) {
                    $comment_counts[$post_id] = count($comments_by_post[$post_id] ?? []);
                }
            } else {
                // 一覧の上位3件+全件数はpost_id単位でmemcachedへcache-aside。
                // 新規commentはPOST /commentで該当post_idのキーをdeleteし整合を保つ
                // （TTLは安全網。del_flgはtemplateで未使用のためbanとのstale干渉なし）。
                $mc = $this->mc();
                $cache_keys = array_map(fn($id) => 'c3:' . $id, $post_ids);
                $cached = $mc->getMulti($cache_keys) ?: [];

                $missing_ids = [];
                foreach ($post_ids as $post_id) {
                    $key = 'c3:' . $post_id;
                    if (array_key_exists($key, $cached)) {
                        $entry = $cached[$key];
                        $comment_counts[$post_id] = $entry['count'];
                        if ($entry['comments']) {
                            $comments_by_post[$post_id] = $entry['comments'];
                        }
                    } else {
                        $missing_ids[] = $post_id;
                    }
                }

                if ($missing_ids) {
                    $in_missing = implode(',', array_fill(0, count($missing_ids), '?'));
                    $ps = $db->prepare("
                        SELECT `id`, `post_id`, `user_id`, `comment`, `created_at`, `comment_user_account_name`,
                               `comment_user_del_flg`, `total_count` FROM (
                            SELECT `c`.`id`, `c`.`post_id`, `c`.`user_id`, `c`.`comment`, `c`.`created_at`,
                                   `cu`.`account_name` AS `comment_user_account_name`, `cu`.`del_flg` AS `comment_user_del_flg`,
                                   ROW_NUMBER() OVER (PARTITION BY `c`.`post_id` ORDER BY `c`.`created_at` DESC) AS `rn`,
                                   COUNT(*) OVER (PARTITION BY `c`.`post_id`) AS `total_count`
                            FROM `comments` `c` JOIN `users` `cu` ON `cu`.`id` = `c`.`user_id`
                            WHERE `c`.`post_id` IN ($in_missing)
                        ) `t` WHERE `rn` <= 3 ORDER BY `post_id`, `created_at` DESC
                    ");
                    $ps->execute($missing_ids);
                    $fetched_by_post = [];
                    $fetched_counts = [];
                    while ($comment = $ps->fetch(PDO::FETCH_ASSOC)) {
                        $post_id = $comment['post_id'];
                        $fetched_counts[$post_id] = (int)$comment['total_count'];
                        unset($comment['total_count']);
                        $comment['user'] = [
                            'id' => $comment['user_id'],
                            'account_name' => $comment['comment_user_account_name'],
                            'del_flg' => $comment['comment_user_del_flg'],
                        ];
                        unset($comment['comment_user_account_name'], $comment['comment_user_del_flg']);
                        $fetched_by_post[$post_id][] = $comment;
                    }
                    foreach ($missing_ids as $post_id) {
                        $count = $fetched_counts[$post_id] ?? 0;
                        $comments = $fetched_by_post[$post_id] ?? [];
                        $comment_counts[$post_id] = $count;
                        if ($comments) {
                            $comments_by_post[$post_id] = $comments;
                        }
                        $mc->set('c3:' . $post_id, ['count' => $count, 'comments' => $comments], 3600);
                    }
                }
            }

            $posts = [];
            foreach ($results as $post) {
                $post['comment_count'] = $comment_counts[$post['id']] ?? 0;
                $comments = $comments_by_post[$post['id']] ?? [];
                $post['comments'] = array_reverse($comments);

                $post['user'] = [
                    'id' => $post['user_id'],
                    'account_name' => $post['post_user_account_name'],
                    'del_flg' => $post['post_user_del_flg'],
                ];
                unset($post['post_user_account_name'], $post['post_user_del_flg']);
                if ($post['user']['del_flg'] == 0) {
                    $posts[] = $post;
                }
                if (count($posts) >= POSTS_PER_PAGE) {
                    break;
                }
            }
            return $posts;
        }

    };
});

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->getRouteCollector()->setCacheFile('/dev/shm/private-isu-routes.cache.php');

// ------- helper method for view

function escape_html($h) {
    return htmlspecialchars($h, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function redirect(Response $response, $location, $status) {
    return $response->withStatus($status)->withHeader('Location', $location);
}

function image_url($post) {
    $ext = match ($post['mime']) {
        'image/jpeg' => '.jpg',
        'image/png' => '.png',
        'image/gif' => '.gif',
        default => '',
    };
    return "/image/{$post['id']}{$ext}";
}

function validate_user($account_name, $password) {
    if (!(preg_match('/\A[0-9a-zA-Z_]{3,}\z/', $account_name) && preg_match('/\A[0-9a-zA-Z_]{6,}\z/', $password))) {
        return false;
    }
    return true;
}

function digest($src) {
    return hash('sha512', $src);
}

function calculate_salt($account_name) {
    return digest($account_name);
}

function calculate_passhash($account_name, $password) {
    $salt = calculate_salt($account_name);
    return digest("{$password}:{$salt}");
}

// --------

$app->get('/initialize', function (Request $request, Response $response) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $this->get('helper')->db_initialize();
    return $response;
});

$app->get('/login', function (Request $request, Response $response) {
    if ($this->get('helper')->get_session_user() !== null) {
        return redirect($response, '/', 302);
    }
    return $this->get('view')->render($response, 'login.php', [
        'me' => null,
        'flash' => $this->get('flash')->getFirstMessage('notice'),
    ]);
});

$app->post('/login', function (Request $request, Response $response) {
    if ($this->get('helper')->get_session_user() !== null) {
        return redirect($response, '/', 302);
    }

    $params = $request->getParsedBody();
    $user = $this->get('helper')->try_login($params['account_name'], $params['password']);

    if ($user) {
        $_SESSION['user'] = [
            'id' => $user['id'],
        ];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
        return redirect($response, '/', 302);
    } else {
        $this->get('flash')->addMessage('notice', 'アカウント名かパスワードが間違っています');
        return redirect($response, '/login', 302);
    }
});

$app->get('/register', function (Request $request, Response $response) {
    if ($this->get('helper')->get_session_user() !== null) {
        return redirect($response, '/', 302);
    }
    return $this->get('view')->render($response, 'register.php', [
        'me' => null,
        'flash' => $this->get('flash')->getFirstMessage('notice'),
    ]);
});


$app->post('/register', function (Request $request, Response $response) {
    if ($this->get('helper')->get_session_user()) {
        return redirect($response, '/', 302);
    }

    $params = $request->getParsedBody();
    $account_name = $params['account_name'];
    $password = $params['password'];

    $validated = validate_user($account_name, $password);
    if (!$validated) {
        $this->get('flash')->addMessage('notice', 'アカウント名は3文字以上、パスワードは6文字以上である必要があります');
        return redirect($response, '/register', 302);
    }

    // account_nameはusers.UNIQUE KEYで一意性が保証されているため、事前SELECTは不要。
    // INSERT自体の重複キー例外(SQLSTATE 23000)で重複を検出する。
    $db = $this->get('db');
    $ps = $db->prepare('INSERT INTO `users` (`account_name`, `passhash`) VALUES (?,?)');
    try {
        $ps->execute([
            $account_name,
            calculate_passhash($account_name, $password)
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            $this->get('flash')->addMessage('notice', 'アカウント名がすでに使われています');
            return redirect($response, '/register', 302);
        }
        throw $e;
    }
    $_SESSION['user'] = [
        'id' => $db->lastInsertId(),
    ];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    return redirect($response, '/', 302);
});

$app->get('/logout', function (Request $request, Response $response) {
    unset($_SESSION['user']);
    unset($_SESSION['csrf_token']);
    return redirect($response, '/', 302);
});

$app->get('/', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    $db = $this->get('db');
    $ps = $db->prepare('SELECT STRAIGHT_JOIN `p`.`id`, `p`.`user_id`, `p`.`body`, `p`.`mime`, `p`.`created_at`,
               `u`.`account_name` AS `post_user_account_name`, `u`.`del_flg` AS `post_user_del_flg`
        FROM `posts` `p` FORCE INDEX (`idx_created_at`) JOIN `users` `u` ON `u`.`id` = `p`.`user_id`
        WHERE `u`.`del_flg` = 0
        ORDER BY `p`.`created_at` DESC
        LIMIT ' . POSTS_PER_PAGE);
    $ps->execute();
    $results = $ps->fetchAll(PDO::FETCH_ASSOC);
    $posts = $this->get('helper')->make_posts($results);

    return $this->get('view')->render($response, 'index.php', [
        'posts' => $posts,
        'me' => $me,
        'flash' => $this->get('flash')->getFirstMessage('notice'),
    ]);
});

$app->get('/posts', function (Request $request, Response $response) {
    $params = $request->getQueryParams();
    $max_created_at = $params['max_created_at'] ?? null;
    $db = $this->get('db');
    $ps = $db->prepare('SELECT STRAIGHT_JOIN `p`.`id`, `p`.`user_id`, `p`.`body`, `p`.`mime`, `p`.`created_at`,
               `u`.`account_name` AS `post_user_account_name`, `u`.`del_flg` AS `post_user_del_flg`
        FROM `posts` `p` FORCE INDEX (`idx_created_at`) JOIN `users` `u` ON `u`.`id` = `p`.`user_id`
        WHERE `u`.`del_flg` = 0 AND `p`.`created_at` <= ?
        ORDER BY `p`.`created_at` DESC
        LIMIT ' . POSTS_PER_PAGE);
    $ps->execute([$max_created_at === null ? null : $max_created_at]);
    $results = $ps->fetchAll(PDO::FETCH_ASSOC);
    $posts = $this->get('helper')->make_posts($results);

    return $this->get('view')->render($response, 'posts.php', ['posts' => $posts]);
});

$app->get('/posts/{id}', function (Request $request, Response $response, $args) {
    $db = $this->get('db');
    $ps = $db->prepare('SELECT `p`.`id`, `p`.`user_id`, `p`.`body`, `p`.`mime`, `p`.`created_at`,
                               `u`.`account_name` AS `post_user_account_name`, `u`.`del_flg` AS `post_user_del_flg`
                        FROM `posts` `p` JOIN `users` `u` ON `u`.`id` = `p`.`user_id` WHERE `p`.`id` = ?');
    $ps->execute([$args['id']]);
    $results = $ps->fetchAll(PDO::FETCH_ASSOC);
    $posts = $this->get('helper')->make_posts($results, ['all_comments' => true]);

    if (count($posts) == 0) {
        $response->getBody()->write('404');
        return $response->withStatus(404);
    }

    $post = $posts[0];

    $me = $this->get('helper')->get_session_user();

    return $this->get('view')->render($response, 'post.php', ['post' => $post, 'me' => $me]);
});

$app->post('/', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    if ($me === null) {
        return redirect($response, '/', 302);
    }

    $params = $request->getParsedBody();
    if (($params['csrf_token'] ?? null) !== $_SESSION['csrf_token']) {
        $response->getBody()->write('422');
        return $response->withStatus(422);
    }

    if ($_FILES['file']) {
        $mime = '';
        // 投稿のContent-Typeからファイルのタイプを決定する
        if (strpos($_FILES['file']['type'], 'jpeg') !== false) {
            $mime = 'image/jpeg';
        } elseif (strpos($_FILES['file']['type'], 'png') !== false) {
            $mime = 'image/png';
        } elseif (strpos($_FILES['file']['type'], 'gif') !== false) {
            $mime = 'image/gif';
        } else {
            $this->get('flash')->addMessage('notice', '投稿できる画像形式はjpgとpngとgifだけです');
            return redirect($response, '/', 302);
        }

        if ($_FILES['file']['size'] > UPLOAD_LIMIT) {
            $this->get('flash')->addMessage('notice', 'ファイルサイズが大きすぎます');
            return redirect($response, '/', 302);
        }

        $imgdata = file_get_contents($_FILES['file']['tmp_name']);

        $db = $this->get('db');
        $query = 'INSERT INTO `posts` (`user_id`, `mime`, `imgdata`, `body`) VALUES (?,?,?,?)';
        $ps = $db->prepare($query);
        $ps->execute([
          $me['id'],
          $mime,
          $imgdata,
          $params['body'],
        ]);
        $pid = $db->lastInsertId();
        $this->get('helper')->mc()->delete('uc:' . $me['id']);
        return redirect($response, "/posts/{$pid}", 302);
    } else {
        $this->get('flash')->addMessage('notice', '画像が必須です');
        return redirect($response, '/', 302);
    }
});

$app->get('/image/{id}.{ext}', function (Request $request, Response $response, $args) {
    $image_id = $args['id'];
    if ($image_id == 0) {
        return $response;
    }

    // 画像はimmutable（UPDATE経路なし、DELETEはid閾値超過分のみでAUTO_INCREMENTのためid再利用もない）
    // なのでid+extから決定的にETagを算出できる。一致すればDBに触れず304で返す。
    $etag = '"i-' . $image_id . '-' . $args['ext'] . '"';
    if ($request->getHeaderLine('If-None-Match') === $etag) {
        return $response
            ->withStatus(304)
            ->withHeader('Cache-Control', 'public, max-age=86400')
            ->withHeader('ETag', $etag);
    }

    $post = $this->get('helper')->fetch_first('SELECT `mime`, `imgdata` FROM `posts` WHERE `id` = ?', $image_id);

    if (($args['ext'] == 'jpg' && $post['mime'] == 'image/jpeg') ||
        ($args['ext'] == 'png' && $post['mime'] == 'image/png') ||
        ($args['ext'] == 'gif' && $post['mime'] == 'image/gif')) {
        $response->getBody()->write($post['imgdata']);
        return $response
            ->withHeader('Content-Type', $post['mime'])
            ->withHeader('Cache-Control', 'public, max-age=86400')
            ->withHeader('ETag', $etag);
    }
    $response->getBody()->write('404');
    return $response->withStatus(404);
});

$app->post('/comment', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    if ($me === null) {
        return redirect($response, '/', 302);
    }

    $params = $request->getParsedBody();
    if (($params['csrf_token'] ?? null) !== $_SESSION['csrf_token']) {
        $response->getBody()->write('422');
        return $response->withStatus(422);
    }

    if (!ctype_digit($params['post_id'])) {
        $response->getBody()->write('post_idは整数のみです');
        return $response;
    }
    $post_id = $params['post_id'];

    $db = $this->get('db');
    $query = 'INSERT INTO `comments` (`post_id`, `user_id`, `comment`) VALUES (?,?,?)';
    $ps = $db->prepare($query);
    $ps->execute([
        $post_id,
        $me['id'],
        $params['comment']
    ]);
    $mc = $this->get('helper')->mc();
    $mc->delete('c3:' . $post_id);
    $mc->delete('uc:' . $me['id']);
    $owner_id = $this->get('helper')->fetch_post_owner_id($post_id);
    if ($owner_id !== null) {
        $mc->delete('uc:' . $owner_id);
    }

    return redirect($response, "/posts/{$post_id}", 302);
});

$app->get('/admin/banned', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    if ($me === null) {
        return redirect($response, '/login', 302);
    }

    if ($me['authority'] == 0) {
        $response->getBody()->write('403');
        return $response->withStatus(403);
    }

    $db = $this->get('db');
    $ps = $db->prepare('SELECT `id`, `account_name` FROM `users` WHERE `authority` = 0 AND `del_flg` = 0 ORDER BY `created_at` DESC');
    $ps->execute();
    $users = $ps->fetchAll(PDO::FETCH_ASSOC);

    return $this->get('view')->render($response, 'banned.php', ['users' => $users, 'me' => $me]);
});

$app->post('/admin/banned', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    if ($me === null) {
        return redirect($response, '/', 302);
    }

    if ($me['authority'] == 0) {
        $response->getBody()->write('403');
        return $response->withStatus(403);
    }

    $params = $request->getParsedBody();
    if (($params['csrf_token'] ?? null) !== $_SESSION['csrf_token']) {
        $response->getBody()->write('422');
        return $response->withStatus(422);
    }

    $db = $this->get('db');
    $mc = $this->get('helper')->mc();
    $query = 'UPDATE `users` SET `del_flg` = ? WHERE `id` = ?';
    $ps = $db->prepare($query);
    foreach ($params['uid'] as $id) {
        $account_name = $this->get('helper')->fetch_first('SELECT `account_name` FROM `users` WHERE `id` = ?', $id)['account_name'] ?? null;
        $ps->execute([1, $id]);
        $mc->delete('u:' . $id);
        if ($account_name !== null) {
            $mc->delete('anx:' . $account_name);
        }
    }

    return redirect($response, '/admin/banned', 302);
});

$app->get('/@{account_name}', function (Request $request, Response $response, $args) {
    $db = $this->get('db');
    $helper = $this->get('helper');
    // try_login()と同一のanx:{account_name}キャッシュを共有する（fetch_user_by_account_name参照）。
    $user = $helper->fetch_user_by_account_name($args['account_name']);

    if (!$user) {
        $response->getBody()->write('404');
        return $response->withStatus(404);
    }

    $ps = $db->prepare('SELECT `p`.`id`, `p`.`user_id`, `p`.`body`, `p`.`created_at`, `p`.`mime`,
                               `u`.`account_name` AS `post_user_account_name`, `u`.`del_flg` AS `post_user_del_flg`
                        FROM `posts` `p` JOIN `users` `u` ON `u`.`id` = `p`.`user_id`
                        WHERE `p`.`user_id` = ? ORDER BY `p`.`created_at` DESC LIMIT ' . POSTS_PER_PAGE);
    $ps->execute([$user['id']]);
    $results = $ps->fetchAll(PDO::FETCH_ASSOC);
    $posts = $this->get('helper')->make_posts($results);

    // 投稿数/comment数/被comment数はuser_id単位でmemcachedへcache-aside。
    // 該当userの新規投稿・新規comment・自分の投稿への被commentの3経路でdelete-on-write。
    $mc = $this->get('helper')->mc();
    $counts_key = 'uc:' . $user['id'];
    $counts = $mc->get($counts_key);
    if ($counts === false) {
        $counts = $this->get('helper')->fetch_first(
            'SELECT (SELECT COUNT(*) FROM `comments` WHERE `user_id` = ?) AS `comment_count`,
                    (SELECT COUNT(*) FROM `posts` WHERE `user_id` = ?) AS `post_count`,
                    (SELECT COUNT(*) FROM `comments` `c` JOIN `posts` `p` ON `p`.`id` = `c`.`post_id` WHERE `p`.`user_id` = ?) AS `commented_count`',
            $user['id'], $user['id'], $user['id']
        );
        $mc->set($counts_key, $counts, 3600);
    }
    $comment_count = $counts['comment_count'];
    $post_count = $counts['post_count'];
    $commented_count = $counts['commented_count'];

    $me = $this->get('helper')->get_session_user();

    // fetch_user_by_account_name()はtry_login()と共有するためpasshashを含むが、
    // view層には渡さない(表示に不要な機微情報をテンプレートへ流さない)。
    $profile_user = ['id' => $user['id'], 'account_name' => $user['account_name']];

    return $this->get('view')->render($response, 'user.php', ['posts' => $posts, 'user' => $profile_user, 'post_count' => $post_count, 'comment_count' => $comment_count, 'commented_count'=> $commented_count, 'me' => $me]);
});

$app->run();

<?php
require 'db_connect.php';
require 'stats_functions.php';
session_start();

// -------------------------- 新增：自动读取Cookie填充用户名 --------------------------
$remembered_username = ''; // 存储记住的用户名
// 检查是否存在有效的"记住我"Cookie（名称可自定义，此处为'remember_me'）
if (isset($_COOKIE['remember_me']) && !isset($_SESSION['user_id'])) {
    // Cookie格式：user_id:加密令牌（避免直接存储明文信息）
    $cookie_parts = explode(':', $_COOKIE['remember_me']);
    // 验证Cookie格式合法性（必须包含2个部分：user_id和令牌）
    if (count($cookie_parts) === 2) {
        $user_id = (int)$cookie_parts[0];
        $stored_token = $cookie_parts[1];
        
        try {
            // 从数据库查询用户信息（需确保users表有'remember_token'字段存储令牌）
            $stmt = $pdo->prepare("SELECT id, username, is_admin, is_banned, remember_token FROM users WHERE id = :id");
            $stmt->bindParam(':id', $user_id);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // 验证用户存在、未被封禁且令牌匹配
            if ($user && !$user['is_banned'] && hash_equals($user['remember_token'], $stored_token)) {
                // 自动恢复登录状态
                updateUserActivity($user['id']);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['is_admin'] = $user['is_admin'];
                header("Location: index.php");
                exit;
            } else {
                // 令牌无效或用户异常，清除无效Cookie
                setcookie('remember_me', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
            }
        } catch (PDOException $e) {
            // 数据库错误时清除Cookie
            setcookie('remember_me', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
        }
    }
}

// 如果用户已登录，重定向到首页（原有逻辑保留）
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    // -------------------------- 新增：获取"记住我"勾选状态 --------------------------
    $remember_me = isset($_POST['remember_me']) ? true : false;
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username");
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // 检查用户是否被封禁（原有逻辑保留）
            if ($user['is_banned']) {
                $error = "您的账户已被封禁，无法登录。如有疑问，请联系管理员。";
            } 
            // 验证密码（原有逻辑保留）
            elseif (password_verify($password, $user['password'])) {
                // 登录成功，更新最后活动时间（原有逻辑保留）
                updateUserActivity($user['id']);
                
                // 设置会话变量（原有逻辑保留）
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['is_admin'] = $user['is_admin'];
                
                // -------------------------- 新增：处理"记住我"逻辑 --------------------------
                if ($remember_me) {
                    // 1. 生成随机令牌（用于验证Cookie合法性，避免伪造）
                    $remember_token = bin2hex(random_bytes(32)); // 生成64位随机字符串
                    
                    // 2. 更新数据库存储令牌（需确保users表已添加'remember_token'字段）
                    $update_stmt = $pdo->prepare("UPDATE users SET remember_token = :token WHERE id = :user_id");
                    $update_stmt->bindParam(':token', $remember_token);
                    $update_stmt->bindParam(':user_id', $user['id']);
                    $update_stmt->execute();
                    
                    // 3. 设置长期Cookie（有效期30天，可自定义；格式：user_id:令牌）
                    $cookie_value = $user['id'] . ':' . $remember_token;
                    setcookie(
                        'remember_me',          // Cookie名称
                        $cookie_value,          // Cookie值（用户ID+令牌）
                        time() + 30 * 24 * 3600, // 有效期：30天（秒数）
                        '/',                    // 作用域：整个网站
                        '',                     // 域名（生产环境需填写，如'.syphotos.com'）
                        isset($_SERVER['HTTPS']), // 仅HTTPS传输（生产环境建议启用）
                        true                    // 仅HTTP访问（禁止JS读取，防XSS）
                    );
                } else {
                    // 用户未勾选"记住我"，清除已有Cookie和数据库令牌
                    if (isset($_COOKIE['remember_me'])) {
                        // 清除Cookie
                        setcookie('remember_me', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
                        // 清除数据库令牌
                        $clear_stmt = $pdo->prepare("UPDATE users SET remember_token = NULL WHERE id = :user_id");
                        $clear_stmt->bindParam(':user_id', $user['id']);
                        $clear_stmt->execute();
                    }
                }
                
                // 重定向到首页（原有逻辑保留）
                header("Location: index.php");
                exit;
            } else {
                $error = "用户名或密码不正确";
            }
        } else {
            $error = "用户名或密码不正确";
        }
    } catch (PDOException $e) {
        $error = "登录失败: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <!-- 头部代码与原有一致，无需修改 -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>syphotos航空摄影平台 - 登录</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* 原有CSS样式保留，新增"记住我"选项的样式 */
        :root {
            --primary: #165DFF;
            --primary-light: #4080FF;
            --primary-dark: #0E42D2;
            --secondary: #6B7280;
            --success: #10B981;
            --danger: #EF4444;
            --light-bg: #F9FAFB;
            --white: #FFFFFF;
            --border: #E5E7EB;
            --shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-hover: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --radius: 8px;
            --transition: all 0.3s ease;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body { 
            font-family: 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', sans-serif; 
            background-color: var(--light-bg); 
            color: #1F2937;
            line-height: 1.6;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
            background-image: 
                radial-gradient(circle at 10% 20%, rgba(22, 93, 255, 0.05) 0%, transparent 20%),
                radial-gradient(circle at 90% 80%, rgba(22, 93, 255, 0.05) 0%, transparent 20%);
        }
        
        .login-container {
            width: 100%;
            max-width: 420px;
            position: relative;
        }
        
        .login-card {
            background-color: var(--white);
            padding: 3rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary) 0%, var(--primary-light) 100%);
        }
        
        .login-card:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }
        
        .brand {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 2.5rem;
        }
        
        .brand-logo {
            color: var(--primary);
            font-size: 2.2rem;
            margin-right: 10px;
        }
        
        .brand-name {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-dark);
        }
        
        h1 {
            color: #1F2937;
            text-align: center;
            margin-bottom: 2rem;
            font-size: 1.8rem;
            font-weight: 600;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #4B5563;
            font-size: 0.95rem;
        }
        
        .form-control {
            width: 100%;
            padding: 0.9rem 1rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 1rem;
            transition: var(--transition);
            background-color: var(--white);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(22, 93, 255, 0.1);
        }
        
        .input-group {
            position: relative;
        }
        
        .input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary);
        }
        
        .input-icon + .form-control {
            padding-left: 2.5rem;
        }
        
        .btn {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 0.9rem 1rem;
            width: 100%;
            cursor: pointer;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 500;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-1px);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .alert {
            padding: 0.8rem 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .alert-error {
            background-color: #FEF2F2;
            color: var(--danger);
            border: 1px solid #FEE2E2;
        }
        
        .links {
            display: flex;
            justify-content: space-between;
            margin: 1rem 0 1.5rem;
            font-size: 0.9rem;
        }
        
        .link {
            color: var(--primary);
            text-decoration: none;
            transition: var(--transition);
        }
        
        .link:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }
        
        .register-section {
            text-align: center;
            margin-top: 2rem;
            font-size: 0.95rem;
            color: #4B5563;
        }
        
        .register-link {
            color: var(--primary);
            font-weight: 500;
            text-decoration: none;
            transition: var(--transition);
        }
        
        .register-link:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }
        
        .divider {
            display: flex;
            align-items: center;
            margin: 1.5rem 0;
        }
        
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background-color: var(--border);
        }
        
        .divider-text {
            padding: 0 1rem;
            font-size: 0.85rem;
            color: var(--secondary);
        }
        
        .social-login {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .social-btn {
            flex: 1;
            padding: 0.8rem;
            border: 1px solid var(--border);
            background-color: var(--white);
            border-radius: 6px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none; /* 新增：清除a标签默认下划线 */
        }
        
        .social-btn:hover {
            background-color: var(--light-bg);
            transform: translateY(-1px);
        }
        
        .social-icon {
            font-size: 1.2rem;
            color: #4B5563;
        }

        /* -------------------------- 新增："记住我"选项样式 -------------------------- */
        .remember-me {
            display: flex;
            align-items: center;
            margin: 1rem 0;
            font-size: 0.9rem;
            color: #4B5563;
        }
        
        .remember-me input[type="checkbox"] {
            margin-right: 8px;
            accent-color: var(--primary); /* 复选框选中颜色与主题一致 */
            cursor: pointer;
        }
        
        .remember-me label {
            cursor: pointer;
            transition: color var(--transition);
        }
        
        .remember-me label:hover {
            color: var(--primary);
        }
        
        @media (max-width: 480px) {
            .login-card {
                padding: 2rem 1.5rem;
            }
            
            h1 {
                font-size: 1.5rem;
            }
            
            .social-login {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="brand">
                <i class="fas fa-plane brand-logo"></i>
                <span class="brand-name">syphotos</span>
            </div>
            
            <h1>欢迎回来</h1>
            
            <?php if($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <form method="post">
                <div class="form-group">
                    <label for="username" class="form-label">用户名</label>
                    <div class="input-group">
                        <i class="fas fa-user input-icon"></i>
                        <!-- -------------------------- 新增：自动填充记住的用户名 -------------------------- -->
                        <input type="text" id="username" name="username" class="form-control" 
                               value="<?php echo htmlspecialchars($remembered_username); ?>" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password" class="form-label">密码</label>
                    <div class="input-group">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" id="password" name="password" class="form-control" required>
                    </div>
                </div>
                
                <!-- -------------------------- 新增："记住我"复选框 -------------------------- -->
                <div class="remember-me">
                    <input type="checkbox" id="remember_me" name="remember_me" 
                           <?php echo isset($_COOKIE['remember_me']) ? 'checked' : ''; ?>>
                    <label for="remember_me">记住我（30天内自动登录）</label>
                </div>
                
                <div class="links">
                    <a href="forgot_password.php" class="link">忘记密码?</a>
                </div>
                
                <button type="submit" class="btn">
                    <i class="fas fa-sign-in-alt"></i>
                    登录账户
                </button>
            </form>
            
            <div class="divider">
                <span class="divider-text">或者</span>
            </div>
            
            <!-- -------------------------- 关键修改：跳转至聚合登录入口 login_redirect.php -------------------------- -->
            <div class="social-login">
                <!-- 微信登录 -->
                <a href="login_redirect.php?login_type=wechat" class="social-btn" title="微信登录">
                    <i class="fab fa-weixin social-icon"></i>
                </a>
                <!-- QQ登录 -->
                <a href="login_redirect.php?login_type=qq" class="social-btn" title="QQ登录">
                    <i class="fab fa-qq social-icon"></i>
                </a>
                <!-- 微博登录 -->
                <a href="login_redirect.php?login_type=sina" class="social-btn" title="微博登录">
                    <i class="fab fa-weibo social-icon"></i>
                </a>
            </div>
            
            <div class="register-section">
                还没有账号? 
                <a href="register.php" class="register-link">立即注册</a>
            </div>
        </div>
    </div>
</body>
</html>
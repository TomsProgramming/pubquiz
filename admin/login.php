<?php
require_once 'auth.php';

define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD', 'pubquiz2026');

redirectIfLoggedIn();

$error = '';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($username === ADMIN_USERNAME && $password === ADMIN_PASSWORD) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        header('Location: index.php');
        exit;
    } else {
        $error = 'Gebruikersnaam of wachtwoord is onjuist';
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - PubQuiz</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', sans-serif;
            background: #ffffff;
            color: #000000;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            width: 100%;
            max-width: 400px;
        }

        .login-header {
            text-align: center;
            margin-bottom: 60px;
        }

        .login-header h1 {
            font-size: 32px;
            font-weight: 300;
            letter-spacing: 0.5px;
            margin-bottom: 20px;
        }

        .login-header p {
            font-size: 14px;
            color: #666;
            font-weight: 300;
        }

        .login-form {
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
            padding: 40px 0;
        }

        .form-group {
            margin-bottom: 30px;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 500;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 10px;
            color: #000;
        }

        .form-group input {
            width: 100%;
            padding: 12px 0;
            border: none;
            border-bottom: 1px solid #000;
            font-size: 14px;
            font-family: inherit;
            background: transparent;
            color: #000;
        }

        .form-group input:focus {
            outline: none;
            border-bottom: 2px solid #000;
        }
        .form-group input::placeholder {
            color: #999;
        }

        .error-message {
            background: #ffebee;
            border-left: 2px solid #c62828;
            color: #c62828;
            padding: 12px;
            margin-bottom: 20px;
            font-size: 13px;
        }

        .submit-btn {
            width: 100%;
            padding: 14px;
            margin-top: 30px;
            background: #000;
            color: #fff;
            border: none;
            font-size: 12px;
            font-weight: 500;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            cursor: pointer;
            transition: background 0.2s;
        }

        .submit-btn:hover {
            background: #333;
        }

        .submit-btn:active {
            background: #000;
        }

        .login-footer {
            text-align: center;
            margin-top: 60px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
        }

        .login-footer a {
            font-size: 12px;
            color: #666;
            text-decoration: none;
            font-weight: 300;
        }

        .login-footer a:hover {
            color: #000;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>PubQuiz</h1>
            <p>Admin Dashboard</p>
        </div>

        <form method="POST" class="login-form">
            <?php if ($error): ?>
                <div class="error-message">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="username">Gebruikersnaam</label>
                <input type="text" id="username" name="username" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Wachtwoord</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit" class="submit-btn">Inloggen</button>
        </form>

        <div class="login-footer">
            <a href="../index.php">← Terug naar Leaderboard</a>
        </div>
    </div>
</body>
</html>

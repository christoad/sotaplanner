<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
session_start();
requireLogin();

$db = getDbConnection();
$current_user = getCurrentCallsign();

$message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update user information
    $stmt = $db->prepare("
        UPDATE users SET
            name = ?,
            home_address = ?
        WHERE callsign = ?
    ");
    $stmt->execute([
        $_POST['name'],
        $_POST['home_address'],
        $current_user
    ]);
    
    // Update or insert user settings
    $stmt = $db->prepare("
        INSERT INTO user_settings (user_callsign, default_activation_time_min)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE
        default_activation_time_min = VALUES(default_activation_time_min)
    ");
    $stmt->execute([
        $current_user,
        $_POST['default_activation_time']
    ]);
    
    $message = "Settings saved successfully!";
}

// Fetch current user data
$stmt = $db->prepare("SELECT * FROM users WHERE callsign = ?");
$stmt->execute([$current_user]);
$user = $stmt->fetch();

// Fetch user settings
$stmt = $db->prepare("SELECT * FROM user_settings WHERE user_callsign = ?");
$stmt->execute([$current_user]);
$settings = $stmt->fetch();

$default_activation_time = $settings['default_activation_time_min'] ?? 60;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - SOTA Planner</title>
    <link href="https://fonts.googleapis.com/css2?family=Overpass:wght@300;600;800&family=Courier+Prime:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --peak-brown: #6B4423;
            --trail-green: #4A7C59;
            --forest-dark: #2C4A3E;
            --summit-gold: #E6B84A;
            --snow-white: #F5F5F0;
            --earth-tan: #D4A574;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Overpass', sans-serif;
            background: linear-gradient(135deg, #F5F5F0 0%, #E8E4D8 100%);
            color: var(--forest-dark);
            min-height: 100vh;
            padding: 2rem;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            color: white;
            background: var(--trail-green);
            border: 2px solid var(--trail-green);
            text-decoration: none;
            font-weight: 700;
            font-size: 0.95rem;
            padding: 0.55rem 1.2rem;
            border-radius: 8px;
            transition: opacity 0.2s;
            margin-bottom: 1.5rem;
        }
        .back-link:hover {
            opacity: 0.85;
        }

        h1 {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--peak-brown);
            margin-bottom: 2rem;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .message {
            padding: 1rem;
            background: #E6F4EA;
            color: #1E7E34;
            border-left: 4px solid #1E7E34;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--forest-dark);
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.05em;
        }

        input[type="text"],
        input[type="number"],
        textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--earth-tan);
            border-radius: 6px;
            font-family: 'Overpass', sans-serif;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        input:focus,
        textarea:focus {
            outline: none;
            border-color: var(--trail-green);
            box-shadow: 0 0 0 3px rgba(74, 124, 89, 0.1);
        }

        textarea {
            resize: vertical;
            min-height: 100px;
        }

        .helper-text {
            font-size: 0.85rem;
            color: #666;
            margin-top: 0.5rem;
            font-style: italic;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            background: linear-gradient(135deg, var(--trail-green) 0%, var(--forest-dark) 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-link">← Back to Dashboard</a>
        
        <h1>⚙️ User Settings</h1>

        <?php if ($message): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="card">
            <form method="POST">
                <div class="form-group">
                    <label for="callsign">Callsign</label>
                    <input 
                        type="text" 
                        id="callsign" 
                        value="<?= htmlspecialchars($current_user) ?>"
                        disabled
                        style="opacity: 0.6; cursor: not-allowed;"
                    >
                    <p class="helper-text">Your callsign cannot be changed</p>
                </div>

                <div class="form-group">
                    <label for="name">Name</label>
                    <input 
                        type="text" 
                        id="name" 
                        name="name"
                        value="<?= htmlspecialchars($user['name'] ?? '') ?>"
                        placeholder="Your name"
                    >
                </div>

                <div class="form-group">
                    <label for="home_address">Home Address</label>
                    <textarea 
                        id="home_address" 
                        name="home_address"
                        placeholder="1234 Main St, City, State 12345"
                    ><?= htmlspecialchars($user['home_address'] ?? '') ?></textarea>
                    <p class="helper-text">Used to calculate drive times to trailheads</p>
                </div>

                <div class="form-group">
                    <label for="default_activation_time">Default Activation Time (minutes)</label>
                    <input 
                        type="number" 
                        id="default_activation_time" 
                        name="default_activation_time"
                        value="<?= $default_activation_time ?>"
                        min="15"
                        max="300"
                        step="15"
                        required
                    >
                    <p class="helper-text">How long you typically spend activating a summit (default: 60 minutes)</p>
                </div>

                <button type="submit" class="btn">Save Settings</button>
            </form>
        </div>
    </div>
<footer style="text-align:center; padding:2rem 1rem 1.5rem; color:#aaa; font-size:0.78rem;">
    SOTA Planner &nbsp;·&nbsp; <a href="changelog.php" style="color:#aaa; text-decoration:none;">v<?= APP_VERSION ?></a> &nbsp;·&nbsp; <a href="https://sotaplanner.com" style="color:#aaa; text-decoration:none;">sotaplanner.com</a>
</footer>
</body>
</html>

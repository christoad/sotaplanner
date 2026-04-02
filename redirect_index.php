<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SOTA Planner — We've Moved</title>
    <link href="https://fonts.googleapis.com/css2?family=Overpass:wght@300;600;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Overpass', sans-serif;
            background: linear-gradient(135deg, #F5F5F0 0%, #E8E4D8 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.12);
            padding: 3rem 2.5rem;
            max-width: 480px;
            width: 100%;
            text-align: center;
        }

        .logo {
            height: 80px;
            margin-bottom: 1.5rem;
        }

        h1 {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1E3A5F;
            margin-bottom: 0.5rem;
        }

        .tagline {
            font-size: 0.85rem;
            color: #999;
            margin-bottom: 1.75rem;
        }

        .moved-badge {
            display: block;
            background: #E8F4F8;
            color: #2C5F7A;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 0.35rem 0.85rem;
            border-radius: 20px;
            margin: 0 auto 1.25rem;
            width: fit-content;
        }

        p {
            font-size: 0.95rem;
            color: #555;
            line-height: 1.6;
            margin-bottom: 2rem;
        }

        p strong {
            color: #1E3A5F;
        }

        .btn {
            display: inline-block;
            padding: 0.85rem 2.25rem;
            background: linear-gradient(135deg, #4A7C59 0%, #1E3A5F 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 700;
            font-size: 1rem;
            letter-spacing: 0.04em;
            transition: transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.2);
        }

        .new-url {
            margin-top: 1.25rem;
            font-size: 0.8rem;
            color: #aaa;
        }

        .new-url a {
            color: #4A90A4;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="card">
        <img src="logo.png" alt="SOTA Planner" class="logo">
        <div class="moved-badge">We've Moved</div>
        <h1>SOTA Planner has a new home</h1>
        <p class="tagline">Doorstep to doorstep planning for busy activators</p>
        <p>
            This tool has moved to its own dedicated domain.<br>
            Head to <strong>SOTAplanner.com</strong> to plan your next activation.
        </p>
        <a href="https://sotaplanner.com" class="btn">🏔 Go to SOTAplanner.com</a>
        <div class="new-url"><a href="https://sotaplanner.com">sotaplanner.com</a></div>
    </div>
</body>
</html>

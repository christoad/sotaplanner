<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome - SOTA Planner</title>
    <link href="https://fonts.googleapis.com/css2?family=Overpass:wght@300;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #1E3A5F;
            --teal: #9B6328;
            --gold: #E6B84A;
            --snow: #F5F5F0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Overpass', sans-serif;
            background: linear-gradient(135deg, var(--snow) 0%, #E8E4D8 100%);
            color: var(--navy);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .welcome-container {
            max-width: 700px;
            text-align: center;
        }

        .logo {
            height: 150px;
            margin-bottom: 2rem;
        }

        h1 {
            font-size: 3rem;
            font-weight: 800;
            color: var(--navy);
            margin-bottom: 1rem;
        }

        .subtitle {
            font-size: 1.3rem;
            color: #666;
            margin-bottom: 3rem;
            line-height: 1.6;
        }

        .cta-button {
            display: inline-block;
            padding: 1.5rem 3rem;
            background: linear-gradient(135deg, var(--teal) 0%, var(--navy) 100%);
            color: white;
            text-decoration: none;
            border-radius: 12px;
            font-size: 1.3rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            transition: all 0.3s;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
        }

        .cta-button:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.25);
        }

        .info-box {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            margin-top: 3rem;
            text-align: left;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .info-box h2 {
            color: var(--navy);
            margin-bottom: 1rem;
            font-size: 1.5rem;
        }

        .info-box ul {
            list-style: none;
            padding: 0;
        }

        .info-box li {
            padding: 0.75rem 0;
            padding-left: 1.5rem;
            position: relative;
            line-height: 1.6;
        }

        .info-box li:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: var(--teal);
            font-weight: 700;
        }
    </style>
</head>
<body>
    <div class="welcome-container">
        <img src="sota-planner-logo.svg" alt="SOTA Planner" class="logo">
        
        <h1>Welcome to SOTA Planner</h1>
        
        <p class="subtitle">
            Make a planning group for you and your friends.<br>
            Nominate summits to activate and add planning data.<br>
            Your work is independent of other groups.
        </p>

        <p style="color: var(--teal); font-weight: 600; font-size: 1.1rem; margin-bottom: 2rem;">
            No signup or email required. Ready to use as-is.
        </p>

        <a href="planning_groups.php" class="cta-button">
            🚀 Create or Choose Planning Group
        </a>

        <div class="info-box">
            <h2>How It Works</h2>
            <ol style="margin-left: 1.5rem; line-height: 1.8;">
                <li><strong>Create a planning group</strong> for your activation team</li>
                <li><strong>Nominate summits</strong> you want to activate</li>
                <li><strong>Research trails</strong> - add distance, gain, trailhead info</li>
                <li><strong>Track activations</strong> - mark when completed</li>
                <li><strong>Plan together</strong> - everyone in your group sees the same data</li>
            </ol>
            <p style="margin-top: 1rem; color: #666;">
                Each group is completely independent. Your nominations won't appear in other groups' dashboards.
            </p>
        </div>
    </div>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About - SOTA Planner</title>
    <link href="https://fonts.googleapis.com/css2?family=Overpass:wght@300;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #1E3A5F;
            --teal: #4A90A4;
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
            padding: 2rem;
            line-height: 1.6;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
        }

        header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .logo {
            height: 80px;
        }

        .back-link {
            color: var(--teal);
            text-decoration: none;
            font-weight: 600;
        }

        .content-card {
            background: white;
            padding: 3rem;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        h1 {
            color: var(--navy);
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 1.5rem;
        }

        h2 {
            color: var(--navy);
            font-size: 1.8rem;
            font-weight: 700;
            margin-top: 2rem;
            margin-bottom: 1rem;
        }

        p {
            margin-bottom: 1.5rem;
            font-size: 1.1rem;
        }

        .highlight {
            background: linear-gradient(135deg, var(--teal) 0%, var(--navy) 100%);
            color: white;
            padding: 2rem;
            border-radius: 8px;
            margin: 2rem 0;
        }

        .highlight h2 {
            color: white;
            margin-top: 0;
        }

        a {
            color: var(--teal);
            font-weight: 600;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .btn {
            display: inline-block;
            padding: 1rem 2rem;
            background: linear-gradient(135deg, var(--teal) 0%, var(--navy) 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-top: 2rem;
            transition: all 0.3s;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
            text-decoration: none;
        }

        .footer {
            text-align: center;
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 2px solid var(--snow);
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <img src="logo.png" alt="SOTA Planner" class="logo">
            <a href="index.php" class="back-link">← Back to Dashboard</a>
        </header>

        <div class="content-card">
            <h1>About SOTA Planner</h1>
            
            <p>
                <strong>SOTA Planner</strong> was created to help busy radio amateurs plan their SOTA (Summits On The Air) activations with a clear understanding of the <strong>total time commitment, doorstep to doorstep</strong>.
            </p>

            <div class="highlight">
                <h2>The Problem</h2>
                <p style="margin-bottom: 0;">
                    Planning a SOTA activation involves piecing together information from multiple sources: drive time, hike distance, elevation gain, time on summit, and the return journey. It's hard to know if you have 3 hours or 8 hours before you even start.
                </p>
            </div>

            <h2>The Solution</h2>
            <p>
                SOTA Planner brings everything together in one place. Set your drive start address, nominate summits you're interested in, research the trails, and instantly see your total time commitment for each activation.
            </p>

            <p>
                Whether you're planning a quick activation before work or a full-day adventure, SOTA Planner helps you choose the right summit for the time you have available.
            </p>

            <h2>Key Features</h2>
            <ul style="margin-left: 2rem; margin-bottom: 1.5rem; font-size: 1.1rem;">
                <li><strong>Planning Groups:</strong> Organize activations with your friends or solo</li>
                <li><strong>Drive Time Calculation:</strong> Automatic routing from your address</li>
                <li><strong>Hike Time Estimation:</strong> Based on distance and elevation gain</li>
                <li><strong>Total Time View:</strong> Drive + Hike + Activation time at a glance</li>
                <li><strong>Activation Tracking:</strong> Keep history of completed activations</li>
                <li><strong>Shared Research:</strong> Benefit from trail data researched by others</li>
            </ul>

            <h2>Who Built This?</h2>
            <p>
                SOTA Planner was created by <strong>Christopher Reddick, KI6CR</strong>, a casual SOTA activator and occasional chaser who wanted a better way to plan time sensitive activation trips.
            </p>

            <p>
                Learn more about Chris and his projects at <a href="https://ki6cr.com" target="_blank">ki6cr.com</a>
            </p>

            <div class="highlight">
                <h2>Free & Open</h2>
                <p style="margin-bottom: 0;">
                    SOTA Planner is free to use. No signup required, no email needed. Just create a planning group and start planning your activations.
                </p>
            </div>

            <h2>Get Started</h2>
            <p>
                Ready to plan your next activation? Create a planning group, add an address to use for directions, and start nominating summits.
            </p>

            <a href="index.php" class="btn">Start Planning Now</a>

            <div class="footer">
                <p>
                    <strong>SOTA Planner</strong> &copy; <?= date('Y') ?> Christopher Reddick, KI6CR<br>
                    Created for the SOTA community with 73s
                </p>
            </div>
        </div>
    </div>
</body>
</html>

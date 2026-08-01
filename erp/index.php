<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>SIBA Public School &mdash; ERP Management Portal</title>
    <link rel="stylesheet" href="./assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="./assets/erp-ui.css">
    <style>
    .landing-body{min-height:100vh;display:flex;flex-direction:column;background:linear-gradient(180deg,#f0f4ff 0%,#f8fafc 40%)}
    .landing-main{flex:1;display:flex;align-items:center;justify-content:center;padding:2rem}
    .landing-card{width:min(1100px,100%);border-radius:var(--radius-lg);overflow:hidden;box-shadow:0 20px 60px -12px rgba(15,23,42,.18),0 0 0 1px rgba(15,23,42,.06)}
    .landing-grid{display:grid;grid-template-columns:1fr 1fr}
    @media(max-width:768px){.landing-grid{grid-template-columns:1fr}}
    .landing-left{background:#fff;padding:2.5rem 2.5rem 2rem;display:flex;flex-direction:column;justify-content:center;gap:1.5rem}
    .landing-right{background:linear-gradient(145deg,#0d6efd 0%,#2266e0 40%,#3d8bfd 100%);padding:2.5rem 2.5rem 2rem;color:#fff;display:flex;flex-direction:column;justify-content:space-between;position:relative;overflow:hidden}
    .landing-right::before{content:"";position:absolute;top:-60px;right:-60px;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.06)}
    .landing-right::after{content:"";position:absolute;bottom:-40px;right:50px;width:120px;height:120px;border-radius:50%;background:rgba(255,255,255,.05)}
    .landing-logo{width:52px;height:52px;border-radius:var(--radius-md);background:var(--brand);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:800;box-shadow:0 4px 12px rgba(13,110,253,.3)}
    .landing-features{display:grid;gap:.65rem}
    .landing-feature{display:flex;align-items:flex-start;gap:.65rem;font-size:.9rem;color:var(--ink)}
    .landing-feature .feat-dot{min-width:20px;width:20px;height:20px;border-radius:50%;background:#d4edda;color:#198754;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;margin-top:1px}
    .landing-footer{text-align:center;padding:1rem 2rem;color:var(--muted);font-size:.8rem;position:relative;z-index:1}
    .landing-footer a{color:var(--brand);text-decoration:none;font-weight:600}
    .landing-footer a:hover{text-decoration:underline}
    .landing-right h2{font-size:1.65rem;font-weight:700;color:#fff;line-height:1.3;position:relative;z-index:1}
    .landing-right p{color:rgba(255,255,255,.78);font-size:.9rem;line-height:1.6;position:relative;z-index:1}
    .landing-cap-grid{display:grid;grid-template-columns:1fr 1fr;gap:.7rem;position:relative;z-index:1}
    .landing-cap{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.14);border-radius:var(--radius-md);padding:.7rem .85rem}
    .landing-cap strong{display:block;font-size:1.15rem;font-weight:700;color:#fff}
    .landing-cap span{font-size:.75rem;color:rgba(255,255,255,.7)}
    .landing-left h1{font-size:1.8rem;font-weight:800;color:var(--ink);line-height:1.25;letter-spacing:-0.02em}
    .landing-left h1 span{color:var(--brand)}
    .landing-tagline{font-size:.95rem;color:var(--muted);line-height:1.55}
    </style>
</head>
<body class="landing-body">
<main class="landing-main">
    <div class="landing-card">
        <div class="landing-grid">
            <div class="landing-left">
                <div class="landing-logo">S</div>
                <div>
                    <h1>SIBA Public School<br><span>Management Portal</span></h1>
                </div>
                <p class="landing-tagline">Streamline admissions, track finances, manage attendance, and run daily operations from one place.</p>
                <div class="landing-features">
                    <div class="landing-feature">
                        <span class="feat-dot">&#10003;</span>
                        <span><strong>End-to-end admissions</strong> &mdash; intake, tracking, parent communication</span>
                    </div>
                    <div class="landing-feature">
                        <span class="feat-dot">&#10003;</span>
                        <span><strong>Full finance module</strong> &mdash; fee collection, expenses, receipts &amp; reports</span>
                    </div>
                    <div class="landing-feature">
                        <span class="feat-dot">&#10003;</span>
                        <span><strong>Role-based access</strong> &mdash; super admin, module admins, and staff accounts</span>
                    </div>
                    <div class="landing-feature">
                        <span class="feat-dot">&#10003;</span>
                        <span><strong>Built for speed</strong> &mdash; lightweight, no bloat, works on any device</span>
                    </div>
                </div>
                <a class="btn" href="./admin/login.php" style="font-size:.95rem;padding:.65rem 1.8rem;width:fit-content">Login to Dashboard</a>
            </div>

            <div class="landing-right">
                <div>
                    <span class="eyebrow" style="background:rgba(255,255,255,.14);color:#f1fff6;position:relative;z-index:1">Platform Capabilities</span>
                    <h2>Everything your staff needs, connected in one place.</h2>
                    <p>From admissions to fee tracking, attendance to expense reports &mdash; SIBA ERP handles the operational side so your team can focus on teaching.</p>
                </div>
                <div class="landing-cap-grid">
                    <div class="landing-cap">
                        <strong>Admissions</strong>
                        <span>Intake &#8594; Review &#8594; Enroll</span>
                    </div>
                    <div class="landing-cap">
                        <strong>Finance</strong>
                        <span>Fees, receipts, reports</span>
                    </div>
                    <div class="landing-cap">
                        <strong>Attendance</strong>
                        <span>Live tracking &amp; records</span>
                    </div>
                    <div class="landing-cap">
                        <strong>Events &amp; Gallery</strong>
                        <span>Publish &amp; manage content</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
<footer class="landing-footer">
    &copy; <?= date('Y') ?> SIBA Public School. &ensp;<a href="../">Back to main website</a>
</footer>
</body>
</html>

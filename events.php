<?php
require_once('includes/db_connect.php');
require_once('includes/cms.php');
require_once('includes/events_helper.php');

$cms = cmsGetPage($conn, 'events');
$pageTitle = $cms['title'];
$data = $cms['data'];

try {
    $dbEvents = getEventsFromDb($conn, 'event');
    $dbNews = getEventsFromDb($conn, 'news');
} catch (Throwable) {
    $dbEvents = [];
    $dbNews = [];
}

$eventList = !empty($dbEvents) ? $dbEvents : ($data['events'] ?? []);
$newsList = !empty($dbNews) ? $dbNews : ($data['news'] ?? []);

include('includes/header.php');
?>

<style>
.events-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2.5rem; align-items: start; }
@media (max-width: 860px) { .events-grid { grid-template-columns: 1fr; } }

.event-item {
    display: flex; gap: 1.25rem; align-items: flex-start;
    padding: 1.25rem; border-radius: 12px; background: #fff;
    box-shadow: 0 1px 4px rgba(0,0,0,.06); margin-bottom: 1rem;
    transition: transform .2s, box-shadow .2s;
}
.event-item:hover { transform: translateY(-3px); box-shadow: 0 6px 20px rgba(0,0,0,.1); }

.event-date-box {
    flex: 0 0 70px; text-align: center; border-radius: 10px;
    padding: .75rem .5rem; color: #fff; line-height: 1;
}
.event-date-box .day { font-size: 1.8rem; font-weight: 800; }
.event-date-box .month { font-size: .65rem; text-transform: uppercase; letter-spacing: .08em; margin-top: .3rem; display: block; opacity: .9; }

.event-body h3 { font-size: 1rem; font-weight: 700; color: var(--primary-color); margin-bottom: .3rem; }
.event-body p { font-size: .88rem; color: var(--text-light); margin: 0; }
.event-body .event-meta { font-size: .78rem; color: var(--text-light); margin-top: .4rem; }

.news-card {
    background: #fff; border-radius: 12px; overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
    transition: transform .2s, box-shadow .2s;
}
.news-card:hover { transform: translateY(-3px); box-shadow: 0 6px 20px rgba(0,0,0,.1); }
.news-card img { width: 100%; height: 200px; object-fit: cover; }
.news-card-body { padding: 1.25rem; }
.news-card-body .news-category { display: inline-block; font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; background: var(--accent-color); color: #fff; padding: .2rem .6rem; border-radius: 4px; margin-bottom: .5rem; }
.news-card-body h3 { font-size: 1.05rem; font-weight: 700; color: var(--primary-color); margin-bottom: .4rem; }
.news-card-body p { font-size: .88rem; color: var(--text-light); margin: 0 0 .5rem; }
.news-card-body .news-date { font-size: .78rem; color: var(--text-light); }

.empty-state { text-align: center; padding: 3rem 1rem; color: var(--text-light); }
.empty-state i { font-size: 2.5rem; margin-bottom: .75rem; display: block; opacity: .4; }
</style>

<div class="page-hero">
    <div class="breadcrumb">
        <a href="<?php echo SITE_URL; ?>"><i class="fas fa-home"></i> Home</a>
        <i class="fas fa-chevron-right" style="font-size:0.6rem"></i>
        <span>Events & News</span>
    </div>
    <h1><?php echo htmlspecialchars($data['hero_title'] ?? 'Events & Latest News'); ?></h1>
    <p><?php echo htmlspecialchars($data['hero_text'] ?? 'Stay updated with the latest happenings at SIBA Public School.'); ?></p>
</div>

<section class="section section-alt">
    <div class="events-grid">

        <div>
            <div class="section-title" style="text-align:left;margin-bottom:2rem;">
                <span class="badge"><?php echo htmlspecialchars($data['events_badge'] ?? 'Calendar'); ?></span>
                <h2 style="text-align:left;"><?php echo htmlspecialchars($data['events_heading'] ?? 'Upcoming Events'); ?></h2>
            </div>

            <?php if (!empty($eventList)): ?>
                <?php foreach ($eventList as $event): ?>
                    <?php if (!empty($event['title'])): ?>
                    <div class="event-item">
                        <div class="event-date-box" style="background:<?php echo htmlspecialchars($event['color'] ?? '#4b5563'); ?>;">
                            <div class="day"><?php echo htmlspecialchars($event['day'] ?? ''); ?></div>
                            <span class="month"><?php echo htmlspecialchars($event['month'] ?? ''); ?></span>
                        </div>
                        <div class="event-body">
                            <h3><i class="fas fa-<?php echo htmlspecialchars($event['icon'] ?? 'calendar'); ?>" style="color:<?php echo htmlspecialchars($event['color'] ?? '#4b5563'); ?>;margin-right:.4rem;font-size:.85rem;"></i><?php echo htmlspecialchars($event['title']); ?></h3>
                            <p><?php echo htmlspecialchars($event['text'] ?? ''); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-plus"></i>
                    <p>No upcoming events at the moment. Check back soon!</p>
                </div>
            <?php endif; ?>
        </div>

        <div>
            <div class="section-title" style="text-align:left;margin-bottom:2rem;">
                <span class="badge"><?php echo htmlspecialchars($data['news_badge'] ?? 'News'); ?></span>
                <h2 style="text-align:left;"><?php echo htmlspecialchars($data['news_heading'] ?? 'Latest from SIBA'); ?></h2>
            </div>

            <?php if (!empty($newsList)): ?>
                <?php foreach ($newsList as $item): ?>
                    <?php if (!empty($item['title'])): ?>
                    <div class="news-card" style="margin-bottom:1.25rem;">
                        <?php if (!empty($item['image'])): ?>
                            <img src="<?php echo htmlspecialchars($item['image']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>">
                        <?php endif; ?>
                        <div class="news-card-body">
                            <span class="news-category"><?php echo htmlspecialchars($item['category'] ?? 'Update'); ?></span>
                            <h3><?php echo htmlspecialchars($item['title']); ?></h3>
                            <p><?php echo htmlspecialchars($item['text'] ?? ''); ?></p>
                            <?php if (!empty($item['event_date'])): ?>
                                <div class="news-date"><i class="fas fa-calendar-alt"></i> <?php echo htmlspecialchars($item['event_date']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-newspaper"></i>
                    <p>No news articles yet. Stay tuned for updates!</p>
                </div>
            <?php endif; ?>
        </div>

    </div>
</section>

<?php include('includes/footer.php'); ?>

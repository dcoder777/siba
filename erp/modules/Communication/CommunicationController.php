<?php

declare(strict_types=1);

namespace modules\Communication;

use core\Controller;
use core\Request;
use PDO;

class CommunicationController extends Controller
{
    // ═══════════════════ ANNOUNCEMENTS & CIRCULARS ═══════════════════

    public function listAnnouncements(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $audience = trim((string) Request::query('audience', ''));

        $where = [];
        $params = [];
        if ($audience !== '') {
            $where[] = 'audience = :aud';
            $params['aud'] = $audience;
        }

        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM system_announcements' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $sql = 'SELECT * FROM system_announcements' . $whereSql . ' ORDER BY id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'announcements');
    }

    public function createAnnouncement(): void
    {
        $data = Request::json();
        $title = trim((string) ($data['title'] ?? ''));
        $content = trim((string) ($data['content'] ?? ''));

        if ($title === '' || $content === '') {
            $this->fail('title and content are required', 422);
            return;
        }

        $sendEmail = !empty($data['send_email']) ? 1 : 0;
        $priority = trim((string) ($data['priority'] ?? 'normal'));
        $audience = trim((string) ($data['audience'] ?? 'all'));

        $stmt = $this->pdo->prepare(
            'INSERT INTO system_announcements (
                title, content, audience, priority, valid_until, is_active, send_email, email_status, created_by, created_at
            ) VALUES (
                :title, :content, :aud, :prio, :until, 1, :send_em, :em_st, 1, NOW()
            )'
        );
        $stmt->execute([
            'title' => $title,
            'content' => $content,
            'aud' => $audience,
            'prio' => $priority,
            'until' => $data['valid_until'] ?? date('Y-m-d', strtotime('+30 days')),
            'send_em' => $sendEmail,
            'em_st' => $sendEmail ? 'Queued' : 'None',
        ]);
        $id = (int) $this->pdo->lastInsertId();

        // If email requested, queue mock/live email dispatch records
        if ($sendEmail) {
            $parents = $this->pdo->query('SELECT email, name FROM parents WHERE email IS NOT NULL AND email != "" LIMIT 20')->fetchAll();
            $logStmt = $this->pdo->prepare(
                'INSERT INTO announcement_email_logs (announcement_id, recipient_email, recipient_name, recipient_type, status, sent_at)
                 VALUES (:aid, :em, :name, "Parent", "Sent", NOW())'
            );
            foreach ($parents as $p) {
                $logStmt->execute([
                    'aid' => $id,
                    'em' => $p['email'],
                    'name' => $p['name'] ?? 'Parent',
                ]);
            }
            $this->pdo->prepare('UPDATE system_announcements SET email_status = "Sent" WHERE id = :id')->execute(['id' => $id]);
        }

        $this->ok(['id' => $id, 'title' => $title, 'send_email' => $sendEmail], 'Announcement published successfully');
    }

    public function getAnnouncement(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $stmt = $this->pdo->prepare('SELECT * FROM system_announcements WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $ann = $stmt->fetch();
        if (!$ann) {
            $this->fail('Announcement not found', 404);
            return;
        }

        $logStmt = $this->pdo->prepare('SELECT * FROM announcement_email_logs WHERE announcement_id = :id ORDER BY id DESC');
        $logStmt->execute(['id' => $id]);
        $ann['email_logs'] = $logStmt->fetchAll();

        $this->ok(['announcement' => $ann]);
    }

    public function deleteAnnouncement(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $this->pdo->prepare('DELETE FROM system_announcements WHERE id = :id')->execute(['id' => $id]);
        $this->ok(['id' => $id], 'Announcement deleted successfully');
    }

    // ═══════════════════ CMS CONTENT PAGES ═══════════════════

    public function listPages(): void
    {
        $stmt = $this->pdo->query('SELECT id, slug, page_title, updated_at FROM cms_pages ORDER BY page_title ASC');
        $this->ok(['pages' => $stmt->fetchAll()]);
    }

    public function getPage(array $params): void
    {
        $slug = trim((string) ($params['slug'] ?? ''));
        $stmt = $this->pdo->prepare('SELECT * FROM cms_pages WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $page = $stmt->fetch();
        if (!$page) {
            $this->fail('CMS page not found', 404);
            return;
        }
        $this->ok(['page' => $page]);
    }

    public function updatePage(array $params): void
    {
        $slug = trim((string) ($params['slug'] ?? ''));
        $data = Request::json();

        $stmt = $this->pdo->prepare('SELECT id FROM cms_pages WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        $title = $data['page_title'] ?? $data['title'] ?? null;
        $content = isset($data['data_json']) ? (is_array($data['data_json']) ? json_encode($data['data_json']) : (string) $data['data_json']) : null;

        if ($row) {
            $up = $this->pdo->prepare(
                'UPDATE cms_pages SET
                    page_title = COALESCE(:title, page_title),
                    data_json = COALESCE(:content, data_json),
                    updated_at = NOW()
                 WHERE slug = :slug'
            );
            $up->execute(['title' => $title, 'content' => $content, 'slug' => $slug]);
        } else {
            $ins = $this->pdo->prepare(
                'INSERT INTO cms_pages (slug, page_title, data_json, created_at, updated_at)
                 VALUES (:slug, :title, :content, NOW(), NOW())'
            );
            $ins->execute(['slug' => $slug, 'title' => $title ?? ucfirst($slug), 'content' => $content ?? '{}']);
        }

        $this->ok(['slug' => $slug], 'CMS page updated successfully');
    }

    // ═══════════════════ EVENTS & CALENDAR ═══════════════════

    public function listEvents(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $type = trim((string) Request::query('type', ''));

        $where = [];
        $params = [];
        if ($type !== '') {
            $where[] = 'type = :type';
            $params['type'] = $type;
        }

        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM events' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $sql = 'SELECT * FROM events' . $whereSql . ' ORDER BY event_date DESC, id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'events');
    }

    public function createEvent(): void
    {
        $data = Request::json();
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $this->fail('title is required', 422);
            return;
        }

        $eventDate = $data['event_date'] ?? date('Y-m-d');
        $d = date('d', strtotime($eventDate));
        $m = date('M', strtotime($eventDate));

        $stmt = $this->pdo->prepare(
            'INSERT INTO events (
                type, title, text, day, month, category, event_date, is_active, created_at, updated_at
            ) VALUES (
                :type, :title, :text, :day, :month, :cat, :edate, 1, NOW(), NOW()
            )'
        );
        $stmt->execute([
            'type' => $data['type'] ?? 'event',
            'title' => $title,
            'text' => $data['text'] ?? $data['description'] ?? '',
            'day' => $d,
            'month' => $m,
            'cat' => $data['category'] ?? 'General',
            'edate' => $eventDate,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->ok(['id' => $id, 'title' => $title], 'Event created successfully');
    }

    public function deleteEvent(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        $this->ok(['id' => $id], 'Event deleted successfully');
    }

    // ═══════════════════ GALLERY ═══════════════════

    public function listGallery(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $category = trim((string) Request::query('category', ''));

        $where = [];
        $params = [];
        if ($category !== '') {
            $where[] = 'category = :cat';
            $params['cat'] = $category;
        }

        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM gallery' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $sql = 'SELECT * FROM gallery' . $whereSql . ' ORDER BY sort_order ASC, id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'gallery');
    }

    public function createGallery(): void
    {
        $data = Request::json();
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $this->fail('title is required', 422);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO gallery (
                title, description, image_file, youtube_url, category, sort_order, is_active, created_at, updated_at
            ) VALUES (
                :title, :desc, :img, :yt, :cat, :sort, 1, NOW(), NOW()
            )'
        );
        $stmt->execute([
            'title' => $title,
            'desc' => $data['description'] ?? '',
            'img' => $data['image_file'] ?? '',
            'yt' => $data['youtube_url'] ?? '',
            'cat' => $data['category'] ?? 'Campus',
            'sort' => (int) ($data['sort_order'] ?? 0),
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->ok(['id' => $id, 'title' => $title], 'Gallery item created successfully');
    }

    public function deleteGallery(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $this->pdo->prepare('DELETE FROM gallery WHERE id = :id')->execute(['id' => $id]);
        $this->ok(['id' => $id], 'Gallery item deleted successfully');
    }

    // ═══════════════════ WEBSITE CONTACT ENQUIRIES ═══════════════════

    public function listEnquiries(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $status = trim((string) Request::query('status', ''));

        $where = [];
        $params = [];
        if ($status !== '') {
            $where[] = 'status = :st';
            $params['st'] = $status;
        }

        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM contact_submissions' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $sql = 'SELECT * FROM contact_submissions' . $whereSql . ' ORDER BY id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'enquiries');
    }

    public function updateEnquiry(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $data = Request::json();

        $stmt = $this->pdo->prepare(
            'UPDATE contact_submissions SET
                status = COALESCE(:status, status),
                admin_note = COALESCE(:note, admin_note),
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'status' => isset($data['status']) ? trim((string) $data['status']) : null,
            'note' => isset($data['admin_note']) ? trim((string) $data['admin_note']) : null,
        ]);

        $this->ok(['id' => $id], 'Enquiry status updated successfully');
    }

    public function deleteEnquiry(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $this->pdo->prepare('DELETE FROM contact_submissions WHERE id = :id')->execute(['id' => $id]);
        $this->ok(['id' => $id], 'Enquiry deleted successfully');
    }
}

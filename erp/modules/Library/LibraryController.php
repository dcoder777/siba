<?php

declare(strict_types=1);

namespace modules\Library;

use core\Controller;
use core\Request;
use PDO;

class LibraryController extends Controller
{
    public function listBooks(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $q = trim((string) Request::query('q', ''));
        $category = trim((string) Request::query('category', ''));

        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = '(title LIKE :q OR author LIKE :q OR isbn LIKE :q OR book_id LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if ($category !== '') {
            $where[] = 'category = :category';
            $params['category'] = $category;
        }

        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM library_books' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $sql = 'SELECT * FROM library_books' . $whereSql . ' ORDER BY id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'books');
    }

    public function createBook(): void
    {
        $data = Request::json();
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $this->fail('title is required', 422);
            return;
        }

        $bookId = trim((string) ($data['book_id'] ?? ''));
        if ($bookId === '') {
            $bookId = 'BK-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        }

        $totalCopies = (int) ($data['total_copies'] ?? 1);

        $stmt = $this->pdo->prepare(
            'INSERT INTO library_books (
                book_id, isbn, title, author, publisher, category, language, class_group,
                edition, rack_location, total_copies, available_copies, status, created_at, updated_at
            ) VALUES (
                :bid, :isbn, :title, :author, :pub, :cat, :lang, :cg,
                :ed, :rack, :tc, :ac, "Active", NOW(), NOW()
            )'
        );
        $stmt->execute([
            'bid' => $bookId,
            'isbn' => $data['isbn'] ?? '',
            'title' => $title,
            'author' => $data['author'] ?? 'Unknown',
            'pub' => $data['publisher'] ?? '',
            'cat' => $data['category'] ?? 'General',
            'lang' => $data['language'] ?? 'English',
            'cg' => $data['class_group'] ?? 'All Classes',
            'ed' => $data['edition'] ?? '1st Edition',
            'rack' => $data['rack_location'] ?? 'Rack A-1',
            'tc' => $totalCopies,
            'ac' => $totalCopies,
        ]);

        $id = (int) $this->pdo->lastInsertId();

        // Automatically create initial copies
        for ($i = 1; $i <= $totalCopies; $i++) {
            $copyStmt = $this->pdo->prepare(
                'INSERT INTO library_book_copies (book_id, accession_no, barcode, rack_location, status, created_at)
                 VALUES (:bid, :acc, :bc, :rack, "Available", NOW())'
            );
            $copyStmt->execute([
                'bid' => $id,
                'acc' => $bookId . '-C' . str_pad((string)$i, 2, '0', STR_PAD_LEFT),
                'bc' => 'BC' . rand(100000, 999999),
                'rack' => $data['rack_location'] ?? 'Rack A-1',
            ]);
        }

        $this->ok(['id' => $id, 'book_id' => $bookId, 'total_copies' => $totalCopies], 'Book registered successfully');
    }

    public function getBook(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $stmt = $this->pdo->prepare('SELECT * FROM library_books WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $book = $stmt->fetch();
        if (!$book) {
            $this->fail('Book not found', 404);
            return;
        }

        $copiesStmt = $this->pdo->prepare('SELECT * FROM library_book_copies WHERE book_id = :id');
        $copiesStmt->execute(['id' => $id]);
        $book['copies'] = $copiesStmt->fetchAll();

        $this->ok(['book' => $book]);
    }

    public function updateBook(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $data = Request::json();

        $stmt = $this->pdo->prepare(
            'UPDATE library_books SET
                title = COALESCE(:title, title),
                author = COALESCE(:author, author),
                publisher = COALESCE(:pub, publisher),
                category = COALESCE(:cat, category),
                rack_location = COALESCE(:rack, rack_location),
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'title' => isset($data['title']) ? trim((string) $data['title']) : null,
            'author' => isset($data['author']) ? trim((string) $data['author']) : null,
            'pub' => isset($data['publisher']) ? trim((string) $data['publisher']) : null,
            'cat' => isset($data['category']) ? trim((string) $data['category']) : null,
            'rack' => isset($data['rack_location']) ? trim((string) $data['rack_location']) : null,
        ]);

        $this->ok(['id' => $id], 'Book updated successfully');
    }

    public function deleteBook(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $this->pdo->prepare('DELETE FROM library_books WHERE id = :id')->execute(['id' => $id]);
        $this->ok(['id' => $id], 'Book deleted successfully');
    }

    public function listCirculation(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $status = trim((string) Request::query('status', ''));
        $studentId = Request::intQuery('student_id', 0);

        $where = [];
        $params = [];
        if ($status !== '') {
            $where[] = 'i.issue_status = :status';
            $params['status'] = $status;
        }
        if ($studentId > 0) {
            $where[] = 'i.student_id = :sid';
            $params['sid'] = $studentId;
        }

        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM library_issues i' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $sql = 'SELECT i.*, b.title, b.author, b.isbn, s.first_name, s.last_name, s.admission_no
                FROM library_issues i
                LEFT JOIN library_books b ON b.id = i.book_id
                LEFT JOIN students s ON s.id = i.student_id' . $whereSql . '
                ORDER BY i.id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'issues');
    }

    public function issueBook(): void
    {
        $data = Request::json();
        $bookId = (int) ($data['book_id'] ?? 0);
        $studentId = (int) ($data['student_id'] ?? 0);

        if ($bookId <= 0 || $studentId <= 0) {
            $this->fail('book_id and student_id are required', 422);
            return;
        }

        // Check availability
        $bStmt = $this->pdo->prepare('SELECT id, available_copies FROM library_books WHERE id = :id LIMIT 1');
        $bStmt->execute(['id' => $bookId]);
        $book = $bStmt->fetch();
        if (!$book || (int) $book['available_copies'] <= 0) {
            $this->fail('No copies available for issue', 400);
            return;
        }

        // Find available copy
        $cpStmt = $this->pdo->prepare('SELECT id FROM library_book_copies WHERE book_id = :id AND status = "Available" LIMIT 1');
        $cpStmt->execute(['id' => $bookId]);
        $copy = $cpStmt->fetch();
        $copyId = $copy ? (int) $copy['id'] : null;

        $dueDate = $data['due_date'] ?? date('Y-m-d', strtotime('+14 days'));
        $issueNo = 'ISS-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        $stmt = $this->pdo->prepare(
            'INSERT INTO library_issues (
                issue_no, book_id, copy_id, student_id, issue_date, due_date, issue_status, issued_by, created_at
            ) VALUES (
                :ino, :bid, :cid, :sid, CURDATE(), :due, "Issued", "Librarian", NOW()
            )'
        );
        $stmt->execute([
            'ino' => $issueNo,
            'bid' => $bookId,
            'cid' => $copyId,
            'sid' => $studentId,
            'due' => $dueDate,
        ]);
        $issueId = (int) $this->pdo->lastInsertId();

        // Update book availability & copy status
        $this->pdo->prepare('UPDATE library_books SET available_copies = available_copies - 1 WHERE id = :id')->execute(['id' => $bookId]);
        if ($copyId) {
            $this->pdo->prepare('UPDATE library_book_copies SET status = "Issued" WHERE id = :cid')->execute(['cid' => $copyId]);
        }

        $this->ok(['issue_id' => $issueId, 'due_date' => $dueDate], 'Book issued successfully');
    }

    public function returnBook(array $params): void
    {
        $issueId = (int) ($params['id'] ?? 0);
        $data = Request::json();

        $stmt = $this->pdo->prepare('SELECT * FROM library_issues WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $issueId]);
        $issue = $stmt->fetch();
        if (!$issue || $issue['issue_status'] === 'Returned') {
            $this->fail('Issue record not found or already returned', 404);
            return;
        }

        $fine = (float) ($data['fine_amount'] ?? 0);

        $upStmt = $this->pdo->prepare(
            'UPDATE library_issues SET
                issue_status = "Returned",
                return_date = CURDATE(),
                returned_by = "Librarian",
                updated_at = NOW()
             WHERE id = :id'
        );
        $upStmt->execute(['id' => $issueId]);

        // Restock copy
        $this->pdo->prepare('UPDATE library_books SET available_copies = available_copies + 1 WHERE id = :id')->execute(['id' => $issue['book_id']]);
        if (!empty($issue['copy_id'])) {
            $this->pdo->prepare('UPDATE library_book_copies SET status = "Available" WHERE id = :cid')->execute(['cid' => $issue['copy_id']]);
        }

        $this->ok(['issue_id' => $issueId, 'fine_amount' => $fine], 'Book returned successfully');
    }
}

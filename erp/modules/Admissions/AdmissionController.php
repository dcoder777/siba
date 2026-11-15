<?php

declare(strict_types=1);

namespace modules\Admissions;

use core\Controller;
use core\Request;

class AdmissionController extends Controller
{
    private function createParentUser(array $payload): array
    {
        // If parent_id is explicitly provided, verify and use existing parent
        if (!empty($payload['parent_id'])) {
            $existingById = $this->pdo->prepare(
                'SELECT p.id AS parent_id, p.user_id, p.name, p.email, p.phone
                 FROM parents p
                 WHERE p.id = :id
                 LIMIT 1'
            );
            $existingById->execute(['id' => (int) $payload['parent_id']]);
            $foundById = $existingById->fetch();
            if ($foundById) {
                return [
                    'parent_id' => (int) $foundById['parent_id'],
                    'user_id'   => (int) ($foundById['user_id'] ?? 0),
                    'name'      => $foundById['name'],
                    'email'     => $foundById['email'],
                    'phone'     => $foundById['phone'],
                ];
            }
        }

        $name = trim((string) ($payload['name'] ?? $payload['father_name'] ?? ''));
        $phone = preg_replace('/\D/', '', (string) ($payload['phone'] ?? $payload['contact_no'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));

        if ($name === '') {
            $this->fail('Parent name is required', 422);
            return [];
        }
        if (strlen($phone) < 10) {
            $this->fail('Valid 10-digit phone number is required', 422);
            return [];
        }
        if ($email === '') {
            $email = $phone . '@sibapublicschool.com';
        }

        // Find existing parent by phone or email — reuse if found
        $existing = $this->pdo->prepare(
            'SELECT p.id AS parent_id, p.user_id, p.name, p.email, p.phone
             FROM parents p
             WHERE p.phone = :phone OR p.email = :email
             LIMIT 1'
        );
        $existing->execute(['phone' => $phone, 'email' => $email]);
        $found = $existing->fetch();

        if ($found) {
            return [
                'parent_id' => (int) $found['parent_id'],
                'user_id'   => (int) $found['user_id'],
                'name'      => $found['name'],
                'email'     => $found['email'],
                'phone'     => $found['phone'],
            ];
        }

        // Parent not found — create new parent + user
        $password = (string) ($payload['password'] ?? '');
        if ($password === '') {
            $password = substr(bin2hex(random_bytes(4)), 0, 8);
        }

        $roleStmt = $this->pdo->prepare("SELECT id FROM roles WHERE name = 'parent' LIMIT 1");
        $roleStmt->execute();
        $role = $roleStmt->fetch();
        if (!$role) {
            $this->fail('Parent role not found', 500);
            return [];
        }

        $erpEmail = $email ?: 'parent_' . $phone . '@siba.local';
        $hashed = password_hash($password, PASSWORD_DEFAULT);

        $userStmt = $this->pdo->prepare(
            'INSERT INTO users (role_id, name, email, password_hash, is_active) VALUES (:role_id, :name, :email, :password_hash, 1)'
        );
        $userStmt->execute([
            'role_id' => $role['id'],
            'name' => $name,
            'email' => $erpEmail,
            'password_hash' => $hashed,
        ]);
        $userId = (int) $this->pdo->lastInsertId();

        $parentStmt = $this->pdo->prepare(
            'INSERT INTO parents (name, email, phone, password, user_id) VALUES (:name, :email, :phone, :password, :user_id)'
        );
        $parentStmt->execute([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password' => $hashed,
            'user_id' => $userId,
        ]);
        $parentId = (int) $this->pdo->lastInsertId();

        return [
            'parent_id' => $parentId,
            'user_id' => $userId,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
        ];
    }

    public function registerParent(): void
    {
        $payload = !empty($_POST) ? array_merge(Request::json(), $_POST) : Request::json();
        if (empty($payload)) {
            $this->fail('Request body is required', 422);
            return;
        }

        try {
            $this->pdo->beginTransaction();
            $result = $this->createParentUser($payload);
            if (empty($result)) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                return;
            }
            $this->pdo->commit();

            $this->ok([
                'parent_id' => $result['parent_id'],
                'user_id' => $result['user_id'],
            ], 'Parent registered successfully');
        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->fail('Failed to register parent: ' . $e->getMessage(), 500);
        }
    }

    public function apply(array $context = []): void
    {
        // Support both application/json and multipart/form-data
        $payload = !empty($_POST) ? array_merge(Request::json(), $_POST) : Request::json();
        if (empty($payload) && empty($_FILES)) {
            $this->fail('Request body is required', 422);
            return;
        }

        // If authenticated as parent and parent_id not supplied, automatically link to logged-in parent
        if (empty($payload['parent_id']) && !empty($context['user']) && ($context['user']['role_name'] ?? '') === 'parent') {
            $pStmt = $this->pdo->prepare('SELECT id, name, email, phone FROM parents WHERE user_id = :uid LIMIT 1');
            $pStmt->execute(['uid' => $context['user']['id']]);
            $pRow = $pStmt->fetch();
            if ($pRow) {
                $payload['parent_id'] = (int) $pRow['id'];
                if (empty($payload['name'])) $payload['name'] = $pRow['name'];
                if (empty($payload['email'])) $payload['email'] = $pRow['email'];
                if (empty($payload['phone'])) $payload['phone'] = $pRow['phone'];
            }
        }

        $studentName = trim((string) ($payload['student_name'] ?? ''));
        $classSought = trim((string) ($payload['class_sought'] ?? ''));
        $dob = trim((string) ($payload['dob'] ?? ''));
        $fatherName = trim((string) ($payload['father_name'] ?? ''));
        $motherName = trim((string) ($payload['mother_name'] ?? ''));

        if ($studentName === '' || $classSought === '' || $dob === '' || $fatherName === '' || $motherName === '') {
            $this->fail('student_name, class_sought, dob, father_name, and mother_name are required', 422);
            return;
        }

        try {
            $this->pdo->beginTransaction();

            $parentResult = $this->createParentUser($payload);
            if (empty($parentResult)) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                return;
            }

            // Handle file uploads
            $uploadDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR;
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }

            $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/webp', 'application/pdf'];
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];

            $fileMap = [
                'birth_cert'         => 'bc',
                'aadhaar'            => 'aa',
                'leaving_cert'       => 'lc',
                'prev_marksheet'     => 'pm',
                'photo'              => 'ph',
                'caste_cert'         => 'cc',
                'father_photo'       => 'fp',
                'mother_photo'       => 'mp',
                'father_aadhaar'     => 'fa',
                'mother_aadhaar'     => 'ma',
                'father_voter'       => 'fv',
                'mother_voter'       => 'mv',
                'disability_cert'    => 'dc',
                'guardian_signature' => 'gs',
            ];

            $savedFiles = [];
            foreach ($fileMap as $field => $prefix) {
                $savedFiles[$field] = null;
                // Check if uploaded via $_FILES (also support 'aadhaar_file' alias for 'aadhaar')
                $fileItem = $_FILES[$field] ?? ($field === 'aadhaar' ? ($_FILES['aadhaar_file'] ?? null) : null);

                if ($fileItem && is_array($fileItem) && ($fileItem['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $origName = basename((string) ($fileItem['name'] ?? ''));
                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    $mime = (string) ($fileItem['type'] ?? '');
                    if (function_exists('mime_content_type') && !empty($fileItem['tmp_name'])) {
                        $detectedMime = @mime_content_type($fileItem['tmp_name']);
                        if ($detectedMime) {
                            $mime = $detectedMime;
                        }
                    }

                    if (in_array($mime, $allowedMimeTypes, true) || in_array($ext, $allowedExtensions, true)) {
                        $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '', $origName);
                        $targetName = time() . '_' . $prefix . '_' . $sanitized;
                        if (move_uploaded_file($fileItem['tmp_name'], $uploadDir . $targetName)) {
                            $savedFiles[$field] = $targetName;
                        }
                    } else {
                        if ($this->pdo->inTransaction()) {
                            $this->pdo->rollBack();
                        }
                        $this->fail("File for {$field} must be a JPG, PNG, WEBP, or PDF.", 422);
                        return;
                    }
                } elseif (!empty($payload[$field]) && is_string($payload[$field])) {
                    // Check if passed as base64 data URI in JSON
                    $val = trim($payload[$field]);
                    if (preg_match('/^data:([a-zA-Z0-9]+\/[a-zA-Z0-9.-]+);base64,(.+)$/', $val, $b64Matches)) {
                        $mimeType = $b64Matches[1];
                        $b64Data = base64_decode($b64Matches[2]);
                        $extMap = [
                            'image/jpeg' => 'jpg', 'image/png' => 'png',
                            'image/webp' => 'webp', 'image/gif' => 'gif',
                            'application/pdf' => 'pdf'
                        ];
                        $ext = $extMap[$mimeType] ?? 'bin';
                        $targetName = time() . '_' . $prefix . '_upload.' . $ext;
                        if ($b64Data !== false && file_put_contents($uploadDir . $targetName, $b64Data)) {
                            $savedFiles[$field] = $targetName;
                        }
                    } else {
                        // Already stored filename reference
                        $savedFiles[$field] = $val;
                    }
                }
            }

            // Fallback combined address if not explicitly set
            $address = trim((string) ($payload['address'] ?? ''));
            if ($address === '') {
                $addrParts = array_filter([
                    $payload['address_line1'] ?? '',
                    $payload['address_line2'] ?? '',
                    $payload['village_city'] ?? ($payload['district'] ?? ''),
                    $payload['state'] ?? '',
                    $payload['pin'] ?? ''
                ]);
                $address = implode(', ', $addrParts);
            }

            $fields = [
                'parent_id', 'student_name', 'first_name', 'middle_name', 'last_name',
                'dob', 'gender', 'religion', 'blood_group', 'aadhaar_no',
                'caste', 'disability', 'disability_details',
                'previous_school', 'previous_class', 'class_sought',
                'address_line1', 'address_line2', 'post_office', 'police_station',
                'district', 'village_city', 'pin', 'state', 'country',
                'father_name', 'father_occupation', 'father_aadhaar_no', 'father_voter_no',
                'mother_name', 'mother_occupation', 'mother_aadhaar_no', 'mother_voter_no',
                'guardian_name', 'guardian_occupation', 'family_annual_income',
                'contact_no', 'email', 'address',
                'birth_cert', 'aadhaar', 'leaving_cert', 'prev_marksheet', 'photo',
                'caste_cert', 'father_photo', 'mother_photo', 'father_aadhaar',
                'mother_aadhaar', 'father_voter', 'mother_voter', 'disability_cert',
                'guardian_signature',
            ];

            $colNames = [];
            $placeholders = [];
            $params = [];
            foreach ($fields as $f) {
                $colNames[] = $f;
                $placeholders[] = ':' . $f;
                if (array_key_exists($f, $savedFiles)) {
                    $params[$f] = $savedFiles[$f];
                } else {
                    $val = $payload[$f] ?? null;
                    $params[$f] = ($val !== '' && $val !== null) ? $val : null;
                }
            }

            $params['parent_id'] = $parentResult['parent_id'];
            $params['class_sought'] = $classSought;
            $params['dob'] = $dob;
            $params['father_name'] = $fatherName;
            $params['mother_name'] = $motherName;
            $params['student_name'] = $studentName;
            $params['address'] = $address ?: null;

            // Generate application number
            $year = date('Y');
            $prefix = "SBA-{$year}-";
            $countStmt = $this->pdo->query("SELECT COUNT(*) AS c FROM applications WHERE application_no LIKE '{$prefix}%'");
            $appCount = (int) $countStmt->fetch()['c'];
            $appNo = $prefix . str_pad((string) ($appCount + 1), 4, '0', STR_PAD_LEFT);

            $colNames[] = 'application_no';
            $placeholders[] = ':application_no';
            $params['application_no'] = $appNo;

            $colNames[] = 'application_number';
            $placeholders[] = ':application_number';
            $params['application_number'] = $appNo;

            $appStmt = $this->pdo->prepare(
                'INSERT INTO applications (' . implode(', ', $colNames) . ", status, applied_at) VALUES (" . implode(', ', $placeholders) . ", 'Application started', NOW())"
            );
            $appStmt->execute($params);
            $applicationId = (int) $this->pdo->lastInsertId();

            $this->pdo->commit();

            $this->ok([
                'application_no' => $appNo,
                'application_number' => $appNo,
                'parent_id' => $parentResult['parent_id'],
                'application_id' => $applicationId,
                'user_id' => $parentResult['user_id'],
                'uploaded_files' => array_filter($savedFiles),
            ], 'Application submitted successfully');

        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->fail('Failed to submit application: ' . $e->getMessage(), 500);
        }
    }

    public function list(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $q = trim((string) Request::query('q', ''));
        $status = trim((string) Request::query('status', ''));

        $where = [];
        $params = [];

        if ($q !== '') {
            $where[] = '(a.application_no LIKE :q0 OR a.application_number LIKE :q0 OR a.student_name LIKE :q1 OR a.father_name LIKE :q2 OR a.mother_name LIKE :q3 OR p.name LIKE :q4 OR p.phone LIKE :q5)';
            $likeQ = '%' . $q . '%';
            $params['q0'] = $likeQ;
            $params['q1'] = $likeQ;
            $params['q2'] = $likeQ;
            $params['q3'] = $likeQ;
            $params['q4'] = $likeQ;
            $params['q5'] = $likeQ;
        }
        if ($status !== '') {
            $where[] = 'a.status = :status';
            $params['status'] = $status;
        }

        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS c FROM applications a LEFT JOIN parents p ON p.id = a.parent_id' . $whereSql
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $sql = 'SELECT a.id, a.application_no, a.application_no AS application_number,
                       a.student_name, a.first_name, a.middle_name, a.last_name, a.dob, a.gender,
                       a.class_sought, a.status, a.payment_status, a.admission_no, a.student_id, a.applied_at,
                       p.name AS parent_name, p.phone AS parent_phone
                FROM applications a
                LEFT JOIN parents p ON p.id = a.parent_id' . $whereSql . '
                ORDER BY a.applied_at DESC
                LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'applications');
    }

    public function show(array $params, array $context = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            $this->fail('Invalid application ID', 422);
            return;
        }

        $user = $context['user'] ?? null;
        if (!$user) {
            $this->fail('Unauthorized', 401);
            return;
        }

        $stmt = $this->pdo->prepare(
            'SELECT a.*, p.name AS parent_name, p.phone AS parent_phone, p.email AS parent_email, p.user_id AS parent_user_id
             FROM applications a
             LEFT JOIN parents p ON p.id = a.parent_id
             WHERE a.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $app = $stmt->fetch();

        if (!$app) {
            $this->fail('Application not found', 404);
            return;
        }

        if ($user['role_name'] === 'parent' && (int) ($app['parent_user_id'] ?? 0) !== (int) $user['id']) {
            $this->fail('Forbidden', 403);
            return;
        }

        $app['application_no'] = $app['application_no'] ?: ($app['application_number'] ?? null);
        $app['application_number'] = $app['application_no'];

        $this->ok(['application' => $app]);
    }

    public function downloadReceipt(array $params, array $context = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            $this->fail('Invalid application ID', 422);
            return;
        }

        $user = $context['user'] ?? null;
        if (!$user) {
            $this->fail('Unauthorized', 401);
            return;
        }

        $stmt = $this->pdo->prepare(
            'SELECT a.*, p.name AS parent_name, p.phone AS parent_phone, p.email AS parent_email, p.user_id AS parent_user_id
             FROM applications a
             LEFT JOIN parents p ON p.id = a.parent_id
             WHERE a.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $app = $stmt->fetch();

        if (!$app) {
            $this->fail('Application not found', 404);
            return;
        }

        if ($user['role_name'] === 'parent' && (int) ($app['parent_user_id'] ?? 0) !== (int) $user['id']) {
            $this->fail('You do not have permission to download this receipt', 403);
            return;
        }

        require_once dirname(__DIR__, 3) . '/includes/receipt_pdf.php';

        $pdf = siba_receipt_pdf($app);
        $filename = siba_receipt_filename($app, $id);

        if (Request::query('format') === 'json') {
            $this->ok([
                'application_id' => $id,
                'application_no' => $app['application_no'] ?? '',
                'filename' => $filename,
                'mime_type' => 'application/pdf',
                'pdf_base64' => base64_encode($pdf),
            ]);
            return;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        echo $pdf;
        exit;
    }
}

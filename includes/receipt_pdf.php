<?php
/**
 * Shared application receipt PDF generator.
 * Pure-PHP, no external library. Produces a byte string for a PDF file.
 *
 * Accepts a single application row (associative array) and returns the PDF
 * binary content. Callers are responsible for sending headers and echoing.
 */
declare(strict_types=1);

if (!function_exists('siba_receipt_pdf')) {
    function siba_receipt_pdf(array $app): string
    {
        $esc = static fn(string $s): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);

        $studentFullName = trim(implode(' ', array_filter([
            $app['first_name'] ?? '',
            $app['middle_name'] ?? '',
            $app['last_name'] ?? '',
        ]))) ?: (string) ($app['student_name'] ?? '');

        $paymentStatus = (string) ($app['payment_status'] ?? 'Pending');
        $appliedAt = (string) ($app['applied_at'] ?? '');
        $appliedDate = $appliedAt !== '' ? date('d-m-Y h:i A', (int) strtotime($appliedAt)) : '';

        $lines = [];

        // Header band
        $lines[] = '0.12 0.16 0.23 rg';           // dark slate
        $lines[] = '0 0 595 90 re f';
        $lines[] = '0.96 0.60 0.13 RG';            // accent line (reserved via rect below)
        $lines[] = '0 90 595 3 re f';
        $lines[] = '0.96 0.60 0.13 rg';

        // School name
        $lines[] = 'BT';
        $lines[] = '/F1 20 Tf';
        $lines[] = '1 1 1 rg';
        $lines[] = '60 56 Td';
        $lines[] = '(' . $esc('SIBA PUBLIC SCHOOL') . ') Tj';
        $lines[] = 'ET';

        $lines[] = 'BT';
        $lines[] = '/F2 10 Tf';
        $lines[] = '0.80 0.84 0.90 rg';
        $lines[] = '60 42 Td';
        $lines[] = '(' . $esc('WBBSE Affiliated | Chapra, West Bengal') . ') Tj';
        $lines[] = 'ET';

        // Receipt number top-right
        $lines[] = 'BT';
        $lines[] = '/F2 8 Tf';
        $lines[] = '0.80 0.84 0.90 rg';
        $lines[] = '400 62 Td';
        $lines[] = '(' . $esc('RECEIPT NO.: ' . ($app['application_no'] ?? '')) . ') Tj';
        $lines[] = 'ET';
        $lines[] = 'BT';
        $lines[] = '/F1 11 Tf';
        $lines[] = '0.96 0.75 0.20 rg';
        $lines[] = '400 48 Td';
        $lines[] = '(' . $esc('APPLICATION RECEIPT') . ') Tj';
        $lines[] = 'ET';

        // Title
        $lines[] = 'BT';
        $lines[] = '/F1 15 Tf';
        $lines[] = '0.12 0.16 0.23 rg';
        $lines[] = '60 104 Td';
        $lines[] = '(' . $esc('Application Acknowledgement Receipt') . ') Tj';
        $lines[] = 'ET';
        $lines[] = 'BT';
        $lines[] = '/F2 9.5 Tf';
        $lines[] = '0.45 0.50 0.55 rg';
        $lines[] = '60 92 Td';
        $lines[] = '(' . $esc('Thank you for applying to SIBA Public School.') . ') Tj';
        $lines[] = 'ET';

        $lines[] = '0.85 0.85 0.85 RG';
        $lines[] = '1 w';
        $lines[] = '60 82 m 535 82 l S';

        // Sections
        $rows = [
            ['APPLICATION DETAILS', true],
            ['Application No', $app['application_no'] ?? ''],
            ['Date of Application', $appliedDate ?: ''],
            ['Student Name', $studentFullName],
            ['Date of Birth', $app['dob'] ?? ''],
            ['Class Applied', $app['class_sought'] ?? ''],
            ['Application Status', $app['status'] ?? ''],
            ['GUARDIAN DETAILS', true],
            ['Guardian Name', $app['parent_name'] ?? ''],
            ['Guardian Phone', $app['parent_phone'] ?? ''],
            ['Guardian Email', $app['parent_email'] ?? ''],
            ["Father's Name", $app['father_name'] ?? ''],
            ["Mother's Name", $app['mother_name'] ?? ''],
            ['PAYMENT SUMMARY', true],
            ['Application Fee', 'Rs. 200'],
            ['Payment Status', $paymentStatus],
        ];

        $y = 500;
        foreach ($rows as $row) {
            $isSection = $row[1] === true;
            if ($isSection) {
                $y -= 6;
                $lines[] = 'BT';
                $lines[] = '/F1 9.5 Tf';
                $lines[] = '0.96 0.55 0.05 rg';
                $lines[] = '60 ' . $y . ' Td';
                $lines[] = '(' . $esc($row[0]) . ') Tj';
                $lines[] = 'ET';
                $lines[] = '0.93 0.89 0.82 RG';
                $lines[] = '0.8 w';
                $lines[] = '60 ' . ($y - 4) . ' m 535 ' . ($y - 4) . ' l S';
                $y -= 17;
                continue;
            }
            $lines[] = 'BT';
            $lines[] = '/F2 9.5 Tf';
            $lines[] = '0.45 0.50 0.55 rg';
            $lines[] = '70 ' . $y . ' Td';
            $lines[] = '(' . $esc($row[0]) . ') Tj';
            $lines[] = 'ET';
            $lines[] = 'BT';
            $lines[] = '/F2 9.5 Tf';
            $lines[] = '0 0 0 rg';
            $lines[] = '210 ' . $y . ' Td';
            $lines[] = '(' . $esc((string) $row[1]) . ') Tj';
            $lines[] = 'ET';
            $lines[] = '0.90 0.90 0.90 RG';
            $lines[] = '0.6 w';
            $lines[] = '70 ' . ($y - 5) . ' m 535 ' . ($y - 5) . ' l S';
            $y -= 17;
        }

        // Footer
        $lines[] = 'BT';
        $lines[] = '/F2 9 Tf';
        $lines[] = '0.45 0.50 0.55 rg';
        $lines[] = '60 56 Td';
        $lines[] = '(' . $esc('This is a computer-generated receipt. No signature required.') . ') Tj';
        $lines[] = 'ET';
        $lines[] = 'BT';
        $lines[] = '/F2 8 Tf';
        $lines[] = '0.12 0.16 0.23 rg';
        $lines[] = '60 44 Td';
        $lines[] = '(' . $esc('SIBA Public School | Chapra, West Bengal | All Rights Reserved') . ') Tj';
        $lines[] = 'ET';

        $content = implode("\n", $lines) . "\n";

        $objects = [];
        $objectCount = 1;
        $offsets = [];

        $pdf = "%PDF-1.4\n";

        $addObject = function (string $body) use (&$pdf, &$offsets, &$objectCount): int {
            $offsets[] = strlen($pdf);
            $pdf .= $objectCount . " 0 obj\n" . $body . "\nendobj\n";
            $num = $objectCount;
            $objectCount++;
            return $num;
        };

        $catalogId = $addObject("<< /Type /Catalog /Pages 2 0 R >>");
        $pagesId = $addObject("<< /Type /Pages /Kids [3 0 R] /Count 1 >>");
        $pageId = $addObject("<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>");
        $f1Id = $addObject("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>");
        $f2Id = $addObject("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>");

        $compressed = gzcompress($content, 9);
        $offsets[] = strlen($pdf);
        $pdf .= "6 0 obj\n<< /Length " . strlen($compressed) . " /Filter /FlateDecode >>\nstream\n" . $compressed . "\nendstream\nendobj\n";

        $totalObjects = $objectCount + 1;

        $xrefStart = strlen($pdf);
        $pdf .= "xref\n0 " . $totalObjects . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < $totalObjects; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i - 1]);
        }
        $pdf .= "trailer\n<< /Size " . $totalObjects . " /Root 1 0 R >>\nstartxref\n" . $xrefStart . "\n%%EOF\n";

        return $pdf;
    }
}

if (!function_exists('siba_receipt_filename')) {
    function siba_receipt_filename(array $app, int $appId): string
    {
        $appNoClean = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($app['application_no'] ?? ('SBA_' . $appId)));
        return $appNoClean . '_receipt.pdf';
    }
}

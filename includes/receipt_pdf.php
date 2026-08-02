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

        $lines = [];
        $lines[] = 'BT';
        $lines[] = '/F1 16 Tf';
        $lines[] = '0.29 0.33 0.39 rg';
        $lines[] = '72 772 Td';
        $lines[] = '(' . $esc('SIBA PUBLIC SCHOOL') . ') Tj';
        $lines[] = 'ET';

        $lines[] = 'BT';
        $lines[] = '/F2 9 Tf';
        $lines[] = '0.40 0.45 0.48 rg';
        $lines[] = '72 758 Td';
        $lines[] = '(' . $esc('WBBSE Affiliated | Chapra, West Bengal') . ') Tj';
        $lines[] = 'ET';

        $lines[] = 'BT';
        $lines[] = '/F1 14 Tf';
        $lines[] = '0.29 0.33 0.39 rg';
        $lines[] = '72 716 Td';
        $lines[] = '(' . $esc('APPLICATION RECEIPT') . ') Tj';
        $lines[] = 'ET';

        $lines[] = '0.85 0.85 0.85 RG';
        $lines[] = '2 w';
        $lines[] = '72 702 m 528 702 l S';

        $rows = [
            ['Application No', $app['application_no'] ?? ''],
            ['Student Name', $app['student_name'] ?? ''],
            ['Date of Birth', $app['dob'] ?? ''],
            ['Class Applied', $app['class_sought'] ?? ''],
            ["Father's Name", $app['father_name'] ?? ''],
            ["Mother's Name", $app['mother_name'] ?? ''],
            ['Parent Name', $app['parent_name'] ?? ''],
            ['Parent Phone', $app['parent_phone'] ?? ''],
            ['Parent Email', $app['parent_email'] ?? ''],
            ['Status', $app['status'] ?? ''],
            ['Payment Status', $app['payment_status'] ?? 'Pending'],
            ['Application Fee', 'Rs. 150'],
            ['Applied On', $app['applied_at'] ?? ''],
        ];

        $y = 688;
        foreach ($rows as [$k, $v]) {
            $lines[] = 'BT';
            $lines[] = '/F2 9 Tf';
            $lines[] = '0.40 0.45 0.48 rg';
            $lines[] = '72 ' . $y . ' Td';
            $lines[] = '(' . $esc($k) . ') Tj';
            $lines[] = 'ET';
            $lines[] = 'BT';
            $lines[] = '/F2 9 Tf';
            $lines[] = '0 0 0 rg';
            $lines[] = '210 ' . $y . ' Td';
            $lines[] = '(' . $esc((string) $v) . ') Tj';
            $lines[] = 'ET';
            $lines[] = '0.90 0.90 0.90 RG';
            $lines[] = '1 w';
            $lines[] = '72 ' . ($y - 5) . ' m 528 ' . ($y - 5) . ' l S';
            $y -= 17;
        }

        $lines[] = 'BT';
        $lines[] = '/F2 8 Tf';
        $lines[] = '0.58 0.62 0.65 rg';
        $lines[] = '72 120 Td';
        $lines[] = '(' . $esc('This is a computer-generated receipt. No signature required.') . ') Tj';
        $lines[] = 'ET';
        $lines[] = 'BT';
        $lines[] = '/F2 8 Tf';
        $lines[] = '0.58 0.62 0.65 rg';
        $lines[] = '72 108 Td';
        $lines[] = '(' . $esc('SIBA Public School | All Rights Reserved') . ') Tj';
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

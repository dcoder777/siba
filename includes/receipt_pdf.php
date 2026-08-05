<?php
/**
 * Shared application receipt PDF generator.
 * Pure-PHP, no external library. Produces a byte string for a PDF file.
 *
 * Accepts a single application row (associative array) and returns the PDF
 * binary content. Callers are responsible for sending headers and echoing.
 */
declare(strict_types=1);

if (!function_exists('siba_jpeg_size')) {
    function siba_jpeg_size(string $file): ?array
    {
        $data = @file_get_contents($file);
        if ($data === false || strlen($data) < 4) {
            return null;
        }
        $len = strlen($data);
        $i = 2;
        while ($i + 9 < $len) {
            if ($data[$i] !== "\xFF") {
                $i++;
                continue;
            }
            $marker = ord($data[$i + 1]);
            if ($marker >= 0xC0 && $marker <= 0xCF && $marker !== 0xC4 && $marker !== 0xC8 && $marker !== 0xCC) {
                $height = (ord($data[$i + 5]) << 8) | ord($data[$i + 6]);
                $width  = (ord($data[$i + 7]) << 8) | ord($data[$i + 8]);
                return [$width, $height];
            }
            $seg = (ord($data[$i + 2]) << 8) | ord($data[$i + 3]);
            $i += 2 + $seg;
        }
        return null;
    }
}

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

        // Load school logo (JPEG) for embedding
        $logoFile = dirname(__DIR__) . '/assets/images/logo.jpg';
        $logoData = is_file($logoFile) ? @file_get_contents($logoFile) : false;
        $logoSize = ($logoData !== false) ? siba_jpeg_size($logoFile) : null;
        if ($logoSize) {
            $aspect = $logoSize[0] / $logoSize[1];
            if ($aspect >= 1) {
                $logoW = 92;
                $logoH = max(1, (int) round($logoW / $aspect));
            } else {
                $logoH = 46;
                $logoW = max(1, (int) round($logoH * $aspect));
            }
        } else {
            $logoW = 60;
            $logoH = 60;
        }
        $logoX = 48;
        $logoY = (int) round((90 - $logoH) / 2);
        $brandX = $logoSize ? '150' : '60';

        $lines = [];

        // A4: 595 x 842. y=842 is top, y=0 is bottom.
        // Header band: y=752..842 (90px tall)
        $headerTop = 842;
        $headerBot = 752;

        // Header band background
        $lines[] = '0.12 0.16 0.23 rg';
        $lines[] = '0 ' . $headerBot . ' 595 90 re f';
        // Accent line at bottom of header
        $lines[] = '0.96 0.60 0.13 rg';
        $lines[] = '0 ' . $headerBot . ' 595 3 re f';

        // Logo (if available) - centered vertically in header band
        if ($logoSize) {
            $logoDrawY = $headerBot + (int) round((90 - $logoH) / 2);
            $lines[] = 'q';
            $lines[] = $logoW . ' 0 0 ' . $logoH . ' ' . $logoX . ' ' . $logoDrawY . ' cm';
            $lines[] = '/Im1 Do';
            $lines[] = 'Q';
        }

        // School name
        $lines[] = 'BT';
        $lines[] = '/F1 20 Tf';
        $lines[] = '1 1 1 rg';
        $lines[] = $brandX . ' 806 Td';
        $lines[] = '(' . $esc('SIBA PUBLIC SCHOOL') . ') Tj';
        $lines[] = 'ET';

        $lines[] = 'BT';
        $lines[] = '/F2 10 Tf';
        $lines[] = '0.80 0.84 0.90 rg';
        $lines[] = $brandX . ' 792 Td';
        $lines[] = '(' . $esc('WBBSE Affiliated | Chapra, West Bengal') . ') Tj';
        $lines[] = 'ET';

        // Receipt number top-right
        $lines[] = 'BT';
        $lines[] = '/F2 8 Tf';
        $lines[] = '0.80 0.84 0.90 rg';
        $lines[] = '400 812 Td';
        $lines[] = '(' . $esc('RECEIPT NO.: ' . ($app['application_no'] ?? '')) . ') Tj';
        $lines[] = 'ET';
        $lines[] = 'BT';
        $lines[] = '/F1 11 Tf';
        $lines[] = '0.96 0.75 0.20 rg';
        $lines[] = '400 798 Td';
        $lines[] = '(' . $esc('APPLICATION RECEIPT') . ') Tj';
        $lines[] = 'ET';

        // Title block below header
        $titleY = $headerBot - 20;
        $lines[] = 'BT';
        $lines[] = '/F1 15 Tf';
        $lines[] = '0.12 0.16 0.23 rg';
        $lines[] = '60 ' . $titleY . ' Td';
        $lines[] = '(' . $esc('Application Acknowledgement Receipt') . ') Tj';
        $lines[] = 'ET';
        $subY = $titleY - 16;
        $lines[] = 'BT';
        $lines[] = '/F2 9.5 Tf';
        $lines[] = '0.45 0.50 0.55 rg';
        $lines[] = '60 ' . $subY . ' Td';
        $lines[] = '(' . $esc('Thank you for applying to SIBA Public School.') . ') Tj';
        $lines[] = 'ET';

        $lineY = $subY - 12;
        $lines[] = '0.85 0.85 0.85 RG';
        $lines[] = '1 w';
        $lines[] = '60 ' . $lineY . ' m 535 ' . $lineY . ' l S';

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
            ['Application Fee', 'Rs. ' . number_format((float) ($app['payment_amount'] ?? (defined('APPLICATION_FEE') ? APPLICATION_FEE : 200)), 2)],
            ['Payment Status', $paymentStatus],
        ];

        $y = $lineY - 20;
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

        // Footer at bottom
        $lines[] = 'BT';
        $lines[] = '/F2 9 Tf';
        $lines[] = '0.45 0.50 0.55 rg';
        $lines[] = '60 46 Td';
        $lines[] = '(' . $esc('This is a computer-generated receipt. No signature required.') . ') Tj';
        $lines[] = 'ET';
        $lines[] = 'BT';
        $lines[] = '/F2 8 Tf';
        $lines[] = '0.12 0.16 0.23 rg';
        $lines[] = '60 34 Td';
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
        $pagesId = $addObject("<< /Type /Pages /Kids [6 0 R] /Count 1 >>");
        $f1Id = $addObject("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>");
        $f2Id = $addObject("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>");
        $imgId = 0;
        if ($logoSize) {
            $imgBody = "<< /Type /XObject /Subtype /Image /Width {$logoSize[0]} /Height {$logoSize[1]} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($logoData) . " >>\nstream\n" . $logoData . "\nendstream";
            $imgId = $addObject($imgBody);
        }
        $xobjRef = $imgId ? " /XObject << /Im1 {$imgId} 0 R >>" : '';
        $contentsObjNum = $imgId ? 7 : 6;
        $pageId = $addObject("<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>{$xobjRef} >> /Contents {$contentsObjNum} 0 R >>");

        $compressed = gzcompress($content, 9);
        $offsets[] = strlen($pdf);
        $pdf .= "{$contentsObjNum} 0 obj\n<< /Length " . strlen($compressed) . " /Filter /FlateDecode >>\nstream\n" . $compressed . "\nendstream\nendobj\n";

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

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('to:template-headerize', function () {
    if (!extension_loaded('zip')) {
        $this->error('ext-zip is required to edit .xlsx templates. Enable ZIP extension and retry.');
        return 1;
    }

    $templatePath = storage_path('app/templates/TRAVEL ORDER FOR CAPSTONE.xlsx');
    if (!is_file($templatePath)) {
        $this->error("Template not found: {$templatePath}");
        return 1;
    }

    $spreadsheet = IOFactory::load($templatePath);

    $sheetNames = ['TO for COS', 'TO for REGULAR'];
    $headerLines = [
        'Republic of the Philippines',
        'REGIONAL FIELD OFFICE IX',
        'Zamboanga Peninsula',
        'Lenienza, Pagadian City 7016',
    ];

    foreach ($sheetNames as $sheetName) {
        $sheet = $spreadsheet->getSheetByName($sheetName);
        if (!$sheet) {
            $this->warn("Sheet not found, skipping: {$sheetName}");
            continue;
        }

        // If the template has a big header overlay picture, remove it and replace it with
        // a smaller logo/header image anchored on the left side only.
        // (Cell text cannot draw above images; background images are not reliably shown/printed in some viewers.)
        $drawings = $sheet->getDrawingCollection();
        $addedLeft = false;
        if ($drawings && count($drawings) > 0) {
            foreach ($drawings as $idx => $drawing) {
                if (!method_exists($drawing, 'getCoordinates')) {
                    continue;
                }
                $coord = (string) $drawing->getCoordinates();
                if ($coord === '') {
                    continue;
                }

                [$col, $row] = Coordinate::coordinateFromString($coord);
                $colIndex = Coordinate::columnIndexFromString($col);
                $row = (int) $row;

                $w = (int) (method_exists($drawing, 'getWidth') ? $drawing->getWidth() : 0);
                $h = (int) (method_exists($drawing, 'getHeight') ? $drawing->getHeight() : 0);

                $isHeaderArea = $row <= 12 && $colIndex <= 8; // A..H, rows 1..12
                $isBig = $w >= 350 || $h >= 140;

                if ($isHeaderArea && $isBig) {
                    $drawingPath = method_exists($drawing, 'getPath') ? (string) $drawing->getPath() : '';
                    if ($drawingPath) {
                        // For embedded drawings, getPath() is often a zip:// stream. file_get_contents works for both.
                        $bytes = @file_get_contents($drawingPath);
                        if ($bytes !== false && $bytes !== '') {
                            // Write bytes to a temp image file and re-add as a smaller Drawing
                            $tmpImg = storage_path('app/templates/__to_header_left.png');
                            @file_put_contents($tmpImg, $bytes);

                            $new = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
                            $new->setName('TO Header Left');
                            $new->setPath($tmpImg);
                            $new->setCoordinates('A1');
                            $new->setOffsetX(0);
                            $new->setOffsetY(0);
                            // Size tuned to approximate original header logos but still stay left of the text in C2:C5
                            $new->setWidth(280);
                            $new->setHeight(120);
                            $new->setWorksheet($sheet);

                            $this->info("Replaced header overlay with left-only image on sheet: {$sheetName}");
                            $addedLeft = true;
                        } else {
                            $this->warn("Found header overlay picture but could not read bytes; skipping background conversion for {$sheetName}");
                        }
                    } else {
                        $this->warn("Found header overlay picture but it has no readable path; skipping background conversion for {$sheetName}");
                    }

                    // Remove the overlay drawing so it no longer covers the cells.
                    try {
                        if (method_exists($drawings, 'offsetUnset')) {
                            $drawings->offsetUnset($idx);
                        } else {
                            unset($drawings[$idx]);
                        }
                    } catch (\Throwable) {
                        // ignore
                    }

                    // Only one big header overlay expected
                    break;
                }
            }
        }

        // If we didn't detect a big overlay (e.g. it was already removed), ensure the left header image exists.
        if (!$addedLeft) {
            $tmpImg = storage_path('app/templates/__to_header_left.png');
            if (is_file($tmpImg)) {
                $new = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
                $new->setName('TO Header Left');
                $new->setPath($tmpImg);
                $new->setCoordinates('A1');
                $new->setOffsetX(0);
                $new->setOffsetY(0);
                $new->setWidth(280);
                $new->setHeight(120);
                $new->setWorksheet($sheet);
                $this->info("Ensured left-only header image exists on sheet: {$sheetName}");
            }
        }

        // Write header into cells (so it survives PhpSpreadsheet load/save)
        // Start at C2, one line per row.
        $startCol = 'C';
        $startRow = 2;
        foreach ($headerLines as $i => $line) {
            $coord = $startCol . ($startRow + $i);
            $sheet->setCellValue($coord, $line);
            $sheet->getStyle($coord)->getFont()->setSize(9);
            $sheet->getStyle($coord)->getAlignment()->setHorizontal('left')->setVertical('top');
        }

        // Bold the "REGIONAL FIELD OFFICE IX" line (C3)
        $sheet->getStyle($startCol . ($startRow + 1))->getFont()->setBold(true);

        // Try to ensure enough row height for readability
        for ($r = $startRow; $r <= $startRow + 3; $r++) {
            $sheet->getRowDimension($r)->setRowHeight(-1); // auto
        }

        $this->info("Header written into cells on sheet: {$sheetName}");
    }

    // Saving will typically drop unsupported TextBox/Shape objects,
    // which is desired here because the header text will now be in cells.
    $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
    // Save via temp file then replace (avoids failure if the template is open/locked).
    $tmpOut = storage_path('app/templates/TRAVEL ORDER FOR CAPSTONE.__tmp__.xlsx');
    $writer->save($tmpOut);

    if (@rename($tmpOut, $templatePath)) {
        $this->info('Template updated successfully.');
        return 0;
    }

    // If rename fails (e.g., file is open), keep the tmp file so user can manually replace.
    $this->warn('Could not overwrite the original template (it may be open).');
    $this->warn('A new file was written instead: ' . $tmpOut);
    $this->warn('Close Excel/WPS, then replace the template with that file.');

    return 0;
})->purpose('Convert TO template header textbox to cell text');

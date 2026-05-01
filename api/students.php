<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function student_payload(array $data): array
{
    return [
        'student_id' => trim((string)($data['student_id'] ?? '')),
        'first_name' => trim((string)($data['first_name'] ?? '')),
        'last_name' => trim((string)($data['last_name'] ?? '')),
        'email' => trim((string)($data['email'] ?? '')),
        'phone' => trim((string)($data['phone'] ?? '')),
        'address' => trim((string)($data['address'] ?? '')),
        'course' => trim((string)($data['course'] ?? '')),
        'year_level' => (int)($data['year_level'] ?? 0),
        'birthdate' => trim((string)($data['birthdate'] ?? '')),
        'status' => trim((string)($data['status'] ?? 'active')),
    ];
}

function validate_student_required(array $payload, array $fields): void
{
    foreach ($fields as $field) {
        if (($payload[$field] ?? '') === '' || ($field === 'year_level' && (int)$payload[$field] < 1)) {
            error_response('Please fill in all required fields.');
        }
    }

    if (!in_array($payload['status'], ['active', 'inactive'], true)) {
        error_response('Invalid student status.');
    }

    if ($payload['email'] !== '' && !filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
        error_response('Please enter a valid email address.');
    }
}

function find_student_user_id(string $email): ?int
{
    if ($email === '') {
        return null;
    }

    $stmt = db()->prepare("SELECT id FROM users WHERE email = ? AND role = 'student' LIMIT 1");
    $stmt->execute([$email]);
    $id = $stmt->fetchColumn();

    return $id ? (int)$id : null;
}

function student_audit_label(array $student): string
{
    return trim((string)$student['first_name'] . ' ' . (string)$student['last_name']);
}

function student_audit_metadata(array $student): array
{
    return [
        'student_id' => $student['student_id'],
        'email' => $student['email'],
        'course' => $student['course'],
        'year_level' => (int)$student['year_level'],
        'previous_status' => $student['status'],
        'previous_deleted_at' => $student['deleted_at'],
    ];
}

function limited_export_text(mixed $value, int $maxLength): string
{
    $text = trim((string)$value);

    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength);
    }

    return substr($text, 0, $maxLength);
}

function student_export_filters(array $data): array
{
    $source = isset($data['filters']) && is_array($data['filters']) ? $data['filters'] : $data;

    $search = limited_export_text($source['search'] ?? '', 100);
    $status = limited_export_text($source['status'] ?? 'all', 20);
    $course = limited_export_text($source['course'] ?? 'all', 120);
    $year = $source['year'] ?? 'all';

    if (!in_array($status, ['all', 'active', 'inactive'], true)) {
        $status = 'all';
    }

    if ($course === '') {
        $course = 'all';
    }

    $year = filter_var(
        $year,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 12]]
    );

    return [
        'search' => $search,
        'status' => $status,
        'course' => $course,
        'year' => $year === false ? 'all' : (int)$year,
    ];
}

function sql_like_escape(string $value): string
{
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
}

function fetch_students_for_export(array $filters): array
{
    $conditions = [];
    $params = [];

    if ($filters['search'] !== '') {
        $search = '%' . sql_like_escape((string)$filters['search']) . '%';
        $conditions[] = '(
            student_id LIKE ? ESCAPE \'!\'
            OR first_name LIKE ? ESCAPE \'!\'
            OR last_name LIKE ? ESCAPE \'!\'
            OR CONCAT(first_name, " ", last_name) LIKE ? ESCAPE \'!\'
            OR email LIKE ? ESCAPE \'!\'
            OR course LIKE ? ESCAPE \'!\'
        )';
        array_push($params, $search, $search, $search, $search, $search, $search);
    }

    if ($filters['status'] !== 'all') {
        $conditions[] = 'status = ?';
        $params[] = $filters['status'];
    }

    if ($filters['course'] !== 'all') {
        $conditions[] = 'course = ?';
        $params[] = $filters['course'];
    }

    if ($filters['year'] !== 'all') {
        $conditions[] = 'year_level = ?';
        $params[] = (int)$filters['year'];
    }

    $sql = 'SELECT student_id, first_name, last_name, email, phone, address, course, year_level, birthdate, status, created_at
            FROM students';

    if ($conditions !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= ' ORDER BY id';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function xml_text(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function xlsx_column_name(int $column): string
{
    $name = '';

    while ($column > 0) {
        $column--;
        $name = chr(65 + ($column % 26)) . $name;
        $column = intdiv($column, 26);
    }

    return $name;
}

function xlsx_cell(int $row, int $column, mixed $value, int $style = 0, string $type = 'string'): string
{
    $reference = xlsx_column_name($column) . $row;
    $styleAttribute = $style > 0 ? ' s="' . $style . '"' : '';

    if ($type === 'number') {
        return '<c r="' . $reference . '"' . $styleAttribute . '><v>' . (float)$value . '</v></c>';
    }

    return '<c r="' . $reference . '" t="inlineStr"' . $styleAttribute . '><is><t xml:space="preserve">'
        . xml_text($value)
        . '</t></is></c>';
}

function xlsx_row(int $row, array $cells, ?int $height = null): string
{
    $heightAttribute = $height === null ? '' : ' ht="' . $height . '" customHeight="1"';
    $xml = '<row r="' . $row . '"' . $heightAttribute . '>';

    foreach ($cells as $cell) {
        $xml .= xlsx_cell(
            $row,
            (int)$cell['column'],
            $cell['value'],
            (int)($cell['style'] ?? 0),
            (string)($cell['type'] ?? 'string')
        );
    }

    return $xml . '</row>';
}

function xlsx_filter_summary(array $filters): string
{
    $parts = [];
    $parts[] = 'Search: ' . ($filters['search'] === '' ? 'All' : (string)$filters['search']);
    $parts[] = 'Status: ' . ucfirst((string)$filters['status']);
    $parts[] = 'Course: ' . ($filters['course'] === 'all' ? 'All Courses' : (string)$filters['course']);
    $parts[] = 'Year: ' . ($filters['year'] === 'all' ? 'All Years' : 'Year ' . (int)$filters['year']);

    return implode(' | ', $parts);
}

function xlsx_styles_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="7">'
        . '<font><sz val="11"/><color rgb="FF1F2937"/><name val="Aptos"/></font>'
        . '<font><b/><sz val="18"/><color rgb="FFFFFFFF"/><name val="Aptos Display"/></font>'
        . '<font><sz val="10"/><color rgb="FFD1D5DB"/><name val="Aptos"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Aptos"/></font>'
        . '<font><b/><sz val="12"/><color rgb="FF111827"/><name val="Aptos"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FF217346"/><name val="Aptos"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FF7F1D1D"/><name val="Aptos"/></font>'
        . '</fonts>'
        . '<fills count="9">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF111314"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFF5A1F"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF3F4F6"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF9FAFB"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE2F0D9"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFCE4D6"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FFE5E7EB"/></left><right style="thin"><color rgb="FFE5E7EB"/></right><top style="thin"><color rgb="FFE5E7EB"/></top><bottom style="thin"><color rgb="FFE5E7EB"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="12">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="5" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="4" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="5" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="6" fillId="8" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="4" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

function xlsx_sheet_xml(array $students, array $filters, array $user): string
{
    $rowXml = [];
    $generatedAt = date('Y-m-d H:i:s');
    $activeCount = 0;
    $inactiveCount = 0;
    $courses = [];

    foreach ($students as $student) {
        if (($student['status'] ?? '') === 'inactive') {
            $inactiveCount++;
        } else {
            $activeCount++;
        }

        if (($student['course'] ?? '') !== '') {
            $courses[(string)$student['course']] = true;
        }
    }

    $rowXml[] = xlsx_row(1, [
        ['column' => 1, 'value' => 'ASCT Student Records Export', 'style' => 1],
    ], 32);
    $rowXml[] = xlsx_row(2, [
        ['column' => 1, 'value' => 'Generated ' . $generatedAt . ' by ' . ($user['name'] ?? 'ASCT User') . ' (' . ($user['role'] ?? 'user') . ')', 'style' => 2],
    ], 24);
    $rowXml[] = xlsx_row(3, [
        ['column' => 1, 'value' => xlsx_filter_summary($filters), 'style' => 3],
    ], 28);
    $rowXml[] = xlsx_row(4, [], 8);
    $rowXml[] = xlsx_row(5, [
        ['column' => 1, 'value' => 'Total', 'style' => 5],
        ['column' => 2, 'value' => count($students), 'style' => 6, 'type' => 'number'],
        ['column' => 3, 'value' => 'Active', 'style' => 5],
        ['column' => 4, 'value' => $activeCount, 'style' => 6, 'type' => 'number'],
        ['column' => 5, 'value' => 'Inactive', 'style' => 5],
        ['column' => 6, 'value' => $inactiveCount, 'style' => 6, 'type' => 'number'],
        ['column' => 7, 'value' => 'Courses', 'style' => 5],
        ['column' => 8, 'value' => count($courses), 'style' => 6, 'type' => 'number'],
        ['column' => 9, 'value' => 'Generated', 'style' => 5],
        ['column' => 10, 'value' => $generatedAt, 'style' => 6],
    ], 26);
    $rowXml[] = xlsx_row(6, [], 8);
    $rowXml[] = xlsx_row(7, [
        ['column' => 1, 'value' => 'Student ID', 'style' => 4],
        ['column' => 2, 'value' => 'Last Name', 'style' => 4],
        ['column' => 3, 'value' => 'First Name', 'style' => 4],
        ['column' => 4, 'value' => 'Email', 'style' => 4],
        ['column' => 5, 'value' => 'Phone', 'style' => 4],
        ['column' => 6, 'value' => 'Course', 'style' => 4],
        ['column' => 7, 'value' => 'Year Level', 'style' => 4],
        ['column' => 8, 'value' => 'Status', 'style' => 4],
        ['column' => 9, 'value' => 'Birthdate', 'style' => 4],
        ['column' => 10, 'value' => 'Created At', 'style' => 4],
    ], 30);

    $row = 8;
    foreach ($students as $index => $student) {
        $baseStyle = $index % 2 === 0 ? 7 : 8;
        $status = (string)($student['status'] ?? 'active');
        $statusStyle = $status === 'inactive' ? 10 : 9;

        $rowXml[] = xlsx_row($row, [
            ['column' => 1, 'value' => $student['student_id'] ?? '', 'style' => 11],
            ['column' => 2, 'value' => $student['last_name'] ?? '', 'style' => $baseStyle],
            ['column' => 3, 'value' => $student['first_name'] ?? '', 'style' => $baseStyle],
            ['column' => 4, 'value' => $student['email'] ?? '', 'style' => $baseStyle],
            ['column' => 5, 'value' => $student['phone'] ?? '', 'style' => $baseStyle],
            ['column' => 6, 'value' => $student['course'] ?? '', 'style' => $baseStyle],
            ['column' => 7, 'value' => (int)($student['year_level'] ?? 0), 'style' => $baseStyle, 'type' => 'number'],
            ['column' => 8, 'value' => ucfirst($status), 'style' => $statusStyle],
            ['column' => 9, 'value' => $student['birthdate'] ?? '', 'style' => $baseStyle],
            ['column' => 10, 'value' => $student['created_at'] ?? '', 'style' => $baseStyle],
        ], 24);
        $row++;
    }

    $lastRow = max(7, $row - 1);
    $autoFilterRef = 'A7:J' . $lastRow;

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<dimension ref="A1:J' . $lastRow . '"/>'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="7" topLeftCell="A8" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/>'
        . '<cols>'
        . '<col min="1" max="1" width="18" customWidth="1"/>'
        . '<col min="2" max="3" width="18" customWidth="1"/>'
        . '<col min="4" max="4" width="32" customWidth="1"/>'
        . '<col min="5" max="5" width="16" customWidth="1"/>'
        . '<col min="6" max="6" width="28" customWidth="1"/>'
        . '<col min="7" max="8" width="13" customWidth="1"/>'
        . '<col min="9" max="9" width="15" customWidth="1"/>'
        . '<col min="10" max="10" width="21" customWidth="1"/>'
        . '</cols>'
        . '<sheetData>' . implode('', $rowXml) . '</sheetData>'
        . '<mergeCells count="3"><mergeCell ref="A1:J1"/><mergeCell ref="A2:J2"/><mergeCell ref="A3:J3"/></mergeCells>'
        . '<autoFilter ref="' . $autoFilterRef . '"/>'
        . '<pageMargins left="0.4" right="0.4" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
        . '</worksheet>';
}

function xlsx_zip(array $files): string
{
    $local = '';
    $central = '';
    $offset = 0;
    $timestamp = getdate();
    $dosTime = ((int)$timestamp['hours'] << 11) | ((int)$timestamp['minutes'] << 5) | intdiv((int)$timestamp['seconds'], 2);
    $dosDate = (((int)$timestamp['year'] - 1980) << 9) | ((int)$timestamp['mon'] << 5) | (int)$timestamp['mday'];

    foreach ($files as $name => $content) {
        $name = str_replace('\\', '/', (string)$name);
        $content = (string)$content;
        $size = strlen($content);
        $crc = crc32($content);

        if ($crc < 0) {
            $crc += 4294967296;
        }

        $header = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $size,
            $size,
            strlen($name),
            0
        );
        $local .= $header . $name . $content;

        $central .= pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            20,
            20,
            0,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $size,
            $size,
            strlen($name),
            0,
            0,
            0,
            0,
            0,
            $offset
        ) . $name;

        $offset += strlen($header) + strlen($name) + $size;
    }

    $centralOffset = strlen($local);
    $centralSize = strlen($central);
    $count = count($files);
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $centralSize, $centralOffset, 0);

    return $local . $central . $end;
}

function create_students_xlsx(array $students, array $filters, array $user): string
{
    $createdAt = gmdate('Y-m-d\TH:i:s\Z');

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>',
        'docProps/app.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>ASCT Student Information Management System</Application>'
            . '<DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop>'
            . '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant></vt:vector></HeadingPairs>'
            . '<TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>Student Records</vt:lpstr></vt:vector></TitlesOfParts>'
            . '</Properties>',
        'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>ASCT Student Records Export</dc:title>'
            . '<dc:creator>' . xml_text($user['name'] ?? 'ASCT User') . '</dc:creator>'
            . '<cp:lastModifiedBy>' . xml_text($user['name'] ?? 'ASCT User') . '</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $createdAt . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $createdAt . '</dcterms:modified>'
            . '</cp:coreProperties>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Student Records" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' => xlsx_styles_xml(),
        'xl/worksheets/sheet1.xml' => xlsx_sheet_xml($students, $filters, $user),
    ];

    return xlsx_zip($files);
}

function send_xlsx_response(string $content, string $filename): never
{
    if (ob_get_length()) {
        ob_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo $content;
    exit;
}

try {
    $user = require_user();
    $role = $user['role'];
    $action = request_action();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET' && $action === 'list') {
        if ($role === 'student') {
            $stmt = db()->prepare('SELECT * FROM students WHERE user_id = ? OR email = ? ORDER BY id');
            $stmt->execute([(int)$user['id'], $user['email']]);
        } else {
            $stmt = db()->query('SELECT * FROM students ORDER BY id');
        }

        json_response([
            'success' => true,
            'data' => $stmt->fetchAll(),
        ]);
    }

    if ($method === 'POST' && $action === 'create') {
        require_permission($user, 'add_student', 'Only admins can add new students.');

        $payload = student_payload(request_data());
        validate_student_required($payload, ['student_id', 'first_name', 'last_name', 'email', 'phone', 'course', 'year_level', 'birthdate', 'status']);
        $studentUserId = find_student_user_id($payload['email']);

        $stmt = db()->prepare(
            'INSERT INTO students (user_id, student_id, first_name, last_name, email, phone, address, course, year_level, birthdate, status, deleted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $studentUserId,
            $payload['student_id'],
            $payload['first_name'],
            $payload['last_name'],
            $payload['email'],
            $payload['phone'],
            $payload['address'],
            $payload['course'],
            $payload['year_level'],
            $payload['birthdate'],
            $payload['status'],
            $payload['status'] === 'inactive' ? date('Y-m-d H:i:s') : null,
        ]);

        $student = fetch_student((int)db()->lastInsertId());
        json_response(['success' => true, 'message' => 'New student record created successfully.', 'data' => $student]);
    }

    if ($method === 'POST' && $action === 'update') {
        require_permission($user, 'edit_students', 'You do not have permission to edit students.');

        $data = request_data();
        $id = (int)($data['id'] ?? 0);
        $student = $id > 0 ? fetch_student($id) : null;

        if (!$student) {
            error_response('Student record not found.', 404);
        }

        require_student_access($user, $student);

        $payload = student_payload($data);
        $allowedFields = $role === 'admin'
            ? ['student_id', 'first_name', 'last_name', 'email', 'phone', 'address', 'course', 'year_level', 'birthdate', 'status']
            : ($role === 'teacher'
                ? ['phone', 'address', 'course', 'year_level', 'status']
                : ['email', 'phone', 'address']);

        $requiredEditable = array_values(array_intersect($allowedFields, ['student_id', 'first_name', 'last_name', 'email', 'phone', 'course', 'year_level', 'birthdate', 'status']));
        $merged = array_merge($student, array_intersect_key($payload, array_flip($allowedFields)));
        validate_student_required($merged, $requiredEditable);

        $assignments = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            $assignments[] = "`$field` = ?";
            $params[] = $field === 'year_level' ? (int)$payload[$field] : $payload[$field];
        }

        if (in_array('email', $allowedFields, true)) {
            $studentUserId = find_student_user_id($payload['email']);
            $assignments[] = '`user_id` = COALESCE(?, `user_id`)';
            $params[] = $studentUserId;
        }

        if (in_array('status', $allowedFields, true)) {
            $assignments[] = '`deleted_at` = CASE WHEN ? = "inactive" AND `deleted_at` IS NULL THEN NOW() WHEN ? = "active" THEN NULL ELSE `deleted_at` END';
            $params[] = $payload['status'];
            $params[] = $payload['status'];
        }

        $statusChangedToInactive = in_array('status', $allowedFields, true)
            && ($student['status'] ?? '') !== 'inactive'
            && $payload['status'] === 'inactive';

        $params[] = $id;
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('UPDATE students SET ' . implode(', ', $assignments) . ' WHERE id = ?');
            $stmt->execute($params);

            if ($statusChangedToInactive) {
                write_audit_log(
                    $user,
                    'student_soft_delete',
                    'student',
                    $id,
                    student_audit_label($student),
                    student_audit_metadata($student) + ['source' => 'student_update'],
                    $pdo
                );
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        json_response([
            'success' => true,
            'message' => 'Student record updated successfully.',
            'data' => fetch_student($id),
        ]);
    }

    if ($method === 'POST' && $action === 'export_excel') {
        require_permission($user, 'view_all_students', 'You do not have permission to export student records.');

        $filters = student_export_filters(request_data());
        $students = fetch_students_for_export($filters);

        write_audit_log(
            $user,
            'student_export',
            'student',
            null,
            'Student records export',
            [
                'filters' => $filters,
                'record_count' => count($students),
            ]
        );

        $filename = 'asct-student-records-' . date('Ymd-His') . '.xlsx';
        send_xlsx_response(create_students_xlsx($students, $filters, $user), $filename);
    }

    if ($method === 'POST' && $action === 'soft_delete') {
        require_permission($user, 'soft_delete', 'You do not have permission to delete students.');

        $id = (int)(request_data()['id'] ?? 0);
        $student = $id > 0 ? fetch_student($id) : null;

        if (!$student) {
            error_response('Student record not found.', 404);
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("UPDATE students SET status = 'inactive', deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);
            write_audit_log(
                $user,
                'student_soft_delete',
                'student',
                $id,
                student_audit_label($student),
                student_audit_metadata($student) + ['source' => 'student_soft_delete'],
                $pdo
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        json_response([
            'success' => true,
            'message' => $student['first_name'] . ' ' . $student['last_name'] . ' has been deactivated.',
            'data' => fetch_student($id),
        ]);
    }

    if ($method === 'POST' && $action === 'hard_delete') {
        require_permission($user, 'hard_delete', 'Only admins can permanently delete records.');

        $id = (int)(request_data()['id'] ?? 0);
        $student = $id > 0 ? fetch_student($id) : null;

        if (!$student) {
            error_response('Student record not found.', 404);
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('DELETE FROM students WHERE id = ?');
            $stmt->execute([$id]);
            write_audit_log(
                $user,
                'student_hard_delete',
                'student',
                $id,
                student_audit_label($student),
                student_audit_metadata($student),
                $pdo
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        json_response([
            'success' => true,
            'message' => $student['first_name'] . ' ' . $student['last_name'] . ' has been permanently deleted.',
        ]);
    }

    error_response('Unsupported student action.', 404);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        error_response('Student ID or email already exists.');
    }

    error_response('Student request failed.', 500);
} catch (Throwable $e) {
    error_response('Student request failed.', 500);
}

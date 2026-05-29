<?php
/**
 * PHILIPPINE LOCATION API
 * Complete offline solution using PSGC Excel file
 * No external libraries needed
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

$action = $_GET['action'] ?? '';

// Configuration
$excelFile = __DIR__ . '/PSGC-3Q-2024-Publication-Datafile.xlsx';
$cacheFile = __DIR__ . '/psgc_cache.json';

// Check if Excel file exists
if (!file_exists($excelFile)) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'PSGC Excel file not found',
        'message' => 'Please upload PSGC-3Q-2024-Publication-Datafile.xlsx to this folder'
    ]);
    exit;
}

/**
 * Simple Excel .xlsx reader
 * Extracts data without requiring external libraries
 */
function readExcelFile($filePath) {
    $data = [];
    
    // Open the zip archive
    $zip = zip_open($filePath);
    if (!is_resource($zip)) {
        return false;
    }
    
    $sharedStrings = [];
    $sheetData = '';
    
    // Read the zip contents
    while ($entry = zip_read($zip)) {
        $entryName = zip_entry_name($entry);
        
        // Get shared strings (text values)
        if ($entryName == 'xl/sharedStrings.xml') {
            zip_entry_open($zip, $entry);
            $content = zip_entry_read($entry, zip_entry_filesize($entry));
            zip_entry_close($entry);
            
            // Parse shared strings
            preg_match_all('/<t[^>]*>([^<]+)<\/t>/', $content, $matches);
            $sharedStrings = $matches[1];
        }
        
        // Get sheet data
        if ($entryName == 'xl/worksheets/sheet1.xml') {
            zip_entry_open($zip, $entry);
            $sheetData = zip_entry_read($entry, zip_entry_filesize($entry));
            zip_entry_close($entry);
        }
    }
    
    zip_close($zip);
    
    if (empty($sheetData)) {
        return false;
    }
    
    // Parse rows
    preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheetData, $rows);
    
    // Skip header row (index 0)
    for ($i = 1; $i < count($rows[1]); $i++) {
        preg_match_all('/<c[^>]*>(.*?)<\/c>/s', $rows[1][$i], $cells);
        $rowData = [];
        
        foreach ($cells[1] as $cell) {
            // Extract value
            if (preg_match('/<v>([^<]+)<\/v>/', $cell, $value)) {
                // Check if it's a shared string
                if (strpos($cell, 't="s"') !== false) {
                    $index = intval($value[1]);
                    $rowData[] = isset($sharedStrings[$index]) ? trim($sharedStrings[$index]) : '';
                } else {
                    $rowData[] = trim($value[1]);
                }
            } else {
                $rowData[] = '';
            }
        }
        
        // Only add non-empty rows
        if (!empty(array_filter($rowData))) {
            $data[] = $rowData;
        }
    }
    
    return $data;
}

// Try to get data from cache first
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) { // 24 hours cache
    $locationData = json_decode(file_get_contents($cacheFile), true);
} else {
    // Read Excel file
    $rows = readExcelFile($excelFile);
    
    if ($rows === false || empty($rows)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to read Excel file',
            'message' => 'The Excel file might be corrupted or in an unsupported format'
        ]);
        exit;
    }
    
    // Build location hierarchy
    $regions = [];
    $provincesByRegion = [];
    $citiesByProvince = [];
    $barangaysByCity = [];
    
    foreach ($rows as $row) {
        // Adjust these indices based on PSGC standard format
        // Most PSGC exports have this structure:
        $regionCode = isset($row[0]) ? trim($row[0]) : '';
        $regionName = isset($row[1]) ? trim($row[1]) : '';
        $provinceName = isset($row[3]) ? trim($row[3]) : '';
        $cityName = isset($row[5]) ? trim($row[5]) : '';
        $barangayName = isset($row[7]) ? trim($row[7]) : '';
        
        // Skip empty rows
        if (empty($regionCode) && empty($regionName)) {
            continue;
        }
        
        // Add region
        if (!empty($regionCode) && !empty($regionName) && !isset($regions[$regionCode])) {
            $regions[$regionCode] = $regionName;
        }
        
        // Add province to region
        if (!empty($regionCode) && !empty($provinceName)) {
            if (!isset($provincesByRegion[$regionCode])) {
                $provincesByRegion[$regionCode] = [];
            }
            $provincesByRegion[$regionCode][$provinceName] = true;
        }
        
        // Add city to province
        if (!empty($provinceName) && !empty($cityName)) {
            if (!isset($citiesByProvince[$provinceName])) {
                $citiesByProvince[$provinceName] = [];
            }
            $citiesByProvince[$provinceName][$cityName] = true;
        }
        
        // Add barangay to city
        if (!empty($cityName) && !empty($barangayName)) {
            if (!isset($barangaysByCity[$cityName])) {
                $barangaysByCity[$cityName] = [];
            }
            $barangaysByCity[$cityName][$barangayName] = true;
        }
    }
    
    // Convert to arrays and sort alphabetically
    foreach ($provincesByRegion as $region => $provinces) {
        $provincesByRegion[$region] = array_keys($provinces);
        sort($provincesByRegion[$region], SORT_STRING | SORT_FLAG_CASE);
    }
    
    foreach ($citiesByProvince as $province => $cities) {
        $citiesByProvince[$province] = array_keys($cities);
        sort($citiesByProvince[$province], SORT_STRING | SORT_FLAG_CASE);
    }
    
    foreach ($barangaysByCity as $city => $barangays) {
        $barangaysByCity[$city] = array_keys($barangays);
        sort($barangaysByCity[$city], SORT_STRING | SORT_FLAG_CASE);
    }
    
    // Sort regions alphabetically by name
    asort($regions, SORT_STRING | SORT_FLAG_CASE);
    
    $locationData = [
        'regions' => $regions,
        'provincesByRegion' => $provincesByRegion,
        'citiesByProvince' => $citiesByProvince,
        'barangaysByCity' => $barangaysByCity
    ];
    
    // Save to cache
    file_put_contents($cacheFile, json_encode($locationData));
}

// Handle API requests
try {
    switch ($action) {
        case 'regions':
            echo json_encode([
                'success' => true,
                'data' => $locationData['regions']
            ]);
            break;
        
        case 'provinces':
            $regionCode = $_GET['region'] ?? '';
            if (empty($regionCode)) {
                echo json_encode(['success' => false, 'error' => 'Region code required']);
                break;
            }
            $provinces = $locationData['provincesByRegion'][$regionCode] ?? [];
            echo json_encode([
                'success' => true,
                'data' => $provinces
            ]);
            break;
        
        case 'cities':
            $province = $_GET['province'] ?? '';
            if (empty($province)) {
                echo json_encode(['success' => false, 'error' => 'Province name required']);
                break;
            }
            $cities = $locationData['citiesByProvince'][$province] ?? [];
            echo json_encode([
                'success' => true,
                'data' => $cities
            ]);
            break;
        
        case 'barangays':
            $city = $_GET['city'] ?? '';
            if (empty($city)) {
                echo json_encode(['success' => false, 'error' => 'City name required']);
                break;
            }
            $barangays = $locationData['barangaysByCity'][$city] ?? [];
            echo json_encode([
                'success' => true,
                'data' => $barangays
            ]);
            break;
        
        default:
            echo json_encode([
                'success' => true,
                'message' => 'Philippine Location API is running',
                'endpoints' => [
                    'regions' => '?action=regions',
                    'provinces' => '?action=provinces&region={region_code}',
                    'cities' => '?action=cities&province={province_name}',
                    'barangays' => '?action=barangays&city={city_name}'
                ],
                'stats' => [
                    'regions' => count($locationData['regions']),
                    'provinces' => count($locationData['provincesByRegion'], COUNT_RECURSIVE) - count($locationData['provincesByRegion']),
                    'cities' => count($locationData['citiesByProvince'], COUNT_RECURSIVE) - count($locationData['citiesByProvince']),
                    'barangays' => count($locationData['barangaysByCity'], COUNT_RECURSIVE) - count($locationData['barangaysByCity'])
                ]
            ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => $e->getMessage()
    ]);
}
?>
<?php
header('Content-Type: application/json');

// Get location data from official Philippine administrative divisions
// This endpoint provides complete, accurate location data for the entire Philippines

$action = $_GET['action'] ?? '';

// Cache file for location data
$cacheFile = '/tmp/ph_locations_cache.json';
$cacheExpiry = 24 * 60 * 60; // 24 hours

// Function to fetch data from GitHub source
function fetchPhilippineData() {
    $url = 'https://raw.githubusercontent.com/flores-jacob/philippine-regions-provinces-cities-municipalities-barangays/master/philippine_provinces_cities_municipalities_and_barangays_2019v2.json';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        return null;
    }
    
    return json_decode($response, true);
}

// Function to process raw data into usable format
function processLocationData($rawData) {
    $processed = [
        'regions' => [],
        'provincesByRegion' => [],
        'citiesByProvince' => [],
        'barangaysByCity' => []
    ];
    
    foreach ($rawData as $regionKey => $regionData) {
        $regionName = $regionData['region_name'] ?? 'Region ' . $regionKey;
        $processed['regions'][$regionKey] = $regionName;
        $processed['provincesByRegion'][$regionName] = [];
        
        if (isset($regionData['province_list'])) {
            foreach ($regionData['province_list'] as $provinceName => $provinceData) {
                $processed['provincesByRegion'][$regionName][] = $provinceName;
                $processed['citiesByProvince'][$provinceName] = [];
                
                if (isset($provinceData['municipality_list'])) {
                    foreach ($provinceData['municipality_list'] as $cityName => $cityData) {
                        $processed['citiesByProvince'][$provinceName][] = $cityName;
                        
                        if (isset($cityData['barangay_list'])) {
                            $processed['barangaysByCity'][$cityName] = $cityData['barangay_list'];
                        }
                    }
                }
            }
        }
    }
    
    return $processed;
}

// Get location data (with caching)
$locationData = null;

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheExpiry) {
    // Use cached data
    $cachedContent = file_get_contents($cacheFile);
    $locationData = json_decode($cachedContent, true);
} else {
    // Fetch fresh data
    $rawData = fetchPhilippineData();
    if ($rawData) {
        $locationData = processLocationData($rawData);
        // Cache the processed data
        file_put_contents($cacheFile, json_encode($locationData));
    } else {
        // Fallback to cached data if available
        if (file_exists($cacheFile)) {
            $cachedContent = file_get_contents($cacheFile);
            $locationData = json_decode($cachedContent, true);
        }
    }
}

// Handle different API actions
switch ($action) {
    case 'regions':
        echo json_encode($locationData['regions'] ?? []);
        break;
    
    case 'provinces':
        $region = $_GET['region'] ?? '';
        $provinces = $locationData['provincesByRegion'][$region] ?? [];
        sort($provinces);
        echo json_encode($provinces);
        break;
    
    case 'cities':
        $province = $_GET['province'] ?? '';
        $cities = $locationData['citiesByProvince'][$province] ?? [];
        sort($cities);
        echo json_encode($cities);
        break;
    
    case 'barangays':
        $city = $_GET['city'] ?? '';
        $barangays = $locationData['barangaysByCity'][$city] ?? [];
        sort($barangays);
        echo json_encode($barangays);
        break;
    
    case 'full_data':
        // Return all location data for initial page load
        echo json_encode($locationData);
        break;
    
    default:
        echo json_encode(['error' => 'Invalid action']);
}
?>

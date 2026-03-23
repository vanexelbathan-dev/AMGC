<?php
// supplier.php - Supplier Management with Cascading Address Dropdowns (UPDATED: VAT Classification + Call/Message + Separate Address Display in View)

require_once '../config/database.php';
require_once '../config/session_handler.php';

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Branch Admin';
$user_role = $_SESSION['role'] ?? 'branch_admin';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// Check if suppliers table exists, create if not
$check_suppliers_table = $conn->query("SHOW TABLES LIKE 'suppliers'");
if ($check_suppliers_table && $check_suppliers_table->num_rows == 0) {
    // Create suppliers table with address fields
    $create_table = "CREATE TABLE IF NOT EXISTS suppliers (
        supplier_id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_code VARCHAR(50) NOT NULL UNIQUE,
        supplier_name VARCHAR(150) NOT NULL,
        contact_person VARCHAR(100),
        email VARCHAR(100),
        phone_number VARCHAR(20),
        mobile_number VARCHAR(20),
        
        -- Address fields
        region VARCHAR(255),
        province VARCHAR(255),
        city VARCHAR(255),
        city_code VARCHAR(50),
        barangay VARCHAR(255),
        street_address TEXT,
        full_address TEXT,
        latitude DECIMAL(10,8),
        longitude DECIMAL(11,8),
        
        -- Business information
        tax_id VARCHAR(50),
        vat_classification ENUM('VAT Registered','Non-VAT','Zero Rated','Exempt') DEFAULT 'VAT Registered',
        payment_terms VARCHAR(100) DEFAULT 'Net 30',
        credit_limit DECIMAL(12,2) DEFAULT 0.00,
        website VARCHAR(255),
        notes TEXT,
        status ENUM('active','inactive','pending') DEFAULT 'active',
        branch_id INT,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        FOREIGN KEY (branch_id) REFERENCES branches(branch_id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
        
        INDEX idx_supplier_code (supplier_code),
        INDEX idx_supplier_name (supplier_name),
        INDEX idx_status (status),
        INDEX idx_branch (branch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    if (!$conn->query($create_table)) {
        error_log("Failed to create suppliers table: " . $conn->error);
    }
} else {
    // ========== FIX: CHECK AND ADD MISSING COLUMNS ==========
    // Table exists, check and add missing address columns
    
    // Check if region column exists
    $check_region = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'region'");
    if ($check_region && $check_region->num_rows == 0) {
        $conn->query("ALTER TABLE suppliers ADD COLUMN region VARCHAR(255) NULL");
    }
    
    // Check if province column exists
    $check_province = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'province'");
    if ($check_province && $check_province->num_rows == 0) {
        $conn->query("ALTER TABLE suppliers ADD COLUMN province VARCHAR(255) NULL");
    }
    
    // Check if city column exists
    $check_city = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'city'");
    if ($check_city && $check_city->num_rows == 0) {
        $conn->query("ALTER TABLE suppliers ADD COLUMN city VARCHAR(255) NULL");
    }
    
    // Check if city_code column exists
    $check_city_code = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'city_code'");
    if ($check_city_code && $check_city_code->num_rows == 0) {
        $conn->query("ALTER TABLE suppliers ADD COLUMN city_code VARCHAR(50) NULL");
    }
    
    // Check if barangay column exists
    $check_barangay = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'barangay'");
    if ($check_barangay && $check_barangay->num_rows == 0) {
        $conn->query("ALTER TABLE suppliers ADD COLUMN barangay VARCHAR(255) NULL");
    }
    
    // Check if street_address column exists
    $check_street_address = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'street_address'");
    if ($check_street_address && $check_street_address->num_rows == 0) {
        $conn->query("ALTER TABLE suppliers ADD COLUMN street_address TEXT NULL");
    }
    
    // Check if full_address column exists
    $check_full_address = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'full_address'");
    if ($check_full_address && $check_full_address->num_rows == 0) {
        $conn->query("ALTER TABLE suppliers ADD COLUMN full_address TEXT NULL");
    }
    
    // Check if latitude column exists
    $check_latitude = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'latitude'");
    if ($check_latitude && $check_latitude->num_rows == 0) {
        $conn->query("ALTER TABLE suppliers ADD COLUMN latitude DECIMAL(10,8) NULL");
    }
    
    // Check if longitude column exists
    $check_longitude = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'longitude'");
    if ($check_longitude && $check_longitude->num_rows == 0) {
        $conn->query("ALTER TABLE suppliers ADD COLUMN longitude DECIMAL(11,8) NULL");
    }
    
    // Check if payment_terms column exists (in case older table doesn't have it)
    $check_payment_terms = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'payment_terms'");
    if ($check_payment_terms && $check_payment_terms->num_rows == 0) {
        $conn->query("ALTER TABLE suppliers ADD COLUMN payment_terms VARCHAR(100) DEFAULT 'Net 30'");
    }
    
    // Check if credit_limit column exists
    $check_credit_limit = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'credit_limit'");
    if ($check_credit_limit && $check_credit_limit->num_rows == 0) {
        $conn->query("ALTER TABLE suppliers ADD COLUMN credit_limit DECIMAL(12,2) DEFAULT 0.00");
    }
    
    // Check if website column exists
    $check_website = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'website'");
    if ($check_website && $check_website->num_rows == 0) {
        $conn->query("ALTER TABLE suppliers ADD COLUMN website VARCHAR(255) NULL");
    }
    
    // Check if vat_classification column exists (NEW)
    $check_vat = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'vat_classification'");
    if ($check_vat && $check_vat->num_rows == 0) {
        $conn->query("ALTER TABLE suppliers ADD COLUMN vat_classification ENUM('VAT Registered','Non-VAT','Zero Rated','Exempt') DEFAULT 'VAT Registered'");
    }
    
    // Check if status ENUM includes 'pending' value
    $check_status = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'status'");
    if ($check_status && $check_status->num_rows > 0) {
        $status_row = $check_status->fetch_assoc();
        if (strpos($status_row['Type'], 'pending') === false) {
            $conn->query("ALTER TABLE suppliers MODIFY COLUMN status ENUM('active','inactive','pending') DEFAULT 'active'");
        }
    }
    // ========== END OF FIX ==========
}

// Check if branch_id column exists in suppliers table
$suppliers_branch_column_exists = false;
$check_branch_column = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'branch_id'");
if ($check_branch_column && $check_branch_column->num_rows > 0) {
    $suppliers_branch_column_exists = true;
}

// Philippine Regions data
$regions = [
    'NCR' => 'National Capital Region',
    'CAR' => 'Cordillera Administrative Region',
    'Region I' => 'Ilocos Region',
    'Region II' => 'Cagayan Valley',
    'Region III' => 'Central Luzon',
    'Region IV-A' => 'CALABARZON',
    'Region IV-B' => 'MIMAROPA',
    'Region V' => 'Bicol Region',
    'Region VI' => 'Western Visayas',
    'Region VII' => 'Central Visayas',
    'Region VIII' => 'Eastern Visayas',
    'Region IX' => 'Zamboanga Peninsula',
    'Region X' => 'Northern Mindanao',
    'Region XI' => 'Davao Region',
    'Region XII' => 'SOCCSKSARGEN',
    'Region XIII' => 'Caraga',
    'BARMM' => 'Bangsamoro Autonomous Region in Muslim Mindanao'
];

// Provinces data by region
$provinces = [
    'NCR' => ['Metro Manila'],
    'CAR' => ['Abra', 'Apayao', 'Benguet', 'Ifugao', 'Kalinga', 'Mountain Province'],
    'Region I' => ['Ilocos Norte', 'Ilocos Sur', 'La Union', 'Pangasinan'],
    'Region II' => ['Batanes', 'Cagayan', 'Isabela', 'Nueva Vizcaya', 'Quirino'],
    'Region III' => ['Aurora', 'Bataan', 'Bulacan', 'Nueva Ecija', 'Pampanga', 'Tarlac', 'Zambales'],
    'Region IV-A' => ['Batangas', 'Cavite', 'Laguna', 'Quezon', 'Rizal'],
    'Region IV-B' => ['Marinduque', 'Occidental Mindoro', 'Oriental Mindoro', 'Palawan', 'Romblon'],
    'Region V' => ['Albay', 'Camarines Norte', 'Camarines Sur', 'Catanduanes', 'Masbate', 'Sorsogon'],
    'Region VI' => ['Aklan', 'Antique', 'Capiz', 'Guimaras', 'Iloilo', 'Negros Occidental'],
    'Region VII' => ['Bohol', 'Cebu', 'Negros Oriental', 'Siquijor'],
    'Region VIII' => ['Biliran', 'Eastern Samar', 'Leyte', 'Northern Samar', 'Samar', 'Southern Leyte'],
    'Region IX' => ['Zamboanga del Norte', 'Zamboanga del Sur', 'Zamboanga Sibugay'],
    'Region X' => ['Bukidnon', 'Camiguin', 'Lanao del Norte', 'Misamis Occidental', 'Misamis Oriental'],
    'Region XI' => ['Davao de Oro', 'Davao del Norte', 'Davao del Sur', 'Davao Occidental', 'Davao Oriental'],
    'Region XII' => ['Cotabato', 'Sarangani', 'South Cotabato', 'Sultan Kudarat'],
    'Region XIII' => ['Agusan del Norte', 'Agusan del Sur', 'Dinagat Islands', 'Surigao del Norte', 'Surigao del Sur'],
    'BARMM' => ['Basilan', 'Lanao del Sur', 'Maguindanao', 'Sulu', 'Tawi-Tawi']
];

// Sort provinces alphabetically for each region
foreach ($provinces as $region => $province_list) {
    sort($provinces[$region]);
}

// Complete cities data
$cities = [
    'Metro Manila' => ['Manila', 'Quezon City', 'Caloocan', 'Las Piñas', 'Makati', 'Malabon', 'Mandaluyong', 'Marikina', 'Muntinlupa', 'Navotas', 'Parañaque', 'Pasay', 'Pasig', 'San Juan', 'Taguig', 'Valenzuela', 'Pateros'],
    'Abra' => ['Bangued', 'Boliney', 'Bucay', 'Bucloc', 'Daguioman', 'Danglas', 'Dolores', 'La Paz', 'Lacub', 'Lagangilang', 'Lagayan', 'Langiden', 'Licuan-Baay', 'Luba', 'Malibcong', 'Manabo', 'Peñarrubia', 'Pidigan', 'Pilar', 'Sallapadan', 'San Isidro', 'San Juan', 'San Quintin', 'Tayum', 'Tineg', 'Tubo', 'Villaviciosa'],
    'Apayao' => ['Calanasan', 'Conner', 'Flora', 'Kabugao', 'Luna', 'Pudtol', 'Santa Marcela'],
    'Benguet' => ['Atok', 'Baguio', 'Bakun', 'Bokod', 'Buguias', 'Itogon', 'Kabayan', 'Kapangan', 'Kibungan', 'La Trinidad', 'Mankayan', 'Sablan', 'Tuba', 'Tublay'],
    'Ifugao' => ['Aguinaldo', 'Alfonso Lista', 'Asipulo', 'Banaue', 'Hingyon', 'Hungduan', 'Kiangan', 'Lagawe', 'Lamut', 'Mayoyao', 'Tinoc'],
    'Kalinga' => ['Balbalan', 'Lubuagan', 'Pasil', 'Pinukpuk', 'Rizal', 'Tanudan', 'Tinglayan'],
    'Mountain Province' => ['Barlig', 'Bauko', 'Besao', 'Bontoc', 'Natonin', 'Paracelis', 'Sabangan', 'Sadanga', 'Sagada', 'Tadian'],
    'Ilocos Norte' => ['Adams', 'Bacarra', 'Badoc', 'Bangui', 'Banna', 'Batac', 'Burgos', 'Carasi', 'Currimao', 'Dingras', 'Dumalneg', 'Laoag', 'Marcos', 'Nueva Era', 'Pagudpud', 'Paoay', 'Pasuquin', 'Piddig', 'Pinili', 'San Nicolas', 'Sarrat', 'Solsona', 'Vintar'],
    'Ilocos Sur' => ['Alilem', 'Banayoyo', 'Bantay', 'Burgos', 'Cabugao', 'Candon', 'Caoayan', 'Cervantes', 'Galimuyod', 'Gregorio Del Pilar', 'Lidlidda', 'Magsingal', 'Nagbukel', 'Narvacan', 'Quirino', 'Salcedo', 'San Emilio', 'San Esteban', 'San Ildefonso', 'San Juan', 'San Vicente', 'Santa', 'Santa Catalina', 'Santa Cruz', 'Santa Lucia', 'Santa Maria', 'Santiago', 'Santo Domingo', 'Sigay', 'Sinait', 'Sugpon', 'Suyo', 'Tagudin', 'Vigan'],
    'La Union' => ['Agoo', 'Aringay', 'Bacnotan', 'Bagulin', 'Balaoan', 'Bangar', 'Bauang', 'Burgos', 'Caba', 'Luna', 'Naguilian', 'Pugo', 'Rosario', 'San Fernando', 'San Gabriel', 'San Juan', 'Santo Tomas', 'Santol', 'Sudipen', 'Tubao'],
    'Pangasinan' => ['Agno', 'Aguilar', 'Alaminos', 'Alcala', 'Anda', 'Asingan', 'Balungao', 'Bani', 'Basista', 'Bautista', 'Bayambang', 'Binalonan', 'Binmaley', 'Bolinao', 'Bugallon', 'Burgos', 'Calasiao', 'Dagupan', 'Dasol', 'Infanta', 'Labrador', 'Laoac', 'Lingayen', 'Mabini', 'Malasiqui', 'Manaoag', 'Mangaldan', 'Mangatarem', 'Mapandan', 'Natividad', 'Pozorrubio', 'Rosales', 'San Carlos', 'San Fabian', 'San Jacinto', 'San Manuel', 'San Nicolas', 'San Quintin', 'Santa Barbara', 'Santa Maria', 'Santo Tomas', 'Sison', 'Sual', 'Tayug', 'Umingan', 'Urbiztondo', 'Urdaneta', 'Villasis'],
    'Batanes' => ['Basco', 'Itbayat', 'Ivana', 'Mahatao', 'Sabtang', 'Uyugan'],
    'Cagayan' => ['Abulug', 'Alcala', 'Allacapan', 'Amulung', 'Aparri', 'Baggao', 'Ballesteros', 'Buguey', 'Calayan', 'Camalaniugan', 'Claveria', 'Enrile', 'Gattaran', 'Gonzaga', 'Iguig', 'Lal-lo', 'Lasam', 'Pamplona', 'Peñablanca', 'Piat', 'Rizal', 'Sanchez-Mira', 'Santa Ana', 'Santa Praxedes', 'Santa Teresita', 'Santo Niño', 'Solana', 'Tuao', 'Tuguegarao'],
    'Isabela' => ['Alicia', 'Angadanan', 'Aurora', 'Benito Soliven', 'Burgos', 'Cabagan', 'Cabatuan', 'Cauayan', 'Cordon', 'Delfin Albano', 'Dinapigue', 'Divilacan', 'Echague', 'Gamu', 'Ilagan', 'Jones', 'Luna', 'Maconacon', 'Mallig', 'Naguilian', 'Palanan', 'Quezon', 'Quirino', 'Ramon', 'Reina Mercedes', 'Roxas', 'San Agustin', 'San Guillermo', 'San Isidro', 'San Manuel', 'San Mariano', 'San Mateo', 'San Pablo', 'Santa Maria', 'Santiago', 'Santo Tomas', 'Tumauini'],
    'Nueva Vizcaya' => ['Alfonso Castaneda', 'Ambaguio', 'Aritao', 'Bagabag', 'Bambang', 'Bayombong', 'Diadi', 'Dupax del Norte', 'Dupax del Sur', 'Kasibu', 'Kayapa', 'Quezon', 'Santa Fe', 'Solano', 'Villaverde'],
    'Quirino' => ['Aglipay', 'Cabarroguis', 'Diffun', 'Maddela', 'Nagtipunan', 'Saguday'],
    'Aurora' => ['Baler', 'Casiguran', 'Dilasag', 'Dinalungan', 'Dingalan', 'Dipaculao', 'Maria Aurora', 'San Luis'],
    'Bataan' => ['Abucay', 'Bagac', 'Balanga', 'Dinalupihan', 'Hermosa', 'Limay', 'Mariveles', 'Morong', 'Orani', 'Orion', 'Pilar', 'Samal'],
    'Bulacan' => ['Angat', 'Balagtas', 'Baliuag', 'Bocaue', 'Bulakan', 'Bustos', 'Calumpit', 'Doña Remedios Trinidad', 'Guiguinto', 'Hagonoy', 'Malolos', 'Marilao', 'Meycauayan', 'Norzagaray', 'Obando', 'Pandi', 'Paombong', 'Plaridel', 'Pulilan', 'San Ildefonso', 'San Jose Del Monte', 'San Miguel', 'San Rafael', 'Santa Maria'],
    'Nueva Ecija' => ['Aliaga', 'Bongabon', 'Cabanatuan', 'Cabiao', 'Carranglan', 'Cuyapo', 'Gabaldon', 'Gapan', 'General Mamerto Natividad', 'General Tinio', 'Guimba', 'Jaen', 'Laur', 'Licab', 'Llanera', 'Lupao', 'Muñoz', 'Nampicuan', 'Palayan', 'Pantabangan', 'Peñaranda', 'Quezon', 'Rizal', 'San Antonio', 'San Isidro', 'San Jose', 'San Leonardo', 'Santa Rosa', 'Santo Domingo', 'Talavera', 'Talugtug', 'Zaragoza'],
    'Pampanga' => ['Angeles', 'Apalit', 'Arayat', 'Bacolor', 'Candaba', 'Floridablanca', 'Guagua', 'Lubao', 'Mabalacat', 'Macabebe', 'Magalang', 'Masantol', 'Mexico', 'Minalin', 'Porac', 'San Fernando', 'San Luis', 'San Simon', 'Santa Ana', 'Santa Rita', 'Santo Tomas', 'Sasmuan'],
    'Tarlac' => ['Anao', 'Bamban', 'Camiling', 'Capas', 'Concepcion', 'Gerona', 'La Paz', 'Mayantoc', 'Moncada', 'Paniqui', 'Pura', 'Ramos', 'San Clemente', 'San Jose', 'San Manuel', 'Santa Ignacia', 'Tarlac', 'Victoria'],
    'Zambales' => ['Botolan', 'Cabangan', 'Candelaria', 'Castillejos', 'Iba', 'Masinloc', 'Olongapo', 'Palauig', 'San Antonio', 'San Felipe', 'San Marcelino', 'San Narciso', 'Santa Cruz', 'Subic'],
    'Batangas' => ['Agoncillo', 'Alitagtag', 'Balayan', 'Balete', 'Batangas', 'Bauan', 'Calaca', 'Calatagan', 'Cuenca', 'Ibaan', 'Laurel', 'Lemery', 'Lian', 'Lipa', 'Lobo', 'Mabini', 'Malvar', 'Mataasnakahoy', 'Nasugbu', 'Padre Garcia', 'Rosario', 'San Jose', 'San Juan', 'San Luis', 'San Nicolas', 'San Pascual', 'Santa Teresita', 'Santo Tomas', 'Taal', 'Talisay', 'Tanauan', 'Taysan', 'Tingloy', 'Tuy'],
    'Cavite' => ['Alfonso', 'Amadeo', 'Bacoor', 'Carmona', 'Cavite', 'Dasmariñas', 'General Emilio Aguinaldo', 'General Mariano Alvarez', 'General Trias', 'Imus', 'Indang', 'Kawit', 'Magallanes', 'Maragondon', 'Mendez', 'Naic', 'Noveleta', 'Rosario', 'Silang', 'Tagaytay', 'Tanza', 'Ternate', 'Trece Martires'],
    'Laguna' => ['Alaminos', 'Bay', 'Biñan', 'Cabuyao', 'Calamba', 'Calauan', 'Cavinti', 'Famy', 'Kalayaan', 'Liliw', 'Los Baños', 'Luisiana', 'Lumban', 'Mabitac', 'Magdalena', 'Majayjay', 'Nagcarlan', 'Paete', 'Pagsanjan', 'Pakil', 'Pangil', 'Pila', 'Rizal', 'San Pablo', 'San Pedro', 'Santa Cruz', 'Santa Maria', 'Santa Rosa', 'Siniloan', 'Victoria'],
    'Quezon' => ['Agdangan', 'Alabat', 'Atimonan', 'Buenavista', 'Burdeos', 'Calauag', 'Candelaria', 'Catanauan', 'Dolores', 'General Luna', 'General Nakar', 'Guinayangan', 'Gumaca', 'Infanta', 'Jomalig', 'Lopez', 'Lucban', 'Lucena', 'Macalelon', 'Mauban', 'Mulanay', 'Padre Burgos', 'Pagbilao', 'Panukulan', 'Patnanungan', 'Perez', 'Pitogo', 'Plaridel', 'Polillo', 'Quezon', 'Real', 'Sampaloc', 'San Andres', 'San Antonio', 'San Francisco', 'San Narciso', 'Sariaya', 'Tagkawayan', 'Tayabas', 'Tiaong', 'Unisan'],
    'Rizal' => ['Angono', 'Antipolo', 'Baras', 'Binangonan', 'Cainta', 'Cardona', 'Jala-Jala', 'Morong', 'Pililla', 'Rodriguez', 'San Mateo', 'Tanay', 'Taytay', 'Teresa'],
    'Marinduque' => ['Boac', 'Buenavista', 'Gasan', 'Mogpog', 'Santa Cruz', 'Torrijos'],
    'Occidental Mindoro' => ['Abra de Ilog', 'Calintaan', 'Looc', 'Lubang', 'Magsaysay', 'Mamburao', 'Paluan', 'Rizal', 'Sablayan', 'San Jose', 'Santa Cruz'],
    'Oriental Mindoro' => ['Baco', 'Bansud', 'Bongabong', 'Bulalacao', 'Calapan', 'Gloria', 'Mansalay', 'Naujan', 'Pinamalayan', 'Pola', 'Puerto Galera', 'Roxas', 'San Teodoro', 'Socorro', 'Victoria'],
    'Palawan' => ['Aborlan', 'Agutaya', 'Araceli', 'Balabac', 'Bataraza', 'Brookes Point', 'Busuanga', 'Cagayancillo', 'Coron', 'Culion', 'Cuyo', 'Dumaran', 'El Nido', 'Kalayaan', 'Linapacan', 'Magsaysay', 'Narra', 'Puerto Princesa', 'Quezon', 'Rizal', 'Roxas', 'San Vicente', 'Sofronio Española', 'Taytay'],
    'Romblon' => ['Alcantara', 'Banton', 'Cajidiocan', 'Calatrava', 'Concepcion', 'Corcuera', 'Ferrol', 'Looc', 'Magdiwang', 'Odiongan', 'Romblon', 'San Agustin', 'San Andres', 'San Fernando', 'San Jose', 'Santa Fe', 'Santa Maria'],
    'Albay' => ['Bacacay', 'Camalig', 'Daraga', 'Guinobatan', 'Jovellar', 'Legazpi', 'Libon', 'Ligao', 'Malilipot', 'Malinao', 'Manito', 'Oas', 'Pio Duran', 'Polangui', 'Rapu-Rapu', 'Santo Domingo', 'Tabaco', 'Tiwi'],
    'Camarines Norte' => ['Basud', 'Capalonga', 'Daet', 'Jose Panganiban', 'Labo', 'Mercedes', 'Paracale', 'San Lorenzo Ruiz', 'San Vicente', 'Santa Elena', 'Talisay', 'Vinzons'],
    'Camarines Sur' => ['Baao', 'Balatan', 'Bato', 'Bombon', 'Buhi', 'Bula', 'Cabusao', 'Calabanga', 'Camaligan', 'Canaman', 'Caramoan', 'Del Gallego', 'Gainza', 'Garchitorena', 'Goa', 'Iriga', 'Lagonoy', 'Libmanan', 'Lupi', 'Magarao', 'Milaor', 'Minalabac', 'Nabua', 'Naga', 'Ocampo', 'Pamplona', 'Pasacao', 'Pili', 'Presentacion', 'Ragay', 'Sagñay', 'San Fernando', 'San Jose', 'Sipocot', 'Siruma', 'Tigaon', 'Tinambac'],
    'Catanduanes' => ['Bagamanoc', 'Baras', 'Bato', 'Caramoran', 'Gigmoto', 'Pandan', 'Panganiban', 'San Andres', 'San Miguel', 'Viga', 'Virac'],
    'Masbate' => ['Aroroy', 'Baleno', 'Balud', 'Batuan', 'Cataingan', 'Cawayan', 'Claveria', 'Dimasalang', 'Esperanza', 'Mandaon', 'Masbate', 'Milagros', 'Mobo', 'Monreal', 'Palanas', 'Pio V. Corpuz', 'Placer', 'San Fernando', 'San Jacinto', 'San Pascual', 'Uson'],
    'Sorsogon' => ['Barcelona', 'Bulan', 'Bulusan', 'Casiguran', 'Castilla', 'Donsol', 'Gubat', 'Irosin', 'Juban', 'Magallanes', 'Matnog', 'Pilar', 'Prieto Diaz', 'Santa Magdalena', 'Sorsogon'],
    'Aklan' => ['Altavas', 'Balete', 'Banga', 'Batan', 'Buruanga', 'Ibajay', 'Kalibo', 'Lezo', 'Libacao', 'Madalag', 'Makato', 'Malay', 'Malinao', 'Nabas', 'New Washington', 'Numancia', 'Tangalan'],
    'Antique' => ['Anini-y', 'Barbaza', 'Belison', 'Bugasong', 'Caluya', 'Culasi', 'Hamtic', 'Laua-an', 'Libertad', 'Pandan', 'Patnongon', 'San Jose', 'San Remigio', 'Sebaste', 'Sibalom', 'Tibiao', 'Tobias Fornier', 'Valderrama'],
    'Capiz' => ['Cuartero', 'Dao', 'Dumalag', 'Dumarao', 'Ivisan', 'Jamindan', 'Ma-ayon', 'Mambusao', 'Panay', 'Panitan', 'Pilar', 'Pontevedra', 'President Roxas', 'Roxas', 'Sapi-an', 'Sigma', 'Tapaz'],
    'Guimaras' => ['Buenavista', 'Jordan', 'Nueva Valencia', 'San Lorenzo', 'Sibunag'],
    'Iloilo' => ['Ajuy', 'Alimodian', 'Anilao', 'Badiangan', 'Balasan', 'Banate', 'Barotac Nuevo', 'Barotac Viejo', 'Batad', 'Bingawan', 'Cabatuan', 'Calinog', 'Carles', 'Concepcion', 'Dingle', 'Dueñas', 'Dumangas', 'Estancia', 'Guimbal', 'Igbaras', 'Iloilo', 'Janiuay', 'Lambunao', 'Leganes', 'Lemery', 'Leon', 'Maasin', 'Miagao', 'Mina', 'New Lucena', 'Oton', 'Passi', 'Pavia', 'Pototan', 'San Dionisio', 'San Enrique', 'San Joaquin', 'San Miguel', 'San Rafael', 'Santa Barbara', 'Sara', 'Tigbauan', 'Tubungan', 'Zarraga'],
    'Negros Occidental' => ['Bacolod', 'Bago', 'Binalbagan', 'Cadiz', 'Calatrava', 'Candoni', 'Cauayan', 'Enrique B. Magalona', 'Escalante', 'Himamaylan', 'Hinigaran', 'Hinoba-an', 'Ilog', 'Isabela', 'Kabankalan', 'La Carlota', 'La Castellana', 'Manapla', 'Moises Padilla', 'Murcia', 'Pontevedra', 'Pulupandan', 'Sagay', 'Salvador Benedicto', 'San Carlos', 'San Enrique', 'Silay', 'Sipalay', 'Talisay', 'Toboso', 'Valladolid', 'Victorias'],
    'Bohol' => ['Alburquerque', 'Alicia', 'Anda', 'Antequera', 'Baclayon', 'Balilihan', 'Batuan', 'Bien Unido', 'Bilar', 'Buenavista', 'Calape', 'Candijay', 'Carmen', 'Catigbian', 'Clarin', 'Corella', 'Cortes', 'Dagohoy', 'Danao', 'Dauis', 'Dimiao', 'Duero', 'Garcia Hernandez', 'Getafe', 'Guindulman', 'Inabanga', 'Jagna', 'Lila', 'Loay', 'Loboc', 'Loon', 'Mabini', 'Maribojoc', 'Panglao', 'Pilar', 'President Carlos P. Garcia', 'Sagbayan', 'San Isidro', 'San Miguel', 'Sevilla', 'Sierra Bullones', 'Sikatuna', 'Tagbilaran', 'Talibon', 'Trinidad', 'Tubigon', 'Ubay', 'Valencia'],
    'Cebu' => ['Alcantara', 'Alcoy', 'Alegria', 'Aloguinsan', 'Argao', 'Asturias', 'Badian', 'Balamban', 'Bantayan', 'Barili', 'Bogo', 'Boljoon', 'Borbon', 'Carcar', 'Carmen', 'Catmon', 'Cebu', 'Compostela', 'Consolacion', 'Cordova', 'Daanbantayan', 'Dalaguete', 'Danao', 'Dumanjug', 'Ginatilan', 'Lapu-Lapu', 'Liloan', 'Madridejos', 'Malabuyoc', 'Mandaue', 'Medellin', 'Minglanilla', 'Moalboal', 'Naga', 'Oslob', 'Pilar', 'Pinamungajan', 'Poro', 'Ronda', 'Samboan', 'San Fernando', 'San Francisco', 'San Remigio', 'Santa Fe', 'Santander', 'Sibonga', 'Sogod', 'Tabogon', 'Tabuelan', 'Talisay', 'Toledo', 'Tuburan', 'Tudela'],
    'Negros Oriental' => ['Amlan', 'Ayungon', 'Bacong', 'Bais', 'Basay', 'Bayawan', 'Bindoy', 'Canlaon', 'Dauin', 'Dumaguete', 'Guihulngan', 'Jimalalud', 'La Libertad', 'Mabinay', 'Manjuyod', 'Pamplona', 'San Jose', 'Santa Catalina', 'Siaton', 'Sibulan', 'Tanjay', 'Tayasan', 'Valencia', 'Vallehermoso', 'Zamboanguita'],
    'Siquijor' => ['Enrique Villanueva', 'Larena', 'Lazi', 'Maria', 'San Juan', 'Siquijor'],
    'Biliran' => ['Almeria', 'Biliran', 'Cabucgayan', 'Caibiran', 'Culaba', 'Kawayan', 'Maripipi', 'Naval'],
    'Eastern Samar' => ['Arteche', 'Balangiga', 'Balangkayan', 'Borongan', 'Can-avid', 'Dolores', 'General MacArthur', 'Giporlos', 'Guiuan', 'Hernani', 'Jipapad', 'Lawaan', 'Llorente', 'Maslog', 'Maydolong', 'Mercedes', 'Oras', 'Quinapondan', 'Salcedo', 'San Julian', 'San Policarpo', 'Sulat', 'Taft'],
    'Leyte' => ['Abuyog', 'Alangalang', 'Albuera', 'Babatngon', 'Barugo', 'Bato', 'Baybay', 'Burauen', 'Calubian', 'Capoocan', 'Carigara', 'Dagami', 'Dulag', 'Hilongos', 'Hindang', 'Inopacan', 'Isabel', 'Jaro', 'Javier', 'Julita', 'Kananga', 'La Paz', 'Leyte', 'MacArthur', 'Mahaplag', 'Matag-ob', 'Matalom', 'Mayorga', 'Ormoc', 'Palo', 'Palompon', 'Pastrana', 'San Isidro', 'San Miguel', 'Santa Fe', 'Tabango', 'Tabontabon', 'Tacloban', 'Tanauan', 'Tolosa', 'Tunga', 'Villaba'],
    'Northern Samar' => ['Allen', 'Biri', 'Bobon', 'Capul', 'Catarman', 'Catubig', 'Gamay', 'Laoang', 'Lapinig', 'Las Navas', 'Lavezares', 'Lope de Vega', 'Mapanas', 'Mondragon', 'Palapag', 'Pambujan', 'Rosario', 'San Antonio', 'San Isidro', 'San Jose', 'San Roque', 'San Vicente', 'Silvino Lobos', 'Victoria'],
    'Samar' => ['Almagro', 'Basey', 'Calbayog', 'Calbiga', 'Catbalogan', 'Daram', 'Gandara', 'Hinabangan', 'Jiabong', 'Marabut', 'Matuguinao', 'Motiong', 'Pagsanghan', 'Paranas', 'Pinabacdao', 'San Jorge', 'San Jose de Buan', 'San Sebastian', 'Santa Margarita', 'Santa Rita', 'Santo Niño', 'Tagapul-an', 'Talalora', 'Tarangnan', 'Villareal', 'Zumarraga'],
    'Southern Leyte' => ['Anahawan', 'Bontoc', 'Hinunangan', 'Hinundayan', 'Libagon', 'Liloan', 'Limasawa', 'Maasin', 'Macrohon', 'Malitbog', 'Padre Burgos', 'Pintuyan', 'Saint Bernard', 'San Francisco', 'San Juan', 'San Ricardo', 'Silago', 'Sogod', 'Tomas Oppus'],
    'Zamboanga del Norte' => ['Baliguian', 'Dapitan', 'Dipolog', 'Godod', 'Gutalac', 'Jose Dalman', 'Kalawit', 'Katipunan', 'La Libertad', 'Labason', 'Leon B. Postigo', 'Liloy', 'Manukan', 'Mutia', 'Piñan', 'Polanco', 'President Manuel A. Roxas', 'Rizal', 'Salug', 'Sergio Osmeña Sr.', 'Siayan', 'Sibuco', 'Sibutad', 'Sindangan', 'Siocon', 'Sirawai', 'Tampilisan'],
    'Zamboanga del Sur' => ['Aurora', 'Bayog', 'Dimataling', 'Dinas', 'Dumalinao', 'Dumingag', 'Guipos', 'Josefina', 'Kumalarang', 'Labangan', 'Lakewood', 'Lapuyan', 'Mahayag', 'Margosatubig', 'Midsalip', 'Molave', 'Pagadian', 'Pitogo', 'Ramon Magsaysay', 'San Miguel', 'San Pablo', 'Sominot', 'Tabina', 'Tambulig', 'Tigbao', 'Tukuran', 'Vincenzo A. Sagun', 'Zamboanga'],
    'Zamboanga Sibugay' => ['Alicia', 'Buug', 'Diplahan', 'Imelda', 'Ipil', 'Kabasalan', 'Mabuhay', 'Malangas', 'Naga', 'Olutanga', 'Payao', 'Roseller Lim', 'Siay', 'Talusan', 'Titay', 'Tungawan'],
    'Bukidnon' => ['Baungon', 'Cabanglasan', 'Damulog', 'Dangcagan', 'Don Carlos', 'Impasugong', 'Kadingilan', 'Kalilangan', 'Kibawe', 'Kitaotao', 'Lantapan', 'Libona', 'Malaybalay', 'Malitbog', 'Manolo Fortich', 'Maramag', 'Pangantucan', 'Quezon', 'San Fernando', 'Sumilao', 'Talakag', 'Valencia'],
    'Camiguin' => ['Catarman', 'Guinsiliban', 'Mahinog', 'Mambajao', 'Sagay'],
    'Lanao del Norte' => ['Bacolod', 'Baloi', 'Baroy', 'Iligan', 'Kapatagan', 'Kauswagan', 'Kolambugan', 'Lala', 'Linamon', 'Magsaysay', 'Maigo', 'Matungao', 'Munai', 'Nunungan', 'Pantao Ragat', 'Pantar', 'Poona Piagapo', 'Salvador', 'Sapad', 'Sultan Naga Dimaporo', 'Tagoloan', 'Tangcal', 'Tubod'],
    'Misamis Occidental' => ['Aloran', 'Baliangao', 'Bonifacio', 'Calamba', 'Clarin', 'Concepcion', 'Don Victoriano Chiongbian', 'Jimenez', 'Lopez Jaena', 'Oroquieta', 'Ozamiz', 'Panaon', 'Plaridel', 'Sapang Dalaga', 'Sinacaban', 'Tangub', 'Tudela'],
    'Misamis Oriental' => ['Alubijid', 'Balingasag', 'Balingoan', 'Binuangan', 'Cagayan de Oro', 'Claveria', 'El Salvador', 'Gingoog', 'Gitagum', 'Initao', 'Jasaan', 'Kinoguitan', 'Lagonglong', 'Laguindingan', 'Libertad', 'Lugait', 'Magsaysay', 'Manticao', 'Medina', 'Naawan', 'Opol', 'Salay', 'Sugbongcogon', 'Tagoloan', 'Talisayan', 'Villanueva'],
    'Davao de Oro' => ['Compostela', 'Laak', 'Mabini', 'Maco', 'Maragusan', 'Mawab', 'Monkayo', 'Montevista', 'Nabunturan', 'New Bataan', 'Pantukan'],
    'Davao del Norte' => ['Asuncion', 'Braulio E. Dujali', 'Carmen', 'Kapalong', 'New Corella', 'Panabo', 'Samal', 'San Isidro', 'Santo Tomas', 'Tagum', 'Talaingod'],
    'Davao del Sur' => ['Bansalan', 'Davao', 'Digos', 'Hagonoy', 'Kiblawan', 'Magsaysay', 'Malalag', 'Matanao', 'Padada', 'Santa Cruz', 'Sulop'],
    'Davao Occidental' => ['Don Marcelino', 'Jose Abad Santos', 'Malita', 'Santa Maria', 'Sarangani'],
    'Davao Oriental' => ['Baganga', 'Banga', 'Boston', 'Caraga', 'Cateel', 'Governor Generoso', 'Lupon', 'Manay', 'Mati', 'San Isidro', 'Tarragona'],
    'Cotabato' => ['Alamada', 'Aleosan', 'Antipas', 'Arakan', 'Banisilan', 'Carmen', 'Kabacan', 'Kidapawan', 'Libungan', "M'lang", 'Magpet', 'Makilala', 'Matalam', 'Midsayap', 'Pigcawayan', 'Pikit', 'President Roxas', 'Tulunan'],
    'Sarangani' => ['Alabel', 'Glan', 'Kiamba', 'Maasim', 'Maitum', 'Malapatan', 'Malungon'],
    'South Cotabato' => ['Banga', 'General Santos', 'Koronadal', 'Lake Sebu', 'Norala', 'Polomolok', 'Santo Niño', 'Surallah', "T'boli", 'Tampakan', 'Tantangan', 'Tupi'],
    'Sultan Kudarat' => ['Bagumbayan', 'Columbio', 'Esperanza', 'Isulan', 'Kalamansig', 'Lambayong', 'Lebak', 'Lutayan', 'Palimbang', 'President Quirino', 'Senator Ninoy Aquino', 'Tacurong'],
    'Agusan del Norte' => ['Buenavista', 'Butuan', 'Cabadbaran', 'Carmen', 'Jabonga', 'Kitcharao', 'Las Nieves', 'Magallanes', 'Nasipit', 'Remedios T. Romualdez', 'Santiago', 'Tubay'],
    'Agusan del Sur' => ['Bayugan', 'Bunawan', 'Esperanza', 'La Paz', 'Loreto', 'Prosperidad', 'Rosario', 'San Francisco', 'San Luis', 'Santa Josefa', 'Sibagat', 'Talacogon', 'Trento', 'Veruela'],
    'Dinagat Islands' => ['Basilisa', 'Cagdianao', 'Dinagat', 'Libjo', 'Loreto', 'San Jose', 'Tubajon'],
    'Surigao del Norte' => ['Alegria', 'Bacuag', 'Burgos', 'Claver', 'Dapa', 'Del Carmen', 'General Luna', 'Gigaquit', 'Mainit', 'Malimono', 'Pilar', 'Placer', 'San Benito', 'San Francisco', 'San Isidro', 'Santa Monica', 'Sison', 'Socorro', 'Surigao', 'Tagana-an', 'Tubod'],
    'Surigao del Sur' => ['Barobo', 'Bayabas', 'Bislig', 'Cagwait', 'Cantilan', 'Carmen', 'Carrascal', 'Cortes', 'Hinatuan', 'Lanuza', 'Lianga', 'Lingig', 'Madrid', 'Marihatag', 'San Agustin', 'San Miguel', 'Tagbina', 'Tago', 'Tandag'],
    'Basilan' => ['Akbar', 'Al-Barka', 'Hadji Mohammad Ajul', 'Hadji Muhtamad', 'Isabela', 'Lamitan', 'Lantawan', 'Maluso', 'Sumisip', 'Tabuan-Lasa', 'Tipo-Tipo', 'Tuburan', 'Ungkaya Pukan'],
    'Lanao del Sur' => ['Amai Manabilang', 'Bacolod-Kalawi', 'Balabagan', 'Balindong', 'Bayang', 'Binidayan', 'Buadiposo-Buntong', 'Bubong', 'Butig', 'Calanogas', 'Ditsaan-Ramain', 'Ganassi', 'Kapai', 'Kapatagan', 'Lumba-Bayabao', 'Lumbaca-Unayan', 'Lumbatan', 'Lumbayanague', 'Madalum', 'Madamba', 'Maguing', 'Malabang', 'Marantao', 'Marawi', 'Marogong', 'Masiu', 'Mulondo', 'Pagayawan', 'Piagapo', 'Poona Bayabao', 'Pualas', 'Saguiaran', 'Sultan Dumalondong', 'Tagoloan II', 'Tamparan', 'Taraka', 'Tubaran', 'Tugaya', 'Wao'],
    'Maguindanao' => ['Ampatuan', 'Barira', 'Buldon', 'Buluan', 'Datu Abdullah Sangki', 'Datu Anggal Midtimbang', 'Datu Blah T. Sinsuat', 'Datu Hoffer Ampatuan', 'Datu Montawal', 'Datu Odin Sinsuat', 'Datu Paglas', 'Datu Piang', 'Datu Salibo', 'Datu Saudi-Ampatuan', 'Datu Unsay', 'General Salipada K. Pendatun', 'Guindulungan', 'Kabuntalan', 'Mamasapano', 'Mangudadatu', 'Matanog', 'Northern Kabuntalan', 'Pagalungan', 'Paglat', 'Pandag', 'Parang', 'Rajah Buayan', 'Shariff Aguak', 'Shariff Saydona Mustapha', 'South Upi', 'Sultan Kudarat', 'Sultan Mastura', 'Sultan sa Barongis', 'Talayan', 'Upi'],
    'Sulu' => ['Hadji Panglima Tahil', 'Indanan', 'Jolo', 'Kalingalan Caluang', 'Lugus', 'Luuk', 'Maimbung', 'Old Panamao', 'Omar', 'Pandami', 'Panglima Estino', 'Pangutaran', 'Parang', 'Pata', 'Patikul', 'Siasi', 'Talipao', 'Tapul'],
    'Tawi-Tawi' => ['Bongao', 'Languyan', 'Mapun', 'Panglima Sugala', 'Sapa-Sapa', 'Sibutu', 'Simunul', 'Sitangkai', 'South Ubian', 'Tandubas', 'Turtle Islands']
];

// Sort cities alphabetically for each province
foreach ($cities as $province => $city_list) {
    sort($cities[$province]);
}

// Function to generate unique supplier code
function generateSupplierCode($conn) {
    $prefix = 'SUP-';
    $year = date('Y');
    $month = date('m');
    
    // Get the latest supplier code for this year/month
    $query = "SELECT supplier_code FROM suppliers 
              WHERE supplier_code LIKE '$prefix$year$month%' 
              ORDER BY supplier_code DESC LIMIT 1";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $last_code = $row['supplier_code'];
        // Extract the sequence number
        $sequence = intval(substr($last_code, -4)) + 1;
    } else {
        $sequence = 1;
    }
    
    // Format: SUP-YYYYMM-XXXX
    $new_code = $prefix . $year . $month . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    return $new_code;
}

// Generate a preview code for the modal
$preview_code = generateSupplierCode($conn);

// Get base64 encoded logo for printing
$logo_path = '../Pictures/amgc3DLogo.png';
$logo_base64 = '';
if (file_exists($logo_path)) {
    $image_data = file_get_contents($logo_path);
    $logo_base64 = 'data:image/png;base64,' . base64_encode($image_data);
}

// Determine branch filter condition
$branch_condition = "";
if ($suppliers_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $branch_condition = "AND s.branch_id = " . intval($branch_id);
}

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        $conn->begin_transaction();
        
        // ADD SUPPLIER
        if ($_POST['action'] === 'add_supplier') {
            $supplier_code = $_POST['supplier_code'] ?? '';
            $supplier_name = $_POST['supplier_name'] ?? '';
            $contact_person = $_POST['contact_person'] ?? null;
            $email = $_POST['email'] ?? null;
            $phone_number = $_POST['phone_number'] ?? null;
            $mobile_number = $_POST['mobile_number'] ?? null;
            
            // Address fields
            $region = $_POST['region'] ?? null;
            $province = $_POST['province'] ?? null;
            $city = $_POST['city'] ?? null;
            $city_code = $_POST['city_code'] ?? '';
            $barangay = $_POST['barangay'] ?? null;
            $street_address = $_POST['street_address'] ?? null;
            
            // Combine address components for full_address
            $full_address_parts = [];
            if (!empty($street_address)) $full_address_parts[] = $street_address;
            if (!empty($barangay)) $full_address_parts[] = $barangay;
            if (!empty($city)) $full_address_parts[] = $city;
            if (!empty($province)) $full_address_parts[] = $province;
            if (!empty($region)) $full_address_parts[] = $region;
            $full_address = implode(', ', $full_address_parts);
            
            // Business information
            $tax_id = $_POST['tax_id'] ?? null;
            $vat_classification = $_POST['vat_classification'] ?? 'VAT Registered';
            $payment_terms = $_POST['payment_terms'] ?? 'Net 30';
            $credit_limit = (float)($_POST['credit_limit'] ?? 0);
            $website = $_POST['website'] ?? null;
            $notes = $_POST['notes'] ?? null;
            $status = $_POST['status'] ?? 'active';
            
            // Latitude/Longitude (optional) - keeping but will be null by default
            $latitude = null;
            $longitude = null;
            
            // Validate required fields
            if (empty($supplier_code)) throw new Exception('Supplier code is required');
            if (empty($supplier_name)) throw new Exception('Supplier name is required');
            
            // Check if supplier code already exists
            $check_query = "SELECT supplier_id FROM suppliers WHERE supplier_code = ?";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("s", $supplier_code);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                throw new Exception('Supplier code already exists');
            }
            
            // Insert supplier
            $insert_query = "INSERT INTO suppliers (
                supplier_code, supplier_name, contact_person, email, phone_number, mobile_number,
                region, province, city, city_code, barangay, street_address, full_address,
                latitude, longitude, tax_id, vat_classification, payment_terms, credit_limit, website, notes, 
                status, branch_id, created_by, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            
            $insert_stmt = $conn->prepare($insert_query);
            if (!$insert_stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            
            $insert_stmt->bind_param(
                "sssssssssssssssssssssssi",
                $supplier_code, $supplier_name, $contact_person, $email, $phone_number, $mobile_number,
                $region, $province, $city, $city_code, $barangay, $street_address, $full_address,
                $latitude, $longitude, $tax_id, $vat_classification, $payment_terms, $credit_limit, $website, $notes,
                $status, $branch_id, $user_id
            );
            
            if (!$insert_stmt->execute()) {
                throw new Exception('Failed to add supplier: ' . $insert_stmt->error);
            }
            
            $supplier_id = $conn->insert_id;
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Supplier added successfully',
                'supplier_id' => $supplier_id
            ]);
            exit;
        }
        
        // UPDATE SUPPLIER
        elseif ($_POST['action'] === 'update_supplier') {
            $supplier_id = (int)($_POST['supplier_id'] ?? 0);
            $supplier_code = $_POST['supplier_code'] ?? '';
            $supplier_name = $_POST['supplier_name'] ?? '';
            $contact_person = $_POST['contact_person'] ?? null;
            $email = $_POST['email'] ?? null;
            $phone_number = $_POST['phone_number'] ?? null;
            $mobile_number = $_POST['mobile_number'] ?? null;
            
            // Address fields
            $region = $_POST['region'] ?? null;
            $province = $_POST['province'] ?? null;
            $city = $_POST['city'] ?? null;
            $city_code = $_POST['city_code'] ?? '';
            $barangay = $_POST['barangay'] ?? null;
            $street_address = $_POST['street_address'] ?? null;
            
            // Combine address components for full_address
            $full_address_parts = [];
            if (!empty($street_address)) $full_address_parts[] = $street_address;
            if (!empty($barangay)) $full_address_parts[] = $barangay;
            if (!empty($city)) $full_address_parts[] = $city;
            if (!empty($province)) $full_address_parts[] = $province;
            if (!empty($region)) $full_address_parts[] = $region;
            $full_address = implode(', ', $full_address_parts);
            
            // Business information
            $tax_id = $_POST['tax_id'] ?? null;
            $vat_classification = $_POST['vat_classification'] ?? 'VAT Registered';
            $payment_terms = $_POST['payment_terms'] ?? 'Net 30';
            $credit_limit = (float)($_POST['credit_limit'] ?? 0);
            $website = $_POST['website'] ?? null;
            $notes = $_POST['notes'] ?? null;
            $status = $_POST['status'] ?? 'active';
            
            // Latitude/Longitude (optional) - keeping but will be null by default
            $latitude = null;
            $longitude = null;
            
            // Validate required fields
            if ($supplier_id <= 0) throw new Exception('Invalid supplier ID');
            if (empty($supplier_code)) throw new Exception('Supplier code is required');
            if (empty($supplier_name)) throw new Exception('Supplier name is required');
            
            // Check if supplier code already exists for another supplier
            $check_query = "SELECT supplier_id FROM suppliers WHERE supplier_code = ? AND supplier_id != ?";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("si", $supplier_code, $supplier_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                throw new Exception('Supplier code already exists for another supplier');
            }
            
            // Verify supplier belongs to user's branch (if not admin)
            if ($suppliers_branch_column_exists && !$view_all_branches) {
                $check_branch_query = "SELECT supplier_id FROM suppliers WHERE supplier_id = ? AND branch_id = ?";
                $check_branch_stmt = $conn->prepare($check_branch_query);
                $check_branch_stmt->bind_param("ii", $supplier_id, $branch_id);
                $check_branch_stmt->execute();
                $check_branch_result = $check_branch_stmt->get_result();
                
                if ($check_branch_result->num_rows === 0) {
                    throw new Exception('Supplier not found or access denied');
                }
            }
            
            // Update supplier
            $update_query = "UPDATE suppliers SET
                supplier_code = ?, supplier_name = ?, contact_person = ?, email = ?,
                phone_number = ?, mobile_number = ?,
                region = ?, province = ?, city = ?, city_code = ?, barangay = ?,
                street_address = ?, full_address = ?,
                latitude = ?, longitude = ?,
                tax_id = ?, vat_classification = ?, payment_terms = ?, credit_limit = ?,
                website = ?, notes = ?, status = ?, updated_at = NOW()
                WHERE supplier_id = ?";
            
            $update_stmt = $conn->prepare($update_query);
            if (!$update_stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            
            $update_stmt->bind_param(
                "sssssssssssssssssssssssi",
                $supplier_code, $supplier_name, $contact_person, $email,
                $phone_number, $mobile_number,
                $region, $province, $city, $city_code, $barangay,
                $street_address, $full_address,
                $latitude, $longitude,
                $tax_id, $vat_classification, $payment_terms, $credit_limit,
                $website, $notes, $status, $supplier_id
            );
            
            if (!$update_stmt->execute()) {
                throw new Exception('Failed to update supplier: ' . $update_stmt->error);
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Supplier updated successfully'
            ]);
            exit;
        }
        
        // DELETE SUPPLIER
        elseif ($_POST['action'] === 'delete_supplier') {
            $supplier_id = (int)($_POST['supplier_id'] ?? 0);
            
            if ($supplier_id <= 0) throw new Exception('Invalid supplier ID');
            
            // Verify supplier belongs to user's branch (if not admin)
            if ($suppliers_branch_column_exists && !$view_all_branches) {
                $check_branch_query = "SELECT supplier_id FROM suppliers WHERE supplier_id = ? AND branch_id = ?";
                $check_branch_stmt = $conn->prepare($check_branch_query);
                $check_branch_stmt->bind_param("ii", $supplier_id, $branch_id);
                $check_branch_stmt->execute();
                $check_branch_result = $check_branch_stmt->get_result();
                
                if ($check_branch_result->num_rows === 0) {
                    throw new Exception('Supplier not found or access denied');
                }
            }
            
            // Check if supplier is used in purchase orders
            $check_po_query = "SELECT COUNT(*) as count FROM purchase_orders WHERE supplier_name = (
                SELECT supplier_name FROM suppliers WHERE supplier_id = ?
            )";
            $check_po_stmt = $conn->prepare($check_po_query);
            $check_po_stmt->bind_param("i", $supplier_id);
            $check_po_stmt->execute();
            $po_result = $check_po_stmt->get_result();
            $po_count = $po_result->fetch_assoc()['count'] ?? 0;
            
            if ($po_count > 0) {
                // Soft delete - just update status to inactive
                $update_query = "UPDATE suppliers SET status = 'inactive', updated_at = NOW() WHERE supplier_id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("i", $supplier_id);
                $update_stmt->execute();
                
                $message = 'Supplier deactivated successfully (used in purchase orders)';
            } else {
                // Hard delete if not used
                $delete_query = "DELETE FROM suppliers WHERE supplier_id = ?";
                $delete_stmt = $conn->prepare($delete_query);
                $delete_stmt->bind_param("i", $supplier_id);
                $delete_stmt->execute();
                
                $message = 'Supplier deleted successfully';
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => $message
            ]);
            exit;
        }
        
        // GET SUPPLIER DETAILS
        elseif ($_POST['action'] === 'get_supplier') {
            $supplier_id = (int)($_POST['supplier_id'] ?? 0);
            
            if ($supplier_id <= 0) throw new Exception('Invalid supplier ID');
            
            // Add branch filter if needed
            $query = "SELECT s.*, b.branch_name, CONCAT(u.first_name, ' ', u.last_name) as created_by_name
                     FROM suppliers s
                     LEFT JOIN branches b ON s.branch_id = b.branch_id
                     LEFT JOIN users u ON s.created_by = u.user_id
                     WHERE s.supplier_id = ?";
            
            if ($suppliers_branch_column_exists && !$view_all_branches) {
                $query .= " AND s.branch_id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ii", $supplier_id, $branch_id);
            } else {
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $supplier_id);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            $supplier = $result->fetch_assoc();
            
            if ($supplier) {
                // Get purchase order history for this supplier
                $po_query = "SELECT 
                                po.po_id, po.po_number, po.order_date, po.total_amount, 
                                po.po_status, COUNT(poi.po_item_id) as item_count,
                                SUM(poi.quantity_ordered) as total_quantity
                            FROM purchase_orders po
                            LEFT JOIN purchase_order_items poi ON po.po_id = poi.po_id
                            WHERE po.supplier_name = ?
                            GROUP BY po.po_id
                            ORDER BY po.order_date DESC
                            LIMIT 10";
                
                $po_stmt = $conn->prepare($po_query);
                $po_stmt->bind_param("s", $supplier['supplier_name']);
                $po_stmt->execute();
                $po_result = $po_stmt->get_result();
                $purchase_orders = $po_result->fetch_all(MYSQLI_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'supplier' => $supplier,
                    'purchase_orders' => $purchase_orders
                ]);
            } else {
                throw new Exception('Supplier not found');
            }
            exit;
        }
        
        // GET ALL SUPPLIERS
        elseif ($_POST['action'] === 'get_all_suppliers') {
            $filter_data = json_decode($_POST['filter_data'] ?? '{}', true);
            
            $query = "SELECT s.*, b.branch_name,
                        (SELECT COUNT(*) FROM purchase_orders WHERE supplier_name = s.supplier_name) as po_count,
                        (SELECT COUNT(*) FROM purchase_orders WHERE supplier_name = s.supplier_name AND po_status = 'received') as completed_orders,
                        (SELECT COALESCE(SUM(total_amount), 0) FROM purchase_orders WHERE supplier_name = s.supplier_name) as total_spent
                     FROM suppliers s
                     LEFT JOIN branches b ON s.branch_id = b.branch_id
                     WHERE 1=1";
            
            // Apply filters
            if (!empty($filter_data['status']) && $filter_data['status'] !== 'all') {
                $query .= " AND s.status = '" . $conn->real_escape_string($filter_data['status']) . "'";
            }
            
            if (!empty($filter_data['search'])) {
                $search = $conn->real_escape_string($filter_data['search']);
                $query .= " AND (s.supplier_name LIKE '%$search%' OR s.supplier_code LIKE '%$search%' OR s.email LIKE '%$search%')";
            }
            
            // Apply branch filter
            if ($suppliers_branch_column_exists && !$view_all_branches) {
                $query .= " AND s.branch_id = $branch_id";
            }
            
            $query .= " ORDER BY s.supplier_name ASC";
            
            $result = $conn->query($query);
            $suppliers = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            
            echo json_encode([
                'success' => true,
                'suppliers' => $suppliers,
                'branch_name' => $branch_id ? ('Branch ' . $branch_id) : 'All Branches',
                'view_all' => $view_all_branches
            ]);
            exit;
        }
        
        // PRINT SUPPLIERS REPORT
        elseif ($_POST['action'] === 'print_suppliers') {
            $filter_data = json_decode($_POST['filter_data'] ?? '{}', true);
            
            $query = "SELECT s.*, b.branch_name,
                        (SELECT COUNT(*) FROM purchase_orders WHERE supplier_name = s.supplier_name) as po_count,
                        (SELECT COALESCE(SUM(total_amount), 0) FROM purchase_orders WHERE supplier_name = s.supplier_name) as total_spent
                     FROM suppliers s
                     LEFT JOIN branches b ON s.branch_id = b.branch_id
                     WHERE 1=1";
            
            // Apply filters
            if (!empty($filter_data['status']) && $filter_data['status'] !== 'all') {
                $query .= " AND s.status = '" . $conn->real_escape_string($filter_data['status']) . "'";
            }
            
            if (!$view_all_branches && $suppliers_branch_column_exists) {
                $query .= " AND s.branch_id = $branch_id";
            }
            
            $query .= " ORDER BY s.supplier_name ASC";
            
            $result = $conn->query($query);
            $suppliers = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            
            echo json_encode([
                'success' => true,
                'suppliers' => $suppliers,
                'branch_name' => $branch_id ? ('Branch ' . $branch_id) : 'All Branches',
                'view_all' => $view_all_branches
            ]);
            exit;
        }
        
        // GENERATE SUPPLIER CODE
        elseif ($_POST['action'] === 'generate_code') {
            $supplier_code = generateSupplierCode($conn);
            
            echo json_encode([
                'success' => true,
                'supplier_code' => $supplier_code
            ]);
            exit;
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// FETCH SUPPLIERS FOR INITIAL DISPLAY WITH BRANCH FILTERING
$suppliers_query = "SELECT s.*, b.branch_name,
                    (SELECT COUNT(*) FROM purchase_orders WHERE supplier_name = s.supplier_name) as po_count,
                    (SELECT COALESCE(SUM(total_amount), 0) FROM purchase_orders WHERE supplier_name = s.supplier_name) as total_spent
                  FROM suppliers s
                  LEFT JOIN branches b ON s.branch_id = b.branch_id
                  WHERE 1=1
                  $branch_condition
                  ORDER BY s.supplier_name ASC";

$suppliers_result = $conn->query($suppliers_query);
$suppliers = $suppliers_result ? $suppliers_result->fetch_all(MYSQLI_ASSOC) : [];

// Calculate statistics
$total_suppliers = count($suppliers);
$active_suppliers = count(array_filter($suppliers, fn($s) => $s['status'] === 'active'));
$inactive_suppliers = count(array_filter($suppliers, fn($s) => $s['status'] === 'inactive'));
$pending_suppliers = count(array_filter($suppliers, fn($s) => $s['status'] === 'pending'));
$total_spent = array_sum(array_column($suppliers, 'total_spent'));

// Helper functions
function getSupplierStatusClass($status) {
    $badges = [
        'active' => 'bg-success',
        'inactive' => 'bg-danger',
        'pending' => 'bg-warning'
    ];
    return $badges[$status] ?? 'bg-secondary';
}

function formatDate($dateStr) {
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y');
}

function formatDateTime($dateStr) {
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y H:i');
}

function getPaymentTermsOptions($selected = 'Net 30') {
    $options = ['Net 30', 'Net 15', 'Net 45', 'Net 60', 'COD', '2/10 Net 30'];
    $html = '';
    foreach ($options as $option) {
        $html .= '<option value="' . $option . '" ' . ($selected == $option ? 'selected' : '') . '>' . $option . '</option>';
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suppliers - Branch Admin</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- SheetJS for Excel Export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Leaflet CSS for Maps -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <!-- jQuery (required for Select2) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <style>
        /* Branch badge styling */
        .branch-badge {
            background-color: #e7f1ff;
            color: #0d6efd;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 5px;
        }
        
        /* Alert for missing table */
        .alert-info {
            background-color: #d1ecf1;
            border-color: #bee5eb;
            color: #0c5460;
        }
        
        .alert-info code {
            background-color: #f8f9fa;
            padding: 2px 4px;
            border-radius: 4px;
            color: #c7254e;
        }
        
        /* Supplier table styling */
        .supplier-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            background-color: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .supplier-table thead th {
            background-color: #f8f9fa;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #495057;
            padding: 14px 12px;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
            vertical-align: middle;
            text-align: left;
        }
        
        .supplier-table tbody td {
            padding: 14px 12px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            font-size: 13px;
        }
        
        .supplier-table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        /* Column widths */
        .col-code { width: 10%; }
        .col-name { width: 18%; }
        .col-contact { width: 15%; }
        .col-phone { width: 12%; }
        .col-email { width: 15%; }
        .col-payment { width: 10%; }
        .col-status { width: 8%; }
        <?php if ($suppliers_branch_column_exists && $view_all_branches): ?>
        .col-branch { width: 8%; }
        <?php endif; ?>
        .col-actions { width: 12%; text-align: center; } /* increased to accommodate call/msg buttons */
        
        /* Stats for supplier metrics */
        .supplier-stat-card {
            background-color: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            border-left: 4px solid #2E7D32;
            height: 100%;
            transition: transform 0.2s;
        }
        
        .supplier-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .supplier-stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #212529;
            line-height: 1.2;
        }
        
        .supplier-stat-label {
            font-size: 0.85rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Filter section */
        .filter-section {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 25px;
            padding: 16px 20px;
            background-color: #f8f9fa;
            border-radius: 8px;
        }
        
        .filter-controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            flex: 1;
        }
        
        .filter-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-dropdowns {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }
        
        .filter-dropdown {
            min-width: 160px;
        }
        
        .filter-dropdown .form-select {
            font-size: 13px;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #ced4da;
            background-color: white;
            cursor: pointer;
        }
        
        .search-box {
            position: relative;
            min-width: 250px;
        }
        
        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            font-size: 14px;
            z-index: 10;
            pointer-events: none;
        }
        
        .search-box input {
            width: 100%;
            padding: 8px 12px 8px 38px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            height: 40px;
            font-size: 14px;
        }
        
        /* Status badge */
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 500;
            border-radius: 20px;
            text-align: center;
            min-width: 85px;
        }
        
        .status-active { background-color: #d4edda; color: #155724; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
        .status-pending { background-color: #fff3cd; color: #856404; }
        
        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 3px;
            justify-content: center;
            align-items: center;
        }
        
        .btn-action {
            background: none;
            border: none;
            padding: 5px;
            border-radius: 4px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 13px;
        }
        
        .btn-action:hover {
            background-color: #e9ecef;
        }
        
        .btn-view { color: #0d6efd; }
        .btn-edit { color: #198754; }
        .btn-delete { color: #dc3545; }
        .btn-call { color: #17a2b8; }   /* teal */
        .btn-message { color: #6f42c1; } /* purple */
        
        /* Empty state */
        .empty-state-table {
            text-align: center;
            padding: 40px 20px;
            background-color: white;
            border-radius: 8px;
        }
        
        .empty-state-table i {
            font-size: 48px;
            color: #adb5bd;
            margin-bottom: 16px;
        }
        
        .empty-state-table h5 {
            color: #495057;
            margin-bottom: 8px;
        }
        
        .empty-state-table p {
            color: #6c757d;
            margin-bottom: 8px;
        }
        
        /* Supplier details styling */
        .supplier-details-card {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .detail-label {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 4px;
        }
        
        .detail-value {
            font-size: 16px;
            font-weight: 600;
            color: #212529;
        }
        
        .contact-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }
        
        .contact-info i {
            font-size: 16px;
            color: #2E7D32;
        }
        
        /* Address preview styling (from customer.php) */
        .address-preview {
            background-color: #f8f9fa;
            border-left: 3px solid #0d6efd;
            padding: 10px 15px;
            margin-top: 10px;
            margin-bottom: 10px;
            border-radius: 0 5px 5px 0;
            font-size: 0.95em;
        }
        
        .form-section-title {
            font-size: 1rem;
            font-weight: 600;
            color: #495057;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e9ecef;
        }
        
        /* Loading indicator (from customer.php) */
        .loading-spinner {
            display: inline-block;
            width: 1rem;
            height: 1rem;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Manual entry toggle button (from customer.php) */
        .manual-toggle-btn {
            font-size: 0.8rem;
            margin-top: 5px;
            cursor: pointer;
            color: #0d6efd;
        }
        
        .manual-toggle-btn:hover {
            text-decoration: underline;
        }
        
        /* Auto-generated code styling (from customer.php) */
        .code-preview {
            background-color: #e9ecef;
            border: 1px solid #ced4da;
            border-radius: 5px;
            padding: 10px 15px;
            font-family: monospace;
            font-size: 1.1em;
            color: #0d6efd;
            font-weight: bold;
            margin-bottom: 15px;
        }
        
        .code-label {
            font-size: 0.85em;
            color: #6c757d;
            margin-bottom: 5px;
        }
        
        .refresh-code {
            cursor: pointer;
            color: #0d6efd;
            margin-left: 10px;
        }
        
        .refresh-code:hover {
            color: #0a58ca;
        }
        
        /* Print Frame */
        #printFrame {
            position: absolute;
            left: -9999px;
            top: -9999px;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
        }

        /* Compact print styles - only logo has color */
        @media print {
            @page {
                size: landscape;
                margin: 0.3in;
            }
            
            body * {
                visibility: hidden;
                background: white !important;
                color: black !important;
                border-color: black !important;
            }
            
            #printFrame, #printFrame * {
                visibility: visible;
            }
            
            #printFrame {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                height: auto;
                border: none;
            }
            
            /* Only keep the logo colored */
            #printFrame img {
                filter: none !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            /* Everything else black and white */
            #printFrame * {
                background: white !important;
                color: black !important;
                border-color: #000 !important;
                box-shadow: none !important;
                text-shadow: none !important;
                -webkit-print-color-adjust: economy;
                print-color-adjust: economy;
            }
            
            /* Table borders in black */
            #printFrame table, 
            #printFrame th, 
            #printFrame td {
                border: 1px solid #000 !important;
            }
            
            /* Header background to white with black text */
            #printFrame th {
                background: white !important;
                color: black !important;
                font-weight: bold;
            }
        }
        
        /* Modal styling */
        .modal-body {
            padding: 1rem 1rem 0.5rem 1rem;
        }
        
        .modal-footer {
            padding: 0.75rem 1rem;
        }
        
        .modal-xl {
            max-width: 1200px;
        }
        
        .modal .row.g-2 {
            margin-bottom: 0.25rem !important;
        }
        
        .modal .row.g-2 > [class*="col-"] {
            padding-bottom: 0.25rem;
        }
        
        .modal .fw-bold.border-bottom {
            margin-top: 0.25rem !important;
            margin-bottom: 0.5rem !important;
            padding-bottom: 0.25rem !important;
        }
        
        .modal .alert {
            margin-bottom: 0.5rem;
            padding: 0.5rem 1rem;
        }
        
        .modal label.form-label {
            margin-bottom: 0.15rem;
            font-size: 0.85rem;
        }
        
        .modal .form-control, .modal .form-select, .modal .input-group {
            min-height: 32px;
            padding: 0.25rem 0.5rem;
            font-size: 0.9rem;
        }
        
        .modal .input-group .btn {
            padding: 0.25rem 0.75rem;
            font-size: 0.9rem;
        }
        
        .modal textarea.form-control {
            min-height: 60px;
        }
        
        .modal .text-muted {
            font-size: 0.75rem;
            margin-top: 0.1rem;
        }
        
        /* Address dropdown styling */
        .address-row {
            margin-bottom: 0.5rem;
        }
        
        .select2-container--default .select2-selection--single {
            height: 32px;
            border: 1px solid #ced4da;
            border-radius: 4px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 32px;
            padding-left: 8px;
            font-size: 0.9rem;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 30px;
        }
        
        .select2-container--default .select2-results__option {
            padding: 4px 8px;
            font-size: 0.9rem;
        }
        
        .select2-dropdown {
            border: 1px solid #ced4da;
        }
    </style>
</head>
<body>
    <!-- Print Frame (hidden) -->
    <iframe id="printFrame" name="printFrame"></iframe>

    <!-- MAIN APPLICATION -->
    <div id="appPage">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>
                    <button class="desktop-toggle-btn" id="desktopToggleBtn">
                        <i class="bi bi-list"></i>
                    </button>    
                    <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> 
                    <span class="nav-text">Branch Admin</span>
                </h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="current_inventory.php">
                            <i class="bi bi-bar-chart-line"></i>
                            <span class="nav-text">Current Inventory</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="sales_order.php">
                            <i class="bi bi-bag"></i>
                            <span class="nav-text">Sales Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="pick_list_items.php">
                            <i class="bi bi-list-check"></i>
                            <span class="nav-text">Pick List Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="bad_orders.php">
                            <i class="bi bi-recycle"></i>
                            <span class="nav-text">Bad Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="supplier.php">
                            <i class="bi bi-building"></i>
                            <span class="nav-text">Suppliers</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="purchase_order.php">
                            <i class="bi bi-box"></i>
                            <span class="nav-text">Purchase Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="drivers.php">
                            <i class="bi bi-people"></i>
                            <span class="nav-text">Users</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket-perforated"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="approve_credit_requests.php">
                            <i class="bi bi-pencil-square"></i>
                            <span class="nav-text">Approve Requests</span>
                        </a>
                    </li>
                    <hr class="sidebar-divider">
                </ul>
            </div>
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar"><?php echo substr($user_name, 0, 2); ?></div>
                    <div class="user-details-sidebar">
                        <span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span>
                    </div>
                </div>
                <button class="logout-btn-sidebar" onclick="logout()">
                    <i class="bi bi-box-arrow-right"></i>
                    <span class="logout-text">Logout</span>
                </button>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- SUPPLIERS CONTENT -->
            <div class="page-content active">
                <!-- Navbar Top -->
                <div class="navbar-top">
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2><i class="bi bi-building me-2"></i>Suppliers</h2>
                        <p id="dashboardSubtitle">
                            Manage suppliers for purchase orders
                            <?php if (!$view_all_branches && $branch_id > 0): ?>
                                - <?php 
                                    $branch_name_query = "SELECT branch_name FROM branches WHERE branch_id = $branch_id";
                                    $branch_name_result = $conn->query($branch_name_query);
                                    $branch_name_row = $branch_name_result ? $branch_name_result->fetch_assoc() : null;
                                    echo $branch_name_row ? htmlspecialchars($branch_name_row['branch_name']) : 'Branch ' . $branch_id;
                                ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <!-- Branch Info Alerts -->
                <?php if (!$suppliers_branch_column_exists): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Branch filtering for suppliers not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific supplier data:
                        <br><br>
                        <code>ALTER TABLE suppliers ADD COLUMN branch_id INT NULL;</code>
                        <br>
                        <code>ALTER TABLE suppliers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                        <br><br>
                        <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('suppliers')">
                            <i class="bi bi-files"></i> Copy SQL
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Stats Section -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3 col-6">
                        <div class="stat-card total">
                            <i class="bi bi-building stat-icon"></i>
                            <div class="stat-value"><?= $total_suppliers ?></div>
                            <div class="stat-label">Total Suppliers</div>
                            <?php if (!$view_all_branches && $branch_id > 0): ?>
                                <small class="d-block text-white-50">Your Branch</small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card complete">
                            <i class="bi bi-check-circle stat-icon"></i>
                            <div class="stat-value"><?= $active_suppliers ?></div>
                            <div class="stat-label">Active</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card pending">
                            <i class="bi bi-clock stat-icon"></i>
                            <div class="stat-value"><?= $pending_suppliers ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card" style="background: linear-gradient(135deg, #2E7D32, #1B5E20);">
                            <i class="bi bi-cash-stack stat-icon"></i>
                            <div class="stat-value">₱<?= number_format($total_spent / 1000, 1) ?>K</div>
                            <div class="stat-label">Total Spent</div>
                        </div>
                    </div>
                </div>

                <!-- FILTER SECTION -->
                <div class="filter-section">
                    <div class="filter-controls">
                        <div class="filter-dropdowns">
                            <!-- Status Filter Dropdown -->
                            <div class="filter-dropdown">
                                <span class="filter-label">Status</span>
                                <select class="form-select" id="statusFilter" onchange="filterSuppliers()">
                                    <option value="all">All Status</option>
                                    <option value="active">Active Only</option>
                                    <option value="inactive">Inactive Only</option>
                                    <option value="pending">Pending Only</option>
                                </select>
                            </div>
                            
                            <?php if ($suppliers_branch_column_exists && $view_all_branches): ?>
                            <!-- Branch Filter Dropdown -->
                            <div class="filter-dropdown">
                                <span class="filter-label">Branch</span>
                                <select class="form-select" id="branchFilter" onchange="filterSuppliers()">
                                    <option value="all">All Branches</option>
                                    <?php
                                    $branches_query = "SELECT branch_id, branch_name FROM branches ORDER BY branch_name";
                                    $branches_result = $conn->query($branches_query);
                                    while ($branch = $branches_result->fetch_assoc()):
                                    ?>
                                    <option value="<?= $branch['branch_id'] ?>"><?= htmlspecialchars($branch['branch_name']) ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Search Box -->
                            <div class="search-box">
                                <i class="bi bi-search"></i>
                                <input type="text" id="searchInput" placeholder="Search by name, code, email..." onkeyup="filterSuppliers()">
                            </div>
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button class="btn btn-outline-primary" onclick="printSuppliers()">
                            <i class="bi bi-printer me-1"></i> Print
                        </button>
                        <button class="btn btn-outline-success" onclick="exportToExcel()">
                            <i class="bi bi-file-earmark-excel me-1"></i> Export
                        </button>
                        <button class="btn btn-primary" onclick="showAddSupplierModal()">
                            <i class="bi bi-plus-circle me-1"></i> Add Supplier
                        </button>
                    </div>
                </div>

                <!-- Suppliers Table -->
                <div class="table-container">
                    <table class="table custom-table compact-table" id="supplierTable">
                        <thead>
                            <tr>
                                <th class="col-code">CODE</th>
                                <th class="col-name">SUPPLIER NAME</th>
                                <th class="col-contact">CONTACT PERSON</th>
                                <th class="col-phone">PHONE</th>
                                <th class="col-email">EMAIL</th>
                                <th class="col-payment">PAYMENT TERMS</th>
                                <?php if ($suppliers_branch_column_exists && $view_all_branches): ?>
                                    <th class="col-branch">BRANCH</th>
                                <?php endif; ?>
                                <th class="col-status">STATUS</th>
                                <th class="col-actions">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody id="supplierTableBody">
                            <?php if (empty($suppliers)): ?>
                            <tr>
                                <td colspan="<?= ($suppliers_branch_column_exists && $view_all_branches) ? '9' : '8' ?>" class="empty-state-table">
                                    <i class="bi bi-building"></i>
                                    <h5>No Suppliers Found</h5>
                                    <p class="text-muted">Click the "Add Supplier" button to add your first supplier.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($suppliers as $supplier): 
                                    $phone = !empty($supplier['mobile_number']) ? $supplier['mobile_number'] : $supplier['phone_number'];
                                ?>
                                <tr class="supplier-row" 
                                    data-id="<?= $supplier['supplier_id'] ?>"
                                    data-code="<?= htmlspecialchars($supplier['supplier_code']) ?>"
                                    data-name="<?= htmlspecialchars($supplier['supplier_name']) ?>"
                                    data-status="<?= $supplier['status'] ?>"
                                    data-branch="<?= $supplier['branch_id'] ?? '' ?>"
                                    data-po-count="<?= $supplier['po_count'] ?? 0 ?>"
                                    data-total-spent="<?= $supplier['total_spent'] ?? 0 ?>"
                                    data-phone="<?= htmlspecialchars($phone) ?>">
                                    <td class="col-code"><strong><?= htmlspecialchars($supplier['supplier_code']) ?></strong></td>
                                    <td class="col-name">
                                        <?= htmlspecialchars($supplier['supplier_name']) ?>
                                        <?php if ($supplier['po_count'] > 0): ?>
                                            <span class="badge bg-info ms-1" title="Purchase Orders: <?= $supplier['po_count'] ?>">
                                                <?= $supplier['po_count'] ?> POs
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-contact"><?= htmlspecialchars($supplier['contact_person'] ?? '—') ?></td>
                                    <td class="col-phone">
                                        <?php if (!empty($supplier['phone_number'])): ?>
                                            <?= htmlspecialchars($supplier['phone_number']) ?>
                                        <?php elseif (!empty($supplier['mobile_number'])): ?>
                                            <?= htmlspecialchars($supplier['mobile_number']) ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-email"><?= htmlspecialchars($supplier['email'] ?? '—') ?></td>
                                    <td class="col-payment"><?= htmlspecialchars($supplier['payment_terms'] ?? 'Net 30') ?></td>
                                    <?php if ($suppliers_branch_column_exists && $view_all_branches): ?>
                                        <td class="col-branch">
                                            <span class="badge bg-info">
                                                <?= htmlspecialchars($supplier['branch_name'] ?? 'Branch ' . $supplier['branch_id']) ?>
                                            </span>
                                        </td>
                                    <?php endif; ?>
                                    <td class="col-status">
                                        <span class="status-badge <?= $supplier['status'] === 'active' ? 'status-active' : ($supplier['status'] === 'pending' ? 'status-pending' : 'status-inactive') ?>">
                                            <?= ucfirst($supplier['status']) ?>
                                        </span>
                                    </td>
                                    <td class="col-actions">
                                        <div class="action-buttons">
                                            <?php if (!empty($phone)): ?>
                                                <a href="tel:<?= htmlspecialchars($phone) ?>" class="btn-action btn-call" title="Call">
                                                    <i class="bi bi-telephone"></i>
                                                </a>
                                                <a href="sms:<?= htmlspecialchars($phone) ?>" class="btn-action btn-message" title="Message">
                                                    <i class="bi bi-chat"></i>
                                                </a>
                                            <?php endif; ?>
                                            <button class="btn-action btn-view" onclick="viewSupplier(<?= $supplier['supplier_id'] ?>)" title="View">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <button class="btn-action btn-edit" onclick="editSupplier(<?= $supplier['supplier_id'] ?>)" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn-action btn-delete" onclick="deleteSupplier(<?= $supplier['supplier_id'] ?>)" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ADD/EDIT SUPPLIER MODAL - WITH CASCADING ADDRESS DROPDOWNS (NO LOCATION COORDINATES) -->
    <div class="modal fade" id="supplierModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white py-2">
                    <h5 class="modal-title" id="supplierModalTitle"><i class="bi bi-plus-circle me-2"></i>Add New Supplier</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="supplierForm">
                        <input type="hidden" id="supplierId">
                        <?php if ($suppliers_branch_column_exists && !$view_all_branches && $branch_id > 0): ?>
                            <input type="hidden" name="branch_id" value="<?= $branch_id ?>">
                        <?php endif; ?>
                        
                        <?php if ($suppliers_branch_column_exists && !$view_all_branches): ?>
                        <div class="alert alert-info py-2 mb-2">
                            <i class="bi bi-info-circle me-2"></i>
                            Adding supplier for <?php 
                                $branch_name_query = "SELECT branch_name FROM branches WHERE branch_id = $branch_id";
                                $branch_name_result = $conn->query($branch_name_query);
                                $branch_name_row = $branch_name_result ? $branch_name_result->fetch_assoc() : null;
                                echo $branch_name_row ? htmlspecialchars($branch_name_row['branch_name']) : 'Branch ' . $branch_id;
                            ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Auto-generated Supplier Code Display -->
                        <div class="row mb-3">
                            <div class="col-12">
                                <div class="code-label">Supplier Code (Auto-generated)</div>
                                <div class="code-preview" id="supplierCodePreview">
                                    <?php echo $preview_code; ?>
                                    <i class="bi bi-arrow-repeat refresh-code" onclick="refreshSupplierCode()" title="Generate new code"></i>
                                </div>
                                <input type="hidden" name="supplier_code" id="supplierCodeInput" value="<?php echo $preview_code; ?>">
                                <small class="text-muted">This code will be automatically generated and assigned to the supplier</small>
                            </div>
                        </div>
                        
                        <div class="row g-2">
                            <!-- Basic Information -->
                            <div class="col-12">
                                <h6 class="fw-bold border-bottom pb-1 mb-2" style="color: #2E7D32;">
                                    <i class="bi bi-info-circle me-2"></i>Basic Information
                                </h6>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="supplierName" class="form-label">Supplier Name *</label>
                                <input type="text" class="form-control" id="supplierName" required>
                            </div>
                            <div class="col-md-6">
                                <label for="contactPerson" class="form-label">Contact Person</label>
                                <input type="text" class="form-control" id="contactPerson">
                            </div>
                            <div class="col-md-6">
                                <label for="supplierEmail" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="supplierEmail">
                            </div>
                            
                            <!-- Contact Information -->
                            <div class="col-12">
                                <h6 class="fw-bold border-bottom pb-1 mb-2 mt-1" style="color: #2E7D32;">
                                    <i class="bi bi-telephone me-2"></i>Contact Information
                                </h6>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="phoneNumber" class="form-label">Phone Number</label>
                                <input type="text" class="form-control" id="phoneNumber" placeholder="e.g., 02-1234-5678">
                            </div>
                            <div class="col-md-4">
                                <label for="mobileNumber" class="form-label">Mobile Number</label>
                                <input type="text" class="form-control" id="mobileNumber" placeholder="e.g., 0912-345-6789">
                            </div>
                            <div class="col-md-4">
                                <label for="website" class="form-label">Website</label>
                                <input type="url" class="form-control" id="website" placeholder="https://...">
                            </div>
                            
                            <!-- Address Information - Cascading Dropdowns -->
                            <div class="col-12">
                                <h6 class="fw-bold border-bottom pb-1 mb-2 mt-1" style="color: #2E7D32;">
                                    <i class="bi bi-geo-alt me-2"></i>Address Information
                                </h6>
                            </div>
                            
                            <div class="col-md-6 address-row">
                                <label class="form-label">Region *</label>
                                <select class="form-select region-select" id="regionSelect" required>
                                    <option value="">Select Region</option>
                                    <?php foreach ($regions as $region_code => $region_name): ?>
                                        <option value="<?php echo $region_code; ?>"><?php echo $region_name; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 address-row">
                                <label class="form-label">Province *</label>
                                <select class="form-select province-select" id="provinceSelect" required disabled>
                                    <option value="">Select Province</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6 address-row">
                                <label class="form-label">City/Municipality *</label>
                                <select class="form-select city-select" id="citySelect" required disabled>
                                    <option value="">Select City/Municipality</option>
                                </select>
                                <input type="hidden" name="city_code" id="cityCode" value="">
                            </div>
                            
                            <div class="col-md-6 address-row">
                                <div id="barangayFieldContainer">
                                    <label class="form-label">Barangay</label>
                                    <select class="form-select barangay-select" id="barangaySelect" disabled>
                                        <option value="">Select City/Municipality first</option>
                                    </select>
                                </div>
                                <div class="mt-1 d-flex align-items-center">
                                    <span class="loading-spinner" style="display: none;"></span>
                                    <small class="api-status text-muted ms-2"></small>
                                </div>
                                <div class="manual-toggle-btn" id="manualBarangayToggle" style="display: none;">
                                    <i class="bi bi-pencil-square"></i> Can't find barangay? Click to type manually
                                </div>
                            </div>
                            
                            <div class="col-md-8 address-row">
                                <label for="streetAddress" class="form-label">Street Address / Building</label>
                                <input type="text" class="form-control" id="streetAddress" placeholder="e.g., 123 Main St., Suite 100">
                            </div>
                            
                            <!-- Address Preview -->
                            <div class="col-12">
                                <div class="address-preview" id="addressPreview">
                                    <small><i class="bi bi-info-circle"></i> Full address will be: <strong><span id="fullAddressPreview">Not yet specified</span></strong></small>
                                </div>
                            </div>
                            
                            <!-- Business Information -->
                            <div class="col-12">
                                <h6 class="fw-bold border-bottom pb-1 mb-2 mt-1" style="color: #2E7D32;">
                                    <i class="bi bi-briefcase me-2"></i>Business Information
                                </h6>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="taxId" class="form-label">Tax ID / TIN</label>
                                <input type="text" class="form-control" id="taxId">
                            </div>
                            <div class="col-md-4">
                                <label for="vatClassification" class="form-label">VAT Classification</label>
                                <select class="form-select" id="vatClassification">
                                    <option value="VAT Registered">VAT Registered</option>
                                    <option value="Non-VAT">Non-VAT</option>
                                    <option value="Zero Rated">Zero Rated</option>
                                    <option value="Exempt">Exempt</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="paymentTerms" class="form-label">Payment Terms</label>
                                <select class="form-select" id="paymentTerms">
                                    <?= getPaymentTermsOptions() ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="creditLimit" class="form-label">Credit Limit (₱)</label>
                                <input type="number" class="form-control" id="creditLimit" min="0" step="0.01" value="0">
                            </div>
                            
                            <!-- Status & Notes -->
                            <div class="col-md-6">
                                <label for="supplierStatus" class="form-label">Status</label>
                                <select class="form-select" id="supplierStatus">
                                    <option value="active">Active</option>
                                    <option value="pending">Pending</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label for="notes" class="form-label">Notes / Remarks</label>
                                <textarea class="form-control" id="notes" rows="2"></textarea>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-sm btn-primary" onclick="saveSupplier()">Save Supplier</button>
                </div>
            </div>
        </div>
    </div>

    <!-- VIEW SUPPLIER MODAL (MODIFIED: Address Displayed Separately) -->
    <div class="modal fade" id="viewSupplierModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white py-2">
                    <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Supplier Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-3">
                    <div id="viewSupplierContent"></div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-sm btn-warning" onclick="editFromView()">Edit Supplier</button>
                    <button type="button" class="btn btn-sm btn-success" onclick="createPOFromSupplier()">
                        <i class="bi bi-plus-circle"></i> Create PO
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- DELETE CONFIRMATION MODAL -->
    <div class="modal fade" id="deleteSupplierModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white py-2">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-3">
                    <p>Are you sure you want to delete this supplier?</p>
                    <p class="fw-bold" id="deleteSupplierName"></p>
                    <div class="alert alert-warning py-2">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        If this supplier has existing purchase orders, it will be deactivated instead.
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-sm btn-danger" onclick="confirmDeleteSupplier()">Delete Supplier</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // ========== GLOBAL VARIABLES ==========
    let currentSupplierId = null;
    const branchId = <?php echo $branch_id; ?>;
    const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
    const suppliersBranchColumnExists = <?php echo $suppliers_branch_column_exists ? 'true' : 'false'; ?>;
    const logoBase64 = '<?php echo $logo_base64; ?>';
    
    // Philippine location data
    const provincesByRegion = <?php echo json_encode($provinces); ?>;
    const citiesByProvince = <?php echo json_encode($cities); ?>;
    
    // City code cache
    let cityCodeCache = null;
    
    // ========== SIDEBAR FUNCTIONS ==========
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const isMobile = window.innerWidth <= 992;
        
        if (isMobile) {
            sidebar.classList.toggle('active');
            if (!document.querySelector('.sidebar-overlay')) {
                const overlay = document.createElement('div');
                overlay.className = 'sidebar-overlay';
                document.body.appendChild(overlay);
                overlay.addEventListener('click', closeMobileSidebar);
                setTimeout(() => overlay.classList.add('active'), 10);
            }
        } else {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            document.querySelectorAll('.nav-text').forEach(text => {
                text.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block';
            });
        }
    }

    function closeMobileSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        sidebar.classList.remove('active');
        if (overlay) {
            overlay.classList.remove('active');
            setTimeout(() => overlay.remove(), 300);
        }
    }

    function initializeSidebar() {
        const sidebar = document.getElementById('sidebar');
        if (window.innerWidth > 992) {
            const savedCollapsed = localStorage.getItem('sidebarCollapsed');
            if (savedCollapsed === 'true') {
                sidebar.classList.add('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'none');
            }
        }
    }

    // ========== SHOW LOADING ==========
    function showLoading() {
        Swal.fire({
            title: 'Processing...',
            text: 'Please wait',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
    }

    // ========== FILTER FUNCTIONS ==========
    function filterSuppliers() {
        const searchTerm = document.getElementById('searchInput').value.toLowerCase();
        const statusFilter = document.getElementById('statusFilter').value;
        const branchFilter = document.getElementById('branchFilter')?.value || 'all';
        
        const rows = document.querySelectorAll('.supplier-row');
        let visibleCount = 0;
        
        rows.forEach(row => {
            const code = row.dataset.code?.toLowerCase() || '';
            const name = row.dataset.name?.toLowerCase() || '';
            const email = row.querySelector('.col-email')?.innerText.toLowerCase() || '';
            const status = row.dataset.status || '';
            const rowBranch = row.dataset.branch || '';
            
            let showRow = true;
            
            // Status filter
            if (statusFilter !== 'all') {
                if (status !== statusFilter) showRow = false;
            }
            
            // Branch filter
            if (showRow && branchFilter !== 'all' && suppliersBranchColumnExists && viewAllBranches) {
                if (rowBranch != branchFilter) showRow = false;
            }
            
            // Search filter
            if (showRow && searchTerm !== '') {
                const searchableText = code + ' ' + name + ' ' + email;
                if (!searchableText.includes(searchTerm)) showRow = false;
            }
            
            row.style.display = showRow ? '' : 'none';
            if (showRow) visibleCount++;
        });
    }

    // ========== ADDRESS DROPDOWN FUNCTIONS ==========
    
    // Load all city codes for faster matching
    function loadCityCodes() {
        fetch('https://psgc.gitlab.io/api/cities-municipalities.json')
            .then(response => response.json())
            .then(cities => {
                cityCodeCache = {};
                cities.forEach(city => {
                    // Store multiple variations of city names
                    const normalized = city.name.toLowerCase()
                        .replace(/\s+/g, ' ')
                        .trim();
                        
                    cityCodeCache[normalized] = city.code;
                    
                    // Store without "City" suffix
                    if (normalized.endsWith(' city')) {
                        cityCodeCache[normalized.replace(' city', '')] = city.code;
                    }
                    
                    // Store without "Municipality" suffix
                    if (normalized.endsWith(' municipality')) {
                        cityCodeCache[normalized.replace(' municipality', '')] = city.code;
                    }
                    
                    // Store with common variations
                    const withoutSpecialChars = normalized.replace(/[^a-z0-9\s]/g, '');
                    cityCodeCache[withoutSpecialChars] = city.code;
                });
                console.log(`Loaded ${Object.keys(cityCodeCache).length} city variations`);
            })
            .catch(error => console.error('Failed to load city codes:', error));
    }

    // Convert barangay select to manual input
    function convertToManualBarangay(message) {
        const container = document.getElementById('barangayFieldContainer');
        const toggleBtn = document.getElementById('manualBarangayToggle');
        
        if (!container) return;
        
        const existingSelect = container.querySelector('select');
        if (!existingSelect) return;
        
        // Create manual input
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control';
        input.name = 'barangay';
        input.id = 'barangayInput';
        input.placeholder = 'Enter Barangay name';
        
        // Add help text
        const helpText = document.createElement('small');
        helpText.className = 'text-muted d-block mt-1';
        helpText.innerHTML = message || '⚠ No data available. Please enter manually.';
        
        // Replace select with input
        container.innerHTML = '<label class="form-label">Barangay</label>';
        container.appendChild(input);
        container.appendChild(helpText);
        
        // Hide toggle button
        if (toggleBtn) toggleBtn.style.display = 'none';
        
        input.addEventListener('input', updateAddressPreview);
    }

    // Convert back to select (if user wants to retry)
    function convertToSelectBarangay() {
        const container = document.getElementById('barangayFieldContainer');
        const toggleBtn = document.getElementById('manualBarangayToggle');
        
        if (!container) return;
        
        // Create select
        const select = document.createElement('select');
        select.className = 'form-select barangay-select';
        select.name = 'barangay';
        select.id = 'barangaySelect';
        select.disabled = true;
        select.innerHTML = '<option value="">Select City/Municipality first</option>';
        
        // Replace with select
        container.innerHTML = '<label class="form-label">Barangay</label>';
        container.appendChild(select);
        
        // Hide toggle button initially
        if (toggleBtn) toggleBtn.style.display = 'none';
    }

    // Initialize location dropdowns with PSGC API
    function initLocationDropdowns() {
        console.log("Initializing location dropdowns with PSGC API");
        
        const regionSelect = document.getElementById('regionSelect');
        const provinceSelect = document.getElementById('provinceSelect');
        const citySelect = document.getElementById('citySelect');
        const cityCodeInput = document.getElementById('cityCode');
        const apiStatus = document.querySelector('.api-status');
        const loadingSpinner = document.querySelector('.loading-spinner');
        const toggleBtn = document.getElementById('manualBarangayToggle');
        
        // Reset to select first
        convertToSelectBarangay();
        
        if (!regionSelect || !provinceSelect || !citySelect) {
            console.error("Could not find form elements");
            return;
        }
        
        // Clear dependent selects
        provinceSelect.innerHTML = '<option value="">Select Province</option>';
        citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
        
        // Set initial disabled states
        provinceSelect.disabled = true;
        citySelect.disabled = true;
        
        // Region change handler
        regionSelect.addEventListener('change', function() {
            const region = this.value;
            
            provinceSelect.innerHTML = '<option value="">Select Province</option>';
            citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
            citySelect.disabled = true;
            if (cityCodeInput) cityCodeInput.value = '';
            
            // Reset barangay field
            convertToSelectBarangay();
            if (toggleBtn) toggleBtn.style.display = 'none';
            
            if (region && provincesByRegion[region]) {
                provinceSelect.disabled = false;
                
                provincesByRegion[region].forEach(province => {
                    const option = document.createElement('option');
                    option.value = province;
                    option.textContent = province;
                    provinceSelect.appendChild(option);
                });
            } else {
                provinceSelect.disabled = true;
            }
            
            updateAddressPreview();
        });
        
        // Province change handler
        provinceSelect.addEventListener('change', function() {
            const province = this.value;
            
            citySelect.innerHTML = '<option value="">Loading cities...</option>';
            citySelect.disabled = true;
            if (cityCodeInput) cityCodeInput.value = '';
            
            // Reset barangay field
            convertToSelectBarangay();
            if (toggleBtn) toggleBtn.style.display = 'none';
            
            if (!province) {
                citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
                return;
            }
            
            // Try PSGC API first
            fetch('https://psgc.gitlab.io/api/cities-municipalities.json')
                .then(response => response.json())
                .then(allCities => {
                    // Filter cities by province
                    const filteredCities = allCities.filter(city => 
                        city.provinceName && city.provinceName.toLowerCase() === province.toLowerCase()
                    );
                    
                    citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
                    
                    if (filteredCities.length > 0) {
                        filteredCities.sort((a, b) => a.name.localeCompare(b.name));
                        
                        filteredCities.forEach(city => {
                            const option = document.createElement('option');
                            option.value = city.name;
                            option.textContent = city.name;
                            option.dataset.code = city.code;
                            citySelect.appendChild(option);
                        });
                        
                        if (apiStatus) apiStatus.textContent = '✓ Using PSGC API';
                        citySelect.disabled = false;
                    } else {
                        // Fallback to local data
                        console.log('Falling back to local city data');
                        if (citiesByProvince[province]) {
                            citiesByProvince[province].forEach(city => {
                                const option = document.createElement('option');
                                option.value = city;
                                option.textContent = city;
                                citySelect.appendChild(option);
                            });
                            if (apiStatus) apiStatus.textContent = '⚠ Using local data (API limited)';
                            citySelect.disabled = false;
                        } else {
                            citySelect.innerHTML = '<option value="">No cities found</option>';
                            if (apiStatus) apiStatus.textContent = '✗ No city data available';
                        }
                    }
                })
                .catch(error => {
                    console.error('Error loading cities:', error);
                    // Fallback to local data
                    if (citiesByProvince[province]) {
                        citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
                        citiesByProvince[province].forEach(city => {
                            const option = document.createElement('option');
                            option.value = city;
                            option.textContent = city;
                            citySelect.appendChild(option);
                        });
                        citySelect.disabled = false;
                        if (apiStatus) apiStatus.textContent = '⚠ Using local data (API unavailable)';
                    } else {
                        citySelect.innerHTML = '<option value="">No cities found</option>';
                    }
                });
            
            updateAddressPreview();
        });
        
        // City change handler
        citySelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const cityName = this.value;
            const cityCode = selectedOption.dataset?.code;
            const barangaySelect = document.getElementById('barangaySelect');
            
            if (cityCodeInput) {
                cityCodeInput.value = cityCode || '';
            }
            
            // Reset barangay field
            convertToSelectBarangay();
            const newBarangaySelect = document.getElementById('barangaySelect');
            
            if (!newBarangaySelect) return;
            
            newBarangaySelect.innerHTML = '<option value="">Loading barangays...</option>';
            newBarangaySelect.disabled = true;
            
            if (loadingSpinner) loadingSpinner.style.display = 'inline-block';
            if (apiStatus) apiStatus.textContent = 'Fetching barangays...';
            
            if (!cityName) {
                newBarangaySelect.innerHTML = '<option value="">Select City/Municipality first</option>';
                if (loadingSpinner) loadingSpinner.style.display = 'none';
                if (toggleBtn) toggleBtn.style.display = 'none';
                updateAddressPreview();
                return;
            }
            
            // Function to handle successful barangay load
            function handleBarangaySuccess(barangays, source) {
                newBarangaySelect.innerHTML = '<option value="">Select Barangay</option>';
                
                if (barangays.length === 0) {
                    newBarangaySelect.innerHTML = '<option value="">No barangays found</option>';
                    if (toggleBtn) toggleBtn.style.display = 'block';
                } else {
                    barangays.sort((a, b) => a.name ? a.name.localeCompare(b.name) : a.localeCompare(b));
                    
                    barangays.forEach(item => {
                        const option = document.createElement('option');
                        const name = item.name || item;
                        option.value = name;
                        option.textContent = name;
                        newBarangaySelect.appendChild(option);
                    });
                    
                    newBarangaySelect.disabled = false;
                    if (toggleBtn) toggleBtn.style.display = 'block';
                }
                
                if (loadingSpinner) loadingSpinner.style.display = 'none';
                if (apiStatus) apiStatus.textContent = `✓ ${barangays.length} barangays loaded from ${source}`;
            }
            
            // Try to get barangays by code
            if (cityCode) {
                fetch(`https://psgc.gitlab.io/api/cities-municipalities/${cityCode}/barangays.json`)
                    .then(response => response.json())
                    .then(barangays => {
                        handleBarangaySuccess(barangays, 'PSGC');
                    })
                    .catch(error => {
                        console.error('Error loading barangays:', error);
                        newBarangaySelect.innerHTML = '<option value="">Error loading barangays</option>';
                        if (loadingSpinner) loadingSpinner.style.display = 'none';
                        if (apiStatus) apiStatus.textContent = '✗ Failed to load barangays';
                        if (toggleBtn) toggleBtn.style.display = 'block';
                    });
            } else if (cityCodeCache) {
                // Try to find city code in cache
                const normalized = cityName.toLowerCase().trim();
                const foundCode = cityCodeCache[normalized] || 
                                 cityCodeCache[normalized.replace(' city', '')] ||
                                 cityCodeCache[normalized.replace(' municipality', '')];
                
                if (foundCode) {
                    cityCodeInput.value = foundCode;
                    fetch(`https://psgc.gitlab.io/api/cities-municipalities/${foundCode}/barangays.json`)
                        .then(response => response.json())
                        .then(barangays => {
                            handleBarangaySuccess(barangays, 'PSGC (matched)');
                        })
                        .catch(error => {
                            console.error('Error loading barangays:', error);
                            newBarangaySelect.innerHTML = '<option value="">Error loading barangays</option>';
                            if (loadingSpinner) loadingSpinner.style.display = 'none';
                            if (apiStatus) apiStatus.textContent = '✗ Failed to load barangays';
                            if (toggleBtn) toggleBtn.style.display = 'block';
                        });
                } else {
                    // No code found, offer manual entry
                    newBarangaySelect.innerHTML = '<option value="">No PSGC data for this city</option>';
                    if (loadingSpinner) loadingSpinner.style.display = 'none';
                    if (apiStatus) apiStatus.textContent = '⚠ No PSGC code found';
                    if (toggleBtn) toggleBtn.style.display = 'block';
                }
            } else {
                // No cache, offer manual entry
                newBarangaySelect.innerHTML = '<option value="">Unable to load barangays</option>';
                if (loadingSpinner) loadingSpinner.style.display = 'none';
                if (apiStatus) apiStatus.textContent = '⚠ City code not available';
                if (toggleBtn) toggleBtn.style.display = 'block';
            }
            
            updateAddressPreview();
        });
        
        // Manual toggle button click handler
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                convertToManualBarangay('Manual entry mode - please type barangay name');
                if (apiStatus) apiStatus.textContent = '⌨️ Manual entry mode';
            });
        }
    }

    function updateAddressPreview() {
        const regionSelect = document.getElementById('regionSelect');
        const provinceSelect = document.getElementById('provinceSelect');
        const citySelect = document.getElementById('citySelect');
        const barangaySelect = document.getElementById('barangaySelect');
        const barangayInput = document.getElementById('barangayInput');
        const streetAddress = document.getElementById('streetAddress')?.value || '';
        
        const region = regionSelect ? regionSelect.options[regionSelect.selectedIndex]?.text || '' : '';
        const province = provinceSelect ? provinceSelect.value || '' : '';
        const city = citySelect ? citySelect.value || '' : '';
        let barangay = '';
        
        if (barangaySelect && !barangaySelect.disabled) {
            barangay = barangaySelect.value || '';
        } else if (barangayInput) {
            barangay = barangayInput.value || '';
        }
        
        const parts = [];
        if (streetAddress) parts.push(streetAddress);
        if (barangay) parts.push(barangay);
        if (city) parts.push(city);
        if (province) parts.push(province);
        if (region) parts.push(region);
        
        const fullAddress = parts.join(', ') || 'Not yet specified';
        const previewSpan = document.getElementById('fullAddressPreview');
        if (previewSpan) {
            previewSpan.textContent = fullAddress;
        }
    }

    // ========== SUPPLIER FUNCTIONS ==========
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Suppliers - Management Page with Cascading Address (Updated - VAT Classification + Call/Message + Separate Address Display)");
        console.log("Branch ID:", branchId);
        console.log("View All Branches:", viewAllBranches);
        
        initializeSidebar();
        
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                sidebar.classList.toggle('active');
                if (!document.querySelector('.sidebar-overlay')) {
                    const overlay = document.createElement('div');
                    overlay.className = 'sidebar-overlay';
                    document.body.appendChild(overlay);
                    overlay.addEventListener('click', closeMobileSidebar);
                    setTimeout(() => overlay.classList.add('active'), 10);
                }
            } else {
                toggleSidebar();
            }
        });
        
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        if (desktopToggleBtn) {
            desktopToggleBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleSidebar();
            });
        }
        
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 992) closeMobileSidebar();
            });
        });

        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const mobileBtn = document.getElementById('mobileMenuBtn');
            const overlay = document.querySelector('.sidebar-overlay');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile && sidebar.classList.contains('active') && 
                !sidebar.contains(event.target) && 
                !mobileBtn.contains(event.target) &&
                !overlay?.contains(event.target)) {
                closeMobileSidebar();
            }
        });
        
        // Fix modal backdrop issue
        const modals = ['supplierModal', 'viewSupplierModal', 'deleteSupplierModal'];
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.addEventListener('hidden.bs.modal', function () {
                    const backdrop = document.querySelector('.modal-backdrop');
                    if (backdrop) backdrop.remove();
                    document.body.classList.remove('modal-open');
                    document.body.style.removeProperty('padding-right');
                });
            }
        });
        
        // Load city codes cache
        loadCityCodes();
        
        // Initialize location dropdowns when modal is shown
        document.getElementById('supplierModal').addEventListener('shown.bs.modal', function() {
            initLocationDropdowns();
        });
    });

    // Refresh supplier code
    function refreshSupplierCode() {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'generate_code');
        
        fetch('supplier.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const supplierCodePreview = document.getElementById('supplierCodePreview');
                if (supplierCodePreview) {
                    supplierCodePreview.innerHTML = data.supplier_code + ' <i class="bi bi-arrow-repeat refresh-code" onclick="refreshSupplierCode()" title="Generate new code"></i>';
                }
                const supplierCodeInput = document.getElementById('supplierCodeInput');
                if (supplierCodeInput) {
                    supplierCodeInput.value = data.supplier_code;
                }
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'Failed to generate supplier code', 'error');
        });
    }

    // Show Add Supplier Modal
    function showAddSupplierModal() {
        document.getElementById('supplierModalTitle').innerHTML = '<i class="bi bi-plus-circle me-2"></i>Add New Supplier';
        document.getElementById('supplierForm').reset();
        document.getElementById('supplierId').value = '';
        document.getElementById('supplierStatus').value = 'active';
        document.getElementById('vatClassification').value = 'VAT Registered';
        
        // Reset address dropdowns
        const regionSelect = document.getElementById('regionSelect');
        regionSelect.innerHTML = '<option value="">Select Region</option>';
        <?php foreach ($regions as $region_code => $region_name): ?>
            regionSelect.innerHTML += '<option value="<?php echo $region_code; ?>"><?php echo $region_name; ?></option>';
        <?php endforeach; ?>
        
        document.getElementById('provinceSelect').innerHTML = '<option value="">Select Province</option>';
        document.getElementById('provinceSelect').disabled = true;
        document.getElementById('citySelect').innerHTML = '<option value="">Select City/Municipality</option>';
        document.getElementById('citySelect').disabled = true;
        
        // Reset barangay field
        convertToSelectBarangay();
        
        // Clear hidden fields
        document.getElementById('cityCode').value = '';
        
        // Reset address preview
        document.getElementById('fullAddressPreview').textContent = 'Not yet specified';
        
        // Generate new code
        refreshSupplierCode();
        
        new bootstrap.Modal(document.getElementById('supplierModal')).show();
    }

    // Save Supplier (Add or Update)
    function saveSupplier() {
        const supplierId = document.getElementById('supplierId').value;
        const supplierCode = document.getElementById('supplierCodeInput').value;
        const supplierName = document.getElementById('supplierName').value;
        const region = document.getElementById('regionSelect').value;
        const province = document.getElementById('provinceSelect').value;
        const city = document.getElementById('citySelect').value;
        const barangaySelect = document.getElementById('barangaySelect');
        const barangayInput = document.getElementById('barangayInput');
        let barangay = '';
        
        if (barangaySelect && !barangaySelect.disabled) {
            barangay = barangaySelect.value;
        } else if (barangayInput) {
            barangay = barangayInput.value;
        }
        
        if (!supplierCode) {
            Swal.fire('Warning', 'Supplier Code is required', 'warning');
            return;
        }
        
        if (!supplierName) {
            Swal.fire('Warning', 'Supplier Name is required', 'warning');
            return;
        }
        
        if (!region) {
            Swal.fire('Warning', 'Please select a Region', 'warning');
            return;
        }
        
        if (!province) {
            Swal.fire('Warning', 'Please select a Province', 'warning');
            return;
        }
        
        if (!city) {
            Swal.fire('Warning', 'Please select a City/Municipality', 'warning');
            return;
        }
        
        showLoading();
        
        const formData = new FormData();
        if (supplierId) {
            formData.append('action', 'update_supplier');
            formData.append('supplier_id', supplierId);
        } else {
            formData.append('action', 'add_supplier');
        }
        
        formData.append('supplier_code', supplierCode);
        formData.append('supplier_name', supplierName);
        formData.append('contact_person', document.getElementById('contactPerson').value || '');
        formData.append('email', document.getElementById('supplierEmail').value || '');
        formData.append('phone_number', document.getElementById('phoneNumber').value || '');
        formData.append('mobile_number', document.getElementById('mobileNumber').value || '');
        
        // Address fields
        formData.append('region', region);
        formData.append('province', province);
        formData.append('city', city);
        formData.append('city_code', document.getElementById('cityCode').value || '');
        formData.append('barangay', barangay || '');
        formData.append('street_address', document.getElementById('streetAddress').value || '');
        
        // Business fields
        formData.append('tax_id', document.getElementById('taxId').value || '');
        formData.append('vat_classification', document.getElementById('vatClassification').value);
        formData.append('payment_terms', document.getElementById('paymentTerms').value);
        formData.append('credit_limit', document.getElementById('creditLimit').value || 0);
        formData.append('website', document.getElementById('website').value || '');
        formData.append('notes', document.getElementById('notes').value || '');
        formData.append('status', document.getElementById('supplierStatus').value);
        
        fetch('supplier.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('supplierModal')).hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while saving the supplier', 'error');
        });
    }

    // View Supplier (MODIFIED: Address Displayed Separately)
    function viewSupplier(id) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_supplier');
        formData.append('supplier_id', id);
        
        fetch('supplier.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const supplier = data.supplier;
                const purchaseOrders = data.purchase_orders || [];
                
                // Format dates
                const createdDate = supplier.created_at ? new Date(supplier.created_at).toLocaleString() : 'N/A';
                
                // Build contact info
                let contactHtml = '';
                if (supplier.contact_person) contactHtml += `<div class="contact-info mb-1"><i class="bi bi-person"></i> ${supplier.contact_person}</div>`;
                if (supplier.email) contactHtml += `<div class="contact-info mb-1"><i class="bi bi-envelope"></i> ${supplier.email}</div>`;
                if (supplier.phone_number) contactHtml += `<div class="contact-info mb-1"><i class="bi bi-telephone"></i> ${supplier.phone_number}</div>`;
                if (supplier.mobile_number) contactHtml += `<div class="contact-info mb-1"><i class="bi bi-phone"></i> ${supplier.mobile_number}</div>`;
                
                // Build address display (separate lines)
                let addressHtml = '';
                if (supplier.street_address) addressHtml += `<div><strong>Street/Building:</strong> ${supplier.street_address}</div>`;
                if (supplier.barangay) addressHtml += `<div><strong>Barangay:</strong> ${supplier.barangay}</div>`;
                if (supplier.city) addressHtml += `<div><strong>City/Municipality:</strong> ${supplier.city}</div>`;
                if (supplier.province) addressHtml += `<div><strong>Province:</strong> ${supplier.province}</div>`;
                if (supplier.region) addressHtml += `<div><strong>Region:</strong> ${supplier.region}</div>`;
                
                if (!addressHtml) addressHtml = '<p class="text-muted">No address provided</p>';
                
                // Build purchase orders history
                let poHtml = '';
                if (purchaseOrders.length > 0) {
                    poHtml = '<h6 class="fw-bold mt-3 mb-2">Recent Purchase Orders</h6><div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>PO Number</th><th>Order Date</th><th>Amount</th><th>Status</th></tr></thead><tbody>';
                    purchaseOrders.forEach(po => {
                        const poDate = po.order_date ? new Date(po.order_date).toLocaleDateString() : 'N/A';
                        poHtml += `<tr>
                            <td>${po.po_number}</td>
                            <td>${poDate}</td>
                            <td class="text-end">₱${Number(po.total_amount || 0).toFixed(2)}</td>
                            <td><span class="badge ${getPOStatusClass(po.po_status)}">${getPOStatusText(po.po_status)}</span></td>
                        </tr>`;
                    });
                    poHtml += '</tbody></table></div>';
                }
                
                const phone = supplier.mobile_number || supplier.phone_number;
                
                const content = document.getElementById('viewSupplierContent');
                content.innerHTML = `
                    <div class="row">
                        <div class="col-md-6">
                            <div class="supplier-details-card p-3 mb-2">
                                <h6 class="fw-bold mb-2"><i class="bi bi-info-circle"></i> Supplier Information</h6>
                                <table class="table table-sm table-borderless mb-0">
                                    <tr>
                                        <td width="40%" class="detail-label">Supplier Code:</td>
                                        <td class="detail-value">${supplier.supplier_code}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Supplier Name:</td>
                                        <td class="detail-value">${supplier.supplier_name}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Tax ID / TIN:</td>
                                        <td>${supplier.tax_id || 'N/A'}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">VAT Classification:</td>
                                        <td>${supplier.vat_classification || 'VAT Registered'}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Payment Terms:</td>
                                        <td>${supplier.payment_terms || 'Net 30'}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Credit Limit:</td>
                                        <td>₱${Number(supplier.credit_limit || 0).toFixed(2)}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Status:</td>
                                        <td><span class="status-badge ${supplier.status === 'active' ? 'status-active' : (supplier.status === 'pending' ? 'status-pending' : 'status-inactive')} py-1">${supplier.status}</span></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="supplier-details-card p-3 mb-2">
                                <h6 class="fw-bold mb-2"><i class="bi bi-person"></i> Contact Information</h6>
                                ${contactHtml || '<p class="text-muted mb-1">No contact information</p>'}
                                ${phone ? `
                                    <div class="mt-2 d-flex gap-2">
                                        <a href="tel:${phone}" class="btn btn-sm btn-outline-info" title="Call"><i class="bi bi-telephone"></i> Call</a>
                                        <a href="sms:${phone}" class="btn btn-sm btn-outline-primary" title="Message"><i class="bi bi-chat"></i> Message</a>
                                    </div>
                                ` : ''}
                                
                                <h6 class="fw-bold mt-3 mb-2"><i class="bi bi-geo-alt"></i> Address</h6>
                                <div class="mb-1">${addressHtml}</div>
                                
                                ${supplier.website ? `
                                <h6 class="fw-bold mt-3 mb-2"><i class="bi bi-globe"></i> Website</h6>
                                <p class="mb-1"><a href="${supplier.website}" target="_blank">${supplier.website}</a></p>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                    
                    ${supplier.notes ? `
                    <div class="row mt-2">
                        <div class="col-12">
                            <div class="supplier-details-card p-3">
                                <h6 class="fw-bold mb-2"><i class="bi bi-chat"></i> Notes</h6>
                                <p class="mb-0">${supplier.notes}</p>
                            </div>
                        </div>
                    </div>
                    ` : ''}
                    
                    ${poHtml}
                    
                    <div class="row mt-2">
                        <div class="col-12">
                            <div class="supplier-details-card p-3">
                                <h6 class="fw-bold mb-2"><i class="bi bi-clock"></i> System Information</h6>
                                <table class="table table-sm table-borderless mb-0">
                                    <tr>
                                        <td width="20%" class="detail-label">Created By:</td>
                                        <td>${supplier.created_by_name || 'N/A'}</td>
                                        <td width="20%" class="detail-label">Created At:</td>
                                        <td>${createdDate}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                `;
                
                currentSupplierId = id;
                new bootstrap.Modal(document.getElementById('viewSupplierModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while fetching supplier details', 'error');
        });
    }

    // Edit from View Modal
    function editFromView() {
        bootstrap.Modal.getInstance(document.getElementById('viewSupplierModal')).hide();
        setTimeout(() => {
            editSupplier(currentSupplierId);
        }, 300);
    }

    // Edit Supplier
    function editSupplier(id) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_supplier');
        formData.append('supplier_id', id);
        
        fetch('supplier.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const supplier = data.supplier;
                
                document.getElementById('supplierModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>Edit Supplier';
                document.getElementById('supplierId').value = supplier.supplier_id;
                
                // Basic information
                document.getElementById('supplierCodePreview').innerHTML = supplier.supplier_code + ' <i class="bi bi-arrow-repeat refresh-code" onclick="refreshSupplierCode()" title="Generate new code"></i>';
                document.getElementById('supplierCodeInput').value = supplier.supplier_code;
                document.getElementById('supplierName').value = supplier.supplier_name || '';
                document.getElementById('contactPerson').value = supplier.contact_person || '';
                document.getElementById('supplierEmail').value = supplier.email || '';
                document.getElementById('phoneNumber').value = supplier.phone_number || '';
                document.getElementById('mobileNumber').value = supplier.mobile_number || '';
                
                // Address fields
                document.getElementById('streetAddress').value = supplier.street_address || '';
                
                // Business information
                document.getElementById('taxId').value = supplier.tax_id || '';
                document.getElementById('vatClassification').value = supplier.vat_classification || 'VAT Registered';
                document.getElementById('paymentTerms').value = supplier.payment_terms || 'Net 30';
                document.getElementById('creditLimit').value = supplier.credit_limit || 0;
                document.getElementById('website').value = supplier.website || '';
                document.getElementById('notes').value = supplier.notes || '';
                document.getElementById('supplierStatus').value = supplier.status || 'active';
                
                // Reset and initialize dropdowns
                const regionSelect = document.getElementById('regionSelect');
                regionSelect.innerHTML = '<option value="">Select Region</option>';
                <?php foreach ($regions as $region_code => $region_name): ?>
                    regionSelect.innerHTML += '<option value="<?php echo $region_code; ?>"><?php echo $region_name; ?></option>';
                <?php endforeach; ?>
                
                document.getElementById('provinceSelect').innerHTML = '<option value="">Select Province</option>';
                document.getElementById('provinceSelect').disabled = true;
                document.getElementById('citySelect').innerHTML = '<option value="">Select City/Municipality</option>';
                document.getElementById('citySelect').disabled = true;
                
                // Reset barangay field
                convertToSelectBarangay();
                
                // Set region value
                if (supplier.region) {
                    regionSelect.value = supplier.region;
                    
                    // Trigger province load
                    const event = new Event('change');
                    regionSelect.dispatchEvent(event);
                    
                    // Set province after a delay
                    setTimeout(() => {
                        const provinceSelect = document.getElementById('provinceSelect');
                        if (supplier.province) {
                            provinceSelect.value = supplier.province;
                            
                            // Trigger city load
                            const provinceEvent = new Event('change');
                            provinceSelect.dispatchEvent(provinceEvent);
                            
                            // Set city after a delay
                            setTimeout(() => {
                                const citySelect = document.getElementById('citySelect');
                                if (supplier.city) {
                                    citySelect.value = supplier.city;
                                    document.getElementById('cityCode').value = supplier.city_code || '';
                                    
                                    // Trigger barangay load
                                    const cityEvent = new Event('change');
                                    citySelect.dispatchEvent(cityEvent);
                                    
                                    // Set barangay after a delay
                                    setTimeout(() => {
                                        const barangaySelect = document.getElementById('barangaySelect');
                                        if (supplier.barangay) {
                                            // Check if it's a select or input
                                            if (barangaySelect && !barangaySelect.disabled) {
                                                // Try to set select value
                                                for (let i = 0; i < barangaySelect.options.length; i++) {
                                                    if (barangaySelect.options[i].value === supplier.barangay) {
                                                        barangaySelect.selectedIndex = i;
                                                        break;
                                                    }
                                                }
                                            } else {
                                                // If select is disabled or not available, convert to manual and set value
                                                convertToManualBarangay();
                                                setTimeout(() => {
                                                    document.getElementById('barangayInput').value = supplier.barangay;
                                                    updateAddressPreview();
                                                }, 100);
                                            }
                                        }
                                        updateAddressPreview();
                                    }, 1000);
                                }
                            }, 1000);
                        }
                    }, 1000);
                }
                
                new bootstrap.Modal(document.getElementById('supplierModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while fetching supplier details', 'error');
        });
    }

    // Create PO from Supplier
    function createPOFromSupplier() {
        bootstrap.Modal.getInstance(document.getElementById('viewSupplierModal')).hide();
        setTimeout(() => {
            window.location.href = 'purchase_order.php?supplier_id=' + currentSupplierId;
        }, 300);
    }

    // Delete Supplier
    function deleteSupplier(id) {
        const row = document.querySelector(`.supplier-row[data-id="${id}"]`);
        if (!row) return;
        
        document.getElementById('deleteSupplierName').textContent = row.dataset.name;
        currentSupplierId = id;
        new bootstrap.Modal(document.getElementById('deleteSupplierModal')).show();
    }

    // Confirm Delete
    function confirmDeleteSupplier() {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'delete_supplier');
        formData.append('supplier_id', currentSupplierId);
        
        fetch('supplier.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('deleteSupplierModal')).hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while deleting the supplier', 'error');
        });
    }

    // ========== PRINT FUNCTION ==========
    function printSuppliers() {
        // Show loading indicator on button
        const printBtn = document.querySelector('.btn-outline-primary[onclick="printSuppliers()"]');
        if (printBtn) {
            const originalText = printBtn.innerHTML;
            printBtn.innerHTML = '<i class="bi bi-printer"></i> Preparing...';
            printBtn.disabled = true;
        }

        // Get current filter values
        const filterData = {
            status: document.getElementById('statusFilter').value,
            search: document.getElementById('searchInput').value
        };
        
        showLoading();
        
        // Fetch filtered data from server
        const formData = new FormData();
        formData.append('action', 'print_suppliers');
        formData.append('filter_data', JSON.stringify(filterData));
        
        fetch('supplier.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const suppliers = data.suppliers;
                
                if (suppliers.length === 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'No Data',
                        text: 'No suppliers match the current filters',
                        confirmButtonColor: '#0d6efd'
                    });
                    return;
                }
                
                // Generate compact HTML
                const htmlContent = generatePrintHTML(suppliers, data.branch_name);
                
                // Use hidden iframe for printing
                const iframe = document.getElementById('printFrame');
                const iframeDoc = iframe.contentWindow.document;
                
                iframeDoc.open();
                iframeDoc.write(htmlContent);
                iframeDoc.close();
                
                // Restore button
                setTimeout(() => {
                    if (printBtn) {
                        printBtn.innerHTML = '<i class="bi bi-printer"></i> Print';
                        printBtn.disabled = false;
                    }
                }, 1000);
                
                // Trigger print dialog
                setTimeout(() => {
                    iframe.contentWindow.focus();
                    iframe.contentWindow.print();
                }, 250);
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to load supplier data',
                    confirmButtonColor: '#0d6efd'
                });
                if (printBtn) {
                    printBtn.innerHTML = '<i class="bi bi-printer"></i> Print';
                    printBtn.disabled = false;
                }
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'An error occurred while preparing print',
                confirmButtonColor: '#0d6efd'
            });
            if (printBtn) {
                printBtn.innerHTML = '<i class="bi bi-printer"></i> Print';
                printBtn.disabled = false;
            }
        });
    }

    // Compact HTML generator for suppliers print
    function generatePrintHTML(suppliers, branchName) {
        let tableRows = '';
        let totalActive = 0;
        let totalInactive = 0;
        let totalPending = 0;
        
        suppliers.forEach(supplier => {
            if (supplier.status === 'active') totalActive++;
            else if (supplier.status === 'pending') totalPending++;
            else totalInactive++;
            
            tableRows += '<tr>';
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${supplier.supplier_code}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${supplier.supplier_name}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${supplier.contact_person || '—'}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${supplier.phone_number || supplier.mobile_number || '—'}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${supplier.email || '—'}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${supplier.payment_terms || 'Net 30'}</td>`;
            if (suppliersBranchColumnExists && viewAllBranches) {
                tableRows += `<td style="padding: 3px; border: 1px solid #000;">${supplier.branch_name || 'Branch ' + supplier.branch_id}</td>`;
            }
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${supplier.status}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: right;">${supplier.po_count || 0}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: right;">₱${Number(supplier.total_spent || 0).toFixed(2)}</td>`;
            tableRows += '</tr>';
        });
        
        const currentDate = new Date().toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
        
        return `
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Suppliers Report</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 0; padding: 0; font-size: 9px; }
                    .print-container { max-width: 100%; margin: 0; }
                    .print-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 5px; border-bottom: 1px solid #000; padding-bottom: 3px; }
                    .logo-section { display: flex; align-items: center; gap: 5px; }
                    .company-logo { width: 30px; height: auto; }
                    .company-info h1 { font-size: 14px; margin: 0; font-weight: bold; }
                    .company-info p { font-size: 8px; margin: 0; }
                    .report-title h2 { font-size: 12px; margin: 0; }
                    .report-title .date-info { font-size: 8px; }
                    .summary-box { border: 1px solid #000; padding: 3px; margin-bottom: 5px; display: flex; }
                    .summary-item { flex: 1; text-align: center; border-right: 1px solid #000; }
                    .summary-item:last-child { border-right: none; }
                    .summary-label { font-size: 8px; font-weight: bold; }
                    .summary-value { font-size: 11px; font-weight: bold; }
                    table { width: 100%; border-collapse: collapse; font-size: 8px; }
                    th { border: 1px solid #000; padding: 3px; text-align: left; font-weight: bold; background: white !important; color: black !important; }
                    td { border: 1px solid #000; padding: 3px; }
                    .print-footer { margin-top: 5px; border-top: 1px solid #000; padding-top: 3px; display: flex; justify-content: space-between; font-size: 8px; }
                </style>
            </head>
            <body>
                <div class="print-container">
                    <div class="print-header">
                        <div class="logo-section">
                            <img src="${logoBase64}" alt="AMGC Logo" class="company-logo">
                            <div class="company-info">
                                <h1>AMGC</h1>
                                <p>Suppliers Report</p>
                            </div>
                        </div>
                        <div class="report-title">
                            <h2>SUPPLIER LIST</h2>
                            <div class="date-info">${currentDate}</div>
                        </div>
                    </div>
                    
                    <div class="summary-box">
                        <div class="summary-item"><div class="summary-label">Total Suppliers</div><div class="summary-value">${suppliers.length}</div></div>
                        <div class="summary-item"><div class="summary-label">Active</div><div class="summary-value">${totalActive}</div></div>
                        <div class="summary-item"><div class="summary-label">Pending</div><div class="summary-value">${totalPending}</div></div>
                        <div class="summary-item"><div class="summary-label">Inactive</div><div class="summary-value">${totalInactive}</div></div>
                        <div class="summary-item"><div class="summary-label">Branch</div><div class="summary-value">${!viewAllBranches ? branchName : 'All'}</div></div>
                    </div>
                    
                    <table>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Supplier Name</th>
                                <th>Contact Person</th>
                                <th>Phone</th>
                                <th>Email</th>
                                <th>Payment Terms</th>
                                ${suppliersBranchColumnExists && viewAllBranches ? '<th>Branch</th>' : ''}
                                <th>Status</th>
                                <th style="text-align: right;">PO Count</th>
                                <th style="text-align: right;">Total Spent</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${tableRows}
                        </tbody>
                    </table>
                    
                    <div class="print-footer">
                        <div>Generated: ${currentDate}</div>
                        <div>${document.querySelector('.user-name-sidebar')?.textContent || 'Branch Admin'}</div>
                    </div>
                </div>
            </body>
            </html>
        `;
    }

    // ========== EXCEL EXPORT FUNCTION ==========
    function exportToExcel() {
        const rows = document.querySelectorAll('.supplier-row:not([style*="display: none"])');
        if (rows.length === 0) {
            Swal.fire('Warning', 'No suppliers to export', 'warning');
            return;
        }
        
        // Prepare data array for Excel
        const excelData = [];
        
        // Add headers
        const headers = [
            'Supplier Code',
            'Supplier Name',
            'Contact Person',
            'Phone Number',
            'Mobile Number',
            'Email',
            'Region',
            'Province',
            'City/Municipality',
            'Barangay',
            'Street Address',
            'VAT Classification',
            'Payment Terms',
            'Credit Limit',
            'Status',
            ...(suppliersBranchColumnExists && viewAllBranches ? ['Branch'] : []),
            'Purchase Orders',
            'Total Spent'
        ];
        excelData.push(headers);

        // Add data rows - Note: This is simplified, you may want to fetch full data from server
        rows.forEach(row => {
            if (row.style.display !== 'none') {
                const cells = row.querySelectorAll('td');
                let cellIndex = 0;
                
                const code = cells[cellIndex++]?.innerText || '';
                const name = cells[cellIndex++]?.innerText.split('\n')[0] || '';
                const contact = cells[cellIndex++]?.innerText || '';
                const phone = cells[cellIndex++]?.innerText || '';
                const email = cells[cellIndex++]?.innerText || '';
                const payment = cells[cellIndex++]?.innerText || '';
                
                let branch = '';
                if (suppliersBranchColumnExists && viewAllBranches) {
                    branch = cells[cellIndex++]?.innerText || '';
                }
                
                const status = cells[cellIndex++]?.innerText || '';
                
                // Get additional data from the row's dataset
                const rowData = [
                    code,
                    name,
                    contact,
                    phone,
                    '',
                    email,
                    '',
                    '',
                    '',
                    '',
                    '',
                    'VAT Registered', // placeholder – you may want to fetch from server
                    payment,
                    '',
                    status,
                    ...(suppliersBranchColumnExists && viewAllBranches ? [branch] : []),
                    row.dataset.poCount || 0,
                    row.dataset.totalSpent || 0
                ];
                
                excelData.push(rowData);
            }
        });

        // Create workbook and worksheet
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(excelData);

        // Set column widths
        const colWidths = [
            { wch: 12 }, // Code
            { wch: 25 }, // Name
            { wch: 20 }, // Contact Person
            { wch: 15 }, // Phone
            { wch: 15 }, // Mobile
            { wch: 25 }, // Email
            { wch: 25 }, // Region
            { wch: 25 }, // Province
            { wch: 25 }, // City
            { wch: 25 }, // Barangay
            { wch: 30 }, // Street
            { wch: 15 }, // VAT Classification
            { wch: 12 }, // Payment Terms
            { wch: 15 }, // Credit Limit
            { wch: 10 }, // Status
            ...(suppliersBranchColumnExists && viewAllBranches ? [{ wch: 15 }] : []), // Branch
            { wch: 15 }, // PO Count
            { wch: 18 }  // Total Spent
        ];
        ws['!cols'] = colWidths;

        // Add worksheet to workbook
        XLSX.utils.book_append_sheet(wb, ws, 'Suppliers');

        // Generate filename
        const date = new Date();
        const dateStr = date.toISOString().slice(0,10).replace(/-/g, '');
        let filename = `Suppliers_${dateStr}`;
        if (suppliersBranchColumnExists && !viewAllBranches) {
            filename += `_Branch_${branchId}`;
        }
        filename += '.xlsx';

        // Export Excel file
        XLSX.writeFile(wb, filename);
        
        Swal.fire({
            icon: 'success',
            title: 'Export Complete',
            text: 'Excel export completed successfully!',
            timer: 2000,
            showConfirmButton: false
        });
    }

    // Helper functions for PO status
    function getPOStatusClass(status) {
        const classes = {
            'draft': 'bg-secondary',
            'submitted': 'bg-warning',
            'approved': 'bg-success',
            'received': 'bg-info',
            'cancelled': 'bg-danger'
        };
        return classes[status] || 'bg-secondary';
    }

    function getPOStatusText(status) {
        const texts = {
            'draft': 'Draft',
            'submitted': 'Processing',
            'approved': 'Approved',
            'received': 'Delivered',
            'cancelled': 'Cancelled'
        };
        return texts[status] || status;
    }

    // ========== COPY SQL FUNCTION ==========
    function copySQL(table) {
        let sql = '';
        if (table === 'suppliers') {
            sql = `-- Suppliers table with address fields
CREATE TABLE IF NOT EXISTS suppliers (
    supplier_id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_code VARCHAR(50) NOT NULL UNIQUE,
    supplier_name VARCHAR(150) NOT NULL,
    contact_person VARCHAR(100),
    email VARCHAR(100),
    phone_number VARCHAR(20),
    mobile_number VARCHAR(20),
    
    -- Address fields
    region VARCHAR(255),
    province VARCHAR(255),
    city VARCHAR(255),
    city_code VARCHAR(50),
    barangay VARCHAR(255),
    street_address TEXT,
    full_address TEXT,
    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),
    
    -- Business information
    tax_id VARCHAR(50),
    vat_classification ENUM('VAT Registered','Non-VAT','Zero Rated','Exempt') DEFAULT 'VAT Registered',
    payment_terms VARCHAR(100) DEFAULT 'Net 30',
    credit_limit DECIMAL(12,2) DEFAULT 0.00,
    website VARCHAR(255),
    notes TEXT,
    status ENUM('active','inactive','pending') DEFAULT 'active',
    branch_id INT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (branch_id) REFERENCES branches(branch_id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    
    INDEX idx_supplier_code (supplier_code),
    INDEX idx_supplier_name (supplier_name),
    INDEX idx_status (status),
    INDEX idx_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;`;
        }
        
        navigator.clipboard.writeText(sql).then(() => {
            Swal.fire({
                icon: 'success',
                title: 'Copied!',
                text: 'SQL copied to clipboard',
                timer: 1500,
                showConfirmButton: false
            });
        });
    }

    // ========== LOGOUT FUNCTION ==========
    function logout() {
        Swal.fire({
            title: 'Are you sure?',
            text: 'You will be logged out of the system',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#07d826',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, logout'
        }).then((result) => {
            if (result.isConfirmed) {
                localStorage.removeItem('sidebarCollapsed');
                window.location.href = '../logout.php';
            }
        });
    }

    // ========== KEYBOARD SHORTCUTS ==========
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
            e.preventDefault();
            toggleSidebar();
        } else if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            document.getElementById('searchInput').focus();
        } else if (e.ctrlKey && e.key === 'n') {
            e.preventDefault();
            showAddSupplierModal();
        }
    });
    </script>
</body>
</html>
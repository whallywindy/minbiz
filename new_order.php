<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include 'db_connect.php';
include 'check_subscription.php';

// ========== MULTI-TENANT CONFIGURATION ==========
$tenant_id = $_SESSION['tenant_id'] ?? 1;
$company_name = $_SESSION['company_name'] ?? 'Import Management System';
$product_category = $_SESSION['product_category'] ?? 'general';
$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['role'] ?? 'staff';
$is_super_admin = ($user_role == 'super_admin');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// ========== DETERMINE MODE (SALES or RETURN) ==========
$return_mode = isset($_GET['return_mode']) && $_GET['return_mode'] === 'sales' ? true : false;
$mode = $return_mode ? 'return' : 'sales';

// ========== INITIALIZE MESSAGE VARIABLES ==========
$error_message = '';
$success_message = '';
$order_details = null;
$show_payment_section = false;
$current_order_id = 0;
$current_invoice_number = '';
$pending_total = 0;
$pending_discount = 0;
$pending_tax = 0;
$pending_subtotal = 0;

// ============ TRIAL & SUBSCRIPTION EXPIRY CHECK ============
$stmt = $conn->prepare("SELECT subscription_status, trial_end_date, subscription_end_date, max_users FROM tenants WHERE id = ?");
$stmt->bind_param("i", $tenant_id);
$stmt->execute();
$tenant_check = $stmt->get_result()->fetch_assoc();
$stmt->close();

$current_date = date('Y-m-d H:i:s');
$trial_expired = false;
$subscription_active = false;
$redirect_to_subscription = false;
$days_left_trial = 0;
$days_left_subscription = 0;
$show_trial_banner = false;
$show_subscription_banner = false;
$trial_end_date_display = $tenant_check['trial_end_date'] ?? '';
$subscription_end_date = '';

if ($tenant_check) {
    $trial_end = $tenant_check['trial_end_date'] ?? null;
    $sub_end = $tenant_check['subscription_end_date'] ?? null;
    $status = $tenant_check['subscription_status'] ?? 'trial';
    
    if ($trial_end && $trial_end < $current_date && $status != 'active') {
        $trial_expired = true;
        $redirect_to_subscription = true;
    } elseif ($trial_end && $trial_end >= $current_date && $status != 'active') {
        $days_left_trial = ceil((strtotime($trial_end) - strtotime($current_date)) / 86400);
        $show_trial_banner = true;
        $trial_end_date_display = $trial_end;
    }
    
    if ($sub_end && $sub_end >= $current_date && $status == 'active') {
        $subscription_active = true;
        $days_left_subscription = ceil((strtotime($sub_end) - strtotime($current_date)) / 86400);
        $subscription_end_date = $sub_end;
        if ($days_left_subscription <= 7) {
            $show_subscription_banner = true;
        }
    }
    
    if ($sub_end && $sub_end < $current_date && $status == 'active') {
        $update = $conn->prepare("UPDATE tenants SET subscription_status = 'expired' WHERE id = ?");
        $update->bind_param("i", $tenant_id);
        $update->execute();
        $update->close();
        $redirect_to_subscription = true;
    }
}

if ($redirect_to_subscription) {
    $_SESSION['require_subscription'] = true;
    $_SESSION['trial_expired'] = true;
    header("Location: subscription.php?expired=1");
    exit();
}

$subscription_status = checkSubscriptionExpiry($conn, $tenant_id);

function formatDocumentCodeValue($value, $length = 10) {
    $text = trim((string)($value ?? ''));
    if ($text === '') {
        return str_repeat('0', $length);
    }

    $digits = preg_replace('/\D/', '', $text);
    if ($digits !== '') {
        $digits = substr($digits, -$length);
        return str_pad($digits, $length, '0', STR_PAD_LEFT);
    }

    $text = substr($text, 0, $length);
    return str_pad($text, $length, '0', STR_PAD_RIGHT);
}

// ========== GET SETTINGS FROM BUSINESS_SETTINGS TABLE ==========
function getSetting($conn, $tenant_id, $key, $default = '') {
    $stmt = $conn->prepare("SELECT setting_value FROM business_settings WHERE tenant_id = ? AND setting_key = ?");
    $stmt->bind_param("is", $tenant_id, $key);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['setting_value'];
    }
    return $default;
}

// Get company settings
$currency = getSetting($conn, $tenant_id, 'currency', 'NGN');
$currency_symbol = getSetting($conn, $tenant_id, 'currency_symbol', '₦');
$country = getSetting($conn, $tenant_id, 'country', 'Nigeria');
$timezone = getSetting($conn, $tenant_id, 'timezone', 'Africa/Lagos');
$date_format = getSetting($conn, $tenant_id, 'date_format', 'Y-m-d');
$invoice_size = getSetting($conn, $tenant_id, 'invoice_size', 'A4');
$company_address = getSetting($conn, $tenant_id, 'company_address', '');
$company_phone = getSetting($conn, $tenant_id, 'company_phone', '');
$company_email = getSetting($conn, $tenant_id, 'company_email', '');
$company_tax_number = getSetting($conn, $tenant_id, 'company_tax_number', '');
$tax_rate_default = getSetting($conn, $tenant_id, 'tax_rate', 0);
$tax_enabled = getSetting($conn, $tenant_id, 'tax_enabled', 'disabled');
$tax_name = getSetting($conn, $tenant_id, 'tax_name', 'VAT');

// Get tenant info for logo
$tenant_stmt = $conn->prepare("SELECT logo_path FROM tenants WHERE id = ?");
$tenant_stmt->bind_param("i", $tenant_id);
$tenant_stmt->execute();
$tenant_result = $tenant_stmt->get_result();
$tenant_info = $tenant_result->fetch_assoc();
$tenant_stmt->close();
$company_logo = $tenant_info['logo_path'] ?? '';

// Function to get PDF-safe currency display
function getPdfSafeCurrency($currency_code, $currency_symbol) {
    return $currency_code;
}

// Get payment channels from business_settings
$payment_channels_raw = getSetting($conn, $tenant_id, 'payment_channels', '[]');
$payment_channels_config = json_decode($payment_channels_raw, true);
if (!is_array($payment_channels_config)) {
    $payment_channels_config = [];
}

// Filter only active payment channels (EXCLUDING Cash since it's always present)
$active_payment_channels = array_filter($payment_channels_config, function($channel) {
    return isset($channel['is_active']) && $channel['is_active'] == 1 && strtolower($channel['name']) != 'cash';
});

date_default_timezone_set($timezone);

// Invoice sizes with dimensions and scaling factors
$invoice_sizes = [
    'A4' => ['width' => 210, 'height' => 297, 'name' => 'A4', 'orientation' => 'P', 'scale' => 1.0, 'margin' => 20, 'is_thermal' => false],
    'Letter' => ['width' => 216, 'height' => 279, 'name' => 'Letter', 'orientation' => 'P', 'scale' => 0.95, 'margin' => 18, 'is_thermal' => false],
    'A5' => ['width' => 148, 'height' => 210, 'name' => 'A5', 'orientation' => 'P', 'scale' => 0.7, 'margin' => 12, 'is_thermal' => false],
    'Thermal_58mm' => ['width' => 58, 'height' => 116, 'name' => 'Thermal (58mm)', 'orientation' => 'P', 'scale' => 0.28, 'margin' => 4, 'is_thermal' => true],
    'Thermal_80mm' => ['width' => 80, 'height' => 160, 'name' => 'Thermal (80mm)', 'orientation' => 'P', 'scale' => 0.38, 'margin' => 6, 'is_thermal' => true],
    'Legal' => ['width' => 216, 'height' => 356, 'name' => 'Legal', 'orientation' => 'P', 'scale' => 0.95, 'margin' => 18, 'is_thermal' => false]
];

// Get current size info
$current_size = isset($invoice_sizes[$invoice_size]) ? $invoice_sizes[$invoice_size] : $invoice_sizes['A4'];

// ========== FORM TOKEN INITIALIZATION ==========
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['payment_form_token'])) {
    $_SESSION['payment_form_token'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['return_form_token'])) {
    $_SESSION['return_form_token'] = bin2hex(random_bytes(32));
}
$page_form_token = $_SESSION['form_token'];
$payment_form_token = $_SESSION['payment_form_token'];
$return_form_token = $_SESSION['return_form_token'];

// Search parameters
$search_customer = isset($_GET['search_customer']) ? trim($_GET['search_customer']) : '';
$selected_customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;

// Preserve selected customer across requests
if ($selected_customer_id > 0) {
    $_SESSION['selected_customer_id'] = $selected_customer_id;
} elseif (isset($_SESSION['selected_customer_id']) && $_SESSION['selected_customer_id'] > 0 && $selected_customer_id == 0) {
    $selected_customer_id = $_SESSION['selected_customer_id'];
}

if (isset($_GET['clear_customer'])) {
    $selected_customer_id = 0;
    $search_customer = '';
    unset($_SESSION['selected_customer_id']);
}

// ========== DEFINE PATHS FOR RECEIPTS ==========
define('BASE_URL', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/');

define('RECEIPTS_DIR', __DIR__ . '/receipts/');
define('INVOICES_DIR', __DIR__ . '/invoices/');
define('SUPPLY_ORDERS_DIR', __DIR__ . '/supply_orders/');
define('RETURN_INVOICES_DIR', __DIR__ . '/return_invoices/');

foreach ([RECEIPTS_DIR, INVOICES_DIR, SUPPLY_ORDERS_DIR, RETURN_INVOICES_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

// Include FPDF
$fpdf_paths = [
    __DIR__ . '/fpdf/fpdf.php',
    __DIR__ . '/../fpdf/fpdf.php',
    '../fpdf/fpdf.php',
];
$fpdf_loaded = false;
foreach ($fpdf_paths as $path) {
    if (file_exists($path)) {
        require_once($path);
        $fpdf_loaded = true;
        break;
    }
}
if (!$fpdf_loaded) {
    die("FPDF library not found.");
}

// ========== GET CUSTOMERS FUNCTION ==========
function getCustomers($conn, $tenant_id, $search = '') {
    $customers = [];
    
    $check_column = $conn->query("SHOW COLUMNS FROM customers LIKE 'tenant_id'");
    $has_tenant_column = $check_column && $check_column->num_rows > 0;
    
    if ($has_tenant_column) {
        $query = "SELECT id, name, email, phone, customer_type, balance as account_balance, address, tax_number, status
                  FROM customers WHERE tenant_id = ? AND status = 'active'";
        $params = [$tenant_id];
    } else {
        $query = "SELECT id, name, email, phone, customer_type, balance as account_balance, address, tax_number, status
                  FROM customers WHERE status = 'active'";
        $params = [];
    }
    
    if (!empty($search)) {
        $query .= " AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)";
        $search_term = "%{$search}%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    $query .= " ORDER BY name";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $types = '';
        foreach ($params as $index => $param) {
            if ($index === 0 && $has_tenant_column) {
                $types .= 'i';
            } else {
                $types .= 's';
            }
        }
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $customers[] = $row;
    }
    $stmt->close();
    return $customers;
}

// ========== GET AVAILABLE PRODUCTS ==========
function getAvailableProducts($conn, $tenant_id) {
    $products = [];
    
    $check_column = $conn->query("SHOW COLUMNS FROM finished_goods_store LIKE 'tenant_id'");
    $has_tenant_column = $check_column && $check_column->num_rows > 0;
    
    if ($has_tenant_column) {
        $query = "SELECT fgs.id as store_item_id, fgs.product_id, fgs.quantity as available_quantity,
                         fgs.price_per_product as current_selling_price,
                         fgs.selling_unit, fgs.pack_quantity,
                         pi.id, pi.item_code, pi.name as product_name, pi.sqpp as standard_quantity_per_pack,
                         pi.is_pack_product, pi.selling_price_per_unit, pi.s_price, pi.pp_price,
                         pi.barcode
                  FROM finished_goods_store fgs
                  JOIN product_items pi ON fgs.product_id = pi.id
                  WHERE fgs.tenant_id = ? AND fgs.quantity > 0
                  ORDER BY pi.name";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $tenant_id);
    } else {
        $query = "SELECT fgs.id as store_item_id, fgs.product_id, fgs.quantity as available_quantity,
                         fgs.price_per_product as current_selling_price,
                         fgs.selling_unit, fgs.pack_quantity,
                         pi.id, pi.item_code, pi.name as product_name, pi.sqpp as standard_quantity_per_pack,
                         pi.is_pack_product, pi.selling_price_per_unit, pi.s_price, pi.pp_price,
                         pi.barcode
                  FROM finished_goods_store fgs
                  JOIN product_items pi ON fgs.product_id = pi.id
                  WHERE fgs.quantity > 0
                  ORDER BY pi.name";
        $stmt = $conn->prepare($query);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $selling_unit = $row['selling_unit'] ?? 'pack';
        $quantity_in_db = (int)$row['available_quantity'];
        
        if ($selling_unit == 'unit') {
            $display_stock = $quantity_in_db . ' units';
            $display_unit = 'units';
        } else {
            $display_stock = $quantity_in_db . ' packs';
            $display_unit = 'packs';
        }
        
        $row['display_stock'] = $display_stock;
        $row['display_unit'] = $display_unit;
        $row['available_quantity'] = $quantity_in_db;
        
        $products[] = $row;
    }
    $stmt->close();
    return $products;
}

// ========== INVOICE NUMBER FUNCTIONS ==========
function getNextInvoiceNumber($conn, $tenant_id) {
    $query = "SELECT invoice_number FROM orders WHERE invoice_number LIKE 'SO-%' 
              AND invoice_number IS NOT NULL AND invoice_number != '' AND invoice_number != '0'
              ORDER BY id DESC LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $last_invoice = $row['invoice_number'];
        if (preg_match('/SO-(\d+)/', $last_invoice, $matches)) {
            $next_number = intval($matches[1]) + 1;
            $stmt->close();
            return 'SO-' . str_pad((string)$next_number, 10, '0', STR_PAD_LEFT);
        }
    }
    $stmt->close();
    return 'SO-0000000001';
}

// ========== RETURN INVOICE NUMBER ==========
function getNextReturnInvoiceNumber($conn, $tenant_id) {
    $query = "SELECT return_invoice_number FROM product_returns 
              WHERE return_invoice_number LIKE 'RET-%' 
              AND return_invoice_number IS NOT NULL AND return_invoice_number != '' AND return_invoice_number != '0'
              AND tenant_id = ?
              ORDER BY id DESC LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $last_invoice = $row['return_invoice_number'];
        if (preg_match('/RET-(\d+)/', $last_invoice, $matches)) {
            $next_number = intval($matches[1]) + 1;
            $stmt->close();
            return 'RET-' . str_pad((string)$next_number, 10, '0', STR_PAD_LEFT);
        }
    }
    $stmt->close();
    return 'RET-0000000001';
}

function fmt_currency($amt) {
    global $currency_symbol;
    return $currency_symbol . ' ' . number_format((float)$amt, 2);
}

// ========== BARCODE SEARCH ENDPOINT ==========
if (isset($_GET['barcode_search'])) {
    $barcode = trim($_GET['barcode_search']);
    if (!empty($barcode)) {
        $stmt = $conn->prepare("SELECT fgs.id as store_item_id, fgs.product_id, fgs.quantity as available_quantity,
                         fgs.price_per_product as current_selling_price,
                         fgs.selling_unit, fgs.pack_quantity,
                         pi.item_code, pi.name as product_name, pi.sqpp as standard_quantity_per_pack,
                         pi.is_pack_product, pi.selling_price_per_unit, pi.s_price, pi.pp_price,
                         pi.barcode
                  FROM finished_goods_store fgs
                  JOIN product_items pi ON fgs.product_id = pi.id
                  WHERE pi.barcode = ? AND fgs.tenant_id = ? AND fgs.quantity > 0");
        $stmt->bind_param("si", $barcode, $tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $selling_unit = $row['selling_unit'] ?? 'pack';
            $quantity_in_db = (int)$row['available_quantity'];
            
            if ($selling_unit == 'unit') {
                $row['display_stock'] = $quantity_in_db . ' units';
                $row['display_unit'] = 'units';
            } else {
                $row['display_stock'] = $quantity_in_db . ' packs';
                $row['display_unit'] = 'packs';
            }
            $row['available_quantity'] = $quantity_in_db;
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'product' => $row]);
            exit();
        }
        $stmt->close();
        
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit();
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Please enter a barcode']);
        exit();
    }
}

// ========== GET ORDER FOR RETURN ==========
if (isset($_GET['get_order_for_return']) && $return_mode) {
    $order_number = trim($_GET['get_order_for_return']);
    
    if (!empty($order_number)) {
        $stmt = $conn->prepare("SELECT o.*, c.name as customer_name, c.phone, c.email, c.address 
                               FROM orders o 
                               LEFT JOIN customers c ON o.customer_id = c.id 
                               WHERE o.invoice_number = ? AND o.tenant_id = ? AND o.status IN ('completed', 'partially_paid')");
        $stmt->bind_param("si", $order_number, $tenant_id);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($order) {
            $stmt = $conn->prepare("SELECT oi.*, pi.item_code, pi.name as product_name, pi.selling_unit, pi.sqpp
                                   FROM order_items oi 
                                   JOIN product_items pi ON oi.product_id = pi.id 
                                   WHERE oi.order_id = ?");
            $stmt->bind_param("i", $order['id']);
            $stmt->execute();
            $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            foreach ($items as &$item) {
                $stmt = $conn->prepare("SELECT SUM(ri.quantity) as returned_qty 
                                       FROM return_items ri
                                       JOIN product_returns pr ON ri.return_id = pr.id
                                       WHERE pr.original_order_id = ? AND ri.product_id = ? AND pr.status != 'cancelled' AND pr.tenant_id = ?");
                $stmt->bind_param("iii", $order['id'], $item['product_id'], $tenant_id);
                $stmt->execute();
                $result = $stmt->get_result()->fetch_assoc();
                $item['returned_qty'] = (int)($result['returned_qty'] ?? 0);
                $item['available_qty'] = max(0, $item['quantity'] - $item['returned_qty']);
                $stmt->close();
            }
            
            $order['items'] = $items;
            $order['source'] = 'orders';
            $order['order_id'] = $order['id'];
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'order' => $order]);
            exit();
        }
        
        $legacy_check = $conn->query("SHOW TABLES LIKE 'legacy_orders'");
        if ($legacy_check && $legacy_check->num_rows > 0) {
            $stmt = $conn->prepare("SELECT lo.*, 
                                   COALESCE(c.name, lo.customer_name) as customer_name,
                                   COALESCE(c.phone, lo.customer_phone) as phone,
                                   COALESCE(c.email, lo.customer_email) as email,
                                   COALESCE(c.address, lo.customer_address) as address
                                   FROM legacy_orders lo
                                   LEFT JOIN customers c ON lo.customer_id = c.id
                                   WHERE lo.invoice_number = ? AND lo.tenant_id = ?");
            $stmt->bind_param("si", $order_number, $tenant_id);
            $stmt->execute();
            $legacy_order = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($legacy_order) {
                $stmt = $conn->prepare("SELECT loi.*, pi.item_code, pi.name as product_name, pi.selling_unit, pi.sqpp
                                       FROM legacy_order_items loi 
                                       LEFT JOIN product_items pi ON loi.product_id = pi.id 
                                       WHERE loi.legacy_order_id = ?");
                $stmt->bind_param("i", $legacy_order['id']);
                $stmt->execute();
                $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                
                $customer_id = $legacy_order['customer_id'];
                if (!$customer_id) {
                    $customer_name = $legacy_order['customer_name'] ?? 'Legacy Customer';
                    $stmt = $conn->prepare("INSERT INTO customers (tenant_id, name, phone, email, address, customer_type, status, balance) 
                                           VALUES (?, ?, ?, ?, ?, 'legacy', 'active', 0)");
                    $stmt->bind_param("issss", $tenant_id, $customer_name, 
                                     $legacy_order['customer_phone'] ?? '',
                                     $legacy_order['customer_email'] ?? '',
                                     $legacy_order['customer_address'] ?? '');
                    $stmt->execute();
                    $customer_id = $conn->insert_id;
                    $stmt->close();
                    
                    $stmt = $conn->prepare("UPDATE legacy_orders SET customer_id = ? WHERE id = ? AND tenant_id = ?");
                    $stmt->bind_param("iii", $customer_id, $legacy_order['id'], $tenant_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    $legacy_order['customer_name'] = $customer_name;
                }
                
                foreach ($items as &$item) {
                    $item['returned_qty'] = 0;
                    $item['available_qty'] = $item['quantity'];
                    $item['price_per_product'] = $item['unit_price'] ?? 0;
                }
                
                $legacy_order['items'] = $items;
                $legacy_order['source'] = 'legacy';
                $legacy_order['order_id'] = $legacy_order['id'];
                
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'order' => $legacy_order]);
                exit();
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Order not found. Please check the invoice number.']);
        exit();
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Please enter an invoice number.']);
        exit();
    }
}

// ==================================================================
// PDF GENERATION FUNCTIONS
// ==================================================================

// ===== INVOICE PDF =====
function generateInvoicePDF($order_data, $customer_data, $items, $conn, $payment_made = 0, $payment_status = 'full') {
    global $fpdf_loaded, $currency, $currency_symbol, $company_name, $company_address, $company_phone, $company_email, $company_tax_number, $tenant_id, $invoice_size, $invoice_sizes, $company_logo;
    
    if (!$order_data || !is_array($order_data)) {
        return ['error' => true, 'message' => 'Invalid order data'];
    }
    
    try {
        if (!$fpdf_loaded) {
            $fpdf_paths = [
                __DIR__ . '/fpdf/fpdf.php',
                __DIR__ . '/../fpdf/fpdf.php',
                '../fpdf/fpdf.php',
            ];
            foreach ($fpdf_paths as $path) {
                if (file_exists($path)) {
                    require_once($path);
                    $fpdf_loaded = true;
                    break;
                }
            }
            if (!$fpdf_loaded) {
                throw new Exception("FPDF library not loaded");
            }
        }
        
        $pdf_currency = getPdfSafeCurrency($currency, $currency_symbol);
        
        $size_info = isset($invoice_sizes[$invoice_size]) ? $invoice_sizes[$invoice_size] : $invoice_sizes['A4'];
        $orientation = $size_info['orientation'];
        $width = $size_info['width'];
        $height = $size_info['height'];
        $scale = $size_info['scale'];
        $margin = $size_info['margin'];
        $is_thermal = $size_info['is_thermal'] ?? false;
        
        if ($is_thermal) {
            $pdf = new FPDF($orientation, 'mm', array($width, $height));
        } else {
            $pdf = new FPDF($orientation, 'mm', $size_info['name']);
        }
        
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 10 * $scale);
        
        $left_margin = $margin;
        $right_margin = $margin;
        $pdf->SetLeftMargin($left_margin);
        $pdf->SetRightMargin($right_margin);
        
        $center_x = $width / 2;
        $content_width = $width - ($left_margin + $right_margin);
        
        $background_paths = [
            __DIR__ . '/../images/background.jpeg',
            __DIR__ . '/../../images/background.jpeg',
            '../images/background.jpeg',
            '../../images/background.jpeg',
        ];
        foreach ($background_paths as $path) {
            if (file_exists($path)) {
                $pdf->Image($path, 0, 0, $width, $height);
                break;
            }
        }
        
        $header_start = $is_thermal ? $height * 0.03 : $height * 0.08;
        $y_position = $header_start;
        $pdf->SetY($y_position);
        
        $logo_path = getLogoData($company_logo);
        if ($logo_path) {
            $logo_width = $is_thermal ? min(20 * $scale, $content_width * 0.2) : min(40 * $scale, $content_width * 0.25);
            $pdf->Image($logo_path, $center_x - ($logo_width / 2), $y_position, $logo_width);
            $logo_spacing = $is_thermal ? 12 * $scale : 24 * $scale;
            $pdf->SetY($y_position + $logo_width * 0.3 + $logo_spacing);
        } else {
            $pdf->SetY($y_position + ($is_thermal ? 10 : 17) * $scale);
        }
        
        $pdf->SetFont('Arial', 'B', ($is_thermal ? 10 : 14) * $scale);
        $pdf->Cell($content_width, ($is_thermal ? 4 : 6) * $scale, $company_name, 0, 1, 'C');
        $pdf->SetFont('Arial', '', ($is_thermal ? 6 : 8) * $scale);
        
        if (!empty($company_address)) {
            $address_lines = wordwrap($company_address, $is_thermal ? 30 : 60, "\n");
            foreach (explode("\n", $address_lines) as $line) {
                $pdf->Cell($content_width, ($is_thermal ? 2.5 : 4) * $scale, $line, 0, 1, 'C');
            }
        }
        
        $contact_info = '';
        if (!empty($company_phone)) $contact_info .= 'Tel: ' . $company_phone;
        if (!empty($company_email)) $contact_info .= ($contact_info ? ' | ' : '') . 'Email: ' . $company_email;
        if (!empty($contact_info)) {
            $pdf->Cell($content_width, ($is_thermal ? 2.5 : 4) * $scale, $contact_info, 0, 1, 'C');
        }
        if (!empty($company_tax_number)) {
            $pdf->SetFont('Arial', 'B', ($is_thermal ? 6 : 8) * $scale);
            $pdf->Cell($content_width, ($is_thermal ? 2.5 : 4) * $scale, 'Tax ID: ' . $company_tax_number, 0, 1, 'C');
        }
        
        $pdf->Ln(($is_thermal ? 2 : 5) * $scale);
        
        $pdf->SetFont('Arial', 'B', ($is_thermal ? 10 : 16) * $scale);
        $pdf->SetFillColor(220, 53, 69);
        $pdf->SetTextColor(255, 255, 255);
        $title_height = ($is_thermal ? 6 : 10) * $scale;
        $pdf->Cell($content_width, $title_height, $is_thermal ? 'INVOICE' : 'SALES INVOICE', 0, 1, 'C', true);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(($is_thermal ? 1 : 3) * $scale);
        
        $pdf->SetFont('Arial', '', ($is_thermal ? 6 : 9) * $scale);
        $line_height = ($is_thermal ? 3.5 : 6) * $scale;
        $pdf->Cell($content_width * 0.4, $line_height, 'Invoice Number:', 0, 0);
        $pdf->Cell($content_width * 0.6, $line_height, $order_data['order_number'] ?? 'N/A', 0, 1);
        $pdf->Cell($content_width * 0.4, ($is_thermal ? 3 : 5) * $scale, 'Invoice Date:', 0, 0);
        $pdf->Cell($content_width * 0.6, ($is_thermal ? 3 : 5) * $scale, date('F j, Y H:i:s'), 0, 1);
        $pdf->Ln(($is_thermal ? 1 : 3) * $scale);
        
        $pdf->SetFont('Arial', 'B', ($is_thermal ? 7 : 10) * $scale);
        $pdf->Cell($content_width, ($is_thermal ? 3.5 : 6) * $scale, 'BILL TO:', 0, 1);
        $pdf->SetFont('Arial', '', ($is_thermal ? 6 : 9) * $scale);
        $line_height = ($is_thermal ? 3 : 5) * $scale;
        $pdf->Cell($content_width, $line_height, $customer_data['name'] ?? 'N/A', 0, 1);
        if (!empty($customer_data['address'])) {
            $address_lines = wordwrap($customer_data['address'], $is_thermal ? 25 : 60, "\n");
            foreach (explode("\n", $address_lines) as $line) {
                $pdf->Cell($content_width, ($is_thermal ? 2.5 : 4) * $scale, $line, 0, 1);
            }
        }
        $pdf->Cell($content_width, $line_height, 'Phone: ' . ($customer_data['phone'] ?? 'N/A'), 0, 1);
        if (!empty($customer_data['email'])) {
            $pdf->Cell($content_width, $line_height, 'Email: ' . $customer_data['email'], 0, 1);
        }
        if (!empty($customer_data['tax_number'])) {
            $pdf->Cell($content_width, $line_height, 'Tax ID: ' . $customer_data['tax_number'], 0, 1);
        }
        $pdf->Ln(($is_thermal ? 1 : 3) * $scale);
        
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetFont('Arial', 'B', ($is_thermal ? 6 : 9) * $scale);
        $row_height = ($is_thermal ? 5 : 8) * $scale;
        
        $table_width = $content_width * 0.9;
        $table_start_x = ($content_width - $table_width) / 2;
        $pdf->SetX($table_start_x);
        
        if ($is_thermal) {
            $col_widths = [
                'sn' => $table_width * 0.08,
                'code' => $table_width * 0.25,
                'item' => $table_width * 0.30,
                'qty' => $table_width * 0.10,
                'price' => $table_width * 0.13,
                'total' => $table_width * 0.14
            ];
            
            $pdf->Cell($col_widths['sn'], $row_height, '#', 1, 0, 'C', true);
            $pdf->Cell($col_widths['code'], $row_height, 'Code', 1, 0, 'C', true);
            $pdf->Cell($col_widths['item'], $row_height, 'Item', 1, 0, 'C', true);
            $pdf->Cell($col_widths['qty'], $row_height, 'Qty', 1, 0, 'C', true);
            $pdf->Cell($col_widths['price'], $row_height, 'Price', 1, 0, 'C', true);
            $pdf->Cell($col_widths['total'], $row_height, 'Total', 1, 1, 'C', true);
        } else {
            $col_widths = [
                'sn' => $table_width * 0.06,
                'code' => $table_width * 0.20,
                'item' => $table_width * 0.30,
                'qty' => $table_width * 0.10,
                'price' => $table_width * 0.16,
                'total' => $table_width * 0.18
            ];
            
            $pdf->Cell($col_widths['sn'], $row_height, 'S/N', 1, 0, 'C', true);
            $pdf->Cell($col_widths['code'], $row_height, 'Item No.', 1, 0, 'C', true);
            $pdf->Cell($col_widths['item'], $row_height, 'Description', 1, 0, 'C', true);
            $pdf->Cell($col_widths['qty'], $row_height, 'Qty', 1, 0, 'C', true);
            $pdf->Cell($col_widths['price'], $row_height, 'Unit Price', 1, 0, 'C', true);
            $pdf->Cell($col_widths['total'], $row_height, 'Total', 1, 1, 'C', true);
        }
        
        $pdf->SetFont('Arial', '', ($is_thermal ? 5 : 8) * $scale);
        $subtotal = 0;
        $serial = 1;
        $fill = false;
        $row_height = ($is_thermal ? 4 : 7) * $scale;
        
        foreach ($items as $item) {
            $unit_price = (float)($item['unit_price'] ?? 0);
            $quantity = (int)($item['quantity'] ?? 0);
            $line_total = $unit_price * $quantity;
            $subtotal += $line_total;
            
            $pdf->SetX($table_start_x);
            
            if ($is_thermal) {
                $pdf->Cell($col_widths['sn'], $row_height, $serial, 1, 0, 'C', $fill);
                $pdf->Cell($col_widths['code'], $row_height, $item['item_code'] ?? 'N/A', 1, 0, 'C', $fill);
                $product_name = $item['product_name'] ?? 'Unknown';
                $pdf->Cell($col_widths['item'], $row_height, substr($product_name, 0, 12), 1, 0, 'L', $fill);
                $pdf->Cell($col_widths['qty'], $row_height, number_format($quantity), 1, 0, 'C', $fill);
                $pdf->Cell($col_widths['price'], $row_height, number_format($unit_price, 0), 1, 0, 'R', $fill);
                $pdf->Cell($col_widths['total'], $row_height, number_format($line_total, 0), 1, 1, 'R', $fill);
            } else {
                $pdf->Cell($col_widths['sn'], $row_height, $serial, 1, 0, 'C', $fill);
                $pdf->Cell($col_widths['code'], $row_height, $item['item_code'] ?? 'N/A', 1, 0, 'C', $fill);
                $product_name = $item['product_name'] ?? 'Unknown';
                if (strlen($product_name) > 30) $product_name = substr($product_name, 0, 28) . '...';
                $pdf->Cell($col_widths['item'], $row_height, $product_name, 1, 0, 'L', $fill);
                $pdf->Cell($col_widths['qty'], $row_height, number_format($quantity), 1, 0, 'C', $fill);
                $pdf->Cell($col_widths['price'], $row_height, $pdf_currency . ' ' . number_format($unit_price, 2), 1, 0, 'R', $fill);
                $pdf->Cell($col_widths['total'], $row_height, $pdf_currency . ' ' . number_format($line_total, 2), 1, 1, 'R', $fill);
            }
            $fill = !$fill;
            $serial++;
        }
        
        $pdf->Ln(($is_thermal ? 1 : 3) * $scale);
        
        $discount_amount = (float)($order_data['discount_amount'] ?? 0);
        $tax_amount = (float)($order_data['tax_amount'] ?? 0);
        $total = $subtotal - $discount_amount + $tax_amount;
        $balance_due = max(0, $total - (float)$payment_made);
        
        $pdf->SetFont('Arial', '', ($is_thermal ? 6 : 9) * $scale);
        $line_height = ($is_thermal ? 4 : 6) * $scale;
        
        $total_start_x = $table_start_x + $table_width - ($col_widths['total'] + $col_widths['price']);
        
        $label_width = 30 * $scale;
        $value_width = 35 * $scale;
        $pdf->Cell($total_start_x - $pdf->GetX(), $line_height, '', 0, 0);
        $pdf->Cell($label_width, $line_height, 'Subtotal:', 0, 0, 'R');
        $pdf->Cell($value_width, $line_height, $pdf_currency . ' ' . number_format($subtotal, 2), 0, 1, 'R');
        
        if ($discount_amount > 0) {
            $pdf->Cell($total_start_x - $pdf->GetX(), $line_height, '', 0, 0);
            $pdf->Cell($label_width, $line_height, 'Discount:', 0, 0, 'R');
            $pdf->Cell($value_width, $line_height, '-' . $pdf_currency . ' ' . number_format($discount_amount, 2), 0, 1, 'R');
        }
        
        if ($tax_amount > 0) {
            $pdf->Cell($total_start_x - $pdf->GetX(), $line_height, '', 0, 0);
            $pdf->Cell($label_width, $line_height, 'Tax:', 0, 0, 'R');
            $pdf->Cell($value_width, $line_height, $pdf_currency . ' ' . number_format($tax_amount, 2), 0, 1, 'R');
        }
        
        if ($payment_made > 0) {
            $pdf->Cell($total_start_x - $pdf->GetX(), $line_height, '', 0, 0);
            $pdf->Cell($label_width, $line_height, 'Amount Paid:', 0, 0, 'R');
            $pdf->Cell($value_width, $line_height, $pdf_currency . ' ' . number_format((float)$payment_made, 2), 0, 1, 'R');
        }
        
        $pdf->SetFont('Arial', 'B', 11 * $scale);
        if ($balance_due > 0) {
            $pdf->SetTextColor(220, 53, 69);
        } else {
            $pdf->SetTextColor(0, 0, 0);
        }
        $pdf->Cell($total_start_x - $pdf->GetX(), 8 * $scale, '', 0, 0);
        $pdf->Cell($label_width, 8 * $scale, 'TOTAL:', 0, 0, 'R');
        $pdf->Cell($value_width, 8 * $scale, $pdf_currency . ' ' . number_format($total, 2), 0, 1, 'R');
        $pdf->SetTextColor(0, 0, 0);
        
        $current_y = $pdf->GetY();
        $footer_needed = ($is_thermal ? 12 : 25) * $scale;
        $space_left = $height - $current_y - ($is_thermal ? 5 : 10) * $scale;
        
        if ($space_left < $footer_needed && !$is_thermal) {
            $pdf->AddPage();
            $pdf->SetY($height - $footer_needed - 10 * $scale);
        }
        
        $pdf->SetFont('Arial', 'I', ($is_thermal ? 5 : 8) * $scale);
        $pdf->SetTextColor(0, 0, 128);
        $pdf->Cell($content_width, ($is_thermal ? 2.5 : 4) * $scale, 'Thank you for your patronage!', 0, 1, 'C');
        $pdf->SetFont('Arial', 'I', ($is_thermal ? 4 : 6) * $scale);
        $pdf->Cell($content_width, ($is_thermal ? 2 : 3) * $scale, 'Computer-generated invoice.', 0, 1, 'C');
        
        if ($is_thermal) {
            $pdf->Ln(2 * $scale);
            $pdf->SetFont('Arial', '', 4 * $scale);
            $pdf->SetTextColor(200, 200, 200);
            $pdf->Cell($content_width, 2 * $scale, '---------- CUT HERE ----------', 0, 1, 'C');
            $pdf->SetTextColor(0, 0, 0);
        }
        
        $filename = 'invoice_' . ($order_data['order_number'] ?? 'unknown') . '_' . date('Ymd_His') . '.pdf';
        $filepath = INVOICES_DIR . $filename;
        $pdf->Output($filepath, 'F');
        
        if (!file_exists($filepath)) {
            throw new Exception("Failed to create PDF file at: " . $filepath);
        }
        
        return [
            'path' => $filepath, 
            'filename' => $filename, 
            'download_url' => getDownloadUrl($filename, 'invoices'),
            'invoice_number' => $order_data['order_number'] ?? 'N/A',
            'invoice_size' => $invoice_size,
            'success' => true
        ];
    } catch (Exception $e) {
        error_log("PDF Generation Error: " . $e->getMessage());
        return ['error' => true, 'message' => $e->getMessage()];
    }
}

// ===== SUPPLY ORDER PDF =====
function generateSupplyOrderPDF($order_data, $customer_data, $items, $conn) {
    global $fpdf_loaded, $currency, $currency_symbol, $company_name, $company_address, $company_phone, $company_email, $tenant_id, $invoice_size, $invoice_sizes, $company_logo;
    
    if (!$order_data || !is_array($order_data)) {
        return ['error' => true, 'message' => 'Invalid order data'];
    }
    
    try {
        if (!$fpdf_loaded) {
            $fpdf_paths = [
                __DIR__ . '/fpdf/fpdf.php',
                __DIR__ . '/../fpdf/fpdf.php',
                '../fpdf/fpdf.php',
            ];
            foreach ($fpdf_paths as $path) {
                if (file_exists($path)) {
                    require_once($path);
                    $fpdf_loaded = true;
                    break;
                }
            }
            if (!$fpdf_loaded) {
                throw new Exception("FPDF library not loaded");
            }
        }
        
        $pdf_currency = getPdfSafeCurrency($currency, $currency_symbol);
        
        $size_info = isset($invoice_sizes[$invoice_size]) ? $invoice_sizes[$invoice_size] : $invoice_sizes['A4'];
        $orientation = $size_info['orientation'];
        $width = $size_info['width'];
        $height = $size_info['height'];
        $scale = $size_info['scale'];
        $margin = $size_info['margin'];
        $is_thermal = $size_info['is_thermal'] ?? false;
        
        if ($is_thermal) {
            $pdf = new FPDF($orientation, 'mm', array($width, $height));
        } else {
            $pdf = new FPDF($orientation, 'mm', $size_info['name']);
        }
        
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 10 * $scale);
        
        $left_margin = $margin;
        $right_margin = $margin;
        $pdf->SetLeftMargin($left_margin);
        $pdf->SetRightMargin($right_margin);
        
        $center_x = $width / 2;
        $content_width = $width - ($left_margin + $right_margin);
        
        $background_paths = [
            __DIR__ . '/../images/background.jpeg',
            __DIR__ . '/../../images/background.jpeg',
            '../images/background.jpeg',
            '../../images/background.jpeg',
        ];
        foreach ($background_paths as $path) {
            if (file_exists($path)) {
                $pdf->Image($path, 0, 0, $width, $height);
                break;
            }
        }
        
        $header_start = $is_thermal ? $height * 0.03 : $height * 0.08;
        $y_position = $header_start;
        $pdf->SetY($y_position);
        
        $logo_path = getLogoData($company_logo);
        if ($logo_path) {
            $logo_width = $is_thermal ? min(20 * $scale, $content_width * 0.2) : min(40 * $scale, $content_width * 0.25);
            $pdf->Image($logo_path, $center_x - ($logo_width / 2), $y_position, $logo_width);
            $logo_spacing = $is_thermal ? 12 * $scale : 24 * $scale;
            $pdf->SetY($y_position + $logo_width * 0.3 + $logo_spacing);
        } else {
            $pdf->SetY($y_position + ($is_thermal ? 10 : 17) * $scale);
        }
        
        $pdf->SetFont('Arial', 'B', ($is_thermal ? 10 : 14) * $scale);
        $pdf->Cell($content_width, ($is_thermal ? 4 : 6) * $scale, $company_name, 0, 1, 'C');
        $pdf->SetFont('Arial', '', ($is_thermal ? 6 : 8) * $scale);
        
        if (!empty($company_address)) {
            $address_lines = wordwrap($company_address, $is_thermal ? 30 : 60, "\n");
            foreach (explode("\n", $address_lines) as $line) {
                $pdf->Cell($content_width, ($is_thermal ? 2.5 : 4) * $scale, $line, 0, 1, 'C');
            }
        }
        
        $contact_info = '';
        if (!empty($company_phone)) $contact_info .= 'Tel: ' . $company_phone;
        if (!empty($company_email)) $contact_info .= ($contact_info ? ' | ' : '') . 'Email: ' . $company_email;
        if (!empty($contact_info)) {
            $pdf->Cell($content_width, ($is_thermal ? 2.5 : 4) * $scale, $contact_info, 0, 1, 'C');
        }
        
        $pdf->Ln(($is_thermal ? 2 : 5) * $scale);
        
        $pdf->SetFont('Arial', 'B', ($is_thermal ? 10 : 16) * $scale);
        $pdf->SetFillColor(40, 167, 69);
        $pdf->SetTextColor(255, 255, 255);
        $title_height = ($is_thermal ? 6 : 10) * $scale;
        $pdf->Cell($content_width, $title_height, $is_thermal ? 'SUPPLY ORDER' : 'SUPPLY ORDER', 0, 1, 'C', true);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(($is_thermal ? 1 : 3) * $scale);
        
        $pdf->SetFont('Arial', '', ($is_thermal ? 6 : 9) * $scale);
        $line_height = ($is_thermal ? 3.5 : 6) * $scale;
        $pdf->Cell($content_width * 0.4, $line_height, 'Order Number:', 0, 0);
        $pdf->Cell($content_width * 0.6, $line_height, $order_data['order_number'] ?? 'N/A', 0, 1);
        $pdf->Cell($content_width * 0.4, ($is_thermal ? 3 : 5) * $scale, 'Date:', 0, 0);
        $pdf->Cell($content_width * 0.6, ($is_thermal ? 3 : 5) * $scale, date('F j, Y H:i:s'), 0, 1);
        $pdf->Ln(($is_thermal ? 1 : 3) * $scale);
        
        $pdf->SetFont('Arial', 'B', ($is_thermal ? 7 : 10) * $scale);
        $pdf->Cell($content_width, ($is_thermal ? 3.5 : 6) * $scale, 'CUSTOMER:', 0, 1);
        $pdf->SetFont('Arial', '', ($is_thermal ? 6 : 9) * $scale);
        $line_height = ($is_thermal ? 3 : 5) * $scale;
        $pdf->Cell($content_width, $line_height, $customer_data['name'] ?? 'N/A', 0, 1);
        if (!empty($customer_data['address'])) {
            $address_lines = wordwrap($customer_data['address'], $is_thermal ? 25 : 60, "\n");
            foreach (explode("\n", $address_lines) as $line) {
                $pdf->Cell($content_width, ($is_thermal ? 2.5 : 4) * $scale, $line, 0, 1);
            }
        }
        $pdf->Cell($content_width, $line_height, 'Phone: ' . ($customer_data['phone'] ?? 'N/A'), 0, 1);
        if (!empty($customer_data['email'])) {
            $pdf->Cell($content_width, $line_height, 'Email: ' . $customer_data['email'], 0, 1);
        }
        $pdf->Ln(($is_thermal ? 1 : 3) * $scale);
        
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetFont('Arial', 'B', ($is_thermal ? 6 : 9) * $scale);
        $row_height = ($is_thermal ? 5 : 8) * $scale;
        
        $table_width = $content_width * 0.8;
        $table_start_x = ($content_width - $table_width) / 2;
        $pdf->SetX($table_start_x);
        
        if ($is_thermal) {
            $col_widths = [
                'sn' => $table_width * 0.10,
                'code' => $table_width * 0.30,
                'item' => $table_width * 0.40,
                'qty' => $table_width * 0.20
            ];
            
            $pdf->Cell($col_widths['sn'], $row_height, '#', 1, 0, 'C', true);
            $pdf->Cell($col_widths['code'], $row_height, 'Code', 1, 0, 'C', true);
            $pdf->Cell($col_widths['item'], $row_height, 'Item', 1, 0, 'C', true);
            $pdf->Cell($col_widths['qty'], $row_height, 'Qty', 1, 1, 'C', true);
        } else {
            $col_widths = [
                'sn' => $table_width * 0.08,
                'code' => $table_width * 0.25,
                'item' => $table_width * 0.45,
                'qty' => $table_width * 0.22
            ];
            
            $pdf->Cell($col_widths['sn'], $row_height, 'S/N', 1, 0, 'C', true);
            $pdf->Cell($col_widths['code'], $row_height, 'Item No.', 1, 0, 'C', true);
            $pdf->Cell($col_widths['item'], $row_height, 'Description', 1, 0, 'C', true);
            $pdf->Cell($col_widths['qty'], $row_height, 'Quantity', 1, 1, 'C', true);
        }
        
        $pdf->SetFont('Arial', '', ($is_thermal ? 5 : 8) * $scale);
        $serial = 1;
        $fill = false;
        $row_height = ($is_thermal ? 4 : 7) * $scale;
        
        foreach ($items as $item) {
            $quantity = (int)($item['quantity'] ?? 0);
            
            $pdf->SetX($table_start_x);
            
            if ($is_thermal) {
                $pdf->Cell($col_widths['sn'], $row_height, $serial, 1, 0, 'C', $fill);
                $pdf->Cell($col_widths['code'], $row_height, $item['item_code'] ?? 'N/A', 1, 0, 'C', $fill);
                $product_name = $item['product_name'] ?? 'Unknown';
                $pdf->Cell($col_widths['item'], $row_height, substr($product_name, 0, 15), 1, 0, 'L', $fill);
                $pdf->Cell($col_widths['qty'], $row_height, number_format($quantity), 1, 1, 'C', $fill);
            } else {
                $pdf->Cell($col_widths['sn'], $row_height, $serial, 1, 0, 'C', $fill);
                $pdf->Cell($col_widths['code'], $row_height, $item['item_code'] ?? 'N/A', 1, 0, 'C', $fill);
                $product_name = $item['product_name'] ?? 'Unknown';
                if (strlen($product_name) > 40) $product_name = substr($product_name, 0, 38) . '...';
                $pdf->Cell($col_widths['item'], $row_height, $product_name, 1, 0, 'L', $fill);
                $pdf->Cell($col_widths['qty'], $row_height, number_format($quantity), 1, 1, 'C', $fill);
            }
            $fill = !$fill;
            $serial++;
        }
        
        $pdf->Ln(($is_thermal ? 1 : 3) * $scale);
        
        $current_y = $pdf->GetY();
        $footer_needed = ($is_thermal ? 12 : 25) * $scale;
        $space_left = $height - $current_y - ($is_thermal ? 5 : 10) * $scale;
        
        if ($space_left < $footer_needed && !$is_thermal) {
            $pdf->AddPage();
            $pdf->SetY($height - $footer_needed - 10 * $scale);
        }
        
        $pdf->SetFont('Arial', 'I', ($is_thermal ? 5 : 8) * $scale);
        $pdf->SetTextColor(0, 0, 128);
        $pdf->Cell($content_width, ($is_thermal ? 2.5 : 4) * $scale, 'Thank you for your order!', 0, 1, 'C');
        $pdf->SetFont('Arial', 'I', ($is_thermal ? 4 : 6) * $scale);
        $pdf->Cell($content_width, ($is_thermal ? 2 : 3) * $scale, 'Computer-generated supply order - Items are for supply only.', 0, 1, 'C');
        
        if ($is_thermal) {
            $pdf->Ln(2 * $scale);
            $pdf->SetFont('Arial', '', 4 * $scale);
            $pdf->SetTextColor(200, 200, 200);
            $pdf->Cell($content_width, 2 * $scale, '---------- CUT HERE ----------', 0, 1, 'C');
            $pdf->SetTextColor(0, 0, 0);
        }
        
        $filename = 'supply_order_' . ($order_data['order_number'] ?? 'unknown') . '_' . date('Ymd_His') . '.pdf';
        $filepath = SUPPLY_ORDERS_DIR . $filename;
        $pdf->Output($filepath, 'F');
        
        if (!file_exists($filepath)) {
            throw new Exception("Failed to create supply order PDF at: " . $filepath);
        }
        
        return [
            'path' => $filepath, 
            'filename' => $filename, 
            'download_url' => getDownloadUrl($filename, 'supply_orders'),
            'order_number' => $order_data['order_number'] ?? 'N/A',
            'success' => true
        ];
    } catch (Exception $e) {
        error_log("Supply Order PDF Generation Error: " . $e->getMessage());
        return ['error' => true, 'message' => $e->getMessage()];
    }
}

// ===== PAYMENT RECEIPT PDF =====
function generatePaymentReceiptPDF($order_data, $customer_data, $payment_data, $conn) {
    global $fpdf_loaded, $currency, $currency_symbol, $company_name, $company_address, $company_phone, $company_email, $tenant_id, $invoice_size, $invoice_sizes, $company_logo;
    
    if (!$order_data || !is_array($order_data) || !$payment_data || !is_array($payment_data)) {
        return ['error' => true, 'message' => 'Invalid payment data'];
    }
    
    try {
        if (!$fpdf_loaded) {
            $fpdf_paths = [
                __DIR__ . '/fpdf/fpdf.php',
                __DIR__ . '/../fpdf/fpdf.php',
                '../fpdf/fpdf.php',
            ];
            foreach ($fpdf_paths as $path) {
                if (file_exists($path)) {
                    require_once($path);
                    $fpdf_loaded = true;
                    break;
                }
            }
            if (!$fpdf_loaded) {
                throw new Exception("FPDF library not loaded");
            }
        }
        
        $pdf_currency = getPdfSafeCurrency($currency, $currency_symbol);
        
        $size_info = isset($invoice_sizes[$invoice_size]) ? $invoice_sizes[$invoice_size] : $invoice_sizes['A4'];
        $orientation = $size_info['orientation'];
        $width = $size_info['width'];
        $height = $size_info['height'];
        $scale = $size_info['scale'];
        $margin = $size_info['margin'];
        $is_thermal = $size_info['is_thermal'] ?? false;
        
        if ($is_thermal) {
            $pdf = new FPDF($orientation, 'mm', array($width, $height));
        } else {
            $pdf = new FPDF($orientation, 'mm', $size_info['name']);
        }
        
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 10 * $scale);
        
        $left_margin = $margin;
        $right_margin = $margin;
        $pdf->SetLeftMargin($left_margin);
        $pdf->SetRightMargin($right_margin);
        
        $center_x = $width / 2;
        $content_width = $width - ($left_margin + $right_margin);
        
        $background_paths = [
            __DIR__ . '/../images/background.jpeg',
            __DIR__ . '/../../images/background.jpeg',
            '../images/background.jpeg',
            '../../images/background.jpeg',
        ];
        foreach ($background_paths as $path) {
            if (file_exists($path)) {
                $pdf->Image($path, 0, 0, $width, $height);
                break;
            }
        }
        
        $header_start = $is_thermal ? $height * 0.03 : $height * 0.08;
        $y_position = $header_start;
        $pdf->SetY($y_position);
        
        $logo_path = getLogoData($company_logo);
        if ($logo_path) {
            $logo_width = $is_thermal ? min(20 * $scale, $content_width * 0.2) : min(40 * $scale, $content_width * 0.25);
            $pdf->Image($logo_path, $center_x - ($logo_width / 2), $y_position, $logo_width);
            $logo_spacing = $is_thermal ? 12 * $scale : 24 * $scale;
            $pdf->SetY($y_position + $logo_width * 0.3 + $logo_spacing);
        } else {
            $pdf->SetY($y_position + ($is_thermal ? 10 : 17) * $scale);
        }
        
        $pdf->SetFont('Arial', 'B', ($is_thermal ? 10 : 14) * $scale);
        $pdf->Cell($content_width, ($is_thermal ? 4 : 6) * $scale, $company_name, 0, 1, 'C');
        $pdf->SetFont('Arial', '', ($is_thermal ? 6 : 8) * $scale);
        
        if (!empty($company_address)) {
            $address_lines = wordwrap($company_address, $is_thermal ? 30 : 60, "\n");
            foreach (explode("\n", $address_lines) as $line) {
                $pdf->Cell($content_width, ($is_thermal ? 2.5 : 4) * $scale, $line, 0, 1, 'C');
            }
        }
        
        $contact_info = '';
        if (!empty($company_phone)) $contact_info .= 'Tel: ' . $company_phone;
        if (!empty($company_email)) $contact_info .= ($contact_info ? ' | ' : '') . 'Email: ' . $company_email;
        if (!empty($contact_info)) {
            $pdf->Cell($content_width, ($is_thermal ? 2.5 : 4) * $scale, $contact_info, 0, 1, 'C');
        }
        
        $pdf->Ln(($is_thermal ? 2 : 5) * $scale);
        
        $pdf->SetFont('Arial', 'B', ($is_thermal ? 10 : 16) * $scale);
        $pdf->SetFillColor(23, 162, 184);
        $pdf->SetTextColor(255, 255, 255);
        $title_height = ($is_thermal ? 6 : 10) * $scale;
        $pdf->Cell($content_width, $title_height, $is_thermal ? 'PAYMENT RECEIPT' : 'PAYMENT RECEIPT', 0, 1, 'C', true);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(($is_thermal ? 1 : 3) * $scale);
        
        $pdf->SetFont('Arial', '', ($is_thermal ? 6 : 9) * $scale);
        $line_height = ($is_thermal ? 3.5 : 6) * $scale;
        $pdf->Cell($content_width * 0.4, $line_height, 'Receipt Number:', 0, 0);
        $pdf->Cell($content_width * 0.6, $line_height, $payment_data['receipt_number'] ?? 'N/A', 0, 1);
        $pdf->Cell($content_width * 0.4, ($is_thermal ? 3 : 5) * $scale, 'Invoice Number:', 0, 0);
        $pdf->Cell($content_width * 0.6, ($is_thermal ? 3 : 5) * $scale, $order_data['order_number'] ?? 'N/A', 0, 1);
        $pdf->Cell($content_width * 0.4, ($is_thermal ? 3 : 5) * $scale, 'Date:', 0, 0);
        $pdf->Cell($content_width * 0.6, ($is_thermal ? 3 : 5) * $scale, date('F j, Y H:i:s'), 0, 1);
        $pdf->Ln(($is_thermal ? 1 : 3) * $scale);
        
        $pdf->SetFont('Arial', 'B', ($is_thermal ? 7 : 10) * $scale);
        $pdf->Cell($content_width, ($is_thermal ? 3.5 : 6) * $scale, 'PAID BY:', 0, 1);
        $pdf->SetFont('Arial', '', ($is_thermal ? 6 : 9) * $scale);
        $line_height = ($is_thermal ? 3 : 5) * $scale;
        $pdf->Cell($content_width, $line_height, $customer_data['name'] ?? 'N/A', 0, 1);
        if (!empty($customer_data['address'])) {
            $address_lines = wordwrap($customer_data['address'], $is_thermal ? 25 : 60, "\n");
            foreach (explode("\n", $address_lines) as $line) {
                $pdf->Cell($content_width, ($is_thermal ? 2.5 : 4) * $scale, $line, 0, 1);
            }
        }
        $pdf->Cell($content_width, $line_height, 'Phone: ' . ($customer_data['phone'] ?? 'N/A'), 0, 1);
        $pdf->Ln(($is_thermal ? 1 : 3) * $scale);
        
        $pdf->SetFont('Arial', 'B', ($is_thermal ? 8 : 11) * $scale);
        $pdf->Cell($content_width * 0.5, ($is_thermal ? 4 : 7) * $scale, 'Payment Method:', 0, 0);
        $pdf->Cell($content_width * 0.5, ($is_thermal ? 4 : 7) * $scale, $payment_data['method'] ?? 'N/A', 0, 1);
        
        $pdf->SetFont('Arial', 'B', ($is_thermal ? 10 : 14) * $scale);
        $pdf->SetTextColor(23, 162, 184);
        $pdf->Cell($content_width * 0.5, ($is_thermal ? 5 : 8) * $scale, 'Amount Paid:', 0, 0);
        $pdf->Cell($content_width * 0.5, ($is_thermal ? 5 : 8) * $scale, $pdf_currency . ' ' . number_format((float)($payment_data['amount'] ?? 0), 2), 0, 1);
        $pdf->SetTextColor(0, 0, 0);
        
        $current_y = $pdf->GetY();
        $footer_needed = ($is_thermal ? 12 : 25) * $scale;
        $space_left = $height - $current_y - ($is_thermal ? 5 : 10) * $scale;
        
        if ($space_left < $footer_needed && !$is_thermal) {
            $pdf->AddPage();
            $pdf->SetY($height - $footer_needed - 10 * $scale);
        }
        
        $pdf->SetFont('Arial', 'I', ($is_thermal ? 5 : 8) * $scale);
        $pdf->SetTextColor(0, 0, 128);
        $pdf->Cell($content_width, ($is_thermal ? 2.5 : 4) * $scale, 'Thank you for your payment!', 0, 1, 'C');
        $pdf->SetFont('Arial', 'I', ($is_thermal ? 4 : 6) * $scale);
        $pdf->Cell($content_width, ($is_thermal ? 2 : 3) * $scale, 'Computer-generated receipt.', 0, 1, 'C');
        
        if ($is_thermal) {
            $pdf->Ln(2 * $scale);
            $pdf->SetFont('Arial', '', 4 * $scale);
            $pdf->SetTextColor(200, 200, 200);
            $pdf->Cell($content_width, 2 * $scale, '---------- CUT HERE ----------', 0, 1, 'C');
            $pdf->SetTextColor(0, 0, 0);
        }
        
        $filename = 'receipt_' . ($payment_data['receipt_number'] ?? 'unknown') . '_' . date('Ymd_His') . '.pdf';
        $filepath = RECEIPTS_DIR . $filename;
        $pdf->Output($filepath, 'F');
        
        if (!file_exists($filepath)) {
            throw new Exception("Failed to create receipt PDF at: " . $filepath);
        }
        
        return [
            'path' => $filepath, 
            'filename' => $filename, 
            'download_url' => getDownloadUrl($filename, 'receipts'),
            'receipt_number' => $payment_data['receipt_number'] ?? 'N/A',
            'success' => true
        ];
    } catch (Exception $e) {
        error_log("Payment Receipt PDF Generation Error: " . $e->getMessage());
        return ['error' => true, 'message' => $e->getMessage()];
    }
}

// ===== RETURN INVOICE PDF =====
function generateReturnInvoicePDF($order_data, $customer_data, $items, $conn, $return_amount = 0) {
    global $fpdf_loaded, $currency, $currency_symbol, $company_name, $company_address, $company_phone, $company_email, $company_tax_number, $tenant_id, $invoice_size, $invoice_sizes, $company_logo;
    
    if (!$order_data || !is_array($order_data)) {
        return ['error' => true, 'message' => 'Invalid order data'];
    }
    
    try {
        if (!$fpdf_loaded) {
            $fpdf_paths = [
                __DIR__ . '/fpdf/fpdf.php',
                __DIR__ . '/../fpdf/fpdf.php',
                '../fpdf/fpdf.php',
            ];
            foreach ($fpdf_paths as $path) {
                if (file_exists($path)) {
                    require_once($path);
                    $fpdf_loaded = true;
                    break;
                }
            }
            if (!$fpdf_loaded) {
                throw new Exception("FPDF library not loaded");
            }
        }
        
        $pdf_currency = getPdfSafeCurrency($currency, $currency_symbol);
        
        $size_info = isset($invoice_sizes[$invoice_size]) ? $invoice_sizes[$invoice_size] : $invoice_sizes['A4'];
        $orientation = $size_info['orientation'];
        $width = $size_info['width'];
        $height = $size_info['height'];
        $scale = $size_info['scale'];
        $margin = $size_info['margin'];
        $is_thermal = $size_info['is_thermal'] ?? false;
        
        if ($is_thermal) {
            $pdf = new FPDF($orientation, 'mm', array($width, $height));
        } else {
            $pdf = new FPDF($orientation, 'mm', $size_info['name']);
        }
        
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 10 * $scale);
        
        $left_margin = $margin;
        $right_margin = $margin;
        $pdf->SetLeftMargin($left_margin);
        $pdf->SetRightMargin($right_margin);
        
        $center_x = $width / 2;
        $content_width = $width - ($left_margin + $right_margin);
        
        $background_paths = [
            __DIR__ . '/../images/background.jpeg',
            __DIR__ . '/../../images/background.jpeg',
            '../images/background.jpeg',
            '../../images/background.jpeg',
        ];
        foreach ($background_paths as $path) {
            if (file_exists($path)) {
                $pdf->Image($path, 0, 0, $width, $height);
                break;
            }
        }
        
        $header_start = $is_thermal ? $height * 0.03 : $height * 0.08;
        $y_position = $header_start;
        $pdf->SetY($y_position);
        
        $logo_path = getLogoData($company_logo);
        if ($logo_path) {
            $logo_width = $is_thermal ? min(20 * $scale, $content_width * 0.2) : min(40 * $scale, $content_width * 0.25);
            $pdf->Image($logo_path, $center_x - ($logo_width / 2), $y_position, $logo_width);
            $logo_spacing = $is_thermal ? 12 * $scale : 24 * $scale;
            $pdf->SetY($y_position + $logo_width * 0.3 + $logo_spacing);
        } else {
            $pdf->SetY($y_position + ($is_thermal ? 10 : 17) * $scale);
        }
        
        $pdf->SetFont('Arial', 'B', ($is_thermal ? 10 : 14) * $scale);
        $pdf->Cell($content_width, ($is_thermal ? 4 : 6) * $scale, $company_name, 0, 1, 'C');
        $pdf->SetFont('Arial', '', ($is_thermal ? 6 : 8) * $scale);
        
        if (!empty($company_address)) {
            $address_lines = wordwrap($company_address, $is_thermal ? 30 : 60, "\n");
            foreach (explode("\n", $address_lines) as $line) {
                $pdf->Cell($content_width, ($is_thermal ? 2.5 : 4) * $scale, $line, 0, 1, 'C');
            }
        }
        
        $contact_info = '';
        if (!empty($company_phone)) $contact_info .= 'Tel: ' . $company_phone;
        if (!empty($company_email)) $contact_info .= ($contact_info ? ' | ' : '') . 'Email: ' . $company_email;
        if (!empty($contact_info)) {
            $pdf->Cell($content_width, ($is_thermal ? 2.5 : 4) * $scale, $contact_info, 0, 1, 'C');
        }
        if (!empty($company_tax_number)) {
            $pdf->SetFont('Arial', 'B', ($is_thermal ? 6 : 8) * $scale);
            $pdf->Cell($content_width, ($is_thermal ? 2.5 : 4) * $scale, 'Tax ID: ' . $company_tax_number, 0, 1, 'C');
        }
        
        $pdf->Ln(($is_thermal ? 2 : 5) * $scale);
        
        $pdf->SetFont('Arial', 'B', ($is_thermal ? 10 : 16) * $scale);
        $pdf->SetFillColor(40, 167, 69);
        $pdf->SetTextColor(255, 255, 255);
        $title_height = ($is_thermal ? 6 : 10) * $scale;
        $pdf->Cell($content_width, $title_height, $is_thermal ? 'RETURN INVOICE' : 'RETURN INVOICE', 0, 1, 'C', true);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(($is_thermal ? 1 : 3) * $scale);
        
        $pdf->SetFont('Arial', '', ($is_thermal ? 6 : 9) * $scale);
        $line_height = ($is_thermal ? 3.5 : 6) * $scale;
        $pdf->Cell($content_width * 0.4, $line_height, 'Return Number:', 0, 0);
        $pdf->Cell($content_width * 0.6, $line_height, $order_data['return_number'] ?? 'N/A', 0, 1);
        $pdf->Cell($content_width * 0.4, ($is_thermal ? 3 : 5) * $scale, 'Original Invoice:', 0, 0);
        $pdf->Cell($content_width * 0.6, ($is_thermal ? 3 : 5) * $scale, $order_data['original_invoice'] ?? 'N/A', 0, 1);
        $pdf->Cell($content_width * 0.4, ($is_thermal ? 3 : 5) * $scale, 'Return Date:', 0, 0);
        $pdf->Cell($content_width * 0.6, ($is_thermal ? 3 : 5) * $scale, date('F j, Y H:i:s'), 0, 1);
        $pdf->Ln(($is_thermal ? 1 : 3) * $scale);
        
        $pdf->SetFont('Arial', 'B', ($is_thermal ? 7 : 10) * $scale);
        $pdf->Cell($content_width, ($is_thermal ? 3.5 : 6) * $scale, 'CUSTOMER:', 0, 1);
        $pdf->SetFont('Arial', '', ($is_thermal ? 6 : 9) * $scale);
        $line_height = ($is_thermal ? 3 : 5) * $scale;
        $pdf->Cell($content_width, $line_height, $customer_data['name'] ?? 'N/A', 0, 1);
        if (!empty($customer_data['address'])) {
            $address_lines = wordwrap($customer_data['address'], $is_thermal ? 25 : 60, "\n");
            foreach (explode("\n", $address_lines) as $line) {
                $pdf->Cell($content_width, ($is_thermal ? 2.5 : 4) * $scale, $line, 0, 1);
            }
        }
        $pdf->Cell($content_width, $line_height, 'Phone: ' . ($customer_data['phone'] ?? 'N/A'), 0, 1);
        if (!empty($customer_data['email'])) {
            $pdf->Cell($content_width, $line_height, 'Email: ' . $customer_data['email'], 0, 1);
        }
        $pdf->Ln(($is_thermal ? 1 : 3) * $scale);
        
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetFont('Arial', 'B', ($is_thermal ? 6 : 9) * $scale);
        $row_height = ($is_thermal ? 5 : 8) * $scale;
        
        $table_width = $content_width * 0.9;
        $table_start_x = ($content_width - $table_width) / 2;
        $pdf->SetX($table_start_x);
        
        if ($is_thermal) {
            $col_widths = [
                'sn' => $table_width * 0.08,
                'code' => $table_width * 0.25,
                'item' => $table_width * 0.30,
                'qty' => $table_width * 0.10,
                'price' => $table_width * 0.13,
                'total' => $table_width * 0.14
            ];
            
            $pdf->Cell($col_widths['sn'], $row_height, '#', 1, 0, 'C', true);
            $pdf->Cell($col_widths['code'], $row_height, 'Code', 1, 0, 'C', true);
            $pdf->Cell($col_widths['item'], $row_height, 'Item', 1, 0, 'C', true);
            $pdf->Cell($col_widths['qty'], $row_height, 'Qty', 1, 0, 'C', true);
            $pdf->Cell($col_widths['price'], $row_height, 'Price', 1, 0, 'C', true);
            $pdf->Cell($col_widths['total'], $row_height, 'Total', 1, 1, 'C', true);
        } else {
            $col_widths = [
                'sn' => $table_width * 0.06,
                'code' => $table_width * 0.20,
                'item' => $table_width * 0.30,
                'qty' => $table_width * 0.10,
                'price' => $table_width * 0.16,
                'total' => $table_width * 0.18
            ];
            
            $pdf->Cell($col_widths['sn'], $row_height, 'S/N', 1, 0, 'C', true);
            $pdf->Cell($col_widths['code'], $row_height, 'Item No.', 1, 0, 'C', true);
            $pdf->Cell($col_widths['item'], $row_height, 'Description', 1, 0, 'C', true);
            $pdf->Cell($col_widths['qty'], $row_height, 'Qty', 1, 0, 'C', true);
            $pdf->Cell($col_widths['price'], $row_height, 'Unit Price', 1, 0, 'C', true);
            $pdf->Cell($col_widths['total'], $row_height, 'Total', 1, 1, 'C', true);
        }
        
        $pdf->SetFont('Arial', '', ($is_thermal ? 5 : 8) * $scale);
        $subtotal = 0;
        $serial = 1;
        $fill = false;
        $row_height = ($is_thermal ? 4 : 7) * $scale;
        
        foreach ($items as $item) {
            $unit_price = (float)($item['unit_price'] ?? 0);
            $quantity = (int)($item['quantity'] ?? 0);
            $line_total = $unit_price * $quantity;
            $subtotal += $line_total;
            
            $pdf->SetX($table_start_x);
            
            if ($is_thermal) {
                $pdf->Cell($col_widths['sn'], $row_height, $serial, 1, 0, 'C', $fill);
                $pdf->Cell($col_widths['code'], $row_height, $item['item_code'] ?? 'N/A', 1, 0, 'C', $fill);
                $product_name = $item['product_name'] ?? 'Unknown';
                $pdf->Cell($col_widths['item'], $row_height, substr($product_name, 0, 12), 1, 0, 'L', $fill);
                $pdf->Cell($col_widths['qty'], $row_height, number_format($quantity), 1, 0, 'C', $fill);
                $pdf->Cell($col_widths['price'], $row_height, number_format($unit_price, 0), 1, 0, 'R', $fill);
                $pdf->Cell($col_widths['total'], $row_height, number_format($line_total, 0), 1, 1, 'R', $fill);
            } else {
                $pdf->Cell($col_widths['sn'], $row_height, $serial, 1, 0, 'C', $fill);
                $pdf->Cell($col_widths['code'], $row_height, $item['item_code'] ?? 'N/A', 1, 0, 'C', $fill);
                $product_name = $item['product_name'] ?? 'Unknown';
                if (strlen($product_name) > 30) $product_name = substr($product_name, 0, 28) . '...';
                $pdf->Cell($col_widths['item'], $row_height, $product_name, 1, 0, 'L', $fill);
                $pdf->Cell($col_widths['qty'], $row_height, number_format($quantity), 1, 0, 'C', $fill);
                $pdf->Cell($col_widths['price'], $row_height, $pdf_currency . ' ' . number_format($unit_price, 2), 1, 0, 'R', $fill);
                $pdf->Cell($col_widths['total'], $row_height, $pdf_currency . ' ' . number_format($line_total, 2), 1, 1, 'R', $fill);
            }
            $fill = !$fill;
            $serial++;
        }
        
        $pdf->Ln(($is_thermal ? 1 : 3) * $scale);
        
        $total = $subtotal;
        
        $pdf->SetFont('Arial', '', ($is_thermal ? 6 : 9) * $scale);
        $line_height = ($is_thermal ? 4 : 6) * $scale;
        
        $total_start_x = $table_start_x + $table_width - ($col_widths['total'] + $col_widths['price']);
        
        $label_width = 30 * $scale;
        $value_width = 35 * $scale;
        $pdf->Cell($total_start_x - $pdf->GetX(), $line_height, '', 0, 0);
        $pdf->Cell($label_width, $line_height, 'Subtotal:', 0, 0, 'R');
        $pdf->Cell($value_width, $line_height, $pdf_currency . ' ' . number_format($subtotal, 2), 0, 1, 'R');
        
        if ($return_amount > 0) {
            $pdf->Cell($total_start_x - $pdf->GetX(), $line_height, '', 0, 0);
            $pdf->Cell($label_width, $line_height, 'Return Amount:', 0, 0, 'R');
            $pdf->Cell($value_width, $line_height, $pdf_currency . ' ' . number_format($return_amount, 2), 0, 1, 'R');
        }
        
        $pdf->SetFont('Arial', 'B', 11 * $scale);
        $pdf->SetTextColor(40, 167, 69);
        $pdf->Cell($total_start_x - $pdf->GetX(), 8 * $scale, '', 0, 0);
        $pdf->Cell($label_width, 8 * $scale, 'TOTAL RETURN:', 0, 0, 'R');
        $pdf->Cell($value_width, 8 * $scale, $pdf_currency . ' ' . number_format($total, 2), 0, 1, 'R');
        $pdf->SetTextColor(0, 0, 0);
        
        $current_y = $pdf->GetY();
        $footer_needed = ($is_thermal ? 12 : 25) * $scale;
        $space_left = $height - $current_y - ($is_thermal ? 5 : 10) * $scale;
        
        if ($space_left < $footer_needed && !$is_thermal) {
            $pdf->AddPage();
            $pdf->SetY($height - $footer_needed - 10 * $scale);
        }
        
        $pdf->SetFont('Arial', 'I', ($is_thermal ? 5 : 8) * $scale);
        $pdf->SetTextColor(0, 0, 128);
        $pdf->Cell($content_width, ($is_thermal ? 2.5 : 4) * $scale, 'Return processed successfully.', 0, 1, 'C');
        $pdf->SetFont('Arial', 'I', ($is_thermal ? 4 : 6) * $scale);
        $pdf->Cell($content_width, ($is_thermal ? 2 : 3) * $scale, 'Computer-generated return invoice.', 0, 1, 'C');
        
        if ($is_thermal) {
            $pdf->Ln(2 * $scale);
            $pdf->SetFont('Arial', '', 4 * $scale);
            $pdf->SetTextColor(200, 200, 200);
            $pdf->Cell($content_width, 2 * $scale, '---------- CUT HERE ----------', 0, 1, 'C');
            $pdf->SetTextColor(0, 0, 0);
        }
        
        $filename = 'return_invoice_' . ($order_data['return_number'] ?? 'unknown') . '_' . date('Ymd_His') . '.pdf';
        $filepath = RETURN_INVOICES_DIR . $filename;
        $pdf->Output($filepath, 'F');
        
        if (!file_exists($filepath)) {
            throw new Exception("Failed to create return PDF at: " . $filepath);
        }
        
        return [
            'path' => $filepath, 
            'filename' => $filename, 
            'download_url' => getDownloadUrl($filename, 'return_invoices'),
            'return_number' => $order_data['return_number'] ?? 'N/A',
            'success' => true
        ];
    } catch (Exception $e) {
        error_log("Return PDF Generation Error: " . $e->getMessage());
        return ['error' => true, 'message' => $e->getMessage()];
    }
}

function getDownloadUrl($filename, $type) {
    return 'download.php?file=' . urlencode($filename) . '&type=' . urlencode($type);
}

function getLogoData($company_logo) {
    $logo_paths = [];
    if (!empty($company_logo)) {
        $logo_paths[] = '../' . $company_logo;
        $logo_paths[] = '../../' . $company_logo;
    }
    $logo_paths[] = '../images/logo.png';
    $logo_paths[] = 'images/logo.png';
    $logo_paths[] = '../../images/logo.png';
    
    foreach ($logo_paths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }
    return null;
}

// ========== PROCESS SALES ORDER CREATION ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_order' && !$return_mode) {
    $submitted_token = $_POST['form_token'] ?? '';
    
    if (empty($submitted_token) || !isset($_SESSION['form_token']) || $submitted_token !== $_SESSION['form_token']) {
        $error_message = "Invalid form submission. Please try again.";
    } else {
        $_SESSION['form_token'] = bin2hex(random_bytes(32));
        
        $customer_id = intval($_POST['customer_id'] ?? 0);
        $items_raw = $_POST['items'] ?? [];
        $notes = trim($_POST['notes'] ?? '');
        $discount_amount = floatval($_POST['discount_amount'] ?? 0);
        $discount_type = $_POST['discount_type'] ?? 'fixed';
        $discount_percentage = floatval($_POST['discount_percentage'] ?? 0);
        $tax_rate_input = floatval($_POST['tax_rate'] ?? 0);
        $apply_discount = isset($_POST['apply_discount']) ? true : false;
        
        if ($customer_id <= 0) {
            $error_message = "Please select a customer";
        } elseif (empty($items_raw)) {
            $error_message = "Please add at least one item to the order";
        } else {
            $conn->begin_transaction();
            
            try {
                $valid_items = [];
                $subtotal = 0;
                
                $items = [];
                foreach ($items_raw as $index => $item) {
                    if (isset($item['store_item_id']) && isset($item['quantity'])) {
                        $items[] = [
                            'store_item_id' => intval($item['store_item_id']),
                            'quantity' => intval($item['quantity'])
                        ];
                    }
                }
                
                foreach ($items as $item) {
                    $store_item_id = $item['store_item_id'];
                    $quantity = $item['quantity'];
                    
                    if ($store_item_id > 0 && $quantity > 0) {
                        $product_query = "SELECT fgs.product_id, fgs.price_per_product, fgs.quantity as available_quantity,
                                          pi.id as item_id, pi.item_code, pi.name as product_name,
                                          pi.s_price as standard_selling_price, pi.pp_price as purchase_price,
                                          pi.selling_unit, pi.is_pack_product, pi.sqpp
                                          FROM finished_goods_store fgs
                                          JOIN product_items pi ON fgs.product_id = pi.id
                                          WHERE fgs.id = ? AND fgs.tenant_id = ?";
                        $stmt_product = $conn->prepare($product_query);
                        $stmt_product->bind_param("ii", $store_item_id, $tenant_id);
                        $stmt_product->execute();
                        $result = $stmt_product->get_result();
                        $product = $result->fetch_assoc();
                        $stmt_product->close();
                        
                        if (!$product) {
                            throw new Exception("Product not found or out of stock");
                        }
                        
                        if ($product['available_quantity'] < $quantity) {
                            throw new Exception("Insufficient stock for product: {$product['product_name']}. Available: {$product['available_quantity']}, Requested: {$quantity}");
                        }
                        
                        $unit_price = floatval($product['price_per_product']);
                        $item_total = $quantity * $unit_price;
                        
                        // FIXED: Get cost price from pp_price (purchase price) first
                        $cost_price = floatval($product['pp_price'] ?? 0);
                        if ($cost_price <= 0) {
                            $cost_price = floatval($product['standard_selling_price'] ?? 0);
                        }
                        if ($cost_price <= 0) {
                            $cost_price = floatval($product['s_price'] ?? 0);
                        }
                        
                        $valid_items[] = [
                            'store_item_id' => $store_item_id,
                            'product_id' => $product['product_id'],
                            'item_code' => $product['item_code'],
                            'quantity' => $quantity,
                            'unit_price' => $unit_price,
                            'cost_price' => $cost_price,
                            'product_name' => $product['product_name'],
                            'selling_unit' => $product['selling_unit'] ?? 'pack',
                            'is_pack_product' => $product['is_pack_product'] ?? 0,
                            'sqpp' => $product['sqpp'] ?? 1
                        ];
                        $subtotal += $item_total;
                    }
                }
                
                if (empty($valid_items)) {
                    throw new Exception("No valid items in the order");
                }
                
                $calculated_discount = 0;
                if ($apply_discount) {
                    if ($discount_type === 'percentage' && $discount_percentage > 0) {
                        $calculated_discount = $subtotal * ($discount_percentage / 100);
                    } else if ($discount_type === 'fixed' && $discount_amount > 0) {
                        $calculated_discount = min($discount_amount, $subtotal);
                    }
                }
                
                $tax_amount = 0;
                $taxable_amount = $subtotal - $calculated_discount;
                if ($tax_rate_input > 0 && $tax_enabled == 'enabled') {
                    $tax_amount = $taxable_amount * ($tax_rate_input / 100);
                }
                
                $total_amount = max(0, $subtotal - $calculated_discount + $tax_amount);
                $order_number = getNextInvoiceNumber($conn, $tenant_id);
                
                $account_query = "SELECT id FROM customer_accounts WHERE customer_id = ? AND tenant_id = ? LIMIT 1";
                $stmt_account = $conn->prepare($account_query);
                $stmt_account->bind_param("ii", $customer_id, $tenant_id);
                $stmt_account->execute();
                $customer_account = $stmt_account->get_result()->fetch_assoc();
                $stmt_account->close();
                
                if (!$customer_account) {
                    $create_account_query = "INSERT INTO customer_accounts (customer_id, tenant_id, account_type, opening_balance, current_balance, currency) VALUES (?, ?, 'primary', 0, 0, ?)";
                    $stmt_create = $conn->prepare($create_account_query);
                    $stmt_create->bind_param("iis", $customer_id, $tenant_id, $currency);
                    $stmt_create->execute();
                    $customer_account_id = $conn->insert_id;
                    $stmt_create->close();
                } else {
                    $customer_account_id = $customer_account['id'];
                }
                
                // FIXED: Use 'pending' instead of 'pending_payment' which doesn't exist in enum
                $order_query = "INSERT INTO orders (
                    customer_id, user_id, total_amount, customer_account_id, 
                    invoice_number, notes, tenant_id, discount_amount, discount_type, discount_percentage,
                    tax_amount, tax_rate,
                    payment_method, payment_made, payment_status, status, order_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 0, 'pending', 'pending', NOW())";
                
                $stmt_order = $conn->prepare($order_query);
                $stmt_order->bind_param(
                    "iidissidsddd",
                    $customer_id,
                    $user_id,
                    $total_amount,
                    $customer_account_id,
                    $order_number,
                    $notes,
                    $tenant_id,
                    $calculated_discount,
                    $discount_type,
                    $discount_percentage,
                    $tax_amount,
                    $tax_rate_input
                );
                $stmt_order->execute();
                $order_id = $conn->insert_id;
                $stmt_order->close();
                
                foreach ($valid_items as $item) {
                    $profit = ($item['unit_price'] - $item['cost_price']) * $item['quantity'];
                    $item_query = "INSERT INTO order_items (order_id, product_id, quantity, price_per_product, cost_price, profit, tenant_id)
                                  VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $stmt_item = $conn->prepare($item_query);
                    $stmt_item->bind_param("iiidddi", $order_id, $item['product_id'], $item['quantity'], 
                        $item['unit_price'], $item['cost_price'], $profit, $tenant_id);
                    $stmt_item->execute();
                    $stmt_item->close();
                    
                    $update_stock_query = "UPDATE finished_goods_store SET quantity = quantity - ? WHERE id = ? AND tenant_id = ?";
                    $stmt_stock = $conn->prepare($update_stock_query);
                    $stmt_stock->bind_param("iii", $item['quantity'], $item['store_item_id'], $tenant_id);
                    $stmt_stock->execute();
                    $stmt_stock->close();
                }
                
                $conn->commit();
                
                $_SESSION['pending_order_id'] = $order_id;
                $_SESSION['pending_order_number'] = $order_number;
                $_SESSION['pending_order_total'] = $total_amount;
                $_SESSION['pending_order_subtotal'] = $subtotal;
                $_SESSION['pending_order_discount'] = $calculated_discount;
                $_SESSION['pending_order_tax'] = $tax_amount;
                $_SESSION['pending_customer_id'] = $customer_id;
                $_SESSION['pending_items'] = $valid_items;
                
                $show_payment_section = true;
                $current_order_id = $order_id;
                $current_invoice_number = $order_number;
                $pending_total = $total_amount;
                $pending_subtotal = $subtotal;
                $pending_discount = $calculated_discount;
                $pending_tax = $tax_amount;
                
                $_SESSION['payment_form_token'] = bin2hex(random_bytes(32));
                $payment_form_token = $_SESSION['payment_form_token'];
                
                $success_message = "Order created successfully! Invoice #{$order_number} - Total: " . fmt_currency($total_amount) . ". Please complete the payment below.";
                
            } catch (Exception $exception) {
                $conn->rollback();
                $error_message = "Error creating order: " . $exception->getMessage();
                error_log("Order Creation Error: " . $exception->getMessage());
            }
        }
    }
}

// ========== PROCESS PAYMENT ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_payment') {
    $submitted_token = $_POST['form_token'] ?? '';
    
    if (empty($submitted_token) || !isset($_SESSION['payment_form_token']) || $submitted_token !== $_SESSION['payment_form_token']) {
        $error_message = "Invalid form submission. Please try again.";
    } else {
        $_SESSION['payment_form_token'] = bin2hex(random_bytes(32));
        
        $order_id = intval($_POST['order_id'] ?? 0);
        $cash_amount = isset($_POST['cash_amount']) ? floatval($_POST['cash_amount']) : 0;
        $payment_made = $cash_amount;
        $payment_channels_breakdown = ['Cash' => $cash_amount];
        
        foreach ($active_payment_channels as $channel) {
            $field_name = 'custom_' . preg_replace('/[^a-zA-Z0-9]/', '_', $channel['name']);
            $amount = isset($_POST[$field_name]) ? floatval($_POST[$field_name]) : 0;
            if ($amount > 0) {
                $payment_made += $amount;
                $payment_channels_breakdown[$channel['name']] = $amount;
            }
        }
        
        $primary_payment_method = 'Cash';
        $primary_amount = $cash_amount;
        foreach ($payment_channels_breakdown as $method => $amount) {
            if ($amount > $primary_amount) {
                $primary_amount = $amount;
                $primary_payment_method = $method;
            }
        }
        
        if ($order_id <= 0) {
            $error_message = "Invalid order ID";
        } elseif ($payment_made <= 0) {
            $error_message = "Please enter at least one payment amount";
        } else {
            $conn->begin_transaction();
            
            try {
                $order_query = "SELECT * FROM orders WHERE id = ? AND tenant_id = ?";
                $stmt_order = $conn->prepare($order_query);
                $stmt_order->bind_param("ii", $order_id, $tenant_id);
                $stmt_order->execute();
                $order = $stmt_order->get_result()->fetch_assoc();
                $stmt_order->close();
                
                if (!$order) {
                    throw new Exception("Order not found");
                }
                
                $total_amount = floatval($order['total_amount']);
                $customer_id = intval($order['customer_id']);
                
                $balance_due = max(0, $total_amount - $payment_made);
                
                $payment_status = ($balance_due <= 0) ? 'full' : 'part';
                $order_status = ($balance_due <= 0) ? 'completed' : 'partially_paid';
                
                $update_query = "UPDATE orders SET payment_made = ?, payment_status = ?, status = ?, payment_method = ? WHERE id = ? AND tenant_id = ?";
                $stmt_update = $conn->prepare($update_query);
                $stmt_update->bind_param("dsssii", $payment_made, $payment_status, $order_status, $primary_payment_method, $order_id, $tenant_id);
                $stmt_update->execute();
                $stmt_update->close();
                
                $customer_query = "SELECT * FROM customers WHERE id = ? AND tenant_id = ?";
                $stmt_customer = $conn->prepare($customer_query);
                $stmt_customer->bind_param("ii", $customer_id, $tenant_id);
                $stmt_customer->execute();
                $customer_data = $stmt_customer->get_result()->fetch_assoc();
                $stmt_customer->close();
                
                $balance_query = "SELECT balance FROM customers WHERE id = ? AND tenant_id = ?";
                $stmt_balance = $conn->prepare($balance_query);
                $stmt_balance->bind_param("ii", $customer_id, $tenant_id);
                $stmt_balance->execute();
                $balance_result = $stmt_balance->get_result();
                $current_balance = 0;
                if ($balance_result && $row = $balance_result->fetch_assoc()) {
                    $current_balance = floatval($row['balance']);
                }
                $stmt_balance->close();
                
                $new_balance = $current_balance + $balance_due;
                
                $update_balance_query = "UPDATE customers SET balance = ? WHERE id = ? AND tenant_id = ?";
                $stmt_update_balance = $conn->prepare($update_balance_query);
                $stmt_update_balance->bind_param("dii", $new_balance, $customer_id, $tenant_id);
                if (!$stmt_update_balance->execute()) {
                    throw new Exception("Failed to update customer balance: " . $stmt_update_balance->error);
                }
                $stmt_update_balance->close();
                
                $account_balance_query = "SELECT current_balance FROM customer_accounts WHERE customer_id = ? AND tenant_id = ?";
                $stmt_acc_balance = $conn->prepare($account_balance_query);
                $stmt_acc_balance->bind_param("ii", $customer_id, $tenant_id);
                $stmt_acc_balance->execute();
                $acc_result = $stmt_acc_balance->get_result();
                $current_acc_balance = 0;
                if ($acc_result && $row = $acc_result->fetch_assoc()) {
                    $current_acc_balance = floatval($row['current_balance']);
                }
                $stmt_acc_balance->close();
                
                $new_acc_balance = $current_acc_balance + $balance_due;
                
                $update_acc_query = "UPDATE customer_accounts SET current_balance = ? WHERE customer_id = ? AND tenant_id = ?";
                $stmt_update_acc = $conn->prepare($update_acc_query);
                $stmt_update_acc->bind_param("dii", $new_acc_balance, $customer_id, $tenant_id);
                if (!$stmt_update_acc->execute()) {
                    throw new Exception("Failed to update customer account balance: " . $stmt_update_acc->error);
                }
                $stmt_update_acc->close();
                
                $reference = 'PAY-' . date('Ymd') . '-' . str_pad((string)mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                $payment_query = "INSERT INTO payments (customer_id, amount_paid, payment_method, payment_date, status, tenant_id, reference) 
                                 VALUES (?, ?, ?, NOW(), ?, ?, ?)";
                $stmt_payment = $conn->prepare($payment_query);
                $stmt_payment->bind_param("idssis", 
                    $customer_id,
                    $payment_made,
                    $primary_payment_method,
                    $payment_status,
                    $tenant_id,
                    $reference
                );
                $stmt_payment->execute();
                $payment_id = $conn->insert_id;
                $stmt_payment->close();
                
                $items_query = "SELECT oi.*, pi.item_code, pi.name as product_name, pi.selling_unit, pi.is_pack_product, pi.sqpp
                               FROM order_items oi 
                               JOIN product_items pi ON oi.product_id = pi.id 
                               WHERE oi.order_id = ? AND oi.tenant_id = ?";
                $stmt_items = $conn->prepare($items_query);
                $stmt_items->bind_param("ii", $order_id, $tenant_id);
                $stmt_items->execute();
                $items_result = $stmt_items->get_result();
                $items_data = [];
                while ($row = $items_result->fetch_assoc()) {
                    $quantity = (int)$row['quantity'];
                    $selling_unit = $row['selling_unit'] ?? 'pack';
                    $pack_quantity = (int)($row['sqpp'] ?? 1);
                    $is_pack_product = (int)($row['is_pack_product'] ?? 0);
                    
                    $display_qty = $quantity;
                    if ($selling_unit == 'unit' && $is_pack_product == 1 && $pack_quantity > 1) {
                        $display_qty = $quantity . ' units';
                    } else {
                        $display_qty = $quantity . ' ' . $selling_unit . 's';
                    }
                    
                    $items_data[] = [
                        'item_code' => $row['item_code'],
                        'product_name' => $row['product_name'],
                        'quantity' => $quantity,
                        'display_quantity' => $display_qty,
                        'unit_price' => $row['price_per_product'],
                        'selling_unit' => $selling_unit,
                        'pack_quantity' => $pack_quantity,
                        'is_pack_product' => $is_pack_product
                    ];
                }
                $stmt_items->close();
                
                $order_data = [
                    'order_number' => $order['invoice_number'],
                    'order_id' => $order_id,
                    'discount_amount' => $order['discount_amount'] ?? 0,
                    'tax_amount' => $order['tax_amount'] ?? 0
                ];
                
                $invoice_info = generateInvoicePDF($order_data, $customer_data, $items_data, $conn, $payment_made, $payment_status);
                $supply_order_info = generateSupplyOrderPDF($order_data, $customer_data, $items_data, $conn);
                
                $payment_data = [
                    'receipt_number' => 'RCP-' . str_pad((string)$payment_id, 6, '0', STR_PAD_LEFT),
                    'amount' => $payment_made,
                    'method' => $primary_payment_method
                ];
                $receipt_info = generatePaymentReceiptPDF($order_data, $customer_data, $payment_data, $conn);
                
                $conn->commit();
                
                unset($_SESSION['pending_order_id']);
                unset($_SESSION['pending_order_number']);
                unset($_SESSION['pending_order_total']);
                unset($_SESSION['pending_order_subtotal']);
                unset($_SESSION['pending_order_discount']);
                unset($_SESSION['pending_order_tax']);
                unset($_SESSION['pending_customer_id']);
                unset($_SESSION['pending_items']);
                
                $show_payment_section = false;
                
                $download_links = '';
                if (isset($invoice_info['success']) && $invoice_info['success']) {
                    $download_links .= '<a href="' . $invoice_info['download_url'] . '" class="btn btn-sm btn-primary mr-2" target="_blank"><i class="fas fa-file-pdf"></i> Invoice</a>';
                }
                if (isset($supply_order_info['success']) && $supply_order_info['success']) {
                    $download_links .= '<a href="' . $supply_order_info['download_url'] . '" class="btn btn-sm btn-success mr-2" target="_blank"><i class="fas fa-file-pdf"></i> Supply Order</a>';
                }
                if (isset($receipt_info['success']) && $receipt_info['success']) {
                    $download_links .= '<a href="' . $receipt_info['download_url'] . '" class="btn btn-sm btn-info mr-2" target="_blank"><i class="fas fa-file-pdf"></i> Receipt</a>';
                }
                
                if ($balance_due > 0) {
                    $success_message = "Payment processed successfully! Invoice #{$order['invoice_number']}. " .
                                      "Customer balance updated from " . fmt_currency($current_balance) . " to " . fmt_currency($new_balance) . 
                                      " (Balance Due: " . fmt_currency($balance_due) . ")<br><br>" . $download_links;
                } else {
                    $success_message = "Payment completed successfully! Invoice #{$order['invoice_number']}. " .
                                      "Customer balance updated from " . fmt_currency($current_balance) . " to " . fmt_currency($new_balance) . 
                                      "<br><br>" . $download_links;
                }
                
            } catch (Exception $exception) {
                $conn->rollback();
                $error_message = "Error processing payment: " . $exception->getMessage();
                error_log("Payment Processing Error: " . $exception->getMessage());
            }
        }
    }
}

// ========== PROCESS RETURN ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_return' && $return_mode) {
    $submitted_token = $_POST['return_token'] ?? '';
    
    if (empty($submitted_token) || !isset($_SESSION['return_form_token']) || $submitted_token !== $_SESSION['return_form_token']) {
        $error_message = "Invalid form submission. Please try again.";
    } else {
        $_SESSION['return_form_token'] = bin2hex(random_bytes(32));
        
        $order_id = intval($_POST['order_id'] ?? 0);
        $order_source = $_POST['order_source'] ?? 'orders';
        $return_items_raw = $_POST['return_items'] ?? [];
        $return_reason = trim($_POST['return_reason'] ?? '');
        $refund_method = $_POST['refund_method'] ?? 'cash';
        $adjust_customer_balance = isset($_POST['adjust_customer_balance']) ? true : false;
        
        if ($order_id <= 0) {
            $error_message = "Invalid order ID";
        } elseif (empty($return_items_raw)) {
            $error_message = "Please select at least one item to return";
        } else {
            $conn->begin_transaction();
            
            try {
                $total_return_amount = 0;
                $customer_id = 0;
                $original_invoice = '';
                $return_number = getNextReturnInvoiceNumber($conn, $tenant_id);
                $return_subtotal = 0;
                
                if ($order_source === 'legacy') {
                    $order_query = "SELECT * FROM legacy_orders WHERE id = ? AND tenant_id = ?";
                    $stmt_order = $conn->prepare($order_query);
                    $stmt_order->bind_param("ii", $order_id, $tenant_id);
                    $stmt_order->execute();
                    $order = $stmt_order->get_result()->fetch_assoc();
                    $stmt_order->close();
                    
                    if (!$order) {
                        throw new Exception("Legacy order not found");
                    }
                    
                    $customer_id = intval($order['customer_id']);
                    $original_invoice = $order['invoice_number'];
                    
                    $return_query = "INSERT INTO product_returns (
                        tenant_id, return_invoice_number, original_order_id, original_order_number,
                        customer_id, user_id, return_date, return_reason, 
                        refund_method, refund_amount, return_subtotal, status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, 0, ?, 'completed', NOW())";
                    
                    $stmt_return = $conn->prepare($return_query);
                    $stmt_return->bind_param(
                        "isisiisssd",
                        $tenant_id,
                        $return_number,
                        $order_id,
                        $original_invoice,
                        $customer_id,
                        $user_id,
                        $return_reason,
                        $refund_method,
                        $return_subtotal
                    );
                    $stmt_return->execute();
                    $return_id = $conn->insert_id;
                    $stmt_return->close();
                    
                    foreach ($return_items_raw as $item_data) {
                        if (isset($item_data['order_item_id']) && isset($item_data['quantity'])) {
                            $legacy_item_id = intval($item_data['order_item_id']);
                            $quantity = intval($item_data['quantity']);
                            
                            if ($legacy_item_id > 0 && $quantity > 0) {
                                $item_query = "SELECT * FROM legacy_order_items WHERE id = ? AND legacy_order_id = ?";
                                $stmt_item = $conn->prepare($item_query);
                                $stmt_item->bind_param("ii", $legacy_item_id, $order_id);
                                $stmt_item->execute();
                                $original_item = $stmt_item->get_result()->fetch_assoc();
                                $stmt_item->close();
                                
                                if (!$original_item) {
                                    throw new Exception("Legacy order item not found");
                                }
                                
                                if ($quantity > $original_item['quantity']) {
                                    throw new Exception("Return quantity cannot exceed original quantity");
                                }
                                
                                $unit_price = floatval($original_item['unit_price'] ?? $original_item['price_per_product'] ?? 0);
                                $item_total = $quantity * $unit_price;
                                $return_subtotal += $item_total;
                                
                                $return_item_query = "INSERT INTO return_items (
                                    tenant_id, return_id, product_id, quantity, unit_price, total_price
                                ) VALUES (?, ?, ?, ?, ?, ?)";
                                
                                $stmt_return_item = $conn->prepare($return_item_query);
                                $stmt_return_item->bind_param(
                                    "iiiddd",
                                    $tenant_id,
                                    $return_id,
                                    $original_item['product_id'],
                                    $quantity,
                                    $unit_price,
                                    $item_total
                                );
                                $stmt_return_item->execute();
                                $stmt_return_item->close();
                                
                                $check_product = $conn->prepare("SELECT id FROM finished_goods_store WHERE product_id = ? AND tenant_id = ?");
                                $check_product->bind_param("ii", $original_item['product_id'], $tenant_id);
                                $check_product->execute();
                                $product_exists = $check_product->get_result()->num_rows > 0;
                                $check_product->close();
                                
                                if ($product_exists) {
                                    $update_stock_query = "UPDATE finished_goods_store 
                                                          SET quantity = quantity + ? 
                                                          WHERE product_id = ? AND tenant_id = ?";
                                    $stmt_stock = $conn->prepare($update_stock_query);
                                    $stmt_stock->bind_param("iii", $quantity, $original_item['product_id'], $tenant_id);
                                    $stmt_stock->execute();
                                    $stmt_stock->close();
                                }
                            }
                        }
                    }
                    
                    $update_return_query = "UPDATE product_returns SET return_subtotal = ?, refund_amount = ? WHERE id = ?";
                    $stmt_update_return = $conn->prepare($update_return_query);
                    $stmt_update_return->bind_param("ddi", $return_subtotal, $return_subtotal, $return_id);
                    $stmt_update_return->execute();
                    $stmt_update_return->close();
                    
                    $total_return_amount = $return_subtotal;
                    
                } else {
                    $order_query = "SELECT * FROM orders WHERE id = ? AND tenant_id = ? AND status IN ('completed', 'partially_paid')";
                    $stmt_order = $conn->prepare($order_query);
                    $stmt_order->bind_param("ii", $order_id, $tenant_id);
                    $stmt_order->execute();
                    $order = $stmt_order->get_result()->fetch_assoc();
                    $stmt_order->close();
                    
                    if (!$order) {
                        throw new Exception("Order not found or not completed");
                    }
                    
                    $customer_id = intval($order['customer_id']);
                    $original_invoice = $order['invoice_number'];
                    
                    $return_query = "INSERT INTO product_returns (
                        tenant_id, return_invoice_number, original_order_id, original_order_number,
                        customer_id, user_id, return_date, return_reason, 
                        refund_method, refund_amount, return_subtotal, status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, 0, ?, 'completed', NOW())";
                    
                    $stmt_return = $conn->prepare($return_query);
                    $stmt_return->bind_param(
                        "isisiisssd",
                        $tenant_id,
                        $return_number,
                        $order_id,
                        $original_invoice,
                        $customer_id,
                        $user_id,
                        $return_reason,
                        $refund_method,
                        $return_subtotal
                    );
                    $stmt_return->execute();
                    $return_id = $conn->insert_id;
                    $stmt_return->close();
                    
                    foreach ($return_items_raw as $item_data) {
                        if (isset($item_data['order_item_id']) && isset($item_data['quantity'])) {
                            $order_item_id = intval($item_data['order_item_id']);
                            $quantity = intval($item_data['quantity']);
                            
                            if ($order_item_id > 0 && $quantity > 0) {
                                $item_query = "SELECT oi.*, pi.item_code, pi.name as product_name, pi.selling_unit
                                              FROM order_items oi
                                              JOIN product_items pi ON oi.product_id = pi.id
                                              WHERE oi.id = ? AND oi.order_id = ? AND oi.tenant_id = ?";
                                $stmt_item = $conn->prepare($item_query);
                                $stmt_item->bind_param("iii", $order_item_id, $order_id, $tenant_id);
                                $stmt_item->execute();
                                $original_item = $stmt_item->get_result()->fetch_assoc();
                                $stmt_item->close();
                                
                                if (!$original_item) {
                                    throw new Exception("Order item not found");
                                }
                                
                                if ($quantity > $original_item['quantity']) {
                                    throw new Exception("Return quantity cannot exceed original quantity");
                                }
                                
                                $unit_price = floatval($original_item['price_per_product']);
                                $item_total = $quantity * $unit_price;
                                $return_subtotal += $item_total;
                                
                                $return_item_query = "INSERT INTO return_items (
                                    tenant_id, return_id, product_id, quantity, unit_price, total_price
                                ) VALUES (?, ?, ?, ?, ?, ?)";
                                
                                $stmt_return_item = $conn->prepare($return_item_query);
                                $stmt_return_item->bind_param(
                                    "iiiddd",
                                    $tenant_id,
                                    $return_id,
                                    $original_item['product_id'],
                                    $quantity,
                                    $unit_price,
                                    $item_total
                                );
                                $stmt_return_item->execute();
                                $stmt_return_item->close();
                                
                                $update_stock_query = "UPDATE finished_goods_store 
                                                      SET quantity = quantity + ? 
                                                      WHERE product_id = ? AND tenant_id = ?";
                                $stmt_stock = $conn->prepare($update_stock_query);
                                $stmt_stock->bind_param("iii", $quantity, $original_item['product_id'], $tenant_id);
                                $stmt_stock->execute();
                                $stmt_stock->close();
                            }
                        }
                    }
                    
                    $update_return_query = "UPDATE product_returns SET return_subtotal = ?, refund_amount = ? WHERE id = ?";
                    $stmt_update_return = $conn->prepare($update_return_query);
                    $stmt_update_return->bind_param("ddi", $return_subtotal, $return_subtotal, $return_id);
                    $stmt_update_return->execute();
                    $stmt_update_return->close();
                    
                    $total_return_amount = $return_subtotal;
                }
                
                if ($adjust_customer_balance && $total_return_amount > 0 && $customer_id > 0) {
                    $balance_query = "SELECT balance FROM customers WHERE id = ? AND tenant_id = ?";
                    $stmt_balance = $conn->prepare($balance_query);
                    $stmt_balance->bind_param("ii", $customer_id, $tenant_id);
                    $stmt_balance->execute();
                    $balance_result = $stmt_balance->get_result();
                    $current_balance = 0;
                    if ($row = $balance_result->fetch_assoc()) {
                        $current_balance = floatval($row['balance']);
                    }
                    $stmt_balance->close();
                    
                    $new_balance = max(0, $current_balance - $total_return_amount);
                    
                    $update_balance_query = "UPDATE customers SET balance = ? WHERE id = ? AND tenant_id = ?";
                    $stmt_update_balance = $conn->prepare($update_balance_query);
                    $stmt_update_balance->bind_param("dii", $new_balance, $customer_id, $tenant_id);
                    $stmt_update_balance->execute();
                    $stmt_update_balance->close();
                    
                    $acc_balance_query = "SELECT current_balance FROM customer_accounts WHERE customer_id = ? AND tenant_id = ?";
                    $stmt_acc_balance = $conn->prepare($acc_balance_query);
                    $stmt_acc_balance->bind_param("ii", $customer_id, $tenant_id);
                    $stmt_acc_balance->execute();
                    $acc_result = $stmt_acc_balance->get_result();
                    $current_acc_balance = 0;
                    if ($row = $acc_result->fetch_assoc()) {
                        $current_acc_balance = floatval($row['current_balance']);
                    }
                    $stmt_acc_balance->close();
                    
                    $new_acc_balance = max(0, $current_acc_balance - $total_return_amount);
                    
                    $update_acc_query = "UPDATE customer_accounts SET current_balance = ? WHERE customer_id = ? AND tenant_id = ?";
                    $stmt_update_acc = $conn->prepare($update_acc_query);
                    $stmt_update_acc->bind_param("dii", $new_acc_balance, $customer_id, $tenant_id);
                    $stmt_update_acc->execute();
                    $stmt_update_acc->close();
                }
                
                $customer_data = null;
                if ($customer_id > 0) {
                    $customer_query = "SELECT * FROM customers WHERE id = ?";
                    $stmt_customer = $conn->prepare($customer_query);
                    $stmt_customer->bind_param("i", $customer_id);
                    $stmt_customer->execute();
                    $customer_data = $stmt_customer->get_result()->fetch_assoc();
                    $stmt_customer->close();
                }
                
                $items_query = "SELECT ri.*, pi.item_code, pi.name as product_name
                               FROM return_items ri
                               JOIN product_items pi ON ri.product_id = pi.id
                               WHERE ri.return_id = ? AND ri.tenant_id = ?";
                $stmt_items = $conn->prepare($items_query);
                $stmt_items->bind_param("ii", $return_id, $tenant_id);
                $stmt_items->execute();
                $items_result = $stmt_items->get_result();
                $items_data = [];
                while ($row = $items_result->fetch_assoc()) {
                    $items_data[] = [
                        'item_code' => $row['item_code'],
                        'product_name' => $row['product_name'],
                        'quantity' => $row['quantity'],
                        'unit_price' => $row['unit_price'],
                        'total_price' => $row['total_price']
                    ];
                }
                $stmt_items->close();
                
                $return_order_data = [
                    'return_number' => $return_number,
                    'original_invoice' => $original_invoice,
                    'order_id' => $order_id
                ];
                
                if ($customer_data) {
                    $return_invoice_info = generateReturnInvoicePDF($return_order_data, $customer_data, $items_data, $conn, $total_return_amount);
                } else {
                    $fallback_customer = ['name' => 'Customer', 'phone' => '', 'email' => '', 'address' => ''];
                    $return_invoice_info = generateReturnInvoicePDF($return_order_data, $fallback_customer, $items_data, $conn, $total_return_amount);
                }
                
                $conn->commit();
                
                $download_links = '';
                if (isset($return_invoice_info['success']) && $return_invoice_info['success']) {
                    $download_links .= '<a href="' . $return_invoice_info['download_url'] . '" class="btn btn-sm btn-success mr-2" target="_blank"><i class="fas fa-file-pdf"></i> Return Invoice</a>';
                }
                
                $source_label = $order_source === 'legacy' ? 'Legacy Order' : 'Order';
                $success_message = "Return processed successfully! Return #{$return_number} for {$source_label} #{$original_invoice}. " .
                                  "Total return amount: " . fmt_currency($total_return_amount) . 
                                  "<br><br>" . $download_links;
                
            } catch (Exception $exception) {
                $conn->rollback();
                $error_message = "Error processing return: " . $exception->getMessage();
                error_log("Return Processing Error: " . $exception->getMessage());
            }
        }
    }
}

// Get data for display
$available_products = getAvailableProducts($conn, $tenant_id);
$active_customers = getCustomers($conn, $tenant_id, $search_customer);

// Check if we have a pending order for payment
if (isset($_SESSION['pending_order_id']) && $_SESSION['pending_order_id'] > 0) {
    $show_payment_section = true;
    $current_order_id = $_SESSION['pending_order_id'];
    $current_invoice_number = $_SESSION['pending_order_number'] ?? '';
    $pending_total = $_SESSION['pending_order_total'] ?? 0;
    $pending_subtotal = $_SESSION['pending_order_subtotal'] ?? 0;
    $pending_discount = $_SESSION['pending_order_discount'] ?? 0;
    $pending_tax = $_SESSION['pending_order_tax'] ?? 0;
    $_SESSION['payment_form_token'] = bin2hex(random_bytes(32));
    $payment_form_token = $_SESSION['payment_form_token'];
}

// ========== MENU ITEMS FOR SIDEBAR ==========
$menu_items = [
    ['icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard', 'link' => "dashboard_{$product_category}.php"],
    ['icon' => 'fas fa-boxes', 'label' => 'Product Management', 'link' => 'warehouse_transfer.php'],
    ['icon' => 'fas fa-users', 'label' => 'Customer Management', 'link' => 'customer_management.php'],
    ['icon' => 'fas fa-money-bill', 'label' => 'Payments/Receipts', 'link' => 'record_payment.php'],
    ['icon' => 'fas fa-shopping-cart', 'label' => 'New Sales/Returns', 'link' => 'new_order.php', 'active' => true],
    ['icon' => 'fas fa-user-circle', 'label' => 'Profile', 'link' => 'profile.php'],
    ['icon' => 'fas fa-cog', 'label' => 'Settings', 'link' => 'settings.php']
];

$selected_customer = null;
if ($selected_customer_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM customers WHERE id = ? AND tenant_id = ?");
    $stmt->bind_param("ii", $selected_customer_id, $tenant_id);
    $stmt->execute();
    $selected_customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$page_title = $return_mode ? 'Process Return' : 'Create New Sales Order';
$page_icon = $return_mode ? 'fa-undo' : 'fa-shopping-cart';

// ==================================================================
// THE HTML BELOW IS THE COMPLETE PAGE - PAYMENT SECTION INCLUDED
// ==================================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo $page_title; ?> - <?php echo htmlspecialchars($company_name); ?></title>

    <!-- ===== ONLINE CDN ASSETS (Primary - works on any connection) ===== -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- ===== CDN JAVASCRIPT LIBRARIES ===== -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; overflow-x: hidden; }
        
        body::before { content: ''; position: fixed; width: 300px; height: 300px; background: rgba(255,255,255,0.1); border-radius: 50%; top: -150px; right: -150px; animation: float 20s infinite; z-index: 0; }
        body::after { content: ''; position: fixed; width: 500px; height: 500px; background: rgba(255,255,255,0.05); border-radius: 50%; bottom: -250px; left: -250px; animation: float 15s infinite reverse; z-index: 0; }
        @keyframes float { 0%, 100% { transform: translate(0, 0) rotate(0deg); } 50% { transform: translate(30px, 30px) rotate(180deg); } }
        
        .sidebar { position: fixed; left: 0; top: 0; height: 100vh; width: 280px; background: rgba(26, 26, 46, 0.95); backdrop-filter: blur(10px); color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto; }
        .sidebar-header { padding: 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-menu { list-style: none; padding: 0; margin: 0; }
        .nav-item { margin: 5px 15px; border-radius: 10px; }
        .nav-item:hover { background: rgba(102,126,234,0.3); }
        .nav-item.active { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .nav-link { display: flex; align-items: center; padding: 12px 15px; color: rgba(255,255,255,0.8); text-decoration: none; }
        .nav-link i { width: 25px; margin-right: 10px; }
        
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; position: relative; z-index: 1; }
        .top-navbar { background: rgba(255,255,255,0.98); border-radius: 15px; padding: 12px 25px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .toggle-sidebar { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; width: 40px; height: 40px; border-radius: 10px; color: white; cursor: pointer; display: none; }
        .logout-btn { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 8px 20px; border-radius: 10px; text-decoration: none; }
        
        .container { max-width: 1400px; margin: 0 auto; background: rgba(255,255,255,0.98); padding: 25px; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.15); position: relative; z-index: 1; }
        
        h1 { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); -webkit-background-clip: text; background-clip: text; color: transparent; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 3px solid #667eea; display: flex; align-items: center; gap: 10px; }
        
        .dashboard-nav { background: linear-gradient(135deg, #f0f4ff, #e8ecf8); padding: 15px 20px; border-radius: 15px; margin-bottom: 25px; border-left: 5px solid #667eea; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        
        .btn { padding: 10px 20px; border: none; border-radius: 10px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.3s ease; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(102,126,234,0.4); }
        .btn-success { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; }
        .btn-success:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(40,167,69,0.4); }
        .btn-danger { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; }
        .btn-danger:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(220,53,69,0.4); }
        .btn-info { background: linear-gradient(135deg, #17a2b8 0%, #138496 100%); color: white; }
        .btn-warning { background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%); color: white; }
        .btn-secondary { background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%); color: white; }
        
        .action-tabs { display: flex; gap: 12px; margin-bottom: 25px; background: rgba(255,255,255,0.9); padding: 12px; border-radius: 15px; backdrop-filter: blur(5px); }
        .action-tab { flex: 1; padding: 14px; text-align: center; background: white; border: 2px solid #e0e0e0; border-radius: 12px; cursor: pointer; font-weight: 600; transition: all 0.3s ease; }
        .action-tab.sales.active { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-color: #667eea; }
        .action-tab.return.active { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; border-color: #28a745; }
        
        .search-section { background: linear-gradient(135deg, #f0f4ff, #e8ecf8); padding: 20px; border-radius: 15px; margin-bottom: 25px; border-left: 5px solid #667eea; }
        .search-section h3 { color: #2c3e50; margin-bottom: 15px; display: flex; align-items: center; gap: 10px; }
        
        .autocomplete-container { position: relative; width: 100%; }
        .autocomplete-input { width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 10px; font-size: 14px; transition: all 0.3s; background: white; }
        .autocomplete-input:focus { border-color: #667eea; outline: none; box-shadow: 0 0 0 3px rgba(102,126,234,0.2); }
        .autocomplete-dropdown { position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #e0e0e0; border-top: none; border-radius: 0 0 10px 10px; max-height: 300px; overflow-y: auto; z-index: 1000; display: none; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .autocomplete-item { padding: 12px 15px; cursor: pointer; border-bottom: 1px solid #f0f0f0; transition: background 0.2s; }
        .autocomplete-item:hover { background: #f0f4ff; }
        .autocomplete-item.selected { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        
        .customer-select { width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 10px; font-size: 14px; background: white; transition: all 0.3s; }
        .customer-select:focus { border-color: #667eea; outline: none; box-shadow: 0 0 0 3px rgba(102,126,234,0.2); }
        .customer-info-card { background: #e7f3ff; border: 1px solid #b8daff; border-radius: 12px; padding: 15px; margin-top: 15px; }
        
        .alert { padding: 15px 20px; margin-bottom: 25px; border-radius: 12px; border-left: 5px solid; animation: slideIn 0.3s ease; }
        @keyframes slideIn { from { transform: translateY(-10px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .alert-success { background: #d4edda; color: #155724; border-color: #28a745; }
        .alert-danger { background: #f8d7da; color: #721c24; border-color: #dc3545; }
        .alert-info { background: #d1ecf1; color: #0c5460; border-color: #17a2b8; }
        .alert-warning { background: #fff3cd; color: #856404; border-color: #ffc107; }
        
        .trial-warning { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; }
        .subscription-warning { background: #fee2e2; border-left: 4px solid #ef4444; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; }
        
        .form-control { border-radius: 10px; border: 1px solid #dee2e6; padding: 10px 15px; width: 100%; }
        .form-control:focus { border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.25); }
        
        .product-card { background: white; border-radius: 16px; padding: 25px; margin: 25px 0; border-left: 5px solid #667eea; box-shadow: 0 5px 20px rgba(0,0,0,0.08); }
        .product-card.return { border-left-color: #28a745; }
        
        .payment-channels-section { background: linear-gradient(135deg, #f0f4ff, #e8ecf8); padding: 25px; border-radius: 16px; margin: 25px 0; border-left: 5px solid #667eea; }
        .payment-channels-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
        .payment-channel-card { background: white; border-radius: 12px; padding: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-left: 3px solid #667eea; }
        .payment-channel-card h3 { font-size: 16px; font-weight: 600; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
        .payment-channel-card .cash-icon { color: #28a745; }
        .payment-channel-card .bank-icon { color: #6c5ce7; }
        .payment-channel-card .pos-icon { color: #e74c3c; }
        .payment-channel-card input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; }
        
        .total-display { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 16px; padding: 20px; text-align: center; margin-top: 20px; }
        .total-display h3 { font-size: 32px; font-weight: bold; margin: 0; }
        
        .discount-section { background: #fef3c7; border-radius: 16px; padding: 25px; margin: 25px 0; border-left: 5px solid #f59e0b; }
        
        .order-summary { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); color: white; padding: 25px; border-radius: 20px; margin: 25px 0; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .summary-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .summary-total { font-size: 24px; font-weight: bold; color: #2ecc71; border-top: 2px solid #667eea; margin-top: 15px; padding-top: 15px; }
        
        .payment-section { 
            background: linear-gradient(135deg, #f0f4ff, #e8ecf8); 
            padding: 25px; 
            border-radius: 16px; 
            margin: 25px 0; 
            border-left: 5px solid #28a745; 
        }
        .payment-section h3 { color: #28a745; }
        .order-total-display { 
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%); 
            color: white; 
            border-radius: 16px; 
            padding: 15px 25px; 
            text-align: center; 
            margin-bottom: 20px; 
        }
        
        .cart-item { background: white; padding: 15px; margin: 12px 0; border-radius: 12px; border-left: 4px solid #667eea; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .cart-item.return { border-left-color: #28a745; }
        
        .quantity-controls { display: flex; align-items: center; gap: 10px; }
        .qty-btn { width: 35px; height: 35px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 8px; cursor: pointer; }
        .qty-btn.return { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); }
        .quantity-input { width: 80px; text-align: center; padding: 8px; border: 2px solid #ddd; border-radius: 8px; }
        
        .product-table { width: 100%; border-collapse: collapse; }
        .product-table th { background: #f8f9fa; padding: 12px; text-align: left; font-weight: 600; border-bottom: 2px solid #e9ecef; }
        .product-table td { padding: 10px 12px; border-bottom: 1px solid #e9ecef; }
        .product-table tr:hover { background: #f8f9fa; }
        
        .modal-content { border-radius: 16px; overflow: hidden; }
        .modal-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; }
        .modal-header.return { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); }
        
        .required::after { content: " *"; color: #dc3545; }
        .invoice-size-badge { background: #f0f0f0; padding: 4px 8px; border-radius: 6px; font-size: 11px; margin-left: 8px; }
        
        .auto-clear-input:focus { border-color: #667eea; outline: none; box-shadow: 0 0 0 3px rgba(102,126,234,0.2); }

        .input-tabs {
            display: flex;
            gap: 10px;
            margin: 15px 0 0 0;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 10px;
            flex-wrap: wrap;
        }
        .input-tab {
            padding: 10px 25px;
            border-radius: 10px 10px 0 0;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            background: transparent;
            border: none;
            color: #6c757d;
        }
        .input-tab.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .input-tab:hover:not(.active) {
            background: #f8f9fa;
        }
        .input-tab i {
            margin-right: 8px;
        }
        
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        
        .voice-input-section {
            background: linear-gradient(135deg, #f8f0ff, #ede7f6);
            padding: 25px;
            border-radius: 16px;
            margin: 25px 0;
            border-left: 5px solid #6c5ce7;
        }
        .voice-status {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: white;
            border-radius: 12px;
            margin-top: 15px;
        }
        .voice-status .status-indicator {
            width: 15px;
            height: 15px;
            border-radius: 50%;
            background: #b2bec3;
            transition: all 0.3s ease;
        }
        .voice-status .status-indicator.listening {
            background: #e74c3c;
            animation: pulse-dot 1s ease-in-out infinite;
        }
        .voice-status .status-indicator.processing {
            background: #fdcb6e;
            animation: pulse-dot 0.8s ease-in-out infinite;
        }
        .voice-status .status-indicator.success {
            background: #00b894;
        }
        @keyframes pulse-dot {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.5); opacity: 0.7; }
        }
        .voice-transcript {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-top: 10px;
            min-height: 60px;
            border: 1px dashed #dee2e6;
            font-size: 16px;
        }
        .voice-transcript .highlight {
            color: #6c5ce7;
            font-weight: 600;
        }
        .voice-suggestions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        .voice-suggestions .suggestion-item {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 20px;
            padding: 5px 15px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.3s ease;
        }
        .voice-suggestions .suggestion-item:hover {
            background: #6c5ce7;
            color: white;
            border-color: #6c5ce7;
        }
        .btn-voice {
            background: linear-gradient(135deg, #6c5ce7 0%, #a29bfe 100%);
            color: white;
        }
        .btn-voice:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(108,92,231,0.4);
            color: white;
        }
        .btn-voice.listening {
            background: linear-gradient(135deg, #e74c3c 0%, #fd79a8 100%);
            animation: pulse-voice 1.5s ease-in-out infinite;
        }
        @keyframes pulse-voice {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .scanner-container {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 15px;
            margin: 15px 0 25px 0;
            border: 2px dashed #667eea;
            position: relative;
        }
        .scanner-container video {
            width: 100%;
            max-width: 400px;
            border-radius: 8px;
            background: #000;
        }
        .scanner-controls {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            margin-top: 10px;
        }
        .scanner-controls .btn {
            padding: 6px 15px;
            font-size: 13px;
        }
        .close-scanner-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            font-size: 16px;
            cursor: pointer;
            z-index: 10;
            display: none;
        }
        .close-scanner-btn:hover { background: #c82333; }
        
        #scanner_result {
            margin-top: 10px;
            padding: 10px;
            background: white;
            border-radius: 8px;
            border-left: 4px solid #28a745;
            display: none;
        }
        #scanner_result .product-found {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        #scanner_result .product-details strong {
            color: #667eea;
        }
        .scan-status {
            padding: 8px 15px;
            border-radius: 8px;
            margin-top: 10px;
            font-size: 13px;
            display: none;
        }
        .scan-status.success { background: #d4edda; color: #155724; display: block; }
        .scan-status.error { background: #f8d7da; color: #721c24; display: block; }
        .scan-status.info { background: #d1ecf1; color: #0c5460; display: block; }
        .scan-status.warning { background: #fff3cd; color: #856404; display: block; }
        
        .offline-banner {
            position: fixed;
            bottom: 10px;
            left: 50%;
            transform: translateX(-50%);
            background: #ffc107;
            color: #333;
            padding: 6px 18px;
            border-radius: 20px;
            font-size: 11px;
            z-index: 9999;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            font-weight: 600;
            display: none;
        }
        .offline-banner i { margin-right: 6px; }
        
        .btn-refresh {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-refresh:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(23,162,184,0.4);
            color: white;
            text-decoration: none;
        }
        
        .return-section {
            background: linear-gradient(135deg, #f0fff4, #e8f5e9);
            padding: 25px;
            border-radius: 16px;
            margin: 25px 0;
            border-left: 5px solid #28a745;
        }
        .return-section h3 { color: #28a745; }
        .return-item { 
            background: white; 
            padding: 15px; 
            margin: 12px 0; 
            border-radius: 12px; 
            border-left: 4px solid #28a745; 
            box-shadow: 0 2px 8px rgba(0,0,0,0.08); 
        }
        
        /* Mic permission guide */
        .mic-permission-guide {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 10px;
            padding: 12px 18px;
            margin: 12px 0;
            font-size: 14px;
            display: none;
        }
        .mic-permission-guide strong {
            color: #856404;
        }
        .mic-permission-guide .step {
            display: inline-block;
            background: #856404;
            color: white;
            border-radius: 50%;
            width: 22px;
            height: 22px;
            text-align: center;
            line-height: 22px;
            font-size: 12px;
            margin-right: 6px;
        }
        
        @media (max-width: 992px) {
            .sidebar { left: -280px; }
            .sidebar.active { left: 0; }
            .main-content { margin-left: 0; }
            .toggle-sidebar { display: block; }
        }
        @media (max-width: 768px) {
            .container { padding: 15px; }
            .scanner-container video { max-width: 100%; }
            .product-table { font-size: 12px; display: block; overflow-x: auto; }
            .action-tabs { flex-direction: column; }
            .payment-channels-grid { grid-template-columns: 1fr; }
            .input-tabs { flex-direction: column; }
            .input-tab { text-align: center; }
        }
    </style>
</head>
<body>
    <div class="offline-banner" id="offlineBanner">
        <i class="fas fa-wifi"></i> Offline Mode - All features work without internet
    </div>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header"><h5><?php echo strtoupper($product_category); ?> MANAGEMENT</h5></div>
        <ul class="nav-menu">
            <?php foreach ($menu_items as $item): ?>
            <li class="nav-item <?php echo isset($item['active']) && $item['active'] ? 'active' : ''; ?>">
                <a class="nav-link" href="<?php echo $item['link']; ?>"><i class="<?php echo $item['icon']; ?>"></i> <?php echo $item['label']; ?></a>
            </li>
            <?php endforeach; ?>
            <li class="nav-item">
                <a class="nav-link" href="orders.php"><i class="fas fa-print"></i> Reprint Invoices</a>
            </li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-navbar">
            <button class="toggle-sidebar" id="toggleSidebarBtn"><i class="fas fa-bars"></i></button>
            <div>
                <span class="mr-3"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($company_name); ?></span>
                <span class="mr-3 small text-muted"><?php echo $currency_symbol; ?> | <?php echo $product_category; ?> <span class="invoice-size-badge"><i class="fas fa-print"></i> <?php echo $current_size['name']; ?></span></span>
                <?php if ($subscription_status == 'trial'): ?>
                    <span class="badge badge-warning mr-2">Trial: <?php echo $days_left_trial; ?> days left</span>
                <?php elseif ($subscription_status == 'active'): ?>
                    <span class="badge badge-success mr-2">Active</span>
                <?php endif; ?>
                <a href="#" onclick="refreshPage()" class="btn-refresh mr-2">
                    <i class="fas fa-sync-alt"></i> New Order
                </a>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>

        <div class="container">
            <h1><i class="fas <?php echo $page_icon; ?>"></i> <?php echo $page_title; ?></h1>

            <div class="dashboard-nav">
                <div>
                    <i class="fas fa-file-invoice"></i> <strong><?php echo $return_mode ? 'Return Management' : 'Sales Order Management'; ?></strong>
                </div>
                <div>
                    <?php if (!$return_mode): ?>
                    <a href="?return_mode=sales" class="btn btn-success">
                        <i class="fas fa-undo"></i> Process Return
                    </a>
                    <?php else: ?>
                    <a href="new_order.php" class="btn btn-primary">
                        <i class="fas fa-shopping-cart"></i> New Sales Order
                    </a>
                    <?php endif; ?>
                    <a href="orders.php" class="btn btn-danger">
                        <i class="fas fa-print"></i> Reprint Invoices / View Orders
                    </a>
                </div>
            </div>

            <div class="action-tabs">
                <div class="action-tab sales <?php echo !$return_mode ? 'active' : ''; ?>" onclick="window.location.href='new_order.php'">
                    <i class="fas fa-shopping-cart"></i> New Sales Order
                </div>
                <div class="action-tab return <?php echo $return_mode ? 'active' : ''; ?>" onclick="window.location.href='?return_mode=sales'">
                    <i class="fas fa-undo"></i> Process Return
                </div>
            </div>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success">
                    <?php echo $success_message; ?>
                    <div class="mt-3">
                        <button onclick="refreshPage()" class="btn btn-warning"><i class="fas fa-plus"></i> <?php echo $return_mode ? 'New Return' : 'New Order'; ?></button>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($show_payment_section && $current_order_id > 0 && !$return_mode): ?>
            <div class="payment-section">
                <h3><i class="fas fa-credit-card"></i> Complete Payment for Order #<?php echo htmlspecialchars($current_invoice_number); ?></h3>
                
                <div class="order-total-display">
                    <h4>Order Summary</h4>
                    <div class="row">
                        <div class="col-6 text-left">Subtotal:</div>
                        <div class="col-6 text-right"><?php echo fmt_currency($pending_subtotal ?? 0); ?></div>
                    </div>
                    <?php if ($pending_discount > 0): ?>
                    <div class="row">
                        <div class="col-6 text-left">Discount:</div>
                        <div class="col-6 text-right">-<?php echo fmt_currency($pending_discount); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($pending_tax > 0): ?>
                    <div class="row">
                        <div class="col-6 text-left">Tax:</div>
                        <div class="col-6 text-right"><?php echo fmt_currency($pending_tax); ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="row" style="border-top: 2px solid white; padding-top: 10px; margin-top: 10px;">
                        <div class="col-6 text-left"><strong>Total:</strong></div>
                        <div class="col-6 text-right"><strong><?php echo fmt_currency($pending_total ?? 0); ?></strong></div>
                    </div>
                    <p class="mb-0 mt-2"><small>Enter payment amount below to complete the transaction</small></p>
                </div>
                
                <form method="POST" action="" id="paymentForm">
                    <input type="hidden" name="form_token" value="<?php echo $payment_form_token; ?>">
                    <input type="hidden" name="action" value="process_payment">
                    <input type="hidden" name="order_id" value="<?php echo $current_order_id; ?>">
                    
                    <div class="payment-channels-grid">
                        <div class="payment-channel-card">
                            <h3><i class="fas fa-money-bill-wave cash-icon"></i> Cash Payment</h3>
                            <input type="number" step="0.01" min="0" name="cash_amount" id="payment_cash_amount" class="form-control auto-clear-input" value="0" data-default="0" oninput="updatePaymentTotal()">
                            <small>Enter cash amount received</small>
                        </div>
                        <?php foreach ($active_payment_channels as $channel): 
                            $field_name = 'custom_' . preg_replace('/[^a-zA-Z0-9]/', '_', $channel['name']);
                            $channel_name = $channel['name'] ?? 'Custom Payment';
                            $channel_icon = 'fa-credit-card';
                            
                            if (isset($channel['type'])) {
                                if ($channel['type'] == 'bank_transfer') $channel_icon = 'fa-university';
                                elseif ($channel['type'] == 'mobile_money') $channel_icon = 'fa-mobile-alt';
                                elseif ($channel['type'] == 'card') $channel_icon = 'fa-credit-card';
                                elseif ($channel['type'] == 'pos') $channel_icon = 'fa-credit-card';
                                else $channel_icon = 'fa-credit-card';
                            } elseif (strpos(strtolower($channel_name), 'bank') !== false || strpos(strtolower($channel_name), 'transfer') !== false) {
                                $channel_icon = 'fa-university';
                            } elseif (strpos(strtolower($channel_name), 'mobile') !== false || strpos(strtolower($channel_name), 'momo') !== false) {
                                $channel_icon = 'fa-mobile-alt';
                            } elseif (strpos(strtolower($channel_name), 'pos') !== false) {
                                $channel_icon = 'fa-credit-card';
                            }
                            
                            $icon_color = 'bank-icon';
                            if (strpos($channel_icon, 'university') !== false) $icon_color = 'bank-icon';
                            elseif (strpos($channel_icon, 'mobile') !== false) $icon_color = 'pos-icon';
                            else $icon_color = 'bank-icon';
                        ?>
                        <div class="payment-channel-card">
                            <h3><i class="fas <?php echo $channel_icon; ?> <?php echo $icon_color; ?>"></i> <?php echo htmlspecialchars($channel_name); ?></h3>
                            <input type="number" step="0.01" min="0" name="<?php echo $field_name; ?>" id="<?php echo $field_name; ?>" class="form-control auto-clear-input" value="0" data-default="0" oninput="updatePaymentTotal()">
                            <small>Enter <?php echo htmlspecialchars($channel_name); ?> amount</small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="row mt-4">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <h5>Payment Summary</h5>
                                    <div class="row">
                                        <div class="col-6"><strong>Total Payment:</strong></div>
                                        <div class="col-6 text-right" id="paymentTotalDisplay"><?php echo $currency_symbol; ?> 0.00</div>
                                    </div>
                                    <div class="row">
                                        <div class="col-6"><strong>Order Total:</strong></div>
                                        <div class="col-6 text-right"><?php echo fmt_currency($pending_total ?? 0); ?></div>
                                    </div>
                                    <div class="row mt-2" style="border-top: 2px solid #28a745; padding-top: 10px;">
                                        <div class="col-6"><strong>Balance Due:</strong></div>
                                        <div class="col-6 text-right" id="balanceDueDisplay" style="color: #dc3545; font-weight: bold;"><?php echo fmt_currency($pending_total ?? 0); ?></div>
                                    </div>
                                    <input type="hidden" name="total_payment_amount" id="total_payment_amount" value="0">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 text-center" style="display: flex; align-items: center; justify-content: center;">
                            <div>
                                <button type="submit" class="btn btn-success btn-lg" id="paymentSubmitBtn">
                                    <i class="fas fa-check"></i> COMPLETE PAYMENT
                                </button>
                                <br>
                                <small class="text-muted">Payment will be applied to invoice #<?php echo htmlspecialchars($current_invoice_number); ?></small>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <?php if (!$show_payment_section || !$current_order_id): ?>
            
            <?php if (!$return_mode): ?>
            
            <div class="search-section">
                <h3>Search & Select Customer</h3>
                <div class="form-group">
                    <label>Search Customer (Name, Email or Phone)</label>
                    <div class="autocomplete-container">
                        <input type="text" id="customerSearch" class="autocomplete-input" placeholder="Type to search..." autocomplete="off">
                        <div id="autocompleteDropdown" class="autocomplete-dropdown"></div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="required">Select Customer</label>
                    <select id="customer_id" name="customer_id" class="customer-select" required>
                        <option value="">-- Select a Customer --</option>
                        <?php foreach ($active_customers as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>" data-name="<?php echo htmlspecialchars($customer['name']); ?>" data-phone="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>" data-email="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>" data-address="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>" data-balance="<?php echo $customer['account_balance']; ?>">
                            <?php echo htmlspecialchars($customer['name']); ?> - <?php echo fmt_currency($customer['account_balance']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="customerInfo" class="customer-info-card" style="display:none">
                    <h6><span id="displayName"></span></h6>
                    <div class="row">
                        <div class="col-md-3"><small>Phone: <span id="displayPhone"></span></small></div>
                        <div class="col-md-3"><small>Email: <span id="displayEmail"></span></small></div>
                        <div class="col-md-3"><small>Address: <span id="displayAddress"></span></small></div>
                        <div class="col-md-3"><small>Balance: <strong id="displayBalance"></strong></small></div>
                    </div>
                </div>
            </div>

            <div class="input-tabs" id="inputTabs">
                <button class="input-tab active" data-tab="scanner-tab" onclick="switchTab('scanner-tab')">
                    <i class="fas fa-qrcode"></i> Barcode Scanner
                </button>
                <button class="input-tab" data-tab="voice-tab" onclick="switchTab('voice-tab')">
                    <i class="fas fa-microphone"></i> Voice Input
                </button>
                <button class="input-tab" data-tab="manual-tab" onclick="switchTab('manual-tab')">
                    <i class="fas fa-keyboard"></i> Manual Search
                </button>
            </div>

            <!-- Mic Permission Guide -->
            <div class="mic-permission-guide" id="micPermissionGuide">
                <strong><i class="fas fa-microphone-slash"></i> Microphone Access Required</strong><br>
                <span style="font-size:13px;">To use voice input, please allow microphone access:</span>
                <div style="margin-top:6px; font-size:13px;">
                    <span class="step">1</span> Click the <strong>lock icon</strong> <i class="fas fa-lock" style="font-size:14px;"></i> in your browser address bar<br>
                    <span class="step">2</span> Find <strong>Microphone</strong> and select <strong>Allow</strong><br>
                    <span class="step">3</span> Refresh the page and try again
                </div>
                <button class="btn btn-sm btn-warning mt-2" onclick="document.getElementById('micPermissionGuide').style.display='none'">
                    <i class="fas fa-times"></i> Dismiss
                </button>
            </div>

            <div id="scanner-tab" class="tab-content active">
                <div class="scanner-container" id="scannerContainer">
                    <button class="close-scanner-btn" id="closeScannerBtn" onclick="stopScanner()"><i class="fas fa-times"></i></button>
                    <h6><i class="fas fa-qrcode mr-2"></i> Scan QR Code or Barcode to Add Products</h6>
                    <p class="text-muted small">Use your device camera to scan product QR codes or barcodes - <strong>Auto-detects and adds to cart</strong></p>
                    <div id="scannerReader" style="width:100%; max-width:400px;"></div>
                    <div class="form-check" style="margin: 6px 0;">
                        <input type="checkbox" class="form-check-input" id="scannerMirroredCheckbox">
                        <label class="form-check-label small text-muted" for="scannerMirroredCheckbox">My camera image is mirrored/inverted (check to auto-correct)</label>
                    </div>
                    <div class="scanner-controls">
                        <button class="btn btn-primary" id="startScannerBtn" onclick="startScanner()">
                            <i class="fas fa-camera"></i> Start Scanner
                        </button>
                        <button class="btn btn-danger" id="stopScannerBtn" onclick="stopScanner()" style="display:none;">
                            <i class="fas fa-stop"></i> Stop Scanner
                        </button>
                    </div>
                    <div id="scanner_result">
                        <div class="product-found">
                            <div class="product-details">
                                <strong id="scannedProductName"></strong><br>
                                <span id="scannedProductCode"></span><br>
                                <span id="scannedProductStock"></span>
                            </div>
                            <button class="btn btn-success btn-sm" onclick="addScannedProductToOrder()">
                                <i class="fas fa-cart-plus"></i> Add to Cart
                            </button>
                        </div>
                    </div>
                    <div id="scanStatus" class="scan-status"></div>
                </div>
            </div>

            <div id="voice-tab" class="tab-content">
                <div class="voice-input-section">
                    <h5><i class="fas fa-microphone-alt" style="color: #6c5ce7;"></i> Voice Product Search</h5>
                    <p class="text-muted small">Speak the product name, code, or barcode to add it to your cart.</p>

                    <div class="alert alert-info py-2 px-3 small mb-3" style="text-align:left;">
                        <i class="fas fa-headphones-alt"></i>
                        <strong>Tip:</strong> For best results, use an earpiece or headphone to prevent echoes in the voice input.
                    </div>
                    
                    <div class="text-center mb-3">
                        <button class="btn btn-voice btn-lg" id="voiceStartBtn" onclick="startVoiceRecognition()">
                            <i class="fas fa-microphone"></i> Click to Speak
                        </button>
                        <button class="btn btn-danger btn-lg" id="voiceStopBtn" onclick="stopVoiceRecognition()" style="display:none;">
                            <i class="fas fa-stop"></i> Stop Listening
                        </button>
                        <div id="voiceStatusMessage" class="mt-2 text-muted">Click the microphone button and speak a product name</div>
                    </div>
                    
                    <div class="voice-status">
                        <div class="status-indicator" id="voiceIndicator"></div>
                        <div>
                            <strong id="voiceStatusText">Ready</strong>
                            <div class="small text-muted" id="voiceConfidence"></div>
                        </div>
                    </div>
                    
                    <div class="voice-transcript" id="voiceTranscript">
                        <span class="text-muted">Your spoken text will appear here...</span>
                    </div>
                    
                    <div class="voice-suggestions" id="voiceSuggestions">
                        <small class="text-muted">Suggested commands:</small>
                        <span class="suggestion-item" onclick="searchVoiceCommand('add 5')">Add 5</span>
                        <span class="suggestion-item" onclick="searchVoiceCommand('add 10')">Add 10</span>
                        <span class="suggestion-item" onclick="searchVoiceCommand('reset')">Reset</span>
                        <span class="suggestion-item" onclick="searchVoiceCommand('clear')">Clear</span>
                        <span class="suggestion-item" onclick="searchVoiceCommand('show cart')">Show Cart</span>
                    </div>
                    
                    <div id="voiceSearchResults" class="mt-3" style="display:none;">
                        <h6>Search Results</h6>
                        <div id="voiceResultsList"></div>
                    </div>
                </div>
            </div>

            <div id="manual-tab" class="tab-content">
                <div class="scanner-container">
                    <h6><i class="fas fa-keyboard mr-2"></i> Manual Search Products</h6>
                    <p class="text-muted small">Search for products by name, code, or barcode.</p>
                    <div class="row">
                        <div class="col-md-10">
                            <input type="text" id="manualBarcodeInput" class="form-control" placeholder="Enter product name, code, or barcode..." style="width:100%;">
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-primary" onclick="manualBarcodeSearch()" style="width:100%;">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                    <div id="manualSearchResults" class="mt-3" style="display:none;">
                        <h6>Search Results</h6>
                        <div id="manualResultsList"></div>
                    </div>
                </div>
            </div>

            <form method="POST" action="" id="salesForm">
                <input type="hidden" name="form_token" value="<?php echo $page_form_token; ?>">
                <input type="hidden" name="action" value="create_order">
                <input type="hidden" name="customer_id" id="formCustomerId" value="<?php echo $selected_customer_id; ?>">
                
                <div class="product-card">
                    <h3>Order Information</h3>
                    <div class="form-group">
                        <label>Order Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Special instructions..."></textarea>
                    </div>
                </div>
                
                <div class="discount-section">
                    <h3>Discount Configuration</h3>
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" id="applyDiscount" name="apply_discount">
                        <label class="custom-control-label" for="applyDiscount">Apply Discount</label>
                    </div>
                    <div id="discountInputs" style="display:none; margin-top:15px;">
                        <div class="row">
                            <div class="col-md-6">
                                <label>Discount Type</label>
                                <select id="discount_type" name="discount_type" class="form-control">
                                    <option value="fixed">Fixed Amount</option>
                                    <option value="percentage">Percentage</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label id="discountLabel">Discount Amount</label>
                                <input type="number" id="discount_amount" name="discount_amount" class="form-control auto-clear-input" value="0" step="0.01" data-default="0">
                                <input type="number" id="discount_percentage" name="discount_percentage" class="form-control auto-clear-input" value="0" step="1" style="display:none;" data-default="0">
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tax Rate (%)</label>
                                <input type="number" step="0.01" class="form-control" id="tax_rate" name="tax_rate" value="<?php echo $tax_rate_default; ?>" min="0" <?php echo $tax_enabled == 'disabled' ? 'readonly style="background:#f5f5f5;"' : ''; ?>>
                                <small class="text-muted"><?php echo $tax_enabled == 'enabled' ? 'Tax rate from settings' : 'Tax is currently disabled in settings'; ?></small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tax Amount (Auto-calculated)</label>
                                <input type="text" class="form-control" id="tax_amount_display" value="<?php echo $currency_symbol; ?> 0.00" disabled>
                                <small class="text-muted">Calculated from subtotal after discount</small>
                            </div>
                        </div>
                    </div>
                    <?php if ($tax_enabled == 'disabled'): ?>
                    <div class="alert alert-warning mt-2">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        Tax is currently <strong>disabled</strong> in your system settings. 
                        <a href="settings.php#taxSettings" target="_blank">Enable tax in Settings</a> to apply tax to orders.
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="product-card">
                    <h3>Select Products</h3>
                    <button type="button" class="btn btn-primary" onclick="showProductModal()"><i class="fas fa-plus"></i> ADD PRODUCT</button>
                    <div id="cartItems" class="mt-4">
                        <h4>Cart <span id="cartCount" class="badge badge-primary">0</span></h4>
                        <div id="cartContent">No products added yet.</div>
                    </div>
                </div>
                
                <div class="order-summary">
                    <h3 class="text-center">ORDER SUMMARY</h3>
                    <div class="summary-row"><span>Subtotal:</span><span id="subtotalDisplay"><?php echo $currency_symbol; ?> 0.00</span></div>
                    <div class="summary-row"><span>Discount:</span><span id="discountDisplay"><?php echo $currency_symbol; ?> 0.00</span></div>
                    <div class="summary-row"><span>Tax:</span><span id="taxDisplay"><?php echo $currency_symbol; ?> 0.00</span></div>
                    <div class="summary-total"><span>TOTAL:</span><span id="totalDisplay"><?php echo $currency_symbol; ?> 0.00</span></div>
                </div>
                
                <div id="hiddenItemsContainer"></div>
                
                <div class="text-center mt-4">
                    <button type="submit" class="btn btn-primary btn-lg" id="createOrderBtn"><i class="fas fa-file-invoice"></i> CREATE ORDER (No Payment)</button>
                    <button type="button" class="btn btn-secondary btn-lg" onclick="resetForm()"><i class="fas fa-undo"></i> Reset</button>
                    <button type="button" class="btn btn-success btn-lg" onclick="refreshPage()"><i class="fas fa-sync-alt"></i> New Order</button>
                </div>
            </form>
            
            <?php else: ?>
            
            <div class="return-section">
                <h3><i class="fas fa-undo"></i> Process Return</h3>
                <p class="text-muted">Enter the invoice number of the completed order to process a return.</p>
                
                <div class="row">
                    <div class="col-md-8">
                        <div class="form-group">
                            <label>Invoice Number</label>
                            <div class="input-group">
                                <input type="text" id="returnInvoiceSearch" class="form-control" placeholder="Enter invoice number (e.g., SO-0000000001)">
                                <button class="btn btn-primary" onclick="searchOrderForReturn()">
                                    <i class="fas fa-search"></i> Search
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <button class="btn btn-secondary" onclick="clearReturnSearch()" style="margin-top: 30px;">
                            <i class="fas fa-times"></i> Clear
                        </button>
                    </div>
                </div>
                
                <div id="returnOrderDetails" style="display:none; margin-top:20px;">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0">Order Found</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <p><strong>Invoice Number:</strong> <span id="returnInvoiceNumber"></span></p>
                                    <p><strong>Customer:</strong> <span id="returnCustomerName"></span></p>
                                    <p><strong>Phone:</strong> <span id="returnCustomerPhone"></span></p>
                                </div>
                                <div class="col-md-6">
                                    <p><strong>Order Date:</strong> <span id="returnOrderDate"></span></p>
                                    <p><strong>Total Amount:</strong> <span id="returnOrderTotal"></span></p>
                                    <p><strong>Status:</strong> <span class="badge bg-success">Completed</span></p>
                                </div>
                            </div>
                            
                            <form method="POST" action="" id="returnForm">
                                <input type="hidden" name="return_token" value="<?php echo $return_form_token; ?>">
                                <input type="hidden" name="action" value="process_return">
                                <input type="hidden" name="order_id" id="returnOrderId" value="">
                                <input type="hidden" name="order_source" value="orders">
                                <input type="hidden" name="refund_method" value="cash">
                                
                                <h5 class="mt-3">Select Items to Return</h5>
                                <div id="returnItemsList"></div>
                                
                                <div class="form-group mt-3">
                                    <label>Return Reason</label>
                                    <textarea name="return_reason" class="form-control" rows="2" placeholder="Reason for return..."></textarea>
                                </div>
                                
                                <div class="form-check mt-3">
                                    <input type="checkbox" class="form-check-input" id="adjustCustomerBalance" name="adjust_customer_balance" checked>
                                    <label class="form-check-label" for="adjustCustomerBalance">
                                        <strong>Adjust customer balance</strong> - Deduct return amount from customer's outstanding balance
                                    </label>
                                </div>
                                
                                <div class="mt-3">
                                    <button type="submit" class="btn btn-success btn-lg" id="processReturnBtn">
                                        <i class="fas fa-check"></i> PROCESS RETURN
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div id="returnNotFound" style="display:none; margin-top:20px;">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> Order not found. Please check the invoice number and try again.
                    </div>
                </div>
            </div>
            
            <?php endif; ?>
            
            <?php endif; ?>
        </div>
        
        <footer class="text-center text-muted py-4 mt-4">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($company_name); ?>. All rights reserved.</footer>
    </div>

    <?php if (!$return_mode): ?>
    <div class="modal fade" id="productModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-boxes"></i> Select Products</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="text" id="productSearch" class="form-control mb-3" placeholder="Search products...">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="thead-light">
                                <tr>
                                    <th style="width: 40px;"><input type="checkbox" id="selectAllProducts"></th>
                                    <th>Product</th>
                                    <th>Code</th>
                                    <th>Stock</th>
                                    <th>Price</th>
                                    <th style="width: 120px;">Quantity</th>
                                </tr>
                            </thead>
                            <tbody id="productTableBody">
                                <?php foreach ($available_products as $product): ?>
                                <tr data-name="<?php echo strtolower($product['product_name']); ?>" data-code="<?php echo strtolower($product['item_code']); ?>">
                                    <td>
                                        <input type="checkbox" class="product-checkbox" 
                                               data-store-id="<?php echo $product['store_item_id']; ?>" 
                                               data-name="<?php echo htmlspecialchars($product['product_name']); ?>" 
                                               data-code="<?php echo $product['item_code']; ?>" 
                                               data-price="<?php echo $product['current_selling_price']; ?>" 
                                               data-stock="<?php echo $product['available_quantity']; ?>"
                                               data-selling-unit="<?php echo $product['selling_unit'] ?? 'pack'; ?>"
                                               data-display-stock="<?php echo $product['display_stock']; ?>">
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($product['product_name']); ?></strong></td>
                                    <td><code><?php echo $product['item_code']; ?></code></td>
                                    <td>
                                        <span class="badge bg-info"><?php echo $product['display_stock']; ?></span>
                                    </td>
                                    <td><strong><?php echo fmt_currency($product['current_selling_price']); ?></strong></td>
                                    <td>
                                        <input type="number" id="modal_qty_<?php echo $product['store_item_id']; ?>" 
                                               class="form-control" value="1" min="1" 
                                               max="<?php echo $product['available_quantity']; ?>" style="width: 100px;">
                                        <small class="text-muted">Max: <?php echo $product['available_quantity']; ?></small>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($available_products)): ?>
                                <tr><td colspan="6" class="text-center py-4">No products available in stock.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" onclick="addSelectedProductsToOrder()"><i class="fas fa-cart-plus"></i> Add Selected</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    // ==================================================================
    // VOICE SUPPORT CHECK - HTTPS + Browser Support
    // ==================================================================
    function checkVoiceSupport() {
        var isSecure = window.location.protocol === 'https:' || window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
        if (!isSecure) {
            showNotification('❌ Voice requires HTTPS. Please use https:// or localhost.', 'error');
            document.getElementById('voiceStatusMessage').innerHTML = '❌ <strong>HTTPS Required</strong> - Voice only works on secure pages (https://) or localhost.';
            document.getElementById('micPermissionGuide').style.display = 'block';
            return false;
        }
        
        var hasSupport = !!(window.SpeechRecognition || window.webkitSpeechRecognition);
        if (!hasSupport) {
            showNotification('❌ Voice recognition is not supported in this browser.', 'error');
            document.getElementById('voiceStatusMessage').innerHTML = '❌ <strong>Browser not supported</strong> - Please use Chrome, Edge, or Safari.';
            return false;
        }
        
        return true;
    }

    // ==================================================================
    // TEXT-TO-SPEECH FEEDBACK - Voice response to user
    // ==================================================================
    function speakResponse(text) {
        try {
            window.speechSynthesis.cancel();
            var utter = new SpeechSynthesisUtterance(text);
            utter.lang = 'en-US';
            utter.rate = 0.95;
            utter.pitch = 1.0;
            window.speechSynthesis.speak(utter);
            console.log('🔊 Speaking:', text);
        } catch (e) {
            console.warn('Speech synthesis error:', e);
        }
    }

    // ==================================================================
    // PAYMENT FUNCTIONS
    // ==================================================================
    function updatePaymentTotal() {
        try {
            var payment = parseFloat(document.getElementById('payment_cash_amount')?.value) || 0;
            var customInputs = document.querySelectorAll('[id^="custom_"]');
            customInputs.forEach(function(inp) {
                if (inp.id !== 'payment_cash_amount') {
                    payment += parseFloat(inp.value) || 0;
                }
            });
            
            var orderTotal = <?php echo $pending_total ?? 0; ?>;
            var balanceDue = Math.max(0, orderTotal - payment);
            
            var paymentDisplay = document.getElementById('paymentTotalDisplay');
            var balanceDisplay = document.getElementById('balanceDueDisplay');
            var totalPaymentHidden = document.getElementById('total_payment_amount');
            var submitBtn = document.getElementById('paymentSubmitBtn');
            
            if (paymentDisplay) paymentDisplay.innerHTML = '<?php echo $currency_symbol; ?> ' + payment.toFixed(2);
            if (balanceDisplay) balanceDisplay.innerHTML = '<?php echo $currency_symbol; ?> ' + balanceDue.toFixed(2);
            if (totalPaymentHidden) totalPaymentHidden.value = payment.toFixed(2);
            if (submitBtn) {
                submitBtn.disabled = (payment <= 0);
                submitBtn.innerHTML = payment > 0 ? 
                    '<i class="fas fa-check"></i> PAY ' + '<?php echo $currency_symbol; ?>' + payment.toFixed(2) : 
                    '<i class="fas fa-check"></i> COMPLETE PAYMENT';
            }
            
            return true;
        } catch(e) {
            console.error('Error in updatePaymentTotal:', e);
            return false;
        }
    }

    document.getElementById('payment_cash_amount')?.addEventListener('input', updatePaymentTotal);
    document.querySelectorAll('[id^="custom_"]').forEach(function(i) {
        i.addEventListener('input', updatePaymentTotal);
    });

    document.getElementById('paymentSubmitBtn')?.addEventListener('click', function(e) {
        var paymentTotal = parseFloat(document.getElementById('total_payment_amount')?.value) || 0;
        if (paymentTotal <= 0) {
            e.preventDefault();
            showNotification('Please enter at least one payment amount.', 'error');
            return false;
        }
        return true;
    });

    document.getElementById('paymentForm')?.addEventListener('submit', function(e) {
        var paymentTotal = parseFloat(document.getElementById('total_payment_amount')?.value) || 0;
        if (paymentTotal <= 0) {
            e.preventDefault();
            showNotification('Please enter at least one payment amount.', 'error');
            return false;
        }
        return true;
    });

    // ==================================================================
    // REFRESH PAGE FUNCTION
    // ==================================================================
    function refreshPage() {
        <?php if ($return_mode): ?>
        window.location.href = '?return_mode=sales&refresh=1';
        <?php else: ?>
        if (Object.keys(cart).length > 0) {
            if (!confirm('Starting a new order will clear the current cart. Continue?')) {
                return false;
            }
        }
        window.location.href = 'new_order.php?refresh=1';
        <?php endif; ?>
    }

    <?php if (isset($_GET['refresh']) && $_GET['refresh'] == 1): ?>
    <?php
        unset($_SESSION['pending_order_id']);
        unset($_SESSION['pending_order_number']);
        unset($_SESSION['pending_order_total']);
        unset($_SESSION['pending_order_subtotal']);
        unset($_SESSION['pending_order_discount']);
        unset($_SESSION['pending_order_tax']);
        unset($_SESSION['pending_customer_id']);
        unset($_SESSION['pending_items']);
        unset($_SESSION['payment_form_token']);
        
        $_SESSION['form_token'] = bin2hex(random_bytes(32));
        $_SESSION['payment_form_token'] = bin2hex(random_bytes(32));
        $_SESSION['return_form_token'] = bin2hex(random_bytes(32));
        
        header("Location: " . ($return_mode ? "?return_mode=sales" : "new_order.php"));
        exit();
    ?>
    <?php endif; ?>

    // ==================================================================
    // switchTab() - WITH EXPLICIT DISPLAY SETTING
    // ==================================================================
    function switchTab(tabId) {
        try {
            document.querySelectorAll('.tab-content').forEach(function(tab) {
                tab.classList.remove('active');
                tab.style.display = 'none';
            });
            
            var targetTab = document.getElementById(tabId);
            if (targetTab) {
                targetTab.classList.add('active');
                targetTab.style.display = 'block';
            }
            
            document.querySelectorAll('.input-tab').forEach(function(btn) {
                btn.classList.remove('active');
            });
            var targetBtn = document.querySelector('.input-tab[data-tab="' + tabId + '"]');
            if (targetBtn) {
                targetBtn.classList.add('active');
            }
            
            if (tabId !== 'scanner-tab') {
                try { stopScanner(); } catch(e) {}
            }
            if (tabId !== 'voice-tab') {
                try { stopVoiceRecognition(); } catch(e) {}
            }
            
            try {
                localStorage.setItem('activeInputTab', tabId);
            } catch (e) {}
        } catch (e) {
            console.warn('switchTab error:', e);
        }
    }

    // ==================================================================
    // OFFLINE DETECTION
    // ==================================================================
    (function() {
        var banner = document.getElementById('offlineBanner');
        function checkOnlineStatus() {
            if (!navigator.onLine) {
                if (banner) banner.style.display = 'block';
                console.log('🔴 Working in OFFLINE mode');
            } else {
                if (banner) banner.style.display = 'none';
                console.log('🟢 Working ONLINE');
            }
        }
        checkOnlineStatus();
        window.addEventListener('online', function() { if (banner) banner.style.display = 'none'; });
        window.addEventListener('offline', function() { if (banner) banner.style.display = 'block'; });
    })();

    // ==================================================================
    // SIDEBAR TOGGLE
    // ==================================================================
    document.addEventListener('DOMContentLoaded', function() {
        var toggleBtn = document.getElementById('toggleSidebarBtn');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                document.getElementById('sidebar').classList.toggle('active');
            });
        }
    });

    // ==================================================================
    // CUSTOMER AUTOCOMPLETE (Sales mode only)
    // ==================================================================
    <?php if (!$return_mode): ?>
    var allCustomers = <?php echo json_encode($active_customers); ?>;
    var currencySymbol = '<?php echo $currency_symbol; ?>';
    
    var searchInput = document.getElementById('customerSearch');
    var dropdown = document.getElementById('autocompleteDropdown');
    var customerSelect = document.getElementById('customer_id');
    var selectedCustomerId = '<?php echo $selected_customer_id; ?>';
    
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function displayAutocompleteResults(results) {
        if (!dropdown) return;
        if (results.length === 0) {
            dropdown.style.display = 'none';
            return;
        }
        var html = '';
        results.forEach(function(customer) {
            var balance = parseFloat(customer.account_balance) || 0;
            var balanceText = balance < 0 ? '<?php echo $currency_symbol; ?>' + Math.abs(balance).toFixed(2) + ' Debt' : 
                             (balance > 0 ? '<?php echo $currency_symbol; ?>' + balance.toFixed(2) + ' Credit' : 'Balanced');
            html += '<div class="autocomplete-item" data-id="' + customer.id + '" data-name="' + escapeHtml(customer.name) + '" data-phone="' + escapeHtml(customer.phone || '') + '" data-email="' + escapeHtml(customer.email || '') + '" data-address="' + escapeHtml(customer.address || '') + '" data-balance="' + balance + '">' +
                        '<strong>' + escapeHtml(customer.name) + '</strong><br>' +
                        '<small>' + escapeHtml(customer.email || 'No email') + ' | ' + escapeHtml(customer.phone || 'No phone') + ' | Balance: ' + balanceText + '</small>' +
                    '</div>';
        });
        dropdown.innerHTML = html;
        dropdown.style.display = 'block';
        
        var items = document.querySelectorAll('.autocomplete-item');
        items.forEach(function(item) {
            item.addEventListener('click', function(e) {
                var id = this.getAttribute('data-id');
                selectCustomer(id);
                e.stopPropagation();
            });
        });
    }
    
    function searchCustomers(term) {
        if (!term || term.length < 2) {
            dropdown.style.display = 'none';
            return;
        }
        var results = allCustomers.filter(function(c) {
            var nameMatch = c.name && c.name.toLowerCase().includes(term.toLowerCase());
            var emailMatch = c.email && c.email.toLowerCase().includes(term.toLowerCase());
            var phoneMatch = c.phone && c.phone.toLowerCase().includes(term.toLowerCase());
            return nameMatch || emailMatch || phoneMatch;
        });
        displayAutocompleteResults(results);
    }
    
    function selectCustomer(id) {
        var customer = null;
        for (var i = 0; i < allCustomers.length; i++) {
            if (allCustomers[i].id == id) {
                customer = allCustomers[i];
                break;
            }
        }
        if (!customer) return;
        
        if (customerSelect) {
            for (var j = 0; j < customerSelect.options.length; j++) {
                if (customerSelect.options[j].value == id) {
                    customerSelect.value = id;
                    break;
                }
            }
        }
        
        if (searchInput) {
            searchInput.value = customer.name;
        }
        
        dropdown.style.display = 'none';
        updateCustomerDisplay(customer);
        showNotification('Customer selected: ' + customer.name, 'success');
    }
    
    function updateCustomerDisplay(customer) {
        var infoDiv = document.getElementById('customerInfo');
        if (!infoDiv) return;
        
        document.getElementById('displayName').innerHTML = escapeHtml(customer.name);
        document.getElementById('displayPhone').innerHTML = escapeHtml(customer.phone || 'N/A');
        document.getElementById('displayEmail').innerHTML = escapeHtml(customer.email || 'N/A');
        document.getElementById('displayAddress').innerHTML = escapeHtml(customer.address || 'N/A');
        
        var balance = parseFloat(customer.account_balance) || 0;
        var balanceSpan = document.getElementById('displayBalance');
        balanceSpan.innerHTML = '<?php echo $currency_symbol; ?> ' + balance.toFixed(2);
        balanceSpan.style.color = balance < 0 ? '#dc3545' : (balance > 0 ? '#28a745' : '#6c757d');
        
        infoDiv.style.display = 'block';
        document.getElementById('formCustomerId').value = customer.id;
    }
    
    function updateCustomerFromSelect() {
        if (!customerSelect || !customerSelect.value) {
            document.getElementById('customerInfo').style.display = 'none';
            document.getElementById('formCustomerId').value = '';
            return;
        }
        var opt = customerSelect.options[customerSelect.selectedIndex];
        var customer = {
            id: opt.value,
            name: opt.getAttribute('data-name'),
            phone: opt.getAttribute('data-phone'),
            email: opt.getAttribute('data-email'),
            address: opt.getAttribute('data-address'),
            account_balance: opt.getAttribute('data-balance')
        };
        updateCustomerDisplay(customer);
        if (searchInput) {
            searchInput.value = customer.name;
        }
        document.getElementById('formCustomerId').value = customer.id;
    }
    
    if (searchInput) {
        searchInput.addEventListener('input', function(e) {
            var term = e.target.value;
            if (term.length >= 2) {
                searchCustomers(term);
            } else {
                dropdown.style.display = 'none';
            }
        });
        
        searchInput.addEventListener('blur', function() {
            setTimeout(function() {
                dropdown.style.display = 'none';
            }, 300);
        });
        
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var firstItem = document.querySelector('.autocomplete-item');
                if (firstItem) {
                    var id = firstItem.getAttribute('data-id');
                    selectCustomer(id);
                }
            }
        });
        
        if (selectedCustomerId) {
            var selectedCustomer = null;
            for (var i = 0; i < allCustomers.length; i++) {
                if (allCustomers[i].id == selectedCustomerId) {
                    selectedCustomer = allCustomers[i];
                    break;
                }
            }
            if (selectedCustomer) {
                searchInput.value = selectedCustomer.name;
                if (customerSelect) {
                    customerSelect.value = selectedCustomerId;
                }
                updateCustomerDisplay(selectedCustomer);
            }
        }
    }
    
    if (customerSelect) {
        customerSelect.addEventListener('change', function() {
            updateCustomerFromSelect();
        });
    }
    <?php endif; ?>

    // ==================================================================
    // CART FUNCTIONS (Sales mode only)
    // ==================================================================
    <?php if (!$return_mode): ?>
    var cart = {};

    function showProductModal() { 
        var modal = new bootstrap.Modal(document.getElementById('productModal'));
        modal.show();
    }

    function filterProducts() {
        var search = document.getElementById('productSearch')?.value.toLowerCase() || '';
        var rows = document.querySelectorAll('#productTableBody tr');
        rows.forEach(function(row) {
            var name = row.getAttribute('data-name') || '';
            var code = row.getAttribute('data-code') || '';
            row.style.display = (name.includes(search) || code.includes(search)) ? '' : 'none';
        });
    }

    document.getElementById('productSearch')?.addEventListener('keyup', filterProducts);
    document.getElementById('selectAllProducts')?.addEventListener('change', function(e) {
        var checkboxes = document.querySelectorAll('.product-checkbox');
        checkboxes.forEach(function(cb) {
            cb.checked = e.target.checked;
        });
    });

    function addSelectedProductsToOrder() {
        var checked = Array.from(document.querySelectorAll('.product-checkbox:checked'));
        if (checked.length === 0) {
            showNotification('No products selected. Please check at least one product.', 'error');
            return;
        }
        var addedCount = 0;
        checked.forEach(function(cb) {
            try {
                var id = cb.getAttribute('data-store-id');
                if (!id) return;
                
                var row = cb.closest('tr');
                var qtyInput = document.getElementById('modal_qty_' + id) || row?.querySelector('input[type="number"]');
                var name = cb.getAttribute('data-name');
                var code = cb.getAttribute('data-code');
                var price = parseFloat(cb.getAttribute('data-price')) || 0;
                var stock = parseInt(cb.getAttribute('data-stock')) || 0;
                var sellingUnit = cb.getAttribute('data-selling-unit') || 'pack';
                
                var qty = parseInt(qtyInput?.value || 1, 10);
                if (isNaN(qty) || qty < 1) qty = 1;
                if (stock > 0 && qty > stock) qty = stock;
                
                addToCart(id, name, code, price, stock, qty, sellingUnit);
                addedCount++;
            } catch (error) {
                console.error('Failed to add selected product:', error);
            }
        });
        document.querySelectorAll('.product-checkbox:checked').forEach(function(cb) {
            cb.checked = false;
        });
        var selectAll = document.getElementById('selectAllProducts');
        if (selectAll) selectAll.checked = false;
        if (addedCount > 0) {
            var modal = bootstrap.Modal.getInstance(document.getElementById('productModal'));
            if (modal) modal.hide();
            showNotification(addedCount + ' product(s) added to order', 'success');
        }
    }

    function addToCart(id, name, code, price, stock, qty, sellingUnit) {
        if (cart[id]) {
            var newQty = cart[id].qty + qty;
            if (newQty > stock) newQty = stock;
            cart[id].qty = newQty;
            cart[id].total = newQty * price;
        } else {
            cart[id] = { id, name, code, price, qty, total: qty * price, stock, sellingUnit };
        }
        updateCart();
    }

    function updateCart() {
        var html = '', count = 0, subtotal = 0;
        for (var key in cart) {
            var item = cart[key];
            subtotal += item.total;
            
            var priceDisplay = currencySymbol + ' ' + item.price.toFixed(2);
            var unitLabel = item.sellingUnit === 'unit' ? 'Units' : 'Packs';
            var qtyLabel = item.qty + ' ' + unitLabel;
            
            html += '<div class="cart-item">' +
                        '<div class="row">' +
                            '<div class="col-md-5">' +
                                '<strong>' + escapeHtml(item.name) + '</strong><br>' +
                                '<small>' + escapeHtml(item.code) + ' | ' + priceDisplay + '</small>' +
                                '<br><small class="text-muted">' + qtyLabel + ' (' + item.stock + ' available)</small>' +
                            '</div>' +
                            '<div class="col-md-5">' +
                                '<div class="quantity-controls">' +
                                    '<button class="qty-btn" onclick="updateCartQty(\'' + key + '\', -1)">-</button>' +
                                    '<input type="number" id="qty_' + key + '" class="quantity-input" value="' + item.qty + '" min="1" max="' + item.stock + '" onchange="setCartQty(\'' + key + '\', this.value)">' +
                                    '<button class="qty-btn" onclick="updateCartQty(\'' + key + '\', 1)">+</button>' +
                                '</div>' +
                            '</div>' +
                            '<div class="col-md-2 text-right">' +
                                '<strong>' + currencySymbol + ' ' + item.total.toFixed(2) + '</strong><br>' +
                                '<button class="btn btn-sm btn-danger mt-1" onclick="removeFromCart(\'' + key + '\')"><i class="fas fa-trash"></i></button>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
            count++;
        }
        document.getElementById('cartCount').innerHTML = count;
        document.getElementById('cartContent').innerHTML = html || '<p class="text-center py-4">No products added.</p>';
        updateHiddenFields();
        updateTotals();
    }

    function updateCartQty(id, change) {
        var newQty = cart[id].qty + change;
        if (newQty < 1) newQty = 1;
        if (newQty > cart[id].stock) newQty = cart[id].stock;
        cart[id].qty = newQty;
        cart[id].total = newQty * cart[id].price;
        document.getElementById('qty_' + id).value = newQty;
        updateCart();
    }

    function setCartQty(id, qty) {
        qty = parseInt(qty);
        if (isNaN(qty) || qty < 1) qty = 1;
        if (qty > cart[id].stock) qty = cart[id].stock;
        cart[id].qty = qty;
        cart[id].total = qty * cart[id].price;
        updateCart();
    }

    function removeFromCart(id) {
        delete cart[id];
        updateCart();
    }

    function updateHiddenFields() {
        var html = '', i = 0;
        for (var key in cart) {
            var item = cart[key];
            html += '<input type="hidden" name="items[' + i + '][store_item_id]" value="' + item.id + '">' +
                    '<input type="hidden" name="items[' + i + '][quantity]" value="' + item.qty + '">';
            i++;
        }
        document.getElementById('hiddenItemsContainer').innerHTML = html;
    }

    function updateTotals() {
        var subtotal = 0;
        for (var key in cart) subtotal += cart[key].total;
        
        var discount = 0;
        var applyDiscount = document.getElementById('applyDiscount');
        var discountType = document.getElementById('discount_type');
        var discountAmount = document.getElementById('discount_amount');
        var discountPercentage = document.getElementById('discount_percentage');
        
        if (applyDiscount && applyDiscount.checked) {
            if (discountType && discountType.value === 'percentage') {
                var p = parseFloat(discountPercentage?.value) || 0;
                discount = subtotal * (p / 100);
            } else {
                discount = parseFloat(discountAmount?.value) || 0;
            }
            if (discount > subtotal) discount = subtotal;
        }
        
        var taxRate = parseFloat(document.getElementById('tax_rate')?.value) || 0;
        var taxableAmount = Math.max(0, subtotal - discount);
        var taxAmount = taxableAmount * (taxRate / 100);
        var total = Math.max(0, subtotal - discount + taxAmount);
        
        document.getElementById('subtotalDisplay').innerHTML = currencySymbol + ' ' + subtotal.toFixed(2);
        document.getElementById('discountDisplay').innerHTML = currencySymbol + ' ' + discount.toFixed(2);
        document.getElementById('taxDisplay').innerHTML = currencySymbol + ' ' + taxAmount.toFixed(2);
        document.getElementById('totalDisplay').innerHTML = currencySymbol + ' ' + total.toFixed(2);
        document.getElementById('tax_amount_display').value = currencySymbol + ' ' + taxAmount.toFixed(2);
    }

    function showNotification(msg, type) {
        var notif = document.createElement('div');
        var alertClass = type === 'success' ? 'alert-success' : type === 'error' ? 'alert-danger' : 'alert-info';
        notif.className = 'alert ' + alertClass + ' alert-dismissible fade show';
        notif.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;max-width:400px;box-shadow:0 4px 15px rgba(0,0,0,0.2);animation:slideIn 0.3s ease;';
        notif.innerHTML = '<strong>' + escapeHtml(msg) + '</strong><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        document.body.appendChild(notif);
        setTimeout(function() {
            if (notif.parentNode) notif.remove();
        }, 4000);
    }

    function resetForm() {
        if (Object.keys(cart).length > 0 && !confirm('Reset form? This will clear the cart.')) return;
        cart = {};
        updateCart();
        document.getElementById('customer_id').value = '';
        document.getElementById('customerSearch').value = '';
        document.getElementById('customerInfo').style.display = 'none';
        document.getElementById('formCustomerId').value = '';
        document.getElementById('applyDiscount').checked = false;
        document.getElementById('discountInputs').style.display = 'none';
        document.getElementById('discount_amount').value = '0';
        document.getElementById('discount_percentage').value = '0';
        document.getElementById('tax_rate').value = '<?php echo $tax_rate_default; ?>';
        showNotification('Form reset successfully', 'info');
    }

    // ==================================================================
    // EVENT LISTENERS FOR DISCOUNT & TAX
    // ==================================================================
    document.getElementById('applyDiscount')?.addEventListener('change', function() {
        document.getElementById('discountInputs').style.display = this.checked ? 'block' : 'none';
        updateTotals();
    });

    document.getElementById('discount_type')?.addEventListener('change', function() {
        var amt = document.getElementById('discount_amount');
        var pct = document.getElementById('discount_percentage');
        var label = document.getElementById('discountLabel');
        if (this.value === 'percentage') { 
            amt.style.display = 'none'; 
            pct.style.display = 'block';
            if (label) label.innerHTML = 'Discount Percentage (%)';
        } else { 
            amt.style.display = 'block'; 
            pct.style.display = 'none';
            if (label) label.innerHTML = 'Discount Amount (<?php echo $currency_symbol; ?>)';
        }
        updateTotals();
    });

    document.getElementById('discount_amount')?.addEventListener('input', updateTotals);
    document.getElementById('discount_percentage')?.addEventListener('input', updateTotals);
    document.getElementById('tax_rate')?.addEventListener('input', updateTotals);

    // ==================================================================
    // VOICE RECOGNITION - FIXED VERSION
    // ==================================================================
    var recognition = null;
    var isListening = false;
    var shouldKeepListening = false;
    var voiceTimeout = null;
    var allProducts = <?php echo json_encode($available_products ?? []); ?>;
    var restartAttempts = 0;
    var maxRestartAttempts = 5;

    if (allProducts.length === 0) {
        console.warn('No products loaded for voice search. Make sure products exist in the database.');
    }

    function startVoiceRecognition() {
        if (!checkVoiceSupport()) {
            return;
        }

        if (recognition) {
            try { recognition.abort(); } catch(e) {}
            try { recognition.stop(); } catch(e) {}
            recognition = null;
        }

        var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRecognition) {
            showNotification('❌ Voice recognition not supported in this browser.', 'error');
            return;
        }

        recognition = new SpeechRecognition();
        recognition.lang = 'en-US';
        recognition.continuous = true;
        recognition.interimResults = true;
        recognition.maxAlternatives = 5;
        recognition.timeout = 10000;

        var startBtn = document.getElementById('voiceStartBtn');
        var stopBtn = document.getElementById('voiceStopBtn');
        var indicator = document.getElementById('voiceIndicator');
        var statusText = document.getElementById('voiceStatusText');
        var transcript = document.getElementById('voiceTranscript');
        var confidenceEl = document.getElementById('voiceConfidence');
        var statusMsg = document.getElementById('voiceStatusMessage');

        restartAttempts = 0;

        recognition.onstart = function() {
            isListening = true;
            shouldKeepListening = true;
            restartAttempts = 0;
            
            if (startBtn) {
                startBtn.style.display = 'none';
                startBtn.classList.add('listening');
            }
            if (stopBtn) stopBtn.style.display = 'inline-block';
            if (indicator) indicator.className = 'status-indicator listening';
            if (statusText) statusText.textContent = '🎤 Listening...';
            if (statusMsg) statusMsg.textContent = '🎤 Listening... Speak a product name';
            if (transcript) transcript.innerHTML = '<span class="text-muted">Listening for your voice...</span>';
            if (confidenceEl) confidenceEl.textContent = '';
            console.log('🎤 Voice recognition started');
            
            document.getElementById('micPermissionGuide').style.display = 'none';
        };

        recognition.onerror = function(event) {
            console.error('Voice recognition error:', event.error);
            
            if (event.error === 'not-allowed') {
                showNotification('❌ Please allow microphone access in your browser settings.', 'error');
                if (statusMsg) statusMsg.innerHTML = '❌ <strong>Microphone access denied.</strong> Click the <i class="fas fa-lock"></i> lock icon in your address bar and allow microphone.';
                document.getElementById('micPermissionGuide').style.display = 'block';
                stopVoiceRecognition();
            } else if (event.error === 'no-speech') {
                if (statusMsg) statusMsg.textContent = '🔇 No speech detected. Please try again.';
                if (statusText) statusText.textContent = '🔇 No speech...';
            } else if (event.error === 'audio-capture') {
                showNotification('❌ No microphone found on this device.', 'error');
                if (statusMsg) statusMsg.textContent = '❌ No microphone found. Please connect a microphone and try again.';
                stopVoiceRecognition();
            } else if (event.error === 'service-not-allowed') {
                showNotification('❌ Voice service not allowed. Please check browser permissions.', 'error');
                if (statusMsg) statusMsg.textContent = '❌ Voice service not allowed. Check browser permissions.';
                document.getElementById('micPermissionGuide').style.display = 'block';
                stopVoiceRecognition();
            } else if (event.error === 'aborted') {
                console.log('Voice recognition aborted');
            } else {
                if (statusMsg) statusMsg.textContent = '❌ Error: ' + event.error;
                stopVoiceRecognition();
            }
        };

        recognition.onend = function() {
            console.log('Voice recognition ended, shouldKeepListening:', shouldKeepListening);
            
            if (shouldKeepListening && isListening) {
                if (restartAttempts >= maxRestartAttempts) {
                    console.warn('Max restart attempts reached, stopping voice recognition');
                    if (statusMsg) statusMsg.textContent = '⚠️ Voice recognition stopped after multiple errors. Click the mic to restart.';
                    stopVoiceRecognition();
                    return;
                }
                
                restartAttempts++;
                console.log('Restarting voice recognition (attempt ' + restartAttempts + ')...');
                
                try {
                    if (recognition) {
                        try { recognition.abort(); } catch(e) {}
                        try { recognition.stop(); } catch(e) {}
                        recognition = null;
                    }
                    
                    var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
                    if (!SpeechRecognition) {
                        stopVoiceRecognition();
                        return;
                    }
                    
                    recognition = new SpeechRecognition();
                    recognition.lang = 'en-US';
                    recognition.continuous = true;
                    recognition.interimResults = true;
                    recognition.maxAlternatives = 5;
                    recognition.timeout = 10000;
                    
                    recognition.onstart = function() {
                        isListening = true;
                        shouldKeepListening = true;
                        if (startBtn) {
                            startBtn.style.display = 'none';
                            startBtn.classList.add('listening');
                        }
                        if (stopBtn) stopBtn.style.display = 'inline-block';
                        if (indicator) indicator.className = 'status-indicator listening';
                        if (statusText) statusText.textContent = '🎤 Listening...';
                        if (statusMsg) statusMsg.textContent = '🎤 Listening... Speak a product name';
                        if (transcript) transcript.innerHTML = '<span class="text-muted">Listening for your voice...</span>';
                        if (confidenceEl) confidenceEl.textContent = '';
                        console.log('🎤 Voice recognition restarted');
                        document.getElementById('micPermissionGuide').style.display = 'none';
                    };
                    
                    recognition.onerror = function(event) {
                        console.error('Voice recognition error:', event.error);
                        if (event.error === 'not-allowed') {
                            showNotification('❌ Please allow microphone access.', 'error');
                            if (statusMsg) statusMsg.innerHTML = '❌ <strong>Microphone access denied.</strong>';
                            document.getElementById('micPermissionGuide').style.display = 'block';
                            stopVoiceRecognition();
                        } else if (event.error === 'no-speech') {
                            if (statusMsg) statusMsg.textContent = '🔇 No speech detected. Please try again.';
                        } else if (event.error === 'aborted') {
                            console.log('Voice recognition aborted');
                        } else {
                            if (statusMsg) statusMsg.textContent = '❌ Error: ' + event.error;
                            stopVoiceRecognition();
                        }
                    };
                    
                    recognition.onend = function() {
                        console.log('Voice recognition ended (restarted instance)');
                        if (shouldKeepListening && isListening) {
                            if (restartAttempts < maxRestartAttempts) {
                                setTimeout(function() {
                                    if (shouldKeepListening && isListening) {
                                        recognition = null;
                                        startVoiceRecognition();
                                    }
                                }, 500);
                            } else {
                                if (statusMsg) statusMsg.textContent = '⚠️ Voice stopped after multiple errors. Click mic to restart.';
                                stopVoiceRecognition();
                            }
                        }
                    };
                    
                    recognition.onresult = function(event) {
                        var finalTranscript = '';
                        var interimTranscript = '';
                        var bestConfidence = 0;
                        
                        for (var i = event.resultIndex; i < event.results.length; i++) {
                            var result = event.results[i];
                            var text = result[0].transcript;
                            var confidence = result[0].confidence || 0;
                            
                            if (result.isFinal) {
                                finalTranscript += text;
                                if (confidence > bestConfidence) bestConfidence = confidence;
                            } else {
                                interimTranscript += text;
                            }
                        }
                        
                        var displayText = finalTranscript || interimTranscript;
                        if (displayText) {
                            if (transcript) {
                                if (!finalTranscript) {
                                    transcript.innerHTML = '<span class="highlight" style="color: #6c5ce7;">' + escapeHtml(displayText) + '</span> <span class="text-muted">(listening...)</span>';
                                } else {
                                    transcript.innerHTML = '<span class="highlight" style="color: #00b894;">✅ ' + escapeHtml(displayText) + '</span>';
                                }
                            }
                            if (confidenceEl) confidenceEl.textContent = 'Confidence: ' + Math.round(bestConfidence * 100) + '%';
                            
                            if (statusMsg && interimTranscript) {
                                statusMsg.textContent = '🔊 Hearing: "' + displayText + '"';
                            }
                            
                            if (finalTranscript) {
                                console.log('🗣️ Processing voice command:', finalTranscript);
                                processVoiceCommand(finalTranscript.trim());
                            }
                        }
                    };
                    
                    recognition.start();
                    console.log('Voice recognition restarted successfully');
                } catch (e) {
                    console.error('Failed to restart voice recognition:', e);
                    if (statusMsg) statusMsg.textContent = '❌ Failed to restart voice. Click the mic to try again.';
                    stopVoiceRecognition();
                }
            } else {
                console.log('Voice recognition stopped intentionally');
                if (statusMsg) statusMsg.textContent = 'Click the microphone button and speak a product name';
            }
        };

        recognition.onresult = function(event) {
            var finalTranscript = '';
            var interimTranscript = '';
            var bestConfidence = 0;
            
            for (var i = event.resultIndex; i < event.results.length; i++) {
                var result = event.results[i];
                var text = result[0].transcript;
                var confidence = result[0].confidence || 0;
                
                if (result.isFinal) {
                    finalTranscript += text;
                    if (confidence > bestConfidence) bestConfidence = confidence;
                } else {
                    interimTranscript += text;
                }
            }
            
            var displayText = finalTranscript || interimTranscript;
            if (displayText) {
                if (transcript) {
                    if (!finalTranscript) {
                        transcript.innerHTML = '<span class="highlight" style="color: #6c5ce7;">' + escapeHtml(displayText) + '</span> <span class="text-muted">(listening...)</span>';
                    } else {
                        transcript.innerHTML = '<span class="highlight" style="color: #00b894;">✅ ' + escapeHtml(displayText) + '</span>';
                    }
                }
                if (confidenceEl) confidenceEl.textContent = 'Confidence: ' + Math.round(bestConfidence * 100) + '%';
                
                if (statusMsg && interimTranscript) {
                    statusMsg.textContent = '🔊 Hearing: "' + displayText + '"';
                }
                
                if (finalTranscript) {
                    console.log('🗣️ Processing voice command:', finalTranscript);
                    processVoiceCommand(finalTranscript.trim());
                }
            }
        };

        try {
            recognition.start();
            console.log('Voice recognition started successfully');
            document.getElementById('voiceStatusMessage').textContent = '🎤 Listening... Speak a product name';
        } catch (e) {
            console.error('Failed to start voice recognition:', e);
            showNotification('Error starting voice recognition: ' + e.message, 'error');
            stopVoiceRecognition();
        }
    }

    function stopVoiceRecognition() {
        shouldKeepListening = false;
        isListening = false;
        restartAttempts = 0;
        
        if (voiceTimeout) {
            clearTimeout(voiceTimeout);
            voiceTimeout = null;
        }
        
        if (recognition) {
            try { recognition.abort(); } catch(e) {}
            try { recognition.stop(); } catch(e) {}
            recognition = null;
        }
        
        var startBtn = document.getElementById('voiceStartBtn');
        var stopBtn = document.getElementById('voiceStopBtn');
        var indicator = document.getElementById('voiceIndicator');
        var statusText = document.getElementById('voiceStatusText');
        var statusMsg = document.getElementById('voiceStatusMessage');
        
        if (startBtn) {
            startBtn.style.display = 'inline-block';
            startBtn.classList.remove('listening');
        }
        if (stopBtn) stopBtn.style.display = 'none';
        if (indicator) indicator.className = 'status-indicator';
        if (statusText) statusText.textContent = 'Ready';
        if (statusMsg) statusMsg.textContent = 'Click the microphone button and speak a product name';
        console.log('Voice recognition stopped');
    }

    // ==================================================================
    // FUZZY / PHONETIC PRODUCT SEARCH
    // ==================================================================
    function soundex(word) {
        word = (word || '').toUpperCase().replace(/[^A-Z]/g, '');
        if (!word) return '';
        var codes = {
            B: '1', F: '1', P: '1', V: '1',
            C: '2', G: '2', J: '2', K: '2', Q: '2', S: '2', X: '2', Z: '2',
            D: '3', T: '3',
            L: '4',
            M: '5', N: '5',
            R: '6'
        };
        var first = word.charAt(0);
        var result = first;
        var lastCode = codes[first] || '';
        for (var i = 1; i < word.length && result.length < 4; i++) {
            var ch = word.charAt(i);
            var code = codes[ch] || '';
            if (code !== '' && code !== lastCode) {
                result += code;
            }
            if (ch !== 'H' && ch !== 'W') {
                lastCode = code;
            }
        }
        while (result.length < 4) result += '0';
        return result;
    }

    function levenshteinDistance(a, b) {
        a = a || ''; b = b || '';
        var m = a.length, n = b.length;
        if (m === 0) return n;
        if (n === 0) return m;
        var prevRow = [];
        for (var j = 0; j <= n; j++) prevRow[j] = j;
        for (var i = 1; i <= m; i++) {
            var currRow = [i];
            for (j = 1; j <= n; j++) {
                var cost = a.charAt(i - 1) === b.charAt(j - 1) ? 0 : 1;
                currRow[j] = Math.min(
                    prevRow[j] + 1,
                    currRow[j - 1] + 1,
                    prevRow[j - 1] + cost
                );
            }
            prevRow = currRow;
        }
        return prevRow[n];
    }

    function wordsSoundSimilar(w1, w2) {
        if (!w1 || !w2) return false;
        if (w1 === w2) return true;

        var minLen = Math.min(w1.length, w2.length);
        var maxLen = Math.max(w1.length, w2.length);

        if (minLen >= 3 && (w1.indexOf(w2) !== -1 || w2.indexOf(w1) !== -1)) return true;
        if (minLen < 4) return false;
        if ((maxLen - minLen) > Math.ceil(minLen * 0.4)) return false;

        var distance = levenshteinDistance(w1, w2);
        var threshold = maxLen <= 4 ? 1 : (maxLen <= 7 ? 2 : 3);
        if (distance <= threshold) return true;

        return soundex(w1) === soundex(w2) && distance <= threshold;
    }

    function fuzzyProductSearch(query) {
        var q = (query || '').toLowerCase().trim();
        if (!q) return [];
        var qWords = q.split(/\s+/).filter(function(w) { return w.length > 0; });

        var scored = [];
        allProducts.forEach(function(product) {
            var name = (product.product_name || '').toLowerCase();
            var code = (product.item_code || '').toLowerCase();
            var barcode = (product.barcode || '').toLowerCase();

            if (name.indexOf(q) !== -1 || code.indexOf(q) !== -1 || (barcode && barcode.indexOf(q) !== -1)) {
                scored.push({ product: product, score: 1000 });
                return;
            }

            var nameWords = name.split(/\s+/).filter(function(w) { return w.length > 0; });
            var matchedCount = 0;
            qWords.forEach(function(qw) {
                for (var i = 0; i < nameWords.length; i++) {
                    if (wordsSoundSimilar(qw, nameWords[i])) {
                        matchedCount++;
                        break;
                    }
                }
            });

            if (matchedCount > 0) {
                scored.push({ product: product, score: (matchedCount / qWords.length) * 100 });
            }
        });

        scored.sort(function(a, b) { return b.score - a.score; });
        return scored
            .filter(function(s) { return s.score >= 40; })
            .map(function(s) { return s.product; });
    }

    function processVoiceCommand(text) {
        var lowerText = text.toLowerCase().trim();
        console.log('Processing voice command:', lowerText);
        
        if (lowerText.includes('reset') || lowerText.includes('clear') || lowerText.includes('new order')) {
            resetForm();
            showNotification('🔄 Cart cleared by voice command', 'info');
            document.getElementById('voiceStatusMessage').textContent = '✅ Cart cleared!';
            speakResponse('Cart cleared');
            return;
        }
        
        if (lowerText.includes('show cart') || lowerText.includes('view cart') || lowerText.includes('what is in my cart')) {
            var count = Object.keys(cart).length;
            showNotification('📋 Current cart has ' + count + ' items', 'info');
            document.getElementById('voiceStatusMessage').textContent = '📋 ' + count + ' items in cart';
            speakResponse('You have ' + count + ' items in your cart');
            return;
        }
        
        var quantity = 1;
        var qtyMatch = text.match(/(\d+)\s*(?:items?|pieces?|units?|packs?)?/i);
        if (qtyMatch) {
            quantity = parseInt(qtyMatch[1]);
            text = text.replace(/\d+\s*(?:items?|pieces?|units?|packs?)?\s*/i, '').trim();
        }
        
        var addMatch = text.match(/add\s+(\d+)/i);
        if (addMatch) {
            quantity = parseInt(addMatch[1]);
            text = text.replace(/add\s+\d+\s*/i, '').trim();
        }
        
        var searchText = text;
        if (searchText.toLowerCase().startsWith('add ')) {
            searchText = searchText.substring(4).trim();
        }
        
        var stopWords = ['please', 'thank', 'you', 'can', 'get', 'me', 'the', 'a', 'an', 'and', 'or', 'but', 'for', 'nor', 'on', 'at', 'to', 'by', 'in', 'i', 'want'];
        var words = searchText.split(' ').filter(function(word) {
            return !stopWords.includes(word.toLowerCase()) && word.length > 1;
        });
        var cleanSearch = words.join(' ');
        
        if (cleanSearch.length < 2) {
            document.getElementById('voiceStatusMessage').textContent = '🔍 Please say a product name or code';
            return;
        }
        
        console.log('Searching for:', cleanSearch);
        
        var foundProducts = fuzzyProductSearch(cleanSearch);
        
        console.log('Found products:', foundProducts.length);
        
        if (foundProducts.length === 0) {
            document.getElementById('voiceStatusMessage').textContent = '🔍 Searching for: ' + cleanSearch + '...';
            findProductByBarcode(cleanSearch, function(product) {
                if (product) {
                    addProductToCart(product, quantity);
                } else {
                    document.getElementById('voiceStatusMessage').textContent = '❌ No product found for: "' + cleanSearch + '"';
                    showNotification('No product found for: ' + cleanSearch, 'error');
                    speakResponse('No product found');
                }
            });
        } else if (foundProducts.length === 1) {
            addProductToCart(foundProducts[0], quantity);
        } else {
            showVoiceProductSuggestions(foundProducts, quantity);
            document.getElementById('voiceStatusMessage').textContent = '🔍 Multiple products found. Please select one below.';
            speakResponse('Multiple products found, please select one');
        }
    }

    function findProductByBarcode(query, callback) {
        $.ajax({
            url: 'new_order.php',
            type: 'GET',
            data: { barcode_search: query },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    callback(response.product);
                } else {
                    callback(null);
                }
            },
            error: function() {
                callback(null);
            }
        });
    }

    function addProductToCart(product, quantity) {
        console.log('Adding product to cart:', product.product_name, quantity);
        var storeId = product.store_item_id;
        var name = product.product_name;
        var code = product.item_code;
        var price = parseFloat(product.current_selling_price);
        var stock = parseInt(product.available_quantity);
        var sellingUnit = product.selling_unit || 'pack';
        
        if (quantity > stock) quantity = stock;
        
        if (stock > 0) {
            addToCart(storeId, name, code, price, stock, quantity, sellingUnit);
            var msg = 'Added ' + quantity + ' ' + name + ' to cart';
            document.getElementById('voiceStatusMessage').textContent = '✅ ' + msg;
            showNotification('✅ ' + msg, 'success');
            speakResponse(msg);
            document.getElementById('cartItems').scrollIntoView({ behavior: 'smooth' });
        } else {
            var msg = name + ' has no stock available';
            document.getElementById('voiceStatusMessage').textContent = '⚠️ ' + msg;
            showNotification('⚠️ ' + msg, 'warning');
            speakResponse('Sorry, ' + name + ' is out of stock');
        }
    }

    function showVoiceProductSuggestions(products, quantity) {
        quantity = parseInt(quantity, 10);
        if (isNaN(quantity) || quantity < 1) quantity = 1;

        var resultsDiv = document.getElementById('voiceSearchResults');
        var listDiv = document.getElementById('voiceResultsList');
        
        resultsDiv.style.display = 'block';
        var html = '';
        products.forEach(function(product, index) {
            var stock = parseInt(product.available_quantity) || 0;
            var rowQty = Math.min(quantity, stock > 0 ? stock : quantity);
            html += '<div class="cart-item">' +
                        '<div class="row align-items-center">' +
                            '<div class="col-6">' +
                                '<strong>' + escapeHtml(product.product_name) + '</strong><br>' +
                                '<small>' + escapeHtml(product.item_code) + ' | Stock: ' + product.display_stock + '</small>' +
                            '</div>' +
                            '<div class="col-3">' +
                                '<strong>' + currencySymbol + ' ' + parseFloat(product.current_selling_price).toFixed(2) + '</strong>' +
                            '</div>' +
                            '<div class="col-3 text-right">' +
                                '<div class="input-group input-group-sm" style="max-width:120px; margin-left:auto;">' +
                                    '<input type="number" min="1" max="' + stock + '" value="' + rowQty + '" id="voiceSuggestQty' + index + '" class="form-control form-control-sm" style="width:55px;" onclick="event.stopPropagation();">' +
                                    '<div class="input-group-append">' +
                                        '<button class="btn btn-success btn-sm" onclick="event.stopPropagation(); addVoiceSuggestionWithQty(' + index + ')">' +
                                            '<i class="fas fa-cart-plus"></i>' +
                                        '</button>' +
                                    '</div>' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
        });
        
        window.productsList = products;
        listDiv.innerHTML = html;
        document.getElementById('voiceSearchResults').scrollIntoView({ behavior: 'smooth' });
    }

    function addVoiceSuggestionWithQty(index) {
        var product = window.productsList && window.productsList[index];
        if (!product) return;
        var qtyInput = document.getElementById('voiceSuggestQty' + index);
        var qty = qtyInput ? parseInt(qtyInput.value, 10) : 1;
        if (isNaN(qty) || qty < 1) qty = 1;
        addProductToCart(product, qty);
        var resultsDiv = document.getElementById('voiceSearchResults');
        if (resultsDiv) resultsDiv.style.display = 'none';
    }

    function searchVoiceCommand(command) {
        processVoiceCommand(command);
    }
    <?php endif; ?>

    // ==================================================================
    // MAIN QR/BARCODE SCANNER
    // ==================================================================
    var html5QrCode = null;
    var scannedProductData = null;
    var isScanning = false;
    var activeCameraStream = null;
    var activeCameraVideo = null;
    var activeCameraDetectionTimer = null;

    function stopScannerInstance(scanner) {
        if (scanner && typeof scanner.stop === 'function') {
            return scanner.stop().catch(function() {});
        }
        return Promise.resolve();
    }

    function stopActiveCameraStream() {
        if (activeCameraDetectionTimer) {
            clearTimeout(activeCameraDetectionTimer);
            activeCameraDetectionTimer = null;
        }
        if (activeCameraStream) {
            activeCameraStream.getTracks().forEach(function(track) {
                track.stop();
            });
            activeCameraStream = null;
        }
        if (activeCameraVideo) {
            if (activeCameraVideo.srcObject) {
                activeCameraVideo.srcObject = null;
            }
            if (activeCameraVideo.parentNode) {
                activeCameraVideo.parentNode.removeChild(activeCameraVideo);
            }
            activeCameraVideo = null;
        }
    }

    function applyScannerMirror(videoEl, shouldMirror) {
        if (videoEl) {
            videoEl.style.transform = shouldMirror ? 'scaleX(-1)' : 'scaleX(1)';
            videoEl.style.webkitTransform = shouldMirror ? 'scaleX(-1)' : 'scaleX(1)';
        }
    }

    function getCameraErrorMessage(err) {
        var rawMessage = err && (err.message || err.name || String(err)) ? (err.message || err.name || String(err)) : 'Unknown error';
        var lowered = rawMessage.toLowerCase();

        if (/notallowed|permission denied|permission|denied/i.test(lowered)) {
            return 'Camera permission was denied. Please allow camera access for this site in your browser settings, then refresh and try again.';
        }

        if (/notfound|no camera|device/i.test(lowered)) {
            return 'No camera was found on this device. Please use a device with a camera or try another browser.';
        }

        if (/notreadable|already in use|in use/i.test(lowered)) {
            return 'The camera is already being used by another app. Please close that app and try again.';
        }

        return 'Unable to start camera. ' + rawMessage;
    }

    function promptCameraAccess(readerId, onContinue) {
        var isMobile = /Mobi|Android|iPhone/i.test(navigator.userAgent);
        var reader = document.getElementById(readerId);
        if (!reader || !isMobile) {
            onContinue();
            return;
        }

        var existingPrompt = document.getElementById(readerId + '_permission_prompt');
        if (existingPrompt) {
            existingPrompt.remove();
        }

        reader.style.position = 'relative';
        var overlay = document.createElement('div');
        overlay.id = readerId + '_permission_prompt';
        overlay.style.position = 'absolute';
        overlay.style.inset = '0';
        overlay.style.background = 'rgba(0,0,0,0.78)';
        overlay.style.color = '#fff';
        overlay.style.display = 'flex';
        overlay.style.flexDirection = 'column';
        overlay.style.alignItems = 'center';
        overlay.style.justifyContent = 'center';
        overlay.style.padding = '16px';
        overlay.style.textAlign = 'center';
        overlay.style.zIndex = '10';
        overlay.style.borderRadius = '8px';
        overlay.innerHTML = '<div style="max-width:260px; line-height:1.4;"><strong style="font-size:15px;">Allow camera access</strong><div style="margin:10px 0 12px; font-size:13px;">Tap Continue and allow camera access when your browser asks.</div><button type="button" style="padding:8px 16px; border:none; border-radius:8px; background:#667eea; color:#fff; font-weight:600;">Continue</button></div>';

        var continueBtn = overlay.querySelector('button');
        continueBtn.addEventListener('click', function() {
            overlay.remove();
            onContinue();
        });

        reader.appendChild(overlay);
    }

    function startCameraWithFallback(readerId, successCb, onStarted, onError, mirrored) {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            onError(new Error('Camera not supported on this device'));
            return Promise.reject(new Error('Camera not supported'));
        }

        var isMobile = /Mobi|Android|iPhone/i.test(navigator.userAgent);
        
        var cameraOptions = isMobile ? [
            { facingMode: { exact: 'environment' } },
            { facingMode: 'environment' },
            { facingMode: 'user' }
        ] : [
            { facingMode: { ideal: 'user' } },
            { facingMode: { ideal: 'environment' } },
            { facingMode: 'user' },
            { facingMode: 'environment' }
        ];

        stopActiveCameraStream();

        var attempt = 0;
        var isSettled = false;
        var scannerInstance = null;

        function finishWithError(err) {
            if (!isSettled) {
                isSettled = true;
                onError(err);
            }
        }

        async function tryStartMirrorCorrected() {
            if (isSettled) return null;
            var candidate = cameraOptions[attempt];
            var reader = document.getElementById(readerId);
            if (!reader) {
                finishWithError(new Error('Scanner container not found'));
                return null;
            }

            try {
                var stream = await navigator.mediaDevices.getUserMedia({
                    video: Object.assign({}, candidate, { width: { ideal: 640 }, height: { ideal: 480 } }),
                    audio: false
                });

                var videoEl = document.createElement('video');
                videoEl.autoplay = true;
                videoEl.playsInline = true;
                videoEl.muted = true;
                videoEl.style.width = '100%';
                videoEl.style.maxWidth = '400px';
                videoEl.style.borderRadius = '8px';
                videoEl.style.background = '#000';
                videoEl.style.display = 'block';
                reader.innerHTML = '';
                reader.appendChild(videoEl);

                videoEl.srcObject = stream;
                await videoEl.play().catch(function() {});

                activeCameraStream = stream;
                activeCameraVideo = videoEl;
                isSettled = true;
                onStarted(candidate);

                var canvas = document.createElement('canvas');
                var ctx = canvas.getContext('2d');

                var hiddenDecodeDiv = document.getElementById(readerId + '_mirrorDecodeArea');
                if (!hiddenDecodeDiv) {
                    hiddenDecodeDiv = document.createElement('div');
                    hiddenDecodeDiv.id = readerId + '_mirrorDecodeArea';
                    hiddenDecodeDiv.style.display = 'none';
                    document.body.appendChild(hiddenDecodeDiv);
                }
                var decodeScanner = new window.Html5Qrcode(hiddenDecodeDiv.id, { verbose: false });

                var stopped = false;
                var decoding = false;

                function frameToBlob(flip) {
                    if (!videoEl.videoWidth || !videoEl.videoHeight) {
                        return Promise.resolve(null);
                    }
                    canvas.width = videoEl.videoWidth;
                    canvas.height = videoEl.videoHeight;
                    ctx.save();
                    if (flip) {
                        ctx.translate(canvas.width, 0);
                        ctx.scale(-1, 1);
                    }
                    ctx.drawImage(videoEl, 0, 0, canvas.width, canvas.height);
                    ctx.restore();
                    return new Promise(function(resolve) {
                        canvas.toBlob(function(blob) { resolve(blob); }, 'image/png');
                    });
                }

                async function tryDecodeFrame(flip) {
                    var blob = await frameToBlob(flip);
                    if (!blob) return null;
                    var file = new File([blob], 'frame.png', { type: 'image/png' });
                    try {
                        var text = await decodeScanner.scanFile(file, false);
                        return text || null;
                    } catch (e) {
                        return null;
                    }
                }

                async function decodeLoop() {
                    if (stopped || !activeCameraStream) return;
                    if (!decoding) {
                        decoding = true;
                        try {
                            var text = await tryDecodeFrame(false);
                            if (!text && !stopped) {
                                text = await tryDecodeFrame(true);
                            }
                            if (text && !stopped) {
                                stopped = true;
                                stopActiveCameraStream();
                                decodeScanner.clear().catch(function() {});
                                successCb(text, null);
                                return;
                            }
                        } catch (e) {}
                        decoding = false;
                    }
                    if (!stopped && activeCameraStream) {
                        setTimeout(decodeLoop, 350);
                    }
                }

                decodeLoop();

                scannerInstance = {
                    stop: function() {
                        stopped = true;
                        try { decodeScanner.clear(); } catch (e) {}
                        return Promise.resolve();
                    }
                };
                return scannerInstance;
            } catch (err) {
                if (attempt < cameraOptions.length - 1) {
                    attempt += 1;
                    return new Promise(function(resolve) {
                        setTimeout(function() {
                            if (!isSettled) {
                                resolve(tryStartMirrorCorrected());
                            } else {
                                resolve(null);
                            }
                        }, 250);
                    });
                } else {
                    finishWithError(err);
                    return null;
                }
            }
        }

        if (mirrored) {
            return tryStartMirrorCorrected();
        }

        async function tryStart() {
            if (isSettled) return null;

            var candidate = cameraOptions[attempt];
            var reader = document.getElementById(readerId);
            if (!reader) {
                finishWithError(new Error('Scanner container not found'));
                return null;
            }

            try {
                if (typeof window !== 'undefined' && window.Html5Qrcode && window.Html5QrcodeSupportedFormats) {
                    var scanner = new window.Html5Qrcode(readerId);
                    
                    var fps = isMobile ? 5 : 10;
                    var qrboxSize = isMobile ? { width: 180, height: 180 } : { width: 220, height: 220 };
                    
                    var config = {
                        fps: fps,
                        qrbox: qrboxSize,
                        aspectRatio: 1.0,
                        formatsToSupport: [
                            window.Html5QrcodeSupportedFormats.QR_CODE,
                            window.Html5QrcodeSupportedFormats.EAN_13,
                            window.Html5QrcodeSupportedFormats.EAN_8,
                            window.Html5QrcodeSupportedFormats.UPC_A,
                            window.Html5QrcodeSupportedFormats.UPC_E,
                            window.Html5QrcodeSupportedFormats.CODE_128,
                            window.Html5QrcodeSupportedFormats.CODE_39,
                            window.Html5QrcodeSupportedFormats.CODE_93,
                            window.Html5QrcodeSupportedFormats.ITF,
                            window.Html5QrcodeSupportedFormats.CODABAR
                        ]
                    };

                    await scanner.start(
                        candidate, 
                        config, 
                        function(decodedText) {
                            stopActiveCameraStream();
                            successCb(decodedText, null);
                        }, 
                        function() {}
                    );

                    isSettled = true;
                    onStarted(candidate);
                    scannerInstance = scanner;
                    return scanner;
                }

                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    throw new Error('This browser does not support camera access.');
                }

                var stream = await navigator.mediaDevices.getUserMedia({ 
                    video: { 
                        ...candidate,
                        width: { ideal: 640 },
                        height: { ideal: 480 }
                    }, 
                    audio: false 
                });
                
                var videoEl = document.createElement('video');
                videoEl.autoplay = true;
                videoEl.playsInline = true;
                videoEl.muted = true;
                videoEl.style.width = '100%';
                videoEl.style.maxWidth = '400px';
                videoEl.style.borderRadius = '8px';
                videoEl.style.background = '#000';
                videoEl.style.display = 'block';
                reader.innerHTML = '';
                reader.appendChild(videoEl);

                videoEl.srcObject = stream;
                await videoEl.play().catch(function() {});

                var shouldMirror = !!(candidate && ((candidate.facingMode && candidate.facingMode.ideal === 'user') || candidate.facingMode === 'user'));
                applyScannerMirror(videoEl, shouldMirror);
                activeCameraStream = stream;
                activeCameraVideo = videoEl;
                isSettled = true;
                onStarted(candidate);

                if (typeof BarcodeDetector !== 'undefined') {
                    var detector = new BarcodeDetector({
                        formats: ['qr_code', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'code_93', 'itf', 'codabar']
                    });

                    function scanFrame() {
                        if (!activeCameraStream || !activeCameraVideo || !activeCameraVideo.videoWidth) {
                            return;
                        }
                        detector.detect(activeCameraVideo).then(function(results) {
                            if (!results || !results.length || !activeCameraStream) {
                                return;
                            }
                            var detectedValue = results[0].rawValue;
                            if (detectedValue) {
                                stopActiveCameraStream();
                                successCb(detectedValue, results[0]);
                            }
                        }).catch(function() {});

                        if (activeCameraStream) {
                            activeCameraDetectionTimer = setTimeout(scanFrame, 1000);
                        }
                    }

                    scanFrame();
                }

                scannerInstance = { stop: function() { return Promise.resolve(); } };
                return scannerInstance;
            } catch (err) {
                if (attempt < cameraOptions.length - 1) {
                    attempt += 1;
                    setTimeout(function() {
                        if (!isSettled) {
                            tryStart();
                        }
                    }, 250);
                } else {
                    finishWithError(err);
                }
            }
        }

        return tryStart();
    }

    function startScanner() {
        var startBtn = document.getElementById('startScannerBtn');
        var stopBtn = document.getElementById('stopScannerBtn');
        var closeBtn = document.getElementById('closeScannerBtn');
        var resultDiv = document.getElementById('scanner_result');
        
        if (html5QrCode) {
            if (isScanning) {
                stopScanner();
            }
            html5QrCode = null;
        }
        
        startBtn.style.display = 'none';
        stopBtn.style.display = 'inline-block';
        closeBtn.style.display = 'block';
        resultDiv.style.display = 'none';
        document.getElementById('scanStatus').style.display = 'none';
        
        promptCameraAccess('scannerReader', function() {
            var mirroredCb = document.getElementById('scannerMirroredCheckbox');
            startCameraWithFallback(
                'scannerReader',
                function(decodedText) {
                    onScanSuccess(decodedText, null);
                },
                function() {
                    isScanning = true;
                    showScanStatus('info', 'Scanner started. Point camera at a QR code or barcode.');
                },
                function(err) {
                    console.error("Scanner start error:", err);
                    showScanStatus('error', getCameraErrorMessage(err));
                    startBtn.style.display = 'inline-block';
                    stopBtn.style.display = 'none';
                    closeBtn.style.display = 'none';
                    isScanning = false;
                },
                !!(mirroredCb && mirroredCb.checked)
            ).then(function(scannerInstance) {
                if (scannerInstance) {
                    html5QrCode = scannerInstance;
                }
            }).catch(function() {});
        });
    }

    function stopScanner() {
        stopScannerInstance(html5QrCode).catch(function() {});
        html5QrCode = null;
        stopActiveCameraStream();
        isScanning = false;
        document.getElementById('startScannerBtn').style.display = 'inline-block';
        document.getElementById('stopScannerBtn').style.display = 'none';
        document.getElementById('closeScannerBtn').style.display = 'none';
        showScanStatus('info', 'Scanner stopped.');
    }

    function onScanSuccess(decodedText, decodedResult) {
        if (document.getElementById('scanner_result').style.display === 'block') {
            return;
        }
        
        if (html5QrCode && isScanning) {
            html5QrCode.pause(true);
        }
        
        showScanStatus('info', 'Scanning: ' + decodedText);
        searchProductByBarcode(decodedText);
        
        setTimeout(function() {
            if (html5QrCode && isScanning) {
                html5QrCode.resume();
            }
        }, 2000);
    }

    function searchProductByBarcode(barcode) {
        if (!barcode) {
            showScanStatus('error', 'No barcode to search');
            return;
        }
        
        showScanStatus('info', 'Searching for: ' + barcode + '...');
        
        $.ajax({
            url: 'new_order.php',
            type: 'GET',
            data: { barcode_search: barcode },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var product = response.product;
                    scannedProductData = product;
                    
                    document.getElementById('scannedProductName').innerHTML = product.product_name;
                    document.getElementById('scannedProductCode').innerHTML = 'Code: ' + product.item_code;
                    document.getElementById('scannedProductStock').innerHTML = 'Stock: ' + (product.display_stock || product.available_quantity + ' units');
                    
                    document.getElementById('scanner_result').style.display = 'block';
                    showScanStatus('success', 'Product found: ' + product.product_name);
                    
                    addScannedProductToOrder();
                } else {
                    showScanStatus('error', response.message || 'Product not found');
                    setTimeout(function() {
                        document.getElementById('scanStatus').style.display = 'none';
                    }, 3000);
                }
            },
            error: function() {
                showScanStatus('error', 'Error searching for product');
            }
        });
    }

    function addScannedProductToOrder() {
        <?php if ($return_mode): ?>
        showScanStatus('warning', 'Scanner is not available in Return mode. Please use the search above.');
        return;
        <?php else: ?>
        if (!scannedProductData) {
            showScanStatus('error', 'No product scanned');
            return;
        }
        
        var product = scannedProductData;
        var storeId = product.store_item_id;
        var name = product.product_name;
        var code = product.item_code;
        var price = parseFloat(product.current_selling_price);
        var stock = parseInt(product.available_quantity);
        var sellingUnit = product.selling_unit || 'pack';
        
        if (stock > 0) {
            addToCart(storeId, name, code, price, stock, 1, sellingUnit);
            showScanStatus('success', '✅ Auto-added: ' + name + ' to cart');
            speakResponse('Added ' + name + ' to cart');
        } else {
            showScanStatus('warning', '⚠️ ' + name + ' has no stock available');
            speakResponse('Sorry, ' + name + ' is out of stock');
        }
        
        document.getElementById('scanner_result').style.display = 'none';
        setTimeout(function() {
            document.getElementById('scanStatus').style.display = 'none';
        }, 2000);
        
        scannedProductData = null;
        <?php endif; ?>
    }

    function showScanStatus(type, message) {
        var statusDiv = document.getElementById('scanStatus');
        statusDiv.style.display = 'block';
        statusDiv.className = 'scan-status ' + type;
        statusDiv.innerHTML = message;
    }

    <?php if (!$return_mode): ?>
    function manualBarcodeSearch() {
        var searchTerm = document.getElementById('manualBarcodeInput').value.trim();
        if (!searchTerm) {
            showNotification('Please enter a product name, code, or barcode.', 'warning');
            return;
        }
        
        var found = fuzzyProductSearch(searchTerm);
        
        if (found.length > 0) {
            showManualSearchResults(found);
            return;
        }
        
        findProductByBarcode(searchTerm, function(product) {
            if (product) {
                showManualSearchResults([product]);
            } else {
                showNotification('❌ No product found for: ' + searchTerm, 'error');
                document.getElementById('manualSearchResults').style.display = 'none';
            }
        });
    }

    function showManualSearchResults(products) {
        var resultsDiv = document.getElementById('manualSearchResults');
        var listDiv = document.getElementById('manualResultsList');
        
        resultsDiv.style.display = 'block';
        var html = '';
        products.forEach(function(product, index) {
            var stock = parseInt(product.available_quantity) || 0;
            html += '<div class="cart-item">' +
                        '<div class="row align-items-center">' +
                            '<div class="col-6">' +
                                '<strong>' + escapeHtml(product.product_name) + '</strong><br>' +
                                '<small>' + escapeHtml(product.item_code) + ' | Stock: ' + product.display_stock + '</small>' +
                            '</div>' +
                            '<div class="col-3">' +
                                '<strong>' + currencySymbol + ' ' + parseFloat(product.current_selling_price).toFixed(2) + '</strong>' +
                            '</div>' +
                            '<div class="col-3 text-right">' +
                                '<div class="input-group input-group-sm" style="max-width:120px; margin-left:auto;">' +
                                    '<input type="number" min="1" max="' + stock + '" value="1" id="manualSearchQty' + index + '" class="form-control form-control-sm" style="width:55px;" onclick="event.stopPropagation();">' +
                                    '<div class="input-group-append">' +
                                        '<button class="btn btn-success btn-sm" onclick="event.stopPropagation(); addProductFromManualSearch(' + index + ')">' +
                                            '<i class="fas fa-cart-plus"></i>' +
                                        '</button>' +
                                    '</div>' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
        });
        
        window.manualProductsList = products;
        listDiv.innerHTML = html;
    }

    function addProductFromManualSearch(index) {
        var product = window.manualProductsList[index];
        var storeId = product.store_item_id;
        var name = product.product_name;
        var code = product.item_code;
        var price = parseFloat(product.current_selling_price);
        var stock = parseInt(product.available_quantity);
        var sellingUnit = product.selling_unit || 'pack';

        var qtyInput = document.getElementById('manualSearchQty' + index);
        var qty = qtyInput ? parseInt(qtyInput.value, 10) : 1;
        if (isNaN(qty) || qty < 1) qty = 1;
        if (qty > stock) qty = stock;
        
        if (stock > 0) {
            addToCart(storeId, name, code, price, stock, qty, sellingUnit);
            showNotification('✅ Added ' + qty + ' ' + name + ' to cart', 'success');
            document.getElementById('manualBarcodeInput').value = '';
            document.getElementById('manualSearchResults').style.display = 'none';
            speakResponse('Added ' + qty + ' ' + name + ' to cart');
        } else {
            showNotification('⚠️ ' + name + ' has no stock available', 'warning');
            speakResponse('Sorry, ' + name + ' is out of stock');
        }
    }

    document.getElementById('manualBarcodeInput')?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            manualBarcodeSearch();
        }
    });
    <?php endif; ?>

    // ==================================================================
    // AUTOMATIC BARCODE INPUT CAPTURE (USB Scanner Fallback)
    // ==================================================================
    (function() {
        var lastInputTime = 0;
        var barcodeBuffer = '';
        var barcodeTimeout = null;
        var isProcessing = false;
        
        function processScannedBarcode(barcode) {
            <?php if ($return_mode): ?>
            if (document.getElementById('returnInvoiceSearch')) {
                document.getElementById('returnInvoiceSearch').value = barcode;
                searchOrderForReturn();
            }
            <?php else: ?>
            if (document.getElementById('scanner-tab')?.classList.contains('active')) {
                searchProductByBarcode(barcode);
            }
            <?php endif; ?>
        }
        
        document.addEventListener('keydown', function(e) {
            var target = e.target;
            var isEditableField = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA');
            var isBarcodeField = !!(isEditableField && (
                target.id?.includes('barcode') ||
                target.id === 'manualBarcodeInput' ||
                target.id === 'returnInvoiceSearch'
            ));

            if (isEditableField && !isBarcodeField) {
                return;
            }

            if (e.key === 'Enter') {
                e.preventDefault();
            }
            
            var now = Date.now();
            var timeDiff = now - lastInputTime;
            lastInputTime = now;
            
            if (e.key.length === 1 && e.key !== 'Enter' && !['Shift','Alt','Control','Meta','Tab','CapsLock','Escape','Backspace','Delete'].includes(e.key)) {
                barcodeBuffer += e.key;
                
                if (barcodeTimeout) {
                    clearTimeout(barcodeTimeout);
                }
                
                barcodeTimeout = setTimeout(function() {
                    if (barcodeBuffer.length >= 3 && !isProcessing) {
                        isProcessing = true;
                        processScannedBarcode(barcodeBuffer);
                        barcodeBuffer = '';
                        isProcessing = false;
                    } else {
                        barcodeBuffer = '';
                    }
                }, 250);
            } else if (e.key === 'Enter' && barcodeBuffer.length > 0) {
                if (barcodeTimeout) {
                    clearTimeout(barcodeTimeout);
                }
                if (!isProcessing) {
                    isProcessing = true;
                    processScannedBarcode(barcodeBuffer);
                    barcodeBuffer = '';
                    isProcessing = false;
                }
            } else {
                if (timeDiff > 200 || e.key === 'Enter') {
                    barcodeBuffer = '';
                    if (barcodeTimeout) {
                        clearTimeout(barcodeTimeout);
                        barcodeTimeout = null;
                    }
                }
            }
        });
    })();

    // ==================================================================
    // RETURN FUNCTIONS
    // ==================================================================
    <?php if ($return_mode): ?>
    function searchOrderForReturn() {
        var invoiceNumber = document.getElementById('returnInvoiceSearch').value.trim();
        if (!invoiceNumber) {
            showNotification('Please enter an invoice number.', 'warning');
            return;
        }
        
        document.getElementById('returnOrderDetails').style.display = 'none';
        document.getElementById('returnNotFound').style.display = 'none';
        
        showNotification('Searching for order...', 'info');
        
        $.ajax({
            url: 'new_order.php',
            type: 'GET',
            data: { get_order_for_return: invoiceNumber },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var order = response.order;
                    
                    document.getElementById('returnInvoiceNumber').textContent = order.invoice_number;
                    document.getElementById('returnCustomerName').textContent = order.customer_name;
                    document.getElementById('returnCustomerPhone').textContent = order.phone || 'N/A';
                    document.getElementById('returnOrderDate').textContent = order.order_date ? new Date(order.order_date).toLocaleString() : 'N/A';
                    document.getElementById('returnOrderTotal').textContent = '<?php echo $currency_symbol; ?> ' + parseFloat(order.total_amount).toFixed(2);
                    document.getElementById('returnOrderId').value = order.id;
                    
                    var itemsHtml = '';
                    var currencySymbol = '<?php echo $currency_symbol; ?>';
                    var totalReturn = 0;
                    
                    order.items.forEach(function(item, index) {
                        var maxQty = parseInt(item.quantity);
                        var itemTotal = parseFloat(item.price_per_product) * maxQty;
                        totalReturn += itemTotal;
                        
                        itemsHtml += '<div class="return-item">' +
                            '<div class="row">' +
                                '<div class="col-md-4">' +
                                    '<strong>' + escapeHtml(item.product_name) + '</strong><br>' +
                                    '<small>' + escapeHtml(item.item_code) + '</small>' +
                                '</div>' +
                                '<div class="col-md-3">' +
                                    '<strong>Unit Price:</strong> ' + currencySymbol + ' ' + parseFloat(item.price_per_product).toFixed(2) +
                                '</div>' +
                                '<div class="col-md-3">' +
                                    '<label>Quantity to Return:</label>' +
                                    '<input type="number" name="return_items[' + index + '][order_item_id]" value="' + item.id + '" hidden>' +
                                    '<input type="number" name="return_items[' + index + '][quantity]" class="form-control return-qty" min="1" max="' + maxQty + '" value="' + Math.min(1, maxQty) + '" onchange="updateReturnTotal()">' +
                                    '<small>Max: ' + maxQty + '</small>' +
                                '</div>' +
                                '<div class="col-md-2 text-right">' +
                                    '<strong>Total:</strong> ' + currencySymbol + ' ' + itemTotal.toFixed(2) +
                                '</div>' +
                            '</div>' +
                        '</div>';
                    });
                    
                    document.getElementById('returnItemsList').innerHTML = itemsHtml;
                    document.getElementById('returnOrderDetails').style.display = 'block';
                    document.getElementById('returnNotFound').style.display = 'none';
                    
                    showNotification('Order found! Select items to return.', 'success');
                    
                } else {
                    document.getElementById('returnOrderDetails').style.display = 'none';
                    document.getElementById('returnNotFound').style.display = 'block';
                    showNotification(response.message || 'Order not found.', 'error');
                }
            },
            error: function() {
                document.getElementById('returnOrderDetails').style.display = 'none';
                document.getElementById('returnNotFound').style.display = 'block';
                showNotification('Error searching for order.', 'error');
            }
        });
    }

    function clearReturnSearch() {
        document.getElementById('returnInvoiceSearch').value = '';
        document.getElementById('returnOrderDetails').style.display = 'none';
        document.getElementById('returnNotFound').style.display = 'none';
    }

    document.getElementById('returnInvoiceSearch')?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchOrderForReturn();
        }
    });

    <?php endif; ?>

    // ==================================================================
    // PAGE LOAD INITIALIZATION
    // ==================================================================
    document.addEventListener('DOMContentLoaded', function() {
        <?php if (!$return_mode): ?>
        var discountType = document.getElementById('discount_type');
        if (discountType && discountType.value === 'percentage') {
            document.getElementById('discount_amount').style.display = 'none';
            document.getElementById('discount_percentage').style.display = 'block';
            var label = document.getElementById('discountLabel');
            if (label) label.innerHTML = 'Discount Percentage (%)';
        }

        updateTotals();
        <?php endif; ?>
        
        if (document.getElementById('payment_cash_amount')) {
            setTimeout(updatePaymentTotal, 100);
        }
        
        var savedTab = null;
        try {
            savedTab = localStorage.getItem('activeInputTab');
        } catch (e) {}
        try {
            if (savedTab && document.querySelector('[data-tab="' + savedTab + '"]')) {
                switchTab(savedTab);
            } else {
                switchTab('scanner-tab');
            }
        } catch (e) {
            try { switchTab('scanner-tab'); } catch (e2) {}
        }
        
        <?php if (!$return_mode): ?>
        var initialCustomerId = '<?php echo $selected_customer_id; ?>';
        if (initialCustomerId && customerSelect) {
            customerSelect.value = initialCustomerId;
            updateCustomerFromSelect();
        }
        <?php endif; ?>
        
        document.querySelectorAll('[data-bs-dismiss="modal"]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var modal = this.closest('.modal');
                if (modal) {
                    var instance = bootstrap.Modal.getInstance(modal);
                    if (instance) instance.hide();
                }
            });
        });
        
        <?php if ($return_mode): ?>
        console.log('🔄 Return Mode Loaded - Enter an invoice number to process a return');
        <?php else: ?>
        console.log('✅ Voice recognition ready - Click the microphone button and speak a product name');
        console.log('📦 Products available for voice search:', allProducts.length);
        console.log('👤 Customers loaded:', allCustomers.length);
        <?php endif; ?>
        
        var isSecure = window.location.protocol === 'https:' || window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
        if (!isSecure) {
            document.getElementById('micPermissionGuide').style.display = 'block';
            document.getElementById('micPermissionGuide').innerHTML = '<strong><i class="fas fa-exclamation-triangle"></i> HTTPS Required for Voice Input</strong><br>' +
                '<span style="font-size:13px;">Voice recognition requires HTTPS or localhost. Please use a secure connection.</span>' +
                '<br><span style="font-size:12px;color:#856404;">USB barcode scanner will still work on HTTP.</span>' +
                '<button class="btn btn-sm btn-warning mt-2" onclick="this.parentElement.style.display=\'none\'"><i class="fas fa-times"></i> Dismiss</button>';
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            var openModal = document.querySelector('.modal.show');
            if (openModal) {
                var instance = bootstrap.Modal.getInstance(openModal);
                if (instance) instance.hide();
            }
        }
    });

    console.log('✅ System Loaded Successfully');
    <?php if ($return_mode): ?>
    console.log('🔄 Mode: RETURN');
    <?php else: ?>
    console.log('🔄 Mode: SALES');
    <?php endif; ?>
    </script>
</body>
</html>

<?php
// warehouse_transfer_basic.php - FULL VOICE-FILLED CRUD with Tenant-Specific SKU
// ALL functionalities intact: CRUD, Stock Management, Barcode Scanner, History, Alerts
// SKU is now unique PER TENANT, not global

session_start();
include '../db_connect.php';
include 'check_subscription.php';
require_once __DIR__ . '/includes/dashboard_route.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$tenant_id = $_SESSION['tenant_id'];
$company_name = $_SESSION['company_name'];
$product_category = $_SESSION['product_category'];
$user_id = $_SESSION['user_id'];

$plan_stmt = $conn->prepare("SELECT product_category FROM tenants WHERE id = ?");
$plan_stmt->bind_param("i", $tenant_id);
$plan_stmt->execute();
$plan_result = $plan_stmt->get_result();
if ($plan_row = $plan_result->fetch_assoc()) {
    $product_category = $plan_row['product_category'] ?? $product_category;
}
$plan_stmt->close();
$dashboard_home_url = getDashboardHomeUrl($product_category);

// Check subscription expiry
$subscription_status = checkSubscriptionExpiry($conn, $tenant_id);

// Get currency symbol from settings
$currency_symbol = '₦';
$stmt = $conn->prepare("SELECT setting_value FROM business_settings WHERE tenant_id = ? AND setting_key = 'currency_symbol'");
$stmt->bind_param("i", $tenant_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $currency_symbol = $row['setting_value'];
}
$stmt->close();

// Get date format from settings
$date_format = 'Y-m-d';
$stmt = $conn->prepare("SELECT setting_value FROM business_settings WHERE tenant_id = ? AND setting_key = 'date_format'");
$stmt->bind_param("i", $tenant_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $date_format = $row['setting_value'];
}
$stmt->close();

// Check if multi-user mode is enabled
$multi_user_mode = 'disabled';
$stmt = $conn->prepare("SELECT setting_value FROM business_settings WHERE tenant_id = ? AND setting_key = 'multi_user_mode'");
$stmt->bind_param("i", $tenant_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $multi_user_mode = $row['setting_value'];
}
$stmt->close();

// ========== ENHANCED VOICE SEARCH ENDPOINT ==========
if (isset($_GET['voice_search'])) {
    $query = trim($_GET['voice_search'] ?? '');
    $search_type = $_GET['search_type'] ?? 'all';
    $search_mode = $_GET['search_mode'] ?? 'product';
    $search_method = $_GET['search_method'] ?? 'auto';
    
    if (!empty($query)) {
        $results = [];
        $query_lower = strtolower($query);
        $query_clean = preg_replace('/[^a-zA-Z0-9\s]/', '', $query);
        $query_metaphone = metaphone($query_clean);
        $query_soundex = soundex($query_clean);
        $query_words = explode(' ', strtolower($query_clean));
        
        if ($search_type == 'product' || $search_type == 'all') {
            $stmt = $conn->prepare("SELECT id, item_code, name, sqpp, pp_price, s_price, selling_unit, is_pack_product, selling_price_per_unit, barcode
                                    FROM product_items 
                                    WHERE tenant_id = ? AND (name LIKE ? OR item_code LIKE ? OR barcode LIKE ?)
                                    ORDER BY name ASC
                                    LIMIT 20");
            $search_term = "%{$query}%";
            $stmt->bind_param("isss", $tenant_id, $search_term, $search_term, $search_term);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $row['type'] = 'product';
                $row['selling_price'] = $row['s_price'];
                $row['pack_cost_price'] = $row['pp_price'];
                $row['pack_quantity'] = $row['sqpp'] ?? 1;
                $row['match_score'] = 100;
                $row['match_type'] = 'exact';
                $results[] = $row;
            }
            $stmt->close();
            
            // If no exact matches, try phonetic
            if (empty($results)) {
                $stmt = $conn->prepare("SELECT id, item_code, name, sqpp, pp_price, s_price, selling_unit, is_pack_product, selling_price_per_unit, barcode
                                        FROM product_items 
                                        WHERE tenant_id = ?
                                        ORDER BY name ASC");
                $stmt->bind_param("i", $tenant_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                while ($row = $result->fetch_assoc()) {
                    $name_clean = preg_replace('/[^a-zA-Z0-9\s]/', '', $row['name']);
                    $name_metaphone = metaphone($name_clean);
                    $name_soundex = soundex($name_clean);
                    $name_lower = strtolower($row['name']);
                    
                    $score = 0;
                    $match_type = 'phonetic';
                    
                    if ($name_soundex == $query_soundex && !empty($query_soundex)) { $score += 40; }
                    if ($name_metaphone == $query_metaphone && !empty($query_metaphone)) { $score += 30; }
                    
                    foreach ($query_words as $word) {
                        if (strlen($word) > 1 && strpos($name_lower, $word) !== false) { $score += 15; }
                        $dist = levenshtein($word, $name_lower);
                        if ($dist <= 3 && strlen($word) > 2) { $score += 10 - $dist; }
                    }
                    
                    if (!empty($row['barcode']) && strpos($row['barcode'], $query) !== false) {
                        $score += 50;
                        $match_type = 'barcode';
                    }
                    
                    if ($score > 0) {
                        $row['type'] = 'product';
                        $row['selling_price'] = $row['s_price'];
                        $row['pack_cost_price'] = $row['pp_price'];
                        $row['pack_quantity'] = $row['sqpp'] ?? 1;
                        $row['match_score'] = $score;
                        $row['match_type'] = $match_type;
                        $results[] = $row;
                    }
                }
                $stmt->close();
                
                usort($results, function($a, $b) {
                    return ($b['match_score'] ?? 0) - ($a['match_score'] ?? 0);
                });
                $results = array_slice($results, 0, 20);
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'results' => $results, 
            'query' => $query,
            'mode' => $search_mode,
            'method' => $search_method
        ]);
        exit();
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No search query provided']);
    exit();
}

// ========== AUTOMATIC BARCODE INPUT CAPTURE HANDLER ==========
if (isset($_POST['auto_barcode_capture'])) {
    $barcode = trim($_POST['auto_barcode_capture']);
    $source = $_POST['source'] ?? 'main';
    $action = $_POST['action'] ?? 'search';
    
    $barcode_original = $barcode;
    $barcode_reversed = strrev($barcode);
    
    $response = ['success' => false, 'message' => 'No product found', 'data' => null];
    $found = false;
    
    if (!empty($barcode)) {
        // Try original barcode first
        $stmt = $conn->prepare("SELECT id, name, s_price, pp_price, sqpp, selling_unit, is_pack_product, selling_price_per_unit, low_stock_threshold, expiry_notification_days, item_code, barcode
                                FROM product_items 
                                WHERE barcode = ? AND tenant_id = ?");
        $stmt->bind_param("si", $barcode, $tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $row['selling_price'] = $row['s_price'];
            $row['pack_cost_price'] = $row['pp_price'];
            $response = ['success' => true, 'message' => 'Product found', 'data' => $row, 'type' => 'product'];
            $found = true;
            $stmt->close();
        } else {
            $stmt->close();
            // Try reversed barcode
            $stmt = $conn->prepare("SELECT id, name, s_price, pp_price, sqpp, selling_unit, is_pack_product, selling_price_per_unit, low_stock_threshold, expiry_notification_days, item_code, barcode
                                    FROM product_items 
                                    WHERE barcode = ? AND tenant_id = ?");
            $stmt->bind_param("si", $barcode_reversed, $tenant_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $row['selling_price'] = $row['s_price'];
                $row['pack_cost_price'] = $row['pp_price'];
                $response = ['success' => true, 'message' => 'Product found (reversed barcode)', 'data' => $row, 'type' => 'product'];
                $found = true;
                $stmt->close();
            } else {
                $stmt->close();
            }
        }
        
        if ($found) {
            if ($source === 'main' || $source === 'add_stock') {
                $_SESSION['auto_scanned_product'] = $response['data'];
            }
            header('Content-Type: application/json');
            echo json_encode($response);
            exit();
        }
        
        // If not found in products, search in inventory
        $stmt = $conn->prepare("SELECT fgs.id, fgs.product_id, fgs.product_name, fgs.quantity, fgs.price_per_product, 
                         fgs.cost_price, fgs.batch_code, fgs.selling_unit, fgs.pack_quantity, 
                         fgs.expiry_date, fgs.barcode, fgs.status,
                         pi.low_stock_threshold, pi.expiry_notification_days
                  FROM finished_goods_store fgs
                  LEFT JOIN product_items pi ON fgs.product_id = pi.id
                  WHERE fgs.barcode = ? AND fgs.tenant_id = ?");
        $stmt->bind_param("si", $barcode, $tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $row['total_selling_value'] = (float)$row['quantity'] * (float)$row['price_per_product'];
            $response = ['success' => true, 'message' => 'Inventory item found', 'data' => $row, 'type' => 'inventory'];
            $found = true;
            $stmt->close();
            
            if ($source === 'inventory') {
                $_SESSION['auto_scanned_inventory'] = $row;
            }
            
            header('Content-Type: application/json');
            echo json_encode($response);
            exit();
        }
        $stmt->close();
        
        // Try reversed barcode for inventory
        $stmt = $conn->prepare("SELECT fgs.id, fgs.product_id, fgs.product_name, fgs.quantity, fgs.price_per_product, 
                         fgs.cost_price, fgs.batch_code, fgs.selling_unit, fgs.pack_quantity, 
                         fgs.expiry_date, fgs.barcode, fgs.status,
                         pi.low_stock_threshold, pi.expiry_notification_days
                  FROM finished_goods_store fgs
                  LEFT JOIN product_items pi ON fgs.product_id = pi.id
                  WHERE fgs.barcode = ? AND fgs.tenant_id = ?");
        $stmt->bind_param("si", $barcode_reversed, $tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $row['total_selling_value'] = (float)$row['quantity'] * (float)$row['price_per_product'];
            $response = ['success' => true, 'message' => 'Inventory item found (reversed barcode)', 'data' => $row, 'type' => 'inventory'];
            $found = true;
            $stmt->close();
            
            if ($source === 'inventory') {
                $_SESSION['auto_scanned_inventory'] = $row;
            }
            
            header('Content-Type: application/json');
            echo json_encode($response);
            exit();
        }
        $stmt->close();
        
        $response = ['success' => false, 'message' => 'Product not found with barcode: ' . $barcode, 'data' => null];
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// ========== BARCODE SEARCH ENDPOINT ==========
if (isset($_GET['barcode_search'])) {
    $barcode = trim($_GET['barcode_search']);
    if (!empty($barcode)) {
        // Try original barcode
        $stmt = $conn->prepare("SELECT id, name, s_price, pp_price, sqpp, selling_unit, is_pack_product, selling_price_per_unit, low_stock_threshold, expiry_notification_days, item_code, barcode
                                FROM product_items 
                                WHERE barcode = ? AND tenant_id = ?");
        $stmt->bind_param("si", $barcode, $tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $row['selling_price'] = $row['s_price'];
            $row['pack_cost_price'] = $row['pp_price'];
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'product' => $row]);
            exit();
        }
        $stmt->close();
        
        // Try reversed barcode
        $barcode_reversed = strrev($barcode);
        $stmt = $conn->prepare("SELECT id, name, s_price, pp_price, sqpp, selling_unit, is_pack_product, selling_price_per_unit, low_stock_threshold, expiry_notification_days, item_code, barcode
                                FROM product_items 
                                WHERE barcode = ? AND tenant_id = ?");
        $stmt->bind_param("si", $barcode_reversed, $tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $row['selling_price'] = $row['s_price'];
            $row['pack_cost_price'] = $row['pp_price'];
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'product' => $row, 'reversed' => true]);
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

// ========== BARCODE SEARCH FOR INVENTORY ITEMS ==========
if (isset($_GET['inventory_barcode_search'])) {
    $barcode = trim($_GET['inventory_barcode_search']);
    if (!empty($barcode)) {
        // Try original barcode
        $stmt = $conn->prepare("SELECT fgs.id, fgs.product_id, fgs.product_name, fgs.quantity, fgs.price_per_product, 
                         fgs.cost_price, fgs.batch_code, fgs.selling_unit, fgs.pack_quantity, 
                         fgs.expiry_date, fgs.barcode, fgs.status,
                         pi.low_stock_threshold, pi.expiry_notification_days
                  FROM finished_goods_store fgs
                  LEFT JOIN product_items pi ON fgs.product_id = pi.id
                  WHERE fgs.barcode = ? AND fgs.tenant_id = ?");
        $stmt->bind_param("si", $barcode, $tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $row['total_selling_value'] = (float)$row['quantity'] * (float)$row['price_per_product'];
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'inventory_item' => $row]);
            exit();
        }
        $stmt->close();
        
        // Try reversed barcode
        $barcode_reversed = strrev($barcode);
        $stmt = $conn->prepare("SELECT fgs.id, fgs.product_id, fgs.product_name, fgs.quantity, fgs.price_per_product, 
                         fgs.cost_price, fgs.batch_code, fgs.selling_unit, fgs.pack_quantity, 
                         fgs.expiry_date, fgs.barcode, fgs.status,
                         pi.low_stock_threshold, pi.expiry_notification_days
                  FROM finished_goods_store fgs
                  LEFT JOIN product_items pi ON fgs.product_id = pi.id
                  WHERE fgs.barcode = ? AND fgs.tenant_id = ?");
        $stmt->bind_param("si", $barcode_reversed, $tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $row['total_selling_value'] = (float)$row['quantity'] * (float)$row['price_per_product'];
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'inventory_item' => $row, 'reversed' => true]);
            exit();
        }
        $stmt->close();
        
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Inventory item not found']);
        exit();
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Please enter a barcode']);
        exit();
    }
}

// ================================================================
// ========== TENANT-SPECIFIC SKU GENERATION ==========
// SKU format: {TENANT_PREFIX}{PRODUCT_PREFIX}-{SEQUENCE}
// Example: T001-PRD-00001
// ================================================================
function generateSKU($conn, $product_name, $tenant_id) {
    // Get tenant code/prefix
    $tenant_prefix = 'T';
    $stmt = $conn->prepare("SELECT id, tenant_code FROM tenants WHERE id = ?");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        // Extract tenant number from ID or use a short code
        $tenant_num = str_pad((string)$tenant_id, 3, '0', STR_PAD_LEFT);
        $tenant_prefix = 'T' . $tenant_num;
    }
    $stmt->close();
    
    // Get product name prefix (first 3 letters, uppercase)
    $product_prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $product_name), 0, 3));
    if (empty($product_prefix)) {
        $product_prefix = 'PRD';
    }
    
    // Count products for this specific tenant only (NOT global)
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM product_items WHERE tenant_id = ?");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = 1;
    if ($result && $row = $result->fetch_assoc()) {
        $count = (int)$row['count'] + 1;
    }
    $stmt->close();
    
    // Generate SKU: {TENANT_PREFIX}-{PRODUCT_PREFIX}-{SEQUENCE}
    $sku = $tenant_prefix . '-' . $product_prefix . '-' . str_pad((string)$count, 5, '0', STR_PAD_LEFT);
    
    // Ensure uniqueness within this tenant
    $check_stmt = $conn->prepare("SELECT id FROM product_items WHERE tenant_id = ? AND item_code = ?");
    $check_stmt->bind_param("is", $tenant_id, $sku);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    while ($check_result && $check_result->num_rows > 0) {
        $count++;
        $sku = $tenant_prefix . '-' . $product_prefix . '-' . str_pad((string)$count, 5, '0', STR_PAD_LEFT);
        $check_stmt->close();
        $check_stmt = $conn->prepare("SELECT id FROM product_items WHERE tenant_id = ? AND item_code = ?");
        $check_stmt->bind_param("is", $tenant_id, $sku);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
    }
    $check_stmt->close();
    
    return $sku;
}

// ================================================================
// ========== ALTERNATIVE: Get tenant prefix for SKU ==========
// ================================================================
function getTenantSKUPrefix($conn, $tenant_id) {
    $stmt = $conn->prepare("SELECT id FROM tenants WHERE id = ?");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $tenant_num = str_pad((string)$tenant_id, 3, '0', STR_PAD_LEFT);
    $stmt->close();
    return 'T' . $tenant_num;
}

// Function to get all products with pack information
function getAllProducts($conn, $tenant_id) {
    $products = [];
    $stmt = $conn->prepare("SELECT id, item_code, name, sqpp, pp_price, s_price, unit_price_usd, unit_price_naira, selling_price_naira,
                     is_pack_product, selling_unit, cost_per_unit, selling_price_per_unit, 
                     low_stock_threshold, expiry_notification_days, barcode
              FROM product_items 
              WHERE tenant_id = ? 
              ORDER BY name ASC");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $row['pack_cost_price'] = (float)($row['pp_price'] ?? 0);
            $row['pack_quantity'] = isset($row['sqpp']) && $row['sqpp'] > 0 ? (int)$row['sqpp'] : 1;
            $row['selling_unit'] = isset($row['selling_unit']) ? $row['selling_unit'] : 'pack';
            $row['is_pack_product'] = isset($row['is_pack_product']) ? (int)$row['is_pack_product'] : 0;
            $row['low_stock_threshold'] = isset($row['low_stock_threshold']) ? (int)$row['low_stock_threshold'] : 5;
            $row['expiry_notification_days'] = isset($row['expiry_notification_days']) ? (int)$row['expiry_notification_days'] : 30;
            
            if ($row['is_pack_product'] && $row['selling_unit'] == 'unit') {
                $row['selling_price'] = (float)($row['selling_price_per_unit'] ?? 0);
                $row['cost_price'] = $row['pack_cost_price'] > 0 && $row['pack_quantity'] > 0 ? $row['pack_cost_price'] / $row['pack_quantity'] : 0;
                $row['calculated_selling_price_per_unit'] = $row['selling_price'];
                $row['display_selling_price'] = $row['selling_price'] . ' per unit';
                $row['profit_per_unit'] = $row['selling_price'] - $row['cost_price'];
            } else {
                $row['selling_price'] = (float)($row['s_price'] ?? 0) > 0 ? (float)$row['s_price'] : ((float)$row['selling_price_naira'] ?? 0);
                $row['cost_price'] = $row['pack_cost_price'];
                $row['calculated_selling_price_per_unit'] = $row['selling_price'];
                $row['display_selling_price'] = $row['selling_price'] . ' per pack';
                $row['profit_per_pack'] = $row['selling_price'] - $row['pack_cost_price'];
            }
            
            $products[] = $row;
        }
    }
    $stmt->close();
    return $products;
}

// Function to get finished goods store items
function getFinishedGoodsStore($conn, $tenant_id) {
    $items = [];
    $stmt = $conn->prepare("SELECT fgs.id, fgs.product_id, fgs.product_name, fgs.batch_code, fgs.status, 
                     fgs.quantity, fgs.price_per_product, fgs.transfer_date, 
                     fgs.selling_unit, fgs.pack_quantity, fgs.cost_price,
                     fgs.adjustment_reason, fgs.adjusted_by, fgs.adjusted_at, 
                     fgs.expiry_date, fgs.barcode,
                     pi.low_stock_threshold, pi.expiry_notification_days
              FROM finished_goods_store fgs
              LEFT JOIN product_items pi ON fgs.product_id = pi.id
              WHERE fgs.tenant_id = ? 
              ORDER BY fgs.id DESC");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $selling_unit = $row['selling_unit'] ?? 'pack';
            $pack_quantity = (int)($row['pack_quantity'] ?? 1);
            $quantity_in_units = (float)$row['quantity'];
            
            $row['total_selling_value'] = (float)$quantity_in_units * (float)$row['price_per_product'];
            $row['low_stock_threshold'] = isset($row['low_stock_threshold']) ? (int)$row['low_stock_threshold'] : 5;
            $row['expiry_notification_days'] = isset($row['expiry_notification_days']) ? (int)$row['expiry_notification_days'] : 30;
            
            if (isset($row['cost_price']) && $row['cost_price'] > 0) {
                $row['total_cost_value'] = (float)$quantity_in_units * (float)$row['cost_price'];
                $row['profit_per_unit'] = (float)$row['price_per_product'] - (float)$row['cost_price'];
                $row['total_profit'] = (float)$quantity_in_units * $row['profit_per_unit'];
                $row['profit_margin'] = ((float)$row['price_per_product'] > 0) ? ($row['profit_per_unit'] / (float)$row['price_per_product']) * 100 : 0;
            } else {
                $row['total_cost_value'] = 0;
                $row['profit_per_unit'] = 0;
                $row['total_profit'] = 0;
                $row['profit_margin'] = 0;
            }
            
            if ($selling_unit == 'unit') {
                $total_units = (int)$quantity_in_units;
                $row['display_quantity'] = number_format($total_units) . ' units';
                $row['stock_unit'] = 'units';
                $row['equivalent_units'] = $total_units;
                $row['cost_per_unit'] = $row['cost_price'];
                $row['selling_price_per_unit'] = $row['price_per_product'];
                $row['display_price'] = $row['price_per_product'] . ' per unit';
                $row['is_low_stock'] = ($total_units <= $row['low_stock_threshold'] && $total_units > 0);
            } else {
                $total_units = (int)$quantity_in_units;
                $total_packs = floor($total_units / $pack_quantity);
                $remaining_units = $total_units % $pack_quantity;
                
                if ($total_packs > 0 && $remaining_units > 0) {
                    $row['display_quantity'] = $total_packs . ' packs + ' . $remaining_units . ' units (' . number_format($total_units) . ' total units)';
                } elseif ($total_packs > 0) {
                    $row['display_quantity'] = $total_packs . ' packs (' . number_format($total_units) . ' units)';
                } else {
                    $row['display_quantity'] = $remaining_units . ' units';
                }
                
                $row['stock_unit'] = 'packs';
                $row['equivalent_units'] = $total_units;
                $row['cost_per_unit'] = $row['cost_price'];
                $row['selling_price_per_unit'] = $row['price_per_product'];
                $row['display_price'] = ($row['price_per_product'] * $pack_quantity) . ' per pack';
                $row['is_low_stock'] = ($total_packs <= $row['low_stock_threshold'] && $total_packs > 0);
            }
            
            if (!empty($row['expiry_date'])) {
                $days_until_expiry = (strtotime($row['expiry_date']) - time()) / 86400;
                $row['days_until_expiry'] = ceil($days_until_expiry);
                
                if ($days_until_expiry <= 0) {
                    $row['expired'] = true;
                    $row['expiry_warning'] = true;
                } elseif ($days_until_expiry <= $row['expiry_notification_days']) {
                    $row['expiry_warning'] = true;
                    $row['notification_due'] = true;
                }
            } else {
                $row['expired'] = false;
                $row['expiry_warning'] = false;
                $row['days_until_expiry'] = null;
                $row['notification_due'] = false;
            }
            
            $items[] = $row;
        }
    }
    $stmt->close();
    return $items;
}

// Function to get inventory history
function getInventoryHistory($conn, $tenant_id) {
    $history = [];
    $stmt = $conn->prepare("SELECT * FROM inventory_history 
              WHERE tenant_id = ? 
              ORDER BY created_at DESC 
              LIMIT 500");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $history[] = $row;
        }
    }
    $stmt->close();
    return $history;
}

function getLowStockItems($conn, $tenant_id) {
    $stmt = $conn->prepare("SELECT fgs.id, fgs.product_id, fgs.product_name, fgs.quantity, fgs.price_per_product, 
                     fgs.selling_unit, fgs.pack_quantity, fgs.cost_price, 
                     fgs.expiry_date, fgs.barcode,
                     pi.low_stock_threshold
              FROM finished_goods_store fgs
              LEFT JOIN product_items pi ON fgs.product_id = pi.id
              WHERE fgs.tenant_id = ? AND fgs.quantity > 0 
              ORDER BY fgs.quantity ASC");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $selling_unit = $row['selling_unit'] ?? 'pack';
            $pack_quantity = (int)($row['pack_quantity'] ?? 1);
            $quantity_in_units = (float)$row['quantity'];
            $threshold = isset($row['low_stock_threshold']) ? (int)$row['low_stock_threshold'] : 5;
            
            if ($selling_unit == 'unit') {
                $total_units = (int)$quantity_in_units;
                $row['display_quantity'] = number_format($total_units) . ' units';
                $row['is_low_stock'] = ($total_units <= $threshold && $total_units > 0);
                $row['threshold_display'] = $threshold . ' units';
                
                if ($row['is_low_stock']) {
                    $items[] = $row;
                }
            } else {
                $total_units = (int)$quantity_in_units;
                $total_packs = floor($total_units / $pack_quantity);
                $remaining_units = $total_units % $pack_quantity;
                
                if ($total_packs > 0 && $remaining_units > 0) {
                    $row['display_quantity'] = $total_packs . ' packs + ' . $remaining_units . ' units';
                } elseif ($total_packs > 0) {
                    $row['display_quantity'] = $total_packs . ' packs';
                } else {
                    $row['display_quantity'] = $remaining_units . ' units';
                }
                
                $row['is_low_stock'] = ($total_packs <= $threshold && $total_packs > 0);
                $row['threshold_display'] = $threshold . ' packs';
                
                if ($row['is_low_stock']) {
                    $items[] = $row;
                }
            }
        }
    }
    $stmt->close();
    return $items;
}

function getExpiringItems($conn, $tenant_id) {
    $stmt = $conn->prepare("SELECT fgs.id, fgs.product_id, fgs.product_name, fgs.quantity, fgs.price_per_product, 
                     fgs.selling_unit, fgs.pack_quantity, fgs.expiry_date, fgs.batch_code, fgs.barcode,
                     pi.low_stock_threshold, pi.expiry_notification_days
              FROM finished_goods_store fgs
              LEFT JOIN product_items pi ON fgs.product_id = pi.id
              WHERE fgs.tenant_id = ? AND fgs.expiry_date IS NOT NULL 
              AND fgs.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL pi.expiry_notification_days DAY)
              AND fgs.quantity > 0
              ORDER BY fgs.expiry_date ASC");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $selling_unit = $row['selling_unit'] ?? 'pack';
            $pack_quantity = (int)($row['pack_quantity'] ?? 1);
            $quantity_in_units = (float)$row['quantity'];
            
            $row['low_stock_threshold'] = isset($row['low_stock_threshold']) ? (int)$row['low_stock_threshold'] : 5;
            $row['expiry_notification_days'] = isset($row['expiry_notification_days']) ? (int)$row['expiry_notification_days'] : 30;
            $days_until_expiry = (strtotime($row['expiry_date']) - time()) / 86400;
            $row['days_until_expiry'] = ceil($days_until_expiry);
            
            if ($selling_unit == 'unit') {
                $row['display_quantity'] = number_format((int)$quantity_in_units) . ' units';
            } else {
                $total_packs = floor((int)$quantity_in_units / $pack_quantity);
                $row['display_quantity'] = $total_packs . ' packs';
            }
            
            $items[] = $row;
        }
    }
    $stmt->close();
    return $items;
}

function getTotalStockValue($conn, $tenant_id) {
    $stmt = $conn->prepare("SELECT SUM(quantity * price_per_product) as total FROM finished_goods_store WHERE tenant_id = ? AND quantity > 0");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        return (float)($row['total'] ?? 0);
    }
    $stmt->close();
    return 0;
}

function getTotalCostValue($conn, $tenant_id) {
    $stmt = $conn->prepare("SELECT SUM(quantity * cost_price) as total FROM finished_goods_store WHERE tenant_id = ? AND quantity > 0");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        return (float)($row['total'] ?? 0);
    }
    $stmt->close();
    return 0;
}

function getTotalProfitValue($conn, $tenant_id) {
    $stmt = $conn->prepare("SELECT SUM((price_per_product - cost_price) * quantity) as total FROM finished_goods_store WHERE tenant_id = ? AND quantity > 0");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        return (float)($row['total'] ?? 0);
    }
    $stmt->close();
    return 0;
}

function getTotalItemsCount($conn, $tenant_id) {
    $stmt = $conn->prepare("SELECT SUM(quantity) as total FROM finished_goods_store WHERE tenant_id = ? AND quantity > 0");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        return (int)($row['total'] ?? 0);
    }
    $stmt->close();
    return 0;
}

function getTotalAvailableUnits($conn, $tenant_id) {
    $stmt = $conn->prepare("SELECT SUM(quantity) as total FROM finished_goods_store WHERE tenant_id = ? AND quantity > 0");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        return (int)($row['total'] ?? 0);
    }
    $stmt->close();
    return 0;
}

function getUniqueProductCount($conn, $tenant_id) {
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT product_name) as count FROM finished_goods_store WHERE tenant_id = ? AND quantity > 0");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        return (int)($row['count'] ?? 0);
    }
    $stmt->close();
    return 0;
}

function logInventoryHistory($conn, $tenant_id, $product_id, $product_name, $action, $old_quantity, $new_quantity, $reason, $user_id) {
    $stmt = $conn->prepare("INSERT INTO inventory_history (tenant_id, product_id, product_name, action, old_quantity, new_quantity, reason, performed_by, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("iissiisi", $tenant_id, $product_id, $product_name, $action, $old_quantity, $new_quantity, $reason, $user_id);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

// ========== CRUD: Add new product ==========
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['crud_add_product'])) {
    $product_name = trim($_POST['crud_product_name'] ?? '');
    $pack_cost_price = (float)($_POST['crud_pp_price'] ?? 0);
    $pack_selling_price = (float)($_POST['crud_s_price'] ?? 0);
    $sqpp = (int)($_POST['crud_sqpp'] ?? 1);
    $selling_unit = $_POST['crud_selling_unit'] ?? 'pack';
    $is_pack_product = isset($_POST['crud_is_pack_product']) ? 1 : 0;
    $selling_price_per_unit = (float)($_POST['crud_selling_price_per_unit'] ?? 0);
    $low_stock_threshold = (int)($_POST['crud_low_stock_threshold'] ?? 5);
    $expiry_notification_days = (int)($_POST['crud_expiry_notification_days'] ?? 30);
    $barcode = trim($_POST['crud_barcode'] ?? '');
    
    if (empty($barcode)) {
        $barcode = null;
    }
    
    if (empty($product_name)) {
        $error = "Product name is required!";
    } elseif ($is_pack_product && $selling_unit == 'unit' && $selling_price_per_unit <= 0) {
        $error = "Selling price per unit is required!";
    } elseif ($selling_unit == 'pack' && $pack_selling_price <= 0 && !$is_pack_product) {
        $error = "Selling price is required!";
    } else {
        $check_stmt = $conn->prepare("SELECT id FROM product_items WHERE name = ? AND tenant_id = ?");
        $check_stmt->bind_param("si", $product_name, $tenant_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result && $check_result->num_rows > 0) {
            $error = "Product already exists!";
        } else {
            if (!empty($barcode)) {
                $barcode_check = $conn->prepare("SELECT id FROM product_items WHERE barcode = ? AND tenant_id = ?");
                $barcode_check->bind_param("si", $barcode, $tenant_id);
                $barcode_check->execute();
                if ($barcode_check->get_result()->num_rows > 0) {
                    $error = "Barcode already exists!";
                    $barcode_check->close();
                    goto skip_product_add;
                }
                $barcode_check->close();
            }
            
            // Generate TENANT-SPECIFIC SKU
            $item_code = generateSKU($conn, $product_name, $tenant_id);
            
            if ($is_pack_product && $selling_unit == 'unit' && $sqpp > 1) {
                $stored_s_price = 0;
                $stored_selling_price_naira = 0;
                $stored_selling_price_per_unit = $selling_price_per_unit;
                $stored_cost_per_unit = $pack_cost_price / $sqpp;
            } elseif ($is_pack_product && $selling_unit == 'pack' && $sqpp > 1) {
                $stored_s_price = $pack_selling_price;
                $stored_selling_price_naira = $pack_selling_price;
                $stored_selling_price_per_unit = 0;
                $stored_cost_per_unit = $pack_cost_price / $sqpp;
            } else {
                $stored_s_price = $pack_selling_price;
                $stored_selling_price_naira = $pack_selling_price;
                $stored_selling_price_per_unit = 0;
                $stored_cost_per_unit = $pack_cost_price;
            }
            
            $unit_price_usd = 0;
            $unit_price_naira = 0;
            
            $insert_stmt = $conn->prepare("INSERT INTO product_items (tenant_id, item_code, name, sqpp, pp_price, s_price, unit_price_usd, unit_price_naira, selling_price_naira, 
                             is_pack_product, selling_unit, cost_per_unit, selling_price_per_unit, 
                             low_stock_threshold, expiry_notification_days, barcode) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $insert_stmt->bind_param("issidddddisddiis", 
                $tenant_id,
                $item_code,
                $product_name,
                $sqpp,
                $pack_cost_price,
                $stored_s_price,
                $unit_price_usd,
                $unit_price_naira,
                $stored_selling_price_naira,
                $is_pack_product,
                $selling_unit,
                $stored_cost_per_unit,
                $stored_selling_price_per_unit,
                $low_stock_threshold,
                $expiry_notification_days,
                $barcode
            );
            
            if ($insert_stmt->execute()) {
                $success = "✅ Product added successfully! SKU: $item_code" . (!empty($barcode) ? " Barcode: $barcode" : "");
            } else {
                $error = "Database Error: " . $conn->error;
            }
            $insert_stmt->close();
        }
        $check_stmt->close();
    }
    skip_product_add:;
}

// ========== CRUD: Edit product ==========
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['crud_edit_product'])) {
    $product_id = (int)($_POST['edit_product_id'] ?? 0);
    $product_name = trim($_POST['edit_product_name'] ?? '');
    $pack_cost_price = (float)($_POST['edit_pp_price'] ?? 0);
    $pack_selling_price = (float)($_POST['edit_s_price'] ?? 0);
    $sqpp = (int)($_POST['edit_sqpp'] ?? 1);
    $selling_unit = $_POST['edit_selling_unit'] ?? 'pack';
    $is_pack_product = isset($_POST['edit_is_pack_product']) ? 1 : 0;
    $selling_price_per_unit = (float)($_POST['edit_selling_price_per_unit'] ?? 0);
    $low_stock_threshold = (int)($_POST['edit_low_stock_threshold'] ?? 5);
    $expiry_notification_days = (int)($_POST['edit_expiry_notification_days'] ?? 30);
    $barcode = trim($_POST['edit_barcode'] ?? '');
    
    if (empty($barcode)) {
        $barcode = null;
    }
    
    if ($product_id <= 0 || empty($product_name)) {
        $error = "Invalid product data!";
    } else {
        if (!empty($barcode)) {
            $barcode_check = $conn->prepare("SELECT id FROM product_items WHERE barcode = ? AND tenant_id = ? AND id != ?");
            $barcode_check->bind_param("sii", $barcode, $tenant_id, $product_id);
            $barcode_check->execute();
            if ($barcode_check->get_result()->num_rows > 0) {
                $error = "Barcode already exists!";
                $barcode_check->close();
                goto skip_product_edit;
            }
            $barcode_check->close();
        }
        
        if ($is_pack_product && $selling_unit == 'unit' && $sqpp > 1) {
            $stored_s_price = 0;
            $stored_selling_price_naira = 0;
            $stored_selling_price_per_unit = $selling_price_per_unit;
            $stored_cost_per_unit = $pack_cost_price / $sqpp;
        } elseif ($is_pack_product && $selling_unit == 'pack' && $sqpp > 1) {
            $stored_s_price = $pack_selling_price;
            $stored_selling_price_naira = $pack_selling_price;
            $stored_selling_price_per_unit = 0;
            $stored_cost_per_unit = $pack_cost_price / $sqpp;
        } else {
            $stored_s_price = $pack_selling_price;
            $stored_selling_price_naira = $pack_selling_price;
            $stored_selling_price_per_unit = 0;
            $stored_cost_per_unit = $pack_cost_price;
        }
        
        $update_stmt = $conn->prepare("UPDATE product_items 
                         SET name = ?,
                             sqpp = ?,
                             pp_price = ?,
                             s_price = ?,
                             selling_price_naira = ?,
                             is_pack_product = ?,
                             selling_unit = ?,
                             cost_per_unit = ?,
                             selling_price_per_unit = ?,
                             low_stock_threshold = ?,
                             expiry_notification_days = ?,
                             barcode = ?
                         WHERE id = ? AND tenant_id = ?");
        $update_stmt->bind_param("sidddisddiisii", $product_name, $sqpp, $pack_cost_price, $stored_s_price, $stored_selling_price_naira, $is_pack_product, $selling_unit, $stored_cost_per_unit, $stored_selling_price_per_unit, $low_stock_threshold, $expiry_notification_days, $barcode, $product_id, $tenant_id);
        
        if ($update_stmt->execute()) {
            $success = "✅ Product updated successfully!";
        } else {
            $error = "Error updating product: " . $conn->error;
        }
        $update_stmt->close();
    }
    skip_product_edit:;
}

// ========== CRUD: Delete product ==========
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['crud_delete_product'])) {
    $product_id = (int)($_POST['delete_product_id'] ?? 0);
    
    if ($product_id <= 0) {
        $error = "Invalid product ID!";
    } else {
        $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM finished_goods_store WHERE product_id = ? AND tenant_id = ?");
        $check_stmt->bind_param("ii", $product_id, $tenant_id);
        $check_stmt->execute();
        $inv_result = $check_stmt->get_result();
        $has_inventory = ($inv_result && $inv_result->fetch_assoc()['count'] > 0);
        $check_stmt->close();
        
        if ($has_inventory) {
            $delete_stmt = $conn->prepare("DELETE FROM finished_goods_store WHERE product_id = ? AND tenant_id = ?");
            $delete_stmt->bind_param("ii", $product_id, $tenant_id);
            $delete_stmt->execute();
            $delete_stmt->close();
        }
        
        // Clear historical log rows tied to this product first, otherwise the
        // foreign key from inventory_history.product_id blocks the delete below.
        $history_delete_stmt = $conn->prepare("DELETE FROM inventory_history WHERE product_id = ? AND tenant_id = ?");
        $history_delete_stmt->bind_param("ii", $product_id, $tenant_id);
        $history_delete_stmt->execute();
        $history_delete_stmt->close();
        
        $delete_stmt = $conn->prepare("DELETE FROM product_items WHERE id = ? AND tenant_id = ?");
        $delete_stmt->bind_param("ii", $product_id, $tenant_id);
        if ($delete_stmt->execute()) {
            if ($delete_stmt->affected_rows > 0) {
                $success = "✅ Product and all associated inventory deleted successfully!";
            } else {
                $error = "⚠️ No product was deleted. Product ID $product_id was not found under your account — it may already be deleted, or belong to a different tenant.";
            }
        } else {
            $error = "Error deleting product: " . $conn->error;
        }
        $delete_stmt->close();
    }
}

// ====================================================================================
// Process transfer - CONVERT PACKS TO UNITS WHEN SELLING UNIT IS 'unit'
// ====================================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['transfer_stock'])) {
    $product_id = (int)($_POST['product_id'] ?? 0);
    $packs_received = (int)($_POST['quantity'] ?? 0);
    $batch_code = trim($_POST['batch_code'] ?? '');
    $selling_price = (float)($_POST['selling_price'] ?? 0);
    $cost_price = (float)($_POST['cost_price'] ?? 0);
    $expiry_date = $_POST['expiry_date'] ?? '';
    $barcode = trim($_POST['batch_barcode'] ?? '');
    
    if (empty($barcode)) {
        $barcode = null;
    }
    
    if ($product_id <= 0 || $packs_received <= 0) {
        $error = "Please select a product and enter a valid quantity!";
    } else {
        $product_stmt = $conn->prepare("SELECT id, name, s_price, pp_price, selling_price_naira, sqpp, is_pack_product, selling_unit, selling_price_per_unit, barcode
                          FROM product_items 
                          WHERE id = ? AND tenant_id = ?");
        $product_stmt->bind_param("ii", $product_id, $tenant_id);
        $product_stmt->execute();
        $product_result = $product_stmt->get_result();
        if ($product_result && $product_row = $product_result->fetch_assoc()) {
            $product_name = $product_row['name'];
            $pack_cost_price = (float)($product_row['pp_price'] ?? 0);
            $pack_quantity = isset($product_row['sqpp']) && $product_row['sqpp'] > 0 ? (int)$product_row['sqpp'] : 1;
            $is_pack_product = isset($product_row['is_pack_product']) ? (int)$product_row['is_pack_product'] : 0;
            $selling_unit = isset($product_row['selling_unit']) ? $product_row['selling_unit'] : 'pack';
            
            if (empty($barcode) && !empty($product_row['barcode'])) {
                $barcode = $product_row['barcode'];
            }
            
            if ($selling_unit == 'unit') {
                $total_units = $packs_received * $pack_quantity;
                $display_info = " (" . number_format($packs_received) . " packs × " . $pack_quantity . " units/pack = " . number_format($total_units) . " units)";
                
                $price_per_unit = ($selling_price > 0) ? $selling_price : ((float)($product_row['selling_price_per_unit'] ?? 0));
                $cost_per_unit = ($cost_price > 0) ? $cost_price : ($pack_cost_price / $pack_quantity);
                
                $store_quantity = $total_units;
                $price_per_product = $price_per_unit;
                $cost_per_product = $cost_per_unit;
            } else {
                $total_units = $packs_received;
                $display_info = " (" . number_format($packs_received) . " packs)";
                
                $price_per_pack = ($selling_price > 0) ? $selling_price : ((float)($product_row['s_price'] ?? 0));
                $cost_per_pack = ($cost_price > 0) ? $cost_price : $pack_cost_price;
                
                $store_quantity = $total_units;
                $price_per_product = $price_per_pack;
                $cost_per_product = $cost_per_pack;
            }
            
            if ($price_per_product <= 0) {
                $error = "Please enter a valid selling price!";
            } else {
                $check_stmt = $conn->prepare("SELECT id, quantity, cost_price FROM finished_goods_store WHERE tenant_id = ? AND product_id = ?");
                $check_stmt->bind_param("ii", $tenant_id, $product_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result && $check_result->num_rows > 0) {
                    $existing = $check_result->fetch_assoc();
                    $old_quantity = $existing['quantity'];
                    $new_quantity = $old_quantity + $store_quantity;
                    
                    $old_total_cost = $existing['quantity'] * $existing['cost_price'];
                    $new_total_cost = $old_total_cost + ($store_quantity * $cost_per_product);
                    $avg_cost_price = $new_total_cost / $new_quantity;
                    
                    $update_stmt = $conn->prepare("UPDATE finished_goods_store 
                                    SET quantity = ?, 
                                        price_per_product = ?,
                                        cost_price = ?,
                                        pack_quantity = ?,
                                        selling_unit = ?,
                                        expiry_date = ?,
                                        barcode = ?
                                    WHERE id = ? AND tenant_id = ?");
                    $update_stmt->bind_param("idddsssii", $new_quantity, $price_per_product, $avg_cost_price, $pack_quantity, $selling_unit, $expiry_date, $barcode, $existing['id'], $tenant_id);
                    
                    if ($update_stmt->execute()) {
                        logInventoryHistory($conn, $tenant_id, $product_id, $product_name, 'stock_in', $old_quantity, $new_quantity, "Added " . number_format($store_quantity) . " units" . $display_info . ($expiry_date ? " Expiry: $expiry_date" : "") . ($barcode ? " Barcode: $barcode" : ""), $user_id);
                        $success = "Stock updated! Added " . number_format($store_quantity) . " units" . $display_info . " of " . htmlspecialchars($product_name);
                        if ($expiry_date) {
                            $success .= " Expiry: " . date('d M Y', strtotime($expiry_date));
                        }
                        if ($barcode) {
                            $success .= " Barcode: $barcode";
                        }
                    } else {
                        $error = "Error updating stock: " . $conn->error;
                    }
                    $update_stmt->close();
                } else {
                    $insert_stmt = $conn->prepare("INSERT INTO finished_goods_store (
                        tenant_id, product_id, product_name, quantity, price_per_product, 
                        cost_price, batch_code, pack_quantity, selling_unit, status, 
                        transfer_date, expiry_date, barcode
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'transfer', NOW(), ?, ?)");
                    $insert_stmt->bind_param("iissddsisss", $tenant_id, $product_id, $product_name, $store_quantity, $price_per_product, $cost_per_product, $batch_code, $pack_quantity, $selling_unit, $expiry_date, $barcode);
                    
                    if ($insert_stmt->execute()) {
                        logInventoryHistory($conn, $tenant_id, $product_id, $product_name, 'stock_in', 0, $store_quantity, "Initial stock of " . number_format($store_quantity) . " units" . $display_info . ($expiry_date ? " Expiry: $expiry_date" : "") . ($barcode ? " Barcode: $barcode" : ""), $user_id);
                        $success = "Stock added! " . number_format($store_quantity) . " units" . $display_info . " of " . htmlspecialchars($product_name);
                        if ($expiry_date) {
                            $success .= " Expiry: " . date('d M Y', strtotime($expiry_date));
                        }
                        if ($barcode) {
                            $success .= " Barcode: $barcode";
                        }
                    } else {
                        $error = "Error: " . $conn->error;
                    }
                    $insert_stmt->close();
                }
                $check_stmt->close();
            }
        } else {
            $error = "Product not found!";
        }
        $product_stmt->close();
    }
}

// ====================================================================================
// Process expiry date edit only
// ====================================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_expiry_only'])) {
    $item_id = (int)($_POST['expiry_item_id'] ?? 0);
    $new_expiry_date = $_POST['expiry_date'] ?? '';
    $edit_reason = trim($_POST['expiry_edit_reason'] ?? '');
    
    if ($item_id <= 0) {
        $error = "Invalid item data!";
    } elseif (empty($new_expiry_date)) {
        $error = "Please select a valid expiry date!";
    } else {
        $old_stmt = $conn->prepare("SELECT product_id, product_name, quantity, expiry_date, barcode, pack_quantity, selling_unit 
                      FROM finished_goods_store WHERE id = ? AND tenant_id = ?");
        $old_stmt->bind_param("ii", $item_id, $tenant_id);
        $old_stmt->execute();
        $old_result = $old_stmt->get_result();
        if ($old_result && $old_row = $old_result->fetch_assoc()) {
            $product_id = $old_row['product_id'];
            $product_name = $old_row['product_name'];
            $quantity = $old_row['quantity'];
            $old_expiry_date = $old_row['expiry_date'] ?? null;
            $barcode = $old_row['barcode'] ?? null;
            $pack_quantity = $old_row['pack_quantity'];
            $selling_unit = $old_row['selling_unit'];
            
            $update_stmt = $conn->prepare("UPDATE finished_goods_store SET expiry_date = ? WHERE id = ? AND tenant_id = ?");
            $update_stmt->bind_param("sii", $new_expiry_date, $item_id, $tenant_id);
            
            if ($update_stmt->execute()) {
                $old_date_display = $old_expiry_date ? date('d M Y', strtotime($old_expiry_date)) : 'None';
                $new_date_display = $new_expiry_date ? date('d M Y', strtotime($new_expiry_date)) : 'None';
                
                $today = date('Y-m-d');
                $is_expired = ($new_expiry_date < $today);
                $expiry_warning = $is_expired ? ' (EXPIRED)' : '';
                
                $unit_info = "";
                if ($selling_unit == 'unit' && $pack_quantity > 1) {
                    $total_units = (int)$quantity * $pack_quantity;
                    $unit_info = " (" . number_format($total_units) . " units)";
                }
                
                $reason = "Expiry date updated: " . $old_date_display . " → " . $new_date_display . $expiry_warning . 
                         " for " . number_format($quantity) . " packs" . $unit_info . 
                         ($barcode ? " Barcode: $barcode" : "") . 
                         " - " . $edit_reason;
                
                logInventoryHistory($conn, $tenant_id, $product_id, $product_name, 'edit_expiry', $quantity, $quantity, $reason, $user_id);
                $success = "✅ Expiry date updated successfully!" . $expiry_warning;
            } else {
                $error = "Error updating expiry date: " . $conn->error;
            }
            $update_stmt->close();
        } else {
            $error = "Item not found!";
        }
        $old_stmt->close();
    }
}

// ====================================================================================
// Process stock adjustment - QUANTITY IN UNITS
// ====================================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['adjust_stock'])) {
    $item_id = (int)($_POST['item_id'] ?? 0);
    $new_quantity = (int)($_POST['new_quantity'] ?? 0);
    $adjustment_reason = trim($_POST['adjustment_reason'] ?? '');
    $adjusted_by = $_SESSION['user_id'] ?? 1;
    
    if ($item_id <= 0 || $new_quantity < 0) {
        $error = "Invalid adjustment data!";
    } else {
        $old_stmt = $conn->prepare("SELECT product_id, product_name, quantity, pack_quantity, selling_unit, expiry_date FROM finished_goods_store WHERE id = ? AND tenant_id = ?");
        $old_stmt->bind_param("ii", $item_id, $tenant_id);
        $old_stmt->execute();
        $old_result = $old_stmt->get_result();
        if ($old_result && $old_row = $old_result->fetch_assoc()) {
            $old_quantity = $old_row['quantity'];
            $product_id = $old_row['product_id'];
            $product_name = $old_row['product_name'];
            $pack_quantity = $old_row['pack_quantity'];
            $selling_unit = $old_row['selling_unit'];
            $expiry_date = $old_row['expiry_date'] ?? null;
            
            $update_stmt = $conn->prepare("UPDATE finished_goods_store 
                             SET quantity = ?, 
                                 status = 'adjusted', 
                                 adjustment_reason = ?, 
                                 adjusted_by = ?, 
                                 adjusted_at = NOW()
                             WHERE id = ? AND tenant_id = ?");
            $update_stmt->bind_param("isiii", $new_quantity, $adjustment_reason, $adjusted_by, $item_id, $tenant_id);
            
            if ($update_stmt->execute()) {
                $change_info = " (Units: $old_quantity → $new_quantity)";
                logInventoryHistory($conn, $tenant_id, $product_id, $product_name, 'adjustment', $old_quantity, $new_quantity, $adjustment_reason . $change_info, $adjusted_by);
                $success = "Stock adjusted successfully!";
            } else {
                $error = "Error: " . $conn->error;
            }
            $update_stmt->close();
        } else {
            $error = "Item not found!";
        }
        $old_stmt->close();
    }
}

// ====================================================================================
// Process edit inventory item - INCLUDES EXPIRY DATE EDIT
// ====================================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_inventory'])) {
    $item_id = (int)($_POST['edit_item_id'] ?? 0);
    $edit_quantity = (int)($_POST['edit_quantity'] ?? 0);
    $edit_selling_price = (float)($_POST['edit_selling_price'] ?? 0);
    $edit_cost_price = (float)($_POST['edit_cost_price'] ?? 0);
    $edit_batch_code = trim($_POST['edit_batch_code'] ?? '');
    $edit_reason = trim($_POST['edit_reason'] ?? '');
    $edit_expiry_date = $_POST['edit_expiry_date'] ?? '';
    $edit_barcode = trim($_POST['edit_barcode'] ?? '');
    
    if (empty($edit_barcode)) {
        $edit_barcode = null;
    }
    
    if ($item_id <= 0) {
        $error = "Invalid item data!";
    } else {
        $old_stmt = $conn->prepare("SELECT product_id, product_name, quantity, price_per_product, cost_price, pack_quantity, selling_unit, expiry_date, barcode 
                      FROM finished_goods_store WHERE id = ? AND tenant_id = ?");
        $old_stmt->bind_param("ii", $item_id, $tenant_id);
        $old_stmt->execute();
        $old_result = $old_stmt->get_result();
        if ($old_result && $old_row = $old_result->fetch_assoc()) {
            $product_id = $old_row['product_id'];
            $product_name = $old_row['product_name'];
            $old_quantity = $old_row['quantity'];
            $old_selling_price = $old_row['price_per_product'];
            $old_cost_price = $old_row['cost_price'];
            $pack_quantity = $old_row['pack_quantity'];
            $selling_unit = $old_row['selling_unit'];
            $old_expiry_date = $old_row['expiry_date'] ?? null;
            
            if (empty($edit_barcode) && !empty($old_row['barcode'])) {
                $edit_barcode = $old_row['barcode'];
            }
            
            $update_stmt = $conn->prepare("UPDATE finished_goods_store 
                             SET quantity = ?,
                                 price_per_product = ?,
                                 cost_price = ?,
                                 batch_code = ?,
                                 expiry_date = ?,
                                 barcode = ?
                             WHERE id = ? AND tenant_id = ?");
            $update_stmt->bind_param("iddsssii", $edit_quantity, $edit_selling_price, $edit_cost_price, $edit_batch_code, $edit_expiry_date, $edit_barcode, $item_id, $tenant_id);
            
            if ($update_stmt->execute()) {
                $change_details = " (Units: $old_quantity → $edit_quantity)";
                $expiry_change = "";
                if ($old_expiry_date != $edit_expiry_date) {
                    $expiry_change = " Expiry: " . ($old_expiry_date ? date('d M Y', strtotime($old_expiry_date)) : 'None') . " → " . ($edit_expiry_date ? date('d M Y', strtotime($edit_expiry_date)) : 'None');
                }
                $barcode_change = "";
                if ($old_row['barcode'] != $edit_barcode) {
                    $barcode_change = " Barcode: " . ($old_row['barcode'] ?? 'None') . " → " . ($edit_barcode ?? 'None');
                }
                $reason = "Edited: Quantity from $old_quantity to $edit_quantity$change_details, " .
                         "Selling price from " . $currency_symbol . number_format($old_selling_price, 2) . " to " . $currency_symbol . number_format($edit_selling_price, 2) . ", " .
                         "Cost price from " . $currency_symbol . number_format($old_cost_price, 2) . " to " . $currency_symbol . number_format($edit_cost_price, 2) . "." .
                         $expiry_change . $barcode_change . " " . $edit_reason;
                logInventoryHistory($conn, $tenant_id, $product_id, $product_name, 'edit', $old_quantity, $edit_quantity, $reason, $user_id);
                $success = "Inventory item updated successfully!";
            } else {
                $error = "Error: " . $conn->error;
            }
            $update_stmt->close();
        } else {
            $error = "Item not found!";
        }
        $old_stmt->close();
    }
}

// ====================================================================================
// Process delete inventory item - QUANTITY IN UNITS
// ====================================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_inventory'])) {
    $item_id = (int)($_POST['delete_item_id'] ?? 0);
    $delete_reason = trim($_POST['delete_reason'] ?? '');
    
    if ($item_id <= 0) {
        $error = "Invalid item data!";
    } else {
        $item_stmt = $conn->prepare("SELECT product_id, product_name, quantity, price_per_product, cost_price, pack_quantity, selling_unit, expiry_date, barcode
                       FROM finished_goods_store WHERE id = ? AND tenant_id = ?");
        $item_stmt->bind_param("ii", $item_id, $tenant_id);
        $item_stmt->execute();
        $item_result = $item_stmt->get_result();
        if ($item_result && $item_row = $item_result->fetch_assoc()) {
            $product_id = $item_row['product_id'];
            $product_name = $item_row['product_name'];
            $quantity = $item_row['quantity'];
            $pack_quantity = $item_row['pack_quantity'];
            $selling_unit = $item_row['selling_unit'];
            $expiry_date = $item_row['expiry_date'] ?? null;
            $barcode = $item_row['barcode'] ?? null;
            
            $delete_stmt = $conn->prepare("DELETE FROM finished_goods_store WHERE id = ? AND tenant_id = ?");
            $delete_stmt->bind_param("ii", $item_id, $tenant_id);
            if ($delete_stmt->execute()) {
                $unit_info = " (" . number_format($quantity) . " units)";
                $expiry_info = $expiry_date ? " Expiry: " . date('d M Y', strtotime($expiry_date)) : "";
                $barcode_info = $barcode ? " Barcode: $barcode" : "";
                $reason = "Deleted item: " . number_format($quantity) . " units" . $unit_info . $expiry_info . $barcode_info . " - " . $delete_reason;
                logInventoryHistory($conn, $tenant_id, $product_id, $product_name, 'deletion', $quantity, 0, $reason, $user_id);
                $success = "Inventory item deleted successfully!";
            } else {
                $error = "Error deleting item: " . $conn->error;
            }
            $delete_stmt->close();
        } else {
            $error = "Item not found!";
        }
        $item_stmt->close();
    }
}

// Get all data
$products = getAllProducts($conn, $tenant_id);
$finished_goods = getFinishedGoodsStore($conn, $tenant_id);
$inventory_history = getInventoryHistory($conn, $tenant_id);
$low_stock_items = getLowStockItems($conn, $tenant_id);
$expiring_items = getExpiringItems($conn, $tenant_id);
$total_stock_value = getTotalStockValue($conn, $tenant_id);
$total_cost_value = getTotalCostValue($conn, $tenant_id);
$total_profit_value = getTotalProfitValue($conn, $tenant_id);
$total_items = getTotalItemsCount($conn, $tenant_id);
$total_available_units = getTotalAvailableUnits($conn, $tenant_id);
$unique_products = getUniqueProductCount($conn, $tenant_id);

function safe_format($num): string { 
    $numeric = is_numeric($num) ? (float)$num : 0;
    return number_format($numeric, 2, '.', ',');
}

function getExpiryStatusDisplay($days_until_expiry) {
    if ($days_until_expiry <= 0) {
        return ['label' => 'EXPIRED', 'class' => 'badge badge-dark', 'row_class' => 'table-danger'];
    } elseif ($days_until_expiry <= 7) {
        return ['label' => 'URGENT', 'class' => 'badge badge-danger', 'row_class' => 'table-danger'];
    } elseif ($days_until_expiry <= 30) {
        return ['label' => $days_until_expiry . ' days', 'class' => 'badge badge-warning', 'row_class' => 'table-warning'];
    } else {
        return ['label' => $days_until_expiry . ' days', 'class' => 'badge badge-info', 'row_class' => ''];
    }
}

$user_role = $_SESSION['role'] ?? 'user';
$is_super_admin = ($user_role == 'super_admin');

// ========== MENU ITEMS - ONLY DASHBOARD ==========
$menu_items = [
    ['icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard', 'link' => $dashboard_home_url, 'active' => false]
];

// ================================================================
// Determine tenant SKU prefix for display
// ================================================================
$tenant_sku_prefix = getTenantSKUPrefix($conn, $tenant_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Product Management - <?php echo htmlspecialchars($company_name); ?></title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <style>
        /* ===== ALL STYLES (same as before) ===== */
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
        
        .content-wrapper { padding: 20px; }
        .card { border-radius: 1.2rem; box-shadow: 0 4px 24px rgba(0,0,0,0.1); border: none; margin-bottom: 1.5rem; overflow: hidden; background: rgba(255,255,255,0.98); }
        .card-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.75rem 1.25rem; border: none; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; border-radius: 10px; padding: 10px 25px; }
        .btn-success { background: #28a745; border: none; border-radius: 10px; padding: 10px 25px; }
        .btn-warning { background: #ffc107; border: none; border-radius: 8px; padding: 5px 12px; color: #333; }
        .btn-info { background: #17a2b8; border: none; border-radius: 8px; padding: 5px 12px; color: white; }
        .btn-danger { background: #dc3545; border: none; border-radius: 8px; padding: 5px 12px; color: white; }
        .btn-secondary { background: #6c757d; border: none; border-radius: 8px; padding: 5px 12px; color: white; }
        .btn-voice { background: linear-gradient(135deg, #6c5ce7 0%, #a29bfe 100%); color: white; border: none; border-radius: 10px; padding: 8px 16px; font-size: 14px; transition: all 0.3s ease; }
        .btn-voice:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(108,92,231,0.4); color: white; }
        .btn-voice.listening { background: linear-gradient(135deg, #e74c3c 0%, #fd79a8 100%); animation: pulse 1.5s ease-in-out infinite; }
        .btn-voice-sm { padding: 4px 10px; font-size: 12px; }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .form-control { border-radius: 10px; border: 1px solid #dee2e6; padding: 10px 15px; }
        .form-control:focus { border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.25); }
        
        .stat-card { background: rgba(255,255,255,0.98); border-radius: 20px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); margin-bottom: 20px; border-top: 4px solid #667eea; text-align: center; }
        .stat-number { font-size: 28px; font-weight: 800; color: #667eea; }
        .stat-label { font-size: 0.85rem; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; }
        
        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status-transfer { background: #d4edda; color: #155724; }
        .status-adjusted { background: #fff3cd; color: #856404; }
        
        .expiry-warning { background: #fff3cd; color: #856404; border-left: 4px solid #ffc107; padding: 8px 12px; border-radius: 4px; }
        .expiry-expired { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; padding: 8px 12px; border-radius: 4px; }
        .low-stock-badge { background: #dc3545; color: white; padding: 3px 10px; border-radius: 12px; font-size: 11px; }
        
        .nav-tabs { border-bottom: none; gap: 10px; margin-bottom: 20px; }
        .nav-tabs .nav-link { border: none; border-radius: 10px; padding: 10px 20px; color: #667eea; font-weight: 500; }
        .nav-tabs .nav-link.active { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        
        .trial-warning { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; }
        .profit-positive { color: #28a745; font-weight: 600; }
        .profit-negative { color: #dc3545; font-weight: 600; }
        
        .modal { z-index: 1050; }
        .modal-backdrop { z-index: 1040; }
        
        .product-table-container { max-height: 400px; overflow-y: auto; }
        .table-products { font-size: 13px; }
        .table-products td, .table-products th { padding: 8px; vertical-align: middle; }
        
        .threshold-input { width: 80px; display: inline-block; }
        .barcode-display { font-family: monospace; font-size: 12px; background: #f8f9fa; padding: 2px 8px; border-radius: 4px; }
        
        .stock-unit-badge { font-size: 10px; padding: 2px 8px; border-radius: 10px; }
        .stock-unit-badge.units { background: #cce5ff; color: #004085; }
        .stock-unit-badge.packs { background: #d4edda; color: #155724; }
        
        .action-btn-group { display: flex; gap: 4px; flex-wrap: wrap; }
        .action-btn-group .btn { font-size: 11px; padding: 3px 8px; }

        /* ===== QR/BARCODE SCANNER STYLES ===== */
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
        #scanner_result .product-details {
            font-size: 14px;
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

        /* ===== MODAL SCANNER STYLES ===== */
        .modal-scanner-container {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 12px;
            margin: 10px 0 15px 0;
            border: 2px dashed #28a745;
        }
        .modal-scanner-container video {
            width: 100%;
            max-width: 350px;
            border-radius: 8px;
            background: #000;
        }
        .modal-scanner-controls {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
            margin-top: 8px;
        }
        .modal-scanner-controls .btn {
            padding: 4px 12px;
            font-size: 12px;
        }
        .modal-scanner-controls .form-control {
            height: 32px;
            font-size: 13px;
            padding: 4px 8px;
        }
        #modal_scanner_result {
            margin-top: 8px;
            padding: 8px;
            background: white;
            border-radius: 6px;
            border-left: 3px solid #28a745;
            display: none;
        }
        #modal_scanner_result .product-found {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }
        #modal_scanner_result .product-details {
            font-size: 13px;
        }
        #modal_scan_status {
            padding: 6px 12px;
            border-radius: 6px;
            margin-top: 8px;
            font-size: 12px;
            display: none;
        }
        #modal_scan_status.success { background: #d4edda; color: #155724; display: block; }
        #modal_scan_status.error { background: #f8d7da; color: #721c24; display: block; }
        #modal_scan_status.info { background: #d1ecf1; color: #0c5460; display: block; }
        #modal_scan_status.warning { background: #fff3cd; color: #856404; display: block; }
        
        /* ===== VOICE INPUT STYLES ===== */
        .voice-input-section { 
            background: linear-gradient(135deg, #f8f0ff, #ede7f6); 
            padding: 25px; 
            border-radius: 16px; 
            margin: 15px 0 25px 0; 
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
        .voice-transcript .match-score { 
            font-size: 12px; 
            color: #28a745; 
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
        
        /* ===== SEQUENTIAL VOICE FILLING STYLES ===== */
        .voice-field-indicator {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
        }
        .voice-field-indicator.active {
            background: #6c5ce7;
            color: white;
            animation: pulse-field 1s ease-in-out infinite;
        }
        .voice-field-indicator.filled {
            background: #28a745;
            color: white;
        }
        .voice-field-indicator.waiting {
            background: #ffc107;
            color: #333;
        }
        @keyframes pulse-field {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .voice-progress-bar {
            height: 4px;
            background: #e9ecef;
            border-radius: 2px;
            margin: 10px 0;
            overflow: hidden;
        }
        .voice-progress-bar .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #6c5ce7, #a29bfe);
            transition: width 0.5s ease;
            width: 0%;
            border-radius: 2px;
        }
        
        .voice-command-help {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 10px 15px;
            margin-top: 10px;
            font-size: 13px;
        }
        .voice-command-help code {
            background: #e9ecef;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .field-filled {
            border-color: #28a745 !important;
            background-color: #f0fff4 !important;
        }
        .field-active {
            border-color: #6c5ce7 !important;
            box-shadow: 0 0 0 3px rgba(108,92,231,0.25) !important;
        }
        
        /* Barcode highlight animation */
        .barcode-highlight {
            animation: pulse-green 1s ease 3;
        }
        @keyframes pulse-green {
            0%, 100% { background-color: transparent; }
            50% { background-color: rgba(40, 167, 69, 0.3); }
        }
        
        /* Scanner status notification */
        .scanner-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999;
            max-width: 400px;
            padding: 12px 20px;
            border-radius: 12px;
            color: white;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            display: none;
            animation: slideUp 0.3s ease;
        }
        .scanner-notification.success { background: #28a745; }
        .scanner-notification.error { background: #dc3545; }
        .scanner-notification.info { background: #17a2b8; }
        .scanner-notification.warning { background: #ffc107; color: #333; }
        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        /* Auto-submit success animation */
        .auto-submit-success {
            animation: flash-green 0.5s ease 3;
        }
        @keyframes flash-green {
            0%, 100% { background-color: transparent; }
            50% { background-color: rgba(40, 167, 69, 0.3); }
        }
        
        /* Ensure number inputs always have room for both digits and spin arrows */
        input[type="number"].form-control {
            min-width: 70px;
        }

        @media (max-width: 1200px) {
            #crudAddProductForm .col-md-1,
            #crudAddProductForm .col-md-2,
            #crudAddProductForm .col-md-3,
            #crudAddProductForm .col-md-4,
            #productEditModal .col-md-1,
            #productEditModal .col-md-2,
            #productEditModal .col-md-3,
            #productEditModal .col-md-4 {
                flex: 0 0 50%;
                max-width: 50%;
            }
        }

        @media (max-width: 992px) {
            .sidebar { left: -280px; }
            .sidebar.active { left: 0; }
            .main-content { margin-left: 0; }
            .toggle-sidebar { display: block; }
        }
        @media (max-width: 768px) {
            .content-wrapper { padding: 10px; }
            .stat-number { font-size: 22px; }
            .scanner-container video { max-width: 100%; }
            #crudAddProductForm .col-md-1,
            #crudAddProductForm .col-md-2,
            #crudAddProductForm .col-md-3,
            #crudAddProductForm .col-md-4,
            #productEditModal .col-md-1,
            #productEditModal .col-md-2,
            #productEditModal .col-md-3,
            #productEditModal .col-md-4 {
                flex: 0 0 100%;
                max-width: 100%;
            }
        }
        
        /* SKU Info Badge */
        .sku-info {
            background: rgba(255,255,255,0.1);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .sku-info i {
            color: #a78bfa;
        }
    </style>
</head>
<body>
    <!-- ===== SIDEBAR ===== -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h5><?php echo strtoupper($product_category); ?> MANAGEMENT</h5>
            <div class="sku-info mt-1">
                <i class="fas fa-tag"></i> SKU Prefix: <?php echo htmlspecialchars($tenant_sku_prefix); ?>
            </div>
        </div>
        <ul class="nav-menu">
            <?php foreach ($menu_items as $item): ?>
            <li class="nav-item <?php echo isset($item['active']) && $item['active'] ? 'active' : ''; ?>">
                <a class="nav-link" href="<?php echo $item['link']; ?>"><i class="<?php echo $item['icon']; ?>"></i> <?php echo $item['label']; ?></a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- The rest of the HTML remains the same as the previous version -->
    <!-- All the HTML content from the previous version goes here -->
    <!-- I'm including the full HTML below for completeness -->

    <!-- ===== MAIN CONTENT ===== -->
    <div class="main-content">
        <div class="top-navbar">
            <button class="toggle-sidebar" id="toggleSidebarBtn"><i class="fas fa-bars"></i></button>
            <div>
                <span class="mr-3"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($company_name); ?></span>
                <span class="mr-3 small text-muted"><?php echo $currency_symbol; ?> | <?php echo $product_category; ?></span>
                <?php if ($subscription_status == 'trial'): ?>
                    <span class="badge badge-warning mr-2">Trial: <?php echo $_SESSION['trial_days_left'] ?? 0; ?> days left</span>
                <?php elseif ($subscription_status == 'active'): ?>
                    <span class="badge badge-success mr-2">Active</span>
                <?php endif; ?>
                <span class="badge badge-info mr-2">SKU: <?php echo htmlspecialchars($tenant_sku_prefix); ?>-XXX-XXXXX</span>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
        </div>

        <div class="content-wrapper">
            <!-- Success/Error Alerts -->
            <?php if (isset($success)): ?>
                <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle mr-2"></i> <?php echo $success; ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
            <?php endif; ?>
            <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-triangle mr-2"></i> <?php echo $error; ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
            <?php endif; ?>

            <!-- ===== STATS CARDS ===== -->
            <div class="row">
                <div class="col-sm-6 col-lg-2 mb-3">
                    <div class="stat-card">
                        <i class="fas fa-boxes fa-2x mb-2" style="color: #667eea;"></i>
                        <div class="stat-number"><?php echo number_format($total_items); ?></div>
                        <div class="stat-label">Total Stock (Units)</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2 mb-3">
                    <div class="stat-card">
                        <i class="fas fa-cubes fa-2x mb-2" style="color: #667eea;"></i>
                        <div class="stat-number"><?php echo number_format($total_available_units); ?></div>
                        <div class="stat-label">Total Units</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2 mb-3">
                    <div class="stat-card">
                        <i class="fas fa-tags fa-2x mb-2" style="color: #28a745;"></i>
                        <div class="stat-number"><?php echo $currency_symbol; ?><?php echo safe_format($total_stock_value); ?></div>
                        <div class="stat-label">Stock Value</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2 mb-3">
                    <div class="stat-card">
                        <i class="fas fa-exclamation-triangle fa-2x mb-2" style="color: #ffc107;"></i>
                        <div class="stat-number"><?php echo count($low_stock_items); ?></div>
                        <div class="stat-label">Low Stock</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2 mb-3">
                    <div class="stat-card">
                        <i class="fas fa-calendar-times fa-2x mb-2" style="color: #dc3545;"></i>
                        <div class="stat-number"><?php echo count($expiring_items); ?></div>
                        <div class="stat-label">Expiring Soon</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2 mb-3">
                    <div class="stat-card">
                        <i class="fas fa-chart-line fa-2x mb-2" style="color: #764ba2;"></i>
                        <div class="stat-number"><?php echo $unique_products; ?></div>
                        <div class="stat-label">Unique Products</div>
                    </div>
                </div>
            </div>

            <!-- ===== SKU INFO BANNER ===== -->
            <div class="alert alert-info mb-3" style="background:rgba(102,126,234,0.15); border-color:#667eea; color:white;">
                <i class="fas fa-info-circle mr-2"></i>
                <strong>Tenant-Specific SKU System:</strong> 
                Each product gets a unique SKU starting with <strong><?php echo htmlspecialchars($tenant_sku_prefix); ?></strong> 
                (e.g., <?php echo htmlspecialchars($tenant_sku_prefix); ?>-PRD-00001, <?php echo htmlspecialchars($tenant_sku_prefix); ?>-PRD-00002, etc.)
                <span class="badge badge-success ml-2">Unique per business</span>
            </div>

            <!-- ===== PRODUCT CRUD SECTION WITH VOICE FILLING ===== -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-database mr-2"></i> Product Management (CRUD) 
                        <button class="btn btn-voice btn-sm ml-3" onclick="startSequentialVoiceFill()" id="voiceFillBtn">
                            <i class="fas fa-microphone"></i> Fill with Voice
                        </button>
                        <button class="btn btn-danger btn-sm ml-2" onclick="stopSequentialVoiceFill()" id="voiceFillStopBtn" style="display:none;">
                            <i class="fas fa-stop"></i> Stop
                        </button>
                    </h5>
                </div>
                <div class="card-body">
                    <!-- Voice Progress Bar -->
                    <div class="voice-progress-bar" id="voiceProgressBar">
                        <div class="progress-fill" id="voiceProgressFill"></div>
                    </div>
                    
                    <!-- Voice Status -->
                    <div id="voiceFillStatus" class="alert alert-info" style="display:none;">
                        <i class="fas fa-microphone-alt mr-2"></i>
                        <span id="voiceFillMessage">Click "Fill with Voice" and speak the field values one by one.</span>
                        <span id="voiceFieldIndicator" class="voice-field-indicator waiting">Waiting...</span>
                    </div>

                    <!-- Add Product Form with Voice Filling -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <h6 class="border-bottom pb-2 mb-3">
                                <i class="fas fa-plus-circle text-success mr-2"></i> Add New Product
                                <small class="text-muted">(SKU will be auto-generated with tenant prefix: <?php echo htmlspecialchars($tenant_sku_prefix); ?>)</small>
                            </h6>
                        </div>
                        <div class="col-md-12">
                            <!-- QR Scanner for Add Product -->
                            <div class="modal-scanner-container" id="addProductScannerContainer">
                                <h6><i class="fas fa-qrcode mr-2"></i> Scan Barcode for New Product</h6>
                                <p class="text-muted small">Scan product barcode to auto-fill</p>
                                <div id="addProductScannerReader" style="width:100%; max-width:350px;"></div>
                                <div class="form-check" style="margin: 6px 0;">
                                    <input type="checkbox" class="form-check-input" id="addProductScannerMirroredCheckbox">
                                    <label class="form-check-label small text-muted" for="addProductScannerMirroredCheckbox">My camera image is mirrored/inverted (check to auto-correct)</label>
                                </div>
                                <div class="modal-scanner-controls">
                                    <button type="button" class="btn btn-primary btn-sm" id="addProductStartScannerBtn" onclick="startAddProductScanner()">
                                        <i class="fas fa-camera"></i> Start
                                    </button>
                                    <button type="button" class="btn btn-danger btn-sm" id="addProductStopScannerBtn" onclick="stopAddProductScanner()" style="display:none;">
                                        <i class="fas fa-stop"></i> Stop
                                    </button>
                                    <input type="text" id="addProductManualBarcodeInput" class="form-control" placeholder="Enter barcode..." style="flex:1; min-width:120px;">
                                    <button class="btn btn-voice btn-sm" onclick="startAddProductVoiceRecognition()" title="Use voice to search/add product">
                                        <i class="fas fa-microphone"></i>
                                    </button>
                                </div>
                                <div id="addProductScannerResult" style="display:none; margin-top:8px; padding:8px; background:white; border-radius:6px; border-left:3px solid #28a745;">
                                    <div class="product-found">
                                        <div class="product-details">
                                            <strong id="addProductScannedName"></strong><br>
                                            <span id="addProductScannedCode"></span>
                                        </div>
                                    </div>
                                </div>
                                <div id="addProductScanStatus" class="scan-status" style="display:none;"></div>
                            </div>
                            
                            <form method="POST" action="" id="crudAddProductForm" class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Product Name * 
                                            <span class="voice-field-indicator waiting" id="field-name-indicator">Waiting</span>
                                        </label>
                                        <input type="text" class="form-control" name="crud_product_name" id="crud_product_name" required>
                                        <button class="btn btn-voice btn-sm mt-1" onclick="startFieldVoiceRecognition('crud_product_name', 'Product Name')" title="Speak product name">
                                            <i class="fas fa-microphone"></i> Speak
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label>Pack Cost (<?php echo $currency_symbol; ?>)
                                            <span class="voice-field-indicator waiting" id="field-cost-indicator">Waiting</span>
                                        </label>
                                        <input type="number" step="0.01" class="form-control" name="crud_pp_price" id="crud_pp_price" value="0">
                                        <button class="btn btn-voice btn-sm mt-1" onclick="startFieldVoiceRecognition('crud_pp_price', 'Pack Cost')" title="Speak pack cost">
                                            <i class="fas fa-microphone"></i> Speak
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label>Pack Selling (<?php echo $currency_symbol; ?>)
                                            <span class="voice-field-indicator waiting" id="field-price-indicator">Waiting</span>
                                        </label>
                                        <input type="number" step="0.01" class="form-control" name="crud_s_price" id="crud_s_price" value="0">
                                        <button class="btn btn-voice btn-sm mt-1" onclick="startFieldVoiceRecognition('crud_s_price', 'Pack Selling Price')" title="Speak pack selling price">
                                            <i class="fas fa-microphone"></i> Speak
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label>SQPP
                                            <span class="voice-field-indicator waiting" id="field-sqpp-indicator">Waiting</span>
                                        </label>
                                        <input type="number" class="form-control" name="crud_sqpp" id="crud_sqpp" value="1" min="1">
                                        <button class="btn btn-voice btn-sm mt-1" onclick="startFieldVoiceRecognition('crud_sqpp', 'SQPP')" title="Speak SQPP">
                                            <i class="fas fa-microphone"></i> Speak
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label>Selling Unit
                                            <span class="voice-field-indicator waiting" id="field-unit-indicator">Waiting</span>
                                        </label>
                                        <select class="form-control" name="crud_selling_unit" id="crud_selling_unit">
                                            <option value="pack">Sell as Pack</option>
                                            <option value="unit">Sell as Unit</option>
                                        </select>
                                        <button class="btn btn-voice btn-sm mt-1" onclick="startFieldVoiceRecognition('crud_selling_unit', 'Selling Unit')" title="Speak selling unit (pack or unit)">
                                            <i class="fas fa-microphone"></i> Speak
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label>Low Stock Threshold
                                            <span class="voice-field-indicator waiting" id="field-threshold-indicator">Waiting</span>
                                        </label>
                                        <input type="number" class="form-control" name="crud_low_stock_threshold" id="crud_low_stock_threshold" value="5" min="1">
                                        <button class="btn btn-voice btn-sm mt-1" onclick="startFieldVoiceRecognition('crud_low_stock_threshold', 'Low Stock Threshold')" title="Speak threshold">
                                            <i class="fas fa-microphone"></i> Speak
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="crud_is_pack_product" id="crud_is_pack_product" value="1">
                                        <label class="form-check-label" for="crud_is_pack_product">
                                            This is a pack product (contains multiple units)
                                            <button class="btn btn-voice btn-sm ml-2" onclick="startFieldVoiceRecognition('crud_is_pack_product', 'Pack Product')" title="Speak 'yes' or 'no'">
                                                <i class="fas fa-microphone"></i> Speak
                                            </button>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-4" id="crud_unit_price_field" style="display: none;">
                                    <div class="form-group">
                                        <label>Selling Price per Unit (<?php echo $currency_symbol; ?>) *
                                            <span class="voice-field-indicator waiting" id="field-unitprice-indicator">Waiting</span>
                                        </label>
                                        <input type="number" step="0.01" class="form-control" name="crud_selling_price_per_unit" id="crud_selling_price_per_unit" value="0">
                                        <button class="btn btn-voice btn-sm mt-1" onclick="startFieldVoiceRecognition('crud_selling_price_per_unit', 'Selling Price per Unit')" title="Speak unit price">
                                            <i class="fas fa-microphone"></i> Speak
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-4" id="crud_expiry_notification_field">
                                    <div class="form-group">
                                        <label>Expiry Notification Days
                                            <span class="voice-field-indicator waiting" id="field-expiry-indicator">Waiting</span>
                                        </label>
                                        <input type="number" class="form-control" name="crud_expiry_notification_days" id="crud_expiry_notification_days" value="30" min="1">
                                        <button class="btn btn-voice btn-sm mt-1" onclick="startFieldVoiceRecognition('crud_expiry_notification_days', 'Expiry Notification Days')" title="Speak number of days">
                                            <i class="fas fa-microphone"></i> Speak
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label><i class="fas fa-barcode"></i> Barcode
                                            <span class="voice-field-indicator waiting" id="field-barcode-indicator">Waiting</span>
                                        </label>
                                        <input type="text" class="form-control" name="crud_barcode" id="crud_barcode" placeholder="Scan or enter barcode">
                                        <button class="btn btn-voice btn-sm mt-1" onclick="startFieldVoiceRecognition('crud_barcode', 'Barcode')" title="Speak barcode numbers">
                                            <i class="fas fa-microphone"></i> Speak
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <div class="alert alert-info mt-2" style="font-size:12px; padding:8px 12px;">
                                        <i class="fas fa-tag"></i> 
                                        <strong>SKU Format:</strong> <code><?php echo htmlspecialchars($tenant_sku_prefix); ?>-PRD-XXXXX</code> 
                                        (auto-generated, unique per tenant)
                                    </div>
                                    <button type="submit" name="crud_add_product" class="btn btn-success"><i class="fas fa-save mr-2"></i> Save Product</button>
                                </div>
                            </form>
                            
                            <!-- Voice Command Help -->
                            <div class="voice-command-help">
                                <i class="fas fa-info-circle text-primary mr-1"></i>
                                <strong>Voice Commands:</strong>
                                <code>name: Product X</code> 
                                <code>cost: 500</code> 
                                <code>price: 1000</code> 
                                <code>sqpp: 12</code> 
                                <code>unit: pack</code> 
                                <code>threshold: 5</code> 
                                <code>barcode: 123456789</code>
                                <code>done</code> - finish filling
                                <code>stop</code> - cancel
                            </div>
                        </div>
                    </div>

                    <!-- Products List Table -->
                    <div class="row">
                        <div class="col-12">
                            <h6 class="border-bottom pb-2 mb-3"><i class="fas fa-list text-info mr-2"></i> All Products</h6>
                            <div class="table-responsive product-table-container">
                                <table class="table table-bordered table-hover table-products" id="productsTable">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>SKU</th>
                                            <th>Name</th>
                                            <th>Pack Cost</th>
                                            <th>Pack Selling</th>
                                            <th>SQPP</th>
                                            <th>Unit</th>
                                            <th>Threshold</th>
                                            <th>Expiry Notify</th>
                                            <th>Barcode</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($products)): ?>
                                            <tr>
                                                <td colspan="10" class="text-center py-4">
                                                    <i class="fas fa-box-open fa-2x text-muted mb-2"></i>
                                                    <p class="mb-0">No products found. Add your first product above!</p>
                                                    <small class="text-muted">First SKU will be: <?php echo htmlspecialchars($tenant_sku_prefix); ?>-PRD-00001</small>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($products as $product): ?>
                                            <tr>
                                                <td><code><?php echo htmlspecialchars($product['item_code']); ?></code></td>
                                                <td><strong><?php echo htmlspecialchars($product['name']); ?></strong></td>
                                                <td><?php echo $currency_symbol; ?><?php echo number_format($product['pack_cost_price'], 2); ?></td>
                                                <td><?php echo $currency_symbol; ?><?php echo number_format($product['selling_price'], 2); ?></td>
                                                <td><?php echo $product['pack_quantity']; ?></td>
                                                <td>
                                                    <span class="badge badge-<?php echo $product['selling_unit'] == 'unit' ? 'primary' : 'secondary'; ?>">
                                                        <?php echo $product['selling_unit'] == 'unit' ? 'Units' : 'Packs'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?php echo $product['low_stock_threshold'] <= 5 ? 'danger' : 'warning'; ?>">
                                                        <?php echo $product['low_stock_threshold']; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $product['expiry_notification_days']; ?> days</td>
                                                <td>
                                                    <?php if (!empty($product['barcode'])): ?>
                                                        <span class="barcode-display"><?php echo htmlspecialchars($product['barcode']); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-warning" onclick='openProductEditModal(<?php echo htmlspecialchars(json_encode($product)); ?>)' title="Edit Product">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-danger" onclick='openProductDeleteModal(<?php echo $product['id']; ?>, <?php echo htmlspecialchars(json_encode($product['name'])); ?>)' title="Delete Product">
                                                            <i class="fas fa-trash"></i>
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
            </div>

            <!-- ===== STOCK MANAGEMENT TABS ===== -->
            <div class="card">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-cog mr-2"></i> Stock Management</h5></div>
                <div class="card-body">
                    <ul class="nav nav-tabs" id="stockTabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="add-to-stock-tab" data-toggle="tab" href="#addToStock" role="tab" onclick="switchStockTab('addToStock')">
                                <i class="fas fa-exchange-alt mr-2"></i> Add to Stock
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="current-inventory-tab" data-toggle="tab" href="#currentInventory" role="tab" onclick="switchStockTab('currentInventory')">
                                <i class="fas fa-boxes mr-2"></i> Current Inventory
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="history-tab" data-toggle="tab" href="#history" role="tab" onclick="switchStockTab('history')">
                                <i class="fas fa-history mr-2"></i> History
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="alerts-tab" data-toggle="tab" href="#alerts" role="tab" onclick="switchStockTab('alerts')">
                                <i class="fas fa-bell mr-2"></i> Alerts
                            </a>
                        </li>
                    </ul>
                    
                    <div class="tab-content mt-4" id="stockTabContent">
                        <!-- ===== TAB 1: ADD TO STOCK ===== -->
                        <div id="addToStock" class="tab-pane fade show active" role="tabpanel">
                            <!-- Add to Stock content (same as before) -->
                            <div class="voice-input-section" style="margin-bottom:15px;">
                                <h6><i class="fas fa-microphone-alt" style="color:#6c5ce7;"></i> Voice Search for Product</h6>
                                <p class="text-muted small">Speak the product name to quickly select it for stock addition</p>
                                <div class="row">
                                    <div class="col-md-8">
                                        <div class="input-group">
                                            <input type="text" id="addToStockVoiceInput" class="form-control" placeholder="Click microphone and speak product name...">
                                            <div class="input-group-append">
                                                <button class="btn btn-voice" onclick="startAddToStockVoiceRecognition()" title="Speak product name">
                                                    <i class="fas fa-microphone"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <small class="text-muted" id="addToStockVoiceStatus">Click the microphone and speak</small>
                                    </div>
                                </div>
                                <div id="addToStockVoiceResults" style="display:none; margin-top:10px;"></div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <div class="card bg-light">
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-8">
                                                    <div class="input-group">
                                                        <div class="input-group-prepend">
                                                            <span class="input-group-text"><i class="fas fa-barcode"></i></span>
                                                        </div>
                                                        <input type="text" id="barcode_search_input" class="form-control" placeholder="Scan barcode to find product..." autofocus>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <small class="text-muted">Scan barcode to auto-select product and fill prices</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <form method="POST" action="" id="addToStockForm">
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label><i class="fas fa-box"></i> Select Product *</label>
                                            <select class="form-control" name="product_id" id="product_id" required onchange="updateProductPrice()">
                                                <option value="">-- Select Product --</option>
                                                <?php foreach ($products as $product): ?>
                                                <option value="<?php echo $product['id']; ?>" 
                                                        data-selling="<?php echo $product['selling_price']; ?>" 
                                                        data-cost="<?php echo $product['cost_price']; ?>" 
                                                        data-pack="<?php echo $product['pack_quantity']; ?>" 
                                                        data-unit="<?php echo $product['selling_unit']; ?>"
                                                        data-is-pack="<?php echo $product['is_pack_product']; ?>"
                                                        data-pack-cost="<?php echo $product['pack_cost_price']; ?>"
                                                        data-selling-per-unit="<?php echo $product['selling_price_per_unit'] ?? 0; ?>"
                                                        data-barcode="<?php echo htmlspecialchars($product['barcode'] ?? ''); ?>">
                                                    <?php echo htmlspecialchars($product['name']); ?> (<?php echo htmlspecialchars($product['item_code']); ?>)
                                                    <?php if ($product['is_pack_product'] && $product['pack_quantity'] > 1): ?>
                                                        - Pack of <?php echo $product['pack_quantity']; ?>
                                                    <?php endif; ?>
                                                    <?php if ($product['selling_unit'] == 'unit'): ?>
                                                        - Sells as Units
                                                    <?php endif; ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="form-group">
                                            <label><i class="fas fa-sort-numeric-up"></i> <span id="quantity_label">Packs Received</span> *</label>
                                            <input type="number" class="form-control" name="quantity" id="transfer_quantity" min="1" required oninput="updatePackInfo()">
                                            <small class="text-muted" id="pack_conversion_info"></small>
                                            <button class="btn btn-voice btn-sm mt-1" onclick="startQuantityVoiceRecognition()" title="Speak quantity">
                                                <i class="fas fa-microphone"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label><i class="fas fa-calendar"></i> Expiry Date</label>
                                            <input type="date" class="form-control" name="expiry_date" id="expiry_date">
                                            <small class="text-muted">Optional - leave empty if not applicable</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label><i class="fas fa-barcode"></i> Batch Barcode</label>
                                            <input type="text" class="form-control" name="batch_barcode" id="batch_barcode" placeholder="Optional batch barcode">
                                            <small class="text-muted">Optional - for batch tracking</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label><i class="fas fa-tag"></i> Selling Price (<?php echo $currency_symbol; ?>) *</label>
                                            <input type="number" step="0.01" class="form-control" name="selling_price" id="selling_price" required>
                                            <small class="text-muted" id="selling_price_unit_label">Per unit/pack price</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label><i class="fas fa-chart-line"></i> Cost Price (<?php echo $currency_symbol; ?>)</label>
                                            <input type="number" step="0.01" class="form-control" name="cost_price" id="cost_price" placeholder="Auto-calculated">
                                            <small class="text-muted" id="cost_price_unit_label">Per unit/pack cost</small>
                                        </div>
                                    </div>
                                </div>
                                <div id="pack_info_display" class="alert alert-info mt-2" style="display: none;"></div>
                                <div id="unit_conversion_display" class="alert alert-success mt-2" style="display: none;"></div>
                                <div id="expiry_warning_display" class="alert alert-warning mt-2" style="display: none;"></div>
                                <button type="submit" name="transfer_stock" class="btn btn-primary" <?php echo empty($products) ? 'disabled' : ''; ?>><i class="fas fa-exchange-alt mr-2"></i> Add to Stock</button>
                            </form>
                        </div>
                        
                        <!-- ===== TAB 2: CURRENT INVENTORY ===== -->
                        <div id="currentInventory" class="tab-pane fade" role="tabpanel">
                            <!-- Current Inventory content (same as before) -->
                            <div class="voice-input-section" style="margin-bottom:15px;">
                                <h6><i class="fas fa-microphone-alt" style="color:#6c5ce7;"></i> Voice Search in Inventory</h6>
                                <p class="text-muted small">Speak product name or barcode to find inventory items</p>
                                <div class="row">
                                    <div class="col-md-8">
                                        <div class="input-group">
                                            <input type="text" id="inventoryVoiceInput" class="form-control" placeholder="Click microphone and speak product name...">
                                            <div class="input-group-append">
                                                <button class="btn btn-voice" onclick="startInventoryVoiceRecognition()" title="Speak product name">
                                                    <i class="fas fa-microphone"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <small class="text-muted" id="inventoryVoiceStatus">Click the microphone and speak</small>
                                    </div>
                                </div>
                                <div id="inventoryVoiceResults" style="display:none; margin-top:10px;"></div>
                            </div>
                            
                            <div class="scanner-container" id="inventoryScannerContainer">
                                <button class="close-scanner-btn" id="inventoryCloseScannerBtn" onclick="stopInventoryScanner()"><i class="fas fa-times"></i></button>
                                <h6><i class="fas fa-qrcode mr-2"></i> Scan Inventory Item Barcode</h6>
                                <p class="text-muted small">Scan barcode to find and auto-highlight inventory items</p>
                                <div id="inventoryScannerReader" style="width:100%; max-width:400px;"></div>
                                <div class="form-check" style="margin: 6px 0;">
                                    <input type="checkbox" class="form-check-input" id="inventoryScannerMirroredCheckbox">
                                    <label class="form-check-label small text-muted" for="inventoryScannerMirroredCheckbox">My camera image is mirrored/inverted (check to auto-correct)</label>
                                </div>
                                <div class="scanner-controls">
                                    <button class="btn btn-primary" id="inventoryStartScannerBtn" onclick="startInventoryScanner()">
                                        <i class="fas fa-camera"></i> Start Scanner
                                    </button>
                                    <button class="btn btn-danger" id="inventoryStopScannerBtn" onclick="stopInventoryScanner()" style="display:none;">
                                        <i class="fas fa-stop"></i> Stop Scanner
                                    </button>
                                    <input type="text" id="inventoryManualBarcodeInput" class="form-control" placeholder="Or enter barcode manually..." style="flex:1; min-width:150px;">
                                    <button class="btn btn-voice btn-sm" onclick="startInventoryBarcodeVoiceRecognition()" title="Use voice to enter barcode">
                                        <i class="fas fa-microphone"></i>
                                    </button>
                                </div>
                                <div id="inventoryScannerResult">
                                    <div class="product-found">
                                        <div class="product-details">
                                            <strong id="inventoryScannedName"></strong><br>
                                            <span id="inventoryScannedCode"></span><br>
                                            <span id="inventoryScannedStock"></span>
                                        </div>
                                    </div>
                                </div>
                                <div id="inventoryScanStatus" class="scan-status"></div>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-hover" id="inventoryTable">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Product</th>
                                            <th>Batch</th>
                                            <th>Barcode</th>
                                            <th>Stock</th>
                                            <th>Stock Unit</th>
                                            <th>Selling Price</th>
                                            <th>Cost Price</th>
                                            <th>Expiry Date</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($finished_goods)): ?>
                                            <tr><td colspan="10" class="text-center py-5"><i class="fas fa-box-open fa-3x text-muted mb-3"></i><p>No inventory yet. Add products to stock!</p></td></tr>
                                        <?php else: ?>
                                            <?php foreach ($finished_goods as $item): ?>
                                            <tr id="inventory-row-<?php echo $item['id']; ?>" data-barcode="<?php echo htmlspecialchars($item['barcode'] ?? ''); ?>">
                                                <td><strong><?php echo htmlspecialchars($item['product_name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($item['batch_code'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <?php if (!empty($item['barcode'])): ?>
                                                        <span class="barcode-display"><?php echo htmlspecialchars($item['barcode']); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($item['is_low_stock']): ?>
                                                        <span class="low-stock-badge"><?php echo $item['display_quantity']; ?></span>
                                                    <?php else: ?>
                                                        <?php echo $item['display_quantity']; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $unit_class = $item['selling_unit'] == 'unit' ? 'units' : 'packs';
                                                    ?>
                                                    <span class="stock-unit-badge <?php echo $unit_class; ?>">
                                                        <?php echo ucfirst($item['selling_unit'] == 'unit' ? 'Units' : 'Packs'); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $currency_symbol; ?><?php echo safe_format($item['price_per_product']); ?></td>
                                                <td><?php echo $currency_symbol; ?><?php echo safe_format($item['cost_price'] ?? 0); ?></td>
                                                <td>
                                                    <?php if (!empty($item['expiry_date'])): ?>
                                                        <?php 
                                                        $status = getExpiryStatusDisplay($item['days_until_expiry'] ?? 999);
                                                        ?>
                                                        <span class="<?php echo $status['class']; ?>">
                                                            <?php echo date('d M Y', strtotime($item['expiry_date'])); ?>
                                                            <?php if ($item['expired'] ?? false): ?>
                                                                <i class="fas fa-exclamation-circle"></i>
                                                            <?php endif; ?>
                                                        </span>
                                                        <?php if ($item['expired'] ?? false): ?>
                                                            <br><span class="badge badge-dark">EXPIRED</span>
                                                        <?php elseif ($item['expiry_warning'] ?? false): ?>
                                                            <br><span class="badge badge-warning"><?php echo $item['days_until_expiry']; ?> days</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($item['is_low_stock']): ?>
                                                        <span class="badge badge-danger">Low Stock</span>
                                                    <?php endif; ?>
                                                    <?php if ($item['expiry_warning'] ?? false): ?>
                                                        <span class="badge badge-warning">Expiring</span>
                                                    <?php endif; ?>
                                                    <?php if ($item['expired'] ?? false): ?>
                                                        <span class="badge badge-dark">Expired</span>
                                                    <?php endif; ?>
                                                    <span class="status-badge status-<?php echo $item['status']; ?>"><?php echo ucfirst($item['status']); ?></span>
                                                </td>
                                                <td>
                                                    <div class="action-btn-group">
                                                        <button type="button" class="btn btn-info" onclick='openExpiryEditModal(<?php echo $item['id']; ?>, <?php echo htmlspecialchars(json_encode($item['product_name'])); ?>, <?php echo htmlspecialchars(json_encode($item['expiry_date'] ?? '')); ?>, <?php echo $item['quantity']; ?>, <?php echo htmlspecialchars(json_encode($item['selling_unit'])); ?>, <?php echo $item['pack_quantity'] ?? 1; ?>, <?php echo $item['equivalent_units']; ?>, <?php echo htmlspecialchars(json_encode($item['barcode'] ?? '')); ?>)' title="Edit Expiry Date Only">
                                                            <i class="fas fa-calendar-edit"></i> Expiry
                                                        </button>
                                                        <button type="button" class="btn btn-warning" onclick='openEditModal(<?php echo $item['id']; ?>, <?php echo htmlspecialchars(json_encode($item['product_name'])); ?>, <?php echo $item['quantity']; ?>, <?php echo $item['price_per_product']; ?>, <?php echo $item['cost_price'] ?? 0; ?>, <?php echo htmlspecialchars(json_encode($item['batch_code'] ?? '')); ?>, <?php echo htmlspecialchars(json_encode($item['selling_unit'])); ?>, <?php echo $item['pack_quantity'] ?? 1; ?>, <?php echo $item['equivalent_units']; ?>, <?php echo htmlspecialchars(json_encode($item['expiry_date'] ?? '')); ?>, <?php echo htmlspecialchars(json_encode($item['barcode'] ?? '')); ?>)' title="Full Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-danger" onclick='openDeleteModal(<?php echo $item['id']; ?>, <?php echo htmlspecialchars(json_encode($item['product_name'])); ?>, <?php echo $item['quantity']; ?>, <?php echo $item['equivalent_units']; ?>, <?php echo $item['total_selling_value']; ?>, <?php echo htmlspecialchars(json_encode($item['selling_unit'])); ?>, <?php echo $item['pack_quantity'] ?? 1; ?>, <?php echo htmlspecialchars(json_encode($item['expiry_date'] ?? '')); ?>, <?php echo htmlspecialchars(json_encode($item['barcode'] ?? '')); ?>)' title="Delete">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-info" onclick='openAdjustModal(<?php echo $item['id']; ?>, <?php echo htmlspecialchars(json_encode($item['product_name'])); ?>, <?php echo $item['quantity']; ?>, <?php echo htmlspecialchars(json_encode($item['selling_unit'])); ?>)' title="Adjust Quantity">
                                                            <i class="fas fa-sliders-h"></i>
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
                        
                        <!-- ===== TAB 3: HISTORY ===== -->
                        <div id="history" class="tab-pane fade" role="tabpanel">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Date & Time</th>
                                            <th>Product</th>
                                            <th>Action</th>
                                            <th>Old Qty</th>
                                            <th>New Qty</th>
                                            <th>Change</th>
                                            <th>Reason</th>
                                            <th>Performed By</th>
                                         </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($inventory_history)): ?>
                                         <tr><td colspan="8" class="text-center py-5"><i class="fas fa-history fa-3x text-muted mb-3"></i><p>No history records yet.</p></td></tr>
                                        <?php else: ?>
                                            <?php foreach ($inventory_history as $history): ?>
                                            <tr class="history-row-<?php echo $history['action']; ?>">
                                                <td><?php echo date('Y-m-d H:i:s', strtotime($history['created_at'])); ?></td>
                                                <td><strong><?php echo htmlspecialchars($history['product_name']); ?></strong></td>
                                                <td>
                                                    <?php 
                                                    $action_icon = '';
                                                    $action_class = '';
                                                    $action_text = ucfirst($history['action']);
                                                    switch($history['action']) {
                                                        case 'stock_in':
                                                            $action_icon = 'fa-arrow-down';
                                                            $action_class = 'text-success';
                                                            $action_text = 'Stock In';
                                                            break;
                                                        case 'adjustment':
                                                            $action_icon = 'fa-sliders-h';
                                                            $action_class = 'text-warning';
                                                            $action_text = 'Adjusted';
                                                            break;
                                                        case 'edit':
                                                            $action_icon = 'fa-edit';
                                                            $action_class = 'text-info';
                                                            $action_text = 'Edited';
                                                            break;
                                                        case 'edit_expiry':
                                                            $action_icon = 'fa-calendar-edit';
                                                            $action_class = 'text-info';
                                                            $action_text = 'Expiry Updated';
                                                            break;
                                                        case 'deletion':
                                                            $action_icon = 'fa-trash';
                                                            $action_class = 'text-danger';
                                                            $action_text = 'Deleted';
                                                            break;
                                                        default:
                                                            $action_icon = 'fa-info-circle';
                                                            $action_class = 'text-secondary';
                                                            $action_text = ucfirst($history['action']);
                                                    }
                                                    ?>
                                                    <i class="fas <?php echo $action_icon; ?> <?php echo $action_class; ?> mr-1"></i>
                                                    <span class="<?php echo $action_class; ?>"><?php echo $action_text; ?></span>
                                                </td>
                                                <td><?php echo number_format($history['old_quantity']); ?></td>
                                                <td><?php echo number_format($history['new_quantity']); ?></td>
                                                <td>
                                                    <?php 
                                                    $diff = $history['new_quantity'] - $history['old_quantity'];
                                                    $diff_class = $diff > 0 ? 'text-success' : ($diff < 0 ? 'text-danger' : 'text-muted');
                                                    $diff_symbol = $diff > 0 ? '+' : '';
                                                    ?>
                                                    <span class="<?php echo $diff_class; ?>"><?php echo $diff_symbol . number_format($diff); ?></span>
                                                </td>
                                                <td><small><?php echo htmlspecialchars($history['reason']); ?></small></td>
                                                <td><small><?php echo $history['performed_by'] ? 'User #' . $history['performed_by'] : 'System'; ?></small></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- ===== TAB 4: ALERTS ===== -->
                        <div id="alerts" class="tab-pane fade" role="tabpanel">
                            <?php if (count($low_stock_items) > 0): ?>
                            <div class="card border-danger mb-3">
                                <div class="card-header bg-danger text-white">
                                    <h5 class="mb-0"><i class="fas fa-exclamation-triangle mr-2"></i> Low Stock Alerts</h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table">
                                            <thead>
                                                <tr><th>Product</th><th>Current Stock</th><th>Threshold</th><th>Barcode</th><th>Status</th><th>Action</th></tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($low_stock_items as $item): ?>
                                                <tr class="table-warning">
                                                    <td><strong><?php echo htmlspecialchars($item['product_name']); ?></strong></td>
                                                    <td><span class="badge badge-danger"><?php echo $item['display_quantity']; ?></span></td>
                                                    <td><?php echo $item['threshold_display']; ?></td>
                                                    <td><?php echo !empty($item['barcode']) ? htmlspecialchars($item['barcode']) : 'N/A'; ?></td>
                                                    <td><span class="badge badge-danger">Reorder Needed</span></td>
                                                    <td>
                                                        <button class="btn btn-sm btn-primary" onclick="switchStockTab('addToStock'); document.getElementById('product_id').value=<?php echo $item['product_id'] ?? ''; ?>; document.getElementById('product_id').dispatchEvent(new Event('change'));">
                                                            <i class="fas fa-plus"></i> Add Stock
                                                        </button>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (count($expiring_items) > 0): ?>
                            <div class="card border-warning">
                                <div class="card-header bg-warning text-dark">
                                    <h5 class="mb-0"><i class="fas fa-calendar-times mr-2"></i> Expiry Alerts</h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table">
                                            <thead>
                                                <tr><th>Product</th><th>Batch</th><th>Barcode</th><th>Expiry Date</th><th>Days Left</th><th>Status</th><th>Action</th></tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($expiring_items as $item): ?>
                                                <tr class="<?php echo $item['days_until_expiry'] <= 7 ? 'table-danger' : 'table-warning'; ?>">
                                                    <td><strong><?php echo htmlspecialchars($item['product_name']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($item['batch_code'] ?? 'N/A'); ?></td>
                                                    <td><?php echo !empty($item['barcode']) ? htmlspecialchars($item['barcode']) : 'N/A'; ?></td>
                                                    <td><?php echo date('d M Y', strtotime($item['expiry_date'])); ?></td>
                                                    <td>
                                                        <?php if ($item['days_until_expiry'] <= 0): ?>
                                                            <span class="badge badge-danger">EXPIRED</span>
                                                        <?php elseif ($item['days_until_expiry'] <= 7): ?>
                                                            <span class="badge badge-danger"><?php echo $item['days_until_expiry']; ?> days</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-warning"><?php echo $item['days_until_expiry']; ?> days</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($item['days_until_expiry'] <= 0): ?>
                                                            <span class="badge badge-dark">Expired</span>
                                                        <?php elseif ($item['days_until_expiry'] <= 7): ?>
                                                            <span class="badge badge-danger">URGENT</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-warning">Soon</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-sm btn-warning" onclick="openExpiryEditModal(<?php echo $item['id']; ?>, <?php echo htmlspecialchars(json_encode($item['product_name'])); ?>, <?php echo htmlspecialchars(json_encode($item['expiry_date'] ?? '')); ?>, <?php echo $item['quantity']; ?>, <?php echo htmlspecialchars(json_encode($item['selling_unit'])); ?>, <?php echo $item['pack_quantity'] ?? 1; ?>, <?php echo $item['quantity'] * ($item['pack_quantity'] ?? 1); ?>, <?php echo htmlspecialchars(json_encode($item['barcode'] ?? '')); ?>)">
                                                            <i class="fas fa-calendar-edit"></i> Update Expiry
                                                        </button>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (count($low_stock_items) == 0 && count($expiring_items) == 0): ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle mr-2"></i> No alerts! All items are adequately stocked and none are expiring soon.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== INVENTORY SUMMARY ===== -->
            <div class="card">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-chart-pie mr-2"></i> Inventory Summary</h5></div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="p-3">
                                <i class="fas fa-boxes fa-2x mb-2" style="color: #667eea;"></i>
                                <h4><?php echo number_format($total_items); ?></h4>
                                <p class="text-muted mb-0">Total Stock (Units)</p>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="p-3">
                                <i class="fas fa-cubes fa-2x mb-2" style="color: #667eea;"></i>
                                <h4><?php echo number_format($total_available_units); ?></h4>
                                <p class="text-muted mb-0">Total Units</p>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="p-3">
                                <i class="fas fa-tags fa-2x mb-2" style="color: #28a745;"></i>
                                <h4 class="text-success"><?php echo $currency_symbol; ?><?php echo safe_format($total_stock_value); ?></h4>
                                <p class="text-muted mb-0">Stock Value</p>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="p-3">
                                <i class="fas fa-chart-line fa-2x mb-2" style="color: #764ba2;"></i>
                                <h4><?php echo $total_stock_value > 0 ? number_format(($total_profit_value / $total_stock_value) * 100, 1) : 0; ?>%</h4>
                                <p class="text-muted mb-0">Profit Margin</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <footer class="footer text-center text-muted py-3">&copy; <?php echo date('Y'); ?> WROSYS. All rights reserved.</footer>
    </div>

    <!-- ===== MODALS ===== -->
    <!-- Product Edit Modal -->
    <div class="modal fade" id="productEditModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Product</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="edit_product_id" id="edit_product_id">
                        <div class="modal-scanner-container" id="editProductScannerContainer">
                            <h6><i class="fas fa-qrcode mr-2"></i> Scan Barcode to Update</h6>
                            <p class="text-muted small">Scan product barcode to auto-fill</p>
                            <div id="editProductScannerReader" style="width:100%; max-width:350px;"></div>
                            <div class="form-check" style="margin: 6px 0;">
                                <input type="checkbox" class="form-check-input" id="editProductScannerMirroredCheckbox">
                                <label class="form-check-label small text-muted" for="editProductScannerMirroredCheckbox">My camera image is mirrored/inverted (check to auto-correct)</label>
                            </div>
                            <div class="modal-scanner-controls">
                                <button type="button" class="btn btn-primary btn-sm" id="editProductStartScannerBtn" onclick="startEditProductScanner()">
                                    <i class="fas fa-camera"></i> Start
                                </button>
                                <button type="button" class="btn btn-danger btn-sm" id="editProductStopScannerBtn" onclick="stopEditProductScanner()" style="display:none;">
                                    <i class="fas fa-stop"></i> Stop
                                </button>
                                <input type="text" id="editProductManualBarcodeInput" class="form-control" placeholder="Enter barcode..." style="flex:1; min-width:120px;">
                                <button class="btn btn-voice btn-sm" onclick="startEditProductVoiceRecognition()" title="Use voice to search product">
                                    <i class="fas fa-microphone"></i>
                                </button>
                            </div>
                            <div id="editProductScannerResult" style="display:none; margin-top:8px; padding:8px; background:white; border-radius:6px; border-left:3px solid #28a745;">
                                <div class="product-found">
                                    <div class="product-details">
                                        <strong id="editProductScannedName"></strong><br>
                                        <span id="editProductScannedCode"></span>
                                    </div>
                                </div>
                            </div>
                            <div id="editProductScanStatus" class="scan-status" style="display:none;"></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Product Name *</label>
                                    <input type="text" class="form-control" name="edit_product_name" id="edit_product_name" required>
                                    <button class="btn btn-voice btn-sm mt-1" onclick="startEditProductNameVoiceRecognition()" title="Speak product name to find">
                                        <i class="fas fa-microphone"></i> Find
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>SKU</label>
                                    <input type="text" class="form-control" id="edit_product_sku" disabled>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>Pack Cost (<?php echo $currency_symbol; ?>)</label>
                                    <input type="number" step="0.01" class="form-control" name="edit_pp_price" id="edit_pp_price" value="0">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>Pack Selling (<?php echo $currency_symbol; ?>)</label>
                                    <input type="number" step="0.01" class="form-control" name="edit_s_price" id="edit_s_price" value="0">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>SQPP</label>
                                    <input type="number" class="form-control" name="edit_sqpp" id="edit_sqpp" value="1" min="1">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>Selling Unit</label>
                                    <select class="form-control" name="edit_selling_unit" id="edit_selling_unit">
                                        <option value="pack">Pack</option>
                                        <option value="unit">Unit</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>Low Stock Threshold</label>
                                    <input type="number" class="form-control" name="edit_low_stock_threshold" id="edit_low_stock_threshold" min="1">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label><i class="fas fa-barcode"></i> Barcode</label>
                                    <input type="text" class="form-control" name="edit_barcode" id="edit_barcode" placeholder="Scan or enter barcode">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" name="edit_is_pack_product" id="edit_is_pack_product" value="1">
                                    <label class="form-check-label" for="edit_is_pack_product">This is a pack product</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Expiry Notification Days</label>
                                    <input type="number" class="form-control" name="edit_expiry_notification_days" id="edit_expiry_notification_days" min="1">
                                </div>
                            </div>
                        </div>
                        <div class="row" id="edit_unit_price_field" style="display: none;">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Selling Price per Unit</label>
                                    <input type="number" step="0.01" class="form-control" name="edit_selling_price_per_unit" id="edit_selling_price_per_unit" value="0">
                                </div>
                            </div>
                        </div>
                        <div class="alert alert-info" id="edit_product_info_display"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="crud_edit_product" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Product Delete Modal (Simple Yes/No confirm) -->
    <div class="modal fade" id="productDeleteModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-trash"></i> Delete Product</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST" action="" id="deleteProductForm">
                    <div class="modal-body">
                        <input type="hidden" name="delete_product_id" id="delete_product_id">
                        <input type="hidden" name="crud_delete_product" value="1">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> 
                            <strong>Warning!</strong> This will permanently delete the product and all associated inventory.
                        </div>
                        <p>Are you sure you want to delete <strong id="delete_product_name_display"></strong>? This action cannot be undone.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">No, Cancel</button>
                        <button type="submit" class="btn btn-danger" id="confirm_delete_btn">Yes, Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Expiry Date Edit Modal -->
    <div class="modal fade" id="expiryEditModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-calendar-edit mr-2"></i> Edit Expiry Date</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="edit_expiry_only" value="1">
                        <input type="hidden" name="expiry_item_id" id="expiry_item_id">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle mr-1"></i> 
                            <strong>Note:</strong> This will only update the expiry date. All other stock details (quantity, price, etc.) will remain unchanged.
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Product</label>
                                    <input type="text" class="form-control" id="expiry_product_name" disabled>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Current Stock</label>
                                    <input type="text" class="form-control" id="expiry_current_stock" disabled>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Barcode</label>
                                    <input type="text" class="form-control" id="expiry_barcode" disabled>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Current Expiry Date</label>
                                    <input type="text" class="form-control" id="expiry_current_date" disabled>
                                    <small class="text-muted" id="expiry_status_text"></small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>New Expiry Date *</label>
                                    <input type="date" class="form-control" name="expiry_date" id="expiry_new_date" required>
                                    <small class="text-muted" id="expiry_new_status"></small>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Reason for Change *</label>
                            <textarea class="form-control" name="expiry_edit_reason" id="expiry_edit_reason" rows="2" required placeholder="e.g., Corrected incorrect expiry date entered previously"></textarea>
                        </div>
                        <div id="expiry_warning_display_edit" class="alert alert-warning" style="display: none;">
                            <i class="fas fa-exclamation-triangle mr-1"></i>
                            <span id="expiry_warning_text"></span>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-info">Update Expiry Date</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Inventory Modal -->
    <div class="modal fade" id="editModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Inventory Item</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="edit_item_id" id="edit_item_id">
                        <div class="modal-scanner-container" id="editInventoryScannerContainer">
                            <h6><i class="fas fa-qrcode mr-2"></i> Scan Barcode to Find Inventory Item</h6>
                            <p class="text-muted small">Scan inventory item barcode to auto-fill</p>
                            <div id="editInventoryScannerReader" style="width:100%; max-width:350px;"></div>
                            <div class="form-check" style="margin: 6px 0;">
                                <input type="checkbox" class="form-check-input" id="editInventoryScannerMirroredCheckbox">
                                <label class="form-check-label small text-muted" for="editInventoryScannerMirroredCheckbox">My camera image is mirrored/inverted (check to auto-correct)</label>
                            </div>
                            <div class="modal-scanner-controls">
                                <button type="button" class="btn btn-primary btn-sm" id="editInventoryStartScannerBtn" onclick="startEditInventoryScanner()">
                                    <i class="fas fa-camera"></i> Start
                                </button>
                                <button type="button" class="btn btn-danger btn-sm" id="editInventoryStopScannerBtn" onclick="stopEditInventoryScanner()" style="display:none;">
                                    <i class="fas fa-stop"></i> Stop
                                </button>
                                <input type="text" id="editInventoryManualBarcodeInput" class="form-control" placeholder="Enter barcode..." style="flex:1; min-width:120px;">
                                <button class="btn btn-voice btn-sm" onclick="startEditInventoryVoiceRecognition()" title="Use voice to find inventory item">
                                    <i class="fas fa-microphone"></i>
                                </button>
                            </div>
                            <div id="editInventoryScannerResult" style="display:none; margin-top:8px; padding:8px; background:white; border-radius:6px; border-left:3px solid #28a745;">
                                <div class="product-found">
                                    <div class="product-details">
                                        <strong id="editInventoryScannedName"></strong><br>
                                        <span id="editInventoryScannedCode"></span>
                                    </div>
                                </div>
                            </div>
                            <div id="editInventoryScanStatus" class="scan-status" style="display:none;"></div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Product Name</label>
                                    <input type="text" class="form-control" id="edit_product_name_display" disabled>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Batch Code</label>
                                    <input type="text" class="form-control" name="edit_batch_code" id="edit_batch_code">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label><i class="fas fa-barcode"></i> Barcode</label>
                                    <input type="text" class="form-control" name="edit_barcode" id="edit_inventory_barcode" placeholder="Batch barcode">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Quantity (Units) *</label>
                                    <input type="number" class="form-control" name="edit_quantity" id="edit_quantity" min="0" required>
                                    <small class="text-muted">Always in units</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Selling Price (<?php echo $currency_symbol; ?>) *</label>
                                    <input type="number" step="0.01" class="form-control" name="edit_selling_price" id="edit_selling_price" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Cost Price (<?php echo $currency_symbol; ?>) *</label>
                                    <input type="number" step="0.01" class="form-control" name="edit_cost_price" id="edit_cost_price" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Expiry Date</label>
                                    <input type="date" class="form-control" name="edit_expiry_date" id="edit_expiry_date">
                                    <small class="text-muted">Update expiry date if needed</small>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Reason for Edit *</label>
                            <textarea class="form-control" name="edit_reason" id="edit_reason" rows="2" required></textarea>
                        </div>
                        <div class="alert alert-info" id="edit_pack_info" style="display: none;"></div>
                        <div class="alert alert-warning" id="edit_expiry_warning" style="display: none;"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="edit_inventory" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Inventory Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-trash"></i> Delete Inventory Item</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="delete_item_id" id="delete_item_id">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> 
                            <strong>Warning!</strong> You are about to delete this inventory item.
                        </div>
                        <p><strong>Product:</strong> <span id="delete_product_name_display_inv"></span></p>
                        <p><strong>Stock (Units):</strong> <span id="delete_stock_info"></span></p>
                        <p><strong>Value:</strong> <span id="delete_total_value"></span></p>
                        <p><strong>Expiry:</strong> <span id="delete_expiry_info"></span></p>
                        <p><strong>Barcode:</strong> <span id="delete_barcode_info"></span></p>
                        <div class="form-group">
                            <label>Reason for Deletion *</label>
                            <textarea class="form-control" name="delete_reason" id="delete_reason" rows="2" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_inventory" class="btn btn-danger">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Adjust Stock Modal -->
    <div class="modal fade" id="adjustModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-sliders-h"></i> Adjust Quantity</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="item_id" id="adjust_item_id">
                        <p><strong>Product:</strong> <span id="adjust_product_name"></span></p>
                        <div class="form-group">
                            <label>Current Quantity (Units)</label>
                            <input type="text" class="form-control" id="adjust_current_quantity" disabled>
                        </div>
                        <div class="form-group">
                            <label>New Quantity (Units) *</label>
                            <input type="number" class="form-control" name="new_quantity" id="adjust_new_quantity" min="0" required>
                        </div>
                        <div class="form-group">
                            <label>Reason *</label>
                            <textarea class="form-control" name="adjustment_reason" id="adjustment_reason" rows="3" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="adjust_stock" class="btn btn-primary">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.12.9/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js"></script>

    <script>
    // ===== ALL JAVASCRIPT (same as previous version) =====
    // ===== SEQUENTIAL VOICE FILLING SYSTEM =====
    
    var voiceRecognition = null;
    var isVoiceListening = false;
    var isSequentialMode = false;
    var currentFieldIndex = 0;
    var isProcessingField = false;
    var sequentialFields = [];
    
    // Define the fields to fill sequentially
    sequentialFields = [
        { id: 'crud_product_name', label: 'Product Name', type: 'text', indicator: 'field-name-indicator', value: '' },
        { id: 'crud_pp_price', label: 'Pack Cost', type: 'number', indicator: 'field-cost-indicator', value: '' },
        { id: 'crud_s_price', label: 'Pack Selling Price', type: 'number', indicator: 'field-price-indicator', value: '' },
        { id: 'crud_sqpp', label: 'SQPP', type: 'number', indicator: 'field-sqpp-indicator', value: '' },
        { id: 'crud_selling_unit', label: 'Selling Unit', type: 'select', indicator: 'field-unit-indicator', value: '' },
        { id: 'crud_low_stock_threshold', label: 'Low Stock Threshold', type: 'number', indicator: 'field-threshold-indicator', value: '' },
        { id: 'crud_is_pack_product', label: 'Pack Product', type: 'checkbox', indicator: '', value: '' },
        { id: 'crud_selling_price_per_unit', label: 'Selling Price per Unit', type: 'number', indicator: 'field-unitprice-indicator', value: '' },
        { id: 'crud_expiry_notification_days', label: 'Expiry Notification Days', type: 'number', indicator: 'field-expiry-indicator', value: '' },
        { id: 'crud_barcode', label: 'Barcode', type: 'text', indicator: 'field-barcode-indicator', value: '' }
    ];

    // ===== DOM READY =====
    $(document).ready(function() {
        $('#toggleSidebarBtn').click(function() { 
            $('#sidebar').toggleClass('active'); 
        });
        
        // Initialize first tab as active
        if (!$('#addToStock').hasClass('active')) {
            $('#addToStock').addClass('active show');
        }
        
        $('#addToStock, #currentInventory, #history, #alerts').each(function() {
            if ($(this).hasClass('active')) {
                $(this).css('display', 'block');
            } else {
                $(this).css('display', 'none');
            }
        });
        
        // Auto-focus barcode input
        var barcodeInput = document.getElementById('barcode_search_input');
        if (barcodeInput && !/Mobi|Android|iPhone/i.test(navigator.userAgent)) {
            setTimeout(function() { barcodeInput.focus(); }, 500);
        }
    });

    // ===== TAB SWITCHING =====
    function switchStockTab(tabId) {
        if (!tabId) return;
        var panes = ['addToStock', 'currentInventory', 'history', 'alerts'];
        panes.forEach(function(id) {
            var el = document.getElementById(id);
            if (el) { el.classList.remove('active', 'show'); el.style.display = 'none'; }
        });
        document.querySelectorAll('#stockTabs .nav-link').forEach(function(el) {
            if (el) { el.classList.remove('active'); }
        });
        
        var selectedPane = document.getElementById(tabId);
        if (selectedPane) { selectedPane.classList.add('active', 'show'); selectedPane.style.display = 'block'; }
        
        var navLink = document.querySelector('#stockTabs .nav-link[href="#' + tabId + '"]');
        if (navLink) { navLink.classList.add('active'); }
        var navLinkById = document.getElementById(tabId + '-tab');
        if (navLinkById) { navLinkById.classList.add('active'); }
    }

    // ===== START SEQUENTIAL VOICE FILLING =====
    function startSequentialVoiceFill() {
        var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRecognition) {
            showNotification('Voice recognition not supported in this browser.', 'error');
            return;
        }
        
        stopSequentialVoiceFill();
        
        // Reset field states
        currentFieldIndex = 0;
        isProcessingField = false;
        sequentialFields.forEach(function(field) {
            field.value = '';
            var indicator = document.getElementById(field.indicator);
            if (indicator) {
                indicator.className = 'voice-field-indicator waiting';
                indicator.textContent = 'Waiting';
            }
            var input = document.getElementById(field.id);
            if (input) {
                input.classList.remove('field-filled', 'field-active');
                if (field.type === 'checkbox') {
                    input.checked = false;
                }
                if (field.type !== 'checkbox' && field.type !== 'select') {
                    input.value = '';
                }
            }
        });
        
        // Show the status
        var statusDiv = document.getElementById('voiceFillStatus');
        if (statusDiv) {
            statusDiv.style.display = 'block';
            statusDiv.className = 'alert alert-info';
            document.getElementById('voiceFillMessage').textContent = '🎤 Speak the field values one by one. The system will auto-advance after each field.';
        }
        
        document.getElementById('voiceFillBtn').style.display = 'none';
        document.getElementById('voiceFillStopBtn').style.display = 'inline-block';
        
        isSequentialMode = true;
        
        // Start voice recognition with continuous mode
        startContinuousVoiceRecognition();
        
        // Highlight the first field
        highlightCurrentField();
        
        showNotification('🎤 Sequential voice filling started. Speak the value for each field.', 'info');
    }

    // ===== START CONTINUOUS VOICE RECOGNITION =====
    function startContinuousVoiceRecognition() {
        var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRecognition) {
            showNotification('Voice recognition not supported.', 'error');
            return;
        }
        
        stopVoiceRecognition();
        voiceRecognition = new SpeechRecognition();
        voiceRecognition.lang = 'en-US';
        voiceRecognition.continuous = true;
        voiceRecognition.interimResults = true;
        voiceRecognition.maxAlternatives = 5;
        
        voiceRecognition.onstart = function() { 
            isVoiceListening = true; 
        };
        
        voiceRecognition.onerror = function(event) {
            if (event.error === 'not-allowed') {
                showNotification('Microphone access denied. Please allow microphone access.', 'error');
                stopSequentialVoiceFill();
            } else if (event.error === 'no-speech') {
                if (isSequentialMode) {
                    setTimeout(function() {
                        try { voiceRecognition.start(); } catch(e) {}
                    }, 500);
                }
            }
        };
        
        voiceRecognition.onend = function() {
            if (isSequentialMode && isVoiceListening) {
                setTimeout(function() {
                    try { voiceRecognition.start(); } catch(e) {}
                }, 300);
            }
        };
        
        voiceRecognition.onresult = function(event) {
            var finalText = '';
            var maxConfidence = 0;
            
            for (var i = event.resultIndex; i < event.results.length; i++) {
                var result = event.results[i];
                if (result.isFinal) {
                    for (var j = 0; j < result.length; j++) {
                        var transcriptText = result[j].transcript.trim();
                        var confidence = result[j].confidence || 0;
                        if (confidence > maxConfidence) {
                            maxConfidence = confidence;
                            finalText = transcriptText;
                        }
                    }
                }
            }
            
            if (finalText && isSequentialMode) {
                processSequentialVoiceCommand(finalText);
            }
        };
        
        try { voiceRecognition.start(); } catch(e) {
            showNotification('Unable to start voice recognition: ' + e.message, 'error');
        }
    }

    // ===== PROCESS SEQUENTIAL VOICE COMMAND =====
    function processSequentialVoiceCommand(command) {
        if (!command || !isSequentialMode || isProcessingField) return;
        if (currentFieldIndex >= sequentialFields.length) {
            finishSequentialFill();
            return;
        }
        
        var lower = command.toLowerCase().trim();
        
        // Check for special commands
        if (lower.includes('done') || lower.includes('finish') || lower.includes('complete')) {
            finishSequentialFill();
            return;
        }
        
        if (lower.includes('stop')) {
            stopSequentialVoiceFill();
            return;
        }
        
        // Check for field:value pattern
        var fieldPatterns = [
            { pattern: /name\s*[:]\s*(.+)/i, fieldIndex: 0 },
            { pattern: /product\s*name\s*[:]\s*(.+)/i, fieldIndex: 0 },
            { pattern: /cost\s*[:]\s*(.+)/i, fieldIndex: 1 },
            { pattern: /pack\s*cost\s*[:]\s*(.+)/i, fieldIndex: 1 },
            { pattern: /price\s*[:]\s*(.+)/i, fieldIndex: 2 },
            { pattern: /selling\s*price\s*[:]\s*(.+)/i, fieldIndex: 2 },
            { pattern: /sqpp\s*[:]\s*(.+)/i, fieldIndex: 3 },
            { pattern: /unit\s*[:]\s*(.+)/i, fieldIndex: 4 },
            { pattern: /threshold\s*[:]\s*(.+)/i, fieldIndex: 5 },
            { pattern: /barcode\s*[:]\s*(.+)/i, fieldIndex: 9 },
            { pattern: /bar\s*code\s*[:]\s*(.+)/i, fieldIndex: 9 },
            { pattern: /pack\s*product\s*[:]\s*(.+)/i, fieldIndex: 6 },
            { pattern: /unit\s*price\s*[:]\s*(.+)/i, fieldIndex: 7 },
            { pattern: /expiry\s*[:]\s*(.+)/i, fieldIndex: 8 }
        ];
        
        var matched = false;
        for (var i = 0; i < fieldPatterns.length; i++) {
            var match = command.match(fieldPatterns[i].pattern);
            if (match && match[1]) {
                var value = match[1].trim();
                var fieldIdx = fieldPatterns[i].fieldIndex;
                
                if (fieldIdx < currentFieldIndex) {
                    currentFieldIndex = fieldIdx;
                    highlightCurrentField();
                }
                
                fillCurrentField(value);
                matched = true;
                break;
            }
        }
        
        if (!matched && currentFieldIndex < sequentialFields.length) {
            fillCurrentField(command);
        }
    }

    // ===== FILL CURRENT FIELD =====
    function fillCurrentField(value) {
        if (!isSequentialMode) return;
        if (currentFieldIndex >= sequentialFields.length) {
            finishSequentialFill();
            return;
        }
        
        isProcessingField = true;
        var field = sequentialFields[currentFieldIndex];
        var input = document.getElementById(field.id);
        
        if (!input) {
            moveToNextField();
            isProcessingField = false;
            return;
        }
        
        var processedValue = value.trim();
        var success = false;
        var displayValue = processedValue;
        
        if (field.type === 'number') {
            var numMatch = processedValue.match(/(\d+\.?\d*)/);
            if (numMatch) {
                processedValue = numMatch[1];
                displayValue = processedValue;
                success = true;
            } else {
                var wordNum = wordsToNumber(processedValue);
                if (wordNum !== null) {
                    processedValue = wordNum.toString();
                    displayValue = processedValue;
                    success = true;
                } else {
                    showNotification('⚠️ Could not detect a number for ' + field.label + '. Try again.', 'warning');
                    isProcessingField = false;
                    return;
                }
            }
        } else if (field.type === 'select') {
            var lower = processedValue.toLowerCase();
            if (lower.includes('pack')) {
                processedValue = 'pack';
                displayValue = 'Pack';
                success = true;
            } else if (lower.includes('unit') || lower.includes('individual')) {
                processedValue = 'unit';
                displayValue = 'Unit';
                success = true;
            } else {
                showNotification('⚠️ Please say "pack" or "unit" for Selling Unit.', 'warning');
                isProcessingField = false;
                return;
            }
        } else if (field.type === 'checkbox') {
            var lower = processedValue.toLowerCase();
            if (lower.includes('yes') || lower.includes('true') || lower.includes('check') || lower.includes('on') || lower.includes('enable')) {
                input.checked = true;
                processedValue = 'checked';
                displayValue = 'Yes';
                success = true;
            } else if (lower.includes('no') || lower.includes('false') || lower.includes('uncheck') || lower.includes('off') || lower.includes('disable')) {
                input.checked = false;
                processedValue = 'unchecked';
                displayValue = 'No';
                success = true;
            } else {
                showNotification('⚠️ Please say "yes" or "no" for ' + field.label, 'warning');
                isProcessingField = false;
                return;
            }
        } else {
            success = true;
        }
        
        if (!success) {
            isProcessingField = false;
            return;
        }
        
        if (field.type === 'checkbox') {
            input.checked = (processedValue === 'checked');
        } else if (field.type === 'select') {
            for (var i = 0; i < input.options.length; i++) {
                if (input.options[i].value.toLowerCase() === processedValue.toLowerCase()) {
                    input.value = input.options[i].value;
                    break;
                }
            }
        } else {
            input.value = processedValue;
        }
        
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        
        input.classList.remove('field-active');
        input.classList.add('field-filled');
        
        var indicator = document.getElementById(field.indicator);
        if (indicator) {
            indicator.className = 'voice-field-indicator filled';
            indicator.textContent = '✓ Filled';
        }
        
        var statusMessage = '✅ ' + field.label + ': "' + displayValue + '" filled';
        document.getElementById('voiceFillMessage').textContent = statusMessage;
        showNotification(statusMessage, 'success');
        
        currentFieldIndex++;
        
        if (currentFieldIndex >= sequentialFields.length) {
            setTimeout(finishSequentialFill, 500);
        } else {
            setTimeout(highlightCurrentField, 300);
        }
        
        isProcessingField = false;
    }

    // ===== WORDS TO NUMBER HELPER =====
    function wordsToNumber(text) {
        var wordMap = {
            'zero': 0, 'one': 1, 'two': 2, 'three': 3, 'four': 4,
            'five': 5, 'six': 6, 'seven': 7, 'eight': 8, 'nine': 9,
            'ten': 10, 'eleven': 11, 'twelve': 12, 'thirteen': 13,
            'fourteen': 14, 'fifteen': 15, 'sixteen': 16, 'seventeen': 17,
            'eighteen': 18, 'nineteen': 19, 'twenty': 20, 'thirty': 30,
            'forty': 40, 'fifty': 50, 'sixty': 60, 'seventy': 70,
            'eighty': 80, 'ninety': 90, 'hundred': 100, 'thousand': 1000
        };
        
        var words = text.toLowerCase().replace(/[^a-z\s-]/g, '').split(/\s+/);
        var total = 0;
        var current = 0;
        
        for (var i = 0; i < words.length; i++) {
            var word = words[i];
            if (wordMap[word] !== undefined) {
                var num = wordMap[word];
                if (num >= 100) {
                    current = current * num;
                } else {
                    current += num;
                }
            }
        }
        
        total = current;
        return total > 0 ? total : null;
    }

    // ===== MOVE TO NEXT FIELD =====
    function moveToNextField() {
        if (!isSequentialMode) return;
        if (currentFieldIndex >= sequentialFields.length) return;
        
        var field = sequentialFields[currentFieldIndex];
        var indicator = document.getElementById(field.indicator);
        if (indicator && indicator.className !== 'voice-field-indicator filled') {
            indicator.className = 'voice-field-indicator waiting';
            indicator.textContent = 'Skipped';
        }
        var input = document.getElementById(field.id);
        if (input) {
            input.classList.remove('field-active');
        }
        
        currentFieldIndex++;
        showNotification('⏩ Skipped ' + field.label, 'info');
        setTimeout(highlightCurrentField, 300);
    }

    // ===== HIGHLIGHT CURRENT FIELD =====
    function highlightCurrentField() {
        if (!isSequentialMode) return;
        
        sequentialFields.forEach(function(field) {
            var input = document.getElementById(field.id);
            if (input) {
                input.classList.remove('field-active');
            }
            var indicator = document.getElementById(field.indicator);
            if (indicator && !indicator.className.includes('filled')) {
                indicator.className = 'voice-field-indicator waiting';
                indicator.textContent = 'Waiting';
            }
        });
        
        if (currentFieldIndex >= sequentialFields.length) {
            document.getElementById('voiceFillMessage').textContent = '✅ All fields filled! Review and save the product.';
            document.getElementById('voiceProgressFill').style.width = '100%';
            showNotification('✅ All fields filled! Click Save Product to complete.', 'success');
            return;
        }
        
        var field = sequentialFields[currentFieldIndex];
        var input = document.getElementById(field.id);
        if (input) {
            input.classList.add('field-active');
            input.focus();
            if (input.type === 'text' || input.type === 'number') {
                input.select();
            }
        }
        
        var indicator = document.getElementById(field.indicator);
        if (indicator) {
            indicator.className = 'voice-field-indicator active';
            indicator.textContent = 'Active';
        }
        
        document.getElementById('voiceFillMessage').textContent = '🎤 Field ' + (currentFieldIndex + 1) + '/' + sequentialFields.length + ': Speak "' + field.label + '"';
        
        var progress = ((currentFieldIndex) / sequentialFields.length) * 100;
        document.getElementById('voiceProgressFill').style.width = progress + '%';
    }

    // ===== FINISH SEQUENTIAL FILL =====
    function finishSequentialFill() {
        if (!isSequentialMode) return;
        
        stopSequentialVoiceFill();
        
        var statusDiv = document.getElementById('voiceFillStatus');
        if (statusDiv) {
            statusDiv.className = 'alert alert-success';
            document.getElementById('voiceFillMessage').textContent = '✅ All fields filled successfully! Review and click Save Product.';
        }
        document.getElementById('voiceProgressFill').style.width = '100%';
        
        showNotification('✅ Voice filling completed! Review all fields before saving.', 'success');
    }

    // ===== STOP SEQUENTIAL VOICE FILL =====
    function stopSequentialVoiceFill() {
        isSequentialMode = false;
        isProcessingField = false;
        stopVoiceRecognition();
        
        document.getElementById('voiceFillBtn').style.display = 'inline-block';
        document.getElementById('voiceFillStopBtn').style.display = 'none';
        
        var statusDiv = document.getElementById('voiceFillStatus');
        if (statusDiv) {
            statusDiv.style.display = 'none';
        }
        
        sequentialFields.forEach(function(field) {
            var input = document.getElementById(field.id);
            if (input) {
                input.classList.remove('field-active');
            }
        });
        
        showNotification('⏹ Sequential voice filling stopped.', 'info');
    }

    // ===== STOP VOICE RECOGNITION =====
    function stopVoiceRecognition() {
        isVoiceListening = false;
        if (voiceRecognition) {
            try { voiceRecognition.stop(); } catch(e) {}
            voiceRecognition = null;
        }
    }

    // ===== FIELD VOICE RECOGNITION =====
    function startFieldVoiceRecognition(fieldId, fieldLabel) {
        var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRecognition) {
            showNotification('Voice recognition not supported.', 'error');
            return;
        }
        
        var input = document.getElementById(fieldId);
        if (!input) return;
        
        var recognition = new SpeechRecognition();
        recognition.lang = 'en-US';
        recognition.continuous = false;
        recognition.interimResults = true;
        recognition.maxAlternatives = 3;
        
        showNotification('🎤 Speak the ' + fieldLabel + '...', 'info');
        input.classList.add('field-active');
        
        recognition.onresult = function(event) {
            var finalText = '';
            var maxConfidence = 0;
            
            for (var i = event.resultIndex; i < event.results.length; i++) {
                var result = event.results[i];
                if (result.isFinal) {
                    for (var j = 0; j < result.length; j++) {
                        var transcriptText = result[j].transcript.trim();
                        var confidence = result[j].confidence || 0;
                        if (confidence > maxConfidence) {
                            maxConfidence = confidence;
                            finalText = transcriptText;
                        }
                    }
                }
            }
            
            if (finalText) {
                if (input.type === 'number') {
                    var numMatch = finalText.match(/(\d+\.?\d*)/);
                    if (numMatch) {
                        input.value = numMatch[1];
                        input.classList.remove('field-active');
                        input.classList.add('field-filled');
                        showNotification('✅ ' + fieldLabel + ': ' + numMatch[1], 'success');
                    } else {
                        showNotification('⚠️ Could not detect a number. Try again.', 'warning');
                    }
                } else if (fieldId === 'crud_selling_unit') {
                    var lower = finalText.toLowerCase();
                    if (lower.includes('pack')) {
                        input.value = 'pack';
                        input.classList.remove('field-active');
                        input.classList.add('field-filled');
                        showNotification('✅ Selling Unit: Pack', 'success');
                    } else if (lower.includes('unit')) {
                        input.value = 'unit';
                        input.classList.remove('field-active');
                        input.classList.add('field-filled');
                        showNotification('✅ Selling Unit: Unit', 'success');
                    } else {
                        showNotification('⚠️ Please say "pack" or "unit"', 'warning');
                    }
                } else if (fieldId === 'crud_is_pack_product') {
                    var lower = finalText.toLowerCase();
                    if (lower.includes('yes') || lower.includes('true') || lower.includes('check') || lower.includes('on')) {
                        input.checked = true;
                        input.classList.remove('field-active');
                        input.classList.add('field-filled');
                        showNotification('✅ Pack Product: Yes', 'success');
                    } else if (lower.includes('no') || lower.includes('false') || lower.includes('uncheck') || lower.includes('off')) {
                        input.checked = false;
                        input.classList.remove('field-active');
                        input.classList.add('field-filled');
                        showNotification('✅ Pack Product: No', 'success');
                    } else {
                        showNotification('⚠️ Please say "yes" or "no"', 'warning');
                    }
                } else {
                    input.value = finalText;
                    input.classList.remove('field-active');
                    input.classList.add('field-filled');
                    showNotification('✅ ' + fieldLabel + ': ' + finalText, 'success');
                }
                
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        };
        
        recognition.onerror = function(event) {
            input.classList.remove('field-active');
            showNotification('Voice error: ' + event.error, 'error');
        };
        
        recognition.onend = function() {
            input.classList.remove('field-active');
        };
        
        try { recognition.start(); } catch(e) {
            showNotification('Unable to start voice recognition: ' + e.message, 'error');
        }
    }

    // ===== VOICE SEARCH FUNCTIONS =====
    function startAddToStockVoiceRecognition() {
        showNotification('🎤 Speak product name to add to stock...', 'info');
        startVoiceRecognition();
    }
    
    function startInventoryVoiceRecognition() {
        showNotification('🎤 Speak product name to search inventory...', 'info');
        startVoiceRecognition();
    }
    
    function startInventoryBarcodeVoiceRecognition() {
        showNotification('🎤 Speak the barcode numbers...', 'info');
        startVoiceRecognition();
    }
    
    function startAddProductVoiceRecognition() {
        showNotification('🎤 Speak product name to search...', 'info');
        startVoiceRecognition();
    }
    
    function startEditProductVoiceRecognition() {
        showNotification('🎤 Speak product name to edit...', 'info');
        startVoiceRecognition();
    }
    
    function startEditProductNameVoiceRecognition() {
        showNotification('🎤 Speak the product name...', 'info');
        startVoiceRecognition();
    }
    
    function startEditInventoryVoiceRecognition() {
        showNotification('🎤 Speak product name to edit inventory...', 'info');
        startVoiceRecognition();
    }
    
    function startQuantityVoiceRecognition() {
        showNotification('🎤 Speak the quantity number...', 'info');
        startVoiceRecognition();
    }

    // ===== BARCODE FUNCTIONS =====
    function startScanner() { showNotification('📷 Camera scanner requires HTTPS/mobile. Use the barcode input field.', 'warning'); }
    function stopScanner() {}
    function startAddProductScanner() { startScanner(); }
    function stopAddProductScanner() {}
    function startEditProductScanner() { startScanner(); }
    function stopEditProductScanner() {}
    function startInventoryScanner() { startScanner(); }
    function stopInventoryScanner() {}
    function startEditInventoryScanner() { startScanner(); }
    function stopEditInventoryScanner() {}
    
    function searchProductByBarcode(barcode, callback) {
        if (!barcode) { showNotification('No barcode to search', 'error'); return; }
        $.ajax({
            url: 'warehouse_transfer_basic.php',
            type: 'GET',
            data: { barcode_search: barcode },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    handleProductSearchResponse(response, barcode);
                    if (typeof callback === 'function') { callback(response.product); }
                } else {
                    var reversed = barcode.split('').reverse().join('');
                    if (reversed !== barcode) {
                        $.ajax({
                            url: 'warehouse_transfer_basic.php',
                            type: 'GET',
                            data: { barcode_search: reversed },
                            dataType: 'json',
                            success: function(responseRev) {
                                if (responseRev.success) {
                                    showNotification('✅ Product found (mirror): ' + responseRev.product.name, 'success');
                                    handleProductSearchResponse(responseRev, reversed);
                                    if (typeof callback === 'function') { callback(responseRev.product); }
                                } else {
                                    prepareAddProductFormFromBarcode(barcode);
                                    showNotification('No matching product. Add product form ready.', 'info');
                                }
                            }
                        });
                    } else {
                        prepareAddProductFormFromBarcode(barcode);
                        showNotification('No matching product. Add product form ready.', 'info');
                    }
                }
            },
            error: function() { showNotification('Error searching for product', 'error'); }
        });
    }
    
    function handleProductSearchResponse(response, barcode) {
        var product = response.product;
        scannedProductData = product;
        var nameEl = document.getElementById('scannedProductName');
        var codeEl = document.getElementById('scannedProductCode');
        var stockEl = document.getElementById('scannedProductStock');
        var resultDiv = document.getElementById('scanner_result');
        if (nameEl) nameEl.innerHTML = product.name;
        if (codeEl) codeEl.innerHTML = 'Code: ' + product.item_code;
        if (stockEl) stockEl.innerHTML = 'Stock: ' + (product.quantity || 0) + ' units';
        if (resultDiv) resultDiv.style.display = 'block';
        showNotification('Product found: ' + product.name, 'success');
        selectScannedProduct();
    }
    
    var scannedProductData = null;
    
    function selectScannedProduct() {
        if (!scannedProductData) { showNotification('No product scanned', 'error'); return; }
        var product = scannedProductData;
        var select = document.getElementById('product_id');
        if (select) {
            for (var i = 0; i < select.options.length; i++) {
                if (select.options[i].value == product.id) { select.value = product.id; break; }
            }
            $(select).trigger('change');
        }
        var priceField = document.getElementById('selling_price');
        if (priceField) priceField.value = product.s_price || 0;
        var costField = document.getElementById('cost_price');
        if (costField) costField.value = product.pp_price || 0;
        var barcodeField = document.getElementById('batch_barcode');
        if (barcodeField && product.barcode) barcodeField.value = product.barcode;
        var qtyField = document.getElementById('transfer_quantity');
        if (qtyField) qtyField.focus();
        var resultDiv = document.getElementById('scanner_result');
        if (resultDiv) resultDiv.style.display = 'none';
        scannedProductData = null;
    }
    
    function manualBarcodeSearch() {
        var input = document.getElementById('manualBarcodeInput');
        if (!input) return;
        var barcode = input.value.trim();
        if (!barcode) { showNotification('Please enter a barcode', 'warning'); return; }
        searchProductByBarcode(barcode, function(product) {
            if (product) { setTimeout(function() { autoSubmitAddToStock(); }, 500); }
        });
    }
    
    function searchProductByBarcodeManual() {
        var input = document.getElementById('barcode_search_input');
        if (!input) return;
        var barcode = input.value.trim();
        if (!barcode) { showNotification('Please scan or enter a barcode', 'warning'); return; }
        searchProductByBarcode(barcode, function(product) {
            if (product) { setTimeout(function() { autoSubmitAddToStock(); }, 500); }
        });
    }
    
    function prepareAddProductFormFromBarcode(barcode) {
        var barcodeField = document.getElementById('crud_barcode');
        if (barcodeField) {
            barcodeField.value = barcode;
            barcodeField.dispatchEvent(new Event('input', { bubbles: true }));
        }
        return true;
    }
    
    function addProductManualBarcodeSearch() {
        var input = document.getElementById('addProductManualBarcodeInput');
        if (!input) return;
        var barcode = input.value.trim();
        if (!barcode) { showNotification('Please enter a barcode', 'warning'); return; }
        addProductSearchBarcode(barcode);
    }
    
    function addProductSearchBarcode(barcode) {
        searchProductByBarcode(barcode, function(product) {
            if (product) {
                var nameField = document.getElementById('crud_product_name');
                if (nameField) nameField.value = product.name;
                var ppField = document.getElementById('crud_pp_price');
                if (ppField) ppField.value = product.pp_price || 0;
                var sField = document.getElementById('crud_s_price');
                if (sField) sField.value = product.s_price || 0;
                var sqppField = document.getElementById('crud_sqpp');
                if (sqppField) sqppField.value = product.sqpp || 1;
                var barcodeField = document.getElementById('crud_barcode');
                if (barcodeField) barcodeField.value = barcode;
                showNotification('Product details auto-filled from barcode: ' + product.name, 'success');
            }
        });
    }
    
    function editProductManualBarcodeSearch() {
        var input = document.getElementById('editProductManualBarcodeInput');
        if (!input) return;
        var barcode = input.value.trim();
        if (!barcode) { showNotification('Please enter a barcode', 'warning'); return; }
        editProductSearchBarcode(barcode);
    }
    
    function editProductSearchBarcode(barcode) {
        searchProductByBarcode(barcode, function(product) {
            if (product && document.getElementById('edit_product_id') && document.getElementById('edit_product_id').value) {
                var nameField = document.getElementById('edit_product_name');
                if (nameField) nameField.value = product.name;
                var ppField = document.getElementById('edit_pp_price');
                if (ppField) ppField.value = product.pp_price || 0;
                var sField = document.getElementById('edit_s_price');
                if (sField) sField.value = product.s_price || 0;
                var sqppField = document.getElementById('edit_sqpp');
                if (sqppField) sqppField.value = product.sqpp || 1;
                var barcodeField = document.getElementById('edit_barcode');
                if (barcodeField) barcodeField.value = barcode;
                showNotification('Product details auto-filled from barcode: ' + product.name, 'success');
            }
        });
    }
    
    function inventoryManualBarcodeSearch() {
        var input = document.getElementById('inventoryManualBarcodeInput');
        if (!input) return;
        var barcode = input.value.trim();
        if (!barcode) { showNotification('Please enter a barcode', 'warning'); return; }
        inventorySearchBarcode(barcode);
    }
    
    function inventorySearchBarcode(barcode) {
        $.ajax({
            url: 'warehouse_transfer_basic.php',
            type: 'GET',
            data: { inventory_barcode_search: barcode },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var item = response.inventory_item;
                    showNotification('Inventory item found: ' + item.product_name, 'success');
                    var rows = document.querySelectorAll('#inventoryTable tbody tr');
                    for (var i = 0; i < rows.length; i++) {
                        var barcodeCell = rows[i].querySelector('.barcode-display');
                        if (barcodeCell && barcodeCell.textContent.trim() === barcode) {
                            rows[i].classList.add('barcode-highlight');
                            rows[i].scrollIntoView({ behavior: 'smooth', block: 'center' });
                            setTimeout(function() { rows[i].classList.remove('barcode-highlight'); }, 3000);
                            break;
                        }
                    }
                }
            }
        });
    }
    
    function editInventoryManualBarcodeSearch() {
        var input = document.getElementById('editInventoryManualBarcodeInput');
        if (!input) return;
        var barcode = input.value.trim();
        if (!barcode) { showNotification('Please enter a barcode', 'warning'); return; }
        editInventorySearchBarcode(barcode);
    }
    
    function editInventorySearchBarcode(barcode) {
        $.ajax({
            url: 'warehouse_transfer_basic.php',
            type: 'GET',
            data: { inventory_barcode_search: barcode },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var item = response.inventory_item;
                    var idField = document.getElementById('edit_item_id');
                    if (idField) idField.value = item.id;
                    var nameField = document.getElementById('edit_product_name_display');
                    if (nameField) nameField.value = item.product_name;
                    var qtyField = document.getElementById('edit_quantity');
                    if (qtyField) qtyField.value = item.quantity;
                    var priceField = document.getElementById('edit_selling_price');
                    if (priceField) priceField.value = item.price_per_product;
                    var costField = document.getElementById('edit_cost_price');
                    if (costField) costField.value = item.cost_price || 0;
                    var batchField = document.getElementById('edit_batch_code');
                    if (batchField) batchField.value = item.batch_code || '';
                    var expiryField = document.getElementById('edit_expiry_date');
                    if (expiryField) expiryField.value = item.expiry_date || '';
                    var barcodeField = document.getElementById('edit_inventory_barcode');
                    if (barcodeField) barcodeField.value = item.barcode || '';
                    showNotification('Inventory item auto-filled: ' + item.product_name, 'success');
                }
            }
        });
    }

    // ===== AUTO-SUBMIT FUNCTION =====
    function autoSubmitAddToStock() {
        var form = document.getElementById('addToStockForm');
        var productSelect = document.getElementById('product_id');
        var quantity = document.getElementById('transfer_quantity');
        var sellingPrice = document.getElementById('selling_price');
        
        if (form && productSelect && productSelect.value && 
            quantity && quantity.value && parseInt(quantity.value) > 0 &&
            sellingPrice && sellingPrice.value && parseFloat(sellingPrice.value) > 0) {
            showNotification('🔄 Auto-submitting stock addition...', 'info');
            form.submit();
        } else {
            if (quantity && (!quantity.value || parseInt(quantity.value) <= 0)) {
                if (quantity) quantity.focus();
                showNotification('⚠️ Please enter quantity', 'warning');
            } else if (sellingPrice && (!sellingPrice.value || parseFloat(sellingPrice.value) <= 0)) {
                if (sellingPrice) sellingPrice.focus();
                showNotification('⚠️ Please enter selling price', 'warning');
            } else if (productSelect && !productSelect.value) {
                if (productSelect) productSelect.focus();
                showNotification('⚠️ Please select a product', 'warning');
            }
        }
    }

    // ===== NOTIFICATION SYSTEM =====
    function showNotification(message, type) {
        var alertClass = type === 'success' ? 'alert-success' : type === 'error' ? 'alert-danger' : type === 'warning' ? 'alert-warning' : 'alert-info';
        var icon = type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : type === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle';
        
        var notification = document.createElement('div');
        notification.className = 'alert ' + alertClass + ' alert-dismissible fade show';
        notification.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;max-width:450px;box-shadow:0 4px 20px rgba(0,0,0,0.15);border-radius:12px;';
        notification.innerHTML = '<i class="fas ' + icon + ' mr-2"></i> ' + message + '<button type="button" class="close" data-dismiss="alert">&times;</button>';
        
        document.body.appendChild(notification);
        setTimeout(function() { if (notification.parentNode) { notification.remove(); } }, 5000);
    }

    // ===== CRUD FUNCTIONS =====
    function openProductEditModal(product) {
        if (!product) return;
        document.getElementById('edit_product_id').value = product.id;
        document.getElementById('edit_product_name').value = product.name;
        document.getElementById('edit_product_sku').value = product.item_code;
        document.getElementById('edit_pp_price').value = product.pack_cost_price;
        document.getElementById('edit_s_price').value = product.selling_price;
        document.getElementById('edit_sqpp').value = product.pack_quantity;
        document.getElementById('edit_selling_unit').value = product.selling_unit;
        document.getElementById('edit_is_pack_product').checked = (product.is_pack_product == 1);
        document.getElementById('edit_selling_price_per_unit').value = product.selling_price_per_unit || 0;
        document.getElementById('edit_low_stock_threshold').value = product.low_stock_threshold || 5;
        document.getElementById('edit_expiry_notification_days').value = product.expiry_notification_days || 30;
        document.getElementById('edit_barcode').value = product.barcode || '';
        
        var unitPriceField = document.getElementById('edit_unit_price_field');
        if (unitPriceField) {
            if (product.selling_unit == 'unit' && product.is_pack_product == 1) {
                unitPriceField.style.display = 'flex';
            } else { unitPriceField.style.display = 'none'; }
        }
        updateEditProfit();
        $('#productEditModal').modal('show');
    }
    
    function openProductDeleteModal(id, name) {
        document.getElementById('delete_product_id').value = id;
        document.getElementById('delete_product_name_display').innerHTML = name;
        $('#productDeleteModal').modal('show');
    }

    document.addEventListener('DOMContentLoaded', function() {
        var deleteProductForm = document.getElementById('deleteProductForm');
        if (deleteProductForm) {
            deleteProductForm.addEventListener('submit', function() {
                var btn = document.getElementById('confirm_delete_btn');
                if (btn) {
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
                    btn.disabled = true;
                }
            });
        }
    });
    
    function openExpiryEditModal(id, name, expiryDate, quantity, sellingUnit, packQuantity, equivalentUnits, barcode) {
        document.getElementById('expiry_item_id').value = id;
        document.getElementById('expiry_product_name').value = name;
        document.getElementById('expiry_barcode').value = barcode || 'N/A';
        document.getElementById('expiry_current_stock').value = numberFormat(quantity) + ' units';
        document.getElementById('expiry_current_date').value = expiryDate ? new Date(expiryDate).toLocaleDateString('en-GB', {day: 'numeric', month: 'short', year: 'numeric'}) : 'No expiry date set';
        document.getElementById('expiry_new_date').value = expiryDate || '';
        document.getElementById('expiry_edit_reason').value = '';
        document.getElementById('expiry_warning_display_edit').style.display = 'none';
        $('#expiryEditModal').modal('show');
    }
    
    document.getElementById('expiry_new_date')?.addEventListener('change', function() {
        var expiryDate = this.value;
        var warningDisplay = document.getElementById('expiry_warning_display_edit');
        var warningText = document.getElementById('expiry_warning_text');
        if (expiryDate && warningDisplay && warningText) {
            var today = new Date();
            var expDate = new Date(expiryDate);
            var daysDiff = Math.ceil((expDate - today) / (1000 * 60 * 60 * 24));
            if (daysDiff < 0) {
                warningDisplay.style.display = 'block';
                warningDisplay.className = 'alert alert-danger';
                warningText.innerHTML = '<strong>Warning:</strong> This date is in the past! The product will be marked as expired.';
            } else if (daysDiff <= 7) {
                warningDisplay.style.display = 'block';
                warningDisplay.className = 'alert alert-warning';
                warningText.innerHTML = '<strong>Notice:</strong> This product will expire in <strong>' + daysDiff + ' days</strong>.';
            } else if (daysDiff <= 30) {
                warningDisplay.style.display = 'block';
                warningDisplay.className = 'alert alert-info';
                warningText.innerHTML = 'This product expires in <strong>' + daysDiff + ' days</strong>.';
            } else { warningDisplay.style.display = 'none'; }
        } else if (warningDisplay) { warningDisplay.style.display = 'none'; }
    });
    
    function openEditModal(id, name, quantity, sellingPrice, costPrice, batchCode, sellingUnit, packQuantity, equivalentUnits, expiryDate, barcode) {
        document.getElementById('edit_item_id').value = id;
        document.getElementById('edit_product_name_display').value = name;
        document.getElementById('edit_quantity').value = quantity;
        document.getElementById('edit_selling_price').value = sellingPrice;
        document.getElementById('edit_cost_price').value = costPrice;
        document.getElementById('edit_batch_code').value = batchCode || '';
        document.getElementById('edit_expiry_date').value = expiryDate || '';
        document.getElementById('edit_inventory_barcode').value = barcode || '';
        document.getElementById('edit_reason').value = '';
        
        var packInfoDiv = document.getElementById('edit_pack_info');
        if (packInfoDiv) {
            if (sellingUnit == 'unit' && packQuantity > 1) {
                packInfoDiv.style.display = 'block';
                packInfoDiv.innerHTML = '<i class="fas fa-info-circle"></i> This product is sold as individual units. Each pack contains <strong>' + packQuantity + ' units</strong>. Current inventory: <strong>' + equivalentUnits.toLocaleString() + ' units</strong>.';
            } else { packInfoDiv.style.display = 'none'; }
        }
        $('#editModal').modal('show');
    }
    
    function openDeleteModal(id, name, quantity, equivalentUnits, totalValue, sellingUnit, packQuantity, expiryDate, barcode) {
        document.getElementById('delete_item_id').value = id;
        document.getElementById('delete_product_name_display_inv').innerHTML = name;
        document.getElementById('delete_stock_info').innerHTML = numberFormat(quantity) + ' units (' + numberFormat(equivalentUnits) + ' total units)';
        document.getElementById('delete_total_value').innerHTML = '<?php echo $currency_symbol; ?>' + totalValue.toFixed(2);
        document.getElementById('delete_expiry_info').innerHTML = expiryDate ? new Date(expiryDate).toLocaleDateString('en-GB', {day: 'numeric', month: 'short', year: 'numeric'}) : 'N/A';
        document.getElementById('delete_barcode_info').innerHTML = barcode || 'N/A';
        document.getElementById('delete_reason').value = '';
        $('#deleteModal').modal('show');
    }
    
    function openAdjustModal(id, name, currentQuantity, sellingUnit) {
        document.getElementById('adjust_item_id').value = id;
        document.getElementById('adjust_product_name').innerHTML = name;
        document.getElementById('adjust_current_quantity').innerHTML = numberFormat(currentQuantity) + ' units';
        document.getElementById('adjust_new_quantity').value = '';
        document.getElementById('adjustment_reason').value = '';
        $('#adjustModal').modal('show');
    }
    
    function numberFormat(num) { return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ","); }

    // ===== FORM HELPER FUNCTIONS =====
    function updateProductPrice() {
        var select = document.getElementById('product_id');
        if (!select) return;
        var opt = select.options[select.selectedIndex];
        if (!opt || !opt.value) return;
        
        var sellingPrice = parseFloat(opt.getAttribute('data-selling')) || 0;
        var packQty = parseInt(opt.getAttribute('data-pack')) || 1;
        var sellingUnit = opt.getAttribute('data-unit');
        var isPack = opt.getAttribute('data-is-pack') == '1';
        var packCost = parseFloat(opt.getAttribute('data-pack-cost')) || 0;
        var sellingPerUnit = parseFloat(opt.getAttribute('data-selling-per-unit')) || 0;
        var barcode = opt.getAttribute('data-barcode') || '';
        
        var barcodeField = document.getElementById('batch_barcode');
        if (barcodeField && barcode) barcodeField.value = barcode;
        
        var priceLabel = document.getElementById('selling_price_unit_label');
        var costLabel = document.getElementById('cost_price_unit_label');
        var qtyLabel = document.getElementById('quantity_label');
        var priceField = document.getElementById('selling_price');
        var costField = document.getElementById('cost_price');
        
        if (sellingUnit == 'unit' && isPack && packQty > 1) {
            if (priceLabel) priceLabel.innerHTML = 'Per unit price';
            if (costLabel) costLabel.innerHTML = 'Per unit cost';
            if (qtyLabel) qtyLabel.innerHTML = 'Units Received';
            if (priceField) priceField.value = sellingPerUnit > 0 ? sellingPerUnit : '';
            if (costField && packCost > 0) { costField.value = (packCost / packQty).toFixed(2); }
        } else {
            if (priceLabel) priceLabel.innerHTML = 'Per pack price';
            if (costLabel) costLabel.innerHTML = 'Per pack cost';
            if (qtyLabel) qtyLabel.innerHTML = 'Packs Received';
            if (priceField) priceField.value = sellingPrice > 0 ? sellingPrice : '';
            if (costField && packCost > 0) { costField.value = packCost; }
        }
    }
    
    function updatePackInfo() {
        var select = document.getElementById('product_id');
        if (!select) return;
        var opt = select.options[select.selectedIndex];
        if (!opt || !opt.value) return;
        
        var quantity = parseInt(document.getElementById('transfer_quantity')?.value) || 0;
        var packQty = parseInt(opt.getAttribute('data-pack')) || 1;
        var sellingUnit = opt.getAttribute('data-unit');
        var isPack = opt.getAttribute('data-is-pack') == '1';
        var unitConversion = document.getElementById('unit_conversion_display');
        
        if (sellingUnit == 'unit' && isPack && packQty > 1 && quantity > 0) {
            var totalUnits = quantity;
            var totalPacks = Math.floor(totalUnits / packQty);
            var remainingUnits = totalUnits % packQty;
            var displayText = '<i class="fas fa-calculator mr-1"></i> <strong>Stock to be stored:</strong> ' + numberFormat(quantity) + ' units';
            if (totalPacks > 0 && remainingUnits > 0) {
                displayText += ' = ' + totalPacks + ' full pack(s) + ' + remainingUnits + ' units';
            } else if (totalPacks > 0) { displayText += ' = ' + totalPacks + ' pack(s)'; }
            displayText += ' will be added to inventory.';
            if (unitConversion) { unitConversion.innerHTML = displayText; unitConversion.style.display = 'block'; }
        } else if (sellingUnit != 'unit' && quantity > 0) {
            var totalUnits = quantity * packQty;
            var displayText = '<i class="fas fa-calculator mr-1"></i> <strong>Stock to be stored:</strong> ' + numberFormat(quantity) + ' packs × ' + packQty + ' units/pack = <strong>' + numberFormat(totalUnits) + ' units</strong> will be added to inventory.';
            if (unitConversion) { unitConversion.innerHTML = displayText; unitConversion.style.display = 'block'; }
        } else if (unitConversion) { unitConversion.style.display = 'none'; }
        
        var expiryDate = document.getElementById('expiry_date')?.value;
        var expiryWarning = document.getElementById('expiry_warning_display');
        if (expiryWarning && expiryDate) {
            var today = new Date();
            var expDate = new Date(expiryDate);
            var daysDiff = Math.ceil((expDate - today) / (1000 * 60 * 60 * 24));
            if (daysDiff < 0) {
                expiryWarning.innerHTML = '<i class="fas fa-exclamation-circle mr-1"></i> <strong>Warning:</strong> This product has already expired!';
                expiryWarning.className = 'alert alert-danger mt-2';
                expiryWarning.style.display = 'block';
            } else if (daysDiff <= 30) {
                expiryWarning.innerHTML = '<i class="fas fa-clock mr-1"></i> <strong>Notice:</strong> This product expires in <strong>' + daysDiff + ' days</strong>. Consider promoting it.';
                expiryWarning.className = 'alert alert-warning mt-2';
                expiryWarning.style.display = 'block';
            } else { expiryWarning.style.display = 'none'; }
        } else if (expiryWarning) { expiryWarning.style.display = 'none'; }
    }
    
    function updateEditProfit() {
        var packCost = parseFloat(document.getElementById('edit_pp_price')?.value) || 0;
        var sellingPrice = parseFloat(document.getElementById('edit_s_price')?.value) || 0;
        var profitPerPack = sellingPrice - packCost;
        var infoDisplay = document.getElementById('edit_product_info_display');
        if (infoDisplay) {
            infoDisplay.innerHTML = '<i class="fas fa-info-circle"></i> Profit per pack: ' + '<?php echo $currency_symbol; ?>' + profitPerPack.toFixed(2);
        }
    }
    
    // Event listeners
    document.addEventListener('DOMContentLoaded', function() {
        var expiryDate = document.getElementById('expiry_date');
        if (expiryDate) expiryDate.addEventListener('change', updatePackInfo);
        var transferQty = document.getElementById('transfer_quantity');
        if (transferQty) transferQty.addEventListener('input', updatePackInfo);
        var editPp = document.getElementById('edit_pp_price');
        if (editPp) editPp.addEventListener('input', updateEditProfit);
        var editSp = document.getElementById('edit_s_price');
        if (editSp) editSp.addEventListener('input', updateEditProfit);
        var editSqpp = document.getElementById('edit_sqpp');
        if (editSqpp) editSqpp.addEventListener('input', updateEditProfit);
        var editUnit = document.getElementById('edit_selling_unit');
        if (editUnit) editUnit.addEventListener('change', updateEditProfit);
        var editPack = document.getElementById('edit_is_pack_product');
        if (editPack) editPack.addEventListener('change', updateEditProfit);
        
        var crudPack = document.getElementById('crud_is_pack_product');
        if (crudPack) {
            crudPack.addEventListener('change', function() {
                var unitPriceField = document.getElementById('crud_unit_price_field');
                var sellingUnit = document.getElementById('crud_selling_unit');
                if (unitPriceField) {
                    if (this.checked && sellingUnit && sellingUnit.value == 'unit') {
                        unitPriceField.style.display = 'block';
                    } else { unitPriceField.style.display = 'none'; }
                }
            });
        }
        var crudUnit = document.getElementById('crud_selling_unit');
        if (crudUnit) {
            crudUnit.addEventListener('change', function() {
                var isPack = document.getElementById('crud_is_pack_product');
                var unitPriceField = document.getElementById('crud_unit_price_field');
                if (unitPriceField) {
                    if (isPack && isPack.checked && this.value == 'unit') {
                        unitPriceField.style.display = 'block';
                    } else { unitPriceField.style.display = 'none'; }
                }
            });
        }
        
        // Barcode input event listeners
        var manualInput = document.getElementById('manualBarcodeInput');
        if (manualInput) {
            manualInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); manualBarcodeSearch(); }
            });
        }
        var searchInput = document.getElementById('barcode_search_input');
        if (searchInput) {
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); searchProductByBarcodeManual(); }
            });
        }
        var addManualInput = document.getElementById('addProductManualBarcodeInput');
        if (addManualInput) {
            addManualInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); addProductManualBarcodeSearch(); }
            });
        }
        var editManualInput = document.getElementById('editProductManualBarcodeInput');
        if (editManualInput) {
            editManualInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); editProductManualBarcodeSearch(); }
            });
        }
        var invManualInput = document.getElementById('inventoryManualBarcodeInput');
        if (invManualInput) {
            invManualInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); inventoryManualBarcodeSearch(); }
            });
        }
        var editInvManualInput = document.getElementById('editInventoryManualBarcodeInput');
        if (editInvManualInput) {
            editInvManualInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); editInventoryManualBarcodeSearch(); }
            });
        }
    });
    </script>
</body>
</html>
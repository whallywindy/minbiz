<?php
session_start();
include '../db_connect.php';
require_once __DIR__ . '/includes/dashboard_route.php';

// =============================================
// VIDEO HELPER - Include with fallback
// =============================================
if (file_exists('includes/video_helper.php')) {
    include_once 'includes/video_helper.php';
} else {
    // Define fallback functions if helper doesn't exist
    function getOnboardingVideos($conn, $page_name) { 
        $videos = [];
        $table_check = $conn->query("SHOW TABLES LIKE 'video_tutorials'");
        if (!$table_check || $table_check->num_rows == 0) {
            return $videos;
        }
        $stmt = $conn->prepare("SELECT * FROM video_tutorials WHERE page_name = ? AND section = 'onboarding' AND is_active = 1 ORDER BY step_order ASC");
        if (!$stmt) return $videos;
        $stmt->bind_param("s", $page_name);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if ($row['video_type'] == 'youtube') {
                $row['embed_url'] = 'https://www.youtube.com/embed/' . $row['video_url'] . '?autoplay=0&rel=0&modestbranding=1';
            }
            $videos[] = $row;
        }
        $stmt->close();
        return $videos;
    }
    function getVideoTutorials($conn, $page_name, $section = null) { return []; }
    function getVideoById($conn, $video_id) { return null; }
    function hasOnboardingVideos($conn, $page_name) { return false; }
    function getVideoCount($conn, $page_name, $section = null) { return 0; }
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$tenant_id = $_SESSION['tenant_id'] ?? 0;
$company_name = $_SESSION['company_name'] ?? 'Company';
$product_category = $_SESSION['product_category'] ?? 'plus';
$subscription_status = $_SESSION['subscription_status'] ?? 'trial';
$trial_days_left = isset($_SESSION['trial_days_left']) ? (int)$_SESSION['trial_days_left'] : 0;
$trial_end_date = $_SESSION['trial_end_date'] ?? '';

// ============ TRIAL & SUBSCRIPTION EXPIRY CHECK ============
$stmt = $conn->prepare("SELECT subscription_status, trial_end_date, subscription_end_date, max_users, product_category FROM tenants WHERE id = ?");
$stmt->bind_param("i", $tenant_id);
$stmt->execute();
$tenant_check = $stmt->get_result()->fetch_assoc();
$stmt->close();
$dashboard_home_url = getDashboardHomeUrl($tenant_check['product_category'] ?? $product_category);

$current_date = date('Y-m-d H:i:s');
$trial_expired = false;
$subscription_active = false;
$redirect_to_subscription = false;
$days_left_trial = 0;
$days_left_subscription = 0;
$show_trial_banner = false;
$show_subscription_banner = false;
$trial_end_date_display = $trial_end_date;
$subscription_end_date = '';
$max_users = isset($tenant_check['max_users']) ? (int)$tenant_check['max_users'] : 3;

if ($tenant_check) {
    $trial_end = $tenant_check['trial_end_date'] ?? null;
    $sub_end = $tenant_check['subscription_end_date'] ?? null;
    $status = $tenant_check['subscription_status'] ?? 'trial';
    
    if ($trial_end && $trial_end < $current_date && $status != 'active') {
        $trial_expired = true;
        $redirect_to_subscription = true;
    } elseif ($trial_end && $trial_end >= $current_date && $status != 'active') {
        $days_left_trial = max(0, ceil((strtotime($trial_end) - strtotime($current_date)) / 86400));
        $show_trial_banner = true;
        $trial_end_date_display = $trial_end;
    }
    
    if ($sub_end && $sub_end >= $current_date && $status == 'active') {
        $subscription_active = true;
        $days_left_subscription = max(0, ceil((strtotime($sub_end) - strtotime($current_date)) / 86400));
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
    $_SESSION['tenant_id'] = $tenant_id;
    header("Location: subscription.php?expired=1");
    exit();
}

// Function to get settings from database
function getSetting($conn, $tenant_id, $key, $default = '') {
    $stmt = $conn->prepare("SELECT setting_value FROM business_settings WHERE tenant_id = ? AND setting_key = ?");
    $stmt->bind_param("is", $tenant_id, $key);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['setting_value'] ?? $default;
    }
    return $default;
}

// Get all settings from database
$currency = getSetting($conn, $tenant_id, 'currency', 'NGN');
$currency_symbol = getSetting($conn, $tenant_id, 'currency_symbol', '₦');
$country = getSetting($conn, $tenant_id, 'country', 'Nigeria');
$timezone = getSetting($conn, $tenant_id, 'timezone', 'Africa/Lagos');
$date_format = getSetting($conn, $tenant_id, 'date_format', 'Y-m-d');
$language = getSetting($conn, $tenant_id, 'language', 'en');
$multi_user_mode = getSetting($conn, $tenant_id, 'multi_user_mode', 'enabled');

date_default_timezone_set($timezone);
$today_date = date($date_format);

// Check if cost_price column exists
$has_cost_price = false;
$check = $conn->query("SHOW COLUMNS FROM finished_goods_store LIKE 'cost_price'");
if ($check && $check->num_rows > 0) {
    $has_cost_price = true;
}

// ============ USER LIMIT CHECK FUNCTION ============
function checkUserLimit($conn, $tenant_id) {
    $stmt = $conn->prepare("SELECT max_users FROM tenants WHERE id = ?");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $max_users = 1;
    if ($row = $result->fetch_assoc()) {
        $max_users = isset($row['max_users']) ? (int)$row['max_users'] : 1;
    }
    $stmt->close();
    
    $staff_stmt = $conn->prepare("SELECT COUNT(*) as count FROM staff WHERE tenant_id = ? AND is_active = 1");
    $staff_stmt->bind_param("i", $tenant_id);
    $staff_stmt->execute();
    $staff_count = ($staff_stmt->get_result()->fetch_assoc())['count'] ?? 0;
    $staff_stmt->close();
    
    return [
        'max_users' => $max_users,
        'active_staff' => $staff_count,
        'slots_available' => max(0, $max_users - $staff_count),
        'limit_reached' => ($staff_count >= $max_users)
    ];
}

// ============ FUNCTION TO GET STAFF COUNTS ============
function getStaffCounts($conn, $tenant_id) {
    $stmt = $conn->prepare("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as pending
        FROM staff WHERE tenant_id = ?");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $counts = $result->fetch_assoc();
    $stmt->close();
    return [
        'total' => (int)($counts['total'] ?? 0),
        'active' => (int)($counts['active'] ?? 0),
        'pending' => (int)($counts['pending'] ?? 0)
    ];
}

// ====================================================================================
// FUNCTION TO CHECK IF PRODUCTS EXIST (for onboarding)
// ====================================================================================
function hasProducts($conn, $tenant_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM finished_goods_store WHERE tenant_id = ? AND quantity > 0");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $count = isset($row['count']) ? (int)$row['count'] : 0;
    $stmt->close();
    return $count > 0;
}

// ====================================================================================
// FUNCTION TO CHECK IF CUSTOMERS EXIST (for onboarding)
// ====================================================================================
function hasCustomers($conn, $tenant_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM customers WHERE tenant_id = ?");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $count = isset($row['count']) ? (int)$row['count'] : 0;
    $stmt->close();
    return $count > 0;
}

// ====================================================================================
// FUNCTION TO GET EXPIRING ITEMS
// ====================================================================================
function getExpiringItems($conn, $tenant_id) {
    $items = [];
    
    $check_column = $conn->query("SHOW COLUMNS FROM finished_goods_store LIKE 'expiry_date'");
    if (!$check_column || $check_column->num_rows == 0) {
        return $items;
    }
    
    $current_date = date('Y-m-d');
    
    $stmt = $conn->prepare("SELECT fgs.id, fgs.product_id, fgs.product_name, fgs.quantity, fgs.price_per_product, 
                     fgs.selling_unit, fgs.pack_quantity, fgs.expiry_date, fgs.batch_code, fgs.barcode,
                     COALESCE(pi.low_stock_threshold, 5) as low_stock_threshold,
                     COALESCE(pi.expiry_notification_days, 30) as expiry_notification_days
              FROM finished_goods_store fgs
              LEFT JOIN product_items pi ON fgs.product_id = pi.id
              WHERE fgs.tenant_id = ? 
              AND fgs.expiry_date IS NOT NULL 
              AND fgs.expiry_date <= DATE_ADD(?, INTERVAL COALESCE(pi.expiry_notification_days, 30) DAY)
              AND fgs.quantity > 0
              ORDER BY fgs.expiry_date ASC");
    $stmt->bind_param("is", $tenant_id, $current_date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $expiry_date = $row['expiry_date'];
            $days_until_expiry = (strtotime($expiry_date) - time()) / 86400;
            $row['days_until_expiry'] = ceil($days_until_expiry);
            
            if ($row['days_until_expiry'] <= 0) {
                $row['status'] = 'expired';
                $row['status_label'] = 'EXPIRED';
                $row['status_class'] = 'badge badge-dark';
                $row['row_class'] = 'table-danger';
            } elseif ($row['days_until_expiry'] <= 7) {
                $row['status'] = 'urgent';
                $row['status_label'] = 'URGENT';
                $row['status_class'] = 'badge badge-danger';
                $row['row_class'] = 'table-danger';
            } elseif ($row['days_until_expiry'] <= (int)$row['expiry_notification_days']) {
                $row['status'] = 'warning';
                $row['status_label'] = 'Soon';
                $row['status_class'] = 'badge badge-warning';
                $row['row_class'] = 'table-warning';
            } else {
                $row['status'] = 'ok';
                $row['status_label'] = 'OK';
                $row['status_class'] = 'badge badge-success';
                $row['row_class'] = '';
            }
            
            $items[] = $row;
        }
    }
    $stmt->close();
    
    usort($items, function($a, $b) {
        $status_order = ['expired' => 1, 'urgent' => 2, 'warning' => 3, 'ok' => 4];
        $a_order = $status_order[$a['status']] ?? 4;
        $b_order = $status_order[$b['status']] ?? 4;
        if ($a_order != $b_order) {
            return $a_order - $b_order;
        }
        return $a['days_until_expiry'] - $b['days_until_expiry'];
    });
    
    return $items;
}

// ============ PROFIT REPORTING FUNCTIONS ============

function getStoreStockValueByCost($conn, $tenant_id, $has_cost_price) { 
    if (!$conn) return 0.0; 
    if ($has_cost_price) {
        $stmt = $conn->prepare("SELECT COALESCE(SUM(quantity * cost_price), 0) as total_value FROM finished_goods_store WHERE tenant_id = ? AND quantity > 0");
        $stmt->bind_param("i", $tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            return isset($row['total_value']) && is_numeric($row['total_value']) ? (float)$row['total_value'] : 0.0;
        }
    } else {
        $stmt = $conn->prepare("SELECT COALESCE(SUM(quantity * price_per_product * 0.7), 0) as total_value FROM finished_goods_store WHERE tenant_id = ? AND quantity > 0");
        $stmt->bind_param("i", $tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            return isset($row['total_value']) && is_numeric($row['total_value']) ? (float)$row['total_value'] : 0.0;
        }
    }
    return 0.0; 
}

function getStoreStockValueBySelling($conn, $tenant_id) { 
    if (!$conn) return 0.0; 
    $stmt = $conn->prepare("SELECT COALESCE(SUM(quantity * price_per_product), 0) as total_value FROM finished_goods_store WHERE tenant_id = ? AND quantity > 0");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return isset($row['total_value']) && is_numeric($row['total_value']) ? (float)$row['total_value'] : 0.0;
    }
    return 0.0; 
}

function getTotalProjectedProfit($conn, $tenant_id, $has_cost_price) {
    if (!$conn) return 0.0;
    if ($has_cost_price) {
        $stmt = $conn->prepare("SELECT COALESCE(SUM((price_per_product - cost_price) * quantity), 0) as total_profit FROM finished_goods_store WHERE tenant_id = ? AND quantity > 0");
        $stmt->bind_param("i", $tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            return isset($row['total_profit']) && is_numeric($row['total_profit']) ? (float)$row['total_profit'] : 0.0;
        }
    } else {
        $stmt = $conn->prepare("SELECT COALESCE(SUM(price_per_product * quantity * 0.3), 0) as total_profit FROM finished_goods_store WHERE tenant_id = ? AND quantity > 0");
        $stmt->bind_param("i", $tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            return isset($row['total_profit']) && is_numeric($row['total_profit']) ? (float)$row['total_profit'] : 0.0;
        }
    }
    return 0.0;
}

function getSalesProfitByPeriod($conn, $tenant_id, $period, $has_cost_price) {
    $profit_data = ['total_profit' => 0.0, 'transaction_count' => 0, 'items_sold' => 0];
    
    $date_condition = '';
    switch($period) {
        case 'today': $date_condition = "DATE(o.order_date) = CURDATE()"; break;
        case 'yesterday': $date_condition = "DATE(o.order_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)"; break;
        case 'last7days': $date_condition = "o.order_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)"; break;
        case 'last14days': $date_condition = "o.order_date >= DATE_SUB(NOW(), INTERVAL 14 DAY)"; break;
        case 'last30days': $date_condition = "o.order_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)"; break;
        case 'this_month': $date_condition = "MONTH(o.order_date) = MONTH(CURDATE()) AND YEAR(o.order_date) = YEAR(CURDATE())"; break;
        case 'last_month': $date_condition = "MONTH(o.order_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(o.order_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))"; break;
        default: $date_condition = "1=1";
    }
    
    $query = "SELECT 
        COALESCE(COUNT(DISTINCT o.id), 0) as transaction_count, 
        COALESCE(SUM(oi.quantity), 0) as items_sold,
        COALESCE(SUM(
            CASE 
                WHEN oi.profit IS NOT NULL AND oi.profit > 0 THEN oi.profit
                ELSE 0
            END
        ), 0) as total_profit
        FROM orders o 
        LEFT JOIN order_items oi ON o.id = oi.order_id 
        WHERE o.tenant_id = ? 
        AND o.status != 'cancelled' 
        AND $date_condition";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $profit_data['total_profit'] = isset($row['total_profit']) && is_numeric($row['total_profit']) ? (float)$row['total_profit'] : 0.0;
        $profit_data['transaction_count'] = isset($row['transaction_count']) ? (int)$row['transaction_count'] : 0;
        $profit_data['items_sold'] = isset($row['items_sold']) ? (int)$row['items_sold'] : 0;
    }
    $stmt->close();
    return $profit_data;
}

function getChartProfitData($conn, $tenant_id, $days = 7, $has_cost_price) {
    $data = [];
    $labels = [];
    $values = [];
    
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $labels[] = date('M d', strtotime("-$i days"));
        
        $query = "SELECT COALESCE(SUM(
            CASE 
                WHEN oi.profit IS NOT NULL AND oi.profit > 0 THEN oi.profit
                ELSE 0
            END
        ), 0) as profit 
        FROM orders o 
        LEFT JOIN order_items oi ON o.id = oi.order_id 
        WHERE o.tenant_id = ? 
        AND o.status != 'cancelled' 
        AND DATE(o.order_date) = ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("is", $tenant_id, $date);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $profit = isset($row['profit']) && is_numeric($row['profit']) ? (float)$row['profit'] : 0.0;
            $values[] = $profit;
            $data[] = ['date' => $date, 'profit' => $profit];
        } else {
            $values[] = 0.0;
            $data[] = ['date' => $date, 'profit' => 0.0];
        }
        $stmt->close();
    }
    
    return [
        'labels' => $labels,
        'values' => $values,
        'data' => $data,
        'total_profit' => array_sum($values)
    ];
}

function getDailyProfitData($conn, $tenant_id, $days = 30, $has_cost_price) {
    $data = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $query = "SELECT COALESCE(SUM(
            CASE 
                WHEN oi.profit IS NOT NULL AND oi.profit > 0 THEN oi.profit
                ELSE 0
            END
        ), 0) as profit 
        FROM orders o 
        LEFT JOIN order_items oi ON o.id = oi.order_id 
        WHERE o.tenant_id = ? 
        AND o.status != 'cancelled' 
        AND DATE(o.order_date) = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("is", $tenant_id, $date);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $data[] = [
                'date' => $date, 
                'profit' => isset($row['profit']) && is_numeric($row['profit']) ? (float)$row['profit'] : 0.0
            ];
        } else {
            $data[] = ['date' => $date, 'profit' => 0.0];
        }
        $stmt->close();
    }
    return $data;
}

function getMonthlyProfitSummary($conn, $tenant_id, $months = 6, $has_cost_price) {
    $data = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $month_start = date('Y-m-01', strtotime("-$i months"));
        $month_end = date('Y-m-t', strtotime("-$i months"));
        $month_name = date('M Y', strtotime($month_start));
        
        $query = "SELECT COALESCE(SUM(
            CASE 
                WHEN oi.profit IS NOT NULL AND oi.profit > 0 THEN oi.profit
                ELSE 0
            END
        ), 0) as profit 
        FROM orders o 
        LEFT JOIN order_items oi ON o.id = oi.order_id 
        WHERE o.tenant_id = ? 
        AND o.status != 'cancelled' 
        AND DATE(o.order_date) >= ? 
        AND DATE(o.order_date) <= ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("iss", $tenant_id, $month_start, $month_end);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $profit_value = isset($row['profit']) && is_numeric($row['profit']) ? (float)$row['profit'] : 0.0;
            $data[] = [
                'month' => $month_name,
                'profit' => $profit_value
            ];
        } else {
            $data[] = ['month' => $month_name, 'profit' => 0.0];
        }
        $stmt->close();
    }
    return $data;
}

function getTotalSalesProfit($conn, $tenant_id, $has_cost_price) {
    $query = "SELECT COALESCE(SUM(
        CASE 
            WHEN oi.profit IS NOT NULL AND oi.profit > 0 THEN oi.profit
            ELSE 0
        END
    ), 0) as total_profit 
    FROM orders o 
    LEFT JOIN order_items oi ON o.id = oi.order_id 
    WHERE o.tenant_id = ? AND o.status != 'cancelled'";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return isset($row['total_profit']) && is_numeric($row['total_profit']) ? (float)$row['total_profit'] : 0.0;
    }
    return 0.0;
}

function getTopProfitProducts($conn, $tenant_id, $limit = 5, $period = 'last30days', $has_cost_price) {
    $date_condition = '';
    switch($period) {
        case 'last7days': $date_condition = "o.order_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)"; break;
        case 'last30days': $date_condition = "o.order_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)"; break;
        case 'last60days': $date_condition = "o.order_date >= DATE_SUB(NOW(), INTERVAL 60 DAY)"; break;
        default: $date_condition = "1=1";
    }
    
    $query = "SELECT 
        p.name as product_name, 
        p.item_code, 
        COALESCE(SUM(oi.quantity), 0) as quantity_sold, 
        COALESCE(SUM(
            CASE 
                WHEN oi.profit IS NOT NULL AND oi.profit > 0 THEN oi.profit
                ELSE 0
            END
        ), 0) as total_profit 
        FROM orders o 
        LEFT JOIN order_items oi ON oi.order_id = o.id 
        LEFT JOIN product_items p ON oi.product_id = p.id 
        WHERE o.tenant_id = ? 
        AND o.status != 'cancelled' 
        AND $date_condition 
        GROUP BY p.id 
        ORDER BY total_profit DESC 
        LIMIT ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $tenant_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $products = [];
    while ($row = $result->fetch_assoc()) {
        $row['total_profit'] = isset($row['total_profit']) && is_numeric($row['total_profit']) ? (float)$row['total_profit'] : 0.0;
        $row['quantity_sold'] = isset($row['quantity_sold']) ? (int)$row['quantity_sold'] : 0;
        $row['profit_per_unit'] = $row['quantity_sold'] > 0 ? ($row['total_profit'] / $row['quantity_sold']) : 0;
        $products[] = $row;
    }
    $stmt->close();
    return $products;
}

function getOrderStatusSummary($conn, $tenant_id) {
    $query = "SELECT status, COUNT(*) as count FROM orders WHERE tenant_id = ? GROUP BY status";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $summary = [];
    while ($row = $result->fetch_assoc()) {
        $summary[$row['status']] = (int)$row['count'];
    }
    $stmt->close();
    return $summary;
}

function getStoreItemsCount($conn, $tenant_id) { 
    if (!$conn) return 0; 
    $stmt = $conn->prepare("SELECT COALESCE(COUNT(*), 0) as count FROM finished_goods_store WHERE tenant_id = ? AND quantity > 0");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return isset($row['count']) ? (int)$row['count'] : 0;
    }
    return 0; 
}

function getTodaySales($conn, $tenant_id) {
    if (!$conn) return 0.0;
    $query = "SELECT COALESCE(SUM(oi.quantity * oi.price_per_product), 0) as total FROM orders o LEFT JOIN order_items oi ON o.id = oi.order_id WHERE o.tenant_id = ? AND o.status != 'cancelled' AND DATE(o.order_date) = CURDATE()";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return isset($row['total']) && is_numeric($row['total']) ? (float)$row['total'] : 0.0;
    }
    return 0.0;
}

// ====================================================================================
// getLowStockItems FUNCTION - Uses threshold from product_items table
// ====================================================================================
function getLowStockItems($conn, $tenant_id) {
    $stmt = $conn->prepare("SELECT 
        fgs.product_name, 
        fgs.product_id, 
        fgs.quantity, 
        fgs.price_per_product, 
        fgs.selling_unit, 
        fgs.pack_quantity,
        fgs.tenant_id,
        COALESCE(pi.low_stock_threshold, 5) as low_stock_threshold
        FROM finished_goods_store fgs 
        LEFT JOIN product_items pi ON fgs.product_id = pi.id
        WHERE fgs.tenant_id = ? 
        AND fgs.quantity > 0 
        ORDER BY fgs.quantity ASC 
        LIMIT 20");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $row['quantity'] = (int)$row['quantity'];
        $row['pack_quantity'] = (int)($row['pack_quantity'] ?? 1);
        $row['selling_unit'] = $row['selling_unit'] ?? 'pack';
        $row['low_stock_threshold'] = isset($row['low_stock_threshold']) ? (int)$row['low_stock_threshold'] : 5;
        
        if ($row['selling_unit'] == 'unit' && $row['pack_quantity'] > 1) {
            $row['total_units'] = $row['quantity'] * $row['pack_quantity'];
            $row['display_quantity'] = $row['total_units'] . ' units (' . $row['quantity'] . ' packs)';
            $row['is_low'] = ($row['total_units'] <= $row['low_stock_threshold']);
        } else {
            $row['total_units'] = $row['quantity'];
            $row['display_quantity'] = $row['quantity'] . ' packs';
            $row['is_low'] = ($row['quantity'] <= $row['low_stock_threshold']);
        }
        
        $items[] = $row;
    }
    $stmt->close();
    
    $low_items = array_filter($items, function($item) {
        return $item['is_low'] === true;
    });
    
    usort($low_items, function($a, $b) {
        return $a['total_units'] - $b['total_units'];
    });
    
    return array_slice($low_items, 0, 10);
}

function getCustomerDebtStatistics($conn, $tenant_id) {
    $stats = ['total_debtors_count' => 0, 'total_creditors_count' => 0, 'largest_debt' => 0.0, 'largest_credit' => 0.0];
    if (!$conn) return $stats;
    $stmt = $conn->prepare("SELECT ABS(balance) as amount_owed FROM customers WHERE tenant_id = ? AND balance < 0");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        $stats['total_debtors_count'] = $result->num_rows;
        while ($row = $result->fetch_assoc()) {
            $balance = isset($row['amount_owed']) && is_numeric($row['amount_owed']) ? (float)$row['amount_owed'] : 0.0;
            if ($balance > $stats['largest_debt']) $stats['largest_debt'] = $balance;
        }
    }
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT balance as amount_we_owe FROM customers WHERE tenant_id = ? AND balance > 0");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        $stats['total_creditors_count'] = $result->num_rows;
        while ($row = $result->fetch_assoc()) {
            $balance = isset($row['amount_we_owe']) && is_numeric($row['amount_we_owe']) ? (float)$row['amount_we_owe'] : 0.0;
            if ($balance > $stats['largest_credit']) $stats['largest_credit'] = $balance;
        }
    }
    $stmt->close();
    return $stats;
}

// ============ FETCH ALL DATA ============
$store_value_cost = getStoreStockValueByCost($conn, $tenant_id, $has_cost_price);
$store_value_selling = getStoreStockValueBySelling($conn, $tenant_id);
$store_items = getStoreItemsCount($conn, $tenant_id);
$total_projected_profit = getTotalProjectedProfit($conn, $tenant_id, $has_cost_price);
$today_sales = getTodaySales($conn, $tenant_id);
$low_stock_items = getLowStockItems($conn, $tenant_id);
$expiring_items = getExpiringItems($conn, $tenant_id);
$order_status_summary = getOrderStatusSummary($conn, $tenant_id);
$customer_debt_stats = getCustomerDebtStatistics($conn, $tenant_id);

// Get profit data
$total_sales_profit = getTotalSalesProfit($conn, $tenant_id, $has_cost_price);

$profit_periods = ['today', 'yesterday', 'last7days', 'last14days', 'last30days', 'this_month', 'last_month'];
$profit_data = [];
foreach ($profit_periods as $period) {
    $profit_data[$period] = getSalesProfitByPeriod($conn, $tenant_id, $period, $has_cost_price);
}

// ============ GET CHART DATA FOR DIFFERENT PERIODS ============
$chart_periods = [
    'last7days' => 'Last 7 Days',
    'last14days' => 'Last 14 Days', 
    'last30days' => 'Last 30 Days',
    'last60days' => 'Last 60 Days'
];

$chart_data = [];
foreach ($chart_periods as $period => $label) {
    $days = (int)str_replace('last', '', str_replace('days', '', $period));
    $chart_data[$period] = getChartProfitData($conn, $tenant_id, $days, $has_cost_price);
    $chart_data[$period]['label'] = $label;
}

$daily_profit_data = getDailyProfitData($conn, $tenant_id, 30, $has_cost_price);
$monthly_profit_data = getMonthlyProfitSummary($conn, $tenant_id, 6, $has_cost_price);
$top_products = getTopProfitProducts($conn, $tenant_id, 5, 'last30days', $has_cost_price);

function safe_format($num): string { 
    $numeric = is_numeric($num) ? (float)$num : 0.0;
    return number_format($numeric, 2, '.', ',');
}

function formatQuantityLabel($quantity, $selling_unit, $pack_quantity = 1): string {
    $is_unit_sale = ($selling_unit === 'unit' && (int)$pack_quantity > 1);
    $unit_word = $is_unit_sale ? 'Unit' : 'Pack';
    $plural_word = $is_unit_sale ? 'Units' : 'Packs';

    return (int)$quantity . ' ' . ((int)$quantity === 1 ? $unit_word : $plural_word);
}

$logo_exists = file_exists('assets/images/logo.png');

// ============ STAFF & FEEDBACK DATA ============
$staff_members = [];
$staff_query = "SELECT s.*, sr.role_name 
                FROM staff s
                LEFT JOIN staff_roles sr ON s.role_id = sr.id
                WHERE s.tenant_id = ? AND s.is_active = 1
                ORDER BY s.created_at DESC LIMIT 5";
$staff_stmt = $conn->prepare($staff_query);
if ($staff_stmt) {
    $staff_stmt->bind_param("i", $tenant_id);
    $staff_stmt->execute();
    $staff_result = $staff_stmt->get_result();
    if ($staff_result) {
        while ($row = $staff_result->fetch_assoc()) {
            $staff_members[] = $row;
        }
    }
    $staff_stmt->close();
}

$available_roles = [];
$roles_result = $conn->query("SELECT * FROM staff_roles");
if ($roles_result) {
    while ($row = $roles_result->fetch_assoc()) {
        $available_roles[] = $row;
    }
}

$departments = [];
$dept_query = "SELECT * FROM staff_departments WHERE tenant_id = ?";
$dept_stmt = $conn->prepare($dept_query);
if ($dept_stmt) {
    $dept_stmt->bind_param("i", $tenant_id);
    $dept_stmt->execute();
    $dept_result = $dept_stmt->get_result();
    if ($dept_result) {
        while ($row = $dept_result->fetch_assoc()) {
            $departments[] = $row;
        }
    }
    $dept_stmt->close();
}

$recent_feedback = [];
$feedback_stmt = $conn->prepare("SELECT * FROM feedback WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 5");
if ($feedback_stmt) {
    $feedback_stmt->bind_param("i", $tenant_id);
    $feedback_stmt->execute();
    $feedback_result = $feedback_stmt->get_result();
    if ($feedback_result) {
        while ($row = $feedback_result->fetch_assoc()) {
            $recent_feedback[] = $row;
        }
    }
    $feedback_stmt->close();
}

$count_stmt = $conn->prepare("SELECT 
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) as reviewed,
    SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
    COUNT(*) as total
    FROM feedback WHERE tenant_id = ?");
$feedback_counts = ['total' => 0, 'pending' => 0, 'reviewed' => 0, 'resolved' => 0];
if ($count_stmt) {
    $count_stmt->bind_param("i", $tenant_id);
    $count_stmt->execute();
    $result = $count_stmt->get_result();
    if ($result) {
        $feedback_counts = $result->fetch_assoc();
        if (!$feedback_counts) {
            $feedback_counts = ['total' => 0, 'pending' => 0, 'reviewed' => 0, 'resolved' => 0];
        }
    }
    $count_stmt->close();
}

// ============ GET USER LIMIT INFO FOR DASHBOARD ============
$user_limit_info = checkUserLimit($conn, $tenant_id);
$staff_counts = getStaffCounts($conn, $tenant_id);

// ============ MENU ITEMS - PLUS DASHBOARD ============
$menu_items = [
    ['icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard', 'link' => $dashboard_home_url, 'active' => true],
    ['icon' => 'fas fa-boxes', 'label' => 'Product Management', 'link' => 'warehouse_transfer.php'],
    ['icon' => 'fas fa-users', 'label' => 'Customer Management', 'link' => 'customer_management.php'],
    ['icon' => 'fas fa-user-tie', 'label' => 'Staff Management', 'link' => 'staff_management.php'],
    ['icon' => 'fas fa-comment-dots', 'label' => 'Feedback (Complaint)', 'link' => 'feedback.php'],
    ['icon' => 'fas fa-money-bill', 'label' => 'Payments/Receipts', 'link' => 'record_payment.php'],
    ['icon' => 'fas fa-shopping-cart', 'label' => 'New Sales/Returns', 'link' => 'new_order.php'],
    ['icon' => 'fas fa-user-circle', 'label' => 'Profile', 'link' => 'profile.php'],
    ['icon' => 'fas fa-cog', 'label' => 'Settings', 'link' => 'settings.php'],
    ['icon' => 'fas fa-play-circle', 'label' => 'Video Tutorials', 'link' => 'video_tutorials.php', 'badge' => 'NEW']
];

// ✅ UPDATED: Promo theme changed to Expansion Promo, end date changed to 23rd September 2026
$promo_end_time = strtotime('2026-09-23 23:59:00');
$current_time = time();
$show_promo = ($current_time < $promo_end_time);

$promo_prices = [
    'basic' => ['name' => 'WROSYS Basic', 'normal_price' => 3500, 'promo_price' => 2500, 'saving' => 1000],
    'plus' => ['name' => 'WROSYS Plus', 'normal_price' => 5500, 'promo_price' => 4000, 'saving' => 1500],
    'extra' => ['name' => 'WROSYS Extra', 'normal_price' => 7500, 'promo_price' => 5500, 'saving' => 2000],
    'special' => ['name' => 'WROSYS Special', 'normal_price' => 15000, 'promo_price' => 10000, 'saving' => 5000]
];

$current_plan = $product_category ?? 'plus';

// =============================================
// ONBOARDING WIZARD - PLUS PLAN (4 STEPS)
// =============================================

// Get onboarding videos from database
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$onboarding_videos = getOnboardingVideos($conn, $current_page);

// Plus plan step configuration (4 steps: Products, Customers, Settings, Sales)
$step_config = [
    1 => [
        'icon' => 'fa-boxes',
        'icon_color' => '#10b981',
        'action_text' => 'Go to Product Management',
        'action_link' => 'warehouse_transfer.php'
    ],
    2 => [
        'icon' => 'fa-users',
        'icon_color' => '#f59e0b',
        'action_text' => 'Go to Customer Management',
        'action_link' => 'customer_management.php'
    ],
    3 => [
        'icon' => 'fa-user-cog',
        'icon_color' => '#8b5cf6',
        'action_text' => 'Go to Settings',
        'action_link' => 'settings.php'
    ],
    4 => [
        'icon' => 'fa-shopping-cart',
        'icon_color' => '#ec4899',
        'action_text' => 'Go to New Sales',
        'action_link' => 'new_order.php'
    ]
];

// Onboarding flags
$show_onboarding = isset($_GET['onboarding']) && $_GET['onboarding'] == '1';
$onboarding_step = isset($_GET['step']) ? (int)$_GET['step'] : 1;

// If user dismisses onboarding
if (isset($_GET['dismiss_onboarding'])) {
    $_SESSION['onboarding_dismissed'] = true;
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit();
}

// If user completes onboarding
if (isset($_GET['onboarding_complete'])) {
    $_SESSION['onboarding_dismissed'] = true;
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit();
}

// Check if user has already dismissed onboarding
if (isset($_SESSION['onboarding_dismissed']) && $_SESSION['onboarding_dismissed'] === true) {
    $show_onboarding = false;
}

// Check if onboarding is complete (Plus: products AND customers needed)
if ($show_onboarding && $tenant_id > 0) {
    $has_products = hasProducts($conn, $tenant_id);
    $has_customers = hasCustomers($conn, $tenant_id);
    if ($has_products && $has_customers) {
        $_SESSION['onboarding_dismissed'] = true;
        $show_onboarding = false;
    }
}

$total_steps = count($step_config);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>WROSYS Plus - <?php echo htmlspecialchars($company_name); ?></title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; overflow-x: hidden; }
        body::before { content: ''; position: fixed; width: 300px; height: 300px; background: rgba(255,255,255,0.1); border-radius: 50%; top: -150px; right: -150px; animation: float 20s infinite; z-index: 0; }
        body::after { content: ''; position: fixed; width: 500px; height: 500px; background: rgba(255,255,255,0.05); border-radius: 50%; bottom: -250px; left: -250px; animation: float 15s infinite reverse; z-index: 0; }
        @keyframes float { 0%, 100% { transform: translate(0, 0) rotate(0deg); } 50% { transform: translate(30px, 30px) rotate(180deg); } }
        
        .sidebar { position: fixed; left: 0; top: 0; height: 100vh; width: 280px; background: rgba(26, 26, 46, 0.95); backdrop-filter: blur(10px); color: white; transition: all 0.3s ease; z-index: 1000; overflow-y: auto; box-shadow: 2px 0 20px rgba(0,0,0,0.1); }
        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.1); }
        .sidebar::-webkit-scrollbar-thumb { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 5px; }
        .sidebar-header { padding: 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 20px; }
        .sidebar-header h5 { font-size: 1rem; margin: 0; color: #fff; }
        .nav-menu { list-style: none; padding: 0; margin: 0; }
        .nav-item { margin: 5px 15px; border-radius: 10px; transition: all 0.3s ease; }
        .nav-item:hover { background: rgba(102, 126, 234, 0.3); }
        .nav-item.active { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .nav-link { display: flex; align-items: center; padding: 12px 15px; color: rgba(255,255,255,0.8); text-decoration: none; transition: all 0.3s ease; font-size: 0.9rem; }
        .nav-link:hover { color: white; text-decoration: none; }
        .nav-item.active .nav-link { color: white; }
        .nav-link i { width: 25px; margin-right: 10px; font-size: 1.1rem; }
        
        /* Menu Badge */
        .badge-nav {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            font-size: 9px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 10px;
            margin-left: auto;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            animation: pulse-badge 2s ease-in-out infinite;
        }
        
        @keyframes pulse-badge {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
        
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; position: relative; z-index: 1; }
        .top-navbar { background: rgba(255,255,255,0.98); border-radius: 15px; padding: 12px 25px; margin-bottom: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .toggle-sidebar { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; width: 40px; height: 40px; border-radius: 10px; color: white; cursor: pointer; transition: all 0.3s; display: none; }
        .toggle-sidebar:hover { transform: scale(1.05); }
        .user-info { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }
        .user-name { font-weight: 600; color: #1a202c; }
        .logout-btn { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 8px 20px; border-radius: 10px; cursor: pointer; transition: all 0.3s; text-decoration: none; display: inline-block; }
        .logout-btn:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(102,126,234,0.4); color: white; text-decoration: none; }
        
        .content-wrapper { padding: 20px; }
        .card { border-radius: 1.2rem; box-shadow: 0 4px 24px rgba(0,0,0,0.1); border: none; transition: transform 0.3s ease, box-shadow 0.3s ease; margin-bottom: 1.5rem; overflow: hidden; background: rgba(255,255,255,0.98); }
        .card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,0.15); }
        .card-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 1.2rem 1.2rem 0 0 !important; padding: 0.75rem 1.25rem; border: none; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; border-radius: 10px; padding: 10px 25px; transition: all 0.3s; color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(102,126,234,0.4); color: white; }
        .btn-danger { background: #ef4444; border: none; border-radius: 8px; padding: 5px 12px; transition: all 0.3s; color: white; }
        .btn-danger:hover { background: #dc2626; color: white; }
        .btn-success { background: #22c55e; border: none; border-radius: 8px; padding: 5px 12px; transition: all 0.3s; color: white; }
        .btn-success:hover { background: #16a34a; color: white; }
        .btn-info { background: #06b6d4; border: none; border-radius: 8px; padding: 5px 12px; transition: all 0.3s; color: white; }
        .btn-info:hover { background: #0891b2; color: white; }
        .btn-light { background: rgba(255,255,255,0.9); color: #333; border: none; }
        .btn-light:hover { background: white; color: #333; }
        
        .table-card { background: white; border-radius: 20px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .table-card .card-header { background: white; color: #667eea; border-bottom: 1px solid #eef2f6; padding: 15px 20px; font-weight: 600; }
        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status-transfer { background: #d4edda; color: #155724; }
        .status-adjusted { background: #fff3cd; color: #856404; }
        
        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .metric-card { background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 15px rgba(0,0,0,0.1); text-align: center; border-top: 4px solid #667eea; transition: all 0.3s ease; height: 100%; display: flex; flex-direction: column; }
        .metric-card:hover { transform: translateY(-3px); box-shadow: 0 6px 15px rgba(0,0,0,0.15); }
        .metric-value { font-size: 1.8rem; font-weight: 800; color: #667eea; margin: 0.5rem 0; }
        .metric-title { font-size: 0.9rem; color: #4a5568; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.5rem; font-weight: 600; }
        .metric-icon { font-size: 2rem; color: #667eea; margin-bottom: 0.5rem; }
        .metric-small { font-size: 0.7rem; color: #718096; margin-top: 8px; }
        .profit-positive { color: #10b981 !important; font-weight: 700; }
        .profit-negative { color: #ef4444 !important; font-weight: 700; }
        
        .user-limit-bar { background: #f0f0f0; border-radius: 10px; height: 8px; overflow: hidden; margin: 8px 0; }
        .user-limit-bar .fill { height: 100%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); transition: width 0.5s ease; border-radius: 10px; }
        .user-limit-bar .fill.warning { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .user-limit-bar .fill.danger { background: #ef4444; }
        
        .chart-period-tabs { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem; border-bottom: 2px solid #dee2e6; padding-bottom: 0.5rem; }
        .chart-period-btn { background: none; border: none; padding: 0.5rem 1.2rem; border-radius: 0.5rem; cursor: pointer; transition: all 0.3s ease; color: #4a5568; font-weight: 500; font-size: 0.85rem; }
        .chart-period-btn:hover { background: rgba(102,126,234,0.1); color: #667eea; }
        .chart-period-btn.active { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        
        .chart-container { position: relative; height: 300px; margin-top: 0.5rem; }
        .chart-summary { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-top: 1rem; padding: 1rem; background: #f8f9fa; border-radius: 0.75rem; }
        .chart-summary-item { text-align: center; }
        .chart-summary-item .label { font-size: 0.75rem; color: #6b7280; text-transform: uppercase; }
        .chart-summary-item .value { font-size: 1.25rem; font-weight: 700; color: #1a202c; }
        .chart-summary-item .value.positive { color: #10b981; }
        .chart-summary-item .value.negative { color: #ef4444; }
        
        .period-tabs { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem; border-bottom: 2px solid #dee2e6; padding-bottom: 0.5rem; }
        .period-btn { background: none; border: none; padding: 0.5rem 1rem; border-radius: 0.5rem; cursor: pointer; transition: all 0.3s ease; color: #4a5568; font-weight: 500; }
        .period-btn:hover { background: rgba(102,126,234,0.1); color: #667eea; }
        .period-btn.active { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .profit-card { background: white; border-radius: 1rem; padding: 1rem; text-align: center; border-left: 4px solid #667eea; margin-bottom: 0.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .profit-card .amount { font-size: 1.5rem; font-weight: bold; color: #2d3748; }
        .profit-card .label { font-size: 0.8rem; color: #718096; font-weight: 500; }
        
        .top-product-item { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; border-bottom: 1px solid #f0f0f0; }
        .top-product-item:last-child { border-bottom: none; }
        .top-product-item .product-info { flex: 1; }
        .top-product-item .product-info .name { font-weight: 600; font-size: 0.9rem; color: #2d3748; }
        .top-product-item .product-info .code { font-size: 0.75rem; color: #718096; }
        .top-product-item .profit-amount { font-weight: 700; color: #10b981; font-size: 0.95rem; }
        .top-product-item .rank-badge { width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.75rem; margin-right: 10px; }
        .top-product-item .rank-1 { background: #f59e0b; color: white; }
        .top-product-item .rank-2 { background: #94a3b8; color: white; }
        .top-product-item .rank-3 { background: #d97706; color: white; }
        .top-product-item .rank-other { background: #e2e8f0; color: #64748b; }
        .top-product-item .qty-badge { font-size: 0.7rem; background: #f1f5f9; padding: 2px 8px; border-radius: 12px; color: #64748b; }
        
        .footer { background: rgba(255,255,255,0.95); border-radius: 15px; padding: 1rem; text-align: center; color: #4a5568; font-size: 0.875rem; margin-top: 2rem; }
        .debug-info { background: rgba(255,255,255,0.95); border-radius: 15px; padding: 15px; margin-bottom: 20px; font-size: 13px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .debug-info h4 { color: #667eea; margin-bottom: 10px; font-size: 16px; font-weight: 700; }
        .debug-stats { display: flex; gap: 15px; flex-wrap: wrap; }
        .debug-stat { background: white; padding: 8px 15px; border-radius: 8px; border-left: 3px solid #667eea; color: #2d3748; }
        .text-white-50 { color: rgba(255, 255, 255, 0.8); }
        .alert-warning { background: #fef3c7; border-left: 4px solid #f59e0b; color: #92400e; }
        .modal-content { border-radius: 20px; border: none; }
        .modal-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 20px 20px 0 0; }
        .modal-header .close { color: white; opacity: 1; }
        
        .promo-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.75); z-index: 99999; align-items: center; justify-content: center; animation: fadeIn 0.5s ease; }
        .promo-overlay.active { display: flex; }
        .promo-popup { background: white; border-radius: 24px; max-width: 600px; width: 95%; padding: 35px 30px 30px; position: relative; box-shadow: 0 25px 60px rgba(0,0,0,0.3); animation: slideUp 0.5s ease; max-height: 95vh; overflow-y: auto; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideUp { from { transform: translateY(50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .promo-close { position: absolute; top: 12px; right: 18px; font-size: 28px; background: none; border: none; color: #999; cursor: pointer; transition: all 0.3s; z-index: 10; }
        .promo-close:hover { color: #333; transform: rotate(90deg); }
        .promo-badge { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; padding: 4px 16px; border-radius: 20px; font-size: 12px; font-weight: 700; display: inline-block; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 1px; }
        .promo-title { font-size: 28px; font-weight: 800; color: #2d3748; margin-bottom: 5px; }
        .promo-title span { color: #f5576c; }
        .promo-subtitle { color: #718096; font-size: 14px; margin-bottom: 20px; }
        .promo-timer { display: flex; justify-content: center; gap: 15px; margin: 15px 0 20px; }
        .promo-timer-item { background: linear-gradient(135deg, #1a202c 0%, #2d3748 100%); color: white; border-radius: 12px; padding: 10px 15px; min-width: 65px; text-align: center; }
        .promo-timer-item .number { font-size: 28px; font-weight: 700; display: block; line-height: 1.2; }
        .promo-timer-item .label { font-size: 10px; text-transform: uppercase; opacity: 0.7; letter-spacing: 1px; }
        .promo-plans { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 15px 0 20px; }
        .promo-plan { background: #f7fafc; border-radius: 12px; padding: 12px 15px; border: 2px solid #e2e8f0; transition: all 0.3s; }
        .promo-plan:hover { border-color: #667eea; background: #f0f4ff; }
        .promo-plan .plan-name { font-weight: 700; font-size: 13px; color: #2d3748; }
        .promo-plan .plan-price { display: flex; align-items: center; gap: 10px; margin-top: 4px; flex-wrap: wrap; }
        .promo-plan .old-price { font-size: 13px; color: #a0aec0; text-decoration: line-through; }
        .promo-plan .new-price { font-size: 18px; font-weight: 800; color: #f5576c; }
        .promo-plan .saving { font-size: 11px; color: #28a745; font-weight: 600; }
        .promo-btn { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); border: none; border-radius: 12px; padding: 14px; font-weight: 700; color: white; width: 100%; font-size: 16px; transition: all 0.3s; cursor: pointer; display: inline-block; text-align: center; text-decoration: none; }
        .promo-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(245, 87, 108, 0.4); color: white; text-decoration: none; }
        .promo-footer { text-align: center; font-size: 12px; color: #a0aec0; margin-top: 12px; }
        .promo-footer a { color: #667eea; font-weight: 600; text-decoration: none; }
        .promo-footer a:hover { text-decoration: underline; }
        .promo-guarantee { text-align: center; font-size: 12px; color: #28a745; margin-top: 8px; }
        .promo-guarantee i { margin-right: 4px; }
        
        .expiry-warning { background: #fff3cd; color: #856404; border-left: 4px solid #ffc107; padding: 8px 12px; border-radius: 4px; }
        .expiry-expired { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; padding: 8px 12px; border-radius: 4px; }
        .expiry-urgent { background: #fde8e8; color: #991b1b; border-left: 4px solid #ef4444; padding: 8px 12px; border-radius: 4px; }
        .expiry-alert-card { border-left: 4px solid #ef4444; }
        
        .badge { font-weight: 600; }
        .table-responsive { overflow-x: auto; }
        
        .threshold-badge { font-size: 10px; padding: 2px 8px; border-radius: 10px; }
        .threshold-badge.low { background: #dc3545; color: white; }
        .threshold-badge.medium { background: #ffc107; color: #333; }
        .threshold-badge.high { background: #28a745; color: white; }
        
        /* ============================================= */
        /* ONBOARDING WIZARD */
        /* ============================================= */
        .onboarding-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.88);
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            backdrop-filter: blur(10px);
            animation: onboardingFadeIn 0.3s ease;
            overflow-y: auto;
        }

        @keyframes onboardingFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes onboardingSlideUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .onboarding-modal {
            background: white;
            border-radius: 24px;
            max-width: 560px;
            width: 100%;
            padding: 35px 30px 30px;
            box-shadow: 0 30px 80px rgba(0,0,0,0.4);
            animation: onboardingSlideUp 0.4s ease;
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
            margin: auto;
        }

        .onboarding-modal .close-btn {
            position: absolute;
            top: 12px;
            right: 18px;
            background: none;
            border: none;
            font-size: 24px;
            color: #94a3b8;
            cursor: pointer;
            transition: color 0.3s;
        }
        .onboarding-modal .close-btn:hover { color: #0f172a; }

        .onboarding-progress {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            justify-content: center;
        }
        .onboarding-progress .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #e2e8f0;
            transition: background 0.3s;
        }
        .onboarding-progress .dot.active { background: #8b5cf6; }
        .onboarding-progress .dot.completed { background: #10b981; }

        .onboarding-icon {
            text-align: center;
            font-size: 48px;
            margin-bottom: 12px;
        }
        .onboarding-icon .icon-circle {
            background: #f5f3ff;
            width: 72px;
            height: 72px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #8b5cf6;
        }

        .onboarding-modal h2 {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            text-align: center;
            margin-bottom: 6px;
        }

        .onboarding-modal .subtitle {
            text-align: center;
            color: #64748b;
            font-size: 14px;
            margin-bottom: 18px;
            line-height: 1.6;
        }

        .onboarding-modal .step-content {
            background: #f8fafc;
            border-radius: 14px;
            padding: 18px 20px;
            margin: 14px 0;
        }

        .onboarding-modal .step-content .highlight {
            background: #fef3c7;
            padding: 2px 8px;
            border-radius: 4px;
            font-weight: 600;
            color: #92400e;
        }

        .onboarding-modal .step-actions {
            display: flex;
            gap: 10px;
            margin-top: 18px;
        }

        .onboarding-modal .step-actions .btn-primary {
            flex: 1;
            background: linear-gradient(135deg, #8b5cf6, #ec4899);
            border: none;
            padding: 12px 20px;
            border-radius: 12px;
            font-weight: 700;
            color: white;
            transition: all 0.3s;
            cursor: pointer;
        }
        .onboarding-modal .step-actions .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(139,92,246,0.35);
        }

        .onboarding-modal .step-actions .btn-secondary {
            background: #e2e8f0;
            border: none;
            padding: 12px 20px;
            border-radius: 12px;
            font-weight: 600;
            color: #334155;
            transition: all 0.3s;
            cursor: pointer;
        }
        .onboarding-modal .step-actions .btn-secondary:hover {
            background: #cbd5e1;
        }

        .onboarding-modal .step-actions .btn-skip {
            background: none;
            border: none;
            color: #94a3b8;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            padding: 12px 16px;
        }
        .onboarding-modal .step-actions .btn-skip:hover {
            color: #0f172a;
        }

        .onboarding-modal .step-actions .btn-success {
            flex: 1;
            background: linear-gradient(135deg, #10b981, #34d399);
            border: none;
            padding: 12px 20px;
            border-radius: 12px;
            font-weight: 700;
            color: white;
            transition: all 0.3s;
            cursor: pointer;
        }
        .onboarding-modal .step-actions .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(16,185,129,0.35);
        }

        .onboarding-modal .step-indicator {
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            margin-top: 14px;
        }

        .onboarding-modal .voice-demo-box {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 13px;
            color: #065f46;
            margin: 12px 0;
        }
        .onboarding-modal .voice-demo-box strong {
            color: #8b5cf6;
        }

        .onboarding-highlight {
            animation: pulse-glow 2s infinite;
            border-radius: 8px;
            padding: 4px;
            background: rgba(139, 92, 246, 0.15);
            border: 2px solid #8b5cf6;
        }

        @keyframes pulse-glow {
            0% { box-shadow: 0 0 0 0 rgba(139, 92, 246, 0.4); }
            70% { box-shadow: 0 0 0 15px rgba(139, 92, 246, 0); }
            100% { box-shadow: 0 0 0 0 rgba(139, 92, 246, 0); }
        }

        /* Responsive */
        @media (max-width: 576px) {
            .onboarding-modal { padding: 25px 20px; }
            .onboarding-modal h2 { font-size: 18px; }
            .onboarding-modal .step-actions { flex-wrap: wrap; }
            .onboarding-modal .step-actions .btn-primary { flex: 1 1 100%; }
            .onboarding-modal .step-actions .btn-success { flex: 1 1 100%; }
            .onboarding-modal .step-actions .btn-secondary { flex: 1 1 48%; }
            .onboarding-icon .icon-circle { width: 60px; height: 60px; font-size: 28px; }
        }

        @media (max-width: 992px) { .sidebar { left: -280px; } .sidebar.active { left: 0; } .main-content { margin-left: 0; } .toggle-sidebar { display: block; } }
        @media (max-width: 768px) { .content-wrapper { padding: 10px; } .metric-value { font-size: 1.5rem; } .promo-plans { grid-template-columns: 1fr; } .promo-popup { padding: 25px 20px; } .promo-title { font-size: 22px; } .promo-timer-item { min-width: 55px; padding: 8px 12px; } .promo-timer-item .number { font-size: 22px; } .chart-container { height: 200px; } .debug-stats { flex-direction: column; } }
        @media (max-width: 480px) { .promo-timer { gap: 8px; } .promo-timer-item { min-width: 45px; padding: 6px 10px; } .promo-timer-item .number { font-size: 18px; } .promo-popup { padding: 20px 15px; } .chart-period-tabs { gap: 0.25rem; } .chart-period-btn { font-size: 0.7rem; padding: 0.3rem 0.6rem; } .top-navbar { padding: 10px 15px; } }
    </style>
</head>
<body>
    <!-- ========== PROMO POPUP ========== -->
    <?php if ($show_promo): ?>
    <div class="promo-overlay active" id="promoOverlay">
        <div class="promo-popup">
            <button class="promo-close" onclick="closePromo()">&times;</button>
            <div style="text-align: center;">
                <span class="promo-badge"><i class="fas fa-fire"></i> LIMITED TIME OFFER</span>
                <h2 class="promo-title">🚀 <span>Expansion</span> Promo!</h2>
                <p class="promo-subtitle">Upgrade your plan and save big on your subscription</p>
            </div>
            <div class="promo-timer" id="promoTimer">
                <div class="promo-timer-item"><span class="number" id="promoDays">00</span><span class="label">Days</span></div>
                <div class="promo-timer-item"><span class="number" id="promoHours">00</span><span class="label">Hours</span></div>
                <div class="promo-timer-item"><span class="number" id="promoMinutes">00</span><span class="label">Mins</span></div>
                <div class="promo-timer-item"><span class="number" id="promoSeconds">00</span><span class="label">Secs</span></div>
            </div>
            <div class="promo-plans">
                <?php foreach ($promo_prices as $key => $plan): ?>
                <div class="promo-plan">
                    <div class="plan-name"><?php echo $plan['name']; ?></div>
                    <div class="plan-price">
                        <span class="old-price">₦<?php echo number_format($plan['normal_price'], 0); ?></span>
                        <span class="new-price">₦<?php echo number_format($plan['promo_price'], 0); ?></span>
                        <span class="saving">Save ₦<?php echo number_format($plan['saving'], 0); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <a href="subscription.php?promo=1" class="promo-btn"><i class="fas fa-gift mr-2"></i> Claim Your Discount Now!</a>
            <div class="promo-guarantee"><i class="fas fa-shield-alt"></i> 100% Satisfaction Guarantee</div>
            <div class="promo-footer">Offer ends <strong>Wednesday, 23rd September 2026 at 11:59 PM</strong> &bull; <a href="#" onclick="closePromo(); return false;">No thanks, I'll pass</a></div>
        </div>
    </div>
    <?php endif; ?>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header"><h5>WROSYS PLUS</h5></div>
        <ul class="nav-menu">
            <?php foreach ($menu_items as $item): ?>
            <li class="nav-item <?php echo isset($item['active']) && $item['active'] ? 'active' : ''; ?>">
                <a class="nav-link" href="<?php echo $item['link']; ?>">
                    <i class="<?php echo $item['icon']; ?>"></i>
                    <span><?php echo $item['label']; ?></span>
                    <?php if (isset($item['badge'])): ?>
                        <span class="badge-nav"><?php echo $item['badge']; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-navbar">
            <button class="toggle-sidebar" id="toggleSidebarBtn"><i class="fas fa-bars"></i></button>
            <div class="user-info">
                <span class="user-name"><i class="fas fa-user-circle mr-1"></i> <?php echo htmlspecialchars($company_name); ?></span>
                <span class="text-muted small mr-2"><?php echo $currency; ?> | <?php echo $country; ?></span>
                <?php if ($subscription_status == 'trial'): ?>
                    <span class="badge badge-warning mr-2">Trial: <?php echo $days_left_trial; ?> days left</span>
                <?php elseif ($subscription_status == 'active'): ?>
                    <span class="badge badge-success mr-2">Active</span>
                <?php endif; ?>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
        </div>

        <div class="content-wrapper">
            <!-- Promo Banner -->
            <?php if ($show_promo): ?>
            <div class="alert alert-warning mb-4" style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border-left: 4px solid #f59e0b; border-radius: 12px; padding: 15px 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                <div>
                    <i class="fas fa-fire mr-2" style="color: #f5576c;"></i>
                    <strong>🚀 Expansion Promo!</strong>
                    <span class="ml-2">Save up to ₦5,000 on your subscription plan</span>
                    <span class="ml-2 text-muted small">(Ends Wednesday, 23rd September 2026 at 11:59 PM)</span>
                </div>
                <a href="subscription.php?promo=1" class="btn btn-sm btn-primary" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); border: none; padding: 8px 20px; border-radius: 8px; color: white;">
                    <i class="fas fa-gift mr-1"></i> Claim Offer
                </a>
            </div>
            <?php endif; ?>

            <div class="text-center mb-4">
                <div style="font-size: 2rem; font-weight: 700; background: linear-gradient(135deg, #fff 0%, #e0d4ff 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
                    WROSYS PLUS
                </div>
                <p class="text-white-50">Inventory Management System - Customer Accounts & Credit</p>
                <p class="text-white-50 small">Today: <?php echo $today_date; ?></p>
            </div>

            <!-- Trial Banner -->
            <?php if ($show_trial_banner && !$subscription_active): ?>
            <div class="alert alert-warning mb-4">
                <i class="fas fa-hourglass-half mr-2"></i> 
                Your 7-day free trial ends on <?php echo date($date_format, strtotime($trial_end_date_display)); ?> 
                (<?php echo $days_left_trial; ?> day<?php echo $days_left_trial > 1 ? 's' : ''; ?> left). 
                <a href="subscription.php" class="alert-link">Subscribe now</a> to continue.
            </div>
            <?php endif; ?>

            <!-- Subscription Banner -->
            <?php if ($show_subscription_banner && $subscription_active): ?>
            <div class="alert alert-warning mb-4">
                <i class="fas fa-clock mr-2"></i> 
                Your subscription expires on <?php echo date($date_format, strtotime($subscription_end_date)); ?> 
                (<?php echo $days_left_subscription; ?> day<?php echo $days_left_subscription > 1 ? 's' : ''; ?> left). 
                <a href="subscription.php" class="alert-link">Renew now</a> to avoid interruption.
            </div>
            <?php endif; ?>

            <!-- Debug Info -->
            <div class="debug-info">
                <h4><i class="fas fa-info-circle"></i> System Status</h4>
                <div class="debug-stats">
                    <div class="debug-stat">
                        <strong>Account Status:</strong><br>
                        <?php if ($subscription_active): ?>
                        ✓ Subscription Active<br>Ends: <?php echo date($date_format, strtotime($subscription_end_date)); ?>
                        <?php elseif ($show_trial_banner): ?>
                        ⚠ Trial Active<br>Ends: <?php echo date($date_format, strtotime($trial_end_date_display)); ?> (<?php echo $days_left_trial; ?> days)
                        <?php else: ?>
                        ✗ Account Expired
                        <?php endif; ?>
                    </div>
                    <div class="debug-stat">
                        <strong>Projected Profit (Stock):</strong><br>
                        <?php echo $currency_symbol; ?><?php echo safe_format($total_projected_profit); ?>
                    </div>
                    <div class="debug-stat">
                        <strong>Profit from Sales:</strong><br>
                        <?php echo $currency_symbol; ?><?php echo safe_format($total_sales_profit); ?>
                    </div>
                    <div class="debug-stat">
                        <strong>Staff Status:</strong><br>
                        <?php echo $staff_counts['active']; ?> active / <?php echo $user_limit_info['max_users']; ?> max
                        <?php if ($user_limit_info['limit_reached']): ?>
                            <span class="badge badge-danger">Limit Reached</span>
                        <?php else: ?>
                            <span class="badge badge-success"><?php echo $user_limit_info['slots_available']; ?> slots</span>
                        <?php endif; ?>
                        <?php if ($staff_counts['pending'] > 0): ?>
                            <span class="badge badge-warning"><?php echo $staff_counts['pending']; ?> pending</span>
                        <?php endif; ?>
                    </div>
                    <div class="debug-stat">
                        <strong>Expiring Items:</strong><br>
                        <?php echo count($expiring_items); ?> product<?php echo count($expiring_items) != 1 ? 's' : ''; ?> expiring soon
                    </div>
                    <div class="debug-stat">
                        <strong>Low Stock Items:</strong><br>
                        <?php echo count($low_stock_items); ?> product<?php echo count($low_stock_items) != 1 ? 's' : ''; ?> below threshold
                    </div>
                </div>
            </div>

            <!-- Metrics Grid -->
            <div class="metrics-grid">
                <div class="metric-card">
                    <div class="metric-icon"><i class="fas fa-store"></i></div>
                    <div class="metric-title">Stock Value (Cost Price)</div>
                    <div class="metric-value"><?php echo $currency_symbol; ?><?php echo safe_format($store_value_cost); ?></div>
                    <div class="metric-small"><?php echo number_format($store_items); ?> items</div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon"><i class="fas fa-tags"></i></div>
                    <div class="metric-title">Stock Value (Selling)</div>
                    <div class="metric-value"><?php echo $currency_symbol; ?><?php echo safe_format($store_value_selling); ?></div>
                    <div class="metric-small">Potential revenue</div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="metric-title">Projected Profit</div>
                    <div class="metric-value <?php echo $total_projected_profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                        <?php echo $currency_symbol; ?><?php echo safe_format($total_projected_profit); ?>
                    </div>
                    <div class="metric-small">If all stock sold</div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon"><i class="fas fa-money-bill-wave"></i></div>
                    <div class="metric-title">Today's Sales</div>
                    <div class="metric-value" style="color: #10b981;"><?php echo $currency_symbol; ?><?php echo safe_format($today_sales); ?></div>
                    <div class="metric-small">Today's revenue</div>
                </div>
                <!-- Staff Slots Metric -->
                <div class="metric-card" style="border-top-color: <?php echo $user_limit_info['limit_reached'] ? '#ef4444' : ($user_limit_info['slots_available'] <= 2 ? '#f59e0b' : '#10b981'); ?>;">
                    <div class="metric-icon"><i class="fas fa-user-plus"></i></div>
                    <div class="metric-title">Staff Slots</div>
                    <div class="metric-value" style="font-size:1.5rem; <?php echo $user_limit_info['limit_reached'] ? 'color:#ef4444;' : ($user_limit_info['slots_available'] <= 2 ? 'color:#f59e0b;' : 'color:#10b981;'); ?>">
                        <?php echo $staff_counts['active']; ?> / <?php echo $user_limit_info['max_users']; ?>
                    </div>
                    <div class="metric-small">
                        <?php if ($user_limit_info['limit_reached']): ?>
                            <span class="text-danger">⚠ Limit reached</span>
                        <?php elseif ($user_limit_info['slots_available'] <= 2): ?>
                            <span class="text-warning">⚠ <?php echo $user_limit_info['slots_available']; ?> slot<?php echo $user_limit_info['slots_available'] > 1 ? 's' : ''; ?> left</span>
                        <?php else: ?>
                            <span class="text-success"><?php echo $user_limit_info['slots_available']; ?> slot<?php echo $user_limit_info['slots_available'] > 1 ? 's' : ''; ?> available</span>
                        <?php endif; ?>
                        <?php if ($staff_counts['pending'] > 0): ?>
                            <br><span class="badge badge-warning"><?php echo $staff_counts['pending']; ?> pending approval</span>
                        <?php endif; ?>
                    </div>
                    <div class="mt-2">
                        <a href="staff_management.php" class="btn btn-sm <?php echo $user_limit_info['limit_reached'] ? 'btn-danger' : 'btn-primary'; ?>">
                            <i class="fas fa-users-cog mr-1"></i> Manage Staff
                        </a>
                    </div>
                </div>
                <!-- Expiry Alert Metric -->
                <div class="metric-card" style="border-top-color: <?php echo count($expiring_items) > 0 ? '#ef4444' : '#10b981'; ?>;">
                    <div class="metric-icon"><i class="fas fa-calendar-times"></i></div>
                    <div class="metric-title">Expiring Soon</div>
                    <div class="metric-value" style="font-size:1.5rem; <?php echo count($expiring_items) > 0 ? 'color:#ef4444;' : 'color:#10b981;'; ?>">
                        <?php echo count($expiring_items); ?>
                    </div>
                    <div class="metric-small">
                        <?php if (count($expiring_items) > 0): ?>
                            <span class="text-danger">⚠ <?php echo count($expiring_items); ?> product<?php echo count($expiring_items) != 1 ? 's' : ''; ?> expiring soon</span>
                        <?php else: ?>
                            <span class="text-success">✓ No expiring items</span>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Low Stock Metric -->
                <div class="metric-card" style="border-top-color: <?php echo count($low_stock_items) > 0 ? '#ef4444' : '#10b981'; ?>;">
                    <div class="metric-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="metric-title">Low Stock Items</div>
                    <div class="metric-value" style="font-size:1.5rem; <?php echo count($low_stock_items) > 0 ? 'color:#ef4444;' : 'color:#10b981;'; ?>">
                        <?php echo count($low_stock_items); ?>
                    </div>
                    <div class="metric-small">
                        <?php if (count($low_stock_items) > 0): ?>
                            <span class="text-danger">⚠ <?php echo count($low_stock_items); ?> product<?php echo count($low_stock_items) != 1 ? 's' : ''; ?> below threshold</span>
                        <?php else: ?>
                            <span class="text-success">✓ All products well stocked</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ============ LOW STOCK ALERT SECTION ============ -->
            <?php if (count($low_stock_items) > 0): ?>
            <div class="card border-danger">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="fas fa-exclamation-triangle mr-2"></i> ⚠️ Low Stock Alerts</h5>
                    <span class="badge badge-light ml-2"><?php echo count($low_stock_items); ?> items</span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Current Stock</th>
                                    <th>Threshold</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($low_stock_items as $item): ?>
                                <tr class="table-danger">
                                    <td><strong><?php echo htmlspecialchars($item['product_name']); ?></strong></td>
                                    <td>
                                        <span class="badge badge-danger">
                                            <?php echo $item['display_quantity']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-warning">
                                            <?php echo $item['low_stock_threshold']; ?> 
                                            <?php echo ($item['selling_unit'] == 'unit' && $item['pack_quantity'] > 1) ? 'units' : 'packs'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-danger">⚠ Reorder Needed</span>
                                    </td>
                                    <td>
                                        <a href="warehouse_transfer.php" class="btn btn-sm btn-primary">
                                            <i class="fas fa-plus"></i> Add Stock
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="alert alert-warning mt-2">
                        <i class="fas fa-info-circle mr-2"></i> 
                        <strong>Note:</strong> Low stock thresholds are configured per product in 
                        <a href="warehouse_transfer.php" class="alert-link">Product Management</a>.
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ============ EXPIRY ALERT SECTION ============ -->
            <?php if (count($expiring_items) > 0): ?>
            <div class="card border-danger expiry-alert-card">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="fas fa-calendar-times mr-2"></i> ⚠️ Expiry Alerts</h5>
                    <span class="badge badge-light ml-2"><?php echo count($expiring_items); ?> items</span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Batch</th>
                                    <th>Barcode</th>
                                    <th>Stock</th>
                                    <th>Expiry Date</th>
                                    <th>Days Left</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expiring_items as $item): 
                                    $display_quantity = (int)$item['quantity'];
                                    if ($item['selling_unit'] == 'unit' && $item['pack_quantity'] > 1) {
                                        $total_units = (int)$item['quantity'] * (int)$item['pack_quantity'];
                                        $display_quantity = $total_units . ' units (' . (int)$item['quantity'] . ' packs)';
                                    } else {
                                        $display_quantity .= ' packs';
                                    }
                                ?>
                                <tr class="<?php echo $item['row_class']; ?>">
                                    <td><strong><?php echo htmlspecialchars($item['product_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($item['batch_code'] ?? 'N/A'); ?></td>
                                    <td><?php echo !empty($item['barcode']) ? htmlspecialchars($item['barcode']) : 'N/A'; ?></td>
                                    <td><?php echo $display_quantity; ?></td>
                                    <td><?php echo date('d M Y', strtotime($item['expiry_date'])); ?></td>
                                    <td>
                                        <?php if ($item['days_until_expiry'] <= 0): ?>
                                            <span class="badge badge-dark">EXPIRED</span>
                                        <?php elseif ($item['days_until_expiry'] <= 7): ?>
                                            <span class="badge badge-danger"><?php echo $item['days_until_expiry']; ?> days</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning"><?php echo $item['days_until_expiry']; ?> days</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="<?php echo $item['status_class']; ?>"><?php echo $item['status_label']; ?></span></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-primary" onclick="window.location.href='warehouse_transfer.php'">
                                                <i class="fas fa-edit"></i> Update
                                            </button>
                                            <button class="btn btn-success" onclick="window.location.href='new_order.php'">
                                                <i class="fas fa-shopping-cart"></i> Sell
                                            </button>
                                            <?php if ($item['days_until_expiry'] <= 0): ?>
                                                <button class="btn btn-danger" onclick="if(confirm('Mark this expired item for disposal?')) { window.location.href='warehouse_transfer.php?action=dispose&id=<?php echo $item['id']; ?>'; }">
                                                    <i class="fas fa-trash"></i> Dispose
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="alert alert-warning mt-2">
                        <i class="fas fa-info-circle mr-2"></i> 
                        <strong>Note:</strong> Products are shown based on your configured notification days. 
                        Expired items should be disposed of immediately. You can change notification settings in 
                        <a href="settings.php" class="alert-link">Settings</a> or per product in 
                        <a href="warehouse_transfer.php" class="alert-link">Product Management</a>.
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ============ PROFIT BAR CHART SECTION ============ -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-chart-bar mr-2"></i> Profit Bar Chart</h5>
                </div>
                <div class="card-body">
                    <div class="chart-period-tabs">
                        <?php foreach ($chart_periods as $period => $label): ?>
                        <button class="chart-period-btn <?php echo $period == 'last7days' ? 'active' : ''; ?>" data-period="<?php echo $period; ?>">
                            <?php echo $label; ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="chart-container">
                        <canvas id="profitChart"></canvas>
                    </div>
                    
                    <!-- Chart Summary -->
                    <div class="chart-summary">
                        <div class="chart-summary-item">
                            <div class="label">Period</div>
                            <div class="value" id="chartPeriodLabel">Last 7 Days</div>
                        </div>
                        <div class="chart-summary-item">
                            <div class="label">Total Profit</div>
                            <div class="value" id="chartTotalProfit"><?php echo $currency_symbol; ?>0.00</div>
                        </div>
                        <div class="chart-summary-item">
                            <div class="label">Average Daily</div>
                            <div class="value" id="chartAvgProfit"><?php echo $currency_symbol; ?>0.00</div>
                        </div>
                        <div class="chart-summary-item">
                            <div class="label">Best Day</div>
                            <div class="value" id="chartBestDay">-</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============ PROFIT FROM SALES SECTION ============ -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-chart-line mr-2"></i> Profit from Sales</h5>
                </div>
                <div class="card-body">
                    <div class="period-tabs">
                        <button class="period-btn active" data-period="today">Today</button>
                        <button class="period-btn" data-period="yesterday">Yesterday</button>
                        <button class="period-btn" data-period="last7days">Last 7 Days</button>
                        <button class="period-btn" data-period="last30days">Last 30 Days</button>
                        <button class="period-btn" data-period="this_month">This Month</button>
                        <button class="period-btn" data-period="last_month">Last Month</button>
                    </div>
                    
                    <div id="profitDisplay">
                        <?php foreach ($profit_periods as $period): ?>
                        <div class="profit-period-data" id="profit-<?php echo $period; ?>" style="display: <?php echo $period == 'today' ? 'block' : 'none'; ?>">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="profit-card">
                                        <div class="label">Total Profit</div>
                                        <div class="amount <?php echo $profit_data[$period]['total_profit'] >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                            <?php echo $currency_symbol; ?><?php echo safe_format($profit_data[$period]['total_profit']); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="profit-card">
                                        <div class="label">Transactions</div>
                                        <div class="amount" style="color: #3b82f6;"><?php echo number_format($profit_data[$period]['transaction_count']); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="profit-card">
                                        <div class="label">Items Sold</div>
                                        <div class="amount" style="color: #f59e0b;"><?php echo number_format($profit_data[$period]['items_sold']); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- ============ TOP PROFIT PRODUCTS SECTION ============ -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-trophy mr-2"></i> Top Profit Products</h5>
                </div>
                <div class="card-body">
                    <?php if (count($top_products) > 0): ?>
                        <?php foreach ($top_products as $index => $product): ?>
                        <div class="top-product-item">
                            <div style="display: flex; align-items: center;">
                                <span class="rank-badge <?php echo $index < 3 ? 'rank-' . ($index + 1) : 'rank-other'; ?>">
                                    <?php echo $index + 1; ?>
                                </span>
                                <div class="product-info">
                                    <div class="name"><?php echo htmlspecialchars($product['product_name'] ?? 'Unknown Product'); ?></div>
                                    <div class="code"><?php echo htmlspecialchars($product['item_code'] ?? 'N/A'); ?></div>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 15px;">
                                <span class="qty-badge"><?php echo number_format($product['quantity_sold']); ?> sold</span>
                                <span class="profit-amount"><?php echo $currency_symbol; ?><?php echo safe_format($product['total_profit']); ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-chart-line fa-2x mb-2" style="color: #dee2e6;"></i>
                            <p>No sales data yet. Start selling to see your top products!</p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="mt-3 text-center">
                        <small class="text-muted">Showing top <?php echo count($top_products); ?> products by profit (last 30 days)</small>
                    </div>
                </div>
            </div>

            <!-- ============ ORDER STATUS SECTION ============ -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-clipboard-list mr-2"></i> Order Status Summary</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php 
                        $status_colors = [
                            'pending' => 'warning',
                            'completed' => 'success',
                            'partially_paid' => 'info',
                            'cancelled' => 'danger',
                            'pending_payment' => 'secondary'
                        ];
                        $status_icons = [
                            'pending' => 'fa-clock',
                            'completed' => 'fa-check-circle',
                            'partially_paid' => 'fa-money-bill-wave',
                            'cancelled' => 'fa-times-circle',
                            'pending_payment' => 'fa-hourglass-half'
                        ];
                        $status_labels = [
                            'pending' => 'Pending',
                            'completed' => 'Completed',
                            'partially_paid' => 'Partially Paid',
                            'cancelled' => 'Cancelled',
                            'pending_payment' => 'Awaiting Payment'
                        ];
                        
                        $has_orders = false;
                        foreach ($order_status_summary as $status => $count):
                            if ($count > 0) $has_orders = true;
                        ?>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="text-center p-3" style="background: <?php echo $status == 'completed' ? '#d4edda' : ($status == 'cancelled' ? '#f8d7da' : '#fff3cd'); ?>; border-radius: 12px; border-left: 4px solid <?php echo $status == 'completed' ? '#28a745' : ($status == 'cancelled' ? '#dc3545' : '#ffc107'); ?>;">
                                <div class="h4 <?php echo $status == 'completed' ? 'text-success' : ($status == 'cancelled' ? 'text-danger' : 'text-warning'); ?>">
                                    <i class="fas <?php echo $status_icons[$status] ?? 'fa-circle'; ?> mr-1"></i>
                                    <?php echo number_format($count); ?>
                                </div>
                                <small><?php echo $status_labels[$status] ?? ucfirst($status); ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if (!$has_orders): ?>
                        <div class="col-12 text-center text-muted py-4">
                            <i class="fas fa-inbox fa-3x mb-2" style="color: #dee2e6;"></i>
                            <p>No orders yet. Start creating orders to see status distribution!</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Staff Management Section -->
            <div id="staff" class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-users mr-2"></i> Staff Overview</h5>
                    <a href="staff_management.php" class="btn btn-sm btn-light">
                        <i class="fas fa-arrow-right mr-1"></i> Manage All Staff
                    </a>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="text-center p-3 bg-light rounded">
                                <div class="display-4 text-primary"><?php echo $staff_counts['active']; ?></div>
                                <div class="text-muted">Active Staff</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-center p-3 bg-light rounded">
                                <div class="display-4 text-warning"><?php echo $staff_counts['pending']; ?></div>
                                <div class="text-muted">Pending Approval</div>
                                <?php if ($staff_counts['pending'] > 0): ?>
                                    <a href="staff_management.php" class="btn btn-sm btn-warning mt-2">
                                        <i class="fas fa-clock mr-1"></i> Review Pending
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <div class="d-flex justify-content-between">
                            <span>Used: <?php echo $staff_counts['active']; ?> / <?php echo $user_limit_info['max_users']; ?></span>
                            <span><?php echo $user_limit_info['max_users'] > 0 ? round(($staff_counts['active'] / $user_limit_info['max_users']) * 100) : 0; ?>%</span>
                        </div>
                        <div class="user-limit-bar">
                            <?php 
                            $percentage = ($user_limit_info['max_users'] > 0) ? ($staff_counts['active'] / $user_limit_info['max_users']) * 100 : 0;
                            $bar_class = ($percentage >= 90) ? 'danger' : (($percentage >= 70) ? 'warning' : '');
                            ?>
                            <div class="fill <?php echo $bar_class; ?>" style="width: <?php echo min($percentage, 100); ?>%;"></div>
                        </div>
                        <?php if ($user_limit_info['limit_reached']): ?>
                            <div class="text-danger mt-1"><i class="fas fa-exclamation-circle mr-1"></i> User limit reached! <a href="settings.php#multi-user" class="alert-link">Upgrade plan</a></div>
                        <?php elseif ($user_limit_info['slots_available'] <= 2): ?>
                            <div class="text-warning mt-1"><i class="fas fa-exclamation-triangle mr-1"></i> Only <?php echo $user_limit_info['slots_available']; ?> slot<?php echo $user_limit_info['slots_available'] > 1 ? 's' : ''; ?> remaining</div>
                        <?php else: ?>
                            <div class="text-success mt-1"><i class="fas fa-check-circle mr-1"></i> <?php echo $user_limit_info['slots_available']; ?> slot<?php echo $user_limit_info['slots_available'] > 1 ? 's' : ''; ?> available</div>
                        <?php endif; ?>
                        
                        <div class="text-center mt-3">
                            <a href="staff_management.php" class="btn btn-primary btn-sm">
                                <i class="fas fa-user-plus mr-1"></i> Manage Staff
                            </a>
                            <a href="settings.php#multi-user" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-cog mr-1"></i> User Settings
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Feedback Section -->
            <div id="feedback" class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-comment-dots mr-2"></i> Feedback &amp; Suggestions</h5>
                    <a href="feedback.php" class="btn btn-sm btn-light">
                        <i class="fas fa-arrow-right mr-1"></i> View All
                    </a>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="text-center p-2 bg-light rounded">
                                <div class="h4 text-primary"><?php echo $feedback_counts['total'] ?? 0; ?></div>
                                <small>Total</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center p-2" style="background: #fff3cd; border-radius: 8px;">
                                <div class="h4 text-warning"><?php echo $feedback_counts['pending'] ?? 0; ?></div>
                                <small>Pending</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center p-2" style="background: #d4edda; border-radius: 8px;">
                                <div class="h4 text-success"><?php echo $feedback_counts['resolved'] ?? 0; ?></div>
                                <small>Resolved</small>
                            </div>
                        </div>
                    </div>
                    
                    <button class="btn btn-primary mb-3" data-toggle="modal" data-target="#feedbackModal">
                        <i class="fas fa-plus mr-1"></i> Share Feedback
                    </button>
                    
                    <?php if (!empty($recent_feedback)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr><th>Date</th><th>Type</th><th>Subject</th><th>Status</th><th>Action</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_feedback as $fb): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($fb['created_at'])); ?></td>
                                    <td>
                                        <span class="badge <?php echo $fb['feedback_type'] == 'bug' ? 'badge-danger' : ($fb['feedback_type'] == 'feature_request' ? 'badge-success' : 'badge-info'); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $fb['feedback_type'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($fb['subject']); ?></td>
                                    <td>
                                        <span class="badge <?php echo $fb['status'] == 'pending' ? 'badge-warning' : ($fb['status'] == 'resolved' ? 'badge-success' : 'badge-secondary'); ?>">
                                            <?php echo ucfirst($fb['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" onclick="viewFeedback('<?php echo htmlspecialchars(addslashes($fb['subject'])); ?>', '<?php echo htmlspecialchars(addslashes($fb['message'])); ?>')">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p class="text-center text-muted py-3">No feedback yet. Share your thoughts!</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Monthly Profit Summary -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-calendar-alt mr-2"></i> Monthly Profit Summary</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead><tr><th>Month</th><th>Profit</th></tr></thead>
                            <tbody>
                                <?php foreach ($monthly_profit_data as $month): ?>
                                <tr>
                                    <td><strong><?php echo $month['month']; ?></strong></td>
                                    <td class="<?php echo $month['profit'] >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                        <?php echo $currency_symbol; ?><?php echo safe_format($month['profit']); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Low Stock Alerts (Legacy) -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-exclamation-triangle mr-2"></i> Low Stock Alerts</h5>
                </div>
                <div class="card-body">
                    <?php if (count($low_stock_items) > 0): ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Quantity</th>
                                    <th>Threshold</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($low_stock_items as $item): 
                                    $selling_unit = $item['selling_unit'] ?? 'pack';
                                    $pack_quantity = (int)($item['pack_quantity'] ?? 1);
                                    $display_qty_label = formatQuantityLabel((int)$item['quantity'], $selling_unit, $pack_quantity);
                                    $display_detail = '';
                                    
                                    if ($selling_unit == 'unit' && $pack_quantity > 1) {
                                        $total_units = (int)$item['quantity'] * $pack_quantity;
                                        $display_detail = ' (' . $total_units . ' units, ' . (int)$item['quantity'] . ' packs)';
                                    }
                                ?>
                                <tr class="table-warning">
                                    <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                    <td>
                                        <span class="badge badge-danger">
                                            <?php echo $display_qty_label . $display_detail; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-secondary">
                                            <?php echo $item['low_stock_threshold']; ?> 
                                            <?php echo ($selling_unit == 'unit' && $pack_quantity > 1) ? 'units' : 'packs'; ?>
                                        </span>
                                    </td>
                                    <td><a href="warehouse_transfer.php" class="btn btn-sm btn-primary">Add Stock</a></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center text-success py-4">
                        <i class="fas fa-check-circle fa-3x mb-2"></i>
                        <p>All products have sufficient stock!</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Financial Summary -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-chart-pie mr-2"></i> Financial Summary</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="text-center p-3 bg-light rounded">
                                <div class="text-danger" style="font-size: 1.5rem; font-weight: bold;"><?php echo $currency_symbol; ?>0.00</div>
                                <div class="text-muted small">Customer Debt (We are owed)</div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="text-center p-3 bg-light rounded">
                                <div class="text-success" style="font-size: 1.5rem; font-weight: bold;"><?php echo $currency_symbol; ?>0.00</div>
                                <div class="text-muted small">Customer Credit (We owe)</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <footer class="footer">
            &copy; <?php echo date('Y'); ?> WROSYS. All rights reserved.<br>
            <small><?php echo $currency; ?> | <?php echo $timezone; ?></small>
        </footer>
    </div>

    <!-- Feedback Modal -->
    <div class="modal fade" id="feedbackModal" tabindex="-1" role="dialog" aria-labelledby="feedbackModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="feedbackModalLabel"><i class="fas fa-comment-dots mr-2"></i> Share Feedback</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="POST" action="submit_feedback.php">
                    <div class="modal-body">
                        <div class="form-group">
                            <label>Feedback Type *</label>
                            <select class="form-control" name="feedback_type" required>
                                <option value="general">General Feedback</option>
                                <option value="suggestion">Suggestion</option>
                                <option value="bug">Bug Report</option>
                                <option value="feature_request">Feature Request</option>
                                <option value="complaint">Complaint</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Subject *</label>
                            <input type="text" class="form-control" name="subject" required>
                        </div>
                        <div class="form-group">
                            <label>Message *</label>
                            <textarea class="form-control" name="message" rows="5" required></textarea>
                        </div>
                        <div class="form-group">
                            <label>Rating (Optional)</label>
                            <select class="form-control" name="rating">
                                <option value="">Select Rating</option>
                                <option value="5">★★★★★ - Excellent</option>
                                <option value="4">★★★★☆ - Good</option>
                                <option value="3">★★★☆☆ - Average</option>
                                <option value="2">★★☆☆☆ - Poor</option>
                                <option value="1">★☆☆☆☆ - Very Poor</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="submit_feedback" class="btn btn-primary">Submit</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Feedback Modal -->
    <div class="modal fade" id="viewFeedbackModal" tabindex="-1" role="dialog" aria-labelledby="viewFeedbackModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewFeedbackModalLabel">Feedback Details</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p><strong>Subject:</strong> <span id="view_subject"></span></p>
                    <hr>
                    <p><strong>Message:</strong></p>
                    <p id="view_message" class="bg-light p-2 rounded"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ============ ONBOARDING WIZARD (4 STEPS) ============ -->
    <?php if ($show_onboarding && !empty($onboarding_videos)): ?>
    <div class="onboarding-overlay" id="onboardingOverlay">
        <div class="onboarding-modal">
            <button class="close-btn" onclick="dismissOnboarding()" aria-label="Close onboarding">
                <i class="fas fa-times"></i>
            </button>

            <!-- Progress Dots -->
            <div class="onboarding-progress">
                <?php for ($i = 1; $i <= $total_steps; $i++): ?>
                <span class="dot <?php echo $i == 1 ? 'active' : ''; ?>" data-step="<?php echo $i; ?>"></span>
                <?php endfor; ?>
            </div>

            <?php 
            $step_num = 1;
            foreach ($onboarding_videos as $video): 
                $config = $step_config[$step_num] ?? $step_config[1];
            ?>
            <!-- Step <?php echo $step_num; ?>: <?php echo htmlspecialchars($video['title']); ?> -->
            <div class="onboarding-step" data-step="<?php echo $step_num; ?>" style="display: <?php echo $step_num == 1 ? 'block' : 'none'; ?>;">
                <div class="onboarding-icon">
                    <div class="icon-circle" style="color: <?php echo $config['icon_color']; ?>;">
                        <i class="fas <?php echo $config['icon']; ?>"></i>
                    </div>
                </div>
                
                <h2>Step <?php echo $step_num; ?>: <?php echo htmlspecialchars($video['title']); ?></h2>
                <p class="subtitle"><?php echo htmlspecialchars($video['description']); ?></p>
                
                <!-- Video Embed -->
                <div style="margin: 15px 0; border-radius: 12px; overflow: hidden; background: #000; position: relative; padding-bottom: 56.25%; height: 0;">
                    <iframe 
                        style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: none;"
                        src="<?php echo $video['embed_url']; ?>" 
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                        allowfullscreen>
                    </iframe>
                </div>
                <div style="text-align:center; font-size:11px; color:#94a3b8; margin-bottom:12px;">
                    <i class="fas fa-play-circle" style="color:#ff0000;"></i> 
                    Watch this tutorial to learn how to <?php echo strtolower($video['title']); ?>
                </div>
                
                <div class="step-content">
                    <p style="margin:0; font-size:14px; color:#475569;">
                        <i class="fas fa-arrow-right" style="color:#8b5cf6;"></i> 
                        <?php echo $config['action_text']; ?> to get started.
                        <br><br>
                        <span class="highlight">💡 Tip:</span> Complete this step before moving to the next one.
                    </p>
                </div>
                
                <div class="step-actions">
                    <?php if ($step_num > 1): ?>
                    <button class="btn-secondary" onclick="prevStep(<?php echo $step_num - 1; ?>)">
                        <i class="fas fa-arrow-left"></i> Back
                    </button>
                    <?php endif; ?>
                    
                    <?php if ($step_num < $total_steps): ?>
                    <button class="btn-primary" onclick="nextStep(<?php echo $step_num + 1; ?>)">
                        Next Step <i class="fas fa-arrow-right"></i>
                    </button>
                    <?php else: ?>
                    <button class="btn-success" onclick="completeOnboarding()">
                        <i class="fas fa-check"></i> Go to Dashboard
                    </button>
                    <?php endif; ?>
                </div>
                
                <!-- Quick action button to go directly to the page -->
                <div style="text-align:center; margin-top:10px;">
                    <a href="<?php echo $config['action_link']; ?>" class="btn btn-sm btn-outline-primary" style="border-radius:8px; font-size:12px; border-color:#8b5cf6; color:#8b5cf6; text-decoration:none; padding:6px 15px; display:inline-block;">
                        <i class="fas fa-external-link-alt"></i> Go to <?php echo $video['title']; ?> Now
                    </a>
                </div>
            </div>
            <?php 
            $step_num++;
            endforeach; 
            ?>

            <div class="step-indicator">
                Step <span id="currentStepDisplay">1</span> of <?php echo $total_steps; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.12.9/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js"></script>

    <script>
    // ============ PROMO COUNTDOWN TIMER ============
    <?php if ($show_promo): ?>
    var countDownDate = new Date('<?php echo date('F j, Y H:i:s', $promo_end_time); ?>').getTime();
    
    var timerInterval = setInterval(function() {
        var now = new Date().getTime();
        var distance = countDownDate - now;
        
        var days = Math.floor(distance / (1000 * 60 * 60 * 24));
        var hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        var minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
        var seconds = Math.floor((distance % (1000 * 60)) / 1000);
        
        document.getElementById("promoDays").innerHTML = String(days).padStart(2, '0');
        document.getElementById("promoHours").innerHTML = String(hours).padStart(2, '0');
        document.getElementById("promoMinutes").innerHTML = String(minutes).padStart(2, '0');
        document.getElementById("promoSeconds").innerHTML = String(seconds).padStart(2, '0');
        
        if (distance < 0) {
            clearInterval(timerInterval);
            document.getElementById("promoOverlay").style.display = "none";
            location.reload();
        }
    }, 1000);
    <?php endif; ?>
    
    function closePromo() {
        document.getElementById("promoOverlay").style.display = "none";
    }
    
    $('#toggleSidebarBtn').click(function() { 
        $('#sidebar').toggleClass('active'); 
    });
    
    $('.period-btn').click(function() {
        $('.period-btn').removeClass('active');
        $(this).addClass('active');
        $('.profit-period-data').hide();
        $('#profit-' + $(this).data('period')).show();
    });
    
    function viewFeedback(subject, message) {
        $('#view_subject').text(subject);
        $('#view_message').text(message);
        $('#viewFeedbackModal').modal('show');
    }
    
    // ============ CHART DATA ============
    var chartData = <?php echo json_encode($chart_data); ?>;
    var currencySymbol = '<?php echo $currency_symbol; ?>';
    var chartInstance = null;
    
    function updateChart(period) {
        var data = chartData[period];
        if (!data) return;
        
        var ctx = document.getElementById('profitChart').getContext('2d');
        
        if (chartInstance) {
            chartInstance.destroy();
        }
        
        var colors = data.values.map(function(v) {
            return v >= 0 ? 'rgba(16, 185, 129, 0.8)' : 'rgba(239, 68, 68, 0.8)';
        });
        
        var borderColors = data.values.map(function(v) {
            return v >= 0 ? 'rgb(16, 185, 129)' : 'rgb(239, 68, 68)';
        });
        
        chartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'Profit (' + currencySymbol + ')',
                    data: data.values,
                    backgroundColor: colors,
                    borderColor: borderColors,
                    borderWidth: 2,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            font: {
                                size: 12,
                                weight: '600'
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                var value = context.raw;
                                return currencySymbol + ' ' + value.toFixed(2);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return currencySymbol + ' ' + value.toFixed(0);
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
        
        var totalProfit = data.total_profit || 0;
        var avgProfit = data.values.length > 0 ? totalProfit / data.values.length : 0;
        var bestDay = Math.max.apply(null, data.values);
        var bestDayIndex = data.values.indexOf(bestDay);
        var bestDayLabel = bestDayIndex >= 0 ? data.labels[bestDayIndex] : '-';
        
        document.getElementById('chartPeriodLabel').textContent = data.label;
        document.getElementById('chartTotalProfit').textContent = currencySymbol + ' ' + totalProfit.toFixed(2);
        document.getElementById('chartTotalProfit').className = 'value ' + (totalProfit >= 0 ? 'positive' : 'negative');
        document.getElementById('chartAvgProfit').textContent = currencySymbol + ' ' + avgProfit.toFixed(2);
        document.getElementById('chartBestDay').textContent = bestDayLabel + ' (' + currencySymbol + ' ' + bestDay.toFixed(2) + ')';
    }
    
    $('.chart-period-btn').click(function() {
        $('.chart-period-btn').removeClass('active');
        $(this).addClass('active');
        var period = $(this).data('period');
        updateChart(period);
    });
    
    document.addEventListener('DOMContentLoaded', function() {
        updateChart('last7days');
    });
    
    $(document).on('click', function(e) {
        if ($(window).width() <= 992) {
            if (!$(e.target).closest('#sidebar').length && !$(e.target).closest('#toggleSidebarBtn').length) {
                $('#sidebar').removeClass('active');
            }
        }
    });

    // ============ ONBOARDING WIZARD - JavaScript ============
    <?php if ($show_onboarding && !empty($onboarding_videos)): ?>
    var currentStep = 1;
    var totalSteps = <?php echo $total_steps; ?>;

    function showStep(step) {
        document.querySelectorAll('.onboarding-step').forEach(function(el) {
            el.style.display = 'none';
        });
        
        var target = document.querySelector('.onboarding-step[data-step="' + step + '"]');
        if (target) {
            target.style.display = 'block';
        }
        
        document.querySelectorAll('.onboarding-progress .dot').forEach(function(dot, index) {
            var dotStep = index + 1;
            dot.className = 'dot';
            if (dotStep === step) {
                dot.classList.add('active');
            } else if (dotStep < step) {
                dot.classList.add('completed');
            }
        });
        
        document.getElementById('currentStepDisplay').textContent = step;
        currentStep = step;
        
        var videoIframe = target ? target.querySelector('iframe') : null;
        if (videoIframe) {
            var src = videoIframe.src;
            if (src.indexOf('autoplay=0') !== -1) {
                src = src.replace('autoplay=0', 'autoplay=1');
            } else if (src.indexOf('autoplay=1') === -1) {
                if (src.indexOf('?') === -1) {
                    src += '?autoplay=1';
                } else {
                    src += '&autoplay=1';
                }
            }
            videoIframe.src = src;
        }
    }

    function nextStep(step) {
        if (step > totalSteps) {
            completeOnboarding();
            return;
        }
        showStep(step);
    }

    function prevStep(step) {
        if (step < 1) return;
        showStep(step);
    }

    function dismissOnboarding() {
        var overlay = document.getElementById('onboardingOverlay');
        overlay.style.transition = 'opacity 0.3s ease';
        overlay.style.opacity = '0';
        
        setTimeout(function() {
            overlay.style.display = 'none';
            var url = new URL(window.location.href);
            url.searchParams.delete('onboarding');
            url.searchParams.delete('step');
            window.history.replaceState({}, document.title, url.pathname + url.search);
            
            fetch(window.location.pathname + '?dismiss_onboarding=1', {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).catch(function() {});
        }, 300);
    }

    function completeOnboarding() {
        var overlay = document.getElementById('onboardingOverlay');
        overlay.style.transition = 'opacity 0.3s ease';
        overlay.style.opacity = '0';
        
        setTimeout(function() {
            overlay.style.display = 'none';
            var url = new URL(window.location.href);
            url.searchParams.delete('onboarding');
            url.searchParams.delete('step');
            url.searchParams.set('onboarding_complete', '1');
            window.location.href = url.pathname + url.search;
        }, 300);
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            dismissOnboarding();
        }
    });

    document.getElementById('onboardingOverlay').addEventListener('click', function(e) {
        if (e.target === this) {
            dismissOnboarding();
        }
    });

    showStep(1);
    <?php endif; ?>
    </script>
</body>
</html>
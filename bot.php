<?php

define('BOT_TOKEN', '7738809600:AAHKcmFvr2F24lhWJiNgm7sLNiCPLQ8YNC8');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('WC_API_URL', 'https://menupich.ir/cafeuka/wp-json/wc/v3/');
define('WC_CONSUMER_KEY', 'ck_19ec91648997cd153940a05c64ad150d0f23efa8');
define('WC_CONSUMER_SECRET', 'cs_834f98b5eb55de0ed47b8ba22c1aa587b2845878');

// Log incoming data
$data = json_decode(file_get_contents('php://input'), true);
file_put_contents('log.txt', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);

function sendMessage($chat_id, $text, $keyboard = null, $inline = false) {
    $params = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];
    
    if ($keyboard) {
        if ($inline) {
            $params['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
        } else {
            $params['reply_markup'] = json_encode([
                'keyboard' => $keyboard,
                'resize_keyboard' => true,
                'one_time_keyboard' => true
            ]);
        }
    }
    
    return apiRequest('sendMessage', $params);
}

function sendPhoto($chat_id, $photo, $caption = '') {
    $params = [
        'chat_id' => $chat_id,
        'photo' => $photo,
        'caption' => $caption,
        'parse_mode' => 'HTML'
    ];
    return apiRequest('sendPhoto', $params);
}

function apiRequest($method, $params) {
    $ch = curl_init(API_URL . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        file_put_contents('error_log.txt', 'Telegram API error: ' . curl_error($ch) . PHP_EOL, FILE_APPEND);
    }
    curl_close($ch);
    
    return json_decode($response, true);
}

function wp_api_request($endpoint, $method = 'GET', $data = []) {
    $url = WC_API_URL . $endpoint . "?consumer_key=" . WC_CONSUMER_KEY . "&consumer_secret=" . WC_CONSUMER_SECRET;
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        file_put_contents('error_log.txt', 'WooCommerce API error: ' . curl_error($ch) . PHP_EOL, FILE_APPEND);
        curl_close($ch);
        return false;
    }
    
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code >= 400) {
        file_put_contents('error_log.txt', "WooCommerce API HTTP error: $http_code - $response" . PHP_EOL, FILE_APPEND);
        return false;
    }
    
    return json_decode($response, true);
}

function getCategoriesKeyboard() {
    $cats = wp_api_request('products/categories', 'GET', ['per_page' => 100]);
    $buttons = [];
    
    if (is_array($cats)) {
        foreach ($cats as $cat) {
            if ($cat['name'] !== 'Uncategorized') { // Skip default category
                $buttons[] = [['text' => $cat['name'], 'callback_data' => 'cat_' . $cat['id']]];
            }
        }
    }
    
    $buttons[] = [['text' => '➕ افزودن دسته‌بندی جدید', 'callback_data' => 'add_cat']];
    return $buttons;
}

// Safely get input data
$chat_id = $data['message']['chat']['id'] ?? $data['callback_query']['message']['chat']['id'] ?? null;
$message = $data['message']['text'] ?? null;
$photo = !empty($data['message']['photo']) ? end($data['message']['photo'])['file_id'] : null;
$callback_data = $data['callback_query']['data'] ?? null;
$user_id = $data['message']['from']['id'] ?? $data['callback_query']['from']['id'] ?? null;

if (!$chat_id) {
    exit;
}

// User state management
$state_file = __DIR__ . "/state_$chat_id.json";
$state = file_exists($state_file) ? json_decode(file_get_contents($state_file), true) : [];

// Handle callback queries
if ($callback_data) {
    if ($callback_data === 'add_cat') {
        $state['step'] = 'new_category_name';
        file_put_contents($state_file, json_encode($state));
        sendMessage($chat_id, "📝 لطفاً نام دسته‌بندی جدید را وارد کنید:");
        exit;
    }

    if (strpos($callback_data, 'cat_') === 0) {
        $cat_id = (int) str_replace('cat_', '', $callback_data);
        $state['product']['categories'] = [['id' => $cat_id]];
        $state['step'] = 'complete_product';
        file_put_contents($state_file, json_encode($state));
        
        $text = "📦 لطفاً تایید کنید:\n\nنام: {$state['product']['name']}\nقیمت: {$state['product']['regular_price']} تومان\nتوضیح: {$state['product']['description']}";
        
        sendMessage($chat_id, $text, [
            [['text' => '✅ تایید', 'callback_data' => 'confirm_product']],
            [['text' => '❌ لغو', 'callback_data' => 'cancel_product']]
        ], true);
        exit;
    }

    if ($callback_data === 'confirm_product') {
        if (!empty($state['photo'])) {
            $state['product']['images'] = [['src' => $state['photo']];
        }
        
        $res = wp_api_request('products', 'POST', $state['product']);
        
        if (isset($res['id'])) {
            sendMessage($chat_id, "✅ محصول با موفقیت ساخته شد.");
        } else {
            $err = is_array($res) ? json_encode($res, JSON_UNESCAPED_UNICODE) : 'خطای نامشخص';
            sendMessage($chat_id, "❌ خطا در ساخت محصول:\n$err");
        }
        
        if (file_exists($state_file)) {
            unlink($state_file);
        }
        exit;
    }

    if ($callback_data === 'cancel_product') {
        if (file_exists($state_file)) {
            unlink($state_file);
        }
        sendMessage($chat_id, "❌ عملیات لغو شد.");
        exit;
    }
}

// Handle text messages and steps
if ($message) {
    if ($message === '/start') {
        $state = ['step' => 'name'];
        file_put_contents($state_file, json_encode($state));
        sendMessage($chat_id, "👋 سلام! لطفاً نام محصول را وارد کنید:");
        exit;
    }

    switch ($state['step'] ?? '') {
        case 'name':
            $state['product']['name'] = $message;
            $state['step'] = 'price';
            file_put_contents($state_file, json_encode($state));
            sendMessage($chat_id, "💵 لطفاً قیمت محصول را وارد کنید (فقط عدد):");
            break;

        case 'price':
            if (!is_numeric($message)) {
                sendMessage($chat_id, "❌ لطفاً فقط عدد وارد کنید.");
                exit;
            }
            
            $state['product']['regular_price'] = $message;
            $state['step'] = 'description';
            file_put_contents($state_file, json_encode($state));
            sendMessage($chat_id, "📝 لطفاً توضیح محصول را وارد کنید:");
            break;

        case 'description':
            $state['product']['description'] = $message;
            $state['step'] = 'image';
            file_put_contents($state_file, json_encode($state));
            sendMessage($chat_id, "📸 لطفاً عکس محصول را ارسال کنید:");
            break;

        case 'new_category_name':
            $cat = wp_api_request('products/categories', 'POST', ['name' => $message]);
            
            if (isset($cat['id'])) {
                $state['product']['categories'] = [['id' => $cat['id']]];
                $state['step'] = 'complete_product';
                file_put_contents($state_file, json_encode($state));
                
                $text = "📦 لطفاً تایید کنید:\n\nنام: {$state['product']['name']}\nقیمت: {$state['product']['regular_price']} تومان\nتوضیح: {$state['product']['description']}";
                
                sendMessage($chat_id, $text, [
                    [['text' => '✅ تایید', 'callback_data' => 'confirm_product']],
                    [['text' => '❌ لغو', 'callback_data' => 'cancel_product']]
                ], true);
            } else {
                $err = is_array($cat) ? json_encode($cat, JSON_UNESCAPED_UNICODE) : 'خطای نامشخص';
                sendMessage($chat_id, "❌ خطا در ایجاد دسته‌بندی:\n$err");
            }
            break;

        case 'image':
            if ($photo) {
                $state['photo'] = $photo;
                $state['step'] = 'category';
                file_put_contents($state_file, json_encode($state));
                
                $keyboard = getCategoriesKeyboard();
                sendMessage($chat_id, "📂 لطفاً دسته‌بندی محصول را انتخاب کنید:", $keyboard, true);
            } else {
                sendMessage($chat_id, "❌ لطفاً یک عکس معتبر ارسال کنید.");
            }
            break;

        default:
            sendMessage($chat_id, "دستور نامعتبر. لطفاً از /start استفاده کنید.");
            break;
    }
}

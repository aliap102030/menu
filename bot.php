<?php

define('BOT_TOKEN', '7985953791:AAGdrU3CStzlGFmzbLWJ0n_baAKWrY184vk');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('WC_API_URL', 'https://menupich.ir/cafeuka/wp-json/wc/v3/');
define('WC_CONSUMER_KEY', 'ck_19ec91648997cd153940a05c64ad150d0f23efa8');
define('WC_CONSUMER_SECRET', 'cs_834f98b5eb55de0ed47b8ba22c1aa587b2845878');

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
            $params['reply_markup'] = json_encode(['keyboard' => $keyboard, 'resize_keyboard' => true]);
        }
    }
    return apiRequest('sendMessage', $params);
}

function sendPhoto($chat_id, $photo, $caption = '') {
    $params = [
        'chat_id' => $chat_id,
        'photo' => $photo,
        'caption' => $caption,
    ];
    return apiRequest('sendPhoto', $params);
}

function apiRequest($method, $params) {
    $ch = curl_init(API_URL . $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('Telegram API request error: ' . curl_error($ch));
    }
    curl_close($ch);
    return json_decode($response, true);
}

function wp_api_request($endpoint, $method = 'GET', $data = []) {
    $url = WC_API_URL . $endpoint . "?consumer_key=" . WC_CONSUMER_KEY . "&consumer_secret=" . WC_CONSUMER_SECRET;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('WooCommerce API request error: ' . curl_error($ch));
        curl_close($ch);
        return null;
    }
    curl_close($ch);
    return json_decode($response, true);
}

function getCategoriesKeyboard() {
    $cats = wp_api_request('products/categories', 'GET', ['per_page' => 100]);
    $buttons = [];
    if (is_array($cats)) {
        foreach ($cats as $cat) {
            $buttons[] = [['text' => $cat['name'], 'callback_data' => 'cat_' . $cat['id']]];
        }
    }
    $buttons[] = [['text' => '➕ افزودن دسته‌بندی جدید', 'callback_data' => 'add_cat']];
    return $buttons;
}

// دریافت داده‌ها با ایمنی
$chat_id = null;
$message = null;
$photo = null;
$callback_data = null;
$user_id = null;

if (isset($data['message'])) {
    $chat_id = $data['message']['chat']['id'] ?? null;
    $message = $data['message']['text'] ?? null;
    if (!empty($data['message']['photo'])) {
        $photo = end($data['message']['photo'])['file_id'] ?? null;
    }
    $user_id = $data['message']['from']['id'] ?? null;
} elseif (isset($data['callback_query'])) {
    $chat_id = $data['callback_query']['message']['chat']['id'] ?? null;
    $callback_data = $data['callback_query']['data'] ?? null;
    $user_id = $data['callback_query']['from']['id'] ?? null;
}

if (!$chat_id) {
    // شناسه چت پیدا نشد، پایان اجرا
    exit;
}

// ذخیره‌سازی وضعیت کاربر
$state_file = __DIR__ . "/state_$chat_id.json";
$s = file_exists($state_file) ? json_decode(file_get_contents($state_file), true) : [];

// هندل کردن callback query ها
if ($callback_data) {
    if ($callback_data == 'add_cat') {
        $s['step'] = 'new_category_name';
        file_put_contents($state_file, json_encode($s));
        sendMessage($chat_id, "📝 لطفاً نام دسته‌بندی جدید را وارد کنید:");
        exit;
    }

    if (strpos($callback_data, 'cat_') === 0) {
        $cat_id = (int) str_replace('cat_', '', $callback_data);
        $s['product']['categories'] = [['id' => $cat_id]];
        $s['step'] = 'complete_product';
        file_put_contents($state_file, json_encode($s));
        $text = "📦 لطفاً تایید کنید:\n\nنام: {$s['product']['name']}\nقیمت: {$s['product']['regular_price']} تومان\nتوضیح: {$s['product']['description']}";
        sendMessage($chat_id, $text, [
            [['text' => '✅ تایید', 'callback_data' => 'confirm_product']],
            [['text' => '❌ لغو', 'callback_data' => 'cancel_product']]
        ], true);
        exit;
    }

    if ($callback_data == 'confirm_product') {
        $res = wp_api_request('products', 'POST', $s['product']);
        if (isset($res['id'])) {
            sendMessage($chat_id, "✅ محصول با موفقیت ساخته شد.");
        } else {
            $err = json_encode($res, JSON_UNESCAPED_UNICODE);
            sendMessage($chat_id, "❌ خطا در ساخت محصول:\n$err");
        }
        unlink($state_file);
        exit;
    }

    if ($callback_data == 'cancel_product') {
        unlink($state_file);
        sendMessage($chat_id, "❌ عملیات لغو شد.");
        exit;
    }
}

// هندل کردن دستورات و مراحل با پیام متنی
if ($message) {
    if ($message == '/start') {
        $s = ['step' => 'name'];
        file_put_contents($state_file, json_encode($s));
        sendMessage($chat_id, "👋 سلام! لطفاً نام محصول را وارد کنید:");
        exit;
    }

    switch ($s['step'] ?? '') {
        case 'name':
            $s['product']['name'] = $message;
            $s['step'] = 'price';
            sendMessage($chat_id, "💵 لطفاً قیمت محصول را وارد کنید (فقط عدد):");
            break;

        case 'price':
            if (!is_numeric($message)) {
                sendMessage($chat_id, "❌ لطفاً فقط عدد وارد کنید.");
                exit;
            }
            $s['product']['regular_price'] = $message;
            $s['step'] = 'description';
            sendMessage($chat_id, "📝 لطفاً توضیح محصول را وارد کنید:");
            break;

        case 'description':
            $s['product']['description'] = $message;
            $s['step'] = 'image';
            sendMessage($chat_id, "📸 لطفاً عکس محصول را ارسال کنید:");
            break;

        case 'new_category_name':
            $cat = wp_api_request('products/categories', 'POST', ['name' => $message]);
            if (isset($cat['id'])) {
                $s['product']['categories'] = [['id' => $cat['id']]];
                $s['step'] = 'complete_product';
                $text = "📦 لطفاً تایید کنید:\n\nنام: {$s['product']['name']}\nقیمت: {$s['product']['regular_price']} تومان\nتوضیح: {$s['product']['description']}";
                sendMessage($chat_id, $text, [
                    [['text' => '✅ تایید', 'callback_data' => 'confirm_product']],
                    [['text' => '❌ لغو', 'callback_data' => 'cancel_product']]
                ], true);
            } else {
                $err = json_encode($cat, JSON_UNESCAPED_UNICODE);
                sendMessage($chat_id, "❌ خطا در ایجاد دسته‌بندی:\n$err");
            }
            break;

        case 'image':
            if (!$photo) {
                sendMessage($chat_id, "❌ لطفاً یک عکس ارسال کنید.");
                exit;
            }
            $file = json_decode(file_get_contents(API_URL . "getFile?file_id=$photo"), true);
            if (!isset($file['result']['file_path'])) {
                sendMessage($chat_id, "❌ خطا در دریافت عکس.");
                exit;
            }
            $file_path = $file['result']['file_path'];
            $img_url = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/$file_path";
            $s['product']['images'] = [['src' => $img_url]];
            $s['step'] = 'category';
            $keyboard = getCategoriesKeyboard();
            sendMessage($chat_id, "📂 لطفاً یک دسته‌بندی انتخاب کنید:", $keyboard, true);
            break;

        case 'category':
        case 'complete_product':
        default:
            // اگر مرحله نامشخص یا کامل بود و پیام اضافی است، می‌توان پیام مناسبی ارسال کرد
            sendMessage($chat_id, "لطفاً از دکمه‌ها استفاده کنید یا /start را بزنید برای شروع مجدد.");
            break;
    }
    file_put_contents($state_file, json_encode($s));
}

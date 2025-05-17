<?php

define('BOT_TOKEN', '7985953791:AAGdrU3CStzlGFmzbLWJ0n_baAKWrY184vk');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('WC_API_URL', 'https://menupich.ir/cafeuka/wp-json/wc/v3/');
define('WC_CONSUMER_KEY', 'ck_19ec91648997cd153940a05c64ad150d0f23efa8');
define('WC_CONSUMER_SECRET', 'cs_834f98b5eb55de0ed47b8ba22c1aa587b2845878');

$data = json_decode(file_get_contents('php://input'), true);

file_put_contents('log.txt', json_encode($data) . PHP_EOL, FILE_APPEND);

function sendMessage($chat_id, $text, $keyboard = null) {
    $params = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard) {
        $params['reply_markup'] = json_encode(['keyboard' => $keyboard, 'resize_keyboard' => true]);
    }
    file_get_contents(API_URL . "sendMessage?" . http_build_query($params));
}

function sendPhoto($chat_id, $photo, $caption = '') {
    $url = API_URL . "sendPhoto";
    $post_fields = ['chat_id' => $chat_id, 'photo' => $photo, 'caption' => $caption];
    $ch = curl_init(); 
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type:multipart/form-data"]);
    curl_setopt($ch, CURLOPT_URL, $url); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields); 
    curl_exec($ch); 
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
    return json_decode($response, true);
}

function getCategoriesKeyboard() {
    $cats = wp_api_request('products/categories', 'GET', ['per_page' => 100]);
    $buttons = [];
    foreach ($cats as $cat) {
        $buttons[] = [['text' => $cat['name'], 'callback_data' => 'cat_' . $cat['id']]];
    }
    $buttons[] = [['text' => '➕ افزودن دسته‌بندی جدید', 'callback_data' => 'add_cat']];
    return ['inline_keyboard' => $buttons];
}

$chat_id = $data['message']['chat']['id'] ?? $data['callback_query']['message']['chat']['id'];
$message = $data['message']['text'] ?? null;
$photo = $data['message']['photo'][array_key_last($data['message']['photo'])]['file_id'] ?? null;
$callback_data = $data['callback_query']['data'] ?? null;
$user_id = $data['message']['from']['id'] ?? $data['callback_query']['from']['id'];

// حذف محدودیت دسترسی به مدیر

// ذخیره‌سازی داده در فایل برای هر کاربر
$state_file = __DIR__ . "/state_$chat_id.json";
$s = file_exists($state_file) ? json_decode(file_get_contents($state_file), true) : [];

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
        sendMessage($chat_id, "📦 لطفاً تایید کنید:\n\nنام: {$s['product']['name']}\nقیمت: {$s['product']['regular_price']} تومان\nتوضیح: {$s['product']['description']}", [
            [['text' => '✅ تایید', 'callback_data' => 'confirm_product']],
            [['text' => '❌ لغو', 'callback_data' => 'cancel_product']]
        ]);
        exit;
    }

    if ($callback_data == 'confirm_product') {
        $res = wp_api_request('products', 'POST', $s['product']);
        if (isset($res['id'])) {
            sendMessage($chat_id, "✅ محصول با موفقیت ساخته شد.");
        } else {
            sendMessage($chat_id, "❌ خطا در ساخت محصول: " . json_encode($res));
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

if ($message == '/start') {
    $s = ['step' => 'name'];
    file_put_contents($state_file, json_encode($s));
    sendMessage($chat_id, "👋 سلام! لطفاً نام محصول را وارد کنید:");
    exit;
}

switch ($s['step']) {
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

    case 'image':
        if (!$photo) {
            sendMessage($chat_id, "❌ لطفاً یک عکس ارسال کنید.");
            exit;
        }
        $file = json_decode(file_get_contents(API_URL . "getFile?file_id=$photo"), true);
        $file_path = $file['result']['file_path'];
        $img_url = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/$file_path";
        $s['product']['images'] = [['src' => $img_url]];
        $s['step'] = 'category';
        sendMessage($chat_id, "📂 لطفاً یک دسته‌بندی انتخاب کنید:", getCategoriesKeyboard());
        break;

    case 'new_category_name':
        $cat = wp_api_request('products/categories', 'POST', ['name' => $message]);
        if (isset($cat['id'])) {
            $s['product']['categories'] = [['id' => $cat['id']]];
            $s['step'] = 'complete_product';
            sendMessage($chat_id, "📦 لطفاً تایید کنید:\n\nنام: {$s['product']['name']}\nقیمت: {$s['product']['regular_price']} تومان\nتوضیح: {$s['product']['description']}", [
                [['text' => '✅ تایید', 'callback_data' => 'confirm_product']],
                [['text' => '❌ لغو', 'callback_data' => 'cancel_product']]
            ]);
        } else {
            sendMessage($chat_id, "❌ خطا در ایجاد دسته‌بندی: " . json_encode($cat));
        }
        break;
}

file_put_contents($state_file, json_encode($s));

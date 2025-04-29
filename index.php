<?php
ob_start(); // Start output buffering to prevent header errors

// Bot configuration
define('BOT_TOKEN', 'Place_Your_Token_Here');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('USERS_FILE', 'users.json');
define('ERROR_LOG', 'error.log');

// Error logging with permission handling
function logError($message) {
    try {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message\n";
        
        // Check and create error log if not exists
        if (!file_exists(ERROR_LOG)) {
            file_put_contents(ERROR_LOG, '');
            chmod(ERROR_LOG, 0666);
        }
        
        file_put_contents(ERROR_LOG, $logMessage, FILE_APPEND | LOCK_EX);
    } catch (Exception $e) {
        error_log('Failed to write error log: ' . $e->getMessage());
    }
}

// Data management with proper file handling
function loadUsers() {
    try {
        if (!file_exists(USERS_FILE)) {
            file_put_contents(USERS_FILE, json_encode([]));
            chmod(USERS_FILE, 0666);
        }
        $data = file_get_contents(USERS_FILE);
        return json_decode($data, true) ?: [];
    } catch (Exception $e) {
        logError("Load users failed: " . $e->getMessage());
        return [];
    }
}

function saveUsers($users) {
    try {
        file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT), LOCK_EX);
        return true;
    } catch (Exception $e) {
        logError("Save users failed: " . $e->getMessage());
        return false;
    }
}

// Message sending with error handling
function sendMessage($chat_id, $text, $keyboard = null) {
    try {
        $params = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        
        if ($keyboard) {
            $params['reply_markup'] = json_encode([
                'inline_keyboard' => $keyboard
            ]);
        }
        
        $context = stream_context_create([
            'http' => ['ignore_errors' => true]
        ]);
        
        $response = file_get_contents(API_URL . 'sendMessage?' . http_build_query($params), false, $context);
        
        if ($response === false) {
            throw new Exception("HTTP request failed");
        }
        
        return true;
    } catch (Exception $e) {
        logError("Send message failed [Chat:$chat_id]: " . $e->getMessage());
        return false;
    }
}

// Main keyboard layout
function getMainKeyboard() {
    return [
        [['text' => '💰 Earn', 'callback_data' => 'earn'], ['text' => '💳 Balance', 'callback_data' => 'balance']],
        [['text' => '🏆 Leaderboard', 'callback_data' => 'leaderboard'], ['text' => '👥 Referrals', 'callback_data' => 'referrals']],
        [['text' => '🏧 Withdraw', 'callback_data' => 'withdraw'], ['text' => '❓ Help', 'callback_data' => 'help']]
    ];
}

// Update processing with validation
function processUpdate($update) {
    try {
        $users = loadUsers();
        
        if (isset($update['message'])) {
            handleMessage($update['message'], $users);
        } elseif (isset($update['callback_query'])) {
            handleCallback($update['callback_query'], $users);
        }
        
        saveUsers($users);
    } catch (Exception $e) {
        logError("Process update failed: " . $e->getMessage());
    }
}

function handleMessage($message, &$users) {
    $chat_id = $message['chat']['id'];
    $text = trim($message['text'] ?? '');

    // Initialize new user
    if (!isset($users[$chat_id])) {
        $users[$chat_id] = [
            'balance' => 0,
            'last_earn' => 0,
            'referrals' => 0,
            'ref_code' => substr(md5($chat_id . microtime()), 0, 8),
            'referred_by' => null
        ];
    }

    if (strpos($text, '/start') === 0) {
        handleStartCommand($chat_id, $text, $users);
    }
}

function handleStartCommand($chat_id, $text, &$users) {
    $ref = explode(' ', $text)[1] ?? null;
    
    if ($ref && !$users[$chat_id]['referred_by']) {
        foreach ($users as $id => $user) {
            if ($user['ref_code'] === $ref && $id != $chat_id) {
                $users[$chat_id]['referred_by'] = $id;
                $users[$id]['referrals']++;
                $users[$id]['balance'] += 50;
                sendMessage($id, "🎉 New referral! +50 points bonus!");
                break;
            }
        }
    }
    
    $msg = "Welcome to Earning Bot!\nEarn points, invite friends, and withdraw your earnings!\nYour referral code: <b>{$users[$chat_id]['ref_code']}</b>";
    sendMessage($chat_id, $msg, getMainKeyboard());
}

function handleCallback($callback, &$users) {
    $chat_id = $callback['message']['chat']['id'];
    $data = $callback['data'];
    
    if (!isset($users[$chat_id])) {
        $users[$chat_id] = [
            'balance' => 0,
            'last_earn' => 0,
            'referrals' => 0,
            'ref_code' => substr(md5($chat_id . microtime()), 0, 8),
            'referred_by' => null
        ];
    }

    switch ($data) {
        case 'earn':
            handleEarn($chat_id, $users);
            break;
            
        case 'balance':
            $msg = "💳 Your Balance\nPoints: {$users[$chat_id]['balance']}\nReferrals: {$users[$chat_id]['referrals']}";
            break;
            
        case 'leaderboard':
            $msg = generateLeaderboard($users);
            break;
            
        case 'referrals':
            $msg = "👥 Referral System\nYour code: <b>{$users[$chat_id]['ref_code']}</b>\n"
                 . "Referrals: {$users[$chat_id]['referrals']}\n"
                 . "Invite link: t.me/" . BOT_TOKEN . "?start={$users[$chat_id]['ref_code']}\n"
                 . "50 points per referral!";
            break;
            
        case 'withdraw':
            $msg = handleWithdraw($chat_id, $users);
            break;
            
        case 'help':
            $msg = "❓ Help\n💰 Earn: Get 10 points/min\n👥 Refer: 50 points/ref\n🏧 Withdraw: Min 100 points\nUse buttons below to navigate!";
            break;
            
        default:
            $msg = "Unknown command";
    }
    
    if (!empty($msg)) {
        sendMessage($chat_id, $msg, getMainKeyboard());
    }
}

function handleEarn($chat_id, &$users) {
    $time_diff = time() - $users[$chat_id]['last_earn'];
    if ($time_diff < 60) {
        $remaining = 60 - $time_diff;
        return "⏳ Please wait $remaining seconds before earning again!";
    }
    
    $users[$chat_id]['balance'] += 10;
    $users[$chat_id]['last_earn'] = time();
    return "✅ You earned 10 points!\nNew balance: {$users[$chat_id]['balance']}";
}

function generateLeaderboard($users) {
    $sorted = [];
    foreach ($users as $id => $user) {
        $sorted[$id] = $user['balance'];
    }
    arsort($sorted);
    
    $msg = "🏆 Top Earners\n";
    $position = 1;
    foreach (array_slice($sorted, 0, 5, true) as $id => $balance) {
        $msg .= "$position. User #$id: $balance points\n";
        $position++;
    }
    return $msg;
}

function handleWithdraw($chat_id, &$users) {
    $min = 100;
    if ($users[$chat_id]['balance'] < $min) {
        return "🏧 Withdrawal\nMinimum: $min points\nYour balance: {$users[$chat_id]['balance']}\n"
             . "Need " . ($min - $users[$chat_id]['balance']) . " more points!";
    }
    
    $amount = $users[$chat_id]['balance'];
    $users[$chat_id]['balance'] = 0;
    return "🏧 Withdrawal of $amount points requested!\nOur team will process it soon.";
}

// Webhook handler
try {
    $content = file_get_contents("php://input");
    $update = json_decode($content, true);
    
    if ($update) {
        processUpdate($update);
        http_response_code(200);
    } else {
        http_response_code(400);
        logError("Invalid update received: " . $content);
    }
} catch (Exception $e) {
    http_response_code(500);
    logError("Fatal error: " . $e->getMessage());
}

ob_end_flush(); // End output buffering

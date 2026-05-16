<?php
// Подключаем наши настройки из первого файла
require_once 'config.php';

// Инициализация базы данных в файле на сервере
if (!file_exists('database.json')) {
    file_put_contents('database.json', json_encode([
        'mode' => 'demo', 
        'risk' => 1.0, 
        'demo' => ['mexc' => 5000, 'bingx' => 5000],
        'trades' => [], 
        'auto' => false, 
        'history' => []
    ]));
}
$db = json_decode(file_get_contents('database.json'), true);

function req($m, $p) {
    global $url;
    $ch = curl_init($url . $m);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $p);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $r = curl_exec($ch); curl_close($ch); return json_decode($r, true);
}

// Защищенный запрос к биржам с маскировкой под мобильный браузер
function fetch($link) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $link);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Mobile/15E148 Safari/604.1');
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

function getMexcAll() {
    $d = json_decode(fetch("https://api.mexc.com/api/v3/ticker/price"), true);
    $r = []; if($d) foreach($d as $i) $r[$i['symbol']] = (float)$i['price']; return $r;
}

function getBingxAll() {
    $d = json_decode(fetch("https://open-api.bingx.com/openApi/spot/v1/ticker/price"), true);
    $r = []; if(isset($d['data'])) foreach($d['data'] as $i) $r[str_replace("-", "", $i['symbol'])] = (float)$i['price']; return $r;
}

// Загружаем актуальные цены бирж для этого прогона
$mp = getMexcAll(); 
$bp = getBingxAll(); 

// 1. ПРОВЕРКА ОТКРЫТЫХ СДЕЛОК НА СХОЖДЕНИЕ
foreach ($db['trades'] as $id => &$t) {
    if ($t['status'] == 'open') {
        $cm = $mp[$t['s']] ?? 0; $cb = $bp[$t['s']] ?? 0;
        if ($cm > 0 && $cb > 0) {
            $diff = abs($cm - $cb) / min($cm, $cb) * 100;
            if ($diff <= 0.05) { 
                $t['status'] = 'closed';
                if ($t['type'] == 'long_m') {
                    $p_mexc = ($cm - $t['p_m']) * $t['vol_m']; 
                    $p_bing = ($t['p_b'] - $cb) * $t['vol_b']; 
                } else {
                    $p_mexc = ($t['p_m'] - $cm) * $t['vol_m']; 
                    $p_bing = ($cb - $t['p_b']) * $t['vol_b']; 
                }
                $total_profit = $p_mexc + $p_bing;
                $db['demo']['mexc'] += $p_mexc;
                $db['demo']['bingx'] += $p_bing;
                
                $db['history'][] = ['s' => $t['s'], 'p' => round($total_profit, 2), 'pm' => round($p_mexc, 2), 'pb' => round($p_bing, 2), 'd' => date("d.m.Y H:i")];
                
                $report = "🏁 **Сделка сошлась!** 🚀\n\n🪙 Монета: #{$t['s']}\n🟢 MEXC: " . round($p_mexc, 2) . "$\n🔵 BingX: " . round($p_bing, 2) . "$\n💵 **Чистый итог: " . round($total_profit, 2) . "$** 🎉";
                req("sendMessage", ['chat_id' => $admin, 'text' => $report, 'parse_mode' => 'Markdown']);
            }
        }
    }
}
file_put_contents('database.json', json_encode($db));

// 2. ОБРАБОТКА ВХОДЯЩИХ КОМАНД ИЗ TELEGRAM (Webhook/Polling)
$input = file_get_contents('php://input');
$u = json_decode($input, true);

if (!$u) {
    // Если запрос пришел не от Телеграма, а от UptimeRobot/Cron-job — выполняем фоновое сканирование рынка
    if ($db['auto'] && !empty($mp) && !empty($bp)) {
        foreach ($mp as $s => $p1) {
            if (isset($bp[$s]) && $p1 > 0 && $bp[$s] > 0) {
                $p2 = $bp[$s]; 
                $spr = (abs($p1 - $p2) / min($p1, $p2) * 100) - 0.24;
                if ($spr >= 0.15 && $spr < 25) {
                    // В режиме авто-торговли тут можно открывать ордера автоматически
                }
            }
        }
    }
    echo "Бот работает. Мониторинг рынка выполнен успешно: " . date("H:i:s");
    exit;
}

// Логика кнопок и текстовых сообщений
$msg = $u['message'] ?? $u['callback_query']['message'] ?? null;
if ($msg && ($u['message']['from']['id'] ?? $u['callback_query']['from']['id']) == $admin) {
    $cid = $msg['chat']['id'];
    $txt = $u['message']['text'] ?? "";

    $kb = ['keyboard' => [
        [['text' => '🔍 ПОИСК СПРЕДОВ 🚀']],
        [['text' => '💰 БАЛАНС 💳'], ['text' => '⚙️ НАСТРОЙКИ 🛠']],
        [['text' => '🤖 АВТО-ТОРГОВЛЯ: ' . ($db['auto'] ? '✅ ВКЛ' : '❌ ВЫКЛ')]]
    ], 'resize_keyboard' => true];

    if ($txt == "/start") {
        req("sendMessage", ['chat_id' => $cid, 'text' => "👋 Привет, Рахмон! Бот успешно развернут в облаке Render и готов к работе. Ищу суточные спреды!", 'reply_markup' => json_encode($kb)]);
    }

    if ($txt == "💰 БАЛАНС 💳") {
        $total = $db['demo']['mexc'] + $db['demo']['bingx'];
        $text = "📊 **ОТЧЕТ ПО БАЛАНСУ** (".strtoupper($db['mode']).")\n\n🟢 MEXC: " . round($db['demo']['mexc'], 2) . " USDT\n🔵 BingX: " . round($db['demo']['bingx'], 2) . " USDT\n\n💵 **Всего:** " . round($total, 2) . " USDT";
        req("sendMessage", ['chat_id' => $cid, 'text' => $text, 'parse_mode' => 'Markdown']);
    }

    if (strpos($txt, "АВТО-ТОРГОВЛЯ") !== false) {
        $db['auto'] = !$db['auto'];
        file_put_contents('database.json', json_encode($db));
        $kb['keyboard'][2][0]['text'] = '🤖 АВТО-ТОРГОВЛЯ: ' . ($db['auto'] ? '✅ ВКЛ' : '❌ ВЫКЛ');
        req("sendMessage", ['chat_id' => $cid, 'text' => "🔄 Режим авто-торговли изменен!", 'reply_markup' => json_encode($kb)]);
    }

    if ($txt == "🔍 ПОИСК СПРЕДОВ 🚀") {
        if (empty($mp) || empty($bp)) {
            req("sendMessage", ['chat_id' => $cid, 'text' => "⚠️ Ошибка получения данных. Попробуйте еще раз."]);
            exit;
        }

        $f = [];
        foreach ($mp as $s => $p1) {
            if (isset($bp[$s]) && $p1 > 0 && $bp[$s] > 0) {
                $p2 = $bp[$s]; 
                $spr = (abs($p1 - $p2) / min($p1, $p2) * 100) - 0.24; 
                
                if ($spr >= 0.10 && $spr < 25) { 
                    $f[] = ['s' => $s, 'm' => $p1, 'b' => $p2, 'p' => round($spr, 2)];
                }
            }
        }
        usort($f, function($a, $b){return $b['p'] <=> $a['p'];});
        
        $top = array_slice($f, 0, 5);
        if (empty($top)) {
            req("sendMessage", ['chat_id' => $cid, 'text' => "⏸ Суточных чистых спредов прямо сейчас не обнаружено. Рынок стабилен."]);
        } else {
            foreach ($top as $i) {
                $m = "💎 **Пара: {$i['s']}**\n🔥 Чистый спред: `{$i['p']}%` (Комиссия учтена)\n\n🟢 Цена MEXC: `{$i['m']}`\n🔵 Цена BingX: `{$i['b']}`";
                $chart_url = "https://www.tradingview.com/chart/?symbol=MEXC:{$i['s']}USDT&theme=dark"; 
                $ik = ['inline_keyboard' => [
                    [['text' => '📊 ГРАФИК ЛИНИИ (Dark)', 'url' => $chart_url]],
                    [['text' => '⚡️ ОТКРЫТЬ СДЕЛКУ (L/S)', 'callback_data' => "open_{$i['s']}_{$i['m']}_{$i['b']}"]]
                ]];
                req("sendMessage", ['chat_id' => $cid, 'text' => $m, 'reply_markup' => json_encode($ik), 'parse_mode' => 'Markdown']);
            }
        }
    }

    if ($txt == "⚙️ НАСТРОЙКИ 🛠") {
        $sk = ['inline_keyboard' => [
            [['text' => "🔄 Режим: " . strtoupper($db['mode']), 'callback_data' => "toggle_mode"]],
            [['text' => "💵 100% Риск", 'callback_data' => "risk_1.0"], ['text' => "📉 20% Risk", 'callback_data' => "risk_0.2"], ['text' => "📊 50% Risk", 'callback_data' => "risk_0.5"]],
            [['text' => "📁 СКАЧАТЬ ИСТОРИЮ", 'callback_data' => "get_history"]]
        ]];
        req("sendMessage", ['chat_id' => $cid, 'text' => "🛠 **Настройки комплекса:**", 'reply_markup' => json_encode($sk), 'parse_mode' => 'Markdown']);
    }

    // Обработка инлайн-кнопок
    if (isset($u['callback_query'])) {
        $d = explode("_", $u['callback_query']['data']);
        if ($d[0] == 'open') { 
            $sym = $d[1]; $pm = (float)$d[2]; $pb = (float)$d[3];
            $allocated = 100 * $db['risk'];
            $db['trades'][] = [
                's' => $sym, 'p_m' => $pm, 'p_b' => $pb, 'status' => 'open',
                'type' => ($pm < $pb) ? 'long_m' : 'long_b',
                'vol_m' => $allocated / $pm, 'vol_b' => $allocated / $pb, 'time' => date("H:i:s")
            ];
            file_put_contents('database.json', json_encode($db));
            req("answerCallbackQuery", ['callback_query_id' => $u['callback_query']['id'], 'text' => "🚀 Демо-ордера открыты!", 'show_alert' => true]);
        }
        if ($d[0] == 'risk') { $db['risk'] = (float)$d[1]; file_put_contents('database.json', json_encode($db)); req("answerCallbackQuery", ['callback_query_id' => $u['callback_query']['id'], 'text' => "Риск изменен!"]); }
        if ($d[0] == 'toggle_mode') { $db['mode'] = ($db['mode'] == 'demo' ? 'real' : 'demo'); file_put_contents('database.json', json_encode($db)); req("answerCallbackQuery", ['callback_query_id' => $u['callback_query']['id'], 'text' => "Режим изменен!"]); }
        if ($d[0] == 'get_history') {
            $h = "📜 ИСТОРИЯ СДЕЛОК\n\n";
            foreach($db['history'] as $i) $h .= "📅 {$i['d']} | 🪙 {$i['s']} | Итог: {$i['p']}$\n";
            file_put_contents("history.txt", $h);
            req("sendMessage", ['chat_id' => $cid, 'text' => "📁 Сделки выгружены."]);
        }
    }
}

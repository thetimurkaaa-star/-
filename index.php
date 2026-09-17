<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$db = new PDO('sqlite:' . DB_PATH);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec(file_get_contents(__DIR__ . '/schema.sql'));

function ensureColumn(PDO $db, string $table, string $column, string $definition): void {
    $columns=$db->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_COLUMN,1);
    if(!in_array($column,$columns,true)) $db->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$definition);
}
ensureColumn($db,'users','respect','INTEGER NOT NULL DEFAULT 0');
ensureColumn($db,'users','vip_until','INTEGER NOT NULL DEFAULT 0');
ensureColumn($db,'users','vip_started_at','INTEGER NOT NULL DEFAULT 0');
ensureColumn($db,'broadcast_log','target_type',"TEXT NOT NULL DEFAULT 'users'");

function tg(string $method, array $data = []): array {
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_TIMEOUT => 30
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    return json_decode($raw ?: '{}', true) ?: [];
}

function money(int $n): string {
    return number_format($n, 0, '.', '.');
}

function xpNeed(int $level): int {
    return 1000 + (($level - 1) * 250);
}

function ensureUser(array $from): void {
    global $db;
    $now = time();
    $q = $db->prepare('SELECT id FROM users WHERE id=?');
    $q->execute([$from['id']]);
    if (!$q->fetchColumn()) {
        $db->prepare('INSERT INTO users(id,username,first_name,balance,respect,vip_until,vip_started_at,strength,level,xp,registered_at,last_seen_at)
                      VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([
               $from['id'], $from['username'] ?? null, $from['first_name'] ?? '',
               START_BALANCE, 0, 0, 0, START_STRENGTH, START_LEVEL, START_XP, $now, $now
           ]);
        if ((int)$from['id'] === MAIN_ADMIN_ID) {
            $db->prepare('INSERT OR IGNORE INTO admins(user_id,role,added_at) VALUES(?,?,?)')
               ->execute([$from['id'], 'main', $now]);
        }
    } else {
        $db->prepare('UPDATE users SET username=?,first_name=?,last_seen_at=? WHERE id=?')
           ->execute([$from['username'] ?? null, $from['first_name'] ?? '', $now, $from['id']]);
    }
}

function user(int $id): array {
    global $db;
    $q = $db->prepare('SELECT * FROM users WHERE id=?');
    $q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: [];
}

function changeBalance(int $id, int $delta, string $reason, ?int $actor = null): bool {
    global $db;
    $db->beginTransaction();
    try {
        $u = user($id);
        $new = (int)$u['balance'] + $delta;
        if ($new < 0) throw new RuntimeException('Недостаточно денег');
        $db->prepare('UPDATE users SET balance=? WHERE id=?')->execute([$new, $id]);
        $db->prepare('INSERT INTO transactions(user_id,amount,balance_after,reason,actor_id,created_at)
                      VALUES(?,?,?,?,?,?)')
           ->execute([$id,$delta,$new,$reason,$actor,time()]);
        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollBack();
        return false;
    }
}

function addXp(int $id, int $amount): void {
    global $db;
    $u = user($id);
    $xp = (int)$u['xp'] + $amount;
    $level = (int)$u['level'];
    while ($xp >= xpNeed($level)) {
        $xp -= xpNeed($level);
        $level++;
    }
    $db->prepare('UPDATE users SET xp=?,level=? WHERE id=?')->execute([$xp,$level,$id]);
}

function admin(int $id): bool {
    global $db;
    if ($id === MAIN_ADMIN_ID) return true;
    $q = $db->prepare("SELECT 1 FROM admins WHERE user_id=? AND role IN ('admin','main')");
    $q->execute([$id]);
    return (bool)$q->fetchColumn();
}

function isVip(int $id): bool { $u=user($id); return !empty($u) && (int)$u['vip_until']>time(); }
function changeRespect(int $id,int $delta,string $reason='',?int $actor=null): bool { global $db; $u=user($id); if(!$u)return false; $new=(int)$u['respect']+$delta;if($new<0)return false;$db->prepare('UPDATE users SET respect=? WHERE id=?')->execute([$new,$id]);return true; }
function registerChat(array $chat): void { global $db;$type=$chat['type']??'';if(!in_array($type,['private','group','supergroup'],true))return;$db->prepare('INSERT INTO chat_registry(chat_id,chat_type,title,username,active,last_seen_at) VALUES(?,?,?,?,1,?) ON CONFLICT(chat_id) DO UPDATE SET chat_type=excluded.chat_type,title=excluded.title,username=excluded.username,active=1,last_seen_at=excluded.last_seen_at')->execute([(int)$chat['id'],$type,$chat['title']??null,$chat['username']??null,time()]); }
function trackChatActivity(int $userId,int $chatId,string $type): void { global $db;if(!in_array($type,['group','supergroup'],true))return;$day=date('Y-m-d');$db->prepare('INSERT INTO chat_activity(user_id,chat_id,day,messages) VALUES(?,?,?,1) ON CONFLICT(user_id,chat_id,day) DO UPDATE SET messages=messages+1')->execute([$userId,$chatId,$day]); }
function vipProgress(int $id): array { global $db;$u=user($id);$start=(int)$u['vip_started_at'];$q=$db->prepare('SELECT COUNT(*) FROM jobs WHERE user_id=? AND created_at>=?');$q->execute([$id,$start]);$jobs=(int)$q->fetchColumn();$q=$db->prepare('SELECT COUNT(*) FROM robberies WHERE attacker_id=? AND created_at>=?');$q->execute([$id,$start]);$rob=(int)$q->fetchColumn();$q=$db->prepare('SELECT COALESCE(SUM(messages),0) FROM chat_activity WHERE user_id=? AND day>=?');$q->execute([$id,date('Y-m-d',$start)]);return ['jobs'=>$jobs,'robberies'=>$rob,'messages'=>(int)$q->fetchColumn()]; }
function vipText(int $id): string { if(!isVip($id))return "💎 <b>VIP</b>\n\nVIP-задания доступны VIP-игрокам.\n\n🎯 5 работ → <b>4 500 бабок</b>\n🔪 3 ограбления → <b>10 000 бабок</b>\n💬 30 сообщений → <b>15 000 бабок + 10 Респектов</b>";global $db;$p=vipProgress($id);$q=$db->prepare('SELECT task_key FROM vip_task_claims WHERE user_id=?');$q->execute([$id]);$c=array_flip($q->fetchAll(PDO::FETCH_COLUMN));return "💎 <b>VIP-ЗАДАНИЯ</b>\n\n🎯 5 работ: <b>{$p['jobs']}/5</b> ".(isset($c['jobs'])?'✅':'')."\n🔪 3 ограбления: <b>{$p['robberies']}/3</b> ".(isset($c['robberies'])?'✅':'')."\n💬 30 сообщений: <b>{$p['messages']}/30</b> ".(isset($c['messages'])?'✅':''); }
function resolveEvent(int $eventId): void { global $db;$q=$db->prepare('SELECT * FROM events WHERE id=?');$q->execute([$eventId]);$e=$q->fetch();if(!$e||$e['status']!=='active'||(int)$e['end_at']>time())return;$n=max(1,(int)$e['winners_count']);$q=$db->prepare('SELECT user_id FROM event_participants WHERE event_id=? ORDER BY RANDOM() LIMIT '.$n);$q->execute([$eventId]);$w=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));foreach($w as $uid){if((int)$e['reward_money']>0)changeBalance($uid,(int)$e['reward_money'],'Победа в событии #'.$eventId);if((int)$e['reward_respect']>0)changeRespect($uid,(int)$e['reward_respect'],'Победа в событии #'.$eventId);send($uid,"🎉 <b>ТЫ ПОБЕДИЛ!</b>\n\n🎉 ".htmlspecialchars($e['title'])."\n💰 +".money((int)$e['reward_money'])."\n💎 +".money((int)$e['reward_respect'])." Респектов");}$db->prepare("UPDATE events SET status='finished',winner_ids=? WHERE id=?")->execute([json_encode($w),$eventId]); }
function resolveExpiredEvents(): void { global $db;foreach($db->query("SELECT id FROM events WHERE status='active' AND end_at<=".time()) as $e)resolveEvent((int)$e['id']); }
function eventsText(int $id): void {
    global $db;
    resolveExpiredEvents();
    $rows=$db->query("SELECT * FROM events WHERE status='active' ORDER BY end_at ASC")->fetchAll();
    if(!$rows){ send($id,"🎉 <b>СОБЫТИЯ</b>\n\nСейчас активных розыгрышей нет."); return; }
    foreach($rows as $e){
        $q=$db->prepare('SELECT 1 FROM event_participants WHERE event_id=? AND user_id=?');
        $q->execute([(int)$e['id'],$id]);
        $joined=(bool)$q->fetchColumn();
        $pc=$db->prepare('SELECT COUNT(*) FROM event_participants WHERE event_id=?');
        $pc->execute([(int)$e['id']]);
        $cnt=(int)$pc->fetchColumn();
        $reward=((int)$e['reward_money']?'💰 '.money((int)$e['reward_money']):'').((int)$e['reward_respect']?' + 💎 '.money((int)$e['reward_respect']).' Респектов':'');
        $btnText=$joined?'✅ Ты участвуешь':'🎟 Участвовать';
        send($id,"🎉 <b>".htmlspecialchars($e['title'])."</b>\n\n".htmlspecialchars($e['description'])."\n\n🎁 Награда: <b>".($reward?:'—')."</b>\n🏆 Победителей: <b>{$e['winners_count']}</b>\n👥 Участников: <b>{$cnt}</b>\n⏰ До: <b>".date('d.m.Y H:i',(int)$e['end_at'])."</b>",buttons([[$btnText,'event:join:'.(int)$e['id']]]));
    }
}

function mainMenu(): array {
    return [
        'keyboard' => [
            [['text'=>'👤 Профиль'],['text'=>'💼 Работа']],
            [['text'=>'🎁 Бонус'],['text'=>'🚨 Ограбить']],
            [['text'=>'🏪 Мои бизнесы'],['text'=>'🎰 Казино']],
            [['text'=>'💪 Прокачка'],['text'=>'🎒 Инвентарь']],
            [['text'=>'🏆 Рейтинг'],['text'=>'🎉 События']],
            [['text'=>'👋 Новичкам']]
        ],
        'resize_keyboard'=>true
    ];
}

function send(int $chat, string $text, ?array $keyboard = null): void {
    $data = ['chat_id'=>$chat,'text'=>$text,'parse_mode'=>'HTML'];
    if ($keyboard) $data['reply_markup']=json_encode($keyboard, JSON_UNESCAPED_UNICODE);
    tg('sendMessage',$data);
}

function answer(array $cb, string $text=''): void {
    tg('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$text]);
}

function inline(array $rows): array {
    return ['inline_keyboard'=>$rows];
}

function profile(int $id): string {
    $u=user($id);
    return "👤 <b>ПРОФИЛЬ</b>\n\n".
           "💰 Бабки: <b>".money((int)$u['balance'])."</b>\n".
           "💪 Сила: <b>{$u['strength']}/100</b>\n".
           "⭐ Уровень: <b>{$u['level']}</b>\n".
           "XP: <b>{$u['xp']}/".xpNeed((int)$u['level'])."</b>\n\n".
           "🆔 ID: <code>{$id}</code>";
}

function shopText(int $id): string {
    $u=user($id);
    $items=[
        'knife'=>['🔪 Нож',10000],
        'pistol'=>['🔫 Пистолет',25000],
        'rifle'=>['🔫 Автомат',75000],
        'armor'=>['🛡 Броня',50000],
        'shop'=>['🏪 Свой магазин',150000]
    ];
    $s="🏪 <b>МАГАЗИН</b>\n\n";
    foreach($items as $k=>$v) $s.="{$v[0]} <b>{$v[1]}</b>\n💰 ".money($v[1])."\n\n";
    return $s;
}

function inventoryText(int $id): string {
    global $db;
    $names=['knife'=>'🔪 Нож','pistol'=>'🔫 Пистолет','rifle'=>'🔫 Автомат','armor'=>'🛡 Броня','shop'=>'🏪 Свой магазин'];
    $q=$db->prepare('SELECT item_key,quantity FROM inventory WHERE user_id=? AND quantity>0');
    $q->execute([$id]);
    $rows=$q->fetchAll(PDO::FETCH_KEY_PAIR);
    $s="🎒 <b>ИНВЕНТАРЬ</b>\n\n";
    if (!$rows) return $s."Пока пусто.";
    foreach($rows as $k=>$n) $s.=($names[$k]??$k).": <b>{$n}</b>\n";
    return $s;
}

function buttons(array $items): array {
    $rows=[];
    foreach(array_chunk($items,2) as $chunk) {
        $row=[];
        foreach($chunk as $item) $row[]=['text'=>$item[0],'callback_data'=>$item[1]];
        $rows[]=$row;
    }
    return inline($rows);
}

if (isset($_GET['cron']) && CRON_SECRET !== '' && hash_equals(CRON_SECRET, (string)$_GET['cron'])) { resolveExpiredEvents(); exit('OK'); }
if (WEBHOOK_SECRET !== '' && (($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '') !== WEBHOOK_SECRET)) { http_response_code(403); exit; }

$update=json_decode(file_get_contents('php://input'),true);
if (!$update) exit;

if (isset($update['callback_query'])) {
    $cb=$update['callback_query'];
    $from=$cb['from']; $chat=$cb['message']['chat']['id']; $id=(int)$from['id'];
    ensureUser($from); registerChat($cb['message']['chat']);
    $a=$cb['data'];

    if ($a==='profile') { answer($cb); send($chat,profile($id),buttons([['💎 VIP-задания','vip']])); exit; }

    if ($a==='upgrade') {
        $u=user($id);
        $cost=1000 * ((int)$u['strength'] + 1);
        if ((int)$u['strength']>=100) { answer($cb,'Максимальная сила'); exit; }
        if ((int)$u['balance']<$cost) { answer($cb,'Недостаточно денег'); exit; }
        changeBalance($id,-$cost,'Прокачка силы');
        $db->prepare('UPDATE users SET strength=strength+1 WHERE id=?')->execute([$id]);
        addXp($id,10);
        answer($cb,'Сила увеличена!');
        send($chat, "💪 Сила увеличена!\n\n".profile($id));
        exit;
    }

    if (str_starts_with($a,'buy:')) {
        $key=substr($a,4);
        $prices=['knife'=>10000,'pistol'=>25000,'rifle'=>75000,'armor'=>50000,'shop'=>150000];
        if (!isset($prices[$key])) exit;
        $price=$prices[$key];
        if (!changeBalance($id,-$price,'Покупка '.$key)) { answer($cb,'Недостаточно денег'); exit; }
        $db->prepare('INSERT INTO inventory(user_id,item_key,quantity) VALUES(?,?,1)
                      ON CONFLICT(user_id,item_key) DO UPDATE SET quantity=quantity+1')
           ->execute([$id,$key]);
        if ($key==='shop') {
            $db->prepare('INSERT OR IGNORE INTO shops(user_id,level,stored_income,last_income_at) VALUES(?,1,0,?)')
               ->execute([$id,time()]);
        }
        answer($cb,'Покупка совершена');
        send($chat,"✅ Куплено!\n\n".inventoryText($id));
        exit;
    }

    if ($a==='shop') {
        $q=$db->prepare('SELECT * FROM shops WHERE user_id=?'); $q->execute([$id]); $s=$q->fetch();
        if (!$s) { answer($cb,'Сначала купи свой магазин'); exit; }
        $incomePerHour=500 + ((int)$s['level']-1)*300;
        $elapsed=max(0,time()-(int)$s['last_income_at']);
        $generated=(int)floor($elapsed/3600)*$incomePerHour;
        if ($generated>0) {
            $db->prepare('UPDATE shops SET stored_income=stored_income+?,last_income_at=? WHERE user_id=?')
               ->execute([$generated,time(),$id]);
            $s['stored_income']+=(int)$generated;
        }
        $s="🏪 <b>ТВОЙ МАГАЗИН</b>\n\n".
           "📊 Уровень: <b>{$s['level']}</b>\n".
           "💰 Доход: <b>".money($incomePerHour)."/час</b>\n".
           "💵 Накоплено: <b>".money((int)$s['stored_income'])."</b>";
        answer($cb);
        send($chat,$s,buttons([
            ['💰 Забрать доход','shop:collect'],
            ['⬆️ Улучшить магазин','shop:upgrade'],
            ['🚨 Проверить магазин','shop:rob']
        ]));
        exit;
    }

    if (str_starts_with($a,'shop:')) {
        $action=substr($a,5);
        $q=$db->prepare('SELECT * FROM shops WHERE user_id=?'); $q->execute([$id]); $s=$q->fetch();
        if (!$s) { answer($cb,'Магазина нет'); exit; }
        if ($action==='collect') {
            $amount=(int)$s['stored_income'];
            $db->prepare('UPDATE shops SET stored_income=0 WHERE user_id=?')->execute([$id]);
            if ($amount) changeBalance($id,$amount,'Доход магазина');
            answer($cb,'Доход забран');
            send($chat,"💰 Получено: <b>".money($amount)."</b>");
        } elseif ($action==='upgrade') {
            $cost=150000*(int)$s['level'];
            if (!changeBalance($id,-$cost,'Улучшение магазина')) { answer($cb,'Недостаточно денег'); exit; }
            $db->prepare('UPDATE shops SET level=level+1 WHERE user_id=?')->execute([$id]);
            addXp($id,30); answer($cb,'Магазин улучшен');
            send($chat,'⬆️ Магазин улучшен!');
        } else {
            $now=time();
            if ($now-(int)$s['last_robbed_at']<259200) { answer($cb,'Проверить магазин можно раз в 3 дня'); exit; }
            $success=random_int(1,100)<=55;
            $amount=min((int)$s['stored_income'], random_int(1000,20000));
            $db->prepare('UPDATE shops SET last_robbed_at=? WHERE user_id=?')->execute([$now,$id]);
            if ($success && $amount>0) {
                $db->prepare('UPDATE shops SET stored_income=stored_income-? WHERE user_id=?')->execute([$amount,$id]);
                changeBalance($id,$amount,'Ограбление собственного магазина');
                send($chat,"🚨 Проверка завершена!\n\nУспех. 💰 +".money($amount));
            } else send($chat,'🚨 Проверка завершена!\n\n❌ Неудача.');
            addXp($id,30);
        }
        exit;
    }

    if ($a==='vip') { answer($cb);$kb=[];if(isVip($id)){global $db;$p=vipProgress($id);$q=$db->prepare('SELECT task_key FROM vip_task_claims WHERE user_id=?');$q->execute([$id]);$c=array_flip($q->fetchAll(PDO::FETCH_COLUMN));if($p['jobs']>=5&&!isset($c['jobs']))$kb[]=['🎁 4 500','vip:claim:jobs'];if($p['robberies']>=3&&!isset($c['robberies']))$kb[]=['🎁 10 000','vip:claim:robberies'];if($p['messages']>=30&&!isset($c['messages']))$kb[]=['🎁 15 000 + 10 💎','vip:claim:messages'];}send($chat,vipText($id),$kb?inline($kb):null);exit; }
    if (str_starts_with($a,'vip:claim:')) { $task=substr($a,10);if(!isVip($id)){answer($cb,'VIP недоступен');exit;}$p=vipProgress($id);$need=['jobs'=>5,'robberies'=>3,'messages'=>30];if(!isset($need[$task])||$p[$task]<$need[$task]){answer($cb,'Задание не выполнено');exit;}global $db;$q=$db->prepare('SELECT 1 FROM vip_task_claims WHERE user_id=? AND task_key=?');$q->execute([$id,$task]);if($q->fetchColumn()){answer($cb,'Уже получено');exit;}$rm=['jobs'=>4500,'robberies'=>10000,'messages'=>15000][$task];$rr=$task==='messages'?10:0;changeBalance($id,$rm,'VIP-задание '.$task);if($rr)changeRespect($id,$rr,'VIP-задание '.$task);$db->prepare('INSERT INTO vip_task_claims(user_id,task_key,claimed_at) VALUES(?,?,?)')->execute([$id,$task,time()]);answer($cb,'Награда получена');send($chat,"🎉 <b>VIP-награда!</b>\n💰 +".money($rm).($rr?"\n💎 +{$rr} Респектов":''));exit; }
    if ($a==='events') { answer($cb);eventsText($chat);exit; }
    if (str_starts_with($a,'event:join:')) { $eid=(int)substr($a,11);global $db;$q=$db->prepare("SELECT * FROM events WHERE id=? AND status='active'");$q->execute([$eid]);$e=$q->fetch();if(!$e){answer($cb,'Событие завершено');exit;}if((int)$e['end_at']<=time()){resolveEvent($eid);answer($cb,'Событие завершено');exit;}$db->prepare('INSERT OR IGNORE INTO event_participants(event_id,user_id,joined_at) VALUES(?,?,?)')->execute([$eid,$id,time()]);answer($cb,'Участие сохранено');eventsText($chat);exit; }

    if ($a==='casino') {
        answer($cb);
        send($chat,"🎰 <b>КАЗИНО</b>\n\nВыбери игру:",buttons([
            ['🎰 Слоты','casino:slots'],['🎲 Кубик','casino:dice'],
            ['🃏 Высокая карта','casino:high']
        ]));
        exit;
    }

    if (str_starts_with($a,'casino:')) {
        $game=substr($a,7);
        send($chat,"🎰 Игра: <b>{$game}</b>\n\nИспользуй:\n<code>/ставка 10000</code>\nПосле команды ставка будет сыграна в выбранной игре.");
        // Состояние выбранной игры упрощено: по умолчанию используется slots.
        exit;
    }

    if ($a==='inventory') { answer($cb); send($chat,inventoryText($id)); exit; }

    if ($a==='admin') {
        if (!admin($id)) { answer($cb,'Нет доступа'); exit; }
        answer($cb);
        send($chat,"👑 <b>АДМИН-ПАНЕЛЬ</b>",buttons([
            ['👥 Пользователи','adm:users'],['💰 Управление деньгами','adm:money'],
            ['📊 Статистика','adm:stats'],['📢 Рассылка','adm:broadcast'],
            ['👑 Администраторы','adm:admins']
        ]));
        exit;
    }

    if (str_starts_with($a,'adm:') && admin($id)) {
        $act=substr($a,4);
        if ($act==='stats') {
            $users=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $money=(int)$db->query('SELECT COALESCE(SUM(balance),0) FROM users')->fetchColumn();
            $shops=(int)$db->query('SELECT COUNT(*) FROM shops')->fetchColumn();
            $weapons=(int)$db->query("SELECT COALESCE(SUM(quantity),0) FROM inventory WHERE item_key IN ('knife','pistol','rifle')")->fetchColumn();
            $casino=(int)$db->query('SELECT COUNT(*) FROM casino_games')->fetchColumn();
            $rob=(int)$db->query('SELECT COUNT(*) FROM robberies')->fetchColumn();
            $today=strtotime('today');
            $active=(int)$db->prepare('SELECT COUNT(*) FROM users WHERE last_seen_at>=?')->execute([$today]) ?: 0;
            $q=$db->prepare('SELECT COUNT(*) FROM users WHERE last_seen_at>=?'); $q->execute([$today]); $active=(int)$q->fetchColumn();
            $q=$db->prepare('SELECT COUNT(*) FROM users WHERE registered_at>=?'); $q->execute([$today]); $new=(int)$q->fetchColumn();
            answer($cb);
            send($chat,"📊 <b>СТАТИСТИКА</b>\n\n👥 Всего пользователей: <b>{$users}</b>\n🟢 Активных сегодня: <b>{$active}</b>\n🆕 Новых сегодня: <b>{$new}</b>\n\n💰 Всего бабок: <b>".money($money)."</b>\n🏪 Магазинов: <b>{$shops}</b>\n🔫 Оружия: <b>{$weapons}</b>\n🎰 Игр в казино: <b>{$casino}</b>\n🚨 Ограблений: <b>{$rob}</b>");
            exit;
        }
        if ($act==='users') {
            answer($cb);
            $rows=$db->query('SELECT id,username,first_name,balance,strength,level FROM users ORDER BY balance DESC LIMIT 20')->fetchAll();
            $s="👥 <b>ПОЛЬЗОВАТЕЛИ — ТОП 20</b>\n\n";
            foreach($rows as $r) $s.="🆔 <code>{$r['id']}</code> ".htmlspecialchars($r['username']?'@'.$r['username']:$r['first_name'])." — 💰 ".money((int)$r['balance'])." | 💪 {$r['strength']} | ⭐ {$r['level']}\n";
            send($chat,$s); exit;
        }
        if ($act==='broadcast') { answer($cb); send($chat,"📢 <b>РАССЫЛКА</b>\n\n<code>/broadcast_users текст</code> — ЛС\n<code>/broadcast_chats текст</code> — группы\n<code>/broadcast_all текст</code> — ЛС + группы"); exit; }
        if ($act==='events') { answer($cb); send($chat,"🎉 <b>СОБЫТИЯ</b>\n\n<code>/event_create Название | Описание | 2026-12-31 23:59 | 10000 | 10 | 1</code>\n<code>/event_list</code>\n<code>/event_end ID</code>"); exit; }
        if ($act==='vip') { answer($cb); send($chat,"💎 <b>VIP</b>\n\n<code>/vip ID DAYS</code> — выдать VIP\n<code>/unvip ID</code> — снять VIP\n<code>/respect ID SUM</code> — изменить Респекты"); exit; }
        if ($act==='admins') {
            answer($cb);
            $rows=$db->query('SELECT a.user_id,a.role,u.username,u.first_name FROM admins a JOIN users u ON u.id=a.user_id ORDER BY a.role DESC')->fetchAll();
            $s="👑 <b>АДМИНИСТРАТОРЫ</b>\n\n";
            foreach($rows as $r) $s.=($r['role']==='main'?'👑 Главный администратор':'🛡 Администратор')."\nID: <code>{$r['user_id']}</code>\n\n";
            send($chat,$s,buttons([['➕ Добавить администратора','adm:add'],['➖ Удалить администратора','adm:del']])); exit;
        }
        if ($act==='money') {
            answer($cb); send($chat,"💰 <b>УПРАВЛЕНИЕ ДЕНЬГАМИ</b>\n\n/give ID SUM\n/take ID SUM\n/setmoney ID SUM"); exit;
        }
        if ($act==='broadcast') {
            answer($cb); send($chat,"📢 Для рассылки отправь:\n<code>/safonof52 текст сообщения</code>"); exit;
        }
    }
    exit;
}

if (!isset($update['message'])) exit;
$m=$update['message'];
$from=$m['from']; $chat=(int)$m['chat']['id']; $id=(int)$from['id'];
ensureUser($from); registerChat($m['chat']); trackChatActivity($id,$chat,$m['chat']['type']??'');
$text=trim($m['text'] ?? '');

if ($text==='/start') {
    send($chat,"💰 <b>РАБОТА БАНДИТА</b>\n\n".profile($id),mainMenu()); exit;
}

if ($text==='/safonof') {
    if (!admin($id)) { send($chat,'⛔ Доступ запрещён.'); exit; }
    send($chat,'👑 <b>АДМИН-ПАНЕЛЬ</b>',buttons([
        ['👥 Пользователи','adm:users'],['💰 Управление деньгами','adm:money'],
        ['📊 Статистика','adm:stats'],['📢 Рассылка','adm:broadcast'],
        ['🎉 События','adm:events'],['💎 VIP','adm:vip'],
        ['👑 Администраторы','adm:admins']
    ])); exit;
}

if (preg_match('~^/safonof52\s+(.+)~us',$text,$mm) && admin($id)) {
    $message=$mm[1]; $sent=0; $failed=0;
    foreach($db->query('SELECT id FROM users') as $r) {
        $res=tg('sendMessage',['chat_id'=>$r['id'],'text'=>$message,'parse_mode'=>'HTML']);
        if (!empty($res['ok'])) $sent++; else $failed++;
    }
    $db->prepare('INSERT INTO broadcast_log(admin_id,message,sent_count,failed_count,created_at) VALUES(?,?,?,?,?)')
       ->execute([$id,$message,$sent,$failed,time()]);
    send($chat,"📢 Рассылка завершена.\n\n✅ Отправлено: {$sent}\n❌ Ошибок: {$failed}"); exit;
}

if (preg_match('~^/broadcast_(users|chats|all)\s+(.+)~us',$text,$mm) && admin($id)) { $type=$mm[1];$targets=[];if($type!=='chats')foreach($db->query('SELECT id FROM users') as $r)$targets[(string)$r['id']] = 1;if($type!=='users')foreach($db->query("SELECT chat_id FROM chat_registry WHERE chat_type IN ('group','supergroup') AND active=1") as $r)$targets[(string)$r['chat_id']] = 1;$sent=0;$failed=0;foreach(array_keys($targets) as $target){$res=send((int)$target,$mm[2]);if(!empty($res['ok']))$sent++;else{$failed++;if(in_array($res['error_code']??0,[400,403],true))$db->prepare('UPDATE chat_registry SET active=0 WHERE chat_id=?')->execute([(int)$target]);}usleep(35000);}$db->prepare('INSERT INTO broadcast_log(admin_id,target_type,message,sent_count,failed_count,created_at) VALUES(?,?,?,?,?,?)')->execute([$id,$type,$mm[2],$sent,$failed,time()]);send($chat,"📢 Рассылка завершена.\n\n✅ {$sent}\n❌ {$failed}");exit; }
if (preg_match('~^/vip\s+(\d+)\s+(\d+)$~',$text,$mm) && admin($id)) { $target=(int)$mm[1];$days=max(1,(int)$mm[2]);if(!user($target)){send($chat,'Пользователь не найден.');exit;}$until=time()+$days*86400;$db->prepare('UPDATE users SET vip_until=?,vip_started_at=? WHERE id=?')->execute([$until,time(),$target]);$db->prepare('DELETE FROM vip_task_claims WHERE user_id=?')->execute([$target]);send($chat,'💎 VIP выдан.');send($target,'💎 <b>VIP активирован</b> до '.date('d.m.Y H:i',$until));exit; }
if (preg_match('~^/unvip\s+(\d+)$~',$text,$mm) && admin($id)) { $db->prepare('UPDATE users SET vip_until=0,vip_started_at=0 WHERE id=?')->execute([(int)$mm[1]]);send($chat,'💎 VIP снят.');exit; }
if (preg_match('~^/respect\s+(\d+)\s+(-?\d+)$~',$text,$mm) && admin($id)) { if(changeRespect((int)$mm[1],(int)$mm[2],'Администратор',$id))send($chat,'💎 Респекты изменены.');else send($chat,'❌ Не удалось изменить.');exit; }
if (preg_match('~^/event_create\s+(.+)$~us',$text,$mm) && admin($id)) { $parts=array_map('trim',explode('|',$mm[1]));if(count($parts)!==6){send($chat,'Формат: /event_create Название | Описание | YYYY-MM-DD HH:MM | деньги | респекты | победители');exit;}$end=strtotime($parts[2]);if(!$end||$end<=time()){send($chat,'❌ Дата должна быть в будущем.');exit;}$db->prepare('INSERT INTO events(title,description,end_at,reward_money,reward_respect,winners_count,created_by,created_at) VALUES(?,?,?,?,?,?,?,?)')->execute([$parts[0],$parts[1],$end,max(0,(int)$parts[3]),max(0,(int)$parts[4]),max(1,min(50,(int)$parts[5])),$id,time()]);send($chat,'🎉 Событие создано: #'.$db->lastInsertId());exit; }
if ($text==='/event_list' && admin($id)) { $rows=$db->query('SELECT id,title,end_at,status FROM events ORDER BY id DESC LIMIT 30')->fetchAll();$s="🎉 <b>СОБЫТИЯ</b>\n\n";foreach($rows as $r)$s.="#{$r['id']} ".htmlspecialchars($r['title'])." — {$r['status']} — ".date('d.m.Y H:i',(int)$r['end_at'])."\n";send($chat,$s);exit; }
if (preg_match('~^/event_end\s+(\d+)$~',$text,$mm) && admin($id)) { $db->prepare("UPDATE events SET end_at=? WHERE id=? AND status='active'")->execute([time(),(int)$mm[1]]);resolveEvent((int)$mm[1]);send($chat,'🎉 Событие завершено, победители выбраны.');exit; }
if (preg_match('~^/admin_add\s+(\d+)$~',$text,$mm) && $id===MAIN_ADMIN_ID) { $db->prepare('INSERT OR REPLACE INTO admins(user_id,role,added_at) VALUES(?,?,?)')->execute([(int)$mm[1],'admin',time()]);send($chat,'🛡 Администратор добавлен.');exit; }
if (preg_match('~^/admin_del\s+(\d+)$~',$text,$mm) && $id===MAIN_ADMIN_ID) { $db->prepare("DELETE FROM admins WHERE user_id=? AND role='admin'")->execute([(int)$mm[1]]);send($chat,'🛡 Администратор удалён.');exit; }
if (preg_match('~^/give\s+(\d+)\s+(\d+)$~',$text,$mm) && admin($id)) {
    $target=(int)$mm[1]; $sum=(int)$mm[2];
    if (!$user($target)) { send($chat,'Пользователь не найден.'); exit; }
    changeBalance($target,$sum,'Выдано администратором',$id);
    send($chat,"✅ Выдано 💰 <b>".money($sum)."</b> пользователю <code>{$target}</code>"); exit;
}

if (preg_match('~^/take\s+(\d+)\s+(\d+)$~',$text,$mm) && admin($id)) {
    $target=(int)$mm[1]; $sum=(int)$mm[2];
    if (!changeBalance($target,-$sum,'Забрано администратором',$id)) { send($chat,'❌ Не удалось снять деньги.'); exit; }
    send($chat,"✅ Забрано 💰 <b>".money($sum)."</b> у <code>{$target}</code>"); exit;
}

if (preg_match('~^/setmoney\s+(\d+)\s+(\d+)$~',$text,$mm) && admin($id)) {
    $target=(int)$mm[1]; $sum=(int)$mm[2]; $u=user($target);
    if (!$u) { send($chat,'Пользователь не найден.'); exit; }
    changeBalance($target,$sum-(int)$u['balance'],'Баланс установлен администратором',$id);
    send($chat,"✅ Баланс <code>{$target}</code> установлен: 💰 <b>".money($sum)."</b>"); exit;
}

if (preg_match('~^/ставка\s+(\d+)$~',$text,$mm)) {
    $bet=(int)$mm[1]; $u=user($id);
    if ($bet<=0 || $bet>(int)$u['balance']) { send($chat,'❌ Некорректная ставка.'); exit; }
    $max=(int)$db->query("SELECT value FROM settings WHERE key='casino_max_bet'")->fetchColumn();
    if ($bet>$max) { send($chat,"❌ Максимальная ставка: ".money($max)); exit; }
    $win=random_int(1,100)<=45;
    $payout=$win?$bet*2:0;
    changeBalance($id,$win?$bet:-$bet,'Казино');
    if ($win) changeBalance($id,$payout,'Выигрыш казино');
    $db->prepare('INSERT INTO casino_games(user_id,game,bet,result,payout,created_at) VALUES(?,?,?,?,?,?)')
       ->execute([$id,'slots',$bet,$win?'win':'lose',$payout,time()]);
    addXp($id,10);
    send($chat,$win?"🎉 <b>Победа!</b>\nТы выиграл 💰 <b>".money($payout)."</b>!":"😔 <b>Проигрыш.</b>\nСтавка 💰 <b>".money($bet)."</b> потеряна.");
    exit;
}

switch($text) {
    case '👤 Профиль': send($chat,profile($id)); break;
    case '🎒 Инвентарь': send($chat,inventoryText($id)); break;
    case '🏪 Магазин': send($chat,shopText($id),buttons([
        ['🔪 Нож — 10.000','buy:knife'],['🔫 Пистолет — 25.000','buy:pistol'],
        ['🔫 Автомат — 75.000','buy:rifle'],['🛡 Броня — 50.000','buy:armor'],
        ['🏪 Свой магазин — 150.000','buy:shop']
    ])); break;
    case '💪 Прокачка':
        $u=user($id); $cost=1000*((int)$u['strength']+1);
        send($chat,"💪 <b>ПРОКАЧКА</b>\n\nСила: <b>{$u['strength']}/100</b>\nСледующая прокачка: 💰 <b>".money($cost)."</b>",buttons([['💪 Прокачать','upgrade']])); break;
    case '🎰 Казино': send($chat,'🎰 <b>КАЗИНО</b>',buttons([
        ['🎰 Слоты','casino:slots'],['🎲 Кубик','casino:dice'],['🃏 Высокая карта','casino:high']
    ])); break;
    case '🎁 Бонус':
        $u=user($id);
        if (time()-(int)$u['last_daily_at']<86400) { send($chat,'🎁 Бонус уже получен. Возвращайся позже.'); break; }
        $bonus=(int)$db->query("SELECT value FROM settings WHERE key='daily_bonus'")->fetchColumn();
        changeBalance($id,$bonus,'Ежедневный бонус');
        $db->prepare('UPDATE users SET last_daily_at=? WHERE id=?')->execute([time(),$id]);
        addXp($id,15);
        send($chat,"🎁 Ежедневный бонус: 💰 <b>".money($bonus)."</b>"); break;
    case '💼 Работа':
        $cool=(int)$db->query("SELECT value FROM settings WHERE key='work_cooldown'")->fetchColumn();
        $last=(int)$db->query("SELECT COALESCE(MAX(created_at),0) FROM jobs WHERE user_id={$id}")->fetchColumn();
        if (time()-$last<$cool) { send($chat,'💼 Работа пока недоступна. Попробуй позже.'); break; }
        $reward=random_int(2000,6000);
        changeBalance($id,$reward,'Работа');
        addXp($id,20);
        $db->prepare('INSERT INTO jobs(user_id,job_key,reward,xp,created_at) VALUES(?,?,?,?,?)')->execute([$id,'thief',$reward,20,time()]);
        send($chat,"💼 Работа выполнена!\n💰 +<b>".money($reward)."</b>\n⭐ +20 XP"); break;
    case '🏆 Рейтинг':
        $rows=$db->query('SELECT id,username,first_name,balance FROM users ORDER BY balance DESC LIMIT 10')->fetchAll();
        $s="🏆 <b>ТОП-10 ПО БАЛАНСУ</b>\n\n"; $i=1;
        foreach($rows as $r) { $s.="{$i}. ".htmlspecialchars($r['username']?'@'.$r['username']:$r['first_name'])." — 💰 ".money((int)$r['balance'])."\n"; $i++; }
        send($chat,$s); break;
    case '🎉 События': eventsText($chat); break;
    case '👋 Новичкам': send($chat,"👋 <b>ДЛЯ НОВИЧКОВ</b>\n\n💼 Работай и зарабатывай.\n🚨 Используй /ограбить ID.\n🏪 Купи магазин и управляй им в «Мои бизнесы».\n🎉 Следи за розыгрышами в «Событиях».\n💎 VIP открывает специальные задания."); break;
    case '🏪 Мои бизнесы': send($chat,'🏪 <b>МОИ БИЗНЕСЫ</b>\n\nКупи «Свой магазин» в магазине.'); break;
    case '🚨 Ограбить':
        send($chat,"🚨 <b>ОГРАБЛЕНИЕ ИГРОКА</b>\n\nВведи:\n<code>/ограбить TELEGRAM_ID</code>\n\nСистема учитывает силу, оружие, броню и кулдаун.");
        break;
}

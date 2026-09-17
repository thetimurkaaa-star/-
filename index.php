<?php
declare(strict_types=1);
require __DIR__ . '/config.php';


if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0775, true);

final class JsonStore {
    private string $dir;
    public function __construct(string $dir) { $this->dir = $dir; }
    private function path(string $name): string { return $this->dir . '/' . $name . '.json'; }
    public function read(string $name, array $default = []): array {
        $path = $this->path($name);
        if (!is_file($path)) { $this->write($name, $default); return $default; }
        $raw = file_get_contents($path);
        $data = json_decode($raw ?: '', true);
        return is_array($data) ? $data : $default;
    }
    public function write(string $name, array $data): void {
        $path = $this->path($name);
        $tmp = $path . '.tmp.' . getmypid();
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT), LOCK_EX);
        rename($tmp, $path);
    }
    public function update(string $name, callable $fn, array $default = []): array {
        $path = $this->path($name);
        $fp = fopen($path, 'c+');
        if (!$fp) throw new RuntimeException('Не удалось открыть JSON: '.$name);
        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $data = json_decode($raw ?: '', true);
        if (!is_array($data)) $data = $default;
        $data = $fn($data);
        ftruncate($fp, 0); rewind($fp);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); fflush($fp);
        flock($fp, LOCK_UN); fclose($fp);
        return $data;
    }
}
$store = new JsonStore(DATA_DIR);

function tg(string $method, array $data = []): array {
    if (BOT_TOKEN === '') return ['ok'=>false,'error_code'=>0,'description'=>'BOT_TOKEN is empty'];
    $ch = curl_init('https://api.telegram.org/bot'.BOT_TOKEN.'/'.$method);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$data,CURLOPT_TIMEOUT=>30]);
    $raw = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($raw === false) return ['ok'=>false,'error_code'=>0,'description'=>$err];
    return json_decode($raw, true) ?: ['ok'=>false,'error_code'=>0,'description'=>'Invalid Telegram response'];
}
function send(int $chat, string $text, ?array $keyboard=null): array {
    $data=['chat_id'=>$chat,'text'=>$text,'parse_mode'=>'HTML'];
    if ($keyboard) $data['reply_markup']=json_encode($keyboard, JSON_UNESCAPED_UNICODE);
    return tg('sendMessage',$data);
}
function answer(array $cb,string $text=''): void { tg('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$text]); }
function inline(array $rows): array { return ['inline_keyboard'=>$rows]; }
function buttons(array $items): array { $rows=[]; foreach(array_chunk($items,2) as $chunk){$row=[];foreach($chunk as $x)$row[]=['text'=>$x[0],'callback_data'=>$x[1]];$rows[]=$row;} return inline($rows); }
function esc(string $s): string { return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function money(int $n): string { return number_format($n,0,'.','.'); }
function xpNeed(int $level): int { return 1000+(($level-1)*250); }

function getUsers(): array { global $store; return $store->read('users',[]); }
function saveUsers(array $v): void { global $store; $store->write('users',$v); }
function getUser(int $id): array { foreach(getUsers() as $u) if((int)$u['id']===$id)return $u; return []; }
function ensureUser(array $from): bool {
    global $store;
    $id=(int)$from['id']; $new=false; $now=time();
    $store->update('users',function($users)use($from,$id,$now,&$new){
        foreach($users as &$u) if((int)$u['id']===$id){$u['username']=$from['username']??null;$u['first_name']=$from['first_name']??'';$u['last_seen_at']=$now;return $users;}
        $new=true;$users[]=['id'=>$id,'username'=>$from['username']??null,'first_name'=>$from['first_name']??'','balance'=>START_BALANCE,'respect'=>0,'vip_until'=>0,'vip_started_at'=>0,'strength'=>START_STRENGTH,'level'=>START_LEVEL,'xp'=>START_XP,'registered_at'=>$now,'last_seen_at'=>$now,'last_daily_at'=>0];return $users;
    },[]);
    return $new;
}
function changeUser(int $id, callable $fn): bool { global $store; $ok=false;$store->update('users',function($users)use($id,$fn,&$ok){foreach($users as &$u)if((int)$u['id']===$id){$u=$fn($u);$ok=true;break;}return $users;},[]);return $ok; }
function changeBalance(int $id,int $delta,string $reason,?int $actor=null): bool {
    global $store; $ok=false;
    $store->update('users',function($users)use($id,$delta,&$ok){foreach($users as &$u)if((int)$u['id']===$id){$new=(int)$u['balance']+$delta;if($new<0)return $users;$u['balance']=$new;$ok=true;break;}return $users;},[]);
    if($ok)$store->update('transactions',function($rows)use($id,$delta,$reason,$actor){$u=getUser($id);$rows[]=['id'=>count($rows)+1,'user_id'=>$id,'amount'=>$delta,'balance_after'=>(int)$u['balance'],'reason'=>$reason,'actor_id'=>$actor,'created_at'=>time()];return $rows;},[]);
    return $ok;
}
function changeRespect(int $id,int $delta,string $reason='',?int $actor=null): bool { return changeUser($id,function($u)use($delta){$n=(int)$u['respect']+$delta;if($n<0)return $u;$u['respect']=$n;return $u;}); }
function addXp(int $id,int $amount): void { changeUser($id,function($u)use($amount){$xp=(int)$u['xp']+$amount;$lv=(int)$u['level'];while($xp>=xpNeed($lv)){$xp-=xpNeed($lv);$lv++;}$u['xp']=$xp;$u['level']=$lv;return $u;}); }
function admin(int $id): bool { global $store;if($id===MAIN_ADMIN_ID)return true;foreach($store->read('admins',[]) as $a)if((int)$a['user_id']===$id && in_array($a['role'],['admin','main'],true))return true;return false; }
function isVip(int $id): bool { $u=getUser($id);return $u && (int)$u['vip_until']>time(); }

function getChats(): array { global $store; return $store->read('chats',[]); }
function registerChat(array $chat): void { global $store;$type=$chat['type']??'';if(!in_array($type,['private','group','supergroup'],true))return;$id=(int)$chat['id'];$store->update('chats',function($rows)use($chat,$type,$id){foreach($rows as &$r)if((int)$r['chat_id']===$id){$r['chat_type']=$type;$r['title']=$chat['title']??null;$r['username']=$chat['username']??null;$r['last_seen_at']=time();return $rows;}$rows[]=['chat_id'=>$id,'chat_type'=>$type,'title'=>$chat['title']??null,'username'=>$chat['username']??null,'active'=>1,'last_seen_at'=>time()];return $rows;},[]); }
function trackChatActivity(int $userId,int $chatId,string $type): void { if(!in_array($type,['group','supergroup'],true))return;global $store;$day=date('Y-m-d');$key=$userId.'_'.$chatId.'_'.$day;$store->update('activity',function($rows)use($key,$userId,$chatId,$day){$rows[$key]=['user_id'=>$userId,'chat_id'=>$chatId,'day'=>$day,'messages'=>(int)($rows[$key]['messages']??0)+1];return $rows;},[]); }
function setChatActive(int $chatId,bool $active): void { global $store;$store->update('chats',function($rows)use($chatId,$active){foreach($rows as &$r)if((int)$r['chat_id']===$chatId)$r['active']=$active?1:0;return $rows;},[]); }

function getJobs(): array { global $store;return $store->read('jobs',[]); }
function addJob(int $id,int $reward): void { global $store;$store->update('jobs',function($r)use($id,$reward){$r[]=['id'=>count($r)+1,'user_id'=>$id,'reward'=>$reward,'xp'=>20,'created_at'=>time()];return $r;},[]); }
function getRobberies(): array { global $store;return $store->read('robberies',[]); }
function getInventory(): array { global $store;return $store->read('inventory',[]); }
function hasItem(int $uid,string $key): int { foreach(getInventory() as $r)if((int)$r['user_id']===$uid&&$r['item_key']===$key)return (int)$r['quantity'];return 0; }
function addItem(int $uid,string $key,int $qty=1): void { global $store;$store->update('inventory',function($rows)use($uid,$key,$qty){foreach($rows as &$r)if((int)$r['user_id']===$uid&&$r['item_key']===$key){$r['quantity']+=(int)$qty;return $rows;}$rows[]=['user_id'=>$uid,'item_key'=>$key,'quantity'=>$qty];return $rows;},[]); }
function getShops(): array { global $store;return $store->read('shops',[]); }
function shop(int $uid): array { foreach(getShops() as $s)if((int)$s['user_id']===$uid)return $s;return []; }
function saveShop(int $uid,array $shop): void { global $store;$store->update('shops',function($rows)use($uid,$shop){foreach($rows as &$r)if((int)$r['user_id']===$uid){$r=$shop;return $rows;}$rows[]=$shop;return $rows;},[]); }

function vipProgress(int $id): array { $u=getUser($id);$start=(int)$u['vip_started_at'];$jobs=0;$rob=0;foreach(getJobs() as $r)if((int)$r['user_id']===$id&&(int)$r['created_at']>=$start)$jobs++;foreach(getRobberies() as $r)if((int)$r['attacker_id']===$id&&(int)$r['created_at']>=$start)$rob++;$messages=0;$startDay=date('Y-m-d',$start?:time());foreach($GLOBALS['store']->read('activity',[]) as $r)if((int)$r['user_id']===$id&&$r['day']>=$startDay)$messages+=(int)$r['messages'];return ['jobs'=>$jobs,'robberies'=>$rob,'messages'=>$messages]; }
function vipClaims(int $id): array { global $store;$c=[];foreach($store->read('vip_claims',[]) as $r)if((int)$r['user_id']===$id)$c[$r['task_key']]=1;return $c; }
function vipText(int $id): string { if(!isVip($id))return "💎 <b>VIP</b>\n\nVIP-задания доступны только VIP-игрокам.\n\n🎯 5 работ → <b>4 500 бабок</b>\n🔪 3 ограбления → <b>10 000 бабок</b>\n💬 30 сообщений → <b>15 000 бабок + 10 Респектов</b>";$p=vipProgress($id);$c=vipClaims($id);return "💎 <b>VIP-ЗАДАНИЯ</b>\n\n🎯 5 работ: <b>{$p['jobs']}/5</b> ".(isset($c['jobs'])?'✅':'')."\n🔪 3 ограбления: <b>{$p['robberies']}/3</b> ".(isset($c['robberies'])?'✅':'')."\n💬 30 сообщений: <b>{$p['messages']}/30</b> ".(isset($c['messages'])?'✅':''); }

function getEvents(): array { global $store;return $store->read('events',[]); }
function eventParticipants(): array { global $store;return $store->read('event_participants',[]); }
function resolveEvent(int $eid): void { global $store;$events=getEvents();$event=[];foreach($events as $e)if((int)$e['id']===$eid)$event=$e;if(!$event||$event['status']!=='active'||(int)$event['end_at']>time())return;$parts=[];foreach(eventParticipants() as $p)if((int)$p['event_id']===$eid)$parts[]=(int)$p['user_id'];shuffle($parts);$winners=array_slice(array_values(array_unique($parts)),0,max(1,(int)$event['winners_count']));foreach($winners as $uid){if((int)$event['reward_money']>0)changeBalance($uid,(int)$event['reward_money'],'Победа в событии #'.$eid);if((int)$event['reward_respect']>0)changeRespect($uid,(int)$event['reward_respect'],'Победа в событии #'.$eid);send($uid,"🎉 <b>ТЫ ПОБЕДИЛ!</b>\n\n🎉 ".esc($event['title'])."\n💰 +".money((int)$event['reward_money'])."\n💎 +".money((int)$event['reward_respect'])." Респектов");}$store->update('events',function($rows)use($eid,$winners){foreach($rows as &$e)if((int)$e['id']===$eid){$e['status']='finished';$e['winner_ids']=$winners;$e['finished_at']=time();}return $rows;},[]);}
function resolveExpiredEvents(): void {foreach(getEvents() as $e)if($e['status']==='active'&&(int)$e['end_at']<=time())resolveEvent((int)$e['id']);}
function eventsText(int $chatId): void {
    resolveExpiredEvents();
    $rows = array_values(array_filter(getEvents(), fn($e) => $e['status'] === 'active'));
    usort($rows, fn($a,$b) => (int)$a['end_at'] <=> (int)$b['end_at']);
    if (!$rows) { send($chatId, '🎉 <b>СОБЫТИЯ</b>\n\nСейчас активных розыгрышей нет.'); return; }
    foreach ($rows as $e) {
        $joined = false; $cnt = 0;
        foreach (eventParticipants() as $p) {
            if ((int)$p['event_id'] === (int)$e['id']) {
                $cnt++;
                if ((int)$p['user_id'] === $chatId) $joined = true;
            }
        }
        $reward = '';
        if ((int)$e['reward_money'] > 0) $reward .= '💰 '.money((int)$e['reward_money']);
        if ((int)$e['reward_respect'] > 0) $reward .= ($reward ? ' + ' : '').'💎 '.money((int)$e['reward_respect']).' Респектов';
        $button = $joined ? '✅ Ты участвуешь' : '🎟 Участвовать';
        $text = '🎉 <b>'.esc($e['title'])."</b>\n\n".
            esc($e['description'])."\n\n🎁 Награда: <b>".($reward ?: '—')."</b>\n🏆 Победителей: <b>{$e['winners_count']}</b>\n👥 Участников: <b>{$cnt}</b>\n⏰ До: <b>".
            date('d.m.Y H:i', (int)$e['end_at']).'</b>';
        $kb = buttons([[$button, 'event:join:'.((int)$e['id'])]]);
        send($chatId, $text, $kb);
    }
}
function profile(int $id): string { $u=getUser($id);$vip=isVip($id)?'💎 VIP до: <b>'.date('d.m.Y H:i',(int)$u['vip_until']).'</b>':'💎 VIP: <b>нет</b>';return "👤 <b>ПРОФИЛЬ</b>\n\n💰 Бабки: <b>".money((int)$u['balance'])."</b>\n💎 Респекты: <b>".money((int)$u['respect'])."</b>\n{$vip}\n💪 Сила: <b>{$u['strength']}/100</b>\n⭐ Уровень: <b>{$u['level']}</b>\nXP: <b>{$u['xp']}/".xpNeed((int)$u['level'])."</b>\n\n🆔 ID: <code>{$id}</code>"; }
function inventoryText(int $id): string { $names=['knife'=>'🔪 Нож','pistol'=>'🔫 Пистолет','rifle'=>'🔫 Автомат','armor'=>'🛡 Броня','shop'=>'🏪 Свой магазин'];$s='🎒 <b>ИНВЕНТАРЬ</b>\n\n';$rows=array_filter(getInventory(),fn($r)=>(int)$r['user_id']===$id&&(int)$r['quantity']>0);if(!$rows)return $s.'Пока пусто.';foreach($rows as $r)$s.=($names[$r['item_key']]??$r['item_key']).': <b>'.$r['quantity'].'</b>\n';return $s;}
function mainMenu(): array { return ['keyboard'=>[[['text'=>'👤 Профиль'],['text'=>'💼 Работа']],[['text'=>'🎁 Бонус'],['text'=>'🚨 Ограбить']],[['text'=>'🏪 Мои бизнесы'],['text'=>'🎰 Казино']],[['text'=>'💪 Прокачка'],['text'=>'🎒 Инвентарь']],[['text'=>'🏆 Рейтинг'],['text'=>'🎉 События']]],'resize_keyboard'=>true]; }
function adminMenu(): array { return buttons([['👥 Пользователи','adm:users'],['💰 Управление деньгами','adm:money'],['📊 Статистика','adm:stats'],['📢 Рассылка','adm:broadcast'],['🎉 События','adm:events'],['💎 VIP','adm:vip'],['💬 Чаты бота','adm:chats'],['👑 Администраторы','adm:admins']]); }
function adminChatsText(): string { $rows=array_filter(getChats(),fn($r)=>in_array($r['chat_type'],['group','supergroup'],true));usort($rows,fn($a,$b)=>(int)$b['active']<=>(int)$a['active']);if(!$rows)return '💬 <b>ЧАТЫ БОТА</b>\n\nПока нет зарегистрированных групп.\nДобавь бота в группу и отправь там сообщение.';$s='💬 <b>ЧАТЫ БОТА</b>\n\n';foreach($rows as $r){$title=esc((string)($r['title']?:($r['username']?'@'.$r['username']:'Без названия')));$s.=((int)$r['active']?'🟢':'🔴')." <b>{$title}</b>\n🆔 <code>{$r['chat_id']}</code>\n📁 ".esc($r['chat_type'])."\n🕐 ".date('d.m.Y H:i',(int)$r['last_seen_at'])."\n\n";}return $s;}
function adminChatsKeyboard(): ?array { $rows=array_filter(getChats(),fn($r)=>in_array($r['chat_type'],['group','supergroup'],true));$items=[];foreach($rows as $r){$title=mb_substr((string)($r['title']?:($r['username']?'@'.$r['username']:$r['chat_id'])),0,24);$items[]=[((int)$r['active']?'🚫 ':'🟢 ').$title,((int)$r['active']?'adm:chatoff:':'adm:chaton:').(int)$r['chat_id']];}if(!$items)return null;$result=[];foreach(array_chunk($items,2)as$c)$result[]=$c;$result[]=[['🔄 Обновить','adm:chats']];return inline($result);}

function newPlayerText(): string { return "👋 <b>ДОБРО ПОЖАЛОВАТЬ В «РАБОТУ БАНДИТА»!</b>\n\n💰 Здесь ты зарабатываешь бабки, выполняешь работы, участвуешь в ограблениях, покупаешь оружие и развиваешь свой бизнес.\n\n🔥 Выполняй задания, следи за событиями и не забывай про бонус каждый день!\n\n💎 У VIP-игроков есть специальные задания с дополнительными наградами."; }

if (isset($_GET['cron']) && CRON_SECRET!=='' && hash_equals(CRON_SECRET,(string)$_GET['cron'])) { resolveExpiredEvents(); exit('OK'); }
if (WEBHOOK_SECRET!=='' && (($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??'')!==WEBHOOK_SECRET)){http_response_code(403);exit;}
$update=json_decode(file_get_contents('php://input'),true);if(!$update)exit;

if(isset($update['callback_query'])){
    $cb=$update['callback_query'];$from=$cb['from'];$chat=(int)$cb['message']['chat']['id'];$id=(int)$from['id'];ensureUser($from);registerChat($cb['message']['chat']);$a=$cb['data'];
    if($a==='profile'){answer($cb);send($chat,profile($id),buttons([['💎 VIP-задания','vip']]));exit;}
    if($a==='upgrade'){ $u=getUser($id);$cost=1000*((int)$u['strength']+1);if((int)$u['strength']>=MAX_STRENGTH){answer($cb,'Максимальная сила');exit;}if(!changeBalance($id,-$cost,'Прокачка силы')){answer($cb,'Недостаточно денег');exit;}changeUser($id,function($u){$u['strength']++;return $u;});addXp($id,10);answer($cb,'Сила увеличена!');send($chat,'💪 Сила увеличена!\n\n'.profile($id));exit; }
    if(str_starts_with($a,'buy:')){ $key=substr($a,4);$prices=['knife'=>10000,'pistol'=>25000,'rifle'=>75000,'armor'=>50000,'shop'=>150000];if(!isset($prices[$key]))exit;if(!changeBalance($id,-$prices[$key],'Покупка '.$key)){answer($cb,'Недостаточно денег');exit;}addItem($id,$key);if($key==='shop'&&!shop($id))saveShop($id,['user_id'=>$id,'level'=>1,'stored_income'=>0,'last_income_at'=>time(),'last_robbed_at'=>0]);answer($cb,'Покупка совершена');send($chat,"✅ Куплено!\n\n".inventoryText($id));exit; }
    if($a==='shop'){ $s=shop($id);if(!$s){answer($cb,'Сначала купи свой магазин');exit;}$income=500+((int)$s['level']-1)*300;$elapsed=max(0,time()-(int)$s['last_income_at']);$generated=(int)floor($elapsed/3600)*$income;if($generated>0){$s['stored_income']+=(int)$generated;$s['last_income_at']=time();saveShop($id,$s);}answer($cb);send($chat,"🏪 <b>ТВОЙ МАГАЗИН</b>\n\n📊 Уровень: <b>{$s['level']}</b>\n💰 Доход: <b>".money($income)."/час</b>\n💵 Накоплено: <b>".money((int)$s['stored_income']).'</b>',buttons([['💰 Забрать доход','shop:collect'],['⬆️ Улучшить магазин','shop:upgrade'],['🚨 Проверить магазин','shop:rob']]));exit; }
    if(str_starts_with($a,'shop:')){ $action=substr($a,5);$s=shop($id);if(!$s){answer($cb,'Магазина нет');exit;}if($action==='collect'){$amount=(int)$s['stored_income'];$s['stored_income']=0;$s['last_income_at']=time();saveShop($id,$s);if($amount)changeBalance($id,$amount,'Доход магазина');answer($cb,'Доход забран');send($chat,'💰 Получено: <b>'.money($amount).'</b>');}elseif($action==='upgrade'){$cost=150000*(int)$s['level'];if(!changeBalance($id,-$cost,'Улучшение магазина')){answer($cb,'Недостаточно денег');exit;}$s['level']++;saveShop($id,$s);addXp($id,30);answer($cb,'Магазин улучшен');send($chat,'⬆️ Магазин улучшен!');}else{$now=time();if($now-(int)$s['last_robbed_at']<259200){answer($cb,'Проверить магазин можно раз в 3 дня');exit;}$s['last_robbed_at']=$now;saveShop($id,$s);$success=random_int(1,100)<=55;$amount=min((int)$s['stored_income'],random_int(1000,20000));if($success&&$amount>0){$s['stored_income']-=$amount;saveShop($id,$s);changeBalance($id,$amount,'Ограбление собственного магазина');send($chat,'🚨 Проверка завершена!\n\nУспех. 💰 +'.money($amount));}else send($chat,'🚨 Проверка завершена!\n\n❌ Неудача.');addXp($id,30);}exit; }
    if($a==='vip'){answer($cb);$kb=[];if(isVip($id)){$p=vipProgress($id);$c=vipClaims($id);if($p['jobs']>=5&&!isset($c['jobs']))$kb[]=['🎁 4 500','vip:claim:jobs'];if($p['robberies']>=3&&!isset($c['robberies']))$kb[]=['🎁 10 000','vip:claim:robberies'];if($p['messages']>=30&&!isset($c['messages']))$kb[]=['🎁 15 000 + 10 💎','vip:claim:messages'];}send($chat,vipText($id),$kb?inline($kb):null);exit;}
    if(str_starts_with($a,'vip:claim:')){$task=substr($a,10);if(!isVip($id)){answer($cb,'VIP недоступен');exit;}$p=vipProgress($id);$need=['jobs'=>5,'robberies'=>3,'messages'=>30];if(!isset($need[$task])||$p[$task]<$need[$task]){answer($cb,'Задание не выполнено');exit;}$claims=vipClaims($id);if(isset($claims[$task])){answer($cb,'Уже получено');exit;}$rm=['jobs'=>4500,'robberies'=>10000,'messages'=>15000][$task];$rr=$task==='messages'?10:0;changeBalance($id,$rm,'VIP-задание '.$task);if($rr)changeRespect($id,$rr,'VIP-задание '.$task);global $store;$store->update('vip_claims',function($r)use($id,$task){$r[]=['user_id'=>$id,'task_key'=>$task,'claimed_at'=>time()];return $r;},[]);answer($cb,'Награда получена');send($chat,'🎉 <b>VIP-награда!</b>\n💰 +'.money($rm).($rr?'\n💎 +'.$rr.' Респектов':''));exit;}
    if($a==='events'){answer($cb);eventsText($chat);exit;}
    if(str_starts_with($a,'event:join:')){$eid=(int)substr($a,11);$event=[];foreach(getEvents() as $e)if((int)$e['id']===$eid)$event=$e;if(!$event||$event['status']!=='active'){answer($cb,'Событие завершено');exit;}if((int)$event['end_at']<=time()){resolveEvent($eid);answer($cb,'Событие завершено');exit;}global $store;$store->update('event_participants',function($rows)use($eid,$id){foreach($rows as $r)if((int)$r['event_id']===$eid&&(int)$r['user_id']===$id)return $rows;$rows[]=['event_id'=>$eid,'user_id'=>$id,'joined_at'=>time()];return $rows;},[]);answer($cb,'Участие сохранено');eventsText($chat);exit;}
    if($a==='casino'){answer($cb);send($chat,'🎰 <b>КАЗИНО</b>\n\nВыбери игру:',buttons([['🎰 Слоты','casino:slots'],['🎲 Кубик','casino:dice'],['🃏 Высокая карта','casino:high']]));exit;}
    if(str_starts_with($a,'casino:')){send($chat,'🎰 Игра выбрана.\n\nИспользуй:\n<code>/ставка 10000</code>');exit;}
    if($a==='inventory'){answer($cb);send($chat,inventoryText($id));exit;}
    if($a==='admin'){if(!admin($id)){answer($cb,'Нет доступа');exit;}answer($cb);send($chat,'👑 <b>АДМИН-ПАНЕЛЬ</b>',adminMenu());exit;}
    if(str_starts_with($a,'adm:')&&admin($id)){ $act=substr($a,4);
        if($act==='chats'){answer($cb);send($chat,adminChatsText(),adminChatsKeyboard());exit;}
        if(str_starts_with($act,'chatoff:')){setChatActive((int)substr($act,8),false);answer($cb,'Чат исключён из рассылки');send($chat,adminChatsText(),adminChatsKeyboard());exit;}
        if(str_starts_with($act,'chaton:')){setChatActive((int)substr($act,7),true);answer($cb,'Чат включён');send($chat,adminChatsText(),adminChatsKeyboard());exit;}
        if($act==='stats'){$users=getUsers();$money=array_sum(array_map(fn($u)=>(int)$u['balance'],$users));$active=count(array_filter($users,fn($u)=>(int)$u['last_seen_at']>=strtotime('today')));$new=count(array_filter($users,fn($u)=>(int)$u['registered_at']>=strtotime('today')));send($chat,"📊 <b>СТАТИСТИКА</b>\n\n👥 Всего: <b>".count($users)."</b>\n🟢 Активных сегодня: <b>{$active}</b>\n🆕 Новых сегодня: <b>{$new}</b>\n💰 Всего бабок: <b>".money($money)."</b>\n🏪 Магазинов: <b>".count(getShops())."</b>\n🔫 Предметов: <b>".array_sum(array_map(fn($r)=>(int)$r['quantity'],getInventory()))."</b>\n🚨 Ограблений: <b>".count(getRobberies())."</b>");exit;}
        if($act==='users'){ $rows=getUsers();usort($rows,fn($a,$b)=>(int)$b['balance']<=>(int)$a['balance']);$s='👥 <b>ПОЛЬЗОВАТЕЛИ — ТОП 20</b>\n\n';foreach(array_slice($rows,0,20)as$r)$s.='🆔 <code>'.$r['id'].'</code> '.esc($r['username']?'@'.$r['username']:$r['first_name']).' — 💰 '.money((int)$r['balance']).' | 💪 '.$r['strength'].' | ⭐ '.$r['level'].'\n';send($chat,$s);exit;}
        if($act==='money'){send($chat,'💰 <b>УПРАВЛЕНИЕ ДЕНЬГАМИ</b>\n\n/give ID SUM\n/take ID SUM\n/setmoney ID SUM');exit;}
        if($act==='broadcast'){send($chat,'📢 <b>РАССЫЛКА</b>\n\n/broadcast_users текст — ЛС\n/broadcast_chats текст — группы\n/broadcast_all текст — ЛС + группы');exit;}
        if($act==='events'){send($chat,'🎉 <b>УПРАВЛЕНИЕ СОБЫТИЯМИ</b>\n\n/event_create Название | Описание | YYYY-MM-DD HH:MM | деньги | респекты | победители\n/event_list\n/event_end ID');exit;}
        if($act==='vip'){send($chat,'💎 <b>VIP</b>\n\n/vip ID ДНИ\n/unvip ID\n/respect ID +/-SUM');exit;}
        if($act==='admins'){send($chat,'👑 <b>АДМИНИСТРАТОРЫ</b>\n\n/admin_add ID\n/admin_del ID');exit;}
    }
    exit;
}

if(!isset($update['message']))exit;$m=$update['message'];$from=$m['from'];$chat=(int)$m['chat']['id'];$id=(int)$from['id'];$isNew=ensureUser($from);registerChat($m['chat']);$text=trim((string)($m['text']??''));trackChatActivity($id,$chat,$m['chat']['type']??'');

if($text==='/start'){send($chat,$isNew?newPlayerText()."\n\n".profile($id):profile($id),mainMenu());exit;}
if($text==='/safonof'){if(!admin($id)){send($chat,'⛔ Доступ запрещён.');exit;}send($chat,'👑 <b>АДМИН-ПАНЕЛЬ</b>',adminMenu());exit;}

if(preg_match('~^/broadcast_(users|chats|all)\s+(.+)~us',$text,$mm)&&admin($id)){ $type=$mm[1];$targets=[];if($type!=='chats')foreach(getUsers()as$u)$targets[(string)$u['id']]='user';if($type!=='users')foreach(getChats()as$c)if(in_array($c['chat_type'],['group','supergroup'],true)&&(int)$c['active']===1)$targets[(string)$c['chat_id']]='chat';$sent=0;$failed=0;foreach($targets as$target=>$targetType){$res=send((int)$target,$mm[2]);if(!empty($res['ok']))$sent++;else{$failed++;if($targetType==='chat'&&in_array((int)($res['error_code']??0),[400,403],true))setChatActive((int)$target,false);}usleep(35000);}global $store;$store->update('broadcast_log',function($r)use($id,$type,$mm,$sent,$failed){$r[]=['id'=>count($r)+1,'admin_id'=>$id,'target_type'=>$type,'message'=>$mm[2],'sent_count'=>$sent,'failed_count'=>$failed,'created_at'=>time()];return $r;},[]);send($chat,"📢 <b>РАССЫЛКА ЗАВЕРШЕНА</b>\n\n🎯 {$type}\n✅ Отправлено: <b>{$sent}</b>\n❌ Ошибок: <b>{$failed}</b>");exit;}
if(preg_match('~^/vip\s+(\d+)\s+(\d+)$~',$text,$mm)&&admin($id)){ $target=(int)$mm[1];$days=max(1,(int)$mm[2]);if(!getUser($target)){send($chat,'Пользователь не найден.');exit;}$until=time()+$days*86400;changeUser($target,function($u)use($until){$u['vip_until']=$until;$u['vip_started_at']=time();return $u;});global $store;$store->update('vip_claims',fn($r)=>array_values(array_filter($r,fn($x)=>(int)$x['user_id']!==$target)),[]);send($chat,'💎 VIP выдан.');send($target,'💎 <b>VIP активирован</b> до '.date('d.m.Y H:i',$until));exit;}
if(preg_match('~^/unvip\s+(\d+)$~',$text,$mm)&&admin($id)){changeUser((int)$mm[1],function($u){$u['vip_until']=0;$u['vip_started_at']=0;return $u;});send($chat,'💎 VIP снят.');exit;}
if(preg_match('~^/respect\s+(\d+)\s+(-?\d+)$~',$text,$mm)&&admin($id)){send($chat,changeRespect((int)$mm[1],(int)$mm[2],'Администратор',$id)?'💎 Респекты изменены.':'❌ Не удалось изменить.');exit;}
if(preg_match('~^/event_create\s+(.+)$~us',$text,$mm)&&admin($id)){ $p=array_map('trim',explode('|',$mm[1]));if(count($p)!==6){send($chat,'Формат: /event_create Название | Описание | YYYY-MM-DD HH:MM | деньги | респекты | победители');exit;}$end=strtotime($p[2]);if(!$end||$end<=time()){send($chat,'❌ Дата должна быть в будущем.');exit;}global $store;$newId=count(getEvents())+1;$store->update('events',function($r)use($p,$end,$id,$newId){$r[]=['id'=>$newId,'title'=>$p[0],'description'=>$p[1],'end_at'=>$end,'reward_money'=>max(0,(int)$p[3]),'reward_respect'=>max(0,(int)$p[4]),'winners_count'=>max(1,min(50,(int)$p[5])),'created_by'=>$id,'created_at'=>time(),'status'=>'active','winner_ids'=>[]];return $r;},[]);send($chat,'🎉 Событие создано: #'.$newId);exit;}
if($text==='/event_list'&&admin($id)){ $s='🎉 <b>СОБЫТИЯ</b>\n\n';foreach(array_reverse(getEvents())as$e)$s.='#'.$e['id'].' '.esc($e['title']).' — '.$e['status'].' — '.date('d.m.Y H:i',(int)$e['end_at']).'\n';send($chat,$s);exit;}
if(preg_match('~^/event_end\s+(\d+)$~',$text,$mm)&&admin($id)){global $store;$store->update('events',function($r)use($mm){foreach($r as &$e)if((int)$e['id']===(int)$mm[1]&&$e['status']==='active')$e['end_at']=time();return $r;},[]);resolveEvent((int)$mm[1]);send($chat,'🎉 Событие завершено, победители выбраны.');exit;}
if(preg_match('~^/admin_add\s+(\d+)$~',$text,$mm)&&$id===MAIN_ADMIN_ID){global $store;$store->update('admins',function($r)use($mm){foreach($r as $a)if((int)$a['user_id']===(int)$mm[1])return $r;$r[]=['user_id'=>(int)$mm[1],'role'=>'admin','added_at'=>time()];return $r;},[]);send($chat,'🛡 Администратор добавлен.');exit;}
if(preg_match('~^/admin_del\s+(\d+)$~',$text,$mm)&&$id===MAIN_ADMIN_ID){global $store;$store->update('admins',fn($r)=>array_values(array_filter($r,fn($a)=>(int)$a['user_id']!==(int)$mm[1])),[]);send($chat,'🛡 Администратор удалён.');exit;}
if(preg_match('~^/give\s+(\d+)\s+(\d+)$~',$text,$mm)&&admin($id)){if(!getUser((int)$mm[1])){send($chat,'Пользователь не найден.');exit;}changeBalance((int)$mm[1],(int)$mm[2],'Выдано администратором',$id);send($chat,'✅ Выдано 💰 <b>'.money((int)$mm[2]).'</b> пользователю <code>'.$mm[1].'</code>');exit;}
if(preg_match('~^/take\s+(\d+)\s+(\d+)$~',$text,$mm)&&admin($id)){if(!changeBalance((int)$mm[1],-(int)$mm[2],'Забрано администратором',$id)){send($chat,'❌ Не удалось снять деньги.');exit;}send($chat,'✅ Забрано 💰 <b>'.money((int)$mm[2]).'</b> у <code>'.$mm[1].'</code>');exit;}
if(preg_match('~^/setmoney\s+(\d+)\s+(\d+)$~',$text,$mm)&&admin($id)){ $u=getUser((int)$mm[1]);if(!$u){send($chat,'Пользователь не найден.');exit;}changeBalance((int)$mm[1],(int)$mm[2]-(int)$u['balance'],'Баланс установлен администратором',$id);send($chat,'✅ Баланс установлен: 💰 <b>'.money((int)$mm[2]).'</b>');exit;}
if(preg_match('~^/ставка\s+(\d+)$~u',$text,$mm)){ $bet=(int)$mm[1];$u=getUser($id);if($bet<=0||$bet>(int)$u['balance']||$bet>CASINO_MAX_BET){send($chat,'❌ Некорректная ставка. Максимум: '.money(CASINO_MAX_BET));exit;}$win=random_int(1,100)<=45;$payout=$win?$bet*2:0;changeBalance($id,$win?$bet:-$bet,'Казино');if($win)changeBalance($id,$payout,'Выигрыш казино');global $store;$store->update('casino_games',function($r)use($id,$bet,$win,$payout){$r[]=['id'=>count($r)+1,'user_id'=>$id,'game'=>'slots','bet'=>$bet,'result'=>$win?'win':'lose','payout'=>$payout,'created_at'=>time()];return $r;},[]);addXp($id,10);send($chat,$win?'🎉 <b>Победа!</b>\nТы выиграл 💰 <b>'.money($payout).'</b>!':'😔 <b>Проигрыш.</b>\nСтавка 💰 <b>'.money($bet).'</b> потеряна.');exit;}

switch($text){
case '👤 Профиль':send($chat,profile($id));break;
case '🎒 Инвентарь':send($chat,inventoryText($id));break;
case '🏪 Мои бизнесы':$s=shop($id);send($chat,$s?'🏪 <b>МОИ БИЗНЕСЫ</b>\n\nТвой магазин доступен ниже.':'🏪 <b>МОИ БИЗНЕСЫ</b>\n\nУ тебя пока нет бизнеса.\nКупи «Свой магазин», чтобы открыть бизнес.',buttons($s?[['🏪 Свой магазин','shop']]:[['🏪 Купить свой магазин','buy:shop']]));break;
case '🏪 Магазин':send($chat,'🏪 <b>МАГАЗИН</b>\n\nВыбери товар:',buttons([['🔪 Нож — 10.000','buy:knife'],['🔫 Пистолет — 25.000','buy:pistol'],['🔫 Автомат — 75.000','buy:rifle'],['🛡 Броня — 50.000','buy:armor'],['🏪 Свой магазин — 150.000','buy:shop']]));break;
case '💪 Прокачка':$u=getUser($id);$cost=1000*((int)$u['strength']+1);send($chat,"💪 <b>ПРОКАЧКА</b>\n\nСила: <b>{$u['strength']}/100</b>\nСледующая прокачка: 💰 <b>".money($cost).'</b>',buttons([['💪 Прокачать','upgrade']]));break;
case '🎰 Казино':send($chat,'🎰 <b>КАЗИНО</b>',buttons([['🎰 Слоты','casino:slots'],['🎲 Кубик','casino:dice'],['🃏 Высокая карта','casino:high']]));break;
case '🎁 Бонус':$u=getUser($id);if(time()-(int)$u['last_daily_at']<86400){send($chat,'🎁 Бонус уже получен. Возвращайся позже.');break;}changeBalance($id,DAILY_BONUS,'Ежедневный бонус');changeUser($id,function($u){$u['last_daily_at']=time();return $u;});addXp($id,15);send($chat,'🎁 Ежедневный бонус: 💰 <b>'.money(DAILY_BONUS).'</b>');break;
case '💼 Работа':$jobs=getJobs();$last=0;foreach($jobs as$r)if((int)$r['user_id']===$id)$last=max($last,(int)$r['created_at']);if(time()-$last<WORK_COOLDOWN){send($chat,'💼 Работа пока недоступна. Попробуй позже.');break;}$reward=random_int(2000,6000);changeBalance($id,$reward,'Работа');addXp($id,20);addJob($id,$reward);send($chat,'💼 Работа выполнена!\n💰 +<b>'.money($reward).'</b>\n⭐ +20 XP');break;
case '🏆 Рейтинг':$rows=getUsers();usort($rows,fn($a,$b)=>(int)$b['balance']<=>(int)$a['balance']);$s='🏆 <b>ТОП-10 ПО БАЛАНСУ</b>\n\n';$i=1;foreach(array_slice($rows,0,10) as $r){ $s.=$i.'. '.esc($r['username']?'@'.$r['username']:$r['first_name']).' — 💰 '.money((int)$r['balance'])."\n"; $i++; }send($chat,$s);break;
case '🎉 События':eventsText($chat);break;
case '🚨 Ограбить':send($chat,"🚨 <b>ОГРАБЛЕНИЕ ИГРОКА</b>\n\nВведи:\n<code>/ограбить TELEGRAM_ID</code>");break;
}

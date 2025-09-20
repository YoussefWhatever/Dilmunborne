<?php
namespace Game;

use Game\Util;

class Game {
    private DB $db;
    public array $state;

    public function __construct(DB $db) {
        $this->db = $db;
        $this->db->ensureMetaTables();
        if (!isset($_SESSION['game'])) {
            $this->newGame();
        } else {
            $this->state = $_SESSION['game'];
        }
    }

    private function randName(): string {
        // Pulls a random user from USERS to name your chef
        try {
            $row = $this->db->pdo->query("SELECT first_name, last_name FROM USERS ORDER BY RANDOM() LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
            if ($row && $row['first_name']) {
                return $row['first_name'] . ' ' . $row['last_name'];
            }
        } catch (\Throwable $e) {}
        $names = ['Alex', 'Sam', 'Riley', 'Jordan', 'Taylor', 'Morgan', 'Quinn', 'Charlie'];
        return Util::pick($names) . ' ' . Util::pick(['Flambe', 'Sous', 'Knifehands', 'Pepper']);
    }

    public function newGame(): void {
        $start = $this->getRandomActiveRestaurant();
        $this->state = [
            'player' => [
                'name' => $this->randName(),
                'hp' => 10,
                'max_hp' => 10,
                'hunger' => 0,
                'max_hunger' => 10,
                'sanity' => 8,
                'max_sanity' => 12,
                'sauce_shards' => 0,
                'inventory' => [],
            ],
            'pos' => $start ? $start['id'] : null,
            'visited' => $start ? [$start['id']] : [],
            'depth' => 1,
            'log' => ["You wake in a neon-lit alley. A whisper: \"Where is the Lamb Sauce?\""],
            'pending_riddle' => null,
            'pending_enemy' => null,
            'shards_by_rest' => [],
            'win' => false,
            'fog' => [],
        ];
        if ($start) $this->state['log'][] = "You smell " . $start['cuisine_type'] . " from " . $start['name'] . ".";
        $_SESSION['game'] = $this->state;
    }

    public function save(): void {
        $_SESSION['game'] = $this->state;
    }

    private function getRandomActiveRestaurant(): ?array {
        try {
            $stmt = $this->db->pdo->query("SELECT id, name, cuisine_type, city, rating
                                           FROM RESTAURANTS
                                           WHERE IFNULL(is_active,1)=1
                                           ORDER BY RANDOM() LIMIT 1");
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getRestaurantById(int $id): ?array {
        $stmt = $this->db->pdo->prepare("SELECT id, name, cuisine_type, city, rating FROM RESTAURANTS WHERE id = :id");
        $stmt->execute([':id'=>$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function neighborsOf(int $rid): array {
        // Nearby restaurants inferred by shared city or cuisine, or review cross-links.
        $r = $this->getRestaurantById($rid);
        if (!$r) return [];
        $neighbors = [];

        // Prefer same city
        $stmt = $this->db->pdo->prepare("SELECT id, name, cuisine_type, city, rating
                                         FROM RESTAURANTS
                                         WHERE city = :city AND id != :id
                                         ORDER BY RANDOM() LIMIT 4");
        $stmt->execute([':city'=>$r['city'] ?? '', ':id'=>$rid]);
        $neighbors = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (count($neighbors) < 2) {
            // fallback: shared cuisine
            $stmt = $this->db->pdo->prepare("SELECT id, name, cuisine_type, city, rating
                                             FROM RESTAURANTS
                                             WHERE cuisine_type = :cui AND id != :id
                                             ORDER BY RANDOM() LIMIT 4");
            $stmt->execute([':cui'=>$r['cuisine_type'] ?? '', ':id'=>$rid]);
            $more = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $neighbors = array_merge($neighbors, $more);
        }
        

// Ensure at least 1-4 neighbors by padding with random picks if needed
$need = 4 - count($neighbors);
if ($need > 0) {
    $stmt = $this->db->pdo->prepare("SELECT id, name, cuisine_type, city, rating
                                     FROM RESTAURANTS
                                     WHERE id != :id
                                     ORDER BY RANDOM() LIMIT :lim");
    $stmt->bindValue(':id', $rid, \PDO::PARAM_INT);
    $stmt->bindValue(':lim', $need, \PDO::PARAM_INT);
    $stmt->execute();
    $more = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    // avoid duplicates
    $seen = [];
    foreach ($neighbors as $n) { $seen[$n['id']] = true; }
    foreach ($more as $m2) {
        if (!isset($seen[$m2['id']])) { $neighbors[] = $m2; $seen[$m2['id']] = true; }
    }
}
// Always return between 1 and 4 neighbors (never empty)
if (count($neighbors) === 0) {
    $stmt = $this->db->pdo->prepare("SELECT id, name, cuisine_type, city, rating
                                     FROM RESTAURANTS
                                     WHERE id != :id
                                     ORDER BY RANDOM() LIMIT 1");
    $stmt->execute([':id'=>$rid]);
    $solo = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($solo) $neighbors[] = $solo;
}
return array_slice($neighbors, 0, 4);
}

    private function moodOfRestaurant(int $rid): array {
        // Derive an "emotion aura" from REVIEWS
        $stmt = $this->db->pdo->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as cnt FROM REVIEWS WHERE restaurant_id = :rid");
        $stmt->execute([':rid'=>$rid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['avg_rating'=>null, 'cnt'=>0];
        $avg = (float)($row['avg_rating'] ?? 0);
        $cnt = (int)($row['cnt'] ?? 0);
        $emotion = 'Neutral Craving';
        $effect = 0;
        if ($cnt > 0) {
            if ($avg >= 4.5) { $emotion = 'Elation'; $effect = 2; }
            elseif ($avg >= 3.5) { $emotion = 'Comfort'; $effect = 1; }
            elseif ($avg >= 2.5) { $emotion = 'Melancholy'; $effect = -1; }
            else { $emotion = 'Rage of the Hangry'; $effect = -2; }
        }
        return ['name'=>$emotion, 'power'=>$effect, 'samples'=>$cnt];
    }

    private function menuItemsAt(int $rid): array {
        // Flexible fetch in case columns differ
        $cols = $this->db->columnList('MENU_ITEMS');
        $selectable = array_intersect($cols, ['id','name','title','item_name','description','price','restaurant_id','is_vegetarian','is_spicy','calories']);
        $sel = implode(',', $selectable ?: ['id']);
        $stmt = $this->db->pdo->prepare("SELECT $sel FROM MENU_ITEMS WHERE restaurant_id = :rid");
        $stmt->execute([':rid'=>$rid]);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        // Normalize fields
        foreach ($items as &$it) {
            $it['display_name'] = $it['name'] ?? ($it['title'] ?? ($it['item_name'] ?? ('Item#'.$it['id'])));
            $it['price'] = isset($it['price']) ? (float)$it['price'] : null;
        }
        return $items;
    }

    private function dangerFromOrders(int $rid): int {
        // Higher traffic => more danger. Try to infer from ORDERS + ORDER_ITEMS
        $sql = null;
        if ($this->db->hasColumn('ORDERS','restaurant_id')) {
            $sql = "SELECT COUNT(*) FROM ORDERS WHERE restaurant_id = :rid";
            $stmt = $this->db->pdo->prepare($sql);
            $stmt->execute([':rid'=>$rid]);
            $cnt = (int)$stmt->fetchColumn();
        } else {
            // infer via joins
            $cnt = 0;
            try {
                $sql = "SELECT COUNT(DISTINCT o.id)
                        FROM ORDERS o
                        JOIN ORDER_ITEMS oi ON oi.order_id = o.id
                        JOIN MENU_ITEMS mi ON mi.id = oi.menu_item_id
                        WHERE mi.restaurant_id = :rid";
                $stmt = $this->db->pdo->prepare($sql);
                $stmt->execute([':rid'=>$rid]);
                $cnt = (int)$stmt->fetchColumn();
            } catch (\Throwable $e) {}
        }
        return max(1, (int)ceil(sqrt(max(0,$cnt))));
    }

    public function renderMap(): string {
        $rid = $this->state['pos'];
        if (!$rid) return "[No map data]";
        $neighbors = $this->neighborsOf($rid);
        $center = $this->getRestaurantById($rid);
        $lines = [];
        $lines[] = "+-------------------- CITY HEATMAP ---------------------+";
        $lines[] = "| " . str_pad(($center['city'] ?? 'Unknown City'), 54) . " |";
        $lines[] = "+-------------------------------------------------------+";
        $lines[] = " You are at: " . ($center['name'] ?? '???') . " [" . ($center['cuisine_type'] ?? '?') . "]";
        $mood = $this->moodOfRestaurant($rid);
        $lines[] = " Emotion aura: {$mood['name']} (samples: {$mood['samples']})";
        $lines[] = " Danger level: " . $this->dangerFromOrders($rid);
        $lines[] = "";
        $lines[] = " Paths:";
        $dirs = ['north','east','south','west'];
        foreach ($neighbors as $i=>$n) {
            $dir = $dirs[$i] ?? 'side-street';
            $lines[] = "  - {$dir}: {$n['name']} ({$n['cuisine_type']}) ★".(int)($n['rating'] ?? 0);
        }
        if (!$neighbors) $lines[] = "  None. The alley is a dead end.";
        return implode(PHP_EOL, $lines);
    }

    private function takeDamage(int $dmg, string $why): void {
        $this->state['player']['hp'] = max(0, $this->state['player']['hp'] - $dmg);
        $this->state['log'][] = "You take {$dmg} damage from $why.";
        if ($this->state['player']['hp'] <= 0) {
            $this->die("Perished by $why");
        }
    }

    private function die(string $cause): void {
        // Write a score and reset
        $stmt = $this->db->pdo->prepare("INSERT INTO GAME_SCORES (player_name, depth, sauce_shards, cause_of_death, created_at) VALUES (:n,:d,:s,:c,:t)");
        $stmt->execute([
            ':n'=>$this->state['player']['name'],
            ':d'=>$this->state['depth'],
            ':s'=>$this->state['player']['sauce_shards'],
            ':c'=>$cause,
            ':t'=>Util::now()
        ]);
        $this->state['log'][] = "*** YOU DIED: {$cause} ***  (Permadeath active)";
        $_SESSION['grave'] = $this->state; // keep last run for the death screen
        unset($_SESSION['game']);
        $this->state = [];
    }

    
private function monsterAttack(): void {
    // Spawn a themed enemy that poses a riddle instead of instant damage
    $names = ['Halwa Hunter','Harees Horror', 'Samboosa Stalker', 'Gahwa Ghoul', 'Fried Chicken Fiend', 'Milkshake Mummy', 'Nugget Nightcrawler', 'Balaleet Widow', 'Machboos Djinnlord', 'Cola Kraken', 'Shawarma Cyclone', 'Nugget Horde'];
    $who = Util::pick($names);

    // Riddle bank (question => answer). Answers should be concise lowercase.
    $riddles = [
        ['q' => 'What has keys but can’t open locks?', 'a' => 'piano'],
        ['q' => 'I’m tall when I’m young and short when I’m old. What am I?', 'a' => 'candle'],
        ['q' => 'What has a heart that doesn’t beat?', 'a' => 'artichoke'],
        ['q' => 'What has hands but can’t clap?', 'a' => 'clock'],
        ['q' => 'What can travel around the world while staying in a corner?', 'a' => 'stamp'],
        ['q' => 'What has many teeth but can’t bite?', 'a' => 'comb'],
        ['q' => 'What gets wetter the more it dries?', 'a' => 'towel'],
        ['q' => 'What has a neck but no head?', 'a' => 'bottle'],
        ['q' => 'What has one eye but can’t see?', 'a' => 'needle'],
        ['q' => 'What has a thumb and four fingers but isn’t alive?', 'a' => 'glove'],
        ['q' => 'What has words but never speaks?', 'a' => 'book'],
        ['q' => 'What can you catch but not throw?', 'a' => 'cold'],
        ['q' => 'What belongs to you but is used more by others?', 'a' => 'your name'],
        ['q' => 'What invention lets you look right through a wall?', 'a' => 'window'],
        ['q' => 'What runs but never walks?', 'a' => 'water'],
        ['q' => 'What has a bed but never sleeps?', 'a' => 'river'],
        ['q' => 'Where does today come before yesterday?', 'a' => 'dictionary'],
        ['q' => 'What has cities but no houses, forests but no trees, and rivers but no water?', 'a' => 'map'],
        ['q' => 'What can fill a room but takes up no space?', 'a' => 'light'],
        ['q' => 'What has 88 keys but can’t open a single door?', 'a' => 'piano'],
        ['q' => 'What gets broken without being held?', 'a' => 'promise'],
        ['q' => 'What has four wheels and flies?', 'a' => 'garbage truck'],
        ['q' => 'What has to be broken before you can use it?', 'a' => 'egg'],
        ['q' => 'What kind of band never plays music?', 'a' => 'rubber band'],
        ['q' => 'What is full of holes but still holds water?', 'a' => 'sponge'],
        ['q' => 'What has one head, one foot, and four legs?', 'a' => 'bed'],
        ['q' => 'What has an eye but cannot see and is driven by the wind?', 'a' => 'hurricane'],
        ['q' => 'What is yours, yet other people use it more than you?', 'a' => 'your name'],
        ['q' => 'What has a ring but no finger?', 'a' => 'telephone'],
        ['q' => 'What kind of coat is always wet when you put it on?', 'a' => 'paint'],
        ['q' => 'What can you keep after giving to someone?', 'a' => 'your word'],
        ['q' => 'What has legs but doesn’t walk?', 'a' => 'table'],
        ['q' => 'What comes down but never goes up?', 'a' => 'rain'],
        ['q' => 'What has many rings but no fingers?', 'a' => 'tree'],
        ['q' => 'What has a face and two hands but no arms or legs?', 'a' => 'clock'],
        ['q' => 'What is always in front of you but can’t be seen?', 'a' => 'future'],
        ['q' => 'What building has the most stories?', 'a' => 'library'],
        ['q' => 'What gets sharper the more you use it?', 'a' => 'your brain'],
        ['q' => 'What kind of room has no doors or windows?', 'a' => 'mushroom'],
        ['q' => 'What has an end but no beginning, a home but no family, and a space without a room?', 'a' => 'keyboard'],
        ['q' => 'What word is spelled incorrectly in every dictionary?', 'a' => 'incorrectly'],
        ['q' => 'What begins with t, ends with t, and has t in it?', 'a' => 'teapot'],
        ['q' => 'What comes once in a minute, twice in a moment, but never in a thousand years?', 'a' => 'm'],
        ['q' => 'What has four fingers and a thumb but is not a hand?', 'a' => 'glove'],
        ['q' => 'What has roots that nobody sees and is taller than trees?', 'a' => 'mountain'],
        ['q' => 'What has many keys but opens no locks?', 'a' => 'keyboard'],
        ['q' => 'What flies without wings and cries without eyes?', 'a' => 'cloud'],
        ['q' => 'What can run but never walks, has a mouth but never talks?', 'a' => 'river'],
        ['q' => 'What begins with e, ends with e, but only contains one letter?', 'a' => 'envelope'],
        ['q' => 'What has a spine but no bones?', 'a' => 'book'],
        ['q' => 'What tastes better than it smells?', 'a' => 'tongue'],
        ['q' => 'What kind of tree can you carry in your hand?', 'a' => 'palm'],
        ['q' => 'What has bark but no bite?', 'a' => 'tree'],
        ['q' => 'What goes up and down but doesn’t move?', 'a' => 'staircase'],
        ['q' => 'What has many needles but doesn’t sew?', 'a' => 'pine tree'],
        ['q' => 'What has ears but cannot hear?', 'a' => 'corn'],
        ['q' => 'What can’t talk but will reply when spoken to?', 'a' => 'echo'],
        ['q' => 'What can you break, even if you never pick it up or touch it?', 'a' => 'promise'],
        ['q' => 'What has one head, one tail, is brown, and has no legs?', 'a' => 'penny'],
        ['q' => 'What building do people go to when they are cold?', 'a' => 'corner'],
        ['q' => 'What month has 28 days?', 'a' => 'all months'],
        ['q' => 'What goes through cities and fields but never moves?', 'a' => 'a road'],
        ['q' => 'What has a mouth but can’t chew?', 'a' => 'river'],
        ['q' => 'What comes with a lot of letters but no words?', 'a' => 'mailbox'],
        ['q' => 'What begins with p, ends with e, and has thousands of letters?', 'a' => 'post office'],
        ['q' => 'What has stripes but no clothes?', 'a' => 'zebra'],
        ['q' => 'What kind of cup doesn’t hold water?', 'a' => 'cupcake'],
        ['q' => 'What has wheels and can fly?', 'a' => 'garbage truck'],
        ['q' => 'What can be cracked, made, told, and played?', 'a' => 'joke'],
        ['q' => 'What has two hands, a round face, always runs, yet stays in place?', 'a' => 'clock'],
        ['q' => 'What begins with an r, ends with an r, and is bruised all over?', 'a' => 'river'],
        ['q' => 'What has a tail and a head but no body?', 'a' => 'coin'],
        ['q' => 'What has a head but no brain?', 'a' => 'lettuce'],
        ['q' => 'What never asks questions but gets answered all the time?', 'a' => 'doorbell'],
        ['q' => 'What has holes on top and bottom and also on both sides, yet still holds water?', 'a' => 'sponge'],
        ['q' => 'What kind of room do ghosts avoid?', 'a' => 'living room'],
        ['q' => 'What do you call a bear with no teeth?', 'a' => 'gummy bear'],
        ['q' => 'What has four letters, sometimes nine letters, never five letters?', 'a' => 'a statement'],
        ['q' => 'What is so fragile that saying its name breaks it?', 'a' => 'silence'],
        ['q' => 'What goes up but never comes down?', 'a' => 'age'],
        ['q' => 'What is always hungry and must be fed, but if you give it water it will die?', 'a' => 'fire'],
        ['q' => 'Feed me and I live, give me a drink and I die. What am I?', 'a' => 'fire'],
        ['q' => 'I shave every day, but my beard stays the same. Who am I?', 'a' => 'barber'],
        ['q' => 'The more there is, the less you see. What is it?', 'a' => 'darkness'],
        ['q' => 'What has many keys but can’t listen to a single note?', 'a' => 'keyboard'],
        ['q' => 'What begins with an e and only contains one letter?', 'a' => 'envelope'],
        ['q' => 'What can you hold in your right hand but never in your left?', 'a' => 'your left hand'],
        ['q' => 'What can fill your belly but is never eaten?', 'a' => 'laughter'],
        ['q' => 'What has a head and tail but no arms or legs?', 'a' => 'coin'],
        ['q' => 'What is always coming but never arrives?', 'a' => 'tomorrow'],
        ['q' => 'What is as light as a feather, yet the strongest man cannot hold it for long?', 'a' => 'breath'],
        ['q' => 'What has knees but can’t bend?', 'a' => 'bee'],
        ['q' => 'What can you find at the end of a rainbow?', 'a' => 'w'],
        ['q' => 'What begins with t and is filled with t?', 'a' => 'teapot'],
    ];
    $r = Util::pick($riddles);
    $this->state['pending_enemy'] = ['name'=>$who, 'question'=>$r['q'], 'answer'=>$r['a']];
$this->state['log'][] = $who . " appears! It hisses a riddle: \"" . $r['q'] . "\"  (answer with: answer <your guess>)";
}


    private function hungerTick(): void {
    $this->state['player']['hunger'] = max(0, $this->state['player']['hunger'] - 1);
}

    public function look(): void {
        if (!$this->state) return;
        $rid = $this->state['pos'];
        $r = $this->getRestaurantById((int)$rid);
        $items = $this->menuItemsAt((int)$rid);
        $this->state['log'][] = "You step into " . ($r['name'] ?? 'a nameless spot') . ".";
        if ($items) {
            $sample = array_slice($items, 0, min(3, count($items)));
            $list = implode(', ', array_map(fn($i)=>$i['display_name'], $sample));
            $this->state['log'][] = "On the chalkboard menu: " . $list . " ...and more.";
        } else {
            $this->state['log'][] = "Dusty menu. Nothing listed.";
        }
        $this->state['log'][] = (in_array((int)$rid, $this->state['shards_by_rest'] ?? [], true) ? 'Shard here: claimed' : 'Shard here: unclaimed');
        $this->hungerTick();
        if (Util::dice(6) <= 3) $this->monsterAttack();
    }

    public function move(string $dir): void {
        if (!$this->state) return;
        $rid = (int)$this->state['pos'];
        $neighbors = $this->neighborsOf($rid);
        $dirs = ['north','east','south','west'];
        $index = array_search($dir, $dirs, true);
        if ($index === false || !isset($neighbors[$index])) {
// Instead of a hard dead-end, generate a winding back-alley path of 1-4 steps
$steps = random_int(1, 4);
$current = (int)$this->state['pos'];
$last = $current;
for ($i = 0; $i < $steps; $i++) {
    $stmt = $this->db->pdo->prepare("SELECT id, name FROM RESTAURANTS WHERE id != :id ORDER BY RANDOM() LIMIT 1");
    $stmt->execute([':id'=>$last]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($row) { $last = (int)$row['id']; }
}
if ($last !== $current) {
    $this->state['pos'] = $last;
    $this->state['visited'][] = $last;
    $this->state['depth'] += $steps;
    $dest = $this->getRestaurantById($last);
    $mood = $this->moodOfRestaurant($last);
    $this->state['player']['sanity'] = Util::clamp(
        $this->state['player']['sanity'] + $mood['power'],
        0, $this->state['player']['max_sanity']
    );
    $this->state['log'][] = "The main street is blocked. You squeeze through {$steps} shadowy back-alley step(s) and reach {$dest['name']}.";
} else {
    $this->state['log'][] = "A dumpster blocks that way. You turn back.";
}
$this->hungerTick();
        if ($this->state['player']['hunger'] <= 0) { $this->takeDamage(1, 'starvation'); }
if (Util::dice(6) <= 2) $this->monsterAttack();
return;
}
        $next = $neighbors[$index];
        $this->state['pos'] = (int)$next['id'];
        $this->state['visited'][] = (int)$next['id'];
        $this->state['depth']++;
        $mood = $this->moodOfRestaurant((int)$next['id']);
        $this->state['player']['sanity'] = Util::clamp(
            $this->state['player']['sanity'] + $mood['power'],
            0, $this->state['player']['max_sanity']
        );
        $this->state['log'][] = "You slip " . $dir . " to " . $next['name'] . " (" . $mood['name'] . ").";
        $this->hungerTick();
        if (Util::dice(6) <= 4) $this->monsterAttack(); // brutally hard
    }

    public function inventory(): void {
        $inv = $this->state['player']['inventory'];
        if (!$inv) $this->state['log'][] = "Inventory empty. The city laughs.";
        else {
            $list = [];
            foreach ($inv as $k=>$item) $list[] = $item['name'] . " x" . $item['qty'];
            $this->state['log'][] = "Backpack: " . implode(', ', $list);
        }
    }

    public function scavenge(): void {
        $rid = (int)$this->state['pos'];
        $items = $this->menuItemsAt($rid);
        if (!$items) { $this->state['log'][] = "No edible relics here."; $this->hungerTick(); return; }
        $found = Util::pick($items);
        $name = $found['display_name'];
        $gain = Util::dice(2);
        $this->state['player']['inventory'][] = ['name'=>$name, 'qty'=>$gain, 'type'=>'food'];
        $this->state['log'][] = "You scavenge {$gain}x {$name}.";
        if (Util::dice(6) <= 4) $this->monsterAttack();
        $this->hungerTick();
    }

        public function eat(string $what = ''): void {
        $inv =& $this->state['player']['inventory'];
        if (!$inv) { $this->state['log'][] = "Nothing to eat."; return; }
        $idx = null;
        foreach ($inv as $i=>$it) {
            if ($what && !Util::str_has_ci($it['name'], $what)) continue;
            if (($it['type'] ?? '') === 'food') { $idx = $i; break; }
        }
        if ($idx === null) { $this->state['log'][] = "You can't find that to eat."; return; }
        $name = $inv[$idx]['name'];

        // Determine healing from DB price/calories if possible
        $rid = (int)$this->state['pos'];
        $heal = 0;
        try {
            $stmt = $this->db->pdo->prepare("
                SELECT price, calories,
                       COALESCE(name, title, item_name) AS nm
                FROM MENU_ITEMS
                WHERE restaurant_id = :rid
                  AND (name = :n OR title = :n OR item_name = :n)
                ORDER BY price DESC
                LIMIT 1");
            $stmt->execute([':rid'=>$rid, ':n'=>$name]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $cal = isset($row['calories']) ? (int)$row['calories'] : null;
                $pr  = isset($row['price']) ? (float)$row['price'] : null;
                if ($cal && $cal > 0) {
                    $heal = (int)max(1, round($cal / 200.0)); // 200 kcal ≈ 1 HP
                } elseif ($pr && $pr > 0) {
                    $heal = (int)max(1, round($pr / 2.0));    // price heuristic
                }
            }
        } catch (\Throwable $e) {
            // Ignore and fall back
        }
        // Fallback + slight variance; cap to avoid breaking balance
        if ($heal <= 0) { $heal = 1; } // old code could be 0; ensure at least 1
        $heal += max(0, Util::dice(2)-1); // +0..+1
        $heal = min($heal, 10);

        // Apply healing and hunger soothe scaled by heal
        $this->state['player']['hp'] = min($this->state['player']['max_hp'], $this->state['player']['hp'] + $heal);
        $this->state['player']['hunger'] = min($this->state['player']['max_hunger'], $this->state['player']['hunger'] + (1 + $heal));

        if (--$inv[$idx]['qty'] <= 0) array_splice($inv, $idx, 1);
        $this->state['log'][] = "You eat {$name}. (+{$heal} hp, hunger soothed)";
        if (Util::dice(6) <= 3) $this->monsterAttack();
    }

    

public function chant(): void {
    // Ultra-robust chant: always produce an even/odd riddle ("solve even|odd") using layered fallbacks.
    $rid = (int)$this->state['pos'];
    $r   = $this->getRestaurantById($rid);
    $city = $r['city'] ?? '';

    try {
        // ---------- 1) ORDERS-based (best): restaurant -> city -> global ----------
        $stmt = $this->db->pdo->prepare("SELECT total_amount FROM ORDERS WHERE restaurant_id = :rid ORDER BY RANDOM() LIMIT 1");
        $stmt->execute([':rid'=>$rid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row && isset($row['total_amount'])) {
            $target = (int)round((float)$row['total_amount']);
            $this->state['log'][] = "A spectral receipt unfurls here. Its sum flickers. Speak: 'solve even' or 'solve odd'.";
            $this->state['pending_riddle'] = ['source'=>'orders@rest','answer'=>($target%2===0?'even':'odd')];
            return;
        }

        if ($city) {
            $stmt = $this->db->pdo->prepare("
                SELECT o.total_amount
                FROM ORDERS o
                JOIN RESTAURANTS r ON r.id = o.restaurant_id
                WHERE r.city = :city
                ORDER BY RANDOM() LIMIT 1");
            $stmt->execute([':city'=>$city]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row && isset($row['total_amount'])) {
                $target = (int)round((float)$row['total_amount']);
                $this->state['log'][] = "Receipts echo across the city. Choose: 'solve even' or 'solve odd'.";
                $this->state['pending_riddle'] = ['source'=>'orders@city','answer'=>($target%2===0?'even':'odd')];
                return;
            }
        }

        $row = $this->db->pdo->query("SELECT total_amount FROM ORDERS ORDER BY RANDOM() LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        if ($row && isset($row['total_amount'])) {
            $target = (int)round((float)$row['total_amount']);
            $this->state['log'][] = "Distant delivery totals whisper. Decide: 'solve even' or 'solve odd'.";
            $this->state['pending_riddle'] = ['source'=>'orders@global','answer'=>($target%2===0?'even':'odd')];
            return;
        }

        // ---------- 2) REVIEWS-based: restaurant -> city -> global ----------
        $stmt = $this->db->pdo->prepare("SELECT AVG(rating) AS avg_rating, COUNT(*) AS cnt FROM REVIEWS WHERE restaurant_id = :rid");
        $stmt->execute([':rid'=>$rid]);
        $rv = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($rv && (int)($rv['cnt'] ?? 0) > 0) {
            $avg = (float)$rv['avg_rating'];
            $target = (int)round($avg * 10);
            $this->state['log'][] = "Review spirits gather here. Judge their mood: 'solve even' or 'solve odd'.";
            $this->state['pending_riddle'] = ['source'=>'reviews@rest','answer'=>($target%2===0?'even':'odd')];
            return;
        }

        if ($city) {
            $stmt = $this->db->pdo->prepare("
                SELECT AVG(r.rating) AS avg_rating, COUNT(*) AS cnt
                FROM REVIEWS r
                JOIN RESTAURANTS t ON t.id = r.restaurant_id
                WHERE t.city = :city");
            $stmt->execute([':city'=>$city]);
            $rv = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($rv && (int)($rv['cnt'] ?? 0) > 0) {
                $avg = (float)$rv['avg_rating'];
                $target = (int)round($avg * 10);
                $this->state['log'][] = "The city's appetite hums. Divine it: 'solve even' or 'solve odd'.";
                $this->state['pending_riddle'] = ['source'=>'reviews@city','answer'=>($target%2===0?'even':'odd')];
                return;
            }
        }

        $rv = $this->db->pdo->query("SELECT AVG(rating) AS avg_rating, COUNT(*) AS cnt FROM REVIEWS")->fetch(\PDO::FETCH_ASSOC);
        if ($rv && (int)($rv['cnt'] ?? 0) > 0) {
            $avg = (float)$rv['avg_rating'];
            $target = (int)round($avg * 10);
            $this->state['log'][] = "All reviews in chorus sing. Decide: 'solve even' or 'solve odd'.";
            $this->state['pending_riddle'] = ['source'=>'reviews@global','answer'=>($target%2===0?'even':'odd')];
            return;
        }

        // ---------- 3) MENU_ITEMS-based: restaurant -> city -> global ----------
        $stmt = $this->db->pdo->prepare("SELECT COUNT(*) AS cnt FROM MENU_ITEMS WHERE restaurant_id = :rid");
        $stmt->execute([':rid'=>$rid]);
        $mi = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($mi && (int)($mi['cnt'] ?? 0) > 0) {
            $cnt = (int)$mi['cnt'];
            $this->state['log'][] = "Menus rustle here. Count their rhythm: 'solve even' or 'solve odd'.";
            $this->state['pending_riddle'] = ['source'=>'menu@rest','answer'=>($cnt%2===0?'even':'odd')];
            return;
        }

        if ($city) {
            $stmt = $this->db->pdo->prepare("
                SELECT COUNT(*) AS cnt
                FROM MENU_ITEMS mi
                JOIN RESTAURANTS r ON r.id = mi.restaurant_id
                WHERE r.city = :city");
            $stmt->execute([':city'=>$city]);
            $mi = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($mi && (int)($mi['cnt'] ?? 0) > 0) {
                $cnt = (int)$mi['cnt'];
                $this->state['log'][] = "City menus cascade like rain. Choose: 'solve even' or 'solve odd'.";
                $this->state['pending_riddle'] = ['source'=>'menu@city','answer'=>($cnt%2===0?'even':'odd')];
                return;
            }
        }

        $mi = $this->db->pdo->query("SELECT COUNT(*) AS cnt FROM MENU_ITEMS")->fetch(\PDO::FETCH_ASSOC);
        if ($mi && (int)($mi['cnt'] ?? 0) > 0) {
            $cnt = (int)$mi['cnt'];
            $this->state['log'][] = "The grand menu of the metropolis unfurls. Answer: 'solve even' or 'solve odd'.";
            $this->state['pending_riddle'] = ['source'=>'menu@global','answer'=>($cnt%2===0?'even':'odd')];
            return;
        }

        // ---------- 4) Final synthetic fallbacks ----------
        if ($r && isset($r['name'])) {
            $len = strlen(preg_replace('/\s+/', '', $r['name']));
            $this->state['log'][] = "Neon letters swirl from the sign. Their parity beckons: 'solve even' or 'solve odd'.";
            $this->state['pending_riddle'] = ['source'=>'name@rest','answer'=>($len%2===0?'even':'odd')];
            return;
        }

        // Absolute last resort: coin flip so chant ALWAYS works
        $ans = (random_int(0,1) === 0) ? 'even' : 'odd';
        $this->state['log'][] = "Fate flips a greasy coin. Call it: 'solve even' or 'solve odd'.";
        $this->state['pending_riddle'] = ['source'=>'synthetic','answer'=>$ans];
    } catch (\Throwable $e) {
        // Even on DB error, avoid dead-end; use synthetic fallback
        $ans = (random_int(0,1) === 0) ? 'even' : 'odd';
        $this->state['log'][] = "The chant sputters… but destiny whispers. 'solve even' or 'solve odd'.";
        $this->state['pending_riddle'] = ['source'=>'error-synthetic','answer'=>$ans];
    }
}

public function chantHard(): void {
    // Hard chant: tougher parity, rewards SHARDS only.
    $rid = (int)$this->state['pos'];
    $r   = $this->getRestaurantById($rid);
    $city = $r['city'] ?? '';
    try {
        // Tough source #1: sum of top-2 menu prices (in cents) here
        $stmt = $this->db->pdo->prepare("
            SELECT price FROM MENU_ITEMS WHERE restaurant_id = :rid
            ORDER BY price DESC LIMIT 2");
        $stmt->execute([':rid'=>$rid]);
        $prices = $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);
        if ($prices && count($prices) >= 2) {
            $sum = (int)round(((float)$prices[0] + (float)$prices[1]) * 100);
            $this->state['log'][] = "High-heat rite: judge the sum of top flavors (in cents). 'solve even' or 'solve odd'.";
            $this->state['pending_riddle'] = ['mode'=>'hard','answer'=>($sum%2===0?'even':'odd')];
            return;
        }

        // Tough source #2 (city-level): (#reviews * avg_rating * 10)
        if ($city) {
            $stmt = $this->db->pdo->prepare("
                SELECT AVG(rv.rating) AS avg_rating, COUNT(*) AS cnt
                FROM REVIEWS rv
                JOIN RESTAURANTS t ON t.id = rv.restaurant_id
                WHERE t.city = :city");
            $stmt->execute([':city'=>$city]);
            $rv = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($rv and (int)($rv['cnt'] ?? 0) > 0) {
                $val = (int)round(((int)$rv['cnt']) * ((float)$rv['avg_rating']) * 10);
                $this->state['log'][] = "The city's chorus rises. 'solve even' or 'solve odd'.";
                $this->state['pending_riddle'] = ['mode'=>'hard','answer'=>($val%2===0?'even':'odd')];
                return;
            }
        }

        // Tough source #3 (global): sum of 3 random delivery fees (in cents)
        $rowset = $this->db->pdo->query("SELECT delivery_fee FROM ORDERS ORDER BY RANDOM() LIMIT 3")->fetchAll(\PDO::FETCH_ASSOC);
        if ($rowset && count($rowset) > 0) {
            $s3 = 0.0; foreach ($rowset as $rw) { $s3 += (float)($rw['delivery_fee'] ?? 0); }
            $val = (int)round($s3 * 100);
            $this->state['log'][] = "Three couriers tithe their fees. 'solve even' or 'solve odd'.";
            $this->state['pending_riddle'] = ['mode'=>'hard','answer'=>($val%2===0?'even':'odd')];
            return;
        }

        // Synthetic hard fallback
        $ans = (random_int(0,1)===0)?'even':'odd';
        $this->state['log'][] = "The grease oracle flips a coin. 'solve even' or 'solve odd'.";
        $this->state['pending_riddle'] = ['mode'=>'hard','answer'=>$ans];
    } catch (\Throwable $e) {
        $ans = (random_int(0,1)===0)?'even':'odd';
        $this->state['log'][] = "Heat haze scrambles the rite. 'solve even' or 'solve odd'.";
        $this->state['pending_riddle'] = ['mode'=>'hard','answer'=>$ans];
    }
}





public function solve(string $parity): void {
    if (!isset($this->state['pending_riddle'])) { $this->state['log'][] = "No riddle is bound."; return; }
    $answer = strtolower($this->state['pending_riddle']['answer'] ?? '');
    $mode = $this->state['pending_riddle']['mode'] ?? 'normal';
    unset($this->state['pending_riddle']);
    if (strtolower($parity) === $answer) {
        if ($mode === 'hard') {
            $rid = (int)$this->state['pos'];
            // One shard per restaurant, once only
            if (!in_array($rid, $this->state['shards_by_rest'] ?? [], true)) {
                $this->state['shards_by_rest'][] = $rid;
                $this->state['player']['sauce_shards'] = count($this->state['shards_by_rest']);
                $left = 20 - $this->state['player']['sauce_shards'];
                $this->state['log'][] = "A Sauce Shard crystallizes here. ({$this->state['player']['sauce_shards']}/20)";
                if ($this->state['player']['sauce_shards'] >= 20) {
                    $this->state['win'] = true;
                    $this->state['log'][] = "All 20 shards resonate. You win!";
                } else {
                    $this->state['log'][] = ($left>0) ? "{$left} shard(s) remain." : "You win!";
                }
            } else {
                $this->state['log'][] = "The shard here has already been claimed.";
            }
        } else {
            $rid = (int)$this->state['pos'];
            $items = $this->menuItemsAt($rid);
            $name = $items ? (Util::pick($items)['display_name']) : 'Mysterious Spice';
            $qty = max(1, Util::dice(2));
            $this->state['player']['inventory'][] = ['name'=>$name, 'qty'=>$qty, 'type'=>'food'];
            $this->state['log'][] = "The spirits are appeased. You receive {$qty}x {$name}.";
        }
    } else {
        $this->state['log'][] = "The rite backfires.";
        $this->takeDamage(3, 'backfired receipt magic');
    }

$answer = strtolower($this->state['pending_riddle']['answer'] ?? '');
    $mode = $this->state['pending_riddle']['mode'] ?? 'normal';
    unset($this->state['pending_riddle']);
    if (strtolower($parity) === $answer) {
        if ($mode === 'hard') {
            $gain = 1 + (int)floor($this->state['depth']/5);
            $this->state['player']['sauce_shards'] += $gain;
            $this->state['log'][] = "You master the high-heat rite. You bind {$gain} Sauce Shard(s).";
        } else {
            $rid = (int)$this->state['pos'];
            $items = $this->menuItemsAt($rid);
            $name = $items ? (Util::pick($items)['display_name']) : 'Mysterious Spice';
            $qty = max(1, Util::dice(2));
            $this->state['player']['inventory'][] = ['name'=>$name, 'qty'=>$qty, 'type'=>'food'];
            $this->state['log'][] = "The spirits are appeased. You receive {$qty}x {$name}.";
        }
    } else {
        $this->state['log'][] = "The rite backfires.";
        $this->takeDamage(3, 'backfired receipt magic');
    }
}

public function answer(string $guess): void {
    if (!isset($this->state['pending_enemy']) || !$this->state['pending_enemy']) {
        $this->state['log'][] = "No enemy’s riddle awaits an answer.";
        return;
    }
    $enc = $this->state['pending_enemy'];
    unset($this->state['pending_enemy']);
    $normalized = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '', $guess)));
    $expected  = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '', $enc['answer'])));

    if ($normalized === $expected) {
        // Reward: random food item from current restaurant, else a Mysterious Spice
        $rid = (int)$this->state['pos'];
        $items = $this->menuItemsAt($rid);
        if ($items) {
            $found = Util::pick($items);
            $name = $found['display_name'];
        } else {
            $name = 'Mysterious Spice';
        }
        $qty = max(1, Util::dice(2));
        $this->state['player']['inventory'][] = ['name'=>$name, 'qty'=>$qty, 'type'=>'food'];
        $this->state['log'][] = "You solve it! {$enc['name']} drops {$qty}x {$name} into your pack.";
    } else {
        $dmg = max(1, Util::dice(4));
        $this->state['log'][] = "{$enc['name']} screeches at your mistake!";
        $this->takeDamage($dmg, $enc['name']);
    }
    $this->hungerTick();
}

public function rest(): void {
        $roll = Util::dice(6);
        if ($roll <= 3) {
            $this->state['log'][] = "You try to rest, but the nightlife finds you.";
            $this->monsterAttack();
        } else {
            $heal = Util::dice(3);
            $this->state['player']['hp'] = min($this->state['player']['max_hp'], $this->state['player']['hp'] + $heal);
            $this->state['log'][] = "You steal {$heal} winks behind a dumpster.";
        }
        $this->hungerTick();
    }

    public function help(): string {
        return implode(PHP_EOL, [
            "Commands: look, map, go north|east|south|west, scavenge, eat [item],",
            "          chant [hard], solve even|odd, answer [text], inv, rest, stats, map, help, new, quit",
            "Goal: Bind Sauce Shards by decoding urban receipts. Permadeath. Good luck.",
        ]);
    }

    public function stats(): string {
        $p = $this->state['player'];
        return "HP {$p['hp']}/{$p['max_hp']} | Hunger {$p['hunger']}/{$p['max_hunger']} | Shards {$p['sauce_shards']} | Depth {$this->state['depth']}";
    }

    public function process(string $cmd): void {
        $cmd = trim($cmd);
        if ($cmd === '') return;
        $parts = preg_split('/\s+/', $cmd);
        $verb = strtolower($parts[0]);
        $arg = $parts[1] ?? '';

        switch ($verb) {
            case 'look': $this->look(); break;
            case 'map': $this->state['log'][] = $this->renderMap(); break;
            case 'go': $this->move(strtolower($arg)); break;
            case 'scavenge': $this->scavenge(); break;
            case 'eat': $this->eat($arg); break;
            case 'chant': if (strtolower($arg)==='hard'){ $this->chantHard(); } else { $this->chant(); } break;
            case 'solve': $this->solve($arg); break;
            case 'inv': $this->inventory(); break;
            case 'rest': $this->rest(); break;
            case 'stats': $this->state['log'][] = $this->stats(); break;
            case 'help': $this->state['log'][] = $this->help(); break;
            case 'answer': $this->answer($arg); break;
            case 'new': $this->newGame(); break;
            case 'quit': $this->die('abandonment'); break;
            default:
                $this->state['log'][] = "Unknown incantation. Try 'help'.";
        }
        $this->save();
    }


public function map(): void {
    try {
        $rows = $this->db->pdo->query("SELECT id, name FROM RESTAURANTS ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
        $claimed = $this->state['shards_by_rest'] ?? [];
        $total = is_array($rows) ? count($rows) : 0;
        $this->state['log'][] = "Map (" . $total . " places):";
        if ($rows) {
            foreach ($rows as $row) {
                $mark = in_array((int)$row['id'], $claimed, true) ? '✓' : '•';
                $this->state['log'][] = $mark . " [" . $row['id'] . "] " . ($row['name'] ?? 'Unnamed');
            }
        } else {
            $this->state['log'][] = "No restaurants found.";
        }
    } catch (\Throwable $e) {
        $this->state['log'][] = "Map failed: " . $e->getMessage();
    }
}



private function getAnyOtherRestaurant(int $id): ?array {
    $stmt = $this->db->pdo->prepare("SELECT id, name, cuisine_type, city, rating FROM RESTAURANTS WHERE id != :id ORDER BY RANDOM() LIMIT 1");
    $stmt->execute([':id'=>$id]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

}
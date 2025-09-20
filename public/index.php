<?php
// public/index.php
declare(strict_types=1);
session_start();

$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../bootstrap/autoload.php';

use Game\DB;
use Game\Game;
use Game\Util;

$db = new DB($config['db_path']);
$game = new Game($db);

// command processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cmd = $_POST['cmd'] ?? '';
    $game->process($cmd);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Basic death screen
$grave = $_SESSION['grave'] ?? null;
if ($grave && empty($_SESSION['game'])) {
    $score = end($grave['log']);
}

function a(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?= a($config['app_name']) ?></title>
  <link rel="stylesheet" href="style.css" />
</head>
<body>
  <div class="wrap">
    <header>
      <h1><?= a($config['app_name']) ?></h1>
      <p class="subtitle">Text roguelike · brutally hard · permadeath</p>
    </header>

    <?php if (!empty($grave) && empty($_SESSION['game'])): ?>
        <div class="panel death">
            <pre><?php foreach ($grave['log'] as $line) echo a($line) . "\n"; ?></pre>
            <form method="post">
                <button name="cmd" value="new">New Run</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['game'])): ?>
    <section class="hud">
        <div class="stats"><?= a($game->stats()) ?></div>
        <div class="map"><pre><?= a($game->renderMap()) ?></pre></div>
    </section>

    <section class="log">
        <pre><?php foreach ($_SESSION['game']['log'] as $line) echo a($line) . "\n"; ?></pre>
    </section>

    <section class="prompt">
        <form method="post" autocomplete="off">
            <input type="text" name="cmd" placeholder="Type a command (help)" autofocus />
            <button type="submit">Enter</button>
        </form>
        <div class="help">
            <code>look, map, go north|east|south|west, scavenge, eat [item], chant, solve even|odd, inv, rest, stats, help, new, quit</code>
        </div>
    </section>
    <?php endif; ?>

    <footer>
        <details>
            <summary>High Scores</summary>
            <table>
                <thead><tr><th>Player</th><th>Depth</th><th>Shards</th><th>Cause</th><th>When</th></tr></thead>
                <tbody>
                <?php
                $rows = $db->pdo->query("SELECT player_name, depth, sauce_shards, cause_of_death, created_at FROM GAME_SCORES ORDER BY id DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    echo '<tr><td>'.a($r['player_name']).'</td><td>'.(int)$r['depth'].'</td><td>'.(int)$r['sauce_shards'].'</td><td>'.a($r['cause_of_death']).'</td><td>'.a($r['created_at']).'</td></tr>';
                }
                ?>
                </tbody>
            </table>
        </details>
    </footer>
  </div>
</body>
</html>

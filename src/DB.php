<?php
namespace Game;

use PDO;
use PDOException;

class DB {
    public PDO $pdo;
    private array $columnsCache = [];

    public function __construct(string $dbPath) {
        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON;');
    }

    public function hasColumn(string $table, string $column): bool {
        $key = $table . ':' . $column;
        if (isset($this->columnsCache[$key])) return $this->columnsCache[$key];
        $stmt = $this->pdo->query("PRAGMA table_info($table)");
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $found = false;
        foreach ($cols as $c) {
            if (strcasecmp($c['name'], $column) === 0) { $found = true; break; }
        }
        $this->columnsCache[$key] = $found;
        return $found;
    }

    public function columnList(string $table): array {
        $stmt = $this->pdo->query("PRAGMA table_info($table)");
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($c) => $c['name'], $cols);
    }

    public function ensureMetaTables(): void {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS GAME_SCORES (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            player_name TEXT NOT NULL,
            depth INTEGER NOT NULL,
            sauce_shards INTEGER NOT NULL,
            cause_of_death TEXT NOT NULL,
            created_at TEXT NOT NULL
        )");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS GAME_SAVES (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id TEXT NOT NULL,
            payload TEXT NOT NULL,
            created_at TEXT NOT NULL
        )");
    }
}

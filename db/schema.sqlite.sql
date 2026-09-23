CREATE TABLE IF NOT EXISTS posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    body TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

INSERT INTO posts (title, body, created_at, updated_at)
VALUES ('Hello, MiniPHP', 'Your first post. Edit or delete it from the Posts page.', datetime('now'), datetime('now'));

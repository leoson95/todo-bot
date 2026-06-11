import sqlite3
from datetime import datetime
from config import DATABASE_NAME


def get_db_connection():
    conn = sqlite3.connect(DATABASE_NAME)
    conn.row_factory = sqlite3.Row
    return conn


def init_db():
    conn = get_db_connection()
    cursor = conn.cursor()
    
    # Users table (for password check)
    cursor.execute('''
        CREATE TABLE IF NOT EXISTS users (
            user_id INTEGER PRIMARY KEY,
            is_authenticated INTEGER DEFAULT 0
        )
    ''')
    
    # Tasks table
    cursor.execute('''
        CREATE TABLE IF NOT EXISTS tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            category TEXT,
            text TEXT,
            done INTEGER DEFAULT 0,
            reminder_enabled INTEGER DEFAULT 0,
            reminder_frequency TEXT,  -- daily, every_other_day
            reminder_time TEXT,       -- HH:MM format
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ''')
    
    conn.commit()
    conn.close()
    print("Database initialized successfully.")


# Example functions (we will expand them later)
def add_task(user_id, category, text, reminder_enabled=0, frequency=None, time=None):
    conn = get_db_connection()
    cursor = conn.cursor()
    cursor.execute("""
        INSERT INTO tasks (user_id, category, text, reminder_enabled, reminder_frequency, reminder_time)
        VALUES (?, ?, ?, ?, ?, ?)
    """, (user_id, category, text, reminder_enabled, frequency, time))
    conn.commit()
    conn.close()


def get_user_tasks(user_id):
    conn = get_db_connection()
    cursor = conn.cursor()
    cursor.execute("SELECT * FROM tasks WHERE user_id = ? AND done = 0 ORDER BY category", (user_id,))
    tasks = cursor.fetchall()
    conn.close()
    return tasks


def mark_task_done(task_id):
    conn = get_db_connection()
    cursor = conn.cursor()
    cursor.execute("UPDATE tasks SET done = 1 WHERE id = ?", (task_id,))
    conn.commit()
    conn.close()


def delete_task(task_id):
    conn = get_db_connection()
    cursor = conn.cursor()
    cursor.execute("DELETE FROM tasks WHERE id = ?", (task_id,))
    conn.commit()
    conn.close()
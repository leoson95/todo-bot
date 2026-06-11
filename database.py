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
    
    cursor.execute('''
        CREATE TABLE IF NOT EXISTS users (
            user_id INTEGER PRIMARY KEY,
            is_authenticated INTEGER DEFAULT 0
        )
    ''')
    
    cursor.execute('''
        CREATE TABLE IF NOT EXISTS tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            category TEXT,
            text TEXT NOT NULL,
            done INTEGER DEFAULT 0,
            reminder_enabled INTEGER DEFAULT 0,
            reminder_frequency TEXT,
            reminder_time TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ''')
    
    conn.commit()
    conn.close()

# User functions
def authenticate_user(user_id):
    conn = get_db_connection()
    cursor = conn.cursor()
    cursor.execute("INSERT OR REPLACE INTO users (user_id, is_authenticated) VALUES (?, 1)", (user_id,))
    conn.commit()
    conn.close()

def is_user_authenticated(user_id):
    conn = get_db_connection()
    cursor = conn.cursor()
    cursor.execute("SELECT is_authenticated FROM users WHERE user_id = ?", (user_id,))
    result = cursor.fetchone()
    conn.close()
    return result and result['is_authenticated'] == 1

# Task functions
def add_task(user_id, category, text, reminder_enabled=0, frequency=None, time=None):
    conn = get_db_connection()
    cursor = conn.cursor()
    cursor.execute("""
        INSERT INTO tasks (user_id, category, text, reminder_enabled, reminder_frequency, reminder_time)
        VALUES (?, ?, ?, ?, ?, ?)
    """, (user_id, category, text, reminder_enabled, frequency, time))
    task_id = cursor.lastrowid
    conn.commit()
    conn.close()
    return task_id

def get_user_tasks(user_id, only_undone=True):
    conn = get_db_connection()
    cursor = conn.cursor()
    if only_undone:
        cursor.execute("SELECT * FROM tasks WHERE user_id = ? AND done = 0 ORDER BY category, id", (user_id,))
    else:
        cursor.execute("SELECT * FROM tasks WHERE user_id = ? ORDER BY category, id", (user_id,))
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

def get_task_by_id(task_id):
    conn = get_db_connection()
    cursor = conn.cursor()
    cursor.execute("SELECT * FROM tasks WHERE id = ?", (task_id,))
    task = cursor.fetchone()
    conn.close()
    return task

def get_pending_reminders():
    conn = get_db_connection()
    cursor = conn.cursor()
    cursor.execute("""
        SELECT * FROM tasks 
        WHERE reminder_enabled = 1 AND done = 0
    """)
    tasks = cursor.fetchall()
    conn.close()
    return tasks
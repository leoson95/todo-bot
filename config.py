import os

# Security
BOT_PASSWORD = os.getenv("BOT_PASSWORD", "your_secret_password_here")

# Reminder settings
MORNING_REMINDER_HOUR = 8  # 8 AM
MORNING_REMINDER_MINUTE = 0

# Database
DATABASE_NAME = "todo_bot.db"
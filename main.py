import os
import logging
import telebot
from flask import Flask, request

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

TOKEN = os.environ.get("TELEGRAM_BOT_TOKEN")
bot = telebot.TeleBot(TOKEN)

app = Flask(__name__)

@app.route("/")
def index():
    return "✅ Bot is running successfully on Railway!"

@app.route("/webhook", methods=["POST"])
def webhook():
    try:
        json_string = request.get_data().decode('utf-8')
        update = telebot.types.Update.de_json(json_string)
        bot.process_new_updates([update])
        return "OK", 200
    except Exception as e:
        logger.error(f"Error: {e}")
        return "Error", 400

# ==================== دستورات بات ====================
@bot.message_handler(commands=['start'])
def start(message):
    bot.reply_to(message, "👋 سلام! من بات اکو هستم.\nهر پیامی بفرستی، همان را برات تکرار می‌کنم.")

@bot.message_handler(commands=['help'])
def help_command(message):
    bot.reply_to(message, "دستورات:\n/start\n/help\n\nهر متنی بفرست.")

@bot.message_handler(func=lambda m: True)
def echo(message):
    bot.reply_to(message, message.text)

if __name__ == "__main__":
    port = int(os.environ.get("PORT", 8080))
    logger.info(f"🚀 Bot started on port {port}")
    app.run(host="0.0.0.0", port=port)

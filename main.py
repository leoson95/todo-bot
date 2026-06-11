import os
import logging
from flask import Flask, request
from telegram import Update
from telegram.ext import Application, CommandHandler, MessageHandler, filters, ContextTypes

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = Flask(__name__)
TOKEN = os.environ.get("TELEGRAM_BOT_TOKEN")

# ساخت Application
application = Application.builder().token(TOKEN).build()

# هندلرها
async def start(update: Update, context: ContextTypes.DEFAULT_TYPE):
    await update.message.reply_text("👋 سلام! من بات اکو هستم.\nهر پیامی بفرستی برات تکرار می‌کنم.")

async def help_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    await update.message.reply_text("دستورات:\n/start\n/help")

async def echo(update: Update, context: ContextTypes.DEFAULT_TYPE):
    await update.message.reply_text(update.message.text)

application.add_handler(CommandHandler("start", start))
application.add_handler(CommandHandler("help", help_command))
application.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, echo))

# Initialize Application (مهم!)
async def initialize_app():
    await application.initialize()
    await application.start()

# Webhook Routes
@app.route("/")
def index():
    return "Bot is running on Railway!"

@app.route("/webhook", methods=["POST"])
async def webhook():
    try:
        update = Update.de_json(request.get_json(force=True), application.bot)
        await application.process_update(update)
        return "OK", 200
    except Exception as e:
        logger.error(f"Error: {e}")
        return "Error", 500

if __name__ == "__main__":
    import asyncio
    # Initialize before running
    asyncio.run(initialize_app())
    
    port = int(os.environ.get("PORT", 8080))
    logger.info(f"Starting bot on port {port}")
    app.run(host="0.0.0.0", port=port)
